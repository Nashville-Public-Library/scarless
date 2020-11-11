<?php

// TO DO: set up github repository

// 20180221 built to update a list of defunct MNPS patrons BTY Patron Type to 37 
// echo 'SYNTAX: path/to/php NashvilleCarlXUpdatePatrons.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php NashvilleCarlXUpdatePatrons.php\n';

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);
$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$reportPath             = '../data/';

$records = array();
$fhnd = fopen($reportPath . "CARLX_MNPS_UPDATE_PATRONS.CSV", "r");
if ($fhnd){
        while (($data = fgetcsv($fhnd)) !== FALSE){
                $records[] = $data;
        }
}

$i = 0;
$errors = array();
foreach ($records as $patron) {
	// CREATE PATRON UPDATE REQUEST
	$request							= new stdClass();
	$request->Modifiers						= new stdClass();
	$request->Modifiers->DebugMode					= false;
	$request->Modifiers->ReportMode					= false;
	$request->SearchType						= 'Patron ID';
	$request->SearchID						= $patron[0]; // Patron ID
	$request->Patron						= new stdClass();
//	$request->Patron->DefaultBranch					= '117'; // McMurray
//	$request->Patron->PatronType					= '37';
//	$request->Patron->PatronType					= '42';
//	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2018-09-01')->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2018-08-01')->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2020-02-15')->format('c'); // Patron Expiration Date as ISO 8601
//	$request->Patron->PatronStatusCode				= 'G'; // GOOD
//	$request->Patron->PatronPIN					= 'DENIED';
//	$request->Patron->PatronPIN					= '1251';
//	$request->Patron->PatronID					= preg_replace('/^300/','3000',$patron[0]); // ILL patronid fix 20190425
//	$request->Patron->PatronType					= '9'; // ILL Customer
//	$request->Patron->DefaultBranch					= 'IL'; // ILL Office
	$request->Patron->ExpirationDate				= date_create_from_format('Y-m-d','2021-04-17')->format('c'); // Patron Expiration Date as ISO 8601
	var_dump($request);

	try {
// TO DO: set soapclient option to retry if service is temporarily unavailable
		$client = new SOAPClient($patronApiWsdl, array('features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
		$result = $client->updatePatron($request);
		$result = $client->__getLastResponse();
		if ($result) {
			$success = stripos($result, '<ns2:ShortMessage>Successful operation</ns2:ShortMessage>') !== false;
			if(!$success) {
// TO DO: catch errors in LongMessage. Will need to convert response into an array and iterate over all <ns2:ResponseStatus> 
				$errorMessage = $result;
				$errors[] = "$patron[0] : Failed to update patron " . ($errorMessage ? ' : ' . $errorMessage : '');
			} else {
				echo "$patron[0] : updated\n";
			}
		} else {
			$errors[] = "$patron[0] : No SOAP response from API.";
		}
	} catch (Exception $e) {
		$errors[] = "$patron[0] : Exception : " . $e->getMessage();
	}
//	if(++$i==100) break;
}

// TO DO: save the errors to a file.
// $ferror = fopen($reportPath . "NashvilleCarlXUpdatePatrons.error.txt", "w");

// TO DO : THIS AIN'T RIGHT fwrite($ferror, print_r($errors));
// fclose($ferror);

print_r($errors);

?>
