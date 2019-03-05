<?php

// echo 'SYNTAX: path/to/php ic2carlx.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php ic2carlx.php\n';
//
// TO DO: logging
// TO DO: capture other patron api errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement; No matching records found
// TO DO: for patron data privacy, kill data files when actions are complete
// TO DO: create IMAGE NOT AVAILABLE image

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'PEAR.php';
require_once 'ic2carlx_put_carlx.php';

$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode     = $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode    = $configArray['Catalog']['patronApiReportMode'];
$reportPath             = '../data/';

//////////////////// REMOVE CARLX PATRONS ////////////////////
// See https://trello.com/c/lK7HgZgX for spec

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_students_remove.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['PatronID'] . ' : removePatron';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['PatronID']; // Patron ID
	$request->Patron						= new stdClass();
	$request->Patron->Addresses					= new stdClass();
	$request->Patron->Addresses->Address[0]				= new stdClass();
	$request->Patron->Addresses->Address[0]->Type			= 'Primary';
	$request->Patron->Addresses->Address[0]->Street			= '';
	$request->Patron->Addresses->Address[0]->City			= '';
	$request->Patron->Addresses->Address[0]->State			= '';
	$request->Patron->Addresses->Address[0]->PostalCode		= '';
	$request->Patron->PatronType					= '38'; // Patron Type = Expired MNPS
	$request->Patron->Phone2					= ''; // Patron Secondary Phone
	$request->Patron->DefaultBranch					= 'XMNPS'; // Patron Default Branch
	$request->Patron->LastActionBranch				= 'XMNPS'; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= 'XMNPS'; // Patron Last Edit Branch
	$request->Patron->RegBranch					= 'XMNPS'; // Patron Registration Branch
	if ($patron['CollectionStatus']==0 || $patron['CollectionStatus']==1 || $patron['CollectionStatus']==78) {
		$request->Patron->CollectionStatus			= 'not sent';
	}
	if (stripos($patron['EmailAddress'],'@mnpsk12.org') > 0) {
		$request->Patron->Email					= ''; // Patron Email
	}
	if (stripos($patron['EmailAddress'],'@mnps.org') > 0) {
		$request->Patron->Email					= ''; // Patron Email
	}
	// REMOVE VALUES FOR Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= ''; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= ''; // Patron Homeroom Teacher Name
	// NON-CSV STUFF
	if (!empty($patron['patron_seen'])) {
		$request->Patron->ExpirationDate			= date_create_from_format('Y-m-d',$patron['patron_seen'])->format('c'); // Patron Expiration Date as ISO 8601
	} else {
		$request->Patron->ExpirationDate			= date('c', strtotime('yesterday')); // Patron Expiration Date as ISO 8601
	}
	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PreferredAddress				= 'Primary';
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
// CREATE URGENT 'Former MNPS Patron' NOTE
	// CREATE REQUEST
	$requestName							= 'addPatronNote';
	$tag								= $patron['PatronID'] . ' : addPatronRemoveNote';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->Modifiers->StaffID					= 'PIK'; // Pika Patron Loader
	$request->Note							= new stdClass();
	$request->Note->PatronID					= $patron['PatronID']; // Patron ID
	$request->Note->NoteType					= '800'; 
	if (!empty($patron['patron_seen'])) {
		$PatronExpirationDate					= $patron['patron_seen']; // Patron Expiration Date as ISO 8601
	} else {
		$PatronExpirationDate					= date('Y-m-d', strtotime('yesterday')); // Patron Expiration Date
	}
	$request->Note->NoteText					= 'MNPS patron expired ' . $PatronExpirationDate . '. This account may be converted to NPL after staff update patron barcode, patron type, email, phone, address, branch, and guarantor.'; 
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

?>
