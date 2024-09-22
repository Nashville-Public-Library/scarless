<?php

//////////////////// COMPARE LOCAL PATRON IMAGES AGAINST CARLX ////////////////////
// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdatePatronImage.php --start=190000000 --type=both\n';

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
		return $imageData;
	}
}

$options = getopt("",["start:","type:"]);
$start = isset($options['start']) ? (int)$options['start'] : 0;
$type = $options['type'] ?? 'both';

$imageFiles = [];

if ($type === 'staff' || $type === 'both') {
	$staffImageIterator = new DirectoryIterator('../data/images/staff');
	$staffImageFiles = iterator_to_array($staffImageIterator);
	$staffImageFiles = array_filter($staffImageFiles, function ($fileinfo) {
		return $fileinfo->isFile();
	});
	usort($staffImageFiles, function ($a, $b) {
		return $a->getFileName() - $b->getFileName();
	});
}

if ($type === 'student' || $type === 'both') {
	$studentImageIterator = new DirectoryIterator('../data/images/students');
	$studentImageFiles = iterator_to_array($studentImageIterator);
	$studentImageFiles = array_filter($studentImageFiles, function ($fileinfo) {
		return $fileinfo->isFile();
	});
	usort($studentImageFiles, function ($a, $b) {
		return $a->getFileName() - $b->getFileName();
	});
}

$imageFiles = array_merge($staffImageFiles, $studentImageFiles);

foreach ($imageFiles as $fileInfo) {
	$file = $fileInfo->getFilename();
	$imageFilePath = $fileInfo->getPathname();
	$mtime = $fileInfo->getMTime();
	$matches = [];
	if ($fileInfo->isFile() && preg_match('/^(190\d{6}|\d{6,7}).jpg$/i', $file, $matches) === 1) {
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
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
		$imageCarl = '';
		if ($result) {
			$imageCarl = getImageDataFromResponse($result);
		}

		if ($imageBin !== $imageCarl) {
			echo "Local does not match CarlX: $imageFilePath\n";
			$requestName 			= 'updateImage';
			$tag 					= $matches[1] . ' : ' . $requestName;
			$imageData 				= file_get_contents($imageFilePath);
			$request->ImageData		= $imageData;
			$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
		}
	}
}

?>
