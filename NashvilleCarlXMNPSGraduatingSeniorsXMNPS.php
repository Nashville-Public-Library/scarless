<?php
// NashvilleCarlXMNPSGraduatingSeniorsXMNPS.php
// Process to update 12th grade students (borrowerTypeCode 34) as removed (XMNPS) in CarlX
// This allows patrons to remain in the database and convert their accounts to Nashville Public Library accounts with staff assistance to update their information.
// Run this script sometime around the last day of school each year.

//////////////////// CONFIGURATION ////////////////////

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';

$configArray            = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$patronApiWsdl          = $configArray['Catalog']['patronApiWsdl'];
$patronApiDebugMode     = $configArray['Catalog']['patronApiDebugMode'];
$patronApiReportMode    = $configArray['Catalog']['patronApiReportMode'];
$reportPath             = '../data/';
$logFile                = '../data/NashvilleCarlXMNPSGraduatingSeniorsXMNPS_' . date('Y-m-d') . '.log';

// Initialize log file
file_put_contents($logFile, date('Y-m-d H:i:s') . " | 12th Grade Student Removal Process Started\n", FILE_APPEND);

//////////////////// PROMPT FOR INPUT FILE ////////////////////

echo "Please provide the full path to the CARLX_INFINITECAMPUS_STUDENT file to process.\n";
echo "IMPORTANT: Please double-check that this is the correct file : a copy of the IC student extract from the most recent day scarless was run - before proceeding.\n";
$inputFile = trim(fgets(STDIN));

if (!file_exists($inputFile)) {
	echo "Error: File not found. Please check the path and try again.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | Error: Input file not found: $inputFile\n", FILE_APPEND);
	exit(1);
}

if (strpos(basename($inputFile), 'CARLX_INFINITECAMPUS_STUDENT') !== 0) {
	echo "Warning: The file name does not start with 'CARLX_INFINITECAMPUS_STUDENT'. Are you sure this is the correct file? (y/n)\n";
	$confirm = trim(fgets(STDIN));
	if (strtolower($confirm) !== 'y') {
		echo "Process aborted by user.\n";
		file_put_contents($logFile, date('Y-m-d H:i:s') . " | Process aborted by user: File name validation failed\n", FILE_APPEND);
		exit(1);
	}
}

//////////////////// PROMPT FOR TEST OR REAL RUN ////////////////////

echo "Is this a test run? (test/real)\n";
echo "If you enter 'test', the script will process the data but NOT update CarlX records.\n";
echo "If you enter 'real', the script will process the data AND update CarlX records.\n";
$runMode = strtolower(trim(fgets(STDIN)));

$isTestRun = ($runMode === 'test');

if ($isTestRun) {
	echo "Running in TEST MODE - No changes will be made to CarlX records.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | Running in TEST MODE - No changes will be made to CarlX records\n", FILE_APPEND);
} else {
	echo "Running in REAL MODE - CarlX records will be updated.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | Running in REAL MODE - CarlX records will be updated\n", FILE_APPEND);
}

//////////////////// PROCESS INPUT FILE ////////////////////

// Read the input file and extract 12th grade students (borrowerTypeCode 34)
$grade12Students = array();
$fhnd = fopen($inputFile, "r");
if ($fhnd) {
	$lineCount = 0;
	$grade12Count = 0;

	while (($line = fgets($fhnd)) !== false) {
		$lineCount++;
		$fields = explode('|', $line);

		// Check if this is a 12th grade student (borrowerTypeCode 34)
		if (isset($fields[1]) && trim($fields[1]) === '34') {
			$patronId = trim($fields[0]);
			$grade12Students[] = $patronId;
			$grade12Count++;
		}
	}
	fclose($fhnd);

	echo "Processed $lineCount records, found $grade12Count 12th grade students.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | Processed $lineCount records, found $grade12Count 12th grade students\n", FILE_APPEND);
} else {
	echo "Error: Could not open input file.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | Error: Could not open input file\n", FILE_APPEND);
	exit(1);
}

if (count($grade12Students) === 0) {
	echo "No 12th grade students found in the input file. Process complete.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | No 12th grade students found in the input file\n", FILE_APPEND);
	exit(0);
}

//////////////////// RETRIEVE RECORDS FROM CARLX ////////////////////

// Modified SQL approach to handle large sets of records
// Instead of using IN clause with potentially thousands of IDs, we'll use a more efficient approach
$sqlContent = "
-- Retrieve 12th grade students from CarlX
SELECT patron_v2.patronid as \"Patron ID\"
  , patron_v2.bty as \"Borrower type code\"
  , patron_v2.lastname as \"Patron last name\"
  , patron_v2.firstname as \"Patron first name\"
  , patron_v2.email as \"Email Address\"
  , patron_v2.collectionstatus as \"Collection Status\"
  , patronbranch.branchcode as \"Default Branch\"
  , editbranch.branchcode as \"Edit Branch\"
FROM patron_v2
LEFT OUTER JOIN branch_v2 patronbranch ON patron_v2.defaultbranch = patronbranch.branchnumber
LEFT OUTER JOIN branch_v2 editbranch ON patron_v2.editbranch = editbranch.branchnumber
WHERE (
    -- Get all patrons with borrower types that match 12th grade or similar
    patron_v2.bty IN (34, 37, 46, 47)
    -- OR get all patrons with MNPS pattern IDs that aren't in the standard borrower type ranges
    OR (REGEXP_LIKE(patron_v2.patronid, '^190[0-9]{6}$') 
        AND patron_v2.bty NOT IN (21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 46, 47))
)
ORDER BY patron_v2.patronid
";

// Define the output file for CarlX data
$carlxDataFile = $reportPath . 'ic2carlx_mnps_12thgrade_carlx_data.csv';

// Connect to CarlX Oracle database and execute the query
echo "Retrieving records from CarlX...\n";
file_put_contents($logFile, date('Y-m-d H:i:s') . " | Retrieving records from CarlX\n", FILE_APPEND);

// Get database connection parameters from config
$carlx_db_php = $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];

// Connect to CarlX Oracle database
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php, 'AL32UTF8');
if (!$conn) {
	$e = oci_error();
	$errorMessage = "Oracle Connection Error: " . htmlentities($e['message'], ENT_QUOTES);
	echo $errorMessage . "\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | " . $errorMessage . "\n", FILE_APPEND);
	exit(1);
}

// Parse and execute the SQL query
$stid = oci_parse($conn, $sqlContent);
if (!$stid) {
	$e = oci_error($conn);
	$errorMessage = "Oracle SQL Parse Error: " . htmlentities($e['message'], ENT_QUOTES);
	echo $errorMessage . "\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | " . $errorMessage . "\n", FILE_APPEND);
	oci_close($conn);
	exit(1);
}

// Set prefetch for better performance with large result sets
oci_set_prefetch($stid, 10000);

// Execute the query
$r = oci_execute($stid);
if (!$r) {
	$e = oci_error($stid);
	$errorMessage = "Oracle SQL Execute Error: " . htmlentities($e['message'], ENT_QUOTES);
	echo $errorMessage . "\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | " . $errorMessage . "\n", FILE_APPEND);
	oci_free_statement($stid);
	oci_close($conn);
	exit(1);
}

// Write results to CSV file
$fhnd = fopen($carlxDataFile, 'w');
if (!$fhnd) {
	$errorMessage = "Error: Could not create output file: $carlxDataFile";
	echo $errorMessage . "\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | " . $errorMessage . "\n", FILE_APPEND);
	oci_free_statement($stid);
	oci_close($conn);
	exit(1);
}

// Write header row first (column names)
$firstRow = true;
$recordCount = 0;

// Process the result set
$carlxRecords = array();
while (($row = oci_fetch_array($stid, OCI_ASSOC+OCI_RETURN_NULLS)) !== false) {
	// Write header row on first iteration
	if ($firstRow) {
		fputcsv($fhnd, array_keys($row));
		$firstRow = false;
	}

	// Write data row
	fputcsv($fhnd, $row);

	// Store in memory for processing
	$carlxRecords[$row['Patron ID']] = $row;
	$recordCount++;

	// Progress indicator for large result sets
	if ($recordCount % 1000 === 0) {
		echo "Retrieved $recordCount records so far...\n";
	}
}

fclose($fhnd);
oci_free_statement($stid);
oci_close($conn);

echo "Retrieved $recordCount records from CarlX.\n";
file_put_contents($logFile, date('Y-m-d H:i:s') . " | Retrieved $recordCount records from CarlX\n", FILE_APPEND);

// Filter the retrieved records to only include the 12th grade students we're interested in
$filteredRecords = array();
foreach ($grade12Students as $patronId) {
	if (isset($carlxRecords[$patronId])) {
		$filteredRecords[$patronId] = $carlxRecords[$patronId];
	}
}

// Replace the full set with just our filtered records
$carlxRecords = $filteredRecords;
echo "Filtered to " . count($carlxRecords) . " 12th grade students from input file.\n";
file_put_contents($logFile, date('Y-m-d H:i:s') . " | Filtered to " . count($carlxRecords) . " 12th grade students from input file\n", FILE_APPEND);

//////////////////// DETERMINE ACTIONS ////////////////////

$updateRecords = array();
$alreadyXMNPS = array();
$manualReview = array();

foreach ($grade12Students as $patronId) {
	if (!isset($carlxRecords[$patronId])) {
		// Record not found in CarlX
		file_put_contents($logFile, date('Y-m-d H:i:s') . " | Warning: Patron ID $patronId not found in CarlX\n", FILE_APPEND);
		continue;
	}

	$record = $carlxRecords[$patronId];
	$bty = $record['Borrower type code'];

	// 4.1. If the CarlX record has BTY = 38, they are already XMNPS and need not be altered
	if ($bty == '38') {
		$alreadyXMNPS[] = $patronId;
	}
	// 4.2. If the CarlX record has BTY in the set (34, 37, 46, 47), then update to XMNPS
	elseif (in_array($bty, array('34', '37', '46', '47'))) {
		$updateRecords[] = $record;
	}
	// 4.3. If the CarlX record has a different BTY, log for manual review
	else {
		$manualReview[] = array(
			'patronId' => $patronId,
			'bty' => $bty,
			'name' => $record['Patron first name'] . ' ' . $record['Patron last name']
		);
	}
}

echo "Action summary:\n";
echo "- " . count($updateRecords) . " records to update to XMNPS\n";
echo "- " . count($alreadyXMNPS) . " records already XMNPS (no action needed)\n";
echo "- " . count($manualReview) . " records need manual review\n";

file_put_contents($logFile, date('Y-m-d H:i:s') . " | Action summary: " . count($updateRecords) . " to update, " .
	count($alreadyXMNPS) . " already XMNPS, " . count($manualReview) . " need manual review\n", FILE_APPEND);

//////////////////// UPDATE CARLX RECORDS ////////////////////

if (count($updateRecords) > 0 && !$isTestRun) {
	echo "Updating " . count($updateRecords) . " records to XMNPS...\n";

	$client = new SOAPClient($patronApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));
	$updateCount = 0;

	foreach ($updateRecords as $patron) {
		// CREATE REQUEST
		$requestName = 'updatePatron';
		$tag = $patron['Patron ID'] . ' : removePatron';
		$request = new stdClass();
		$request->Modifiers = new stdClass();
		$request->Modifiers->DebugMode = $patronApiDebugMode;
		$request->Modifiers->ReportMode = $patronApiReportMode;
		$request->SearchType = 'Patron ID';
		$request->SearchID = $patron['Patron ID'];
		$request->Patron = new stdClass();
		$request->Patron->PatronType = '38'; // Patron Type = Expired MNPS
		$request->Patron->Phone2 = ''; // Patron Secondary Phone
		$request->Patron->DefaultBranch = 'XMNPS'; // Patron Default Branch
		$request->Patron->LastEditBranch = 'XMNPS'; // Patron Last Edit Branch
		$request->Patron->RegBranch = 'XMNPS'; // Patron Registration Branch

		// Handle collection status
		if ($patron['Collection Status'] == 0 || $patron['Collection Status'] == 1 || $patron['Collection Status'] == 78) {
			$request->Patron->CollectionStatus = 'not sent';
		}

		// Handle email address
		if (stripos($patron['Email Address'], '@mnpsk12.org') > 0 || stripos($patron['Email Address'], '@mnps.org') > 0) {
			$request->Patron->Email = ''; // Clear MNPS email addresses
			$request->Patron->EmailNotices = 'do not send email';
		}

		// Remove teacher/homeroom information
		$request->Patron->Addresses = new stdClass();
		$request->Patron->Addresses->Address[0] = new stdClass();
		$request->Patron->Addresses->Address[0]->Type = 'Secondary';
		$request->Patron->Addresses->Address[0]->Street = ''; // Clear Teacher ID
		$request->Patron->SponsorName = ''; // Clear Teacher Name

		// Set expiration date to yesterday
		$request->Patron->ExpirationDate = date('c', strtotime('yesterday')); // Patron Expiration Date as ISO 8601
		$request->Patron->LastEditDate = date('c'); // Patron Last Edit Date, format ISO 8601
		$request->Patron->LastEditedBy = 'PIK'; // Pika Patron Loader
		$request->Patron->PreferredAddress = 'Primary';

		// Call the API
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);

		// Add note to patron record
		$requestName = 'addPatronNote';
		$tag = $patron['Patron ID'] . ' : addPatronRemoveNote';
		$request = new stdClass();
		$request->Modifiers = new stdClass();
		$request->Modifiers->DebugMode = $patronApiDebugMode;
		$request->Modifiers->ReportMode = $patronApiReportMode;
		$request->Modifiers->StaffID = 'PIK'; // Pika Patron Loader
		$request->Note = new stdClass();
		$request->Note->PatronID = $patron['Patron ID']; // Patron ID
		$request->Note->NoteType = '800';
		$PatronExpirationDate = date('Y-m-d', strtotime('yesterday')); // Patron Expiration Date
		$request->Note->NoteText = 'MNPS 12th grade patron expired ' . $PatronExpirationDate .
			'. Previous branchcode: ' . $patron['Default Branch'] .
			'. Previous bty: ' . $patron['Borrower type code'] .
			'. This account may be converted to NPL after staff update patron barcode, patron type, email, phone, address, branch, and guarantor.';
		$result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);

		$updateCount++;
		if ($updateCount % 100 == 0) {
			echo "Updated $updateCount records...\n";
		}
	}

	echo "Successfully updated $updateCount records to XMNPS.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | Successfully updated $updateCount records to XMNPS\n", FILE_APPEND);
} elseif (count($updateRecords) > 0 && $isTestRun) {
	echo "TEST MODE: Would have updated " . count($updateRecords) . " records to XMNPS.\n";
	file_put_contents($logFile, date('Y-m-d H:i:s') . " | TEST MODE: Would have updated " . count($updateRecords) . " records to XMNPS\n", FILE_APPEND);
}

//////////////////// CREATE MANUAL REVIEW LOG ////////////////////

if (count($manualReview) > 0) {
	$manualReviewFile = $reportPath . 'ic2carlx_mnps_12thgrade_manual_review_' . date('Y-m-d') . '.csv';
	$fhnd = fopen($manualReviewFile, "w");
	if ($fhnd) {
		fputcsv($fhnd, array('Patron ID', 'Borrower Type Code', 'Name'));
		foreach ($manualReview as $record) {
			fputcsv($fhnd, array($record['patronId'], $record['bty'], $record['name']));
		}
		fclose($fhnd);

		echo "Created manual review file: " . basename($manualReviewFile) . " with " . count($manualReview) . " records.\n";
		file_put_contents($logFile, date('Y-m-d H:i:s') . " | Created manual review file: " . basename($manualReviewFile) . " with " . count($manualReview) . " records\n", FILE_APPEND);
	} else {
		echo "Error: Could not create manual review file.\n";
		file_put_contents($logFile, date('Y-m-d H:i:s') . " | Error: Could not create manual review file\n", FILE_APPEND);
	}
}

//////////////////// COMPLETION ////////////////////

$endTime = microtime(true);
$elapsedTime = $endTime - $startTime;
echo "Process completed in " . round($elapsedTime, 2) . " seconds.\n";
file_put_contents($logFile, date('Y-m-d H:i:s') . " | Process completed in " . round($elapsedTime, 2) . " seconds\n", FILE_APPEND);

// Print verification command
echo "\nYou can check these numbers with a CarlX record BTY count of all 12th grade patronids from the Infinite Campus extract using a script like:\n";
echo "awk -F'|' '\$2 == 34 {print \$1}' " . escapeshellarg($inputFile) . " | sort -u > grade12.tmp && awk -F, 'NR>1 {gsub(/\"/, \"\", \$1); gsub(/\"/, \"\", \$2); if (system(\"grep -q \\\"^\"\\$1\"\\$\\\" grade12.tmp\") == 0) bty[\$2]++} END {total=0; for (b in bty) {print \"BTY \" b \": \" bty[b] \" records\"; total += bty[b]}; print \"TOTAL: \" total \" records\"}' " . escapeshellarg($reportPath . 'ic2carlx_mnps_12thgrade_carlx_data.csv') . " && rm grade12.tmp\n";
?>