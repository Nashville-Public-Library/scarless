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

        // 2.5. Get Reporting Token (Handshake for Tableau)
        if ($this->verbose) {
            echo "\n[--- BEGIN ANALYTIC SEGMENT ---]\n";
        }
        
        $reportingTokenUrls = [
            'https://mwt-gateway.midwesttape.com/insights/v1/token',
            'https://mwt-gateway.midwesttape.com/insights/v1/reporting/token',
            'https://mwt-gateway.midwesttape.com/auth/v1/token/reporting',
            'https://mwt-gateway.midwesttape.com/auth/v1/reporting/token',
            'https://www.midwesttape.com/api/insights/v1/token',
            'https://www.midwesttape.com/api/auth/v1/token/reporting',
            'https://mwt-gateway.midwesttape.com/insights/v1/reporting/redirect'
        ];
        
        $redirectUrl = null;
        foreach ($reportingTokenUrls as $tryUrl) {
            if ($this->verbose) echo "Step 2.5: Attempting to get reporting token from $tryUrl\n";
            curl_setopt($ch, CURLOPT_URL, $tryUrl);
            curl_setopt($ch, CURLOPT_POST, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'X-Auth-Token: ' . $token,
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json, text/plain, */*',
                'Referer: ' . $baseUrl . '/'
            ]);
            $reportingResponse = curl_exec($ch);
            $repCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $repContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            
            if ($this->verbose) {
                echo "Response Code: $repCode, Content-Type: $repContentType\n";
            }
            
            if (($repCode === 200 || $repCode === 201) && strpos($repContentType, 'application/json') !== false) {
                if ($this->verbose) echo "Success at $tryUrl (JSON received)\n";
                $reportingData = json_decode($reportingResponse, true);
                $redirectUrl = $reportingData['redirectUrl'] ?? ($reportingData['url'] ?? null);
                if ($redirectUrl) break;
            }
        }
        
        if ($redirectUrl) {
            if ($this->verbose) echo "Step 2.6: Establishing Tableau session via redirect URL: $redirectUrl\n";
            curl_setopt($ch, CURLOPT_URL, $redirectUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Referer: ' . $baseUrl . '/'
            ]);
            curl_exec($ch);
            if ($this->verbose) {
                echo "Step 2.6 Response Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
                $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST);
                echo "DEBUG: Cookies in jar after Step 2.6 handshake:\n";
                foreach ($cookies as $cookie) {
                    echo "  - $cookie\n";
                }
            }
        } else {
            if ($this->verbose) echo "Warning: No redirectUrl found in reporting token response. Proceeding anyway.\n";
        }

        // 2.7. Get Tableau JWT via GraphQL
        if ($this->verbose) echo "Step 2.7: Fetching Tableau JWT via GraphQL...\n";
        $graphqlQuery = [
            'query' => 'query generateTableauJwt { tableauJwt { ... on TableauJwtPayload { token } ... on ErrorPayload { errors { message code } } } }',
            'variables' => new stdClass()
        ];
        
        $graphqlEndpoints = [
            'https://mwt-ecom-graphql-gateway.midwesttape.com',
            'https://mwt-gateway.midwesttape.com/graphql'
        ];
        
        $tableauJwt = null;
        foreach ($graphqlEndpoints as $endpoint) {
            if ($this->verbose) echo "Trying GraphQL endpoint: $endpoint\n";
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($graphqlQuery));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'X-Client-Name: MEG',
                'X-Requested-With: XMLHttpRequest'
            ]);
            
            $gqlResponse = curl_exec($ch);
            $gqlHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($gqlHttpCode === 200) {
                $gqlData = json_decode($gqlResponse, true);
                $tableauJwt = $gqlData['data']['tableauJwt']['token'] ?? null;
                if ($tableauJwt) {
                    if ($this->verbose) echo "Successfully obtained Tableau JWT.\n";
                    break;
                }
            }
            if ($this->verbose) echo "Failed to get token from $endpoint (HTTP $gqlHttpCode)\n";
        }

        if (!$tableauJwt) {
            if ($this->verbose) echo "Warning: Could not obtain Tableau JWT via GraphQL. Proceeding with regular session.\n";
        }

        // 3. Set the auth token cookie for the domains
        // We set them as session cookies (no Max-Age) to avoid CURL thinking they are expired due to potential clock skew
        curl_setopt($ch, CURLOPT_COOKIELIST, "Set-Cookie: mwt-client-auth-token=$token; Domain=midwesttape.com; Path=/");
        curl_setopt($ch, CURLOPT_COOKIELIST, "Set-Cookie: mwt-client-auth-token=$token; Domain=.midwesttape.com; Path=/");

        // 4. Access the report page first to initialize session
        if ($this->verbose) {
            echo "Step 3: Accessing main report page: $reportUrl\n";
        }
        
        curl_setopt($ch, CURLOPT_URL, $reportUrl);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_REFERER, $baseUrl . '/login');
        
        $reportHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Authorization: Bearer ' . $token
        ];
        if ($tableauJwt) {
            $reportHeaders[] = 'X-Tableau-Auth: ' . $tableauJwt;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $reportHeaders);

        $reportHtml = curl_exec($ch);
        if ($this->verbose) {
            echo "Step 3 Response Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
            
            // Check for Tableau references in the HTML (RAW)
            if (preg_match_all('/https?:\/\/[^"\'>]*(?:tableau|externalreporting)[^"\'>]*/i', $reportHtml, $matches)) {
                echo "DEBUG: Found relevant URLs in report page:\n";
                foreach (array_unique($matches[0]) as $url) {
                    echo "  - $url\n";
                }
            } else {
                echo "DEBUG: No relevant URLs found in first 5000 chars of body. Dumping RAW HTML snippet:\n";
                echo "--------------------------------------------------\n";
                echo substr($reportHtml, 0, 5000) . "\n";
                echo "--------------------------------------------------\n";
                
                // Search for any strings that might be API endpoints or tokens
                if (preg_match_all('/"([^"]*(?:api|token|reporting|redirect)[^"]*)"/i', $reportHtml, $stringMatches)) {
                    echo "DEBUG: Found potential interesting strings in HTML:\n";
                    $count = 0;
                    foreach (array_unique($stringMatches[1]) as $str) {
                        echo "  - $str\n";
                        if ($count++ > 20) break;
                    }
                }
                
                // Search for potential JWT tokens
                if (preg_match_all('/[A-Za-z0-9-_]{20,}\.[A-Za-z0-9-_]{20,}\.[A-Za-z0-9-_]{20,}/', $reportHtml, $jwtMatches)) {
                    echo "DEBUG: Found potential JWT tokens in HTML:\n";
                    foreach (array_unique($jwtMatches[0]) as $jwt) {
                        echo "  - " . substr($jwt, 0, 30) . "..." . substr($jwt, -30) . "\n";
                    }
                }
            }

            $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST);
            echo "DEBUG: Cookies in jar after Step 3:\n";
            foreach ($cookies as $cookie) {
                echo "  - $cookie\n";
            }
        }

        // 5. Initialize Tableau Session
        if ($tableauJwt) {
            if ($this->verbose) echo "Step 3.4: Proactively signing in to Tableau via JWT...\n";
            $signInUrl = "https://externalreporting.midwesttape.com/vizportal/api/web/v1/auth/signin?token=" . urlencode($tableauJwt);
            curl_setopt($ch, CURLOPT_URL, $signInUrl);
            curl_setopt($ch, CURLOPT_REFERER, $reportUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json, text/plain, */*',
                'X-Requested-With: XMLHttpRequest'
            ]);
            curl_exec($ch);
            if ($this->verbose) {
                echo "Step 3.4 Response Code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
                $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST);
                echo "DEBUG: Cookies in jar after Step 3.4 signin:\n";
                foreach ($cookies as $cookie) {
                    echo "  - $cookie\n";
                }
            }
        }

        // We hit the view URL without .csv first to establish the session.
        $viewUrl = $tableauBaseUrl;
        $tableauParams = [
            ':embed' => 'y',
            ':apiID' => 'embhost5',
            ':embcount' => '1',
            ':apiInternalVersion' => '1.196.0',
            ':apiExternalVersion' => '3.16.0',
            'navType' => '0',
            'navSrc' => 'Opt',
            ':disableUrlActionsPopups' => 'n',
            ':tabs' => 'y',
            ':toolbar' => 'n',
            ':device' => 'desktop',
            'mobile' => 'n',
            ':hideEditButton' => 'n',
            ':hideEditInDesktopButton' => 'n',
            ':suppressDefaultEditBehavior' => 'n',
            ':jsdebug' => 'n',
            ':site' => 'external_reporting',
            'customer_master' => $customerMaster,
            'Custom Start' => $date,
            'Custom End' => $date,
            'Time Frame' => 'Custom'
        ];
        $viewUrlWithParams = $viewUrl . '?' . http_build_query($tableauParams);

        if ($this->verbose) echo "Step 3.5: Initializing Tableau session via $viewUrlWithParams\n";
        
        curl_setopt($ch, CURLOPT_URL, $viewUrlWithParams);
        curl_setopt($ch, CURLOPT_REFERER, $reportUrl);
        $initHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Authorization: Bearer ' . ($tableauJwt ?? $token)
        ];
        if ($tableauJwt) {
            $initHeaders[] = 'X-Tableau-Auth: ' . $tableauJwt;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $initHeaders);
        $initResponse = curl_exec($ch);
        $initHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($this->verbose) {
            echo "Step 3.5 Response Code: $initHttpCode\n";
            echo "Step 3.5 Response Preview: " . substr(strip_tags($initResponse), 0, 1000) . "...\n";
            $cookies = curl_getinfo($ch, CURLINFO_COOKIELIST);
            echo "DEBUG: Cookies in jar after Step 3.5:\n";
            foreach ($cookies as $cookie) {
                echo "  - $cookie\n";
            }
        }

        // Give the session a moment to initialize
        if ($this->verbose) echo "Waiting 2 seconds for session propagation...\n";
        sleep(2);

        // 6. Trigger the Tableau CSV Export
        $downloadUrl = $tableauBaseUrl . ".csv";
        // Use the same params as manual process for consistency
        $downloadParams = $tableauParams;
        $downloadParams[':export_format'] = 'csv';
        $downloadUrl .= '?' . http_build_query($downloadParams);

        echo "Exporting Hoopla Overall Circulations report for $date...\n";
        if ($this->verbose) echo "Step 4: Downloading CSV from $downloadUrl\n";
        
        $tempFile = tempnam(sys_get_temp_dir(), 'HooplaReport');
        $fp = fopen($tempFile, 'w+');
        curl_setopt($ch, CURLOPT_URL, $downloadUrl);
        curl_setopt($ch, CURLOPT_REFERER, $viewUrlWithParams);
        $downloadHeaders = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Authorization: Bearer ' . ($tableauJwt ?? $token)
        ];
        if ($tableauJwt) {
            $downloadHeaders[] = 'X-Tableau-Auth: ' . $tableauJwt;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $downloadHeaders);
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
