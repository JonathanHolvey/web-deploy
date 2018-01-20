<?php
/**
 * GitHub Web Deploy
 * https://github.com/JonathanHolvey/web-deploy
 * @author Jonathan Holvey
 * @license GPLv3
 * @version 1.2.0-beta
 */

const VERSION_INFO = "GitHub Web Deploy v1.2.0-beta";

const LOG_NONE = 0;
const LOG_BASIC = 1;
const LOG_VERBOSE = 2;


class WebDeploy {
	function __construct($payload, $configs, &$logger) {
		$this->payload = $payload;
		$this->logger = $logger;
		
		$this->files = null;
		$this->config = null;
		$this->destination = null;
		$this->mode = null;
		$this->zipname = null;
		$this->errors = 0;

		$this->verify($configs);
	}

	function deploy() {
		$this->logger->message("Deploying " . substr($this->payload["head_commit"]["id"], 0, 6) . 
				   " (" . basename($this->payload["ref"]) . ") " . "from " . $this->config["repository"]);
		foreach ($this->config["destinations"] as $destination)
			$this->deployTo($destination);
		$this->cleanup();
		if ($this->errors == 0)
			$this->logger->success("Repository deployed successfully in mode '" . $this->config["mode"] . "'");
		else
			$this->logger->error("Repository deployed in mode '" . $this->config["mode"] . "' with "
					  . $this->errors . ($this->errors > 1 ? " errors" : " error"), 500);
	}

	function deployTo($destination) {
		$this->destination = $destination;
		$this->logger->message("Destination: " . $this->destination, LOG_VERBOSE);
		// Set deployment mode for current destination
		if ($this->payload["forced"] === true) {
			$this->logger->message("Forced update - deploying all files");
			$this->mode = "replace";
		}
		elseif (in_array($this->config["mode"], ["deploy", "dry-run"])) {
			if ($this->countNotIgnored($destination) === 0) {
				$this->logger->message("Destination is empty - deploying all files");
				$this->mode = "replace";
			}
			else
				$this->mode = "update";
		}
		else
			$this->mode = $this->config["mode"];

		$this->parseCommits();
		// Download and extract repository
		if (!$this->getRepo())
			$this->logger->error("The zip archive could not be downloaded", 400);
		$zip = new GithubZip;
		if (!$zip->open($this->zipname))
			$this->logger->error("The zip archive could not be opened", 400);
		// Extract modified files
		foreach ($zip->listFiles() as $index => $filename) {
			if (!$this->ignored($filename)) {
				if ($this->mode == "replace" or in_array($filename, $this->files["modified"]))
					$this->writeFile($filename, $zip->getFromIndex($index));
			}
			else
				$this->logger->message("Skipping ignored file " . $filename, LOG_VERBOSE);
		}
		// Delete removed files		
		foreach ($this->files["removed"] as $filename) {
			if (!$this->ignored($filename))
				$this->removeFile($filename);
		}
		$this->destination = null;
		$this->mode = null;
	}

	// Select and verify correct config
	function verify($configs) {
		$required  = ["repository", "destinations", "mode"];
		$filtered = [];
		// Find first matching config
		foreach ($configs as $config) {
			// Check required options are defined
			if (count(array_diff($required, array_keys($config))) !== 0)
				continue;
			// Check repository
			if ($this->payload["repository"]["url"] != $config["repository"])
				continue;		
			// Check webhook event
			if (isset($this->config["events"]) and !in_array($_SERVER["HTTP_X_GITHUB_EVENT"], $this->config["events"])) {
				$filtered[] = "events";
				continue;
			}
			// Check for pre-releases
			if ($_SERVER["HTTP_X_GITHUB_EVENT"] == "release" and $this->payload["release"]["prerelease"] === true) {
				if (isset($this->config["pre-releases"]) and $this->config["pre-releases"] !== true)
					$filtered[] = "pre-releases";
					continue;
			}
			// Check branch name
			if (isset($config["branches"])) {
				$branchMatch = false;
				foreach ($config["branches"] as $branch) {
					if (preg_match("/^" . preg_quote($branch) . "/", basename($this->payload["ref"])))
						$branchMatch = true;
				}
				if (!$branchMatch) {
					$filtered[] = "branches";
					continue;
				}
			}
			$this->config = $config;
			break;
		}
		// Return status if no config is fully matched
		$filtered = array_unique($filtered);
		if ($this->config === null) {
			if (count($filtered) == 0)
				$this->logger->error("The webhook didn't match any deployment config", 401);
			else
				$this->logger->error("The webhook was matched by repo URL, but config filters prevented deployment: " . implode($filtered, ", "), 202);
		}

		// Check for valid mode option
		if (!in_array($this->config["mode"], ["update", "replace", "deploy", "dry-run"]))
			$this->logger->error("The current mode option '" . $this->config["mode"] . "' is invalid", 500);

		// Check and tidy all defined destinations
		foreach ($this->config["destinations"] as $index => $destination) {
			$this->config["destinations"][$index] = rtrim($destination, "/");
			if (!is_dir($destination)) {
				if (!mkdir($destination, 0755, true))
					$this->logger->error("The script can't create the destination directory " . $destination, 500);
			}
			elseif (!is_writable($destination))
				$this->logger->error("The script can't write to the destination directory " . $destination, 500);
		}

		// Check installation directory is writable
		if (!is_writable(dirname(__FILE__)))
			$this->logger->error("The script can't write to the deployment directory " . dirname(__FILE__), 500);

		// Remove trailing slash from repository URL
		$this->config["repository"] = rtrim($this->config["repository"], "/");

		// Set log level
		if (isset($this->config["log-level"]))
			$this->logger->setLogLevel($this->config["log-level"]);
	}

	// Download repository as zip file from GitHub
	function getRepo() {
		$this->zipname = $this->payload["head_commit"]["id"] . ".zip";
		$url = $this->config["repository"] . "/archive/" . $this->zipname;
		return file_put_contents($this->zipname, fopen($url, "r"));
	}

	// Gather file changes from each commit in sequence
	function parseCommits() {
		if (count($this->payload["commits"]) === 0 and $this->payload["forced"] !== true)
			$this->logger->error("No commits were found in the webhook payload", 400);
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

	// Create file from data string
	function writeFile($filename, $data) {
		$filename = $this->destination . "/" . $filename;
		$this->logger->message((file_exists($filename) ? "Replacing " : "Creating ") . "file " . $filename, LOG_VERBOSE);
		if ($this->config["mode"] != "dry-run") {
			if (!is_dir(dirname($filename)))
				mkdir(dirname($filename), 0755, true);
			if (file_put_contents($filename, $data) === false) {
				$this->logger->message("Error writing file " . $filename, LOG_BASIC);
				$this->errors += 1;
			}
		}
	}

	// Remove file and empty directories
	function removeFile($filename) {
		$filename = $this->destination . "/" . $filename;
		if (is_file($filename)) {
			$this->logger->message("Removing file " . $filename, LOG_VERBOSE);
			if ($this->config["mode"] != "dry-run") {
				if (unlink($filename)) {
					// Traverse up file structure removing empty directories
					$path = dirname($filename);
					while ($path != $this->destination and countFiles($path) == 0) {
						rmdir($path);
						$path = dirname($path);
					}
				}
				else {
					$this->logger->message("Error removing file " . $filename, LOG_BASIC);
					$this->errors += 1;
				}
			}
		}
		else
			$this->logger->message("Skipping file " . $filename . " - already removed", LOG_VERBOSE);
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

	// Count the number of non-ignored files in a directory
	function countNotIgnored($path) {
		$count = 0;
		foreach (scandir($path) as $file) {
			if (!in_array($file, [".", ".."]) and !$this->ignored($file))
				$count ++;
		}
		return $count;
	}
}


abstract class Webhook {
	function __construct($data) {
		$this->raw = $data;
		$this->properties = [
			"event"=>null,
			"repository"=>null,
			"branch"=>null,
			"hash"=>null,
			"archive"=>null,
			"commits"=>[],
			"forced"=>false,
			"pre-release"=>null
		];
		$this->parse($data);
	}
	function set($key, $value) {
		$this->properties[$key] = $value;
	}
	function get($key) {
		if (array_key_exists($key, $this->properties))
			return $this->properties[$key];
		else
			return null;
	}
	abstract function parse($data);
}


class GitHubWebhook extends WebHook {
	function parse($data) {
		$this->set("event", $data["event"]);
		$this->set("repository", $data["repository"]["url"]);
		$this->set("branch", basename($data["ref"]));
		$this->set("hash", $data["head_commit"]["id"]);
		$this->set("archive", $this->get("repository") . "/archive/" . $this->get("hash") . ".zip");
		$commits = [];
		foreach ($data["commits"] as $commit)
			$commits[] = [
				"added"=>$commit["added"],
				"removed"=>$commit["removed"],
				"modified"=>$commit["modified"]
			];
		$this->set("commits", $commits);
		if (array_key_exists("forced", $data))
			$this->set("forced", $data["forced"]);
		if (array_key_exists("release", $data))
			$this->set("pre-release", $data["release"]["prerelease"]);
	}
}


class ConfigRule {
	const REQUIRED = ["repository", "destination", "mode"];
	const VALID_MODES = ["update", "replace", "deploy", "dry-run"];
	function __construct($data) {
		$this->options = [
			"branches"=>[],
			"events"=>[],
			"pre-releases"=>false,
			"ignore"=>[],
			"log-level"=>"basic"
		];
		$this->parse($data);
	}
	function parse($data) {
		foreach ($data as $key=>$value)
			$this->set($key, $value);
	}
	function validate() {
		$valid = true;
		if (count($this->options) === 0)
			$valid = false;
		elseif (count(array_diff(self::REQUIRED, array_keys($this->options))) !== 0)
			$valid = false;
		elseif (!in_array($this->get("mode"), self::VALID_MODES))
			$valid = false;
		return $valid;
	}
	function set($key, $value) {
		$this->options[$key] = $value;
	}
	function get($key) {
		if (array_key_exists($key, $this->options))
			return $this->options[$key];
		else
			return null;
	}
	function compareTo($hook) {
		$match = true;
		if ($this->get("repository") !== $hook->get("repository"))
			$match = false;
		elseif (count($this->get("events")) > 0
				&& !in_array($hook->get("event"), $this->get("events")))
			$match = false;
		elseif ($hook->get("event") == "release"
				&& $hook->get("pre-release") === true
				&& $this->get("pre-releases") !== true)
			$match = false;
		elseif (count($this->get("branches")) > 0) {
			foreach ($this->get("branches") as $branch) {
				if (strpos($hook->get("branch"), $branch) !== 0)
					$match = false;
			}
		}
		return $match;
	}
}


class GithubZip extends ZipArchive {
	// List the files found inside a GitHub commit archive root folder
	function listFiles() {
		$root = $this->getNameIndex(0);
		for ($i = 1; $i < $this->numFiles; $i ++) {
			if (substr($this->getNameIndex($i), -1) != "/")  // List files only
				$files[$i] = str_replace($root, "", $this->getNameIndex($i));
		}
		return $files;
	}
}


class Logger {
	function __construct($filename) {
		$this->filename = $filename;
		$this->logLevel = LOG_BASIC;
		$this->statusMessage = VERSION_INFO;
		$this->statusCode = 0;
	}

	// Set logger instance log level
	function setLogLevel($level) {
		if (!is_int($level)) {
			$levels = ["none" => LOG_NONE,
					   "basic" => LOG_BASIC,
					   "verbose" => LOG_VERBOSE];
			if (in_array($level, $levels))
				$level = $levels[$level];
			else
				$level = LOG_BASIC;
		}
		$this->logLevel = $level;
	}

	// Log to file
	function message($message, $level=LOG_BASIC) {
		if ($level <= $this->logLevel and $this->logLevel > LOG_NONE) {
			$prefix = date("c") . "  ";
			$message = str_replace("\n", str_pad("\n", strlen($prefix) + 1), $message);
			file_put_contents($this->filename, $prefix . $message . "\n", FILE_APPEND);		
		}
	}

	// Set error code and message
	function error($message, $code) {
		$this->message("Error: " . $message);
		$this->setStatus($message, $code);
		$this->handleError();
	}

	// Set success code and message
	function success($message) {
		$this->message($message);
		$this->setStatus($message, 200);
	}

	// Store status code and message, to be output in HTML
	function setStatus($message, $code) {
		if ($code > $this->statusCode)
			$this->statusCode = $code;
		$this->statusMessage .= "\n" . $message;
	}

	// Output status code and message
	function sendStatus() {
		http_response_code($this->statusCode);
		echo($this->statusMessage);
	}

	// Quit on error
	function handleError() {
		$this->sendStatus();
		exit;
	}
}


// Count the files in a directory, excluding . and ..
function countFiles($path) {
	return count(array_diff(scandir($path), [".", ".."]));
}


// Run deployment
$logger = new Logger("./deploy.log");
if (in_array("HTTP_X_GITHUB_EVENT", array_keys($_SERVER))) {
	if ($_SERVER["HTTP_X_GITHUB_EVENT"] == "ping")
		$logger->success("Ping received");
	elseif (file_exists("config.json")) {
		$payload = json_decode($_POST["payload"], true);
		$configs =  json_decode(file_get_contents("config.json"), true);
		$deployment = new WebDeploy($payload, $configs, $logger);
		$deployment->deploy();
	}
	else
		$logger->error("Config file not found", 500);
}
$logger->sendStatus();
