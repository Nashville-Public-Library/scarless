<?php

// echo 'SYNTAX: path/to/php ic2carlx.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php ic2carlx.php\n';
// 
// TO DO: logging
// TO DO: capture other patron api errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement; No matching records found
// TO DO: for patron data privacy, kill data files when actions are complete

//////////////////// CONFIGURATION ////////////////////

//require_once 'Log.php';

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl		= $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode	= $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode	= $configArray['Catalog']['patronApiReportMode'];
$reportPath		= '../data/';

//////////////////// FUNCTIONS ////////////////////

// Custom error handler function
// As of 2025 03 01, this custom error handler does NOT work
function customErrorHandler($errno, $errstr, $errfile, $errline) {
	if (strpos($errstr, 'Failed to open stream: Too many open files in') !== false) {
		// Handle the specific warning here
		echo "Custom Warning: $errstr in $errfile on line $errline\n";
		// Optionally, you can log this warning to a file or take other actions
		exit(1);
	}
	// Ensure the default error handler is still called
	return false;
}

// Set error reporting to include warnings
error_reporting(E_ALL);
// Set the custom error handler
set_error_handler('customErrorHandler');

function callAPI($wsdl, $requestName, $request, $tag, $client = null) {
//	$logger = Log::singleton('file', $reportPath . 'ic2carlx.log');
//echo "REQUEST:\n" . var_dump($request) ."\n";
	$connectionPassed = false;
	$numTries = 0;
	$result = new stdClass();
	$result->response = "";
	while (!$connectionPassed && $numTries < 2) {
		try {
			if (($client ?? null) === null) { // client is either not set or is null
				$client = new SOAPClient($wsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
			}
			$result->response = $client->$requestName($request);
//echo "REQUEST:\n" . $client->__getLastRequest() . "\n";
			$connectionPassed = true;
			if (is_null($result->response)) {$result->response = $client->__getLastResponse();}
			if (!empty($result->response)) {
				if (gettype($result->response) == 'object') {
					$ShortMessage[0] = $result->response->ResponseStatuses->ResponseStatus->ShortMessage;
					$result->success = $ShortMessage[0] == 'Successful operation';
				} else if (gettype($result->response) == 'string') {
					$result->success = stripos($result->response, '<ns2:ShortMessage>Successful operation</ns2:ShortMessage>') !== false;
					preg_match('/<ns2:LongMessage>(.+?)<\/ns2:LongMessage>/', $result->response, $longMessages);
					preg_match('/<ns2:ShortMessage>(.+?)<\/ns2:ShortMessage>/', $result->response, $shortMessages);
				}
				if(!$result->success) {
					$result->error = "ERROR: " . $tag . " : " . (isset($longMessages[1]) ? ' : ' . $longMessages[1] : (isset($shortMessages[0]) ? ' : ' . $shortMessages[0] : ''));
				}
			} else {
				$result->error = "ERROR: " . $tag . " : No SOAP response from API.";
			}
		} catch (SoapFault $e) {
			if ($numTries == 2) { $result->error = "EXCEPTION: " . $tag . " : " . $e->getMessage(); }
		} finally {
			unset($client); // Ensure the SOAP client is unset to free resources
		}
		$numTries++;
	}
	if (isset($result->error)) {
		echo "$result->error\n";
//		$logger->log("$result->error");
	} else {
		echo "SUCCESS: " . $tag . "\n";
	}
	return $result;
}

// create cryptographically secure random password for new patron that is 6 characters long and is composed of hexadecimal characters 0-9 and a-f
function createRandomPIN(): string {
	return bin2hex(random_bytes(3));
}

function getRecords($filename) {
	$fhnd = fopen($filename, 'r');
	if ($fhnd) {
		$header = fgetcsv($fhnd);
		while ($row = fgetcsv($fhnd)) {
			yield array_combine($header, $row);
		}
		fclose($fhnd);
	}
}
