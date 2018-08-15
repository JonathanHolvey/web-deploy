<?php
/**
 * GitHub Web Deploy
 * https://github.com/JonathanHolvey/web-deploy
 * @author Jonathan Holvey
 * @license GPLv3
 * @version 2.0.0-beta.1
 */

const VERSION_INFO = "GitHub Web Deploy v2.0.0-beta.1";

const LOG_NONE = 0;
const LOG_BASIC = 1;
const LOG_VERBOSE = 2;
const LOG_DEBUG = 3;


// Class to hold and manage entire deployment config
class WebDeploy {
	function __construct($json, $logger) {
		$this->hook = null;
		$this->logger = $logger;
		$this->rules = [];
		$this->valid = [];
		$this->matched = [];
		$this->results = [];
		$this->parse($json);
	}

	function parse($json) {
		foreach (json_decode($json, true) as $index=>$data) {
			$this->addRule(new ConfigRule($data));
		}
	}

	function deployAll() {
		$this->checkRules();
		if (count($this->matched) > 0) {
			foreach ($this->matched as $index)
				$this->deployRule($index);
			$this->logStatus();
		}
	}

	function deployRule($index) {
		if (!in_array($index, $this->matched))
			return;
		$rule = $this->rules[$index];
		$deploy = new Deployment($rule, $this->hook, $this->logger);
		$deploy->deploy();
		$this->results[$index] = $deploy->result;
		$this->logger->setLogLevel();
	}

	function addRule($rule) {
		$index = count($this->rules);
		$this->rules[$index] = $rule;
		if ($rule->validate() === true)
			$this->valid[] = $index;
	}

	function matchHook($hook) {
		$this->hook = $hook;
		$this->matched = [];
		foreach ($this->valid as $index) {
			$rule = $this->rules[$index];
			if ($rule->compareTo($this->hook) === true)
				$this->matched[] = $index;
		}
	}

	// Log messages if issues were found in config rules
	function checkRules() {
		if (count($this->valid) === 0)
			$this->logger->error("No valid rules were found in the deployment config", 500);
		elseif (count($this->matched) === 0) {
			$this->logger->error("The webhook couldn't be matched against any deployment config", 401);
			foreach ($this->rules as $index=>$rule) {
				if (count($rule->filters) > 0)
					$this->logger->message("Rule $index was filtered by option"
										   . (count($rule->filters) > 1 ? "s" : "")
										   . "'" . implode("', '", $rule->filters) . "'");
			}
		}

	}

	function logStatus($success=true) {
		$matched = count($this->matched);
		$message = "Repository matched with $matched config rule" . ($matched != 1 ? "s" : "") . ":";
		foreach ($this->results as $index=>$result) {
			$mode = $this->rules[$index]->get("mode");
			$dest = $this->rules[$index]->get("destination");
			$message .= "\nRule $index: $result (mode $mode) > $dest";
		}
		if ($success === true)
			$this->logger->setStatus($message);
		else
			$this->logger->setStatus($message, 500);
	}
}


// Base class to hold all webhook properties
abstract class Webhook {
	function __construct($data) {
		$this->raw = $data;
		$this->properties = [
			"event"=>null,
			"repository"=>null,
			"branch"=>null,
			"commit-id"=>null,
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

	function collectChanges() {
		$modified = [];
		$removed = [];
		foreach ($this->properties["commits"] as $commit) {
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
		return ["modified"=>array_unique($modified),
				"removed"=>array_unique($removed)];
	}
}


// Translator for GitHub webhook format
class GitHubWebhook extends WebHook {
	function parse($data) {
		$this->set("event", $data["event"]);
		$this->set("repository", $data["repository"]["url"]);
		$this->set("branch", basename($data["ref"]));
		$this->set("commit-id", $data["head_commit"]["id"]);
		$this->set("archive",  rtrim($this->get("repository"), "/")
				   . "/archive/" . $this->get("commit-id") . ".zip");
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


// Class to hold, validate and match a single config rule
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
		$this->filters = [];
		$this->parse($data);
	}

	// Load all options into array, overriding defaults
	function parse($data) {
		foreach ($data as $key=>$value)
			$this->set($key, $value);
	}

	// Ensure rule can be used for deployment
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
	
	// Attempt to match rule aginst webhook 
	function compareTo($hook) {
		$match = true;
		if ($this->get("repository") !== $hook->get("repository"))
			$match = false;
		elseif (count($this->get("events")) > 0
				&& !in_array($hook->get("event"), $this->get("events"))) {
			$this->filters[] = "events";
			$match = false;
		}
		elseif ($hook->get("event") == "release"
				&& $hook->get("pre-release") === true
				&& $this->get("pre-releases") !== true) {
			$this->filters[] = "pre-releases";
			$match = false;
		}
		else {
			$branchMatch = false;
			foreach ($this->get("branches") as $branch) {
				if (strpos($hook->get("branch"), $branch) === 0)
					$branchMatch = true;
			}
			if (count($this->get("branches")) > 0 && !$branchMatch) {
				$match = false;
				$this->filters[] = "branches";
			}
		}
		return $match;
	}
}


// Class to perform all file changes
class Deployment {
	function __construct($rule, $hook, $logger) {
		$this->rule = $rule;
		$this->hook = $hook;
		$this->logger = $logger;
		$this->deployMode = null;
		$this->errors = 0;
		$this->result = null;
	}

	function deploy() {
		if ($this->setup() !== true)
			return false;
		$allFiles = $this->getMode() == "replace";
		$dryRun = $this->rule->get("mode") == "dry-run";
		if(!$this->deployFiles($allFiles, $dryRun)) {
			$this->result = "failure";
			return false;
		}
		elseif ($this->errors === 0) {
			$this->result = "success";
			$this->logger->message("Repository deployed successfully in mode "
								   . $this->rule->get("mode"));
			return true;
		}
		else {
			$this->result = $this->errors . " error" . ($this->errors != 1 ? "s" : "");
			$this->logger->message("Repository deployed in mode " . $this->rule->get("mode")
								   . " with " . $this->result);
			return false;
		}
	}

	// Prepare to deploy
	function setup() {
		$this->logger->setLogLevel($this->rule->get("log-level"));
		$commitId = substr($this->hook->get("commit-id"), 0, 6);
		$this->logger->message("Deploying $commitId (" . $this->hook->get("branch")
							   . ") from " . $this->hook->get("repository")
							   . "\nDestination: " . $this->rule->get("destination"));
		// Create destination if it doesn't exist
		if (!$this->is_dir($this->rule->get("destination"))) {
			if (!$this->mkdir($this->rule->get("destination"))) {
				$this->logger->error("Error creating destination directory "
									 . $this->rule->get("destination"), 500);
				return false;
			}
		}
		// Check files can be written
		elseif (!$this->is_writable($this->rule->get("destination"))) {
			$this->logger->error("Cannot write to destination directory "
								 . $this->rule->get("destination"), 500);
			return false;
		}
		elseif (!$this->is_writable(getcwd())) {
			$this->logger->error("Cannot write to working directory " . getcwd(), 500);
			return false;			
		}
		return true;
	}

	// Determine the actual deployment mode to use
	function getMode() {
		if ($this->hook->get("forced") === true) {
			$mode = "replace";
			$this->logger->message("Forced update - deploying all files");
		}
		elseif (in_array($this->rule->get("mode"), ["deploy", "dry-run"])) {
			if ($this->countFiles($this->rule->get("destination"), false) === 0) {
				$mode = "replace";
				$this->logger->message("Destination is empty - deploying all files");
			}
			else
				$mode = "update";
		}
		else
			$mode = $this->rule->get("mode");
		return $mode;
	}

	// Extract files according to webhook commit data
	function deployFiles($allFiles=false, $dryRun=false) {
		// Populate arrays $modified and $removed with lists files
		extract($this->hook->collectChanges());
		// Download and extract repository
		$this->logger->message("Fetching repository archive from " . $this->hook->get("archive"), LOG_DEBUG);
		$archive = $this->getArchive($this->hook->get("archive"));
		if ($archive === false) {
			$this->logger->error("The zip archive could not be opened", 500);
			return false;
		}
		$this->logger->message("Modified files: " . implode(", ", $modified), LOG_DEBUG);
		$this->logger->message("Removed files: " . implode(", ", $removed), LOG_DEBUG);
		$this->logger->message("Repository files: " . implode(", ", $archive->listFiles()), LOG_DEBUG);
		// Extract modified files
		foreach ($archive->listFiles() as $index=>$filename) {
			if ($this->isIgnored($filename)) {
				$this->logger->message("Skipping ignored file " . $filename, LOG_VERBOSE);
				continue;
			}
			if ($allFiles !== true && !in_array($filename, $modified))
				continue;
			$this->logger->message("Writing file " . $filename, LOG_VERBOSE);
			if ($dryRun !== true) {
				if ($this->writeFile($filename, $archive->getFromIndex($index)) !== true) {
					$this->logger->message("Error writing file " . $filename, LOG_BASIC);
					$this->errors += 1;
				}
			}
		}
		// Delete removed files		
		foreach ($removed as $filename) {
			if ($this->isIgnored($filename) || !$this->file_exists($filename))
				continue;
			$this->logger->message("Removing file " . $filename, LOG_VERBOSE);
			if ($dryRun !== true) {
				if ($this->removeFile($filename) !== true) {
					$this->logger->message("Error removing file " . $filename, LOG_BASIC);
					$this->errors += 1;
				}
				else
					$this->cleanDirs(dirname($filename));
			}
		}
		// Remove repository archive
		$archive->cleanup();
		return true;
	}

	// Download and open repository from zip file
	function getArchive($url) {
		$filename = basename($url);
		$size = file_put_contents($filename, fopen($url, "r"));
		if ($size > 0 && $size !== false) {
			$zip = new GitArchive;
			if ($zip->open($filename) === true)
				return $zip;
		}
		else {
			unlink($filename);
			return false;
		}
	}

	// Create file from data string
	function writeFile($path, $data) {
		$path = $this->rule->get("destination") . "/" . $path;
		if (!is_dir(dirname($path)))
			mkdir(dirname($path), 0755, true);
		if (file_put_contents($path, $data) !== false)
			return true;
		return false;
	}

	// Remove file
	function removeFile($path) {
		$path = $this->rule->get("destination") . "/" . $path;
		if (is_file($path)) {
			if (unlink($path))
				return true;
			return false;
		}
		return true;
	}

	// Remove empty directories
	function cleanDirs($path) {
		while ($path !== $this->rule->get("destination") and $this->countFiles($path) === 0) {
			rmdir($path);
			$path = dirname($path);
		}
	}

	// Check to see if a file should be ignored
	function isIgnored($filename) {
		// Match by glob pattern
		foreach ($this->rule->get("ignore") as $pattern) {
			if (fnmatch($pattern, $filename))
				return true;
		}
		// Match by path fragments
		$fragments = explode("/", $filename);
		foreach ($fragments as $index=>$fragment) {
			$path = implode("/", array_slice($fragments, 0, $index + 1));
			if (in_array($path, $this->rule->get("ignore")))
				return true;
		}
		return false;
	}

	// Count the number of files in a directory, including ignored if required
	function countFiles($path, $all=true) {
		$count = 0;
		$files = array_diff(scandir($path), [".", ".."]);
		foreach ($files as $file) {
			if ($all || !$this->isIgnored($file))
				$count ++;
		}
		return $count;
	}

	// Wrappers for mocking builtin functions
	function mkdir(...$args) {
		return mkdir(...$args);
	}
	function is_dir(...$args) {
		return is_dir(...$args);
	}
	function is_writable(...$args) {
		return is_dir(...$args);
	}
	function file_exists(...$args) {
		return file_exists(...$args);
	}
}


class GitArchive extends ZipArchive {
	// List the files found inside a GitHub commit archive root folder
	function listFiles() {
		$root = $this->getNameIndex(0);
		for ($i = 1; $i < $this->numFiles; $i ++) {
			if (substr($this->getNameIndex($i), -1) != "/")  // List files only
				$files[$i] = str_replace($root, "", $this->getNameIndex($i));
		}
		return $files;
	}

	// Close and remove zip file
	function cleanup() {
		$file = $this->filename;
		$this->close();
		unlink($file);
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
	function setLogLevel($level=LOG_BASIC) {
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
	}

	// Store status code and message, to be output in HTML
	function setStatus($message, $code=200) {
		if ($code > $this->statusCode)
			$this->statusCode = $code;
		$this->statusMessage .= "\n" . $message;
	}

	// Output status code and message
	function sendStatus() {
		http_response_code($this->statusCode);
		echo($this->statusMessage);
	}
}


if (__FILE__ == get_included_files()[0]) {
	// Run deployment
	$logger = new Logger("./deploy.log");
	if (in_array("HTTP_X_GITHUB_EVENT", array_keys($_SERVER))) {
		if ($_SERVER["HTTP_X_GITHUB_EVENT"] == "ping")
			$logger->success("Ping received");
		elseif (file_exists("config.json")) {
			$hook = new GitHubWebhook(json_decode($_POST["payload"], true));
			$config = new WebDeploy(file_get_contents("config.json"), $logger);
			$config->matchHook($hook);
			$config->deployAll();
		}
		else
			$logger->error("Config file not found", 500);
	}
	$logger->sendStatus();
}
