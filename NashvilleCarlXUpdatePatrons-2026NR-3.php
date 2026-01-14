<?php

// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatrons-2026NR-3.php\n';
// see https://trello.com/c/hPL1UbnP/4435-update-nr-cards-and-service-area
// One-off script to increase expiration date of no-fee non-residents by 2 years
// If expdate < today, then do not change
// If today >= expdate < today + 730, then +730 days
// if expdate > today + 730, then do not change

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';

$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php			= $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user		= $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password	= $configArray['Catalog']['carlx_db_php_password'];
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode		= $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode	= $configArray['Catalog']['patronApiReportMode'];
$reportPath             = '../data/';

// connect to carlx oracle db
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php);
if (!$conn) {
	$e = oci_error();
	trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}
$sql = <<<EOT
--identifies accounts that are outside of our service area (or vice versa) by zip code
select patronid
from patron_v2
where bty in (4,16,17)
EOT;
$stid = oci_parse($conn, $sql);
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$df = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS_2026NR-1.CSV", 'w');

while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($df, $row);
}
fclose($df);
echo "CARLX MNPS patrons to be updated retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

$records = array();
$fhnd = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS_2026NR-1.CSV", "r");
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
	$request->Modifiers->DebugMode = $patronApiDebugMode;
	$request->Modifiers->ReportMode = $patronApiReportMode;
	$request->SearchType = 'Patron ID';
	$request->SearchID = $patron[0]; // Patron ID
	$request->Patron = new stdClass();
	$request->Patron->PatronType					= '52'; // Out of Serv Area - No Renewal
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
}
?>
