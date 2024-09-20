<?php

// TO DO: set up github repository

// 20180221 built to update a list of defunct MNPS patrons BTY Patron Type to 37 
// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatrons.php\n';

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
-- Belmont patrons with EXPDATE = 01-OCT-24 -- to be updated to 01-OCT-25
	select
		patronid
	from patron_v2 p
	where bty = 48
	and trunc(expdate) = '01-OCT-24'
	and patronid like 'B%'
	order by patronid
	fetch first 10000 rows only -- php has problems on server and desktop running large update sets, see https://trello.com/c/2eN74bgA/3992-update-mnps-expiration-date#comment-6637a417529f6f83bc704ddd
EOT;
$stid = oci_parse($conn, $sql);
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$df = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS.CSV", 'w');

while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($df, $row);
}
fclose($df);
echo "CARLX MNPS patrons to be updated retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

$records = array();
$fhnd = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS.CSV", "r");
if ($fhnd){
        while (($data = fgetcsv($fhnd)) !== FALSE){
                $records[] = $data;
        }
}

$i = 0;
$errors = array();
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
//	$request->Patron->PatronPIN = createRandomPIN(); // Create random PIN
//	$request->Patron->DefaultBranch					= '117'; // McMurray
//	$request->Patron->PatronType					= '42';
//	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2020-02-15')->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->PatronStatusCode				= 'G'; // GOOD
//	$request->Patron->PatronPIN					= 'DENIED';
//	$request->Patron->PatronID					= preg_replace('/^300/','3000',$patron[0]); // ILL patronid fix 20190425
//	$request->Patron->PatronType					= '9'; // ILL Customer
//	$request->Patron->DefaultBranch					= 'IL'; // ILL Office
//	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2021-04-17')->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->SponsorName					= 'Woot';
//	$request->Patron->Addresses					= new stdClass();
//	$request->Patron->Addresses->Address[0]				= new stdClass();
//	$request->Patron->Addresses->Address[0]->Type			= 'Secondary'; // Address type "secondary" = Sponsor
//	$request->Patron->Addresses->Address[0]->Street			= '3007111'; // Address type "secondary", street = teacher id
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2025-10-01')->format('c'); // Patron Expiration Date as ISO 8601

	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}
?>
