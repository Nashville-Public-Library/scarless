<?php

// echo 'SYNTAX: path/to/php NashvilleCarlXPatronLoader.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php NashvilleCarlXPatronLoader.php\n';

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
// consider tuning oci_set_prefetch to improve performance
// https://docs.oracle.com/database/121/TDPPH/ch_eight_query.htm#TDPPH172
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$patrons_mnps_carlx_filehandle;
$patrons_mnps_carlx_filehandle = fopen($reportPath . "patrons_mnps_carlx.csv", 'w');
        
while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($patrons_mnps_carlx_filehandle, $row);
}
fclose($patrons_mnps_carlx_filehandle);
echo "Patrons MNPS CARLX retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

// TO DO: handle staff

$records = array();
// $icfile = "../data/INFINITECAMPUS_STUDENT.csv";
$icfile = "../data/TEST-INFINITECAMPUS_STUDENT.csv";

// TO DO: Diff ad hoc csv result against ic extract
// TO DO: ic extract should quote like php csv output
// TO DO: create new patrons

// Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
// FROM https://www.sqlite.org/cli.html:
// "The dot-commands are interpreted by the sqlite3.exe command-line program, not by SQLite itself."
// "So none of the dot-commands will work as an argument to SQLite interfaces like sqlite3_prepare() or sqlite3_exec()."

exec("sqlite3 ../data/ic2carlx.db < NashvilleCarlXPatronLoaderImport.sql");

$db = new SQLite3('../data/ic2carlx.db');

if(!$db) {
      echo $db->lastErrorMsg();
} else {
//      echo "Opened database successfully\n";
}

$sql = <<<EOT
	select *
	from infinitecampus
	left join carlx on infinitecampus.PatronID = carlx.PatronID
	where carlx.PatronID IS NULL
	order by infinitecampus.PatronID
	;
EOT;

$aCreatePatrons = $db->query($sql);
while ($patron = $aCreatePatrons->fetchArray(2)) {
	if ($patron[0] == 'Patron ID') {continue;}
	var_dump($patron[0]);

// UPDATE OR CREATE PATRON
	// TESTING
	if ($patron[0] > 190999200) { exit(); }
	// CREATE PATRON UPDATE REQUEST
	$request							= new stdClass();
	$request->Modifiers						= '';
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron[0]; // Patron ID
	$request->Patron						= new stdClass();
	// $request->Patron->PatronID					= $patron[0]; // Patron ID
	$request->Patron->PatronType					= $patron[1]; // Patron Type
	$request->Patron->LastName					= $patron[2]; // Patron Name Last
	$request->Patron->FirstName					= $patron[3]; // Patron Name First
	$request->Patron->MiddleName					= $patron[4]; // Patron Name Middle
	$request->Patron->SuffixName					= $patron[5]; // Patron Name Suffix
	$request->Patron->Addresses					= new stdClass();
	$request->Patron->Addresses->Address[0]				= new stdClass();
	$request->Patron->Addresses->Address[0]->Type			= 'Primary';
	$request->Patron->Addresses->Address[0]->Street			= $patron[6]; // Patron Address Street
	$request->Patron->Addresses->Address[0]->City			= $patron[7]; // Patron Address City
	$request->Patron->Addresses->Address[0]->State			= $patron[8]; // Patron Address State
	$request->Patron->Addresses->Address[0]->PostalCode		= $patron[9]; // Patron Address ZIP Code
	// $request->Patron->Addresses->Address[2]->Street		= $patron[10]; // Patron Secondary Address Street
	// $request->Patron->Addresses->Address[2]->City		= $patron[11]; // Patron Secondary Address City
	// $request->Patron->Addresses->Address[2]->State		= $patron[12]; // Patron Secondary Address State
	// $request->Patron->Addresses->Address[2]->PostalCode		= $patron[13]; // Patron Secondary Address ZIP Code
	$request->Patron->Phone1					= $patron[14]; // Patron Primary Phone
	// $request->Patron->Phone2					= $patron[15]; // Patron Secondary Phone
	// $request->Patron->AltId					= $patron[16]; // Patron Alternate ID
	// $request->Patron->Non-Validated Stat Code			= $patron[17]; // Patron Non-Validated Stat Code
	$request->Patron->DefaultBranch					= $patron[18]; // Patron Default Branch
	// $request->Patron->Validated Stat Code			= $patron[19]; // Patron Validated Stat Code
	// MIGHT need logic to properly process changes in PatronStatusCode
	// $request->Patron->PatronStatusCode				= $patron[20]; // Patron Status Code
	// $request->Patron->RegistrationDate				= $patron[21]; // Patron Registrtation Date
	// $request->Patron->LastActionDate				= $patron[22]; // Patron Last Action Date
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron[23])->format('c'); // Patron Expiration Date as ISO 8601
	$request->Patron->Email						= $patron[24]; // Patron Email
	// $request->Patron->Notes					= $patron[25]; // Patron Notes
	$request->Patron->BirthDate					= $patron[26]; // Patron Birth Date as Y-m-d
	// Sponsor: Homeroom Teacher
	$request->Patron->Addresses->Address[1]				= new stdClass();
	$request->Patron->Addresses->Address[1]->Type			= 'Secondary';
	$request->Patron->Addresses->Address[1]->Street			= $patron[32]; // Patron Homeroom Teacher ID
	$request->Patron->SponsorName					= $patron[33];
	// NON-CSV STUFF
	$request->Patron->LastEditBranch				= $patron[18]; // Patron Default Branch
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
// TO DO: accommodate patron manually updates
	// $request->Patron->EmailNotices				= 'send email';
	// $request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
// TO DO: accommodate patron manually updates
	$request->Patron->PatronPIN					= substr($patron[26],5,2) . substr($patron[26],8,2);
	// TEST PATRON PIN = 7357
	if (stripos($patron[0],'190999') == 0) {
		$request->Patron->PatronPIN				= '7357';
	}
	$request->Patron->PreferredAddress				= 'Sponsor';

//var_dump($request);

//	try {
//		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
//		$result = $client->updatePatron($request);
//		$result = $client->__getLastResponse();
//var_dump($result);

// CREATE PATRON
//		if ($result && stripos($result,'ShortMessage>No matching records found') !== false) {
			$request->Patron->RegBranch		= $patron[18]; // Patron Registration Branch
			$request->Patron->RegisteredBy		= 'PIK'; // Registered By : Pika Patron Loader
			$request->Patron->RegistrationDate	= date('c'); // Registration Date, format ISO 8601
			$request->Patron->PatronStatusCode	= 'G'; // Patron Status Code = GOOD
			try {
$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
				$result = $client->createPatron($request);
				$result = $client->__getLastResponse();
var_dump($result);
			} catch (Exception $e) {
				echo $e->getMessage();
			}
//		} 
//	} catch (Exception $e) {
//		echo $e->getMessage();
//	}

/*
// UPDATE PATRON IMAGE
	$request							= new stdClass();
	$request->Modifiers						= '';
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron[0]; // Patron ID
	$request->ImageType						= 'Profile'; // Patron Profile Picture vs. Signature
	$imageFilePath 							= "../data/images/" . $patron[0] . ".jpg";
	if (file_exists($imageFilePath)) {
		$imageFileHandle 					= fopen($imageFilePath, "rb");
		$request->ImageData					= bin2hex(fread($imageFileHandle, filesize($imageFilePath)));
		fclose($imageFileHandle);
	} else {
// TO DO: create IMAGE NOT AVAILABLE
	}

	if (isset($request->ImageData)) {
	        try {
	                $result = $client->updateImage($request);
			$result = $client->__getLastResponse();
//var_dump($result);
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}
*/

/*
// TO DO: Patron->UDFs ain't writing to db 20171211
// TLC Marisa Wood wrote the class in in updatePatronRequest to provide backward compatibility in the future
	$request->Patron->UserDefinedFields				= new stdClass();
	// User Defined Field: Racial or Ethnic Category
	$request->Patron->UserDefinedFields->UserDefinedField[0]	= new stdClass();
	$request->Patron->UserDefinedFields->UserDefinedField[0]->Field	= '2';
	$request->Patron->UserDefinedFields->UserDefinedField[0]->Value	= $patron[28]; // UDF2:Racial or Ethnic Category
	// User Defined Field: Lap Top Check Out
	$request->Patron->UserDefinedFields->UserDefinedField[1]	= new stdClass();
	$request->Patron->UserDefinedFields->UserDefinedField[1]->Field	= '3';
	$request->Patron->UserDefinedFields->UserDefinedField[1]->Value	= $patron[29]; // UDF3:Lap Top Check Out
	// User Defined Field: Limitless Library Use
	$request->Patron->UserDefinedFields->UserDefinedField[2]	= new stdClass();
	$request->Patron->UserDefinedFields->UserDefinedField[2]->Field	= '4';
	$request->Patron->UserDefinedFields->UserDefinedField[2]->Value	= $patron[30]; // UDF4:Limitless Library Use
	// User Defined Field: Tech Opt Out
	$request->Patron->UserDefinedFields->UserDefinedField[3]	= new stdClass();
	$request->Patron->UserDefinedFields->UserDefinedField[3]->Field	= '1';
	$request->Patron->UserDefinedFields->UserDefinedField[3]->Value	= $patron[31]; // UDF1:Tech Opt Out						
*/

// TO DO: Update Patron User Defined Fields [Permissions]
// Retrieve current User Defined Fields to determine which need to be created and which updated
	$request							= new stdClass();
	$request->Modifiers						= '';
	$request->Patronid						= $patron[0]; // Patron ID
	$request->Occur							= 0;
	try {
		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
		$result = $client->GetPatronUserDefinedFields($request);
		$result = $client->__getLastResponse();
//var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}

/*
// TO DO: Compare GetPatronUserDefinedFields against incoming data 
		$patron[28]; // UDF2:Racial or Ethnic Category [THIS SCRIPT WILL NOT ALTER VALUES]
		$patron[29]; // UDF3:Lap Top Check Out
		$patron[30]; // UDF4:Limitless Library Use
		$patron[31]; // UDF1:Tech Opt Out
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
	$request->Modifiers				= '';
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
