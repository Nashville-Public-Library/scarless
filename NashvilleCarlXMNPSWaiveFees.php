<?php

// Waive MNPS fees at the end of the school year
// 2021 05 24
// JAMES STAUB, NASHVILLE PUBLIC LIBRARY

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);
$reportPath = '../data/';

$configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
$carlx_db_php = $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
$circulationApiLogin = $configArray['Catalog']['circulationApiLogin'];
$circulationApiPassword = $configArray['Catalog']['circulationApiPassword'];
$circulationApiWsdl = $configArray['Catalog']['circulationApiWsdl'];
$circulationApiDebugMode = $configArray['Catalog']['circulationApiDebugMode'];
$circulationApiReportMode = $configArray['Catalog']['circulationApiReportMode'];
$patronApiWsdl = $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode = $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode = $configArray['Catalog']['patronApiReportMode'];
$catalogApiWsdl = $configArray['Catalog']['catalogApiWsdl'];
$catalogApiDebugMode = $configArray['Catalog']['catalogApiDebugMode'];
$catalogApiReportMode = $configArray['Catalog']['catalogApiReportMode'];

//////////////////// FUNCTIONS ////////////////////

function getItemsToCheckIn() {

	$sql = <<<EOT
		select t.item
			, ib.branchcode
			, t.patronid
			, t.amountdebited
		from transitem_v2 t
		left join patron_v2 p on t.patronid = p.patronid
		left join item_v2 i on t.item = i.item
		left join branch_v2 pb on p.defaultbranch = pb.branchnumber
		left join branch_v2 ib on i.owningbranch = ib.branchnumber
		where p.bty not in (10,13,38,40,42)
		and t.lastactiondate > '01-MAR-20' -- is this use of lastactiondate the right way to find items checked out after March 1 2020?
		and p.expdate > (sysdate) -- do we really want to exclude patrons expired over the course of 2020?
		and i.status='L' -- are all the things that should be L actually L (following the TLC work on ticket 
		-- and regexp_like(t.item, '^[a-zA-Z].{3,}$')
		and ib.branchgroup = 2
		-- and p.patronid not like '2519%'
		and pb.branchgroup = 2
EOT;

	// connect to carlx oracle db
	$conn = oci_connect($this->carlx_db_php_user, $this->carlx_db_php_password, $this->carlx_db_php, 'AL32UTF8');
	if (!$conn) {
		$e = oci_error();
		trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
	}
	$stid = oci_parse($conn, $sql);
	// TO DO: consider tuning oci_set_prefetch to improve performance. See https://docs.oracle.com/database/121/TDPPH/ch_eight_query.htm#TDPPH172
	oci_set_prefetch($stid, 10000);
	oci_execute($stid);
	$data = array();
	while (($row = oci_fetch_array ($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
		$data[] = $row;
	}
	oci_free_statement($stid);
	oci_close($conn);
	return $data;
}

function callAPI($wsdl, $requestName, $request, $tag) {
	$this->circulationApiLogin;
	$this->circulationApiPassword;
	$connectionPassed = false;
	$numTries = 0;
	$result = new stdClass();
	$result->response = "";
	while (!$connectionPassed && $numTries < 3) {
		try {
			$client = new SOAPClient($wsdl, array('connection_timeout' => 3, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1, 'login' => $this->circulationApiLogin, 'password' => $this->circulationApiPassword));
			$result->response = $client->$requestName($request);
			$connectionPassed = true;
			if (is_null($result->response)) {$result->response = $client->__getLastResponse();}
			if (!empty($result->response)) {
				if (gettype($result->response) == 'object') {
					$ShortMessage[0] = $result->response->ResponseStatuses->ResponseStatus->ShortMessage;
					if ($ShortMessage[0] == 'Successful operation') {
						$result->success = $ShortMessage[0];
					} else {
						$result->error = "ERROR: " . $tag . " : " . $ShortMessage[0];
					}
				} else if (gettype($result->response) == 'string') {
					$result->success = stripos($result->response, '<ns2:ShortMessage>Successful operation</ns2:ShortMessage>') !== false;
					preg_match('/<ns2:LongMessage>(.+?)<\/ns2:LongMessage>/', $result->response, $longMessages);
					preg_match('/<ns2:ShortMessage>(.+?)<\/ns2:ShortMessage>/', $result->response, $shortMessages);
					if (!empty($shortMessages)) {
						$result->error  .= implode($shortMessages);
					} elseif (!empty($longMessages)) {
						$result->error  .= implode($longMessages);
					}
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
//		echo '<h1>result->error</h1>';
//		var_dump($result->error);
//		echo "\n\n";
	} else {
//		echo "SUCCESS: " . $tag . "\n";
	}
	return $result;
}

function checkinViaAPI($item, $branchcode)
{
// CIRCULATION API CHECKOUT
	$this->circulationApiWsdl;
	$this->circulationApiDebugMode;
	$this->circulationApiReportMode;
	$requestName = 'CheckinItem';
	$tag = $requestName . ' ' . $item . ' from ' . $patron;
	$requestCheckinItem = new stdClass();
	$requestCheckinItem->Modifiers = new stdClass();
	$requestCheckinItem->Modifiers->DebugMode = $this->circulationApiDebugMode;
	$requestCheckinItem->Modifiers->ReportMode = $this->circulationApiReportMode;
	$requestCheckinItem->Modifiers->EnvBranch = $branchcode;
	$requestCheckinItem->ItemID = $item; // Item Barcode
	$requestCheckinItem->Alias = $alias; // Staffer alias
	$resultCheckinItem = '';
	$resultCheckinItem = callAPI($this->circulationApiWsdl, $requestName, $requestCheckinItem, $tag);
}

function itemUpdateToMissing($item) {
	$this->itemApiWsdl;
	$this->itemApiDebugMode;
	$this->itemApiReportMode;
	$requestName = 'UpdateItem';
	$tag = $requestName . ' ' . $item;
	$requestUpdateItem = new stdClass();
	$requestUpdateItem->Modifiers = new stdClass();
	$requestUpdateItem->Modifiers->DebugMode = $this->itemApiDebugMode;
	$requestUpdateItem->Modifiers->ReportMode = $this->itemApiReportMode;
	$requestUpdateItem->ItemID = $item; // Item Barcode
	$requestUpdateItem->Item = new stdClass();
	$requestUpdateItem->Item->Status = 'SM';
	$resultUpdateItem = '';
	$resultUpdateItem = callAPI($this->itemApiWsdl, $requestName, $requestUpdateItem, $tag);
}


$items = getItemsToCheckIn();
foreach ($items as $item) {
	echo $items;
	//	ItemCheckIn();
//	ItemUpdateMissing();
}

