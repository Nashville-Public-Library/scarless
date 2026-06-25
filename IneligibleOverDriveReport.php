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

    public function run($useLocal = false) {
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
            $outFp = fopen($outputFile, 'w');
            $matchCount = 0;
            $recordCount = 0;

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
                    
                    // Add Carl.X columns to header
                    $finalHeader = array_merge($header, ['patronid', 'bty']);
                    fputcsv($outFp, $finalHeader);

                    $sampleOdIds = [];
                    while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                        $recordCount++;
                        if (count($header) !== count($data)) continue;

                        $hold = array_combine($header, $data);
                        $odUserId = null;
                        
                        // Find User ID column - OverDrive might use 'User ID', 'user id', 'UserID', 'Patron ID'
                        foreach (['User ID', 'user id', 'UserID', 'Patron ID'] as $key) {
                            if (isset($hold[$key])) {
                                $odUserId = strtolower(trim($hold[$key]));
                                break;
                            }
                        }

                        if ($recordCount <= 5 && $odUserId) {
                            $sampleOdIds[] = $odUserId;
                        }

                        if ($odUserId && isset($ineligiblePatrons[$odUserId])) {
                            // Match found! 
                            $carlxData = $ineligiblePatrons[$odUserId];
                            // Carl.X columns are returned in uppercase from OCI
                            $patronid = isset($carlxData['PATRONID']) ? $carlxData['PATRONID'] : (isset($carlxData['patronid']) ? $carlxData['patronid'] : '');
                            $bty = isset($carlxData['BTY']) ? $carlxData['BTY'] : (isset($carlxData['bty']) ? $carlxData['bty'] : '');
                            
                            $finalData = array_merge($data, [$patronid, $bty]);
                            fputcsv($outFp, $finalData);
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
            fclose($outFp);
            // Only unlink if it's a temporary file (not when using local)
            if (!$useLocal) {
                @unlink($tempFile);
            }

            echo "Found $matchCount matches out of $recordCount records in OverDrive report.\n";

            if ($matchCount > 0) {
                echo "Final report saved to: $outputFile\n";
            } else {
                echo "No matches found. Empty report generated.\n";
                @unlink($outputFile);
            }
            
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

$useLocal = in_array('-localfile', $argv);
$report = new IneligibleOverDriveReport();
$report->run($useLocal);
