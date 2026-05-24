<?php
/**
 * ComicsPlus School Lookup via Carl.X
 * James Staub, Nashville Public Library with significant assitance from JetBrains Junie
 *
 * This script takes a list of patron IDs, queries Carl.X (Oracle) to find their registered branch,
 * and outputs a CSV mapping patronid to tn_school_code.
 */

if ($argc < 2) {
    echo "Usage: php ComicsPlusSchoolLookup.php <patronids_file>\n";
    exit(1);
}

$patronIdsFile = $argv[1];
if (!file_exists($patronIdsFile)) {
    echo "Error: File $patronIdsFile not found.\n";
    exit(1);
}

$patronIds = array();
$handle = fopen($patronIdsFile, "r");
while (($line = fgets($handle)) !== false) {
    $id = trim($line);
    if (!empty($id)) {
        $patronIds[] = $id;
    }
}
fclose($handle);

if (empty($patronIds)) {
    exit(0);
}

// Read configuration
$configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
$carlx_db_php = $configArray['Catalog']['carlx_db_php'];
$carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
$carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];

// Connect to Carl.X Oracle DB
$conn = oci_connect($carlx_db_php_user, $carlx_db_php_password, $carlx_db_php, 'AL32UTF8');
if (!$conn) {
    $e = oci_error();
    fwrite(STDERR, "Oracle Connection Error: " . $e['message'] . "\n");
    exit(1);
}

// Prepare the query. Since there might be many IDs, we might need to chunk it if there are thousands,
// but for a daily report, it should be manageable.
// Oracle IN clause has a limit of 1000 items. Let's chunk it.

$chunks = array_chunk($patronIds, 1000);
$results = array();

$verbose = false;
foreach ($argv as $arg) {
    if ($arg === '--verbose') {
        $verbose = true;
    }
}

foreach ($chunks as $chunk) {
    $idList = "'" . implode("','", array_map(function($id) { return str_replace("'", "''", $id); }, $chunk)) . "'";
    $sql = "select p.patronid, substr(b.branchcode, -3) as tn_school_code 
            from patron_v2 p 
            left join branch_v2 b on p.regbranch = b.branchnumber 
            where p.patronid in ($idList)
            and b.branchgroup = 2
            and b.branchcode != 'XMNPS'";
    
    if ($verbose) {
        fwrite(STDERR, "Executing Carl.X Query:\n$sql\n");
    }

    $stid = oci_parse($conn, $sql);
    oci_execute($stid);
    
    $rowCount = 0;
    while (($row = oci_fetch_array($stid, OCI_ASSOC)) != false) {
        $results[$row['PATRONID']] = $row['TN_SCHOOL_CODE'];
        $rowCount++;
    }

    if ($verbose) {
        fwrite(STDERR, "Query returned $rowCount rows.\n");
    }

    oci_free_statement($stid);
}

oci_close($conn);

// Output to stdout as CSV
echo "patronid,tn_school_code\n";
foreach ($results as $pid => $school) {
    echo "$pid,$school\n";
}
