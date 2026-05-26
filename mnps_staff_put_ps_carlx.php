<?php

// coded as a one-off to load 50+ records for Promising Scholars faux homeroom teachers in 2026

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';

$configArray			= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl			= $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode		= $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode	= $configArray['Catalog']['patronApiReportMode'];
$reportPath				= '../data/';

//////////////////// CREATE CARLX PATRONS ////////////////////

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_staff_PS_create.csv", "r"); // N.B. `ps` for Promising Scholars
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
$client = new SOAPClient($patronApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['patronid'] > 999115) { break; }
	// CREATE REQUEST
	$requestName						= 'createPatron';
	$tag								= $patron['patronid'] . ' : ' . $requestName;
	$request							= new stdClass();
	$request->Modifiers					= new stdClass();
	$request->Modifiers->DebugMode		= $patronApiDebugMode;
	$request->Modifiers->ReportMode		= $patronApiReportMode;
	$request->Patron					= new stdClass();
	$request->Patron->PatronID			= $patron['patronid']; // Patron ID
	$request->Patron->PatronType		= $patron['borrowertypecode']; // Patron Type
	$request->Patron->LastName			= $patron['patronlastname']; // Patron Name Last
	$request->Patron->FirstName			= $patron['patronfirstname']; // Patron Name First
	$request->Patron->MiddleName		= $patron['patronmiddlename']; // Patron Name Middle
	$request->Patron->SuffixName		= $patron['patronsuffix']; // Patron Name Suffix
	$request->Patron->DefaultBranch		= $patron['defaultbranch']; // Patron Default Branch
//	$request->Patron->LastActionBranch	= $patron['defaultbranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch	= $patron['defaultbranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch			= $patron['defaultbranch']; // Patron Registration Branch
	$request->Patron->Email				= $patron['emailaddress']; // Patron Email
	// NON-CSV STUFF
	if ($patron['borrowertypecode'] == 13 || $patron['borrowertypecode'] == 40 || $patron['borrowertypecode'] == 51) {
		$request->Patron->CollectionStatus	= 'do not send';
	} else {
		$request->Patron->CollectionStatus	= 'not sent';
	}
	$request->Patron->EmailNotices		= 'send email';
	$request->Patron->ExpirationDate	= date_create_from_format('Y-m-d',$patron['expirationdate'])->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->LastActionDate	= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate		= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy		= 'PIK'; // Pika Patron Loader
	$request->Patron->PatronStatusCode	= 'G'; // Patron Status Code = GOOD
	$request->Patron->RegisteredBy		= 'PIK'; // Registered By : Pika Patron Loader
	$request->Patron->RegistrationDate	= date('c'); // Registration Date, format ISO 8601
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);

// SET PIN FOR CREATED PATRON
// createPatron is not setting PIN as requested. See TLC ticket 452557
// Therefore we use updatePatron to set PIN
	// CREATE REQUEST
	$requestName						= 'updatePatron';
	$tag								= $patron['patronid'] . ' : updatePatronPIN';
	$request							= new stdClass();
	$request->Modifiers					= new stdClass();
	$request->Modifiers->DebugMode		= $patronApiDebugMode;
	$request->Modifiers->ReportMode		= $patronApiReportMode;
	$request->SearchType				= 'Patron ID';
	$request->SearchID					= $patron['patronid']; // Patron ID
	$request->Patron					= new stdClass();
	if (stripos($patron['patronid'],'998') === 0) { // NB Promising Scholars faux staff have a 998 prefix
		$request->Patron->PatronPIN		= '7357';
	} else {
		$request->Patron->PatronPIN		= createRandomPIN();
	}
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
}

?>
