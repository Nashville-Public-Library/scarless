<?php

// Batch change due dates for Nashville Public Library CarlX items
// 2024 01 10
// JAMES STAUB, NASHVILLE PUBLIC LIBRARY

class nashvilleCarlXCirculationChangeDueDates
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
		$this->itemApiWsdl = $this->apiURL . 'ItemAPI.wsdl';
		$this->circulationApiWsdl = $this->apiURL . 'CirculationAPI.wsdl';
//		$this->alias = $configArray['Catalog']['staffInitials'];

		$this->apiDebugMode = true;
		$this->apiReportMode = false;

	}

	function getDataViaCSV() {
		$fhnd = fopen($this->reportPath . "CARLX_CHANGE_DUE_DATES.CSV", "r");
		if ($fhnd){
			$header = fgetcsv($fhnd);
			while ($row = fgetcsv($fhnd)) {
				$all_rows[] = array_combine($header, $row);
			}
		}
		fclose($fhnd);
		return $all_rows;
	}

	function getDataViaSQL()
	{
		$sql = <<<EOT
with ti as (
    select
        ti.*
    from transitem_v2 ti
    where transcode in ('C','O')
    and duedate < '01-APR-24' -- hardcoded for Bellevue following 2024 01 08 branch closure
), tx as (
    select
        *
    from txlog_v2
    where systemtimestamp >= add_months(trunc(sysdate, 'MON'), -6)
), txx as (
    select
        rank() over (partition by item order by systemtimestamp desc) as rank
        , tx.*
    from tx
    where tx.transactiontype = 'CH'
    and tx.envbranch = '2' -- hardcoded for Bellevue following 2024 01 08 branch closure
)    
select
    ti.item
    , ti.patronid
from ti
left join txx on ti.item = txx.item and ti.patronid = txx.patronid
where txx.rank = 1
order by ti.item, ti.patronid
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
			echo $result->error . "\n";
		} else {
			echo "SUCCESS: " . $tag . "\n";
		}
		return $result;
	}

	function checkoutViaAPI($item, $patronid)
	{
		$requestName = 'CheckoutItem';
		$tag = $requestName . ' ' . $item;
		$requestCheckoutItem = new stdClass();
		$requestCheckoutItem->Modifiers = new stdClass();
		$requestCheckoutItem->Modifiers->DebugMode = $this->apiDebugMode;
		$requestCheckoutItem->Modifiers->ReportMode = $this->apiReportMode;
		$requestCheckoutItem->Modifiers->EnvBranch = 'BL'; // hardcoded for Bellevue following 2024 01 08 branch closure
		$requestCheckoutItem->PatronSearchType = 'Patron ID';
		$requestCheckoutItem->PatronSearchID = $patronid; // Patron Barcode
		$requestCheckoutItem->ItemID = $item; // Item Barcode
		$requestCheckoutItem->Alias = 'PIK'; // Staffer alias
//		$requestCheckoutItem->damagedItemNote = '';
		$requestCheckoutItem->Override = true; // Circulation override
		return $this->callAPI($this->circulationApiWsdl, $requestName, $requestCheckoutItem, $tag);
	}
}

$update = new nashvilleCarlXCirculationChangeDueDates();
$update->getConfig();
$items = $update->getDataViaSQL();
// $items = $update->getDataViaCSV();
foreach ($items as $item)
{
//	var_dump($item);
	$result = $update->checkoutViaAPI($item['ITEM'], $item['PATRONID']);
//	var_dump($resultCheckin);
}
