<?php

// echo 'SYNTAX: path/to/php ic2carlx.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php ic2carlx.php\n';
// 
// TO DO: logging
// TO DO: retry after oracle connect error
// TO DO: review oracle php error handling https://docs.oracle.com/cd/E17781_01/appdev.112/e18555/ch_seven_error.htm#TDPPH165
// TO DO: capture other patron api errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement; No matching records found
// TO DO: for patron data privacy, kill data files when actions are complete
// TO DO: create IMAGE NOT AVAILABLE image

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'PEAR.php';

$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php		= $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user	= $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password	= $configArray['Catalog']['carlx_db_php_password'];
$patronApiWsdl		= $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode	= $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode	= $configArray['Catalog']['patronApiReportMode'];
$reportPath		= '../data/';

//////////////////// FUNCTIONS ////////////////////

function callAPI($wsdl, $requestName, $request, $tag) {
	$connectionPassed = false;
	$numTries = 0;
	$result = new stdClass();
	$result->response = "";
	while (!$connectionPassed && $numTries < 3) {
		try {
			$client = new SOAPClient($wsdl, array('connection_timeout' => 3, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
			$result->response = $client->$requestName($request);
//echo "REQUEST:\n" . $client->__getLastRequest() . "\n";
			$connectionPassed = true;
			if (is_null($result->response)) {$result->response = $client->__getLastResponse();}
			if (!empty($result->response)) {
				if (gettype($result->response) == 'object') {
					$ShortMessage[0] = $result->response->ResponseStatuses->ResponseStatus->ShortMessage;
					$result->success = $ShortMessage[0] == 'Successful operation';
				} else if (gettype($result->response) == 'string') {
					$result->success = stripos($result->response, '<ns2:ShortMessage>Successful operation</ns2:ShortMessage>') !== false;
					preg_match('/<ns2:LongMessage>(.+?)<\/ns2:LongMessage>/', $result->response, $longMessages);
					preg_match('/<ns2:ShortMessage>(.+?)<\/ns2:ShortMessage>/', $result->response, $shortMessages);
				}
				if(!$result->success) {
					$result->error = "ERROR: " . $tag . " : " . (isset($longMessages[1]) ? ' : ' . $longMessages[1] : (isset($shortMessages[0]) ? ' : ' . $shortMessages[0] : ''));
				}
			} else {
				$result->error = "ERROR: " . $tag . " : No SOAP response from API.";
			}
		} catch (SoapFault $e) {
			if ($numTries == 2) { $result->error = "EXCEPTION: " . $tag . " : " . $e->getMessage(); }
		}
		$numTries++;
	}
	if (isset($result->error)) {
		echo "$result->error\n";
		$errors[] = $result->error;
	} else {
		echo "SUCCESS: " . $tag . "\n";
	}
	return $result;
}

//////////////////// ORACLE DB ////////////////////

// connect to carlx oracle db
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php);
if (!$conn) {
	$e = oci_error();
	trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}

// ic2carlx_mnps_staff_get_carlx.sql
$get_carlx_filehandle = fopen("ic2carlx_mnps_staff_get_carlx.sql", "r") or die("Unable to open ic2carlx_mnps_staff_get_carlx.sql");
$sql = fread($get_carlx_filehandle, filesize("ic2carlx_mnps_staff_get_carlx.sql"));
fclose($get_carlx_filehandle);

$stid = oci_parse($conn, $sql);
// TO DO: consider tuning oci_set_prefetch to improve performance. See https://docs.oracle.com/database/121/TDPPH/ch_eight_query.htm#TDPPH172
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$got_carlx_filehandle = fopen($reportPath . "ic2carlx_mnps_staff_carlx.csv", 'w');
while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($got_carlx_filehandle, $row);
}
fclose($got_carlx_filehandle);
echo "CARLX retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

//////////////////// SQLITE3 ////////////////////

// Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
// FROM https://www.sqlite.org/cli.html:
// "The dot-commands are interpreted by the sqlite3.exe command-line program, not by SQLite itself."
// "So none of the dot-commands will work as an argument to SQLite interfaces like sqlite3_prepare() or sqlite3_exec()."

exec("bash ic2carlx_mnps_staff_format_infinitecampus.sh");
exec("sqlite3 ../data/ic2carlx_mnps_staff.db < ic2carlx_mnps_staff_compare.sql");
echo "Infinitecampus vs. CarlX MNPS Staff record comparison complete\n";
if (file_exists("../data/ic2carlx_mnps_staff_defaultbranch_ABORT.csv")||file_exists("../data/ic2carlx_mnps_staff_borrowertype_ABORT.csv")) {
	echo "ABORT!!!\n";
	exit;
}

//////////////////// REMOVE CARLX PATRONS ////////////////////
// See https://trello.com/c/lK7HgZgX for spec
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
	} else {
		$request->Patron->PatronPIN				= '2018';
	}
	
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
$fhnd = fopen("../data/patrons_mnps_carlx_deleteExpiredNotes.csv", "r") or die("unable to open ../data/patrons_mnps_carlx_deleteExpiredNotes.csv");
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
