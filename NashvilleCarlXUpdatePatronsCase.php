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
    from patron_v2
    where bty not in (9,13,19,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,40,46,47) -- exclude MNPS, ILL, NPL Branch
    and ( -- Target the names that are not already in Title Case
                not regexp_like (firstname, '^[A-Z][a-z]+$')
                or not regexp_like (middlename, '(^[A-Z]$|^[A-Z][a-z]+$)')
                or not regexp_like (lastname, '^[A-Z][a-z]+$')
                or not regexp_like (suffixname, '^[A-Z][a-z]+$')
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

$records = array();
$fhnd = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS.CSV", "r");
if ($fhnd){
	while (($data = fgetcsv($fhnd)) !== FALSE){
		$records[] = $data;
	}
}

$count = 0;
$callcount = 0;
$round = 0;
foreach ($records as $patron) {
	$count++;
	if ($callcount >= 12600) { // empirically, when run on catalog.library.nashville.org, the 4092nd update and beyond does not actually update. On NLMNJSTAUB, it updates until the 12597th update
		exit;
		$callcount = 0;
		$round++;
	}

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

	$request->Patron->FirstName = Formatter::nameCase($patron[1]);
	$request->Patron->MiddleName = Formatter::nameCase($patron[2]);
	if (str_starts_with($patron[3], '#')) {
		$patron_last = preg_replace('/^#+\s*/','',$patron[3]);
		$request->Patron->LastName = '##' . Formatter::nameCase($patron_last);
	} else {
		$request->Patron->LastName = Formatter::nameCase($patron[3]);
	}
	$request->Patron->SuffixName = Formatter::nameCase($patron[4]);
	$request->Patron->FullName = $request->Patron->FirstName . ' ' . $request->Patron->MiddleName . ' ' . $request->Patron->LastName . ' ' . $request->Patron->SuffixName;

	if($request->Patron->FirstName != $patron[1] || $request->Patron->MiddleName != $patron[2] || $request->Patron->LastName != $patron[3] || $request->Patron->SuffixName != $patron[4]) {
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
		$callcount++;
		echo 'COUNT: ' . $count . "\n";
		echo 'ROUND/CALL COUNT: ' . $round . '/' . $callcount . "\n";
		echo 'Patron ID: ' . $request->SearchID . "\n";
		echo 'Patron First Name: ' . $patron[1] . ' -> ' . $request->Patron->FirstName . "\n";
		echo 'Patron Middle Name: ' . $patron[2] . ' -> ' . $request->Patron->MiddleName . "\n";
		echo 'Patron Last Name: ' . $patron[3] . ' -> ' . $request->Patron->LastName . "\n";
		echo 'Patron Suffix Name: ' . $patron[4] . ' -> ' . $request->Patron->SuffixName . "\n";
	} else {
		echo 'COUNT: ' . $count . "\n";
		echo "NO CHANGE\n";
	}
}
?>
