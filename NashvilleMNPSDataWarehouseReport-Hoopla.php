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

    private function base64UrlDecode($input) {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }

    private function getCustomerMasterFromToken($token) {
        $parts = explode('.', $token);
        if (count($parts) < 2) return '';
        $payload = $this->base64UrlDecode($parts[1]);
        $decoded = json_decode($payload, true);
        return $decoded['customerSapId'] ?? '';
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
        $tableauBaseUrl = "https://externalreporting.midwesttape.com/t/external_reporting/views/OverallCirculations-LibraryCard-expandedfixeddates_nofilter/OverallCirculationsLibraryCards";

        echo "Logging in to Midwest Tape Gateway...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
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

        // Gateway often returns 201 Created on successful token generation
        if ($httpCode !== 200 && $httpCode !== 201) {
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

        $customerMaster = $this->getCustomerMasterFromToken($token);
        if ($this->verbose) {
            echo "Login successful. Received JWT token.\n";
            echo "Extracted Customer Master ID: $customerMaster\n";
        }

        // 3. Set the auth token cookie for the main domain
        // We use both midwesttape.com and .midwesttape.com to be sure it's sent correctly
        curl_setopt($ch, CURLOPT_COOKIELIST, "Set-Cookie: mwt-client-auth-token=$token; Domain=midwesttape.com; Path=/; Max-Age=259200");
        curl_setopt($ch, CURLOPT_COOKIELIST, "Set-Cookie: mwt-client-auth-token=$token; Domain=.midwesttape.com; Path=/; Max-Age=259200");

        // 4. Access the report page first to initialize session/get CSRF if needed
        if ($this->verbose) {
            echo "\n[--- BEGIN ANALYTIC SEGMENT ---]\n";
            echo "Step 3: Accessing main report page: $reportUrl\n";
        }
        
        curl_setopt($ch, CURLOPT_URL, $reportUrl);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_REFERER, $baseUrl . '/login');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Authorization: Bearer ' . $token
        ]);

        $reportHtml = curl_exec($ch);
        if ($this->verbose) {
            echo "Step 3 Response Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
            // Check for Tableau and External Reporting references
            if (preg_match_all('/https?:\/\/[^"\'>]*(?:tableau|externalreporting)[^"\'>]*/i', $reportHtml, $matches)) {
                echo "DEBUG: Found relevant URLs in report page:\n";
                foreach (array_unique($matches[0]) as $url) {
                    echo "  - $url\n";
                }
            } else {
                echo "DEBUG: No relevant URLs found in first 5000 chars of body:\n";
                echo substr(strip_tags($reportHtml), 0, 5000) . "...\n";
                // Look for any JSON-like structures that might contain config
                if (preg_match_all('/\{[^{}]*"url"[^{}]*\}/i', $reportHtml, $jsonMatches)) {
                    echo "DEBUG: Found potential JSON config objects:\n";
                    foreach (array_unique($jsonMatches[0]) as $json) {
                        echo "  - $json\n";
                    }
                }
            }
            
            $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST);
            echo "DEBUG: Cookies in jar after Step 3:\n";
            foreach ($cookies as $cookie) {
                echo "  - $cookie\n";
            }
        }

        // 3.2. Hit the External Reporting root to see if it sets anything
        if ($this->verbose) echo "Step 3.2: Accessing externalreporting.midwesttape.com root\n";
        curl_setopt($ch, CURLOPT_URL, 'https://externalreporting.midwesttape.com/');
        curl_setopt($ch, CURLOPT_REFERER, $reportUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token
        ]);
        curl_exec($ch);

        // 5. Initialize Tableau Session
        // We hit the view URL without .csv first to establish the session.
        $viewUrl = $tableauBaseUrl;
        $params = [
            ':embed' => 'y',
            ':toolbar' => 'n',
            'customer_master' => $customerMaster,
            'Custom Start' => $date,
            'Custom End' => $date,
            'Time Frame' => 'Custom'
        ];
        $viewUrlWithParams = $viewUrl . '?' . http_build_query($params);

        if ($this->verbose) echo "Step 3.5: Initializing Tableau session via $viewUrlWithParams\n";
        
        curl_setopt($ch, CURLOPT_URL, $viewUrlWithParams);
        curl_setopt($ch, CURLOPT_REFERER, $reportUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token
        ]);
        $initResponse = curl_exec($ch);
        $initHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($this->verbose) {
            echo "Step 3.5 Response Code: $initHttpCode\n";
            $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST);
            echo "DEBUG: Cookies in jar after Step 3.5:\n";
            foreach ($cookies as $cookie) {
                echo "  - $cookie\n";
            }
        }

        // 6. Trigger the Tableau CSV Export
        // The user pointed out the specific Tableau view and parameters needed.
        // We append .csv to the view URL for a direct data export.
        $downloadUrl = $tableauBaseUrl . ".csv";
        $params = [
            ':embed' => 'y',
            ':toolbar' => 'n',
            'customer_master' => $customerMaster,
            'Custom Start' => $date,
            'Custom End' => $date,
            'Time Frame' => 'Custom'
        ];
        $downloadUrl .= '?' . http_build_query($params);

        echo "Exporting Hoopla Overall Circulations report for $date...\n";
        if ($this->verbose) echo "Step 4: Downloading CSV from $downloadUrl\n";
        
        $tempFile = tempnam(sys_get_temp_dir(), 'HooplaReport');
        $fp = fopen($tempFile, 'w+');
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_REFERER, $viewUrlWithParams);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token
        ]);
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
            
            if ($this->verbose) echo "\n[--- END ANALYTIC SEGMENT ---]\n";
            
            curl_close($ch);
            @unlink($cookieFile);
            @unlink($tempFile);
            return $hooplaFile;
        } else {
            $html = file_get_contents($tempFile);
            curl_close($ch);
            @unlink($cookieFile);
            @unlink($tempFile);
            if ($this->verbose) {
                echo "Response Preview: " . substr(strip_tags($html), 0, 1000) . "...\n";
                echo "\n[--- END ANALYTIC SEGMENT ---]\n";
                echo "\nANALYSIS NEEDED: Please copy everything from '[--- BEGIN ANALYTIC SEGMENT ---]' above to here and provide it to Junie.\n";
            }
            throw new Exception("Failed to download report. Received content type: $finalContentType. Check verbose output for details.");
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
    if ($verbose) {
        echo "\nANALYSIS NEEDED: Please copy everything from '[--- BEGIN ANALYTIC SEGMENT ---]' above to the end and provide it to Junie.\n";
    }
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
