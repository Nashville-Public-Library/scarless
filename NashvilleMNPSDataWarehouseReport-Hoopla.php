<?php
/**
 * NashvilleMNPSDataWarehouseReport-Hoopla.php
 * 
 * Purpose:
 * This script retrieves the Hoopla Overall Circulations report for a specific date.
 * It logs into Midwest Tape using their gateway authentication and saves the report.
 * 
 * Usage:
 * php NashvilleMNPSDataWarehouseReport-Hoopla.php [date] [options]
 * 
 * Arguments:
 *   [date]          Optional. The date for which to process reports in YYYY-MM-DD format.
 *                   Defaults to yesterday.
 * 
 *   -localfile      Optional. Skip live data retrieval and use previously downloaded CSV files.
 * 
 *   -verbose        Optional. Enable detailed diagnostic logging of curl actions.
 * 
 * Credits:
 * Developed by Junie, an autonomous AI programmer by JetBrains, following requirements 
 * provided by James Staub (Nashville Public Library).
 */

class HooplaReportDownloader {
    private $username;
    private $password;
    private $reportPath = '../data/hoopla/';
    private $verbose = false;
    private $useLocal = false;

    public function __construct($verbose = false, $useLocal = false) {
        $this->verbose = $verbose;
        $this->useLocal = $useLocal;
        if (!is_dir($this->reportPath)) {
            mkdir($this->reportPath, 0777, true);
        }
    }

    private function cleanIniValue($value) {
        if (is_string($value)) {
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value)-1] === '"') {
                $value = substr($value, 1, -1);
            }
        }
        return (string)$value;
    }

    public function getConfig() {
        if (!file_exists('../config.pwd.ini')) {
            throw new Exception("Config file not found at ../config.pwd.ini");
        }
        // Use INI_SCANNER_RAW to prevent mangling of special characters in passwords (like booleans or numbers)
        $configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);
        
        if (isset($configArray['Hoopla'])) {
            $this->username = $this->cleanIniValue($configArray['Hoopla']['MidwestTapeUserName'] ?? '');
            $this->password = $this->cleanIniValue($configArray['Hoopla']['MidwestTapePassword'] ?? '');
        } else {
            throw new Exception("[Hoopla] section missing in config.pwd.ini");
        }

        if (empty($this->username) || empty($this->password)) {
            throw new Exception("MidwestTapeUserName or MidwestTapePassword missing in [Hoopla] section of config.pwd.ini");
        }
    }

    public function downloadReport($date) {
        $date_safe = str_replace('-', '', $date);
        $hooplaFile = $this->reportPath . "Hoopla_Report_$date_safe.csv";

        if ($this->useLocal && file_exists($hooplaFile)) {
            echo "Using local file: $hooplaFile\n";
            return $hooplaFile;
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'HooplaCookie');
        $baseUrl = 'https://www.midwesttape.com';
        $gatewayUrl = 'https://mwt-gateway.midwesttape.com/auth/v1/token';
        $reportUrl = $baseUrl . '/hoopla/overall-circulations-report';

        echo "Logging in to Midwest Tape Gateway...\n";
        
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

        // 1. Get initial session cookies from the main site
        if ($this->verbose) echo "Step 1: Getting session cookies from $baseUrl/login\n";
        curl_setopt($ch, CURLOPT_URL, $baseUrl . '/login');
        curl_exec($ch);

        // 2. Perform Login via Gateway
        if ($this->verbose) echo "Step 2: Performing Login to $gatewayUrl\n";
        $loginData = [
            'username' => trim($this->username),
            'password' => $this->password
        ];

        if ($this->verbose) {
            echo "DEBUG: Username: '" . $loginData['username'] . "', Password Length: " . strlen($loginData['password']) . "\n";
        }

        curl_setopt($ch, CURLOPT_URL, $gatewayUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        // Use JSON_UNESCAPED_SLASHES and JSON_UNESCAPED_UNICODE to keep special characters literal
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            if ($this->verbose) {
                echo "Login failed. HTTP Code: $httpCode\n";
                echo "Response: $response\n";
            }
            throw new Exception("Hoopla Login failed. Check credentials in config.pwd.ini");
        }

        $responseData = json_decode($response, true);
        $token = $responseData['token'] ?? null;

        if (!$token) {
            throw new Exception("Login successful but no token received in response.");
        }

        if ($this->verbose) echo "Login successful. Received JWT token.\n";

        // 3. Set the auth token cookie for the main domain
        // This simulates Cookies.set("mwt-client-auth-token", token, ...)
        curl_setopt($ch, CURLOPT_COOKIE, "mwt-client-auth-token=$token");

        // 4. Access the report page
        // We'll try to guess the parameters for date filtering
        $reportUrlWithParams = $reportUrl . "?startDate=$date&endDate=$date";
        echo "Exporting Hoopla Overall Circulations report for $date...\n";
        
        if ($this->verbose) echo "Step 3: Accessing Report Page: $reportUrlWithParams\n";
        
        curl_setopt($ch, CURLOPT_URL, $reportUrlWithParams);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'
        ]);

        $reportPage = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Find download link
        $downloadUrl = '';
        if (preg_match('/href="([^"]+export[^"]+)"/i', $reportPage, $matches) || 
            preg_match('/href="([^"]+csv[^"]+)"/i', $reportPage, $matches) ||
            preg_match('/href="([^"]+download[^"]+)"/i', $reportPage, $matches)) {
            $downloadUrl = $matches[1];
            if (strpos($downloadUrl, 'http') !== 0) {
                $downloadUrl = $baseUrl . $downloadUrl;
            }
        }

        if (!$downloadUrl) {
            // Fallback: try appending format=csv to the URL
            $downloadUrl = $reportUrlWithParams . "&format=csv";
            if ($this->verbose) echo "No obvious download link found. Trying guessed URL: $downloadUrl\n";
        }

        // 5. Download the report
        if ($this->verbose) echo "Step 4: Downloading CSV from $downloadUrl\n";
        
        $tempFile = tempnam(sys_get_temp_dir(), 'HooplaReport');
        $fp = fopen($tempFile, 'w+');
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_exec($ch);
        
        $finalContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $finalHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);

        if ($this->verbose) echo "HTTP Response Code: $finalHttpCode, Content-Type: $finalContentType\n";

        if ($finalHttpCode === 200 && (strpos($finalContentType, 'text/csv') !== false || 
            strpos($finalContentType, 'application/vnd.ms-excel') !== false || 
            strpos($finalContentType, 'text/plain') !== false ||
            strpos($finalContentType, 'application/octet-stream') !== false)) {
            
            copy($tempFile, $hooplaFile);
            echo "Hoopla report saved to: $hooplaFile\n";
            
            curl_close($ch);
            @unlink($cookieFile);
            @unlink($tempFile);
            return $hooplaFile;
        } else {
            $html = file_get_contents($tempFile);
            curl_close($ch);
            @unlink($cookieFile);
            @unlink($tempFile);
            if ($this->verbose) echo "Response Preview: " . substr(strip_tags($html), 0, 500) . "...\n";
            throw new Exception("Failed to download report. Received content type: $finalContentType");
        }
    }
}

$date = null;
$verbose = false;
$useLocal = false;

foreach ($argv as $i => $arg) {
    if ($i == 0) continue;
    if ($arg === '-verbose') {
        $verbose = true;
    } elseif ($arg === '-localfile') {
        $useLocal = true;
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
}

try {
    $downloader = new HooplaReportDownloader($verbose, $useLocal);
    $downloader->getConfig();
    $downloader->downloadReport($date);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
