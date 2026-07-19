<?php
/**
 * NashvilleMNPSDataWarehouseReport-OverDrive.php
 * 
 * Purpose:
 * This script retrieves the OverDrive Unique Users User Detail report for a specific date.
 * It logs into OverDrive Marketplace, triggers an export, and saves the CSV.
 * 
 * Usage:
 * php NashvilleMNPSDataWarehouseReport-OverDrive.php [date] [options]
 * 
 * Arguments:
 *   [date]          Optional. The date for which to process reports in YYYY-MM-DD format.
 *                   Defaults to yesterday.
 * 
 *   -localfile      Optional. Skip live data retrieval and use previously downloaded CSV files 
 *                   from the ../data/overdrive/ directory.
 * 
 *   -no-email       Optional. Suppress the automated email notification.
 * 
 *   -verbose        Optional. Enable detailed diagnostic logging.
 * 
 * Credits:
 * Most of the programming and automation logic was developed by Junie, an autonomous 
 * AI programmer by JetBrains, following requirements provided by James Staub (Nashville Public Library).
 */

class OverDriveReportDownloader {
    private $od_username;
    private $od_password;
    private $reportPath = '../data/overdrive/';
    private $verbose = false;
    private $useLocal = false;
    private $sendEmail = true;
    private $emailRecipients;

    public function __construct($verbose = false, $useLocal = false, $sendEmail = true) {
        $this->verbose = $verbose;
        $this->useLocal = $useLocal;
        $this->sendEmail = $sendEmail;
        if (!is_dir($this->reportPath)) {
            mkdir($this->reportPath, 0777, true);
        }
    }

    public function getConfig() {
        if (!file_exists('../config.pwd.ini')) {
            throw new Exception("Config file not found at ../config.pwd.ini");
        }
        $configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
        
        if (isset($configArray['OverDrive'])) {
            $this->od_username = $configArray['OverDrive']['MarketplaceUserName'] ?? ($configArray['OverDrive']['UserName'] ?? null);
            $this->od_password = $configArray['OverDrive']['MarketplacePassword'] ?? ($configArray['OverDrive']['Password'] ?? null);
        } else {
            throw new Exception("[OverDrive] section missing in config.pwd.ini");
        }

        $this->emailRecipients = $configArray['Email Recipients']['NashvilleMNPS'] ?? null;
    }

    private function sendEmail($subject, $body) {
        if (!$this->sendEmail || empty($this->emailRecipients)) {
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
                echo "Warning: Email accepted by local mailer, but the SMTP server at $host:$port is not responding. Recipient: $to\n";
            }
        } else {
            echo "Failed to send email to: $to. The local mailer rejected the message.\n";
        }
    }

    public function downloadReport($date) {
        $date_safe = str_replace('-', '', $date);
        $odFile = $this->reportPath . "OverDrive_Report_$date_safe.csv";

        if ($this->useLocal && file_exists($odFile)) {
            echo "Using local file: $odFile\n";
            return $odFile;
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'ODCookie');
        $baseUrl = 'https://marketplace.overdrive.com';
        $loginUrl = $baseUrl . '/Account/Login';
        
        // Prepare data for the report
        $dt = new DateTime($date);
        $keyValue = $dt->format('n/j/Y');

        $inputJson = [
            "ChartBy" => "Day",
            "DateRange" => [
                "DateRangePeriodType" => "specific",
                "DateUnitsValue" => 1,
                "DateRangeDateUnit" => "month",
                "StartDateInputValue" => $date,
                "EndDateInputValue" => $date,
                "MinStartDate" => "1753-01-01",
                "UseMinWhenStartDateIsNull" => false
            ],
            "Branch" => [],
            "UserBarcode" => "",
            "KeyValue" => $keyValue,
            "BranchCode" => $date,
            "Parameters" => [
                "page" => 1,
                "start" => 0,
                "limit" => 50,
                "sort" => []
            ]
        ];

        echo "Logging in to OverDrive Marketplace...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        if ($this->verbose) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            echo "Cookie file: $cookieFile\n";
        }

        // 1. Get Login Page
        if ($this->verbose) echo "Step 1: Getting Login Page from $loginUrl\n";
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        $loginPage = curl_exec($ch);
        
        $token = '';
        if (preg_match('/name="__RequestVerificationToken" type="hidden" value="([^"]+)"/', $loginPage, $matches)) {
            $token = $matches[1];
            if ($this->verbose) echo "Found RequestVerificationToken: $token\n";
        }

        // 2. Perform Login
        if ($this->verbose) echo "Step 2: Performing Login to $loginUrl\n";
        $postFields = [
            'UserName' => $this->od_username,
            'Password' => $this->od_password,
            'RememberMe' => 'false'
        ];
        if ($token) {
            $postFields['__RequestVerificationToken'] = $token;
        }

        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $response = curl_exec($ch);

        if (strpos($response, 'Sign out') === false && strpos($response, 'Log out') === false) {
             if (strpos($response, 'name="UserName"') !== false) {
                 if ($this->verbose) {
                     echo "Login failed. Response contains UserName input field again.\n";
                     echo "Response Preview: " . substr(strip_tags($response), 0, 500) . "...\n";
                 }
                 throw new Exception("OverDrive Login failed. Check credentials in config.pwd.ini");
             }
        }
        echo "Login successful.\n";

        // 3. Trigger Export
        echo "Exporting Unique Users User Detail report for $date...\n";
        $exportApiUrl = $baseUrl . '/api/insights/UniqueUsersUserDetail/Export';
        
        if ($this->verbose) {
            echo "Step 3: Triggering Export via API: $exportApiUrl\n";
            echo "Input JSON: " . json_encode($inputJson, JSON_PRETTY_PRINT) . "\n";
        }
        
        curl_setopt($ch, CURLOPT_URL, $exportApiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['inputJson' => json_encode($inputJson)]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Requested-With: XMLHttpRequest',
            'Accept: */*'
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'ODReport');
        if ($this->verbose) echo "Temporary storage: $tempFile\n";
        $fp = fopen($tempFile, 'w+');
        curl_setopt($ch, CURLOPT_FILE, $fp);

        curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);

        if ($this->verbose) echo "HTTP Response Code: $httpCode, Content-Type: $contentType\n";

        if (strpos($contentType, 'text/csv') !== false || 
            strpos($contentType, 'application/vnd.ms-excel') !== false || 
            strpos($contentType, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false) {
            
            copy($tempFile, $odFile);
            echo "OverDrive report saved to: $odFile\n";
            
            if ($this->sendEmail) {
                $subject = "OverDrive Report Download Successful - $date";
                $body = "The OverDrive Unique Users User Detail report for $date has been successfully downloaded and saved to:\n$odFile\n";
                $this->sendEmail($subject, $body);
            }
            
            curl_close($ch);
            @unlink($cookieFile);
            @unlink($tempFile);
            return $odFile;
        } else {
            $html = file_get_contents($tempFile);
            curl_close($ch);
            @unlink($cookieFile);
            @unlink($tempFile);
            if ($this->verbose) echo "Response Preview: " . substr(strip_tags($html), 0, 500) . "...\n";
            throw new Exception("Failed to download report. Received content type: $contentType");
        }
    }
}

$date = null;
$verbose = false;
$useLocal = false;
$sendEmail = true;

foreach ($argv as $i => $arg) {
    if ($i == 0) continue;
    if ($arg === '-verbose') {
        $verbose = true;
    } elseif ($arg === '-localfile') {
        $useLocal = true;
    } elseif ($arg === '-no-email') {
        $sendEmail = false;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $arg)) {
        $date = $arg;
    }
}

if (!$date) {
    $date = date('Y-m-d', strtotime('yesterday'));
}

if ($verbose) {
    echo "Running in verbose mode.\n";
    echo "Date: $date\n";
    echo "Local file: " . ($useLocal ? "Yes" : "No") . "\n";
    echo "Send email: " . ($sendEmail ? "Yes" : "No") . "\n";
}

try {
    $downloader = new OverDriveReportDownloader($verbose, $useLocal, $sendEmail);
    $downloader->getConfig();
    $downloader->downloadReport($date);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
