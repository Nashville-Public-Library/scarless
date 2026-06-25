<?php

class IneligibleOverDriveReport {
    private $carlx_db_php;
    private $carlx_db_php_user;
    private $carlx_db_php_password;
    private $od_username;
    private $od_password;
    private $reportPath = '../data/';

    public function getConfig() {
        if (!file_exists('../config.pwd.ini')) {
            throw new Exception("Config file not found at ../config.pwd.ini");
        }
        $configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
        
        $this->carlx_db_php = $configArray['Catalog']['carlx_db_php'];
        $this->carlx_db_php_user = $configArray['Catalog']['carlx_db_php_user'];
        $this->carlx_db_php_password = $configArray['Catalog']['carlx_db_php_password'];
        
        if (isset($configArray['OverDrive'])) {
            $this->od_username = $configArray['OverDrive']['UserName'];
            $this->od_password = $configArray['OverDrive']['Password'];
        } else {
            throw new Exception("[OverDrive] section missing in config.pwd.ini");
        }
    }

    public function getIneligiblePatronsFromCarlX() {
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
        while (($row = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS)) != false) {
            // Store by patronguid for easy lookup
            if ($row['PATRONGUID']) {
                $patrons[strtolower($row['PATRONGUID'])] = $row;
            }
        }

        oci_free_statement($stid);
        oci_close($conn);

        return $patrons;
    }

    public function downloadOverDriveHoldsReport() {
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

        $fileData = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        if (strpos($contentType, 'text/csv') !== false || 
            strpos($contentType, 'application/vnd.ms-excel') !== false || 
            strpos($contentType, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false) {
            
            $tempFile = tempnam(sys_get_temp_dir(), 'ODReport');
            file_put_contents($tempFile, $fileData);
            echo "Report downloaded to temporary file: $tempFile\n";
            
            curl_close($ch);
            @unlink($cookieFile);
            return $this->parseOverDriveReport($tempFile, $contentType);
        } else {
            // Log the error and content type for troubleshooting
            echo "Error: Unexpected content type received: $contentType\n";
            if (strpos($contentType, 'text/html') !== false) {
                // If it's HTML, it might be an error page or a redirect to login
                echo "Received HTML content instead of CSV/Excel. This might indicate a session timeout or an application error.\n";
            }
            curl_close($ch);
            @unlink($cookieFile);
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

    public function run() {
        try {
            $this->getConfig();
            echo "Retrieving ineligible patrons from Carl.X...\n";
            $ineligiblePatrons = $this->getIneligiblePatronsFromCarlX();
            echo "Found " . count($ineligiblePatrons) . " ineligible patrons in Carl.X.\n";

            echo "Retrieving OverDrive holds report...\n";
            $odHolds = $this->downloadOverDriveHoldsReport();
            echo "Found " . count($odHolds) . " records in OverDrive report.\n";

            $finalReport = [];
            $matchCount = 0;

            foreach ($odHolds as $hold) {
                $odUserId = null;
				if (isset($hold['User ID'])) {
					$odUserId = strtolower(trim($hold['User ID']));
					break;
				}

                if ($odUserId && isset($ineligiblePatrons[$odUserId])) {
                    // Match found! Include all columns from OverDrive report.
                    $finalReport[] = $hold;
                    $matchCount++;
                }
            }

            echo "Found $matchCount matches between OverDrive holds and ineligible Carl.X patrons.\n";

            if ($matchCount > 0) {
                $outputFile = $this->reportPath . 'Ineligible_OverDrive_Holds_' . date('Y-m-d') . '.csv';
                $fp = fopen($outputFile, 'w');
                fputcsv($fp, array_keys($finalReport[0]));
                foreach ($finalReport as $row) {
                    fputcsv($fp, $row);
                }
                fclose($fp);
                echo "Final report saved to: $outputFile\n";
            } else {
                echo "No matches found. No report generated.\n";
            }
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

$report = new IneligibleOverDriveReport();
$report->run();
