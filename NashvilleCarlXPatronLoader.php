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

$sql = <<<EOT
select patron_v.patronid as "Patron ID"
  , patron_v.bty as "Borrower type code"
  , patron_v.lastname as "Patron last name"
  , patron_v.firstname as "Patron first name"
  , patron_v.middlename as "Patron middle name"
  , patron_v.suffixname as "Patron suffix"
  , patron_v.street1 as "Primary Street Address"
  , patron_v.city1 as "Primary City"
  , patron_v.state1 as "Primary State"
  , patron_v.zip1 as "Primary Zip Code"
  , '' as "Secondary Street Address"
  , '' as "Secondary City"
  , '' as "Secondary State"
  , '' as "Secondary Zip Code"
  , patron_v.ph2 as "Primary Phone Number" -- CONFUSING. MNPS SUPPLIES PRIMARY HOME PHONE. NPL LOADS INTO SECONDARY PHONE BECAUSE STUDENTS SHOULD NOT RECEIVE ITIVA AUTOMATED CALLS
  , '' as "Secondary Phone Number"
  , '' as "Alternate ID"
  , '' as "Non-validated Stats"
  , patronbranch.branchcode as "Default Branch"
  , '' as "Validated Stat Codes"
  , patron_v.status as "Status Code"
  , '' as "Registration Date"
  , '' as "Last Action Date"
  , to_char(jts.todate(patron_v.expdate),'YYYY-MM-DD') as "Expiration Date"
  , patron_v.email as "Email Address"
  , '' as "Notes"
  , to_char(jts.todate(patron_v.birthdate),'YYYY-MM-DD') as "Birth Date"
  , guarantor.guarantor as "Guardian" -- FIXED!
  , udf2.valuename as "Racial or Ethnic Category" -- FIX THIS
  , udf3.valuename as "Lap Top Check Out" -- FIX THIS
  , udf4.valuename as "Limitless Library Use" -- FIX THIS
  , udf1.valuename as "Tech Opt Out" -- FIXED?
  , patron_v.street2 as "Teacher ID"
  , patron_v.sponsor as "Teacher Name"

from patron_v 
join branch_v patronbranch on patron_v.defaultbranch = patronbranch.branchnumber
left outer join ( 
  select distinct
    refid
    , first_value(text) over (partition by refid order by timestamp desc) as guarantor
  from patronnotetext_v
  where regexp_like(patronnotetext_v.text, 'NPL: MNPS Guarantor effective')
) guarantor on patron_v.patronid = guarantor.refid
left outer join ( 
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 1
) udf1 on patron_v.patronid = udf1.patronid
left outer join ( 
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 2
) udf2 on patron_v.patronid = udf2.patronid
left outer join ( 
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 3
) udf3 on patron_v.patronid = udf3.patronid
left outer join ( 
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v
  where udfpatron_v.fieldid = 4
) udf4 on patron_v.patronid = udf4.patronid
where 
   patronbranch.branchgroup = '2'
--   or patron_v.bty = 13 or (patron_v.bty >= 21 and patron_v.bty <= 42)
--   or regexp_like(patron_v.patronid,'^190[0-9]{6}$')
  and regexp_like(patron_v.patronid,'^190999[0-9]{3}$') -- TEST STUDENT PATRONS
order by patron_v.patronid
EOT;

$stid = oci_parse($conn, $sql);
// consider using oci_set_prefetch to improve performance
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$df;
$df = fopen($reportPath . "CARLX_MNPS.CSV", 'w');
        
while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($df, $row);
}
fclose($df);
echo "CARLX MNPS patrons retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

// TO DO: handle staff
// TO DO: Diff ad hoc csv result against ic extract
// TO DO: ic extract should quote like php csv output
// TO DO: create new patrons

$records = array();
$fhnd = fopen("../data/20171222-TEST-PATRONLOADER.csv", "r");
if ($fhnd){
        while (($data = fgetcsv($fhnd)) !== FALSE){
                $records[] = $data;
        }
}

foreach ($records as $patron) {

// UPDATE OR CREATE PATRON
	// TESTING
	if ($patron[0] > 190999101) { exit(); }
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
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d',$patron[23])->format('c'); // Patron Expiration Date as Y-m-d
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

	try {
		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
		$result = $client->updatePatron($request);
		$result = $client->__getLastResponse();
//var_dump($result);

// CREATE PATRON
		if ($result && stripos($result,'ShortMessage>No matching records found') !== false) {
			$request->Patron->RegBranch		= $patron[18]; // Patron Registration Branch
			$request->Patron->RegisteredBy		= 'PIK'; // Registered By : Pika Patron Loader
			$request->Patron->RegistrationDate	= date('c'); // Registration Date, format ISO 8601
			try {
				$result = $client->createPatron($request);
				$result = $client->__getLastResponse();
//var_dump($result);
			} catch (Exception $e) {
				echo $e->getMessage();
			}
		} 
	} catch (Exception $e) {
		echo $e->getMessage();
	}

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
//var_dump($result);
	} catch (Exception $e) {
		echo $e->getMessage();
	}
}

?>
