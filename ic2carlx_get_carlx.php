<?php

// echo 'SYNTAX: path/to/php ic2carlx_get_carlx.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php ic2carlx_get_carlx.php\n';
// 
// TO DO: logging
// TO DO: retry after oracle connect error
// TO DO: review oracle php error handling https://docs.oracle.com/cd/E17781_01/appdev.112/e18555/ch_seven_error.htm#TDPPH165
// TO DO: for patron data privacy, kill data files when actions are complete

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

//////////////////// ORACLE DB ////////////////////

function get_carlx($who) {

/// CONFIGURATION REDUX ///
$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php		= $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user	= $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password	= $configArray['Catalog']['carlx_db_php_password'];
$reportPath		= '../data/';

// connect to carlx oracle db
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php, 'AL32UTF8');
if (!$conn) {
	$e = oci_error();
	trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}

// ic2carlx_[$who]_get_carlx.sql
$get_carlx_filehandle = fopen("ic2carlx_" . $who . "_get_carlx.sql", "r") or die("Unable to open ic2carlx_" . $who . "_get_carlx.sql");
$sql = fread($get_carlx_filehandle, filesize("ic2carlx_" . $who . "_get_carlx.sql"));
fclose($get_carlx_filehandle);

$stid = oci_parse($conn, $sql);
// TO DO: consider tuning oci_set_prefetch to improve performance. See https://docs.oracle.com/database/121/TDPPH/ch_eight_query.htm#TDPPH172
oci_set_prefetch($stid, 10000);
oci_execute($stid);
// start a new file for the CarlX patron extract
$got_carlx_filehandle = fopen($reportPath . "ic2carlx_" . $who . "_carlx.csv", 'w');
while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
	// CSV OUTPUT
	fputcsv($got_carlx_filehandle, $row);
}
fclose($got_carlx_filehandle);
oci_free_statement($stid);
oci_close($conn);
}

//////////////////// WHO /////////////////////

$whos = array("mnps_staff", "mnps_students");
foreach ($whos as $who) {
	get_carlx($who);
}
