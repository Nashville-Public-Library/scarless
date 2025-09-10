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
    select
--    count(patronid)
        patronid
        , firstname
        , middlename
        , lastname
        , suffixname
    	, street1
    	, city1
    	, state1
    	, zip1
    from patron_v2 -- sample(1)
    where bty not in (9,19) -- exclude ILL, NPL Branch
    and bty not in (13,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,40,46,47,51) -- exclude MNPS
    and patronid not like 'B%'
    and ( -- Target the names that are not already in Title Case
--                 not regexp_like (firstname, '^[A-Z][a-z]+$')
--                 or not regexp_like (middlename, '(^[A-Z]$|^[A-Z][a-z]+$)')
--                 or not regexp_like (lastname, '^[A-Z][a-z]+$')
--                 or not regexp_like (suffixname, '^[A-Z][a-z]+$')
		not regexp_like (street1, '^([0-9]+[-A-Z]* )?([A-Z] )?([[0-9]+[DHNRSTdhnrst]{2} )?([A-Z][a-z]+\.? ?)+((, )?((Apt|Lot|No|Unit) )?\#?[A-Z]*[- ]?[0-9]*)?$')
		or not regexp_like (city1, '^([A-Z][a-z]+ )*(Ma?c)?[A-Z][a-z]*$')
		or not regexp_like (state1, '^[A-Z]{2}$')
        or not regexp_like (zip1, '^[0-9]{5}$')
    )    
    order by patronid
EOT;
$stid = oci_parse($conn, $sql);
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$df = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS.CSV", 'w');

while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($df, $row);
}
fclose($df);
echo "CARLX MNPS patrons to be updated retrieved and written\n";
oci_free_statement($stid);
oci_close($conn);

$records = array();
$fhnd = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS.CSV", "r");
if ($fhnd){
	while (($data = fgetcsv($fhnd)) !== FALSE){
		$records[] = $data;
	}
}

$count = 0;
$client = new SOAPClient($patronApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
foreach ($records as $patron) {
	$count++;
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
	$request->Patron->Addresses = new stdClass();
	$request->Patron->Addresses->Address = new stdClass();
	$request->Patron->Addresses->Address->Type = 'Primary';
	$request->Patron->Addresses->Address->Street = Formatter::nameCase($patron[5], ['spanish' => false, 'postnominal' => false]); // Spanish = false and Postnomial = false to keep 7 E ST from becoming 7est
	$request->Patron->Addresses->Address->City = Formatter::nameCase($patron[6]);

	// STREET: Eliminate multiple spaces (will need to iterate)
	if (strpos($request->Patron->Addresses->Address->Street,'  ')) {
		$request->Patron->Addresses->Address->Street = str_replace('  ',' ', $request->Patron->Addresses->Address->Street);
	}
	// STREET: Eliminate periods
	if (strpos($request->Patron->Addresses->Address->Street,'.')) {
		$request->Patron->Addresses->Address->Street = str_replace('.','', $request->Patron->Addresses->Address->Street);
	}
	// STREET: Eliminate apostrophes
	if (strpos($request->Patron->Addresses->Address->Street,"'")) {
		$request->Patron->Addresses->Address->Street = str_replace("'","", $request->Patron->Addresses->Address->Street);
	}
	// STREET: RD->Rd, PK->Pk, etc.
	if(strpos($request->Patron->Addresses->Address->Street, ' CT')) {
		$request->Patron->Addresses->Address->Street = str_replace(' CT', ' Ct', $request->Patron->Addresses->Address->Street);
	}
	if(strpos($request->Patron->Addresses->Address->Street, ' LN')) {
		$request->Patron->Addresses->Address->Street = str_replace(' LN', ' Ln', $request->Patron->Addresses->Address->Street);
	}
	if(strpos($request->Patron->Addresses->Address->Street, ' MT')) {
		$request->Patron->Addresses->Address->Street = str_replace(' MT', ' Mount', $request->Patron->Addresses->Address->Street);
	}
	if(strpos($request->Patron->Addresses->Address->Street, ' PK')) {
		$request->Patron->Addresses->Address->Street = str_replace(' PK', ' Pk', $request->Patron->Addresses->Address->Street);
	}
	if(strpos($request->Patron->Addresses->Address->Street, ' PL')) {
		$request->Patron->Addresses->Address->Street = str_replace(' PL', ' Pl', $request->Patron->Addresses->Address->Street);
	}
	if(strpos($request->Patron->Addresses->Address->Street, ' RD')) {
		$request->Patron->Addresses->Address->Street = str_replace(' RD', ' Rd', $request->Patron->Addresses->Address->Street);
	}
	if(strpos($request->Patron->Addresses->Address->Street, ' SQ')) {
		$request->Patron->Addresses->Address->Street = str_replace(' SQ', ' Sq', $request->Patron->Addresses->Address->Street);
	}

	// CITY: Eliminate periods
	if (strpos($request->Patron->Addresses->Address->City,'.')) {
		$request->Patron->Addresses->Address->City = str_replace('.','', $request->Patron->Addresses->Address->City);
	}
	// CITY: Eliminate apostrophes
	if (strpos($request->Patron->Addresses->Address->City,"'")) {
		$request->Patron->Addresses->Address->City = str_replace("'","", $request->Patron->Addresses->Address->City);
	}
	// CITY: Fort Campbell, etc.
	if (preg_match('/^FT\.?\b/i', $request->Patron->Addresses->Address->City)) {
		$request->Patron->Addresses->Address->City = 'Fort';
	}
	// CITY: La Vergne, etc.
	if (preg_match('/^LA\b/i', $request->Patron->Addresses->Address->City)) {
		$request->Patron->Addresses->Address->City = 'La';
	}
	// CITY: Mount Juliet, Mount Pleasant, etc.
	if (preg_match('/^MT\.?\b/i', $request->Patron->Addresses->Address->City)) {
	$request->Patron->Addresses->Address->City = 'Mount';
	}


	$request->Patron->Addresses->Address->State = strtoupper($patron[7]);
	if (strpos($request->Patron->Addresses->Address->State,'.')) {
		$request->Patron->Addresses->Address->State = str_replace('.','', $request->Patron->Addresses->Address->State);
	}
	$request->Patron->Addresses->Address->PostalCode = preg_replace('/-([0-9]{4})$/', '', $patron[8]);

	// TESTING
// PATRON NAME CASE CORRECTION
//	$request->Patron->FirstName = Formatter::nameCase($patron[1]);
//	$request->Patron->MiddleName = Formatter::nameCase($patron[2]);
//	if (str_starts_with($patron[3], '#')) {
//		$patron_last = preg_replace('/^#+\s*/','',$patron[3]);
//		$request->Patron->LastName = '##' . Formatter::nameCase($patron_last);
//	} else {
//		$request->Patron->LastName = Formatter::nameCase($patron[3]);
//	}
//	$request->Patron->SuffixName = Formatter::nameCase($patron[4]);
//	$request->Patron->FullName = $request->Patron->FirstName . ' ' . $request->Patron->MiddleName . ' ' . $request->Patron->LastName . ' ' . $request->Patron->SuffixName;

//	if($request->Patron->FirstName != $patron[1] || $request->Patron->MiddleName != $patron[2] || $request->Patron->LastName != $patron[3] || $request->Patron->SuffixName != $patron[4]) {
//		$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
//		$callcount++;
//		echo 'COUNT: ' . $count . "\n";
//		echo 'ROUND/CALL COUNT: ' . $round . '/' . $callcount . "\n";
//		echo 'Patron ID: ' . $request->SearchID . "\n";
//		echo 'Patron First Name: ' . $patron[1] . ' -> ' . $request->Patron->FirstName . "\n";
//		echo 'Patron Middle Name: ' . $patron[2] . ' -> ' . $request->Patron->MiddleName . "\n";
//		echo 'Patron Last Name: ' . $patron[3] . ' -> ' . $request->Patron->LastName . "\n";
//		echo 'Patron Suffix Name: ' . $patron[4] . ' -> ' . $request->Patron->SuffixName . "\n";

	if($request->Patron->Addresses->Address->Street != $patron[5] || $request->Patron->Addresses->Address->City != $patron[6] || $request->Patron->Addresses->Address->State != $patron[7] || $request->Patron->Addresses->Address->PostalCode != $patron[8]) {
//	if($request->Patron->Addresses->Address->PostalCode != $patron[8]) {
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
		$callcount++;
		echo 'COUNT: ' . $count . "\n";
		echo 'Patron ID: ' . $request->SearchID . "\n";
		echo 'Patron Primary Address Street: ' . $patron[5] . ' -> ' . $request->Patron->Addresses->Address->Street . "\n";
		echo 'Patron Primary Address City: ' . $patron[6] . ' -> ' . $request->Patron->Addresses->Address->City . "\n";
		echo 'Patron Primary Address State: ' . $patron[7] . ' -> ' . $request->Patron->Addresses->Address->State . "\n";
		echo 'Patron Primary Address ZIP: ' . $patron[8] . ' -> ' . $request->Patron->Addresses->Address->PostalCode . "\n";
	} else {
		echo 'COUNT: ' . $count . "\n";
		echo 'Patron ID: ' . $request->SearchID . "\n";
		echo "NO CHANGE\n";
	}
}
?>
