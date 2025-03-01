<?php

// Waive MNPS fees at the end of the school year
// 2021 05 24
// JAMES STAUB, NASHVILLE PUBLIC LIBRARY

// 2021 08 18: there is widely divergent code that appears to be what ran on catalog when this process was actually run. That code is included at the end, commented out. Ack! I don't have the time right now to figure out which is best...
// 2024 06 04: tweaked to handle 2024 fees for unhoused students

class nashvilleCarlXMNPSWaiveFees
{
	private $reportPath;
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
	private $alias;

	function getConfig()
	{
		date_default_timezone_set('America/Chicago');
		// $startTime = microtime(true);
		$this->reportPath = '../data/';
		$configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
		$this->carlx_db_php = $configArray['Catalog']['carlx_db_php'];
		$this->carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
		$this->carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
		$this->circulationApiLogin = $configArray['Catalog']['circulationApiLogin'];
		$this->circulationApiPassword = $configArray['Catalog']['circulationApiPassword'];
		$this->apiURL = $configArray['Catalog']['apiURL'];
		$this->apiDebugMode = $configArray['Catalog']['apiDebugMode'];
		$this->apiReportMode = $configArray['Catalog']['apiReportMode'];
//$this->apiReportMode = true;
		$this->itemApiWsdl = $this->apiURL . 'ItemAPI.wsdl';
		$this->circulationApiWsdl = $this->apiURL . 'CirculationAPI.wsdl';
		$this->alias = $configArray['Catalog']['staffInitials'];
	}

	function getItemsToCheckInViaCSV() {
		$fhnd = fopen($this->reportPath . "CARLX_MNPS_WAIVE.CSV", "r");
		if ($fhnd){
			$header = fgetcsv($fhnd);
			while ($row = fgetcsv($fhnd)) {
				$all_rows[] = array_combine($header, $row);
			}
		}
		fclose($fhnd);
		return $all_rows;
	}

	function getItemsToCheckInViaSQL()
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
		where p.bty not in (10,13,40,42,51)
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

	function callAPI($wsdl, $requestName, $request, $tag, $client)
	{
		$connectionPassed = false;
		$numTries = 0;
		$result = new stdClass();
		$result->response = "";
		while (!$connectionPassed && $numTries < 3) {
			try {
				if ($client === null) {
					$client = new SOAPClient($wsdl, array('connection_timeout' => 3, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1, 'login' => $this->circulationApiLogin, 'password' => $this->circulationApiPassword));
				}
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
			echo $result->error . "\n";
		} else {
			echo "SUCCESS: " . $tag . "\n";
		}
		return $result;
	}

	function checkinViaAPI($item, $branchcode, $circulationAPIClient)
	{
		$requestName = 'CheckinItem';
		$tag = $requestName . ' ' . $item;
		$requestCheckinItem = new stdClass();
		$requestCheckinItem->Modifiers = new stdClass();
		$requestCheckinItem->Modifiers->DebugMode = $this->apiDebugMode;
		$requestCheckinItem->Modifiers->ReportMode = $this->apiReportMode;
		$requestCheckinItem->Modifiers->EnvBranch = $branchcode;
		$requestCheckinItem->ItemID = $item; // Item Barcode
		$requestCheckinItem->Alias = $this->alias; // Staffer alias
//		$requestCheckinItem->damagedItemNote = 'MNPS 2020-21 pandemic waive';
		$requestCheckinItem->damagedItemNote = 'MNPS 2024 06 HERO waive';
		return $this->callAPI($this->circulationApiWsdl, $requestName, $requestCheckinItem, $tag, $circulationAPIClient);
	}

	function itemUpdateToMissing($item, $itemAPIClient)
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
		return $this->callAPI($this->itemApiWsdl, $requestName, $requestUpdateItem, $tag, $itemAPIClient);
	}

	function createItemNote ($item, $itemAPIClient)
	{
		$requestName = 'createItemNote';
		$tag = $item . ': ' . $requestName;
		$request = new stdClass();
		$request->Modifiers = new stdClass();
		$request->Modifiers->DebugMode = $this->apiDebugMode;
		$request->Modifiers->ReportMode = $this->apiReportMode; // 2024 06 09 James Staub: even when ReportMode is set to true, the Item Note is ACTUALLY created
		$request->Modifiers->EnvBranch = 'VI';
		$request->Note = new stdClass();
		$request->Note->Item = $item;
		$request->Note->NoteType = 'Standard Note';
//		$request->Note->NoteText = 'MNPS 2020-21 pandemic waive';
		$request->Note->NoteText = 'MNPS 2024 06 HERO waive';
		$request->Note->Alias = $this->alias;
		return $this->callAPI($this->itemApiWsdl, $requestName, $request, $tag, $itemAPIClient);
	}
}

$waive = new nashvilleCarlXMNPSWaiveFees();
$waive->getConfig();
// $items = $waive->getItemsToCheckInViaSQL();
$items = $waive->getItemsToCheckInViaCSV();
$itemAPIClient = new SOAPClient($this->itemApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
$circulationAPIClient = new SOAPClient($this->circulationApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
foreach ($items as $item)
{
	var_dump($item);
	$resultCheckin = $waive->checkinViaAPI($item['ITEM'], $item['BRANCHCODE'], $circulationAPIClient);
	var_dump($resultCheckin);
	$resultItemUpdate = $waive->itemUpdateToMissing($item['ITEM'], $itemAPIClient);
	var_dump($resultItemUpdate);
	$resultItemNote = $waive->createItemNote($item['ITEM'], $itemAPIClient);
	var_dump($resultItemNote);
}

/*
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
//$catalogApiWsdl = $configArray['Catalog']['catalogApiWsdl'];
//$catalogApiDebugMode = $configArray['Catalog']['catalogApiDebugMode'];
//$catalogApiReportMode = $configArray['Catalog']['catalogApiReportMode'];

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
                where p.bty not in (10,13,38,40,42,51)
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
//              echo '<h1>result->error</h1>';
//              var_dump($result->error);
//              echo "\n\n";
        } else {
//              echo "SUCCESS: " . $tag . "\n";
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
        //      ItemCheckIn();
//      ItemUpdateMissing();
}

 */