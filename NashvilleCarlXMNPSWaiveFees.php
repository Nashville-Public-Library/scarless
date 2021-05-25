<?php

// Waive MNPS fees at the end of the school year
// 2021 05 24
// JAMES STAUB, NASHVILLE PUBLIC LIBRARY

class nashvilleCarlXMNPSWaiveFees
{
	private $carlx_db_php;
	private $carlx_db_php_user;
	private $carlx_db_php_password;
	private $circulationApiLogin;
	private $circulationApiPassword;
	private $apiURL;
	private $apiDebugMode;
	private $apiReportMode;
	private $itemApiWsdl;
	private $circulationApiWsdl;

	function getConfig()
	{
		date_default_timezone_set('America/Chicago');
		// $startTime = microtime(true);
		$this->reportPath = '../data/';
		$configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
		$this->carlx_db_php = $configArray['Catalog']['carlx_db_php'];
		$this->carlx_db_php_user2 = $configArray['Catalog']['carlx_db_php_user2'];
		$this->carlx_db_php_password2 = $configArray['Catalog']['carlx_db_php_password2'];
		$this->circulationApiLogin = $configArray['Catalog']['circulationApiLogin'];
		$this->circulationApiPassword = $configArray['Catalog']['circulationApiPassword'];
		$this->apiURL = $configArray['Catalog']['apiURL'];
		$this->apiDebugMode = $configArray['Catalog']['apiDebugMode'];
		$this->apiReportMode = $configArray['Catalog']['apiReportMode'];
		$this->itemApiWsdl = $this->apiURL . 'ItemAPI.wsdl';
		$this->circulationApiWsdl = $this->apiURL . 'CirculationAPI.wsdl';
		$this->alias = $configArray['Catalog']['staffInitials'];
	}

	function getItemsToCheckIn()
	{
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
		where p.bty not in (10,13,40,42)
		and t.transcode in ('L', 'O')
		and t.lastactiondate > '01-MAR-20'
		and ib.branchgroup = 2
		and pb.branchgroup = 2
		and t.patronid not in (select pp.patronid from patron_v2 pp where pp.bty = 38 and pp.patronid not like '190%')
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
		while (($row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS)) != false) {
			$data[] = $row;
		}
		oci_free_statement($stid);
		oci_close($conn);
		return $data;
	}

	function callAPI($wsdl, $requestName, $request, $tag)
	{
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
				if (is_null($result->response)) {
					$result->response = $client->__getLastResponse();
				}
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
							$result->error .= implode($shortMessages);
						} elseif (!empty($longMessages)) {
							$result->error .= implode($longMessages);
						}
					}
				} else {
					$result->error = "ERROR: " . $tag . " : No SOAP response from API.";
				}
			} catch (SoapFault $e) {
				if ($numTries == 2) {
					$result->error = "EXCEPTION: " . $tag . " : " . $e->getMessage();
				}
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

	function checkinViaAPI($item, $branchcode, $patronid)
	{
		$requestName = 'CheckinItem';
		$tag = $requestName . ' ' . $item . ' from ' . $patronid;
		$requestCheckinItem = new stdClass();
		$requestCheckinItem->Modifiers = new stdClass();
		$requestCheckinItem->Modifiers->DebugMode = $this->apiDebugMode;
		$requestCheckinItem->Modifiers->ReportMode = $this->apiReportMode;
		$requestCheckinItem->Modifiers->EnvBranch = $branchcode;
		$requestCheckinItem->ItemID = $item; // Item Barcode
		$requestCheckinItem->Alias = $this->alias; // Staffer alias
		return $this->callAPI($this->circulationApiWsdl, $requestName, $requestCheckinItem, $tag);
	}

	function itemUpdateToMissing($item)
	{
		$requestName = 'UpdateItem';
		$tag = $requestName . ' ' . $item;
		$requestUpdateItem = new stdClass();
		$requestUpdateItem->Modifiers = new stdClass();
		$requestUpdateItem->Modifiers->DebugMode = $this->apiDebugMode;
		$requestUpdateItem->Modifiers->ReportMode = $this->apiReportMode;
		$requestUpdateItem->ItemID = $item; // Item Barcode
		$requestUpdateItem->Item = new stdClass();
		$requestUpdateItem->Item->Status = 'SM';
		return $this->callAPI($this->itemApiWsdl, $requestName, $requestUpdateItem, $tag);
	}

}

$waive = new nashvilleCarlXMNPSWaiveFees();
$waive->getConfig();
$items = $waive->getItemsToCheckIn();
foreach ($items as $item)
{
	var_dump($item);
	$resultCheckin = $waive->checkinViaAPI($item['ITEM'], $item['BRANCHCODE'], $item['PATRONID']);
	$resultItemUpdate = $waive->itemUpdateToMissing($item['ITEM']);
	var_dump($resultCheckin);
	var_dump($resultItemUpdate);
	break;
}
