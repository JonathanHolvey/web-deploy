<?php
use PHPUnit\Framework\TestCase;
include_once __dir__ . "/../deploy.php";
include_once __dir__ . "/utils.php";


function configDefaults() {
	$logger = new Logger("nul");
	$rule = new ConfigRule([
		"repository"=>"https://test-repository",
		"destination"=>"deploy-test",
		"mode"=>"deploy"
	]);
	$hook = new TestWebhook(["repository"=>"https://test-repository"]);
	return ["logger"=>$logger, "rule"=>$rule, "hook"=>$hook];
}


final class ConfigTest extends TestCase {
	function test_addRule_forValidConfigRules_includedInValidArray() {
		extract(configDefaults());
		$config = new WebDeploy("[]", $logger);
		$config->addRule($rule);
		$this->assertContains(0, $config->valid);
	}
	function test_addRule_forInvalidConfigRules_excludedFromValidArray() {
		extract(configDefaults());
		$config = new WebDeploy("[]", $logger);
		$rule->set("mode", null);
		$config->addRule($rule);
		$this->assertNotContains(0, $config->valid);
	}
	function test_matchHook_forMatchedConfigRules_includedInMatchedArray() {
		extract(configDefaults());
		$config = new WebDeploy("[]", $logger);
		$rule->set("repository", "a");
		$hook->set("repository", "a");
		$config->addRule($rule);
		$config->matchHook($hook);
		$this->assertContains(0, $config->matched);
	}
	function test_matchHook_forUnmatchedConfigRules_excludedFromMatchedArray() {
		extract(configDefaults());
		$config = new WebDeploy("[]", $logger);
		$rule->set("repository", "a");
		$hook->set("repository", "b");
		$config->addRule($rule);
		$config->matchHook($hook);
		$this->assertNotContains(0, $config->matched);
	}
}
