<?php

class githubWebDeploy {
	function __construct() {
		$this->files = null;
		$this->config = null;
		$this->zipname = null;

		$this->payload = json_decode($_POST["payload"], $assoc=true);
		$this->verify();
	}

	function deploy() {
		$this->parseCommits();
		// Download and extract repository
		$zip = new ZipArchive;
		if (!$this->getRepo() or !$zip->open($this->zipname))
			respond("There was a problem opening the zip archive.", 400);
		// Extract modified files
		for ($i = 0; $i < $zip->numFiles; $i ++) {
			$source = $zip->getNameIndex($i);
			$filename = preg_replace("/^[^\/]+\//", "", $source);  // Remove zip root folder from paths
			if (preg_match("/^[^\/]+\/(.+)$/", $source)) {
				if (in_array($filename, $this->files["modified"]) or $this->config["mode"] == "replace")
					$this->writeFile($this->config["destination"] . "/" . $filename, $zip->getFromName($source));
			}
		}
		// Delete removed files		
		foreach ($this->files["removed"] as $filename) {
			$this->removeFile($this->config["destination"] . "/" . $filename);
		}
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
			if (isset($config["branch"]) and end(explode("/", $this->payload["ref"])) != $config["branch"])
				continue;
			$this->config = $config;
			break;
		}
		if ($this->config === null)
			respond("The payload didn't match the deployment config", 401);
		// Check config contains valid options
		elseif (!in_array($this->config["mode"], ["update", "replace"]))
			respond("The current mode option '" . $this->config["mode"] . "' is invalid.", 500);
		// Remove trailing slashes from paths
		$this->config["repository"] = rtrim($this->config["repository"], "/");
		$this->config["destination"] = rtrim($this->config["destination"], "/");
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
			respond("No commits were found in the payload.", 400);
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
		$this->files = array("modified" => $modified, "removed" => $removed);
	}

	// Load deployment config from config.json
	function loadConfig() {
		return json_decode(file_get_contents("config.json"), $assoc=true);
	}

	// Create file from data string
	function writeFile($filename, $data) {
		if (!is_dir(dirname($filename)))
			mkdir(dirname($filename), $mode=0755, $recursive=true);
		file_put_contents($filename, $data);
	}

	// Remove file and empty directories
	function removeFile($filename) {
		if (is_file($filename))
			unlink($filename);
		// Traverse up file structure removing empty directories
		$path = dirname($filename);
		while ($path != $this->config["destination"]) {
			if (count(scandir($path)) > 2)  // Check directory is empty
				break;
			rmdir($path);
			$path = dirname($path);
		}
	}
}

// Return a HTTP response code and message, and quit
function respond($message, $code) {
	http_response_code($code);
	echo($message);
	die();
}

$deploy = new githubWebDeploy();
$deploy->deploy();
