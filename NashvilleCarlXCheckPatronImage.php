<?php

//////////////////// COMPARE LOCAL PATRON IMAGES AGAINST CARLX ////////////////////
// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatronImage.php\n';

require_once 'ic2carlx_put_carlx.php';

date_default_timezone_set('America/Chicago');
$startTime 				= microtime(true);
$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode     = $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode    = $configArray['Catalog']['patronApiReportMode'];
$reportPath             = '../data/';

function getImageDataFromResponse($response) {
	// Ensure the response is an object
	if (!is_object($response)) {
		throw new InvalidArgumentException('Expected an object as the response.');
	}
	// Navigate through the object structure
	if (!isset($response->response->ImageData)) {
		echo "WARNING: No ImageData\n";
//		throw new InvalidArgumentException('Expected an object property named "ImageData".');
	} else {
		$imageData = $response->response->ImageData;
		// Check if running on Linux and convert to hex if necessary
		if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
			$imageData = bin2hex($imageData);
		}
		return $imageData;
	}
}

$errors = array();
$iterator = new DirectoryIterator('../data/images/staff');
foreach ($iterator as $fileinfo) {
	$file = $fileinfo->getFilename();
	$imageFilePath = $fileinfo->getPathname();
//	echo $imageFilePath . "\n";
	$mtime = $fileinfo->getMTime();
	$matches = [];
	if ($fileinfo->isFile() && preg_match('/^(\d{6,7}).jpg$/', $file, $matches) === 1) {
//		echo "\nMATCHES MATCHES MATCHES\n";
//		print_r($matches);
		$imageBin = file_get_contents($imageFilePath);
		$imageHex = bin2hex($imageBin);
		$requestName = 'getImage';
		$tag = $matches[1] . ' : ' . $requestName;
		$request = new stdClass();
		$request->Modifiers = new stdClass();
		$request->Modifiers->DebugMode = $patronApiDebugMode;
		$request->Modifiers->ReportMode = $patronApiReportMode;
		$request->SearchType = 'Patron ID';
		$request->SearchID = $matches[1]; // Patron ID
		$request->ImageType = 'Profile'; // Patron Profile Picture vs. Signature
//print_r($request);
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
		if ($result) {
			$imageHexCarl = '';
			$imageHexCarl = getImageDataFromResponse($result);
		}

		if ($imageBin !== $imageHexCarl) {
			echo "Local does not match CarlX: $imageFilePath\n";
			$requestName 			= 'updateImage';
			$tag 					= $matches[1] . ' : ' . $requestName;
			$imageData 				= file_get_contents($imageFilePath);
			// Check if running on Linux and convert to hex if necessary
			if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
				$imageData = bin2hex($imageData);
			}
			$request->ImageData		= $imageData;
			$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
		}
	}
}
if (count($errors) > 0) {
	print_r($errors);
}
?>
