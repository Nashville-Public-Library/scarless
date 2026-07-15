<?php

// Gather collection count CarlX report for MNPS branches
// Usage: php NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.php [YYYY-MM-DD]

class nashvilleMNPSCollectionCountCarlXReport {
    private $reportPath;
    private $carlx_db_php;
    private $carlx_db_php_user;
    private $carlx_db_php_password;
    private $emailRecipients;
    public $reportDate;

    function getConfig() {
        date_default_timezone_set('America/Chicago');
        $this->reportPath = '../data/';
        
        if (!file_exists('../config.pwd.ini')) {
            throw new Exception("Config file not found at ../config.pwd.ini");
        }
        $configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
        
        $this->carlx_db_php = $configArray['Catalog']['carlx_db_php'];
        $this->carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
        $this->carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
        
        // Try to get email recipients from standard location
        $this->emailRecipients = $configArray['Email Recipients']['NashvilleMNPS'] ?? null;
    }

    function getCollectionCountCarlXData($reportDate) {
        $sql = <<<EOT
-- MNPS SCHOOL LIBRARY COLLECTION COUNT CARLX
with b as (
    select
        *
    from branch_v2 b
    where b.branchgroup = 2
      and b.branchname != 'Bailey building - MNPS'
      and b.branchname != 'Bransford building - MNPS'
      and b.branchname != 'Former MNPS'
      and b.branchname != 'Limitless Bookmobile Annex'
      and b.branchname != 'Limitless Libraries'
      and b.branchname != 'Limitless Libraries Bookmobile'
      and b.branchname != 'Mark as error'
      and b.branchname != 'Martin Professional Development Center'
      and b.branchname != 'NPL delivery only'
      and b.branchname not like 'Robertson Academy%'
      and b.branchname not like 'DO NOT USE%'
      and b.branchname not like 'DNU%'
      and b.branchname not like 'was %'
)
   , i as (
    select
        i.*
    from item_v2 i
             right join b on i.owningbranch = b.branchnumber
    where b.branchnumber is not null
      and i.status in ('C','H','I','IH','S','SC','SI','SP','SS','SU')
)
   , ic as (
    select
        i.owningbranch
         , count(i.item) as count
    from i
    group by i.owningbranch
)
   , bc as (
    select
        substr(b.branchcode,3) as schoolCode
         , b.branchname as school
         , b.branchnumber
         , nvl(ic.count,0) as collectionCountCarlX
    from b
             right join ic on b.branchnumber = ic.owningbranch
    order by 1
)
select
    bc.schoolCode
     , bc.collectionCountCarlX
     , '$reportDate' as collectionCountCarlXDate
from bc
EOT;

        // connect to carlx oracle db
        $conn = oci_connect($this->carlx_db_php_user, $this->carlx_db_php_password, $this->carlx_db_php, 'AL32UTF8');
        if (!$conn) {
            $e = oci_error();
            throw new Exception("Carl.X Connection failed: " . $e['message']);
        }
        $stid = oci_parse($conn, $sql);
        oci_set_prefetch($stid, 10000);
        if (!@oci_execute($stid)) {
            $e = oci_error($stid);
            throw new Exception("SQL Execution failed: " . $e['message']);
        }
        $data = array();
        while (($row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS)) != false) {
            $data[] = $row;
        }
        oci_free_statement($stid);
        oci_close($conn);
        return $data;
    }

    function writeData($rows, $reportDate) {
        if (empty($rows)) {
            throw new Exception("No data rows returned from query for date $reportDate.");
        }

        $filename = $this->reportPath . 'LibraryServices-CollectionCountCarlX-MNPS-' . $reportDate . '.txt';
        $fp = fopen($filename, 'w');
        if (!$fp) {
            throw new Exception("Could not open file for writing: $filename");
        }
        $header = array('schoolCode', 'collectionCountCarlX', 'collectionCountCarlXDate');
        fputcsv($fp, $header, "\t");
        $rowCount = 0;
        foreach ($rows as $row) {
            fputcsv($fp, $row, "\t");
            $rowCount++;
        }
        fclose($fp);

        if (!file_exists($filename) || filesize($filename) == 0) {
            throw new Exception("Output file was not written or is empty: $filename");
        }

        echo "Report for $reportDate saved to $filename ($rowCount records).\n";
        return $filename;
    }

    private function sendCompletionEmail($rows, $filename) {
        $schoolCount = count($rows);
        $totalCollectionCountCarlX = array_sum(array_column($rows, 'collectionCountCarlX'));

        $pickupPath = "/home/mnps.org/data/" . basename($filename);
        $subject = "MNPS Collection Count CarlX Report: " . $this->reportDate;

        $body = "The MNPS Collection Count CarlX Report has completed successfully.\n\n";
        $body .= "Pickup Path: $pickupPath\n\n";
        $body .= "Summary:\n";
        $body .= "Count of Schools: $schoolCount\n";
        $body .= "Total Collection Count CarlX: " . number_format($totalCollectionCountCarlX) . "\n\n";
        $body .= "Full Report:\n";
        $body .= "schoolCode\tcollectionCountCarlX\tcollectionCountCarlXDate\n";

        foreach ($rows as $row) {
            $body .= implode("\t", $row) . "\n";
        }

        $this->sendEmail($subject, $body);
    }

    private function sendEmail($subject, $body) {
        if (empty($this->emailRecipients)) {
            echo "No email recipients found in config. Skipping email.\n";
            return;
        }

        $to = $this->emailRecipients;
        $headers = "From: noreply-connected@nashville.gov\r\n";

        // Verify if the mail server is actually responding
        $host = ini_get('SMTP') ?: 'localhost';
        $port = (int)(ini_get('smtp_port') ?: 25);
        $mailerActive = false;
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if (is_resource($connection)) {
            $mailerActive = true;
            fclose($connection);
        }

        if (mail($to, $subject, $body, $headers)) {
            if ($mailerActive) {
                echo "Email sent successfully to: $to\n";
            } else {
                echo "Warning: Email accepted by local mailer, but the SMTP server at $host:$port is not responding. The mail is likely queued and will be sent once the mail server (Postfix) is back online. Recipient: $to\n";
            }
        } else {
            echo "Failed to send email to: $to. The local mailer rejected the message.\n";
        }
    }

    function setReportDate($date = null) {
        if (isset($date)) {
            $this->reportDate = date('Y-m-d', strtotime($date));
        } else {
            // default to yesterday
            $this->reportDate = date('Y-m-d', strtotime('yesterday'));
        }
    }

    public function run($date = null) {
        try {
            ini_set('memory_limit', '256M');
            $this->setReportDate($date);
            $this->getConfig();
            echo "Starting MNPS Collection Count CarlX Report for date: {$this->reportDate}\n";
            $collectionCountCarlXRows = $this->getCollectionCountCarlXData($this->reportDate);
            $filename = $this->writeData($collectionCountCarlXRows, $this->reportDate);
            $this->sendCompletionEmail($collectionCountCarlXRows, $filename);
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            echo "ERROR: $errorMsg\n";
            $this->sendEmail("ERROR: MNPS Collection Count CarlX Report", "An error occurred during the MNPS Collection Count CarlX Report process for date " . ($this->reportDate ?? 'unknown') . ":\n\n$errorMsg");
        }
    }
}

$report = new nashvilleMNPSCollectionCountCarlXReport();
$report->run(!empty($argv[1]) ? $argv[1] : null);
?>
