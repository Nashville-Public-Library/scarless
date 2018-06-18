<?php

// echo 'SYNTAX: path/to/php NashvilleCarlXPatronLoader.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php NashvilleCarlXPatronLoader.php\n';
// 
// TO DO: retry after oracle connect error
// TO DO: retry after connection patron api errors
// TO DO: capture other patron api errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement
// TO DO: test whether setting PIN works... appears to set as 9999
// TO DO: UDF
// TO DO: CREATE GUARANTOR NOTE
	// $request->Patron->Notes					= $patron[25]; // Patron Notes
// TO DO: STUDENT IMAGES
// TO DO: consider whether to make the SQL write the SOAP into a single table
// TO DO: STAFF

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'PEAR.php';

$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php		= $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user	= $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password	= $configArray['Catalog']['carlx_db_php_password'];
$patronApiWsdl		= $configArray['Catalog']['patronApiWsdl'];
$reportPath		= '../data/';

// connect to carlx oracle db
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php);
if (!$conn) {
	$e = oci_error();
	trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}

// get_patrons_mnps_carlx.sql
$get_patrons_mnps_carlx_filehandle = fopen("get_patrons_mnps_carlx.sql", "r") or die("Unable to open get_patrons_mnps_carlx.sql");
$sql = fread($get_patrons_mnps_carlx_filehandle, filesize("get_patrons_mnps_carlx.sql"));
fclose($get_patrons_mnps_carlx_filehandle);

$stid = oci_parse($conn, $sql);
// TO DO: consider tuning oci_set_prefetch to improve performance. See https://docs.oracle.com/database/121/TDPPH/ch_eight_query.htm#TDPPH172
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$patrons_mnps_carlx_filehandle = fopen($reportPath . "patrons_mnps_carlx.csv", 'w');
while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($patrons_mnps_carlx_filehandle, $row);
}
fclose($patrons_mnps_carlx_filehandle);
echo "Patrons MNPS CARLX retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

// Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
// FROM https://www.sqlite.org/cli.html:
// "The dot-commands are interpreted by the sqlite3.exe command-line program, not by SQLite itself."
// "So none of the dot-commands will work as an argument to SQLite interfaces like sqlite3_prepare() or sqlite3_exec()."

exec("bash format_patrons_mnps_infinitecampus.sh");
exec("sqlite3 ../data/ic2carlx.db < patrons_mnps_compare.sql");

// CREATE CARLX PATRONS

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_create.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);

foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['PatronID'] > 190999110) { break; }
	// CREATE REQUEST
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= 1;
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
	$request->Patron->LastActionBranch				= $patron['DefaultBranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['DefaultBranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['DefaultBranch']; // Patron Registration Branch
	$request->Patron->Email						= $patron['EmailAddress']; // Patron Email
	$request->Patron->BirthDate					= $patron['BirthDate']; // Patron Birth Date as Y-m-d
	// Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= $patron['TeacherID']; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= $patron['TeacherName'];
	if (stripos($patron['PatronID'],'190999') == 0) {
		$request->Patron->PatronPIN				= '7357';
	} else {
		$request->Patron->PatronPIN				= substr($patron['BirthDate'],5,2) . substr($patron['BirthDate'],8,2);
	}
	// NON-CSV STUFF
	$request->Patron->EmailNotices					= 'send email';
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['ExpirationDate'])->format('c'); // Patron Expiration Date as ISO 8601
	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PatronStatusCode				= 'G'; // Patron Status Code = GOOD
	$request->Patron->PreferredAddress				= 'Sponsor';
	$request->Patron->RegisteredBy					= 'PIK'; // Registered By : Pika Patron Loader
	$request->Patron->RegistrationDate				= date('c'); // Registration Date, format ISO 8601
//var_dump($request);
	try {
		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
		$result = $client->createPatron($request);
		$result = $client->__getLastResponse();
//var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}

// CREATE PATRON IMAGE
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= 1;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron[0]; // Patron ID
	$request->ImageType						= 'Profile'; // Patron Profile Picture vs. Signature
	$imageFilePath 							= "../data/images/" . $patron[0] . ".jpg";
	if (file_exists($imageFilePath)) {
		$imageFileHandle 					= fopen($imageFilePath, "rb");
		$request->ImageData					= bin2hex(fread($imageFileHandle, filesize($imageFilePath)));
		fclose($imageFileHandle);
	} else {
// TO DO: create IMAGE NOT AVAILABLE image
	}

	if (isset($request->ImageData)) {
//var_dump($request);
	        try {
	                $result = $client->updateImage($request);
			$result = $client->__getLastResponse();
//var_dump($result);
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}
}

// UPDATE CARLX PATRONS

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_update.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);

foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['PatronID'] > 190999110) { break; }
	// CREATE REQUEST
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= 1;
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
	$request->Patron->LastActionBranch				= $patron['DefaultBranch']; // Patron Last Action Branch
	$request->Patron->LastEditBranch				= $patron['DefaultBranch']; // Patron Last Edit Branch
	$request->Patron->RegBranch					= $patron['DefaultBranch']; // Patron Registration Branch
	$request->Patron->Email						= $patron['EmailAddress']; // Patron Email
	$request->Patron->BirthDate					= $patron['BirthDate']; // Patron Birth Date as Y-m-d
	// Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= $patron['TeacherID']; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= $patron['TeacherName'];
	if (stripos($patron['PatronID'],'190999') == 0) {
		$request->Patron->PatronPIN				= '7357';
	} else {
		$request->Patron->PatronPIN				= substr($patron['BirthDate'],5,2) . substr($patron['BirthDate'],8,2);
	}
	// NON-CSV STUFF
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron['ExpirationDate'])->format('c'); // Patron Expiration Date as ISO 8601
	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PreferredAddress				= 'Sponsor';
//var_dump($request);
	try {
		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
		$result = $client->updatePatron($request);
		$result = $client->__getLastResponse();
//var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}
}


// CREATE USER DEFINED FIELDS ENTRIES

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_createUdf.csv", "r") or die("unable to open ../data/patrons_mnps_carlx_createUdf.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['patronid'] > 190999110) { break; }
	// CREATE REQUEST
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= 1;
	$request->PatronUserDefinedField				= new stdClass();
	$request->PatronUserDefinedField->patronid			= $patron['patronid'];
	$request->PatronUserDefinedField->occur				= $patron['occur'];
	$request->PatronUserDefinedField->fieldid			= $patron['fieldid'];
	$request->PatronUserDefinedField->numcode			= $patron['numcode'];
	$request->PatronUserDefinedField->type				= $patron['type'];
	$request->PatronUserDefinedField->valuename			= $patron['valuename'];
//var_dump($request);
	try {
		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
		$result = $client->createPatronUserDefinedFields($request);
		$result = $client->__getLastResponse();
//var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}
}

// UPDATE USER DEFINED FIELDS ENTRIES

$all_rows = array();
$fhnd = fopen("../data/patrons_mnps_carlx_updateUdf.csv", "r") or die("unable to open ../data/patrons_mnps_carlx_updateUdf.csv");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	if ($patron['new_patronid'] > 190999110) { break; }
	// CREATE REQUEST
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= 1;
	$request->OldPatronUserDefinedField				= new stdClass();
	$request->OldPatronUserDefinedField->patronid			= $patron['old_patronid'];
	$request->OldPatronUserDefinedField->occur			= $patron['old_occur'];
	$request->OldPatronUserDefinedField->fieldid			= $patron['old_fieldid'];
	$request->OldPatronUserDefinedField->numcode			= $patron['old_numcode'];
	$request->OldPatronUserDefinedField->type			= $patron['old_type'];
	$request->OldPatronUserDefinedField->valuename			= $patron['old_valuename'];
	$request->NewPatronUserDefinedField				= new stdClass();
	$request->NewPatronUserDefinedField->patronid			= $patron['new_patronid'];
	$request->NewPatronUserDefinedField->occur			= $patron['new_occur'];
	$request->NewPatronUserDefinedField->fieldid			= $patron['new_fieldid'];
	$request->NewPatronUserDefinedField->numcode			= $patron['new_numcode'];
	$request->NewPatronUserDefinedField->type			= $patron['new_type'];
	$request->NewPatronUserDefinedField->valuename			= $patron['new_valuename'];
//var_dump($request);
	try {
		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
		$result = $client->updatePatronUserDefinedFields($request);
		$result = $client->__getLastResponse();
//var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}
}

/*
*/


/*
// TO DO: Guardian notes
// Lane says the note should be like: 
// NPL: MNPS Guarantor effective 03/29/2017 - 7/31/2017: BOBBY BROWN
	// Note: Guardian // Notes appears weird in the API // BE CAREFUL TO NOT OVERWRITE UNRELATED NOTES
	$request->Patron->Notes						= new stdClass();
	$request->Patron->Notes->NoteType				= 2; 
	$request->Patron->Notes->NoteText				= 'NPL: MNPS Guardian: ' . $patron[27]; // Patron Guardian Name
*/


// VERIFY ALL UPDATED VALUES WERE UPDATED
	$request					= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= 1;
	$request->SearchType				= 'Patron ID';
	$request->SearchID				= $patron[0]; // Patron ID
	try {
		$result = $client->getPatronInformation($request);
		$result = $client->__getLastResponse();
var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}

}

$db->close();


?>
