<?php

// echo 'SYNTAX: path/to/php getOverDriveISBNs.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php getOverDriveISBNs.php\n';
// 
// TO DO: logging
// TO DO: retry after oracle connect error
// TO DO: review oracle php error handling https://docs.oracle.com/cd/E17781_01/appdev.112/e18555/ch_seven_error.htm#TDPPH165
// TO DO: for patron data privacy, kill data files when actions are complete

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$database_host		= $configArray['Database']['database_host'];
$database_user		= $configArray['Database']['database_user'];
$database_password	= $configArray['Database']['database_password'];
$database_db		= $configArray['Database']['database_db'];
$reportPath		= '../data/';

//////////////////// SCRIPT ////////////////////

// connect to mysql vufind econtent
$mysqli = new mysqli($database_host, $database_user, $database_password, $database_db);

if ($mysqli->connect_errno) {
    echo "Error: Failed to make a MySQL connection, here is why: \n";
    echo "Errno: " . $mysqli->connect_errno . "\n";
    echo "Error: " . $mysqli->connect_error . "\n";
    exit;
}

// getOverDriveISBNs.sql
$get_filehandle = fopen("getOverDriveISBNs.sql", "r") or die("Unable to open getOverDriveISBNs.sql");
$sql = fread($get_filehandle, filesize("getOverDriveISBNs.sql"));
fclose($get_filehandle);

if (!$result = $mysqli->query($sql)) {
    echo "Error: Our query failed to execute and here is why: \n";
    echo "Query: " . $sql . "\n";
    echo "Errno: " . $mysqli->errno . "\n";
    echo "Error: " . $mysqli->error . "\n";
    exit;
}

if ($result->num_rows === 0) {
	echo "Zero results\n";
}

// start a new file for the OverDriveISBN extract
$got_filehandle = fopen($reportPath . "getOverDriveISBNs.csv", 'w');
while ($row = $result->fetch_assoc()) {
	// CSV OUTPUT
	fputcsv($got_filehandle, $row);
}

$result->free();
$mysqli->close();
