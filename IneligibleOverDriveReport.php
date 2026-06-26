<?php
/**
 * IneligibleOverDriveReport.php
 * 
 * Purpose:
 * This script identifies patrons who are ineligible for OverDrive services based on their 
 * Carl.X status and have active holds in OverDrive Marketplace. It generates a report, 
 * optionally emails it, and can automate the cancellation of holds for these patrons.
 * 
 * Usage:
 * php IneligibleOverDriveReport.php [options]
 * 
 * Arguments:
 *   -localfile      Optional. Skip live data retrieval and use previously downloaded CSV files 
 *                   from the ../data/ directory:
 *                   - IneligibleOverDrive_Holds_CarlX.csv
 *                   - IneligibleOverDrive_Holds_OverDrive.csv
 * 
 *   -no-email       Optional. Suppress the automated email notification. By default, the script 
 *                   sends the final report to recipients specified in config.pwd.ini.
 * 
 *   -cancel-holds   Optional. Enable automated hold cancellation in OverDrive Marketplace for 
 *                   matched patrons. For safety, this only processes 15-digit User IDs.
 * 
 *   -test-batch=N   Optional. Limit hold cancellations to a specific number of patrons (e.g., -test-batch=5).
 *                   Useful for verification before running a full batch.
 * 
 *   -verbose        Optional. Enable detailed diagnostic logging for the hold cancellation process.
 * 
 * Credits:
 * Most of the programming and automation logic was developed by Junie, an autonomous 
 * AI programmer by JetBrains, following requirements provided by James Staub (Nashville Public Library).
 */

class IneligibleOverDriveReport {
    private $carlx_db_php;
    private $carlx_db_php_user;
    private $carlx_db_php_password;
    private $od_username;
    private $od_password;
    private $api_client_key;
    private $api_client_secret;
    private $api_library_account;
    private $reportPath = '../data/';
    private $emailRecipients;
    private $cancelHolds = false;
    private $verbose = false;

    public function getConfig() {
        if (!file_exists('../config.pwd.ini')) {
            throw new Exception("Config file not found at ../config.pwd.ini");
        }
        $configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
        
        $this->carlx_db_php = $configArray['Catalog']['carlx_db_php'];
        $this->carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
        $this->carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
        
        if (isset($configArray['OverDrive'])) {
            $this->od_username = $configArray['OverDrive']['MarketplaceUserName'] ?? ($configArray['OverDrive']['UserName'] ?? null);
            $this->od_password = $configArray['OverDrive']['MarketplacePassword'] ?? ($configArray['OverDrive']['Password'] ?? null);
            $this->emailRecipients = $configArray['OverDrive']['IneligibleOverDriveEmailRecipients'] ?? null;
        } else {
            throw new Exception("[OverDrive] section missing in config.pwd.ini");
        }
    }

    public function getIneligiblePatronsFromCarlX($useLocal = false) {
        $carlxFile = $this->reportPath . 'IneligibleOverDrive_Holds_CarlX.csv';

        if ($useLocal && file_exists($carlxFile)) {
            echo "Reading Carl.X data from local file: $carlxFile\n";
            $patrons = [];
            if (($handle = fopen($carlxFile, "r")) !== FALSE) {
                $header = fgetcsv($handle);
                if ($header) {
                    // Remove BOM if present
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                    // Normalize headers to lowercase
                    $header = array_map('strtolower', $header);
                }
                while (($data = fgetcsv($handle)) !== FALSE) {
                    if (count($header) !== count($data)) continue;
                    $row = array_combine($header, $data);
                    if (isset($row['patronguid']) && $row['patronguid']) {
                        $patrons[strtolower(trim($row['patronguid']))] = $row;
                    } elseif (isset($row['patronid']) && $row['patronid']) {
                        // Fallback or secondary check if needed, but the requirement was patronguid
                    }
                }
                fclose($handle);
            }
            return $patrons;
        }

        $sql = <<<EOT
select
    patronid
    , patronguid
    , bty
    , expdate
    , sactdate
from patron_v2 p
where bty in ('43','44','45')
or (bty = '52' and expdate < sysdate)
EOT;

        $conn = oci_connect($this->carlx_db_php_user, $this->carlx_db_php_password, $this->carlx_db_php, 'AL32UTF8');
        if (!$conn) {
            $e = oci_error();
            throw new Exception("Carl.X Connection failed: " . $e['message']);
        }

        $stid = oci_parse($conn, $sql);
        oci_execute($stid);

        $patrons = [];
        $sampleGuids = [];
        $fp = fopen($carlxFile, 'w');
        $headerWritten = false;

        while (($row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS)) != false) {
            if (!$headerWritten) {
                fputcsv($fp, array_keys($row));
                $headerWritten = true;
            }
            fputcsv($fp, $row);

            // Store by patronguid for easy lookup
            if ($row['PATRONGUID']) {
                $guid = strtolower(trim($row['PATRONGUID']));
                $patrons[$guid] = $row;
                if (count($sampleGuids) < 5) {
                    $sampleGuids[] = $guid;
                }
            }
        }
        fclose($fp);

        oci_free_statement($stid);
        oci_close($conn);

        echo "Carl.X data saved to: $carlxFile\n";
        echo "Carl.X Sample PATRONGUIDs: " . implode(', ', $sampleGuids) . "\n";

        return $patrons;
    }

    public function downloadOverDriveHoldsReport($useLocal = false) {
        $odFile = $this->reportPath . 'IneligibleOverDrive_Holds_OverDrive.csv';

        if ($useLocal && file_exists($odFile)) {
            echo "Using local OverDrive report: $odFile\n";
            return ['file' => $odFile, 'contentType' => 'text/csv'];
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'ODCookie');
        $baseUrl = 'https://marketplace.overdrive.com';
        $loginUrl = $baseUrl . '/Account/Login';
        $reportUrl = $baseUrl . '/Insights/Reports/CurrentHolds?data=%7b%22Parameters%22%3a%7b%22page%22%3a1%2c%22limit%22%3a50%2c%22sort%22%3a%5b%5d%2c%22filter%22%3a%5b%5d%2c%22query%22%3a%22%22%7d%2c%22Branch%22%3a%5b%5d%2c%22IsWeeded%22%3anull%2c%22IsSuspended%22%3anull%2c%22IsAvailableForSale%22%3anull%2c%22Website%22%3a%22%22%2c%22UserStatus%22%3anull%2c%22VisitingSystem%22%3anull%2c%22AdvContentOnly%22%3afalse%2c%22RunBy%22%3a2%2c%22Exporting%22%3afalse%7d&deferDataLoad=True&showDialogOnLoad=True';

        echo "Logging in to OverDrive Marketplace...\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        // 1. Get Login Page to capture any CSRF tokens if they exist (common in ASP.NET)
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        $loginPage = curl_exec($ch);
        
        // Extract __RequestVerificationToken if present
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
             // Check if we are actually logged in. 
             // If "Log in" or "UserName" field still appears, login failed.
             if (strpos($response, 'name="UserName"') !== false) {
                 throw new Exception("OverDrive Login failed. Check credentials in config.pwd.ini");
             }
        }
        echo "Login successful.\n";

        // 3. Navigate to Report Page
        echo "Navigating to holds report page...\n";
        curl_setopt($ch, CURLOPT_URL, $reportUrl);
        curl_setopt($ch, CURLOPT_POST, false);
        $reportPage = curl_exec($ch);

        // 4. Trigger "Create Worksheet" (Export)
        // Based on the HAR files provided, the export is a POST request to a specific API endpoint
        echo "Exporting holds report...\n";
        $exportApiUrl = $baseUrl . '/api/insights/CurrentHolds/Export';
        
        $inputJson = json_encode([
            "Parameters" => [
                "page" => 1,
                "limit" => 50,
                "sort" => [],
                "filter" => [],
                "query" => ""
            ],
            "Branch" => [],
            "IsWeeded" => null,
            "IsSuspended" => null,
            "IsAvailableForSale" => null,
            "Website" => "",
            "UserStatus" => null,
            "VisitingSystem" => null,
            "AdvContentOnly" => false,
            "RunBy" => 2,
            "Exporting" => false
        ]);

        curl_setopt($ch, CURLOPT_URL, $exportApiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['inputJson' => $inputJson]));
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
            echo "Report downloaded to temporary file: $tempFile\n";
            echo "OverDrive report saved to: $odFile\n";
            
            curl_close($ch);
            @unlink($cookieFile);
            return ['file' => $tempFile, 'contentType' => $contentType];
        } else {
            // Log the error and content type for troubleshooting
            echo "Error: Unexpected content type received: $contentType\n";
            if (strpos($contentType, 'text/html') !== false) {
                // If it's HTML, it might be an error page or a redirect to login
                $fileData = file_get_contents($tempFile);
                echo "Received HTML content instead of CSV/Excel. This might indicate a session timeout or an application error.\n";
            }
            curl_close($ch);
            @unlink($cookieFile);
            @unlink($tempFile);
            throw new Exception("Failed to download report. Received content type: $contentType");
        }
    }

    private function parseOverDriveReport($filePath, $contentType) {
        $rows = [];
        if (strpos($contentType, 'text/csv') !== false) {
            if (($handle = fopen($filePath, "r")) !== FALSE) {
                $header = fgetcsv($handle, 1000, ",");
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $rows[] = array_combine($header, $data);
                }
                fclose($handle);
            }
        } else {
            // If it's Excel, parsing in pure PHP without extra libs is hard. 
            // Hopefully it's CSV or a simple HTML table masquerading as XLS.
            // Try reading it as CSV first anyway, or just alert.
            echo "Warning: Report is in Excel format. Attempting basic CSV parsing...\n";
             if (($handle = fopen($filePath, "r")) !== FALSE) {
                $header = fgetcsv($handle, 0, ","); // Try comma
                if (count($header) < 2) {
                    rewind($handle);
                    $header = fgetcsv($handle, 0, "\t"); // Try tab
                }
                
                if ($header) {
                    while (($data = fgetcsv($handle, 0, (count($header) > 1 ? "," : "\t"))) !== FALSE) {
                        if (count($header) == count($data)) {
                            $rows[] = array_combine($header, $data);
                        }
                    }
                }
                fclose($handle);
            }
        }
        @unlink($filePath);
        return $rows;
    }

    private function sendEmail($subject, $body, $attachmentPath = null) {
        if (empty($this->emailRecipients)) {
            echo "No email recipients found in config. Skipping email.\n";
            return;
        }

        $to = $this->emailRecipients;
        $headers = "From: noreply-connected@nashville.gov\r\n";
        
        if ($attachmentPath && file_exists($attachmentPath)) {
            $file = file_get_contents($attachmentPath);
            $filename = basename($attachmentPath);
            $boundary = md5(time());
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
            
            $message = "--{$boundary}\r\n";
            $message .= "Content-Type: text/plain; charset=\"utf-8\"\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $body . "\r\n";
            
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/plain; name=\"{$filename}\"\r\n";
            $message .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= chunk_split(base64_encode($file)) . "\r\n";
            $message .= "--{$boundary}--";
            
            if (mail($to, $subject, $message, $headers)) {
                echo "Email sent successfully to: $to\n";
            } else {
                echo "Failed to send email to: $to\n";
            }
        } else {
            if (mail($to, $subject, $body, $headers)) {
                echo "Email sent successfully to: $to\n";
            } else {
                echo "Failed to send email to: $to\n";
            }
        }
    }

    private function processHoldCancellations($userIds, $testBatchLimit = null) {
        echo "Starting hold cancellation process via OverDrive Marketplace...\n";
        if ($testBatchLimit !== null) {
            echo "Test batch mode enabled. Limit: $testBatchLimit patrons.\n";
        }

        $cookieFile = tempnam(sys_get_temp_dir(), 'ODCookie');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        if ($this->verbose) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
        }

        // Login to Marketplace
        $baseUrl = 'https://marketplace.overdrive.com';
        $loginUrl = $baseUrl . '/Account/Login';
        
        if ($this->verbose) echo "[Verbose] Accessing login page: $loginUrl\n";
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_POST, false);
        $loginPage = curl_exec($ch);
        $token = '';
        if (preg_match('/name="__RequestVerificationToken" type="hidden" value="([^"]+)"/', $loginPage, $matches)) {
            $token = $matches[1];
        }
        if ($this->verbose) echo "[Verbose] Login page token: " . ($token ? $token : "NOT FOUND") . "\n";

        $postFields = [
            'UserName' => $this->od_username,
            'Password' => $this->od_password,
            'RememberMe' => 'false'
        ];
        if ($token) {
            $postFields['__RequestVerificationToken'] = $token;
        }

        if ($this->verbose) echo "[Verbose] Submitting login form to $loginUrl\n";
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $response = curl_exec($ch);

        if (strpos($response, 'Sign out') === false && strpos($response, 'Log out') === false) {
             echo "Login failed for cancellation process.\n";
             if ($this->verbose) {
                 echo "[Verbose] Login response preview: " . substr(strip_tags($response), 0, 500) . "...\n";
             }
             curl_close($ch);
             @unlink($cookieFile);
             return;
        }
        echo "Login successful for cancellation.\n";

        $processedCount = 0;
        $skippedCount = 0;
        foreach ($userIds as $id) {
            if ($testBatchLimit !== null && $processedCount >= $testBatchLimit) {
                echo "Test batch limit reached ($testBatchLimit). Stopping.\n";
                break;
            }

            if (strlen($id) !== 15) {
                $skippedCount++;
                continue;
            }

            echo "[$processedCount] Processing User ID: $id\n";
            if ($this->cancelHoldsForUser($ch, $id)) {
                $processedCount++;
            }
        }
        
        if ($skippedCount > 0) {
            echo "Skipped $skippedCount User IDs that were not 15 digits.\n";
        }

        curl_close($ch);
        @unlink($cookieFile);
    }

                        private function cancelHoldsForUser($ch, $userId) {
        $baseUrl = 'https://marketplace.overdrive.com';
        
        // 1. Visit the search page to establish session context for this patron
        // This mimics entering User ID and clicking Update in the manual flow
        $searchParams = [
            "titleId" => "",
            "PatronCardNumber" => $userId,
            "PatronEmail" => "",
            "IsSuspended" => null,
            "Parameters" => [
                "page" => 1,
                "start" => 0,
                "limit" => 50,
                "sort" => []
            ]
        ];
        $searchDataJson = json_encode($searchParams);
        $searchPageUrl = $baseUrl . '/Library/Site/EndUserManagement/SearchHolds?data=' . urlencode($searchDataJson);

        if ($this->verbose) echo "   [Verbose] Visiting search page for $userId: $searchPageUrl\n";
        curl_setopt($ch, CURLOPT_URL, $searchPageUrl);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Referer: $baseUrl/Library/Site/EndUserManagement/SearchHolds"
        ]);
        $searchPageHtml = curl_exec($ch);
        
        $token = '';
        if (preg_match('/name="__RequestVerificationToken" type="hidden" value="([^"]+)"/', $searchPageHtml, $matches)) {
            $token = $matches[1];
        }
        if ($this->verbose) echo "   [Verbose] Search page token: " . ($token ? $token : "NOT FOUND") . "\n";

        // 2. Request the hold data for the grid
        $dc = round(microtime(true) * 1000);
        $searchApiUrl = $baseUrl . '/api/Library/Site/EndUserManagement/ManageHolds/Data?_dc=' . $dc;
        
        if ($this->verbose) echo "   [Verbose] Requesting hold data from API: $searchApiUrl\n";
        if ($this->verbose) echo "   [Verbose] Payload: $searchDataJson\n";
        
        curl_setopt($ch, CURLOPT_URL, $searchApiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $searchDataJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
            'Content-Type: application/json',
            "Referer: $searchPageUrl"
        ]);
        
        $searchResults = curl_exec($ch);
        if ($this->verbose) echo "   [Verbose] API Response (first 500 chars): " . substr($searchResults, 0, 500) . "...\n";
        
        $resultsArr = json_decode($searchResults, true);
        
        if (empty($resultsArr['data'])) {
            echo "   User ID $userId has 0 active/suspended holds.\n";
            return true; 
        }

        $holdsToCancel = [];
        foreach ($resultsArr['data'] as $item) {
            // Safety check: ensure the CardNumber in the response matches what we requested
            $respCardNumber = $item['CardNumber'] ?? 'unknown';
            if ($this->verbose) echo "   [Verbose] Found record in data: Title=" . ($item['Title'] ?? 'N/A') . ", CardNumber=$respCardNumber, ReserveID=" . ($item['ReserveID'] ?? 'N/A') . "\n";
            
            if ($respCardNumber != $userId && $respCardNumber != 'unknown') {
                if ($this->verbose) echo "   [Verbose] Skipping record as CardNumber does not match $userId\n";
                continue;
            }

            if (isset($item['ReserveID']) && isset($item['PatronID'])) {
                $holdsToCancel[] = [
                    'ReserveId' => $item['ReserveID'],
                    'PatronId' => $item['PatronID']
                ];
                echo "   Found hold: " . ($item['Title'] ?? 'Unknown') . "\n";
            }
        }

        if (empty($holdsToCancel)) {
            echo "   No valid holds identified for $userId.\n";
            return true;
        }

        echo "   Canceling " . count($holdsToCancel) . " holds...\n";

        // 3. Execute Cancellation
        $cancelUrl = $baseUrl . '/Library/Site/EndUserManagement/CancelHold';
        
        $postData = [
            '__RequestVerificationToken' => $token
        ];
        foreach ($holdsToCancel as $index => $hold) {
            $postData["holdsToCancel[$index][ReserveId]"] = $hold['ReserveId'];
            $postData["holdsToCancel[$index][PatronId]"] = $hold['PatronId'];
        }

        if ($this->verbose) echo "   [Verbose] Sending cancel request to: $cancelUrl\n";
        if ($this->verbose) echo "   [Verbose] Cancel payload: " . http_build_query($postData) . "\n";

        curl_setopt($ch, CURLOPT_URL, $cancelUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            'X-Requested-With: XMLHttpRequest',
            "Referer: $searchPageUrl"
        ]);
        
        $cancelResponse = curl_exec($ch);
        if ($this->verbose) echo "   [Verbose] Cancel Response: $cancelResponse\n";
        
        if (strpos($cancelResponse, '"success":true') !== false) {
            echo "   [Success] Holds removed.\n";
            return true;
        } else {
            echo "   [Error] Cancellation failed: " . substr($cancelResponse, 0, 200) . "\n";
            return false;
        }
    }

    public function run($useLocal = false, $sendEmail = true, $cancelHolds = false, $testBatchLimit = null, $verbose = false) {
        $this->cancelHolds = $cancelHolds;
        $this->verbose = $verbose;
        // Increase memory limit for processing large datasets
        ini_set('memory_limit', '256M');
        try {
            $this->getConfig();
            echo "Retrieving ineligible patrons from Carl.X...\n";
            $ineligiblePatrons = $this->getIneligiblePatronsFromCarlX($useLocal);
            echo "Found " . count($ineligiblePatrons) . " ineligible patrons in Carl.X.\n";

            echo "Retrieving OverDrive holds report...\n";
            $odReportInfo = $this->downloadOverDriveHoldsReport($useLocal);
            $tempFile = $odReportInfo['file'];
            $contentType = $odReportInfo['contentType'];

            $outputFile = $this->reportPath . 'IneligibleOverDrive_Holds.csv';
            $matchCount = 0;
            $recordCount = 0;
            $matchedUserIds = [];

            if (($handle = fopen($tempFile, "r")) !== FALSE) {
                $delimiter = ",";
                if (strpos($contentType, 'text/csv') === false) {
                    echo "Warning: Report is likely in Excel/Tab format. Detecting delimiter...\n";
                    $headerLine = fgets($handle);
                    if (strpos($headerLine, "\t") !== false && strpos($headerLine, ",") === false) {
                        $delimiter = "\t";
                    }
                    rewind($handle);
                }

                $header = fgetcsv($handle, 0, $delimiter);
                if ($header) {
                    // Remove UTF-8 BOM if present on the first header field
                    if (isset($header[0])) {
                        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                    }
                    
                    echo "OverDrive Report Headers: " . implode(', ', $header) . "\n";
                    
                    $sampleOdIds = [];
                    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                        $recordCount++;
                        if (count($header) !== count($data)) continue;

                        $hold = array_combine($header, $data);
                        $odUserId = null;
                        
                        // Find User ID column - OverDrive might use 'User ID', 'user id', 'UserID', 'Patron ID'
                        $foundKey = null;
                        foreach (['User ID', 'user id', 'UserID', 'Patron ID'] as $key) {
                            if (isset($hold[$key])) {
                                $odUserId = strtolower(trim($hold[$key]));
                                $foundKey = $key;
                                break;
                            }
                        }

                        if ($recordCount <= 5 && $odUserId) {
                            $sampleOdIds[] = $odUserId;
                        }

                        if ($odUserId && isset($ineligiblePatrons[$odUserId])) {
                            // Match found! 
                            // Requirements: single column "User ID", sorted numerically.
                            // Use the original value from the CSV for output
                            $matchedUserIds[] = trim($hold[$foundKey]);
                            $matchCount++;
                        }
                        
                        if ($recordCount % 10000 == 0) {
                            echo "Processed $recordCount records...\n";
                        }
                    }
                    echo "OverDrive Sample User IDs: " . implode(', ', $sampleOdIds) . "\n";
                }
                fclose($handle);
            }

            if ($matchCount > 0) {
                echo "Sorting $matchCount matched User IDs numerically...\n";
                sort($matchedUserIds, SORT_NUMERIC);

                $outFp = fopen($outputFile, 'w');
                fputcsv($outFp, ['User ID']);
                foreach ($matchedUserIds as $id) {
                    fputcsv($outFp, [$id]);
                }
                fclose($outFp);
                echo "Final report saved to: $outputFile\n";

                if ($this->cancelHolds) {
                    $this->processHoldCancellations($matchedUserIds, $testBatchLimit);
                }

                if ($sendEmail) {
                    echo "Sending report via email...\n";
                    $this->sendEmail(
                        "Ineligible OverDrive Holds Report",
                        "Please find attached the Ineligible OverDrive Holds Report generated on " . date('Y-m-d H:i:s') . ".\n\nTotal matches found: $matchCount",
                        $outputFile
                    );
                } else {
                    echo "Email suppressed by -no-email flag.\n";
                }
            } else {
                echo "No matches found. No report generated.\n";
                if (file_exists($outputFile)) {
                    @unlink($outputFile);
                }
            }
            
            // Only unlink if it's a temporary file (not when using local)
            if (!$useLocal) {
                @unlink($tempFile);
            }

            echo "Found $matchCount matches out of $recordCount records in OverDrive report.\n";
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

$useLocal = in_array('-localfile', $argv);
$sendEmail = !in_array('-no-email', $argv);
$cancelHolds = in_array('-cancel-holds', $argv);
$verbose = in_array('-verbose', $argv);

$testBatchLimit = null;
foreach ($argv as $arg) {
    if (preg_match('/^-test-batch=(\d+)$/', $arg, $matches)) {
        $testBatchLimit = (int)$matches[1];
        break;
    }
}

$report = new IneligibleOverDriveReport();
$report->run($useLocal, $sendEmail, $cancelHolds, $testBatchLimit, $verbose);





