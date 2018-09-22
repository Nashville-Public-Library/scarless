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

$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl		= $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode	= $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode	= $configArray['Catalog']['patronApiReportMode'];
$reportPath		= '../data/';

//////////////////// REMOVE CARLX PATRONS ////////////////////
// See https://trello.com/c/lK7HgZgX for spec

/* DEACTIVATE UNTIL 2018-09-01

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_staff_remove.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);

foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['patronid'] > 999115) { break; }
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['patronid'] . ' : removePatron';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['patronid']; // Patron ID
	$request->Patron						= new stdClass();
	$request->Patron->PatronType					= '38'; // Patron Type = Expired MNPS
	$request->Patron->DefaultBranch					= 'XMNPS'; // Patron Default Branch
	$request->Patron->LastActionBranch				= 'XMNPS'; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= 'XMNPS'; // Patron Last Edit Branch
	$request->Patron->RegBranch					= 'XMNPS'; // Patron Registration Branch
	if ($patron['collectionstatus']==0 || $patron['collectionstatus']==1 || $patron['collectionstatus']==78) {
		$request->Patron->CollectionStatus			= 'not sent';
	}
//	if (stripos($patron['EmailAddress'],'@mnps.org') > 0) {
//		$request->Patron->Email					= ''; // Patron Email
//	}
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
	$tag								= $patron['patronid'] . ' : addPatronRemoveNote';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->Modifiers->StaffID					= 'PIK'; // Pika Patron Loader
	$request->Note							= new stdClass();
	$request->Note->PatronID					= $patron['patronid']; // Patron ID
	$request->Note->NoteType					= '800'; 
	if (!empty($patron['patron_seen'])) {
		$PatronExpirationDate					= $patron['patron_seen']; // Patron Expiration Date as ISO 8601
	} else {
		$PatronExpirationDate					= date('Y-m-d', strtotime('yesterday')); // Patron Expiration Date
	}
	$request->Note->NoteText					= 'MNPS patron expired ' . $PatronExpirationDate . '. This account may be converted to NPL after staff update patron barcode, patron type, email, phone, address, branch, and guarantor.'; 
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

*/ // DEACTIVATE UNTIL 2018-09-01

//////////////////// CREATE CARLX PATRONS ////////////////////

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_staff_create.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);

foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['patronid'] > 999115) { break; }
	// CREATE REQUEST
	$requestName							= 'createPatron';
	$tag								= $patron['patronid'] . ' : ' . $requestName;
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->Patron						= new stdClass();
	$request->Patron->PatronID					= $patron['patronid']; // Patron ID
	$request->Patron->PatronType					= $patron['borrowertypecode']; // Patron Type
	$request->Patron->LastName					= $patron['patronlastname']; // Patron Name Last
	$request->Patron->FirstName					= $patron['patronfirstname']; // Patron Name First
	$request->Patron->MiddleName					= $patron['patronmiddlename']; // Patron Name Middle
	$request->Patron->SuffixName					= $patron['patronsuffix']; // Patron Name Suffix
	$request->Patron->DefaultBranch					= $patron['defaultbranch']; // Patron Default Branch
	$request->Patron->LastActionBranch				= $patron['defaultbranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['defaultbranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['defaultbranch']; // Patron Registration Branch
	$request->Patron->Email						= $patron['emailaddress']; // Patron Email
	// NON-CSV STUFF
	$request->Patron->CollectionStatus				= 'do not send';
	$request->Patron->EmailNotices					= 'send email';
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['expirationdate'])->format('c'); // Patron Expiration Date as ISO 8601
	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PatronStatusCode				= 'G'; // Patron Status Code = GOOD
	$request->Patron->RegisteredBy					= 'PIK'; // Registered By : Pika Patron Loader
	$request->Patron->RegistrationDate				= date('c'); // Registration Date, format ISO 8601
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);

// SET PIN FOR CREATED PATRON
// createPatron is not setting PIN as requested. See TLC ticket 452557
// Therefore we use updatePatron to set PIN
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['patronid'] . ' : updatePatronPIN';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['patronid']; // Patron ID
	$request->Patron						= new stdClass();
	if (stripos($patron['patronid'],'999') == 0) {
		$request->Patron->PatronPIN				= '7357';
	} else {
		$request->Patron->PatronPIN				= '2018';
	}
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

//////////////////// UPDATE CARLX PATRONS ////////////////////

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_staff_update.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);

foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['patronid'] > 999115) { break; }
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['patronid'] . ' : ' . $requestName;
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['patronid']; // Patron ID
	$request->Patron						= new stdClass();
	$request->Patron->PatronType					= $patron['borrowertypecode']; // Patron Type
	$request->Patron->LastName					= $patron['patronlastname']; // Patron Name Last
	$request->Patron->FirstName					= $patron['patronfirstname']; // Patron Name First
	$request->Patron->MiddleName					= $patron['patronmiddlename']; // Patron Name Middle
	$request->Patron->SuffixName					= $patron['patronsuffix']; // Patron Name Suffix
	$request->Patron->DefaultBranch					= $patron['defaultbranch']; // Patron Default Branch
	$request->Patron->LastActionBranch				= $patron['defaultbranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['defaultbranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['defaultbranch']; // Patron Registration Branch
	if ($patron['collectionstatus']==0 || $patron['collectionstatus']==1 || $patron['collectionstatus']==78) {
		$request->Patron->CollectionStatus			= 'do not send';
	}
	$request->Patron->Email						= $patron['emailaddress']; // Patron Email
	if (stripos($patron['patronid'],'999') == 0) {
		$request->Patron->PatronPIN				= '7357';
	} 
// PIN RESET ENDS 2018 09 03. RESTORE ON 2019 08 01
//	else {
//		$request->Patron->PatronPIN				= '2018';
//	}
	
	// NON-CSV STUFF
	$request->Patron->EmailNotices					= 'send email'; // Patron Email Notices
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['expirationdate'])->format('c'); // Patron Expiration Date as ISO 8601
	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

//////////////////// REMOVE OBSOLETE MNPS PATRON EXPIRED NOTES //////////////////// 
$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_staff_deleteExpiredNotes.csv", "r") or die("unable to open ../data/ic2carlx_mnps_staff_deleteExpiredNotes.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['patronid'] > 999115) { continue; }
	$noteIDs = explode(',', $patron['ExpiredNoteIDs']);
	foreach ($noteIDs as $noteID) {
		// CREATE REQUEST
		$requestName						= 'deletePatronNote';
		$tag							= $patron['patronid'] . ' : deleteExpiredNote ' . $noteID;
		$request						= new stdClass();
		$request->Modifiers					= new stdClass();
		$request->Modifiers->DebugMode				= $patronApiDebugMode;
		$request->Modifiers->ReportMode				= $patronApiReportMode;
		$request->NoteID					= $noteID;
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
	}
}

//////////////////// CREATE/UPDATE PATRON IMAGES ////////////////////
// if they were modified today
$iterator = new DirectoryIterator('../data/images');
$today = date_create('today')->format('U');
foreach ($iterator as $fileinfo) {
        $file = $fileinfo->getFilename();
        $mtime = $fileinfo->getMTime();
        if ($fileinfo->isFile() && preg_match('/^\d{6}.jpg$/', $file) === 1 && $mtime >= $today) {
		$requestName						= 'updateImage';
		$tag							= $patron['patronid'] . ' : ' . $requestName;
		$request						= new stdClass();
		$request->Modifiers					= new stdClass();
		$request->Modifiers->DebugMode				= $patronApiDebugMode;
		$request->Modifiers->ReportMode				= $patronApiReportMode;
		$request->SearchType					= 'Patron ID';
		$request->SearchID					= substr($file,0,9); // Patron ID
		$request->ImageType					= 'Profile'; // Patron Profile Picture vs. Signature
		$imageFilePath 						= "../data/images/" . $file;
		if (file_exists($imageFilePath)) {
			$imageFileHandle 				= fopen($imageFilePath, "rb");
			$request->ImageData				= fread($imageFileHandle, filesize($imageFilePath));
			fclose($imageFileHandle);
		} else {
// TO DO: create IMAGE NOT AVAILABLE image
		}
		if (isset($request->ImageData)) {
			$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
		}
	}
}

?>
