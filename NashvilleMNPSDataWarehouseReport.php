<?php

// Gather monthly report for MNPS Data Warehouse
// 2024 11 16
// James Staub
// Nashville Public Library
// Usage: php NashvilleMNPSDataWarehouseReport.php YYYYMM
// MNPS Data Warehouse Reports include
// + tanglible items checked out
// + online items checked out
class nashvilleMNPSDataWarehouseReport
{
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

	function getConfig()
	{
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

	function getCarlXDataViaSQL($reportDate)
	{
		// Calculate the number of months difference
		$currentDate = new DateTime();
		$reportDateObj = DateTime::createFromFormat('Ym', $reportDate);
		$monthsDifference = ($currentDate->format('Y') - $reportDateObj->format('Y')) * 12 + ($currentDate->format('m') - $reportDateObj->format('m'));

		$sql = <<<EOT
-- MNPS SCHOOL LIBRARY MONTHLY CHECKOUTS
-- Does NOT include Limitless Libraries
-- Does include checkouts to MNPS staff
with co as (
    select
        *
    from txlog_v2
    where systemtimestamp >= add_months(trunc(sysdate, 'MON'), -$monthsDifference) -- provided month
    and systemtimestamp < add_months(trunc(sysdate, 'MON'), -$monthsDifference + 1) -- next month after provided month
    and transactiontype = 'CH'
)
, cos as (
    select
        *
    from co
    left join branch_v2 br on co.envbranch = br.branchnumber
    where co.envbranch != 29 -- Exclude the Limitless Libraries checkout terminals
    and branchgroup = 2
)
, coss as (
    select
        substr(cos.branchcode, -3) as schoolCode
        , to_char(cos.systemtimestamp, 'YYYYMMDD') as yearMonthDay
        , patronid as studentID
    from cos
)
select
    schoolcode
    , yearmonthday
    , studentid
    , count(*) as countOfCheckouts
from coss
group by schoolcode, yearmonthday, studentid
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

	function writeData($rows)
	{
		$filename = $this->reportPath . 'Library Services - Checkouts Tangible - ' . $this->reportDate . '.txt';
		$fp = fopen($filename, 'w');
		$header = array('schoolcode', 'yearmonthday', 'studentid', 'countOfCheckouts');
		fputcsv($fp, $header, "\t");
		foreach ($rows as $row) {
			fputcsv($fp, $row, "\t");
		}
		fclose($fp);
	}

	function setReportDate($date) {
		$this->reportDate = $date;
	}
}

if (isset($argv[1])) {
	$reportDate = $argv[1];
} else {
	// default to the previous month
	$reportDate = date('Ym', strtotime('first day of last month'));
}

$report = new nashvilleMNPSDataWarehouseReport();
$report->getConfig();
$report->setReportDate($reportDate);
$carlXRows = $report->getCarlXDataViaSQL($reportDate);
$report->writeData($carlXRows);

?>
