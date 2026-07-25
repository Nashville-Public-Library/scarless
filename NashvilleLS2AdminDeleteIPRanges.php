<?php
/**
 * NashvilleLS2AdminDeleteIPRanges.php
 *
 * Purpose:
 * This script automates the removal of IP addresses from LS2Admin Station Location Settings.
 * It logs into LS2Admin, parses the IP range table to find matching IDs, and executes delete requests.
 *
 * Usage:
 * php NashvilleLS2AdminDeleteIPRanges.php [options]
 *
 * Arguments:
 *   -ips="96.4.9.0/24,^96\.4\.9\." Comma-separated list of IP addresses, CIDR blocks, or Regex patterns to remove.
 *   -file=path/to/file     File containing IP addresses/patterns to remove (one per line).
 *   -verbose               Enable detailed diagnostic logging.
 *   -dry-run               Find matching IDs but do not execute deletion.
 *
 * Credits:
 * Most of the programming and automation logic was developed by Junie, an autonomous
 * AI programmer by JetBrains, following requirements provided by James Staub (Nashville Public Library).
 */

class NashvilleLS2AdminDeleteIPRanges {
    private $baseUrl;
    private $username;
    private $password;
    private $verbose = false;
    private $dryRun = false;
    private $cookieFile;

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'LS2AdminCookie');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function getConfig() {
        if (!file_exists('../config.pwd.ini')) {
            throw new Exception("Config file not found at ../config.pwd.ini");
        }
        $configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_TYPED);
        
        if (isset($configArray['LS2Admin'])) {
            $this->baseUrl  = rtrim($configArray['LS2Admin']['BaseUrl'] ?? 'https://kids.library.nashville.org/admin', '/');
            $this->username = $configArray['LS2Admin']['UserName'] ?? null;
            $this->password = $configArray['LS2Admin']['Password'] ?? null;
        } else {
            // Fallback or error
            throw new Exception("[LS2Admin] section missing in config.pwd.ini");
        }

        if (!$this->username || !$this->password) {
            throw new Exception("LS2Admin credentials missing in config.pwd.ini");
        }
    }

    private function log($message) {
        if ($this->verbose) {
            echo "[LOG] " . $message . "\n";
        }
    }

    private function isMatch($target, $value) {
        $value = trim($value);
        if (empty($value)) return false;
        if ($target === $value) return true;

        // CIDR support (IPv4)
        if (strpos($target, '/') !== false) {
            $parts = explode('/', $target);
            if (count($parts) === 2 && filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && is_numeric($parts[1])) {
                $ip = ip2long($value);
                $net = ip2long($parts[0]);
                $maskBits = (int)$parts[1];
                if ($maskBits === 0) return true;
                if ($maskBits > 0 && $maskBits <= 32) {
                    $mask = ~((1 << (32 - $maskBits)) - 1);
                    if ($ip !== false && $net !== false && ($ip & $mask) === ($net & $mask)) {
                        return true;
                    }
                }
            }
        }

        // Regex support - if the target contains regex special characters
        if (preg_match('/[\^\$\*\[\]\(\)\\\\|]/', $target)) {
            $pattern = '/' . str_replace('/', '\/', $target) . '/';
            if (@preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    private function initCurl() {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        return $ch;
    }

    public function login() {
        $this->log("Attempting JSON login to " . $this->baseUrl);
        $ch = $this->initCurl();

        // Based on HAR, login is at /login relative to the host
        $parsed = parse_url($this->baseUrl);
        if (!$parsed || !isset($parsed['host'])) {
            throw new Exception("Invalid BaseUrl for login derivation: " . $this->baseUrl);
        }
        $loginUrl = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . '/login';

        $postData = [
            'ajax' => true,
            'username' => $this->username,
            'password' => $this->password,
            'rememberMe' => false,
            'useHostLogin' => false
        ];

        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'X-Requested-With: XMLHttpRequest'
        ]);
        
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($info['http_code'] >= 400) {
            throw new Exception("Login failed with HTTP code " . $info['http_code']);
        }

        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true) {
            $this->log("Login successful.");
        } else {
             $error = $data['error'] ?? 'Unknown error';
             throw new Exception("Login failed: " . $error);
        }
    }

    public function findIpRangeIds($targetIps) {
        $this->log("Fetching IP ranges...");
        $ch = $this->initCurl();
        
        // Based on the HAR, /admin/ipRange returns JSON with Accept: application/json
        $settingsUrl = $this->baseUrl . '/ipRange'; 
        curl_setopt($ch, CURLOPT_URL, $settingsUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest'
        ]);
        $response = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!$response) {
            throw new Exception("Failed to fetch IP range settings from $settingsUrl");
        }

        $matchingIds = [];

        // Check if response is JSON
        if (strpos($contentType, 'application/json') !== false || (isset($response[0]) && ($response[0] == '[' || $response[0] == '{'))) {
            $this->log("Parsing JSON response...");
            $data = json_decode($response, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (isset($item['id'])) {
                        $itemMatched = false;
                        foreach ($targetIps as $target) {
                            $fields = ['startAddressString', 'endAddressString', 'ipAddress', 'startIp', 'value'];
                            foreach ($fields as $field) {
                                if (isset($item[$field]) && $this->isMatch($target, $item[$field])) {
                                    $matchingIds[$item['id']] = $target;
                                    $itemMatched = true;
                                    break;
                                }
                            }
                            if ($itemMatched) break;
                        }
                    }
                }
            }
            if (!empty($matchingIds)) return $matchingIds;
        }

        $this->log("Parsing HTML response...");
        $dom = new DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new DOMXPath($dom);
        
        $rows = $xpath->query("//tr");
        foreach ($rows as $row) {
            $rowValues = [];
            $cells = $xpath->query(".//td", $row);
            foreach ($cells as $cell) {
                $rowValues[] = trim($cell->textContent);
                $divs = $xpath->query(".//div[@title]", $cell);
                foreach ($divs as $div) {
                    $rowValues[] = $div->getAttribute('title');
                }
            }

            foreach ($targetIps as $target) {
                $found = false;
                foreach ($rowValues as $val) {
                    if ($this->isMatch($target, $val)) {
                        $found = true;
                        break;
                    }
                }

                if ($found) {
                    $id = $row->getAttribute('id');
                    if (!$id) $id = $row->getAttribute('data-id');
                    if (!$id) {
                        $inputs = $xpath->query(".//input[@action='delete']", $row);
                        if ($inputs->length > 0) {
                            $id = $inputs->item(0)->getAttribute('data-id') ?: $inputs->item(0)->getAttribute('id');
                        }
                    }

                    if ($id && preg_match('/(\d+)$/', $id, $matches)) {
                        $matchingIds[$matches[1]] = $target;
                        break;
                    }
                }
            }
        }

        if (empty($matchingIds)) {
            $this->log("Checking for data in embedded JavaScript objects...");
            if (preg_match_all('/"id":\s*(\d+)[^}]+"(?:ipAddress|startIp|value|title)":\s*"([^"]+)"/', $response, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    foreach ($targetIps as $target) {
                        if ($this->isMatch($target, $m[2])) {
                            $matchingIds[$m[1]] = $target;
                            break;
                        }
                    }
                }
            }
        }

        return $matchingIds;
    }

    public function deleteIpRange($id) {
        $deleteUrl = $this->baseUrl . '/ipRange/' . $id;
        
        if ($this->dryRun) {
            echo "[DRY-RUN] Would delete IP range ID: $id at $deleteUrl\n";
            return true;
        }

        $this->log("Deleting IP range ID: $id at $deleteUrl");
        $ch = $this->initCurl();
        curl_setopt($ch, CURLOPT_URL, $deleteUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json, text/javascript, */*; q=0.01'
        ]);
        
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($info['http_code'] == 200 || $info['http_code'] == 204) {
            return true;
        } else {
            $this->log("Delete failed with HTTP code: " . $info['http_code'] . " Response: " . substr($response, 0, 200));
            return false;
        }
    }

    public function run($ips, $dryRun = false, $verbose = false) {
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;

        try {
            $this->getConfig();
            $this->login();
            
            $matchingIds = $this->findIpRangeIds($ips);
            
            if (empty($matchingIds)) {
                echo "No matching IP ranges found for deletion.\n";
                return;
            }

            foreach ($matchingIds as $id => $target) {
                echo "Processing removal for ID: $id (Matched by: $target)\n";
                if ($this->deleteIpRange($id)) {
                    echo "SUCCESS: ID $id removed.\n";
                } else {
                    echo "FAILED: Could not remove ID $id.\n";
                }
            }

        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }
}

// CLI Handling
if (php_sapi_name() == "cli") {
    $options = getopt("", ["ips:", "file:", "verbose", "dry-run"]);
    
    $targetIps = [];
    if (isset($options['ips'])) {
        $targetIps = array_map('trim', explode(',', $options['ips']));
    }
    if (isset($options['file']) && file_exists($options['file'])) {
        $fileIps = file($options['file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $targetIps = array_merge($targetIps, array_map('trim', $fileIps));
    }

    if (empty($targetIps)) {
        echo "Usage: php NashvilleLS2AdminDeleteIPRanges.php --ips=\"IP1,IP2\" [--verbose] [--dry-run]\n";
        exit(1);
    }

    $app = new NashvilleLS2AdminDeleteIPRanges();
    $app->run($targetIps, isset($options['dry-run']), isset($options['verbose']));
}
