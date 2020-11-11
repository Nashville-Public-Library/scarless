<?php

// EXAMPLE SCRIPT FOR ROWEN SHUE AT LOS ANGELES PUBLIC LIBRARY
// 2019 10 23
// JAMES STAUB, NASHVILLE PUBLIC LIBRARY

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);



//////////////////// FUNCTIONS ////////////////////

function callAPI($wsdl, $requestName, $request, $tag) {
//echo "REQUEST:\n" . var_dump($request) ."\n";
	$connectionPassed = false;
	$numTries = 0;
	$result = new stdClass();
	$result->response = "";
	while (!$connectionPassed && $numTries < 3) {
		try {
			$client = new SOAPClient($wsdl, array('connection_timeout' => 3, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
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
		}
		$numTries++;
	}
	if (isset($result->error)) {
		echo "$result->error\n";
	} else {
		echo "SUCCESS: " . $tag . "\n" ;
//		echo var_dump($result) . "\n\n" ;
	}
	return $result;
}

function getBidIsbn($bid) {
	$catalogApiWsdl		= 'http://nashapp.library.nashville.org:8082/CarlXAPI/CatalogAPI.wsdl';
	$catalogApiDebugMode	= false;
	$catalogApiReportMode	= false;
	$reportPath		= '../data/';
	$requestName							= 'getCatalogInformation';
	$tag								= $bid . ' : getCatalogInformation';
	$request							= new stdClass();
	$request->SearchField						= 'BID';
	$request->SearchFieldValue					= $bid;
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $catalogApiDebugMode;
	$request->Modifiers->ReportMode					= $catalogApiReportMode;
	$result = callAPI($catalogApiWsdl, $requestName, $request, $tag);
	$isbn = $result->response->Title->Isbn;
	echo "ISBN: " . $isbn . "\n";
}

getBidIsbn('815763');
