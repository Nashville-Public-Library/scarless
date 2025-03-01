<?php

// echo 'SYNTAX: path/to/php NashvilleCarlXCreateItems.php, e.g., $ sudo /opt/rh/php55/root/usr/bin/php NashvilleCarlXCreateItems.php\n';
// 
// TO DO: logging
// TO DO: capture other errors, e.g., org.hibernate.exception.ConstraintViolationException: could not execute statement; No matching records found
// TO DO: this is an incomplete script; I have no memory of why I stopped mid-foreach-loop

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
$client = new SOAPClient($itemApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
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

