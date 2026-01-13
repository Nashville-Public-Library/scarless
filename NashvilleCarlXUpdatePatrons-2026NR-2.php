<?php

// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatrons-2026NR-2.php\n';
// Should be run by scarless crontab for 2026 01 20 through 2027 04 30
// see https://trello.com/c/hPL1UbnP/4435-update-nr-cards-and-service-area

// This script moves in-service-area non-resident patrons from full fee BTY to limited BTY
// the first run will change exp date Oct 12 2025 through Jan 20 2026 to Jan 20 2028

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';

$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php			= $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user		= $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password	= $configArray['Catalog']['carlx_db_php_password'];
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$reportPath             = '../data/';

// connect to carlx oracle db
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php);
if (!$conn) {
	$e = oci_error();
	trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}
$sql = <<<EOT
--identifies non-resident full fee accounts that are inside of our service area (after NashvilleCarlXUpdatePatrons-2026NR-1.php has been run)
-- with exp date before or equal to today
select 
	patronid
	, bty
	, to_char(expdate, 'YYYY-MM-DD')
from patron_v2
where bty in (3,14,15)
and expdate <= (sysdate)
EOT;
$stid = oci_parse($conn, $sql);
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$df = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS_2026NR-2.CSV", 'w');

while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($df, $row);
}
fclose($df);
echo "CARLX MNPS patrons to be updated retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

$records = array();
$fhnd = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS_2026NR-2.CSV", "r");
if ($fhnd){
        while (($data = fgetcsv($fhnd)) !== FALSE){
                $records[] = $data;
        }
}

$i = 0;
$errors = array();
$client = new SOAPClient($patronApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
foreach ($records as $patron) {
	// CREATE PATRON UPDATE REQUEST
	$requestName = 'updatePatron';
	$tag = $patron[0] . ' : ' . $requestName;
	$request = new stdClass();
	$request->Modifiers = new stdClass();
	$request->Modifiers->DebugMode = false;
	$request->Modifiers->ReportMode = false;
	$request->SearchType = 'Patron ID';
	$request->SearchID = $patron[0]; // Patron ID
	$request->Patron = new stdClass();
	if ($patron[1] == '3') {
		$request->Patron->PatronType	= '45'; // Change from Adult Non-Resident Full Fee to Adult Non-Resident Limited
	} elseif ($patron[1] == '14') {
		$request->Patron->PatronType	= '44'; // Change from Teen Non-Resident Full Fee to Teen Non-Resident Limited
	} elseif ($patron[1] == '15') {
		$request->Patron->PatronType	= '43'; // Change from Child Non-Resident Full Fee to Child Non-Resident Limited
	}
	if ($patron[2] > strtotime('-100 days')) { // iF Exp Date is greater than 100 days BEFORE today (should only be put into use the very first run)
		$PatronExpirationDate = date('Y-m-d', strtotime('+2 years')); // Patron Expiration Date set to 2 years from now
	} else {
		$PatronExpirationDate = date('Y-m-d', $patron[2]); // Patron Expiration Date remains the same
	}
	$request->Patron->ExpirationDate	= $PatronExpirationDate;

	$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
}
?>
