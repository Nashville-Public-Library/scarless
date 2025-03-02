<?php

// Gather monthly report for MNPS Data Warehouse
// 2024 11 16
// James Staub
// Nashville Public Library
// Usage: php NashvilleMNPSDataWarehouseReport.php YYYY-MM-DD

class nashvilleMNPSDataWarehouseReport {
	private $reportPath;
	private $carlx_db_php;
	private $carlx_db_php_user;
	private $carlx_db_php_password;
	private $circulationApiLogin;
	private $circulationApiPassword;
	private $apiURL;
	private $apiDebugMode;
	private $apiReportMode;
	private $itemApiWsdl;
	private $circulationApiWsdl;
	private $alias;
	public $reportDate;
	public $mnpsLimitlessConditions = array('MNPS', 'LimitlessLibraries');
	public $staffStudentConditions = array('staff', 'student');

	function getConfig() {
		date_default_timezone_set('America/Chicago');
		// $startTime = microtime(true);
		$this->reportPath = '../data/';
		$configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
		$this->carlx_db_php = $configArray['Catalog']['carlx_db_php'];
		$this->carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
		$this->carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
		$this->circulationApiLogin = $configArray['Catalog']['circulationApiLogin'];
		$this->circulationApiPassword = $configArray['Catalog']['circulationApiPassword'];
		$this->apiURL = $configArray['Catalog']['apiURL'];
		$this->apiDebugMode = $configArray['Catalog']['apiDebugMode'];
		$this->apiReportMode = $configArray['Catalog']['apiReportMode'];
		$this->itemApiWsdl = $this->apiURL . 'ItemAPI.wsdl';
		$this->circulationApiWsdl = $this->apiURL . 'CirculationAPI.wsdl';
//		$this->alias = $configArray['Catalog']['staffInitials'];

		$this->apiDebugMode = true;
		$this->apiReportMode = false;

	}

	function getCarlXDataViaSQL($reportDate, $mnpsLimitlessCondition, $staffStudentCondition) {
		// MNPS vs Limitless condition
		$mnpsLimitlessSQL = "";
		if (!in_array($mnpsLimitlessCondition, $this->mnpsLimitlessConditions)) {
			throw new InvalidArgumentException("Invalid MNPS/Limitless Libraries condition");
		} else if ($mnpsLimitlessCondition === "MNPS") {
			$mnpsLimitlessSQL = <<<EOT
    and co.envbranch != 29 -- Exclude the Limitless Libraries checkout terminals
    and bre.branchgroup = 2 -- Only envbranch at MNPS
    and bri.branchgroup = 2 -- Only MNPS items
EOT;
		} else if ($mnpsLimitlessCondition === "LimitlessLibraries") {
			$mnpsLimitlessSQL = <<<EOT
	and co.envbranch = 29 -- Only the Limitless Libraries checkout terminals
	and bri.branchgroup = 1 -- Only Limitless Libraries (NPL) items
EOT;
		}

		// staff vs. student condition
		$staffStudentSQL = "";
		if (!in_array($staffStudentCondition, $this->staffStudentConditions)) {
			throw new InvalidArgumentException("Invalid staff/student condition");
		} else if ($staffStudentCondition === "staff") {
			$staffStudentSQL = <<<EOT
	and regexp_like(patronid, '^190[0-9]{6}$') -- MNPS student IDs start with 190, followed by 6 digits, i.e., NOT MNPS staff or NPL patrons
	and patronid not like '190999%' -- Exclude test student patronids
EOT;
		} else if ($staffStudentCondition === "student") {
			$staffStudentSQL = <<<EOT
	and regexp_like(patronid, '^[0-9]{6,7}$') -- MNPS staff IDs are 6 or 7 digits, i.e., NOT MNPS students or NPL patrons
	and patronid not like '999%' -- Exclude test staff patronids
EOT;
		}

		$sql = <<<EOT
-- DAILY CHECKOUT REPORT FOR MNPS DATA WAREHOUSE
-- Configurable for MNPS Items vs. Limitless Libraries (NPL) Items
-- Configurable for MNPS Students vs. MNPS Staff
-- Does NOT include NPL Item checkouts at NPL branches
-- Does NOT include transactions where checkout is likely assigned to the wrong patron
with co as (
    select
        *
    from txlog_v2
	where systemtimestamp >= to_date('$reportDate','YYYY-MM-DD') 
	  and systemtimestamp < to_date('$reportDate','YYYY-MM-DD') + 1 -- DAILY REPORT
	  	and patronid = '190248976'

)
, cos as (
    select
        substr(bre.branchcode,-3) as schoolcode_e
        , substr(bri.branchcode,-3) as schoolcode_i
        , substr(brp.branchcode,-3) as schoolcode_p
        , to_char(co.systemtimestamp, 'YYYY-MM-DD') as checkoutdate
--        , to_char(co.systemtimestamp, 'HH24:MI:SS') as checkouttime -- useful for troubleshooting
        , co.*
    from co
    left join branch_v2 bre on co.envbranch = bre.branchnumber
    left join branch_v2 bri on co.itembranch = bri.branchnumber
    left join branch_v2 brp on co.patronbranchofregistration = brp.branchnumber
    where 1=1 -- throwaway line to make it easier to add conditions
    $mnpsLimitlessSQL
    $staffStudentSQL
)
--select * from cos; -- useful for troubleshooting when you want transactions
, coss as (
    select
--
-- CASE STATEMENT BELOW TO BE USED ONLY IF SCHOOLCODE IS BASED ON ENVBRANCH
-- HANDLES LEAD CAMERON COLLEGE PREP -> LEAD ACADEMY HIGH
--        case
--            when schoolcode_e = '71181' and schoolcode_p = '78508' then '508' -- LEAD Cameron envbranch + LEAD Academy patronbranch = LEAD Academy High School -- it might be useful to include 'and patronbty in (31,32,33,34,37,46,47)'...
--            else substr(cos.branchcode, -3) 
--        end as schoolCode
--
-- CASE STATEMENT BELOW TO BE USED ONLY IF SCHOOLCODE IS BASED ON PATRON
-- HANDLES GLITCHES IN SCARLESS WHERE STUDENT SCHOOL FAILS TO UPDATE
-- I.E., WHEN ENV BRANCH MATCHES ITEM BRANCH, BUT PATRON BRANCH DOES NOT MATCH 
-- AND THE RUNTIME PATRON BRANCH DOES MATCH ENV AND ITEM, 
-- THEN CHANGE TRANSACTION PATRON BRANCH TO THE RUNTIME PATRON BRANCH
        case
        -- LEAD Cameron/LEAD Academy: 181 = env = item, but patron = 508 OR runtime patron = 508
            when schoolcode_e = '181'
                and schoolcode_i = '181'
                and (schoolcode_p = '508' or substr(b.branchcode,-3) = '508')
                then schoolcode_p -- i.e., 508
        -- env = item = runtime patron defaultbranch != tx patronregistrationbranch; assume scarless failed to update student location, make schoolcode = env branch
            when schoolcode_e = schoolcode_i 
                and schoolcode_e != schoolcode_p 
                and schoolcode_e = substr(b.branchcode,-3) 
                then schoolcode_e
        -- env = item != runtime patron defaultbranch != tx patronregistrationbranch; assume checked out item assigned to wrong patron, make schoolcode NULL
            when  schoolcode_e = schoolcode_i 
                and schoolcode_e != schoolcode_p 
                and schoolcode_e != substr(b.branchcode,-3)
                then NULL
        -- env != item != patron; assume THE ENDTIMES ARE UPON US... RUN!!! and make schoolCode NULL
            when schoolcode_e != schoolcode_i
                and schoolcode_e != schoolcode_p
                and schoolcode_i != schoolcode_p
                then NULL
        -- item = patron != env (e.g., librarian logs in as the wrong branch)
        -- OR env = patron != item (e.g., librarian has snuck into another school and stolen books and not bothered to change owningbranch in Carl)
            else substr(cos.schoolcode_p, -3) 
        end as schoolCode
        , to_char(cos.systemtimestamp, 'YYYYMMDD') as yearMonthDay
        , cos.patronid as studentID
    from cos 
    left join patron_v2 p on cos.patronid = p.patronid
    left join branch_v2 b on p.defaultbranch = b.branchnumber
)
select
    schoolcode
    , yearmonthday
    , studentid
    , count(*) as countOfCheckouts
from coss
where schoolCode is not null -- skip records where schoolCode is NULL
group by schoolcode, yearmonthday, studentid
order by studentid, yearmonthday, schoolcode
EOT;
		// connect to carlx oracle db
		$conn = oci_connect($this->carlx_db_php_user, $this->carlx_db_php_password, $this->carlx_db_php, 'AL32UTF8');
		if (!$conn) {
			$e = oci_error();
			trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
		}
		$stid = oci_parse($conn, $sql);
		// TO DO: consider tuning oci_set_prefetch to improve performance. See https://docs.oracle.com/database/121/TDPPH/ch_eight_query.htm#TDPPH172
		oci_set_prefetch($stid, 10000);
		oci_execute($stid);
		$data = array();
		while (($row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS)) != false) {
			$data[] = $row;
		}
		oci_free_statement($stid);
		oci_close($conn);
		return $data;
	}

	function writeData($rows, $reportDate, $mnpsLimitlessCondition, $staffStudentCondition) {
		$filename = $this->reportPath . 'LibraryServices-Checkouts-' . $mnpsLimitlessCondition . '-' . $staffStudentCondition . '-' . $reportDate . '.txt';
		$fp = fopen($filename, 'w');
		$header = array('schoolcode', 'yearmonthday', 'studentid', 'countOfCheckouts');
		fputcsv($fp, $header, "\t");
		foreach ($rows as $row) {
			fputcsv($fp, $row, "\t");
		}
		fclose($fp);
	}

	function setReportDate($date = null) {
		if (isset($date)) {
			$this->reportDate = date('Y-m-d', strtotime($date));
		} else {
			// default to yesterday
			$this->reportDate = date('Y-m-d', strtotime('yesterday'));
		}
	}
}

$report = new nashvilleMNPSDataWarehouseReport();
$report->getConfig();

if (!empty($argv[1])) {
	$report->setReportDate($argv[1]);
} else {
	$report->setReportDate();
}

foreach ($report->mnpsLimitlessConditions as $mnpsLimitlessCondition) {
	foreach ($report->staffStudentConditions as $staffStudentCondition) {
		$carlXRows = $report->getCarlXDataViaSQL($report->reportDate, $mnpsLimitlessCondition, $staffStudentCondition);
		$report->writeData($carlXRows, $report->reportDate, $mnpsLimitlessCondition, $staffStudentCondition);
	}
}

?>
