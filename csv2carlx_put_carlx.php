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

function callAPI($wsdl, $requestName, $request, $tag) {
//	$logger = Log::singleton('file', $reportPath . 'csv2carlx.log');
//echo "REQUEST:\n" . var_dump($request) ."\n";
	$connectionPassed = false;
	$numTries = 0;
	$result = new stdClass();
	$result->response = "";
	while (!$connectionPassed && $numTries < 2) {
		try {
			$client = new SOAPClient($wsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
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

//////////////////// CREATE CARLX PATRONS ////////////////////
$all_rows = array();
$fhnd = fopen("../data/belmont.csv", "r");
if ($fhnd){
	$header = fgetcsv($fhnd);
	while ($row = fgetcsv($fhnd)) {
		$all_rows[] = array_combine($header, $row);
	}
}
fclose($fhnd);
//print_r($all_rows);
foreach ($all_rows as $patron) {
	// TESTING
	//if ($patron['PatronID'] != 'B99999999') { break; }


	// Last Name,First Name,Middle Name,BUID,Address,Hall,City,State,Email
	// CREATE REQUEST
	$requestName							= 'createPatron';
	$tag								= $patron['BUID'] . ' : ' . $requestName;
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->Patron						= new stdClass();
	$request->Patron->PatronID					= $patron['BUID']; // Patron ID
	$request->Patron->PatronType					= 48; // Patron Type 48 #University
	$request->Patron->LastName					= $patron['Last Name']; // Patron Name Last
	$request->Patron->FirstName					= $patron['First Name']; // Patron Name First
	$request->Patron->MiddleName					= $patron['Middle Name']; // Patron Name Middle
//	$request->Patron->SuffixName					= $patron['Patronsuffix']; // Patron Name Suffix
	$request->Patron->Addresses					= new stdClass();
	$request->Patron->Addresses->Address[0]				= new stdClass();
	$request->Patron->Addresses->Address[0]->Type			= 'Primary';
	$request->Patron->Addresses->Address[0]->Street			= 'Belmont University, ' . $patron['Hall'] . ' Hall'; // Patron Address Street
	$request->Patron->Addresses->Address[0]->City			= 'Nashville'; // Patron Address City
	$request->Patron->Addresses->Address[0]->State			= 'TN'; // Patron Address State
	$request->Patron->Addresses->Address[0]->PostalCode		= '37212'; // Patron Address ZIP Code
//	$request->Patron->Phone1					= $patron['PrimaryPhoneNumber']; // Patron Primary Phone
//	$request->Patron->Phone2					= $patron['SecondaryPhoneNumber']; // Patron Secondary Phone
	$request->Patron->DefaultBranch					= 'EH'; // Patron Default Branch
//	$request->Patron->LastActionBranch				= $patron['DefaultBranch']; // Patron Last Action Branch
//	$request->Patron->LastEditBranch				= 'VI'; // Patron Last Edit Branch
	$request->Patron->RegBranch					= 'VI'; // Patron Registration Branch
	$request->Patron->Email						= $patron['Email']; // Patron Email
//	$request->Patron->BirthDate					= $patron['BirthDate']; // Patron Birth Date as Y-m-d

//	NON-CSV STUFF
	$request->Patron->CollectionStatus				= 'not sent';
	$request->Patron->EmailNotices					= 'send email';
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2024-10-01')->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->LastActionDate				= date('c'); // Last Action Date, format ISO 8601
	$request->Patron->LastEditDate					= date('c'); // Patron Last Edit Date, format ISO 8601
	$request->Patron->LastEditedBy					= 'PIK'; // Pika Patron Loader
	$request->Patron->PatronStatusCode				= 'G'; // Patron Status Code = GOOD
	$request->Patron->PreferredAddress				= 'Primary';
	$request->Patron->RegisteredBy					= 'PIK'; // Registered By : Pika Patron Loader
	$request->Patron->RegistrationDate				= date('c'); // Registration Date, format ISO 8601
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
// SET PIN FOR CREATED PATRON
// createPatron is not setting PIN as requested. See TLC ticket 452557
// Therefore we use updatePatron to set PIN
	// CREATE REQUEST
	$requestName							= 'updatePatron';
	$tag								= $patron['BUID'] . ' : updatePatronPIN';
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= $patronApiDebugMode;
	$request->Modifiers->ReportMode					= $patronApiReportMode;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron['BUID']; // Patron ID
	$request->Patron						= new stdClass();
	if (stripos($patron['BUID'],'B99999999') === 0) {
		$request->Patron->PatronPIN				= '7357';
	} else {
		$request->Patron->PatronPIN				= createRandomPIN();
	}
	$result = callAPI($patronApiWsdl, $requestName, $request, $tag);
}

// create cryptographically secure random password for new patron that is 6 characters long and is composed of hexadecimal characters 0-9 and a-f
function createRandomPIN(): string {
	return bin2hex(random_bytes(3));
}
