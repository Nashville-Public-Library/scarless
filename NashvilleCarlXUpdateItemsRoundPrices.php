<?php

// echo 'SYNTAX: $ sudo php NashvilleCarlXUpdateItems.php\n';
date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';

function getDataFromCarlX() {
	$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
	$carlx_db_php		= $configArray['Catalog']['carlx_db_php'];
	$carlx_db_php_user	= $configArray['Catalog']['carlx_db_php_user'];
	$carlx_db_php_password	= $configArray['Catalog']['carlx_db_php_password'];
	$reportPath		= '../data/';
	// connect to carlx oracle db
	$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php);
	if (!$conn) {
		$e = oci_error();
		trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
	}

	$sql = <<<EOT
	-- round item prices to nearest dollar
	select
			iv.item ,
			iv.price ,
			to_char(round(to_number(iv.price)), '99g999d99') as rounded_price
	from
			item_v2 iv
	where
			substr(iv.price, -2) != '00'
			and iv.owningbranch != 28
			-- and iv.item like '35192%' -- commented out to include all school items 2025 08 13
			and iv.status not in ('sw', 'sc')
			and iv.item not in (
				select
					   tv.item
				from
					   transitem_v2 tv
				where
					   tv.patronid like 'a9%'
			)
	order by
			iv.item asc
	EOT;

	$stid = oci_parse($conn, $sql);
	oci_set_prefetch($stid, 10000);
	oci_execute($stid);
	// start a new file for the CarlX item extract
	$df = fopen($reportPath . "CARLX_UPDATE_ITEMS.CSV", 'w');

	while (($row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS)) != false) {
		// CSV OUTPUT
		fputcsv($df, $row);
	}
	fclose($df);
	echo "CARLX items to be updated have been retrieved and written\n";
	oci_free_statement($stid);
	oci_close($conn);
}

function getDataFromCSV() {
	$reportPath		= '../data/';
	$records = array();
	$fhnd = fopen($reportPath . "CARLX_UPDATE_ITEMS.CSV", "r");
	if ($fhnd) {
		while (($data = fgetcsv($fhnd)) !== FALSE) {
			$records[] = $data;
		}
	}
	fclose($fhnd);
	return $records;
}

function updateItems() {
	date_default_timezone_set('America/Chicago');
	$startTime = microtime(true);

	require_once 'ic2carlx_put_carlx.php';

	$configArray		= parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
	$itemApiWsdl		= $configArray['Catalog']['itemApiWsdl'];

	$errors = array();

	$callcount = 0;
	getDataFromCarlX();
	$records = getDataFromCSV();
	$client = new SOAPClient($itemApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
	foreach ($records as $item) {
		// CREATE ITEM UPDATE REQUEST
		$requestName = 'updateItem';
		$tag = $item[0] . ' : ' . $requestName . ' : ' . $item[1] . ' -> ' . $item[2];
		$request = new stdClass();
		$request->Modifiers = new stdClass();
		$request->Modifiers->DebugMode = false;
		$request->Modifiers->ReportMode = false;
		$request->ItemID = $item[0];
		$request->Item = new stdClass();
		$request->Item->Price = trim($item[2]);

		$result = callAPI($itemApiWsdl, $requestName, $request, $tag, $client);
		$callcount++;
	//	var_dump($result);
	//	if (isset($result->Fault)) {
	//		echo "$result->Fault\n";
	//		$errors[] = $result->Fault;
	//	}
	}
}

updateItems();

//$ferror = fopen($reportPath . "NashvilleCarlXUpdateItems.log", "a");
//fwrite($ferror, "-------------------------------------------------------------\n");
//fwrite($ferror, date('c') . " BEGIN UPDATE ITEMS\n");
//fwrite($ferror, $sql . "\n");
//fwrite($ferror, implode(',',array_column($records,0)) . "\n");
//fwrite($ferror, implode("\n",$errors) . "\n\n");
//fclose($ferror);

?>
