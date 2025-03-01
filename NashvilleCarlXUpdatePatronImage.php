<?php

//////////////////// CREATE/UPDATE PATRON IMAGES FROM ID LIST ////////////////////
// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatronImage.php\n';

require_once 'ic2carlx_put_carlx.php';

date_default_timezone_set('America/Chicago');
$startTime 				= microtime(true);
$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode     = $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode    = $configArray['Catalog']['patronApiReportMode'];
$reportPath             = '../data/';
if (!empty($configArray['Catalog']['staffSubDir'])) {
	$staffSubDir = $configArray['Catalog']['staffSubDir'];
} else {
	$staffSubDir = 'staff';
}
if (!empty($configArray['Catalog']['studentSubDir'])) {
	$studentSubDir = $configArray['Catalog']['studentSubDir'];
} else {
	$studentSubDir = 'students';
}

$records = array();
$fhnd = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRON_IMAGE.CSV", "r");
if ($fhnd){
        while (($data = fgetcsv($fhnd)) !== FALSE){
                $records[] = $data;
        }
}

$i = 0;
$errors = array();
$client = new SOAPClient($patronApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
foreach ($records as $patron) {

	if (preg_match('/^\d{6,7}$/', $patron[0]) === 1) {
		$patronGroup = $staffSubDir;
	} elseif (preg_match('/^190\d{6}$/', $patron[0]) === 1) {
		$patronGroup = $studentSubDir;
	} else {
		continue;
	}

	$requestName					= 'updateImage';
	$tag							= $patron[0] . ' : ' . $requestName;
	$request						= new stdClass();
	$request->Modifiers				= new stdClass();
	$request->Modifiers->DebugMode	= $patronApiDebugMode;
	$request->Modifiers->ReportMode	= $patronApiReportMode;
	$request->SearchType			= 'Patron ID';
	$request->SearchID				= $patron[0]; // Patron ID
	$request->ImageType				= 'Profile'; // Patron Profile Picture vs. Signature
	$imageFilePath 					= "../data/images/" . $patronGroup . "/" . $patron[0] . ".jpg";
	if (file_exists($imageFilePath)) {
		$imageBin 					= file_get_contents($imageFilePath);
		$request->ImageData			= $imageBin;
	} else {
// TO DO: create IMAGE NOT AVAILABLE image
	}
	if (isset($request->ImageData)) {
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
	}
}

// TO DO: save the errors to a file.
// $ferror = fopen($reportPath . "NashvilleCarlXUpdatePatronImage.error.txt", "w");

// TO DO : THIS AIN'T RIGHT fwrite($ferror, print_r($errors));
// fclose($ferror);

print_r($errors);

?>
