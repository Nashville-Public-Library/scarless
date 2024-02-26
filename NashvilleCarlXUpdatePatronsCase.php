<?php

// Change case of patron data (names, street address) to Title Case
// echo 'SYNTAX: $ php NashvilleCarlXUpdatePatronsCase.php\n';

//use Formatter;

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';
require_once 'Formatter.php';
use Tamtamchik\NameCase\Formatter;

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
with x as (
    select
        patronid
        , firstname
        , middlename
        , lastname
        , suffixname
    from patron_v2 
    where bty != 9
    and not regexp_like (firstname, '^[A-Z][a-z]+$')
    or not regexp_like (middlename, '^[A-Z][a-z]+$')
    or not regexp_like (lastname, '^[A-Z][a-z]+$')
    or not regexp_like (suffixname, '^[A-Z][a-z]+$')
)
select
*
from x sample (.01)
;
EOT;
$stid = oci_parse($conn, $sql);
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$df;
$df = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS.CSV", 'w');

while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($df, $row);
}
fclose($df);
echo "CARLX MNPS patrons to be deleted retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);
$records = array();

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




//	$request->Patron->PatronPIN = createRandomPIN();
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

	$request->Patron->FirstName = Formatter::nameCase($patron[1]);
	$request->Patron->MiddleName = Formatter::nameCase($patron[2]);
	$request->Patron->LastName = Formatter::nameCase($patron[3]);
	$request->Patron->SuffixName = Formatter::nameCase($patron[4]);
	$request->Patron->FullName = $request->Patron->FirstName . ' ' . $request->Patron->MiddleName . ' ' . $request->Patron->LastName . ' ' . $request->Patron->SuffixName;

//	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
	echo 'Patron ID: ' . $request->SearchID . "\n";
	echo 'Patron First Name: ' . $patron[1] . ' -> ' . $request->Patron->FirstName . "\n";
	echo 'Patron Middle Name: ' . $patron[2] . ' -> ' . $request->Patron->MiddleName . "\n";
	echo 'Patron Last Name: ' . $patron[3] . ' -> ' . $request->Patron->LastName . "\n";
	echo 'Patron Suffix Name: ' . $patron[4] . ' -> ' . $request->Patron->SuffixName . "\n";
}
?>
