<?php

// Gather daily in-house circulation report for MNPS branches
// Usage: php NashvilleMNPSDataWarehouseReport-InHouseCirc.php [YYYY-MM-DD]

class nashvilleMNPSInHouseCircReport {
    private $reportPath;
    private $carlx_db_php;
    private $carlx_db_php_user;
    private $carlx_db_php_password;
    public $reportDate;

    function getConfig() {
        date_default_timezone_set('America/Chicago');
        $this->reportPath = '../data/';
        $configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
        $this->carlx_db_php = $configArray['Catalog']['carlx_db_php'];
        $this->carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
        $this->carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
    }

    function getInHouseCircData($reportDate) {
        $sql = <<<EOT
-- In-house circulation by MNPS branch for the previous day
with txp as ( -- Transaction, plus the Previous one
	select t.*,
		lag(transactiontype) over (partition by item order by systemtimestamp)  as prev_transactiontype,
		lag(itemstatusbefore) over (partition by item order by systemtimestamp) as prev_statusbefore,
		lag(itemstatusafter) over (partition by item order by systemtimestamp)  as prev_statusafter,
		lag(systemtimestamp) over (partition by item order by systemtimestamp)  as prev_time,
		(systemtimestamp - lag(systemtimestamp) over (partition by item order by systemtimestamp))*24 as time_diff -- in hours
	from txlog_v2 t
	where systemtimestamp >= to_date('$reportDate','YYYY-MM-DD')
		and systemtimestamp < to_date('$reportDate','YYYY-MM-DD') + 1 -- DAILY REPORT
)
, osr as ( -- on-shelf returns
	select
		txp.item,
		b.branchcode
	from txp
	left join branch_v2 b on txp.itembranch = b.branchnumber
	where b.branchgroup = 2                   -- MNPS
		and regexp_like(b.branchcode, '^[0-9]') -- exclude Limitless branches
		and transactiontype = 'DS'
		and itemstatusbefore = 'S'
		and (time_diff > 2 -- more than 2 hours since previous transaction
			or time_diff is null) -- or no previous transaction within the report day
)
select
	substr(osr.branchcode,3,3) as schoolCode
	, count(*) as inHouseCirc
	, '$reportDate' as inHouseCircDate
from osr
group by osr.branchcode
order by substr(osr.branchcode,3,3) asc
EOT;

        // connect to carlx oracle db
        $conn = oci_connect($this->carlx_db_php_user, $this->carlx_db_php_password, $this->carlx_db_php, 'AL32UTF8');
        if (!$conn) {
            $e = oci_error();
            trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
        }
        $stid = oci_parse($conn, $sql);
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

    function writeData($rows, $reportDate) {
        $filename = $this->reportPath . 'LibraryServices-InHouseCirc-MNPS-' . $reportDate . '.txt';
        $fp = fopen($filename, 'w');
        $header = array('branchnumber', 'inHouseCirc', 'inHouseCircDate');
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

$report = new nashvilleMNPSInHouseCircReport();
$report->getConfig();

if (!empty($argv[1])) {
    $report->setReportDate($argv[1]);
} else {
    $report->setReportDate();
}

$inHouseCircRows = $report->getInHouseCircData($report->reportDate);
$report->writeData($inHouseCircRows, $report->reportDate);
?>