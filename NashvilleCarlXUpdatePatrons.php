<?php

// TO DO: set up github repository

// 20180221 built to update a list of defunct MNPS patrons BTY Patron Type to 37 
// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatrons.php\n';

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';

$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$reportPath             = '../data/';

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
	$tag = $patron['patronid'] . ' : ' . $requestName;
	$request = new stdClass();
	$request->Modifiers = new stdClass();
	$request->Modifiers->DebugMode = false;
	$request->Modifiers->ReportMode = false;
	$request->SearchType = 'Patron ID';
	$request->SearchID = $patron[0]; // Patron ID
	$request->Patron = new stdClass();
	$request->Patron->PatronPIN = createRandomPIN();
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

	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}
?>
