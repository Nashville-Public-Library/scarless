<?php

	ini_set('memory_limit', '1024M'); // or you could use 1G

// echo 'SYNTAX: path/to/php ic2carlx.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php ic2carlx.php\n';
//
// TO DO: logging
// TO DO: capture other patron api errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement; No matching records found
// TO DO: for patron data privacy, kill data files when actions are complete
// TO DO: create IMAGE NOT AVAILABLE image

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';

$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode     = $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode    = $configArray['Catalog']['patronApiReportMode'];
$reportPath             = '../data/';
$startDate				= DateTime::createFromFormat('Y-m-d', $configArray['Calendar']['startDate']);
$twentyDay				= DateTime::createFromFormat('Y-m-d', $configArray['Calendar']['twentyDay']);
$stopDate				= DateTime::createFromFormat('Y-m-d', $configArray['Calendar']['stopDate']);
$today					= DateTime::createFromFormat('Y-m-d', date('Y-m-d'));

//echo "Today is " . $today->format('Y-m-d') . "\n";
//echo "Start Date is " . $startDate->format('Y-m-d') . "\n";
//echo "Twenty Day is " . $twentyDay->format('Y-m-d') . "\n";
//echo "Stop Date is " . $stopDate->format('Y-m-d') . "\n";

//////////////////// REMOVE CARLX PATRONS : HOMEROOM ////////////////////
//// FROM STARTDATE UNTIL TWENTYDAY, REMOVES HOMEROOM FROM STUDENTS WHO WOULD OTHERWISE BE XMNPS ////
if ($today >= $startDate && $today < $twentyDay) {
	$all_rows = array();
	$fhnd = fopen("../data/ic2carlx_mnps_students_remove.csv", "r");
	if ($fhnd){
		$header = fgetcsv($fhnd);
		while ($row = fgetcsv($fhnd)) {
			$all_rows[] = array_combine($header, $row);
		}
	}
	fclose($fhnd);

	//print_r($all_rows);
	foreach ($all_rows as $patron) {
		// TESTING
		//if ($patron['PatronID'] > 190999115) { break; }
		if (!empty($patron['teacherid'] || !empty($patron['teachername']))) {
			// CREATE REQUEST
			$requestName = 'updatePatron';
			$tag = $patron['patronid'] . ' : removePatronHomeroom';
			$request = new stdClass();
			$request->Modifiers = new stdClass();
			$request->Modifiers->DebugMode = $patronApiDebugMode;
			$request->Modifiers->ReportMode = $patronApiReportMode;
			$request->SearchType = 'Patron ID';
			$request->SearchID = $patron['patronid'];
			$request->Patron = new stdClass();
			// REMOVE VALUES FOR Sponsor: Homeroom Teacher
			$request->Patron->Addresses = new stdClass();
			$request->Patron->Addresses->Address[0] = new stdClass();
			$request->Patron->Addresses->Address[0]->Type = 'Secondary';
			$request->Patron->Addresses->Address[0]->Street = ''; // Patron Homeroom Teacher ID
			$request->Patron->SponsorName = ''; // Patron Homeroom Teacher Name
			$request->Patron->LastEditDate = date('c'); // Patron Last Edit Date, format ISO 8601
			$request->Patron->LastEditedBy = 'PIK'; // Pika Patron Loader
			$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
		}
	}
}
//////////////////// REMOVE CARLX PATRONS ////////////////////
// See https://trello.com/c/lK7HgZgX for spec

if ($today >= $twentyDay && $today < $stopDate) { // FROM TWENTYDAY UNTIL STOPDATE, RUN XMNPS
	$all_rows = array();
	$fhnd = fopen("../data/ic2carlx_mnps_students_remove.csv", "r");
	if ($fhnd){
		$header = fgetcsv($fhnd);
		while ($row = fgetcsv($fhnd)) {
			$all_rows[] = array_combine($header, $row);
		}
	}
	fclose($fhnd);
	//print_r($all_rows);
	foreach ($all_rows as $patron) {
		// TESTING
		//if ($patron['patronid'] > 190999115) { break; }
		// CREATE REQUEST
		$requestName = 'updatePatron';
		$tag = $patron['patronid'] . ' : removePatron';
		$request = new stdClass();
		$request->Modifiers = new stdClass();
		$request->Modifiers->DebugMode = $patronApiDebugMode;
		$request->Modifiers->ReportMode = $patronApiReportMode;
		$request->SearchType = 'Patron ID';
		$request->SearchID = $patron['patronid'];
		$request->Patron = new stdClass();
		$request->Patron->PatronType = '38'; // Patron Type = Expired MNPS
		$request->Patron->Phone2 = ''; // Patron Secondary Phone
		$request->Patron->DefaultBranch = 'XMNPS'; // Patron Default Branch
		$request->Patron->LastEditBranch = 'XMNPS'; // Patron Last Edit Branch
		$request->Patron->RegBranch = 'XMNPS'; // Patron Registration Branch
		if ($patron['collectionstatus'] == 0 || $patron['collectionstatus'] == 1 || $patron['collectionstatus'] == 78) {
			$request->Patron->CollectionStatus = 'not sent';
		}
		if (stripos($patron['emailaddress'], '@mnpsk12.org') > 0) {
			$request->Patron->Email = ''; // Patron Email
		}
		if (stripos($patron['emailaddress'], '@mnps.org') > 0) {
			$request->Patron->Email = ''; // Patron Email
		}
		if (empty($request->Patron->Email)) {
			$request->Patron->EmailNotices = 'do not send email';
		}
		// REMOVE VALUES FOR Sponsor: Homeroom Teacher
		$request->Patron->Addresses = new stdClass();
		$request->Patron->Addresses->Address[0] = new stdClass();
		$request->Patron->Addresses->Address[0]->Type = 'Secondary';
		$request->Patron->Addresses->Address[0]->Street = ''; // Patron Homeroom Teacher ID
		$request->Patron->SponsorName = ''; // Patron Homeroom Teacher Name
		// NON-CSV STUFF
		if (!empty($patron['patron_seen'])) {
			$request->Patron->ExpirationDate = date_create_from_format('Y-m-d', $patron['patron_seen'])->format('c'); // Patron Expiration Date as ISO 8601
		} else {
			$request->Patron->ExpirationDate = date('c', strtotime('yesterday')); // Patron Expiration Date as ISO 8601
		}
		$request->Patron->LastEditDate = date('c'); // Patron Last Edit Date, format ISO 8601
		$request->Patron->LastEditedBy = 'PIK'; // Pika Patron Loader
		$request->Patron->PreferredAddress = 'Primary';
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
	// CREATE URGENT 'Former MNPS Patron' NOTE
		// CREATE REQUEST
		$requestName = 'addPatronNote';
		$tag = $patron['patronid'] . ' : addPatronRemoveNote';
		$request = new stdClass();
		$request->Modifiers = new stdClass();
		$request->Modifiers->DebugMode = $patronApiDebugMode;
		$request->Modifiers->ReportMode = $patronApiReportMode;
		$request->Modifiers->StaffID = 'PIK'; // Pika Patron Loader
		$request->Note = new stdClass();
		$request->Note->PatronID = $patron['patronid']; // Patron ID
		$request->Note->NoteType = '800';
		if (!empty($patron['patron_seen'])) {
			$PatronExpirationDate = $patron['patron_seen']; // Patron Expiration Date as ISO 8601
		} else {
			$PatronExpirationDate = date('Y-m-d', strtotime('yesterday')); // Patron Expiration Date
		}
		$request->Note->NoteText = 'MNPS patron expired ' . $PatronExpirationDate . '. Previous branchcode: ' . $patron['defaultbranch'] . '. Previous bty: ' . $patron['borrowertypecode'] . '. This account may be converted to NPL after staff update patron barcode, patron type, email, phone, address, branch, and guarantor.';
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
	}
}

//////////////////// CREATE CARLX PATRONS ////////////////////
$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_students_create.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] > 190999115) { break; }

	// SENIOR SLIDE
	// IF 1. date is April 17 2023 to May 26 2023 AND 2. patron school is Hume-Fogg or Nashville School for the Arts AND 3. patron type is 12th grade, High school no-delivery, MNPS 18+, MNPS 18+ no delivery
	// THEN SKIP. See https://trello.com/c/AXK9CIfj
	$seniorSlideStart = date_create_from_format('Y-m-d','2023-04-16')->format('U');
	$seniorSlideStop = date_create_from_format('Y-m-d','2023-05-27')->format('U');
	$today = date_create('today')->format('U');
	if ($today > $seniorSlideStart && $today < $seniorSlideStop) {
		if ($patron['DefaultBranch'] == '69450' || $patron['DefaultBranch'] == '62242') { // Hume-Fogg or Nashville School for the Arts
			if ($patron['Borrowertypecode'] == '34' || $patron['Borrowertypecode'] == '37' || $patron['Borrowertypecode'] == '46' || $patron['Borrowertypecode'] == '47') { // 12th grade, High school no-delivery, MNPS 18+, MNPS 18+ no delivery
				continue; // skip
			}
		}
	}

	// CREATE REQUEST
	$requestName							= 'createPatron';
	$tag								= $patron['PatronID'] . ' : ' . $requestName;
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->Patron						= new stdClass();
	$request->Patron->PatronID					= $patron['PatronID']; // Patron ID
	$request->Patron->PatronType					= $patron['Borrowertypecode']; // Patron Type
	$request->Patron->LastName					= $patron['Patronlastname']; // Patron Name Last
	$request->Patron->FirstName					= $patron['Patronfirstname']; // Patron Name First
	$request->Patron->MiddleName					= $patron['Patronmiddlename']; // Patron Name Middle
	$request->Patron->SuffixName					= $patron['Patronsuffix']; // Patron Name Suffix
	$request->Patron->Addresses					= new stdClass();
	$request->Patron->Addresses->Address[0]				= new stdClass();
	$request->Patron->Addresses->Address[0]->Type			= 'Primary';
	$request->Patron->Addresses->Address[0]->Street			= $patron['PrimaryStreetAddress']; // Patron Address Street
	$request->Patron->Addresses->Address[0]->City			= $patron['PrimaryCity']; // Patron Address City
	$request->Patron->Addresses->Address[0]->State			= $patron['PrimaryState']; // Patron Address State
	$request->Patron->Addresses->Address[0]->PostalCode		= $patron['PrimaryZipCode']; // Patron Address ZIP Code
	// $request->Patron->Phone1					= $patron['PrimaryPhoneNumber']; // Patron Primary Phone
	$request->Patron->Phone2					= $patron['SecondaryPhoneNumber']; // Patron Secondary Phone
	$request->Patron->DefaultBranch					= $patron['DefaultBranch']; // Patron Default Branch
//	$request->Patron->LastActionBranch				= $patron['DefaultBranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['DefaultBranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['DefaultBranch']; // Patron Registration Branch
	$request->Patron->Email						= $patron['EmailAddress']; // Patron Email
	$request->Patron->BirthDate					= $patron['BirthDate']; // Patron Birth Date as Y-m-d
	// Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= $patron['TeacherID']; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= $patron['TeacherName'];
	// NON-CSV STUFF
	$request->Patron->CollectionStatus				= 'do not send';
	$request->Patron->EmailNotices					= 'send email';
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['ExpirationDate'])->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PatronStatusCode				= 'G'; // Patron Status Code = GOOD
	if (!empty($patron['TeacherID'])) {
		$request->Patron->PreferredAddress			= 'Sponsor';
	} else {
		$request->Patron->PreferredAddress			= 'Primary';
	}
	$request->Patron->RegisteredBy					= 'PIK'; // Registered By : Pika Patron Loader
	$request->Patron->RegistrationDate				= date('c'); // Registration Date, format ISO 8601
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
// SET PIN FOR CREATED PATRON
// createPatron is not setting PIN as requested. See TLC ticket 452557
// Therefore we use updatePatron to set PIN
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['PatronID'] . ' : updatePatronPIN';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['PatronID']; // Patron ID
	$request->Patron						= new stdClass();
	if (stripos($patron['PatronID'],'190999') === 0) {
		$request->Patron->PatronPIN				= '7357';
	} else {
		$request->Patron->PatronPIN				= substr($patron['BirthDate'],5,2) . substr($patron['BirthDate'],8,2);
	}
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

//////////////////// UPDATE CARLX PATRONS ////////////////////

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_students_update.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['PatronID'] . ' : ' . $requestName;
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['PatronID']; // Patron ID
	$request->Patron						= new stdClass();
	$request->Patron->PatronType					= $patron['Borrowertypecode']; // Patron Type
	$request->Patron->LastName					= $patron['Patronlastname']; // Patron Name Last
	$request->Patron->FirstName					= $patron['Patronfirstname']; // Patron Name First
	$request->Patron->MiddleName					= $patron['Patronmiddlename']; // Patron Name Middle
	$request->Patron->SuffixName					= $patron['Patronsuffix']; // Patron Name Suffix
	$request->Patron->Addresses					= new stdClass();
	$request->Patron->Addresses->Address[0]				= new stdClass();
	$request->Patron->Addresses->Address[0]->Type			= 'Primary';
	$request->Patron->Addresses->Address[0]->Street			= $patron['PrimaryStreetAddress']; // Patron Address Street
	$request->Patron->Addresses->Address[0]->City			= $patron['PrimaryCity']; // Patron Address City
	$request->Patron->Addresses->Address[0]->State			= $patron['PrimaryState']; // Patron Address State
	$request->Patron->Addresses->Address[0]->PostalCode		= $patron['PrimaryZipCode']; // Patron Address ZIP Code
	// $request->Patron->Phone1					= $patron['PrimaryPhoneNumber']; // Patron Primary Phone
	$request->Patron->Phone2					= $patron['SecondaryPhoneNumber']; // Patron Secondary Phone
	$request->Patron->DefaultBranch					= $patron['DefaultBranch']; // Patron Default Branch
//	$request->Patron->LastActionBranch				= $patron['DefaultBranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['DefaultBranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['DefaultBranch']; // Patron Registration Branch
	if ($patron['CollectionStatus']==0 || $patron['CollectionStatus']==1 || $patron['CollectionStatus']==78) {
		$request->Patron->CollectionStatus			= 'do not send';
	}
	//$request->Patron->Email					= $patron['EmailAddress']; // Patron Email
	$request->Patron->BirthDate					= $patron['BirthDate']; // Patron Birth Date as Y-m-d
	// Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= $patron['TeacherID']; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= $patron['TeacherName'];
	if (stripos($patron['PatronID'],'190999') === 0) {
		$request->Patron->PatronPIN				= '7357';
	}
	elseif ($today >= $startDate && $today <= $twentyDay) { // From startDate until twentyDay, reset PIN to default
		$request->Patron->PatronPIN				= substr($patron['BirthDate'],5,2) . substr($patron['BirthDate'],8,2);
	}

	// NON-CSV STUFF
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['ExpirationDate'])->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	if (!empty($patron['TeacherID'])) {
		$request->Patron->PreferredAddress			= 'Sponsor';
	} else {
		$request->Patron->PreferredAddress			= 'Primary';
	}
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

//////////////////// UPDATE EMAIL ADDRESS AND NOTICES ////////////////////

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_students_updateEmail.csv", "r") or die("unable to open ../data/ic2carlx_mnps_students_updateEmail.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['PatronID'] . ' : updatePatronEmail';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['PatronID']; // Patron ID
	$request->Patron						= new stdClass();
	$request->Patron->Email						= $patron['Email']; // Email Address
	$request->Patron->EmailNotices					= $patron['EmailNotices']; // Email Address
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

//////////////////// CREATE GUARANTOR NOTES ////////////////////

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_students_createNoteGuarantor.csv", "r") or die("unable to open ../data/ic2carlx_mnps_students_createNoteGuarantor.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] > 190999115) { break; }
	// CREATE REQUEST
	$requestName						= 'addPatronNote';
	$tag								= $patron['PatronID'] . ' : addPatronGuarantor';
	$request							= new stdClass();
	$request->Modifiers					= new stdClass();
	$request->Modifiers->DebugMode		= $patronApiDebugMode;
	$request->Modifiers->ReportMode		= $patronApiReportMode;
	$request->Modifiers->StaffID		= 'PIK'; // Pika Patron Loader
	$request->Note						= new stdClass();
	$request->Note->PatronID			= $patron['PatronID']; // Patron ID
	$request->Note->NoteType			= 2;
	$request->Note->NoteText			= $patron['Guarantor']; // Patron Guarantor as Note
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

//////////////////// REMOVE OBSOLETE MNPS PATRON EXPIRED NOTES ////////////////////

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_students_deleteExpiredNotes.csv", "r") or die("unable to open ../data/ic2carlx_mnps_students_deleteExpiredNotes.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] > 190999115) { continue; }
	$noteIDs = explode(',', $patron['ExpiredNoteIDs']);
	foreach ($noteIDs as $noteID) {
		// CREATE REQUEST
		$requestName						= 'deletePatronNote';
		$tag							= $patron['PatronID'] . ' : deleteExpiredNote ' . $noteID;
		$request						= new stdClass();
		$request->Modifiers					= new stdClass();
		$request->Modifiers->DebugMode				= $patronApiDebugMode;
		$request->Modifiers->ReportMode				= $patronApiReportMode;
		$request->NoteID					= $noteID;
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
	}
}

//////////////////// REMOVE OBSOLETE "NPL: MNPS GUARANTOR EFFECTIVE" NOTES //////////////////// 

$all_rows = array();
$fhnd = fopen("../data/ic2carlx_mnps_students_deleteGuarantorNotes.csv", "r") or die("unable to open ../data/ic2carlx_mnps_students_deleteGuarantorNotes.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] > 190999115) { continue; }
	$noteIDs = explode(',', $patron['DeleteGuarantorNoteIDs']);
	foreach ($noteIDs as $noteID) {
		// CREATE REQUEST
		$requestName						= 'deletePatronNote';
		$tag							= $patron['PatronID'] . ' : deleteGuarantorNote ' . $noteID;
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
$iterator = new DirectoryIterator('../data/images/students');
$today = date_create('today')->format('U');
//$today = date_create('2020-08-10')->format('U');
foreach ($iterator as $fileinfo) {
        $file = $fileinfo->getFilename();
        $mtime = $fileinfo->getMTime();
        if ($fileinfo->isFile() && preg_match('/^190\d{6}.jpg$/', $file) === 1 && $mtime >= $today) {
		$requestName						= 'updateImage';
		$tag							= substr($file,0,9) . ' : ' . $requestName;
		$request						= new stdClass();
		$request->Modifiers					= new stdClass();
		$request->Modifiers->DebugMode				= $patronApiDebugMode;
		$request->Modifiers->ReportMode				= $patronApiReportMode;
		$request->SearchType					= 'Patron ID';
		$request->SearchID					= substr($file,0,9); // Patron ID
		$request->ImageType					= 'Profile'; // Patron Profile Picture vs. Signature
		$imageFilePath 						= "../data/images/students/" . $file;
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
