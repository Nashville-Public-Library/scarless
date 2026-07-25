<?php
/**
 * NashvilleLS2AdminAddIPRanges.php
 *
 * Purpose:
 * This script automates the addition of IP addresses to LS2Admin Station Location Settings.
 * It reads a CSV file containing IP ranges and CARL branch codes, then executes POST requests.
 *
 * Usage:
 * php NashvilleLS2AdminAddIPRanges.php --file="C:\path\to\your\file.csv" [options]
 *
 * Arguments:
 *   --file=path/to/file     Required. CSV file with columns "IP RANGE" and "CARL BRANCHNUMBER".
 *   --verbose               Enable detailed diagnostic logging.
 *   --dry-run               Parse the file and simulate requests without sending them.
 *   --proxy=host:port       Optional. Route requests through a proxy server.
 *   --no-verify-ssl         Optional. Disable SSL certificate verification.
 */

class NashvilleLS2AdminAddIPRanges {
	private $baseUrl;
	private $username;
	private $password;
	private $verbose = false;
	private $dryRun = false;
	private $proxy = null;
	private $verifySsl = true;
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
		$configArray = parse_ini_file('../config.pwd.ini', true, INI_SCANNER_RAW);

		if (isset($configArray['LS2Admin'])) {
			$this->baseUrl  = rtrim($configArray['LS2Admin']['BaseUrl'], '/') ?? null;
			$this->username = $configArray['LS2Admin']['UserName'] ?? null;
			$this->password = $configArray['LS2Admin']['Password'] ?? null;
		} else {
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

	private function initCurl() {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 45);
		if ($this->proxy) {
			curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
		}
		if (!$this->verifySsl) {
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		}
		return $ch;
	}

	public function login() {
		$this->log("Establishing session at " . $this->baseUrl);
		$ch = $this->initCurl();
		curl_setopt($ch, CURLOPT_URL, $this->baseUrl);
		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		$error = curl_error($ch);
		curl_close($ch);

		if ($response === false) {
			throw new Exception("Failed to establish session: " . $error);
		}

		$parsed = parse_url($this->baseUrl);
		$origin = ($parsed['scheme'] ?? 'https') . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
		$loginUrl = $origin . '/login?_=' . (time() * 1000);

		$this->log("Attempting JSON login to " . $loginUrl);
		$ch = $this->initCurl();

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
			'X-Requested-With: XMLHttpRequest',
			'Accept: application/json, text/javascript, */*; q=0.01',
			'Origin: ' . $origin,
			'Referer: ' . $this->baseUrl
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

	/**
	 * Fetches all existing IP ranges to prevent duplicates.
	 */
	public function fetchExistingIpRanges() {
		$this->log("Fetching existing IP ranges for deduplication...");
		$ch = $this->initCurl();
		$settingsUrl = $this->baseUrl . '/ipRange?_=' . (time() * 1000);
		curl_setopt($ch, CURLOPT_URL, $settingsUrl);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: application/json, text/javascript, */*; q=0.01',
			'X-Requested-With: XMLHttpRequest',
			'Referer: ' . $this->baseUrl
		]);
		$response = curl_exec($ch);
		curl_close($ch);

		if (!$response) {
			throw new Exception("Failed to fetch existing IP ranges from $settingsUrl");
		}

		$data = json_decode($response, true);
		$existing = [];
		if (is_array($data)) {
			foreach ($data as $item) {
				if (isset($item['startAddressString']) && isset($item['endAddressString'])) {
					$key = trim($item['startAddressString']) . '|' . trim($item['endAddressString']);
					$existing[$key] = true;
				}
			}
		}
		return $existing;
	}

	public function addIpRange($startAddress, $endAddress, $branchNumber, $stationType = "0") {
		$addUrl = $this->baseUrl . '/ipRange?_=' . (time() * 1000);

		$postData = [
			'startAddress' => trim($startAddress),
			'endAddress' => trim($endAddress),
			'branchName' => trim($branchNumber),
			'stationType' => $stationType
		];

		if ($this->dryRun) {
			echo "[DRY-RUN] Would add IP range: $startAddress - $endAddress for Branch: $branchNumber\n";
			return true;
		}

		$this->log("Adding IP range: $startAddress - $endAddress for Branch: $branchNumber");
		$ch = $this->initCurl();
		curl_setopt($ch, CURLOPT_URL, $addUrl);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json; charset=utf-8',
			'X-Requested-With: XMLHttpRequest',
			'Accept: application/json, text/javascript, */*; q=0.01',
			'Referer: ' . $this->baseUrl
		]);

		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);

		$data = json_decode($response, true);
		if ($info['http_code'] == 200 && isset($data['success']) && $data['success'] === true) {
			return true;
		} else {
			$msg = $data['message'] ?? 'Unknown error';
			$this->log("Add failed: " . $msg . " Response: " . substr($response, 0, 200));
			return false;
		}
	}

	private function parseIpRange($rangeString) {
		$delimiters = [" - ", " ", "-", ","];
		foreach ($delimiters as $delim) {
			if (strpos($rangeString, $delim) !== false) {
				$parts = explode($delim, $rangeString);
				if (count($parts) >= 2) {
					return [trim($parts[0]), trim($parts[1])];
				}
			}
		}
		return [trim($rangeString), trim($rangeString)];
	}

	public function run($csvPath, $dryRun = false, $verbose = false, $proxy = null, $verifySsl = true) {
		$this->dryRun = $dryRun;
		$this->verbose = $verbose;
		$this->proxy = $proxy;
		$this->verifySsl = $verifySsl;

		if (!file_exists($csvPath)) {
			echo "ERROR: CSV file not found at $csvPath\n";
			return;
		}

		try {
			$this->getConfig();
			$this->login();

			$existingRanges = $this->fetchExistingIpRanges();

			$handle = fopen($csvPath, "r");
			if (!$handle) {
				throw new Exception("Could not open CSV file.");
			}

			$header = fgetcsv($handle);
			if (!$header) {
				throw new Exception("CSV file is empty.");
			}

			// Aggressive normalization: Strip BOM, Uppercase, Trim, Remove Quotes
			$header = array_map(function($h) {
				$h = preg_replace('/^[\xEF\xBB\xBF\xFE\xFF\xFF\xFE]+/', '', $h);
				return trim(str_replace(['"', "'"], '', strtoupper($h)));
			}, $header);

			$colRange = array_search('IP RANGE', $header);
			$colBranch = array_search('CARL BRANCHNUMBER', $header);
			if ($colBranch === false) {
				$colBranch = array_search('CARL BRANCHCODE', $header);
			}

			if ($colRange === false || $colBranch === false) {
				$foundHeaders = implode('|', $header);
				throw new Exception("Required columns 'IP RANGE' and 'CARL BRANCHNUMBER' not found. Detected: [$foundHeaders]");
			}

			while (($row = fgetcsv($handle)) !== false) {
				if (!isset($row[$colRange]) || trim($row[$colRange]) === '') continue;

				list($start, $end) = $this->parseIpRange($row[$colRange]);
				$branch = trim($row[$colBranch] ?? '');

				if (empty($start) || empty($branch)) {
					$this->log("Skipping invalid row: " . implode(',', $row));
					continue;
				}

				// Deduplication check
				$rangeKey = $start . '|' . $end;
				if (isset($existingRanges[$rangeKey])) {
					echo "WARNING: IP range $start - $end already exists. Skipping.\n";
					continue;
				}

				if ($this->addIpRange($start, $end, $branch)) {
					echo "SUCCESS: Added $start - $end for Branch $branch\n";
				} else {
					echo "FAILED: Could not add $start - $end for Branch $branch\n";
				}
			}

			fclose($handle);

		} catch (Exception $e) {
			echo "ERROR: " . $e->getMessage() . "\n";
		}
	}
}

if (php_sapi_name() == "cli") {
	$options = getopt("", ["file:", "verbose", "dry-run", "proxy:", "no-verify-ssl"]);

	if (!isset($options['file'])) {
		echo "Usage: php NashvilleLS2AdminAddIPRanges.php --file=\"../data/file.csv\" [--verbose] [--dry-run]\n";
		exit(1);
	}

	$app = new NashvilleLS2AdminAddIPRanges();
	$app->run(
		$options['file'],
		isset($options['dry-run']),
		isset($options['verbose']),
		$options['proxy'] ?? null,
		!isset($options['no-verify-ssl'])
	);
}