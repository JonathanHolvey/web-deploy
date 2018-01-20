<?php
use PHPUnit\Framework\TestCase;
include_once __dir__ . "/../deploy.php";
include_once __dir__ . "/utils.php";


function configRuleDefaults () {
	$configData = [
		"repository"=>"https://test-repository",
		"destination"=>"deploy-test",
		"mode"=>"deploy"
	];
	$hook = new TestWebhook(["repository"=>"https://test-repository"]);
	return ["configData"=>$configData, "hook"=>$hook];
}


final class ConfigRuleTest extends TestCase {
	function test_validate_forAllRequiredOptions_returnsTrue() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$this->assertTrue($rule->validate());
	}
	function test_validate_forMissingRequiredOptions_returnsFalse() {
		extract(configRuleDefaults());
		foreach (ConfigRule::REQUIRED as $option) {
			$data = $configData;
			unset($data[$option]);
			$rule = new ConfigRule($data);
			$this->assertFalse($rule->validate());
		}
		$rule = new ConfigRule([]);
		$this->assertFalse($rule->validate());
	}
	function test_validate_forValidMode_returnsTrue (){
		extract(configRuleDefaults());
		foreach (ConfigRule::VALID_MODES as $mode) {
			$rule = new ConfigRule($configData);
			$rule->set("mode", $mode);
			$this->assertTrue($rule->validate());
		}
	}
	function test_validate_forInvalidMode_returnsFalse (){
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$rule->set("mode", null);
		$this->assertFalse($rule->validate());
	}
	function test_compareTo_forMatchingRepository_returnsTrue() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("repository", "a");
		$rule->set("repository", "a");
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forDifferentRepository_returnsFalse() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("repository", "a");
		$rule->set("repository", "b");
		$this->assertFalse($rule->compareTo($hook));
	}
	function test_compareTo_forMatchingEvent_returnsTrue() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("event", "a");
		$rule->set("events", ["b", "a"]);
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forDifferentEvent_returnsFalse() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("event", "a");
		$rule->set("events", ["b", "c"]);
		$this->assertFalse($rule->compareTo($hook));
	}
	function test_compareTo_forMatchingPreReleases_returnsTrue() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("event", "release");
		$hook->set("pre-release", true);
		$rule->set("pre-releases", true);
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forDifferentPreReleases_returnsFalse() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("event", "release");
		$hook->set("pre-release", true);
		$rule->set("pre-releases", false);
		$this->assertFalse($rule->compareTo($hook));
	}
	function test_compareTo_forPreReleasesWithNonReleaseEvent_returnsTrue() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("event", "push");
		$rule->set("pre-releases", true);
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forMatchingBranch_returnsTrue() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("branch", "a");
		$rule->set("branches", ["b", "a"]);
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forMatchingBranchPrefix_returnsTrue() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("branch", "abc");
		$rule->set("branches", ["b", "a"]);
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forDifferentBranch_returnsFalse() {
		extract(configRuleDefaults());
		$rule = new ConfigRule($configData);
		$hook->set("branch", "a");
		$rule->set("branches", ["b", "c"]);
		$this->assertFalse($rule->compareTo($hook));
	}
}
