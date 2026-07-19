<?php
/**
 * NashvilleMNPSDataWarehouseReport-OverDrive.php
 * 
 * Purpose:
 * This script retrieves the OverDrive Unique Users User Detail report for a specific date.
 * It logs into OverDrive Marketplace, triggers an export, and saves the CSV.
 * 
 * Usage:
 * php NashvilleMNPSDataWarehouseReport-OverDrive.php [date]
 * 
 * Arguments:
 *   [date]          Optional. The date for which to process reports in YYYY-MM-DD format.
 *                   Defaults to yesterday.
 */

class OverDriveReportDownloader {
    private $od_username;
    private $od_password;
    private $reportPath = '../data/overdrive/';
    private $verbose = false;

    public function __construct($verbose = false) {
        $this->verbose = $verbose;
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
    }

    public function downloadReport($date) {
        $date_safe = str_replace('-', '', $date);
        $odFile = $this->reportPath . "OverDrive_Report_$date_safe.csv";

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
        }

        // 1. Get Login Page
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        $loginPage = curl_exec($ch);
        
        $token = '';
        if (preg_match('/name="__RequestVerificationToken" type="hidden" value="([^"]+)"/', $loginPage, $matches)) {
            $token = $matches[1];
        }

        // 2. Perform Login
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
                 throw new Exception("OverDrive Login failed. Check credentials in config.pwd.ini");
             }
        }
        echo "Login successful.\n";

        // 3. Trigger Export
        echo "Exporting Unique Users User Detail report for $date...\n";
        $exportApiUrl = $baseUrl . '/api/insights/UniqueUsersUserDetail/Export';
        
        curl_setopt($ch, CURLOPT_URL, $exportApiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['inputJson' => json_encode($inputJson)]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Requested-With: XMLHttpRequest',
            'Accept: */*'
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'ODReport');
        $fp = fopen($tempFile, 'w+');
        curl_setopt($ch, CURLOPT_FILE, $fp);

        curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        fclose($fp);

        if (strpos($contentType, 'text/csv') !== false || 
            strpos($contentType, 'application/vnd.ms-excel') !== false || 
            strpos($contentType, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false) {
            
            copy($tempFile, $odFile);
            echo "OverDrive report saved to: $odFile\n";
            
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

$date = isset($argv[1]) ? $argv[1] : date('Y-m-d', strtotime('yesterday'));
$verbose = in_array('-verbose', $argv);

try {
    $downloader = new OverDriveReportDownloader($verbose);
    $downloader->getConfig();
    $downloader->downloadReport($date);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
