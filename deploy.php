<?php

const LOG_NONE = 0;
const LOG_BASIC = 1;
const LOG_VERBOSE = 2;
const LOG_DEBUG = 3;

$logLevel = LOG_BASIC;


class githubWebDeploy {

	function __construct() {
		$this->files = null;
		$this->config = null;
		$this->zipname = null;
		$this->errors = 0;

		$this->payload = json_decode($_POST["payload"], true);
		$this->verify();
	}

	function deploy() {
		logMessage("Deploying " . substr($this->payload["head_commit"]["id"], 0, 6) . 
				   " (" . basename($this->payload["ref"]) . ") " . "from " . $this->config["repository"]);
		$this->parseCommits();
		// Download and extract repository
		if (!$this->getRepo())
			logStatus("The zip archive could not be downloaded", 400);
		$zip = new ZipArchive;
		if (!$zip->open($this->zipname))
			logStatus("The zip archive could not be opened", 400);
		// Extract modified files
		for ($i = 0; $i < $zip->numFiles; $i ++) {
			$source = $zip->getNameIndex($i);
			$filename = preg_replace("/^[^\/]+\//", "", $source);  // Remove zip root folder from paths
			if (preg_match("/^[^\/]+\/(.+)$/", $source) and !$this->ignored($filename)) {
				if ($this->config["mode"] == "replace" or in_array($filename, $this->files["modified"]))
					$this->writeFile($filename, $zip->getFromName($source));
			}
		}
		// Delete removed files		
		foreach ($this->files["removed"] as $filename) {
			if (!$this->ignored($filename))
				$this->removeFile($filename);
		}
		$this->cleanup();
		if ($this->errors == 0)
			logStatus("Repository deployed successfully", 200);
		else
			logStatus("Repository deployed with " . $this->errors . ($this->errors > 1 ? " errors" : " error"), 500);
	}

	// Select and verify correct config
	function verify() {
		$required  = ["repository", "destination", "mode"];
		foreach ($this->loadConfig() as $config) {
			// Check required options are defined in config
			if (count(array_diff($required, array_keys($config))) !== 0)
				continue;
			// Check repository
			if ($this->payload["repository"]["url"] != $config["repository"])
				continue;		
			// Check branch
			if (isset($config["branch"]) and basename($this->payload["ref"]) != $config["branch"])
				continue;
			$this->config = $config;
			break;
		}
		if ($this->config === null)
			logStatus("The payload didn't match the deployment config", 401);
		// Check config contains valid options
		if (!in_array($this->config["mode"], ["update", "replace"]))
			logStatus("The current mode option '" . $this->config["mode"] . "' is invalid", 500);
		if (!is_writable($this->config["destination"]))
			logStatus("The script can't write to the destination directory " . $this->config["destination"], 500);
		if (!is_writable(dirname(__FILE__)))
			logStatus("The script can't write to the deployment directory " . dirname(__FILE__), 500);
		// Remove trailing slashes from paths
		$this->config["repository"] = rtrim($this->config["repository"], "/");
		$this->config["destination"] = rtrim($this->config["destination"], "/");
		// Set global log level
		if (isset($this->config["log-level"]))
			setLogLevel($this->config["log-level"]);
	}

	// Download repository as zip file from GitHub
	function getRepo() {
		$this->zipname = $this->payload["head_commit"]["id"] . ".zip";
		$url = $this->config["repository"] . "/archive/" . $this->zipname;
		return file_put_contents($this->zipname, fopen($url, "r"));
	}

	// Gather file changes from each commit in sequence
	function parseCommits() {
		if (count($this->payload["commits"]) === 0)
			logStatus("No commits were found in the payload", 400);
		$modified = array();
		$removed = array();
		foreach ($this->payload["commits"] as $commit) {
			// List new and modified files
			foreach (array_merge($commit["added"], $commit["modified"]) as $file) {
				$removed = array_diff($removed, [$file]);
				$modified[] = $file;
			}
			// List removed files
			foreach ($commit["removed"] as $file) {
				$modified = array_diff($modified, [$file]);
				$removed[] = $file;
			}
		}
		$this->files = array("modified" => array_unique($modified), "removed" => array_unique($removed));
	}

	// Remove downloaded zip file
	function cleanup() {
		if ($this->zipname != null and is_file($this->zipname)) {
			if (unlink($this->zipname))
				$this->zipname = null;
		}
	}

	// Load deployment config from config.json
	function loadConfig() {
		return json_decode(file_get_contents("config.json"), true);
	}

	// Create file from data string
	function writeFile($filename, $data) {
		$filename = $this->config["destination"] . "/" . $filename;
		logMessage((file_exists($filename) ? "Replacing " : "Creating ") . "file " . $filename, LOG_VERBOSE);
		if (!is_dir(dirname($filename)))
			mkdir(dirname($filename), 0755, true);
		if (file_put_contents($filename, $data) === false) {
			logMessage("Error writing file " . $filename, LOG_BASIC);
			$this->errors += 1;
		}
	}

	// Remove file and empty directories
	function removeFile($filename) {
		$filename = $this->config["destination"] . "/" . $filename;
		if (is_file($filename)) {
			logMessage("Removing file " . $filename, LOG_VERBOSE);
			if (unlink($filename)) {
				// Traverse up file structure removing empty directories
				$path = dirname($filename);
				while ($path != $this->config["destination"] and countFiles($path) == 0) {
					rmdir($path);
					$path = dirname($path);
				}
			}
			else {
				logMessage("Error removing file " . $filename, LOG_BASIC);
				$this->errors += 1;
			}
		}
		else
			logMessage("Skipping file " . $filename . " - already removed", LOG_BASIC);
	}

	// Check to see if a file should be ignored
	function ignored($filename) {
		if (isset($this->config["ignore"])) {
			if (in_array($filename, $this->config["ignore"]))
				return true;
			foreach ($this->config["ignore"] as $pattern) {
				if (fnmatch($pattern, $filename))
					return true;
			}
		}
		return false;
	}
}


// Log to file
function logMessage($message, $level=LOG_BASIC) {
	global $logLevel;
	if ($level <= $logLevel and $logLevel > LOG_NONE) {
		$prefix = date("c") . "  ";
		$message = str_replace("\n", str_pad("\n", strlen($prefix)), $message);
		file_put_contents("./deploy.log", $prefix . $message . "\n", FILE_APPEND);		
	}
}


// Return an HTTP response code and message, and quit
function logStatus($message, $code) {
	if (floor($code / 100) != 2)
		$message = "Error: " . $message;
	logMessage($message);
	http_response_code($code);
	die($message);
}


// Set global log level to integer value
function setLogLevel($level=LOG_BASIC) {
	global $logLevel;
	if (!is_int($level)) {
		$levels = ["none" => LOG_NONE,
				   "basic" => LOG_BASIC,
				   "verbose" => LOG_VERBOSE,
				   "debug" => LOG_DEBUG];
		if (in_array($level, $levels))
			$level = $levels[$level];
		else
			$level = LOG_BASIC;
	}
	$logLevel = $level;
}


// Count the files in a directory, excluding . and ..
function countFiles($path) {
	return count(array_diff(scandir($path), [".", ".."]));
}

if (in_array("HTTP_X_GITHUB_EVENT", array_keys($_SERVER))) {
	if ($_SERVER["HTTP_X_GITHUB_EVENT"] == "ping")
		logStatus("Ping received", 200);
	else {
		$deploy = new githubWebDeploy();
		$deploy->deploy();		
	}
}
