<?php

// Change case of patron data (names, street address) to Title Case
// Optimized version that processes each field separately
// Includes field-specific last run time tracking to only process records modified since last run
// echo 'SYNTAX: $ php NashvilleCarlXUpdatePatronsCase2.php\n';
//
// Last run times are stored in a JSON file at $reportPath/NashvilleCarlXUpdatePatronsCase2-lastRun.txt
// Each field (zip1, state1, city1, street1, firstname, etc.) has its own last run timestamp

date_default_timezone_set('America/Chicago');
$startTime = microtime(true);

require_once 'ic2carlx_put_carlx.php';
require_once 'Formatter.php';
use Tamtamchik\NameCase\Formatter;

$configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php = $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
$patronApiWsdl = $configArray['Catalog']['patronApiWsdl'];
$reportPath = '../data/';
$lastRunFile = $reportPath . 'NashvilleCarlXUpdatePatronsCase2-lastRun.txt';

/**
 * Gets the last run time for a specific field
 * 
 * @param string $fieldName The name of the field
 * @return string The last run time in 'YYYY-MM-DD HH24:MI:SS' format
 */
function getLastRunTimeForField($fieldName) {
    global $lastRunFile;
    
    $lastRunTimes = [];
    
    // Check if the last run file exists
    if (file_exists($lastRunFile)) {
        try {
            // Read the file contents
            $fileContents = @file_get_contents($lastRunFile);
            if ($fileContents === false) {
                error_log("Warning: Could not read last run file: $lastRunFile");
                return '2000-01-01 00:00:00';
            }
            
            // Parse the JSON data
            $lastRunTimes = json_decode($fileContents, true);
            if ($lastRunTimes === null) {
                // JSON parsing failed, initialize empty array
                error_log("Warning: Invalid JSON in last run file: $lastRunFile");
                $lastRunTimes = [];
            }
        } catch (Exception $e) {
            error_log("Error reading last run file: " . $e->getMessage());
            return '2000-01-01 00:00:00';
        }
    } else {
        // File doesn't exist, log this information
        error_log("Info: Last run file does not exist yet: $lastRunFile");
    }
    
    // Return the last run time for the field if it exists, otherwise return a date far in the past
    return isset($lastRunTimes[$fieldName]) ? $lastRunTimes[$fieldName] : '2000-01-01 00:00:00';
}

/**
 * Saves the last run time for a specific field
 * 
 * @param string $fieldName The name of the field
 * @return bool True if the save was successful, false otherwise
 */
function saveLastRunTimeForField($fieldName) {
    global $lastRunFile;
    
    $lastRunTimes = [];
    $currentTime = date('Y-m-d H:i:s');
    
    try {
        // Check if the last run file exists
        if (file_exists($lastRunFile)) {
            // Read the file contents
            $fileContents = @file_get_contents($lastRunFile);
            if ($fileContents === false) {
                error_log("Warning: Could not read last run file for updating: $lastRunFile");
            } else {
                // Parse the JSON data
                $lastRunTimes = json_decode($fileContents, true);
                if ($lastRunTimes === null) {
                    // JSON parsing failed, initialize empty array
                    error_log("Warning: Invalid JSON in last run file when updating: $lastRunFile");
                    $lastRunTimes = [];
                }
            }
        } else {
            // File doesn't exist, we'll create it
            error_log("Info: Creating new last run file: $lastRunFile");
            
            // Make sure the directory exists
            $directory = dirname($lastRunFile);
            if (!is_dir($directory)) {
                if (!@mkdir($directory, 0755, true)) {
                    error_log("Error: Failed to create directory for last run file: $directory");
                    return false;
                }
            }
        }
        
        // Update the last run time for the field
        $lastRunTimes[$fieldName] = $currentTime;
        
        // Save the updated last run times to the file
        $jsonData = json_encode($lastRunTimes, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            error_log("Error: Failed to encode last run times to JSON");
            return false;
        }
        
        $result = @file_put_contents($lastRunFile, $jsonData, LOCK_EX);
        if ($result === false) {
            error_log("Error: Failed to write to last run file: $lastRunFile");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error saving last run time: " . $e->getMessage());
        return false;
    }
}

/**
 * Builds and optionally executes a SQL query for patron field standardization
 * 
 * @param string $fieldName The name of the field to check (e.g., 'zip1', 'state1')
 * @param string $regexCondition The regex condition to apply to the field
 * @param bool $executeQuery Whether to execute the query and return results (default: false)
 * @param array $selectFields Additional fields to select beyond patronid and target field (default: empty)
 * @return mixed If $executeQuery is true, returns query results; otherwise returns the SQL string
 */
function buildPatronFieldQuery($fieldName, $regexCondition, $executeQuery = false, $selectFields = []) {
    global $conn;
    
    // Get the last run time for this field
    $lastRunTime = getLastRunTimeForField($fieldName);
    
    // Start with the basic SELECT clause
    $selectClause = "    select\n        patronid\n        , $fieldName";
    
    // Add any additional fields requested
    foreach ($selectFields as $field) {
        if ($field !== 'patronid' && $field !== $fieldName) {
            $selectClause .= "\n        , $field";
        }
    }
    
    // Build the complete SQL query with editdate condition
    $sql = <<<EOT
$selectClause
    from patron_v2
    where bty not in (9,19) -- exclude ILL, NPL Branch
    and bty not in (13,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,40,46,47,51) -- exclude MNPS
    and patronid not like 'B%' -- exclude Belmont University patrons
    and $regexCondition
    and editdate > TO_DATE('$lastRunTime', 'YYYY-MM-DD HH24:MI:SS')
    order by patronid
EOT;

    // If execution is requested, run the query and return results
    if ($executeQuery) {
        $stid = oci_parse($conn, $sql);
        oci_set_prefetch($stid, 10000);
        oci_execute($stid);
        
        $results = [];
        while (($row = oci_fetch_array($stid, OCI_ASSOC+OCI_RETURN_NULLS)) != false) {
            $results[] = $row;
        }
        
        oci_free_statement($stid);
        return $results;
    }
    
    // Otherwise just return the SQL string
    return $sql;
}

/**
 * Standardizes ZIP codes
 * 
 * @param string $value The ZIP code to standardize
 * @return string The standardized ZIP code
 */
function standardizeZip($value) {
    // Remove ZIP+4 extension if present
    return preg_replace('/-([0-9]{4})$/', '', $value);
}

/**
 * Standardizes state codes
 * 
 * @param string $value The state code to standardize
 * @return string The standardized state code
 */
function standardizeState($value) {
    $state = strtoupper($value);
    // Remove periods if present
    return str_replace('.', '', $state);
}

/**
 * Standardizes city names
 * 
 * @param string $value The city name to standardize
 * @return string The standardized city name
 */
function standardizeCity($value) {
    $city = Formatter::nameCase($value);
    
    // Remove periods
    if (strpos($city, '.')) {
        $city = str_replace('.', '', $city);
    }
    
    // Remove apostrophes
    if (strpos($city, "'")) {
        $city = str_replace("'", "", $city);
    }
    
    // Handle special cases
    // Fort Campbell, etc.
    if (preg_match('/^FT\.?\b/i', $city)) {
        $city = preg_replace('/^FT\.?\b/i', 'Fort', $city);
    }
    
    // La Vergne, etc.
    if (preg_match('/^LA\b/i', $city)) {
        $city = preg_replace('/^LA\b/i', 'La', $city);
    }
    
    // Mount Juliet, Mount Pleasant, etc.
    if (preg_match('/^MT\.?\b/i', $city)) {
        $city = preg_replace('/^MT\.?\b/i', 'Mount', $city);
    }
    
    return $city;
}

/**
 * Standardizes street addresses
 * 
 * @param string $value The street address to standardize
 * @return string The standardized street address
 */
function standardizeStreet($value) {
    $street = Formatter::nameCase($value, ['spanish' => false, 'postnominal' => false]);
    
    // Eliminate multiple spaces
    while (strpos($street, '  ') !== false) {
        $street = str_replace('  ', ' ', $street);
    }
    
    // Eliminate periods
    if (strpos($street, '.')) {
        $street = str_replace('.', '', $street);
    }
    
    // Eliminate apostrophes
    if (strpos($street, "'")) {
        $street = str_replace("'", "", $street);
    }
    
    // Standardize common street suffixes
    $replacements = [
        ' CT' => ' Ct',
        ' LN' => ' Ln',
        ' MT' => ' Mount',
        ' PK' => ' Pk',
        ' PL' => ' Pl',
        ' RD' => ' Rd',
        ' SQ' => ' Sq'
    ];
    
    foreach ($replacements as $search => $replace) {
        if (strpos($street, $search)) {
            $street = str_replace($search, $replace, $street);
        }
    }
    
    return $street;
}

/**
 * Standardizes name fields
 * 
 * @param string $value The name to standardize
 * @param string $fieldType The type of name field (firstname, middlename, lastname, suffixname)
 * @return string The standardized name
 */
function standardizeName($value, $fieldType) {
	if ($fieldType === 'lastname') {
		// Fast regex: match up to two leading #s and optional whitespace
		if (preg_match('/^(#{1,2})#*\s*(.*)$/', $value, $matches)) {
			// $matches[1] = octothorpes, $matches[2] = rest of name
			return $matches[1] . Formatter::nameCase($matches[2]);
		}
	}
	return Formatter::nameCase($value);
}

/**
 * Process a specific patron field for standardization
 * 
 * @param string $fieldName The name of the field to standardize
 * @param string $regexCondition The regex condition to identify records needing standardization
 * @param callable $standardizeFunction Function to standardize the field value
 * @param array $additionalFields Additional fields to select (optional)
 * @param array $functionParams Additional parameters for the standardize function (optional)
 * @return int Number of records processed
 */
function processPatronField($fieldName, $regexCondition, $standardizeFunction, $additionalFields = [], $functionParams = []) {
    global $conn, $patronApiWsdl, $client;
    
    // Get the last run time for this field for debugging
    $lastRunTime = getLastRunTimeForField($fieldName);
    
    echo "\nProcessing field: $fieldName\n";
    echo "Last run time: $lastRunTime\n";
    echo "----------------------------------------\n";
    
    // Get records needing standardization
    $records = buildPatronFieldQuery($fieldName, $regexCondition, true, $additionalFields);
    
    $recordCount = count($records);
    echo "Found $recordCount records to process\n";
    
    $count = 0;
    $updateCount = 0;
    
    foreach ($records as $record) {
        $count++;
        
        // Create patron update request
        $requestName = 'updatePatron';
        $tag = $record['PATRONID'] . ' : ' . $requestName;
        $request = new stdClass();
        $request->Modifiers = new stdClass();
        $request->Modifiers->DebugMode = false;
        $request->Modifiers->ReportMode = false;
        $request->SearchType = 'Patron ID';
        $request->SearchID = $record['PATRONID'];
        $request->Patron = new stdClass();
        
        // Apply the standardization function to get the new value
        $originalValue = $record[strtoupper($fieldName)];
        $standardizedValue = $functionParams ? 
            call_user_func_array($standardizeFunction, array_merge([$originalValue], $functionParams)) : 
            call_user_func($standardizeFunction, $originalValue);
        
        // Set up the appropriate field in the request
        if (in_array($fieldName, ['zip1', 'state1', 'city1', 'street1'])) {
            $request->Patron->Addresses = new stdClass();
            $request->Patron->Addresses->Address = new stdClass();
            $request->Patron->Addresses->Address->Type = 'Primary';
            
            if ($fieldName == 'zip1') {
                $request->Patron->Addresses->Address->PostalCode = $standardizedValue;
            } else if ($fieldName == 'state1') {
                $request->Patron->Addresses->Address->State = $standardizedValue;
            } else if ($fieldName == 'city1') {
                $request->Patron->Addresses->Address->City = $standardizedValue;
            } else if ($fieldName == 'street1') {
                $request->Patron->Addresses->Address->Street = $standardizedValue;
            }
        } else if (in_array($fieldName, ['firstname', 'middlename', 'lastname', 'suffixname'])) {
            // Handle name fields
            if ($fieldName == 'firstname') {
                $request->Patron->FirstName = $standardizedValue;
            } else if ($fieldName == 'middlename') {
                $request->Patron->MiddleName = $standardizedValue;
            } else if ($fieldName == 'lastname') {
                $request->Patron->LastName = $standardizedValue;
            } else if ($fieldName == 'suffixname') {
                $request->Patron->SuffixName = $standardizedValue;
            }
        }
        
        // Only update if the value has changed
        if ($standardizedValue != $originalValue) {
            $result = callAPI($patronApiWsdl, $requestName, $request, $tag, $client);
            $updateCount++;
            echo 'COUNT: ' . $count . "\n";
            echo 'Patron ID: ' . $request->SearchID . "\n";
            echo "Field $fieldName: $originalValue -> $standardizedValue\n";
        } else {
            echo 'COUNT: ' . $count . "\n";
            echo 'Patron ID: ' . $request->SearchID . "\n";
            echo "NO CHANGE\n";
        }
    }
    
    echo "\nTotal records processed for $fieldName: $count\n";
    echo "Records updated: $updateCount\n";
    
    // Save the last run time for this field
    if (saveLastRunTimeForField($fieldName)) {
        $lastRunTime = date('Y-m-d H:i:s');
        echo "Last run time saved for $fieldName: $lastRunTime\n";
    } else {
        echo "Warning: Failed to save last run time for $fieldName\n";
    }
    
    echo "----------------------------------------\n";
    
    return $count;
}

// Main execution
// The script processes each field separately and tracks the last run time for each field
// Only records that have been modified since the last run time for a specific field will be processed
// This significantly improves performance for nightly automated runs

// Connect to carlx oracle db
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php);
if (!$conn) {
    $e = oci_error();
    trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
}

// Initialize SOAP client
$client = new SOAPClient($patronApiWsdl, array('connection_timeout' => 1, 'features' => SOAP_WAIT_ONE_WAY_CALLS, 'trace' => 1));

echo "Starting patron record standardization...\n";

// Process ZIP codes
processPatronField('zip1', "not regexp_like (zip1, '^[0-9]{5}$')", 'standardizeZip');

// Process state codes
processPatronField('state1', "not regexp_like (state1, '^[A-Z]{2}$')", 'standardizeState');

// Process city names
processPatronField('city1', "not regexp_like (city1, '^([A-Z][a-z]+ )*(Ma?c)?[A-Z][a-z]*$')", 'standardizeCity');

// Process street addresses
processPatronField('street1', "not regexp_like (street1, '^([0-9]+[-A-Z]* )?([A-Z] )?([[0-9]+[DHNRSTdhnrst]{2} )?([A-Z][a-z]+\.? ?)+((, )?((Apt|Lot|No|Unit) )?\#?[A-Z]*[- ]?[0-9]*)?$')", 'standardizeStreet');

// Process name fields
processPatronField('firstname', "not regexp_like (firstname, '^[A-Z][a-z]+$')", 'standardizeName', [], ['firstname']);
processPatronField('middlename', "not regexp_like (middlename, '(^[A-Z]$|^[A-Z][a-z]+$)')", 'standardizeName', [], ['middlename']);
processPatronField('lastname', "not regexp_like (lastname, '^[A-Z][a-z]+$')", 'standardizeName', [], ['lastname']);
processPatronField('suffixname', "not regexp_like (suffixname, '^[A-Z][a-z]+$')", 'standardizeName', [], ['suffixname']);

// Close the database connection
oci_close($conn);

// Calculate and display execution time
$endTime = microtime(true);
$executionTime = ($endTime - $startTime);
echo "\nExecution time: " . number_format($executionTime, 2) . " seconds\n";

?>