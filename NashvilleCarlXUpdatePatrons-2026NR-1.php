<?php

// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatrons-2026NR-1.php\n';
// see https://trello.com/c/hPL1UbnP/4435-update-nr-cards-and-service-area

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
select 
	patronid
	, to_char(expdate, 'YYYY-MM-DD')
from patron_v2
where bty in (3,14,15,43,44,45)
and zip1 not in (
'37010',
'37014',
'37015',
'37019',
'37020',
'37022',
'37025',
'37027',
'37029',
'37031',
'37032',
'37033',
'37034',
'37035',
'37036',
'37037',
'37040',
'37042',
'37043',
'37046',
'37047',
'37048',
'37049',
'37051',
'37052',
'37055',
'37060',
'37062',
'37064',
'37066',
'37067',
'37069',
'37072',
'37073',
'37074',
'37075',
'37076',
'37080',
'37082',
'37085',
'37086',
'37087',
'37090',
'37091',
'37098',
'37118',
'37122',
'37127',
'37128',
'37129',
'37130',
'37132',
'37135',
'37137',
'37138',
'37140',
'37141',
'37142',
'37143',
'37146',
'37148',
'37153',
'37160',
'37165',
'37167',
'37171',
'37172',
'37174',
'37179',
'37180',
'37181',
'37183',
'37184',
'37186',
'37187',
'37188',
'37191',
'37221',
'38401',
'38451',
'38454',
'38461',
'38474',
'38476',
'38482',
'38487')
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
if ($fhnd) {
    while (($data = fgetcsv($fhnd)) !== false) {
        $records[] = $data;
    }
    fclose($fhnd);
}

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
	$request->Patron->PatronType				= '52'; // Out of Serv Area - No Renewal
	$PatronExpirationDate = null;
	$PatronExpirationDate = date_create_from_format('Y-m-d', $patron[1])->format('c');
	$request->Patron->ExpirationDate			= $PatronExpirationDate;
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
	// CREATE URGENT 'DO NOT RENEW' NOTE
	$requestName = 'addPatronNote';
	$tag = $patron[0] . ' : addPatronRemoveNote';
	$request = new stdClass();
	$request->Modifiers = new stdClass();
	$request->Modifiers->DebugMode = $patronApiDebugMode;
	$request->Modifiers->ReportMode = $patronApiReportMode;
	$request->Modifiers->StaffID = 'PIK'; // Pika Patron Loader
	$request->Note = new stdClass();
	$request->Note->PatronID = $patron[0]; // Patron ID
	$request->Note->NoteType = '800';
	$request->Note->NoteText = 'DO NOT RENEW';
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
}
?>
