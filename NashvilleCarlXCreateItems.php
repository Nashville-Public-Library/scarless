<?php

// echo 'SYNTAX: path/to/php NashvilleCarlXCreateItems.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php NashvilleCarlXCreateItems.php\n';
// 
// TO DO: logging
// TO DO: capture other errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement; No matching records found

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'PEAR.php';
require_once 'ic2carlx_put_carlx.php';

$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$itemApiWsdl          = $configArray['Catalog']['itemApiWsdl'];
$itemApiDebugMode     = $configArray['Catalog']['itemApiDebugMode'];
$itemApiReportMode    = $configArray['Catalog']['itemApiReportMode'];
$reportPath             = '../data/';

//////////////////// FUNCTIONS ////////////////////

function callAPI($wsdl, $requestName, $request, $tag) {
//	$logger = Log::singleton('file', $reportPath . 'ic2carlx.log');
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
//		$logger->log("$result->error");
	} else {
		echo "SUCCESS: " . $tag . "\n";
	}
	return $result;
}

//////////////////// CREATE CARLX ITEMS ////////////////////
/* DATA FILE SHOULD HAVE
ItemID			949b
BID			910a
OwningBranch		949h
OwningLocation		949l
ReserveLoanPeriod	0
ReserveMedia		0
Status			949s
Media			949m
Price			949p
RotateFlag		''
CallNumber		949c
Branch			949j
Location		949l
ReserveBranch		0
ReserveLocation		0
ReserveType		Regular
ReserveCallNumber	''
ManuallySuppressed	false
AlternateStatus		''
*/

$all_rows = array();
$fhnd = fopen("../data/NashvilleCarlXCreateItems.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
        while ($row = fgetcsv($fhnd)) {
                $all_rows[] = array_combine($header, $row);
        }
}
//print_r($all_rows);
foreach ($all_rows as $item) {
        // CREATE REQUEST
        $requestName                                                    = 'createItem';
        $tag                                                            = $item['ItemID'] . ' : ' . $requestName;
        $request                                                        = new stdClass();
        $request->Modifiers                                             = new stdClass();
        $request->Modifiers->DebugMode                                  = $itemApiDebugMode;
        $request->Modifiers->ReportMode                                 = $itemApiReportMode;
        $request->Item	                                                = new stdClass();
	// NB does not include Bucket, Chronology, Enumeration 'cause I'm lazy
	$request->Item->ItemID						= $item['ItemID'];
	$request->Item->BID						= $item['BID'];
	$request->Item->OwningBranch					= $item['OwningBranch'];
	$request->Item->OwningLocation					= $item['OwningLocation'];
	$request->Item->ReserveLoanPeriod				= $item['ReserveLoanPeriod'];
	$request->Item->ReserveMedia					= $item['ReserveMedia'];
	$request->Item->Status						= $item['Status'];
	$request->Item->Media						= $item['Media'];
	$request->Item->Price						= $item['Price'];
	$request->Item->RotateFlag					= $item['RotateFlag'];
	$request->Item->CallNumber					= $item['CallNumber'];
	$request->Item->Branch						= $item['Branch'];
	$request->Item->Location					= $item['Location'];
	$request->Item->ReserveBranch					= $item['ReserveBranch'];
	$request->Item->ReserveLocation					= $item['ReserveLocation'];
	$request->Item->ReserveType					= $item['ReserveType'];
	$request->Item->ReserveCallNumber				= $item['ReserveCallNumber'];
	$request->Item->ManuallySuppressed				= $item['ManuallySuppressed'];
	$request->Item->AlternateStatus					= $item['AlternateStatus'];

