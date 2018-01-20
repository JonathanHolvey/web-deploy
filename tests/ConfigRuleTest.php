<?php
use PHPUnit\Framework\TestCase;
include __dir__ . "/../deploy.php";


class TestWebhook extends Webhook {
	function parse($data) {
		foreach ($data as $key=>$value)
			$this->set($key, $value);
	}
}

function defaults () {
	$configData = [
		"repository"=>"https://test-repository",
		"destination"=>"deploy-test",
		"mode"=>"deploy"
	];
	$hookData = ["repository"=>"https://test-repository"];
	return ["configData"=>$configData, "hookData"=>$hookData];
}


final class ConfigRuleTest extends TestCase {
	function test_validate_forAllRequiredOptions_returnsTrue() {
		extract(defaults());
		$rule = new ConfigRule($configData);
		$this->assertTrue($rule->validate());
	}
	function test_validate_forMissingRequiredOptions_returnsFalse() {
		extract(defaults());
		foreach (ConfigRule::REQUIRED as $option) {
			$data = $configData;
			unset($data[$option]);
			$rule = new ConfigRule($data);
			$this->assertFalse($rule->validate());
		}
		$rule = new ConfigRule([]);
		$this->assertFalse($rule->validate());
	}
	function test_compareTo_forMatchingRepository_returnsTrue() {
		extract(defaults());
		$hook = new TestWebHook($hookData);
		$rule = new ConfigRule($configData);
		$hook->set("repository", "a");
		$rule->set("repository", "a");
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forDifferentRepository_returnsFalse() {
		extract(defaults());
		$hook = new TestWebHook($hookData);
		$rule = new ConfigRule($configData);
		$hook->set("repository", "a");
		$rule->set("repository", "b");
		$this->assertFalse($rule->compareTo($hook));
	}
	function test_compareTo_forMatchingEvent_returnsTrue() {
		extract(defaults());
		$hook = new TestWebHook($hookData);
		$rule = new ConfigRule($configData);
		$hook->set("event", "a");
		$rule->set("events", ["a", "b"]);
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forDifferentEvent_returnsFalse() {
		extract(defaults());
		$hook = new TestWebHook($hookData);
		$rule = new ConfigRule($configData);
		$hook->set("event", "a");
		$rule->set("events", ["b", "c"]);
		$this->assertFalse($rule->compareTo($hook));
	}
	function test_compareTo_forMatchingPreReleases_returnsTrue() {
		extract(defaults());
		$hook = new TestWebHook($hookData);
		$rule = new ConfigRule($configData);
		$hook->set("event", "release");
		$hook->set("pre-release", true);
		$rule->set("pre-releases", true);
		$this->assertTrue($rule->compareTo($hook));
	}
	function test_compareTo_forDifferentPreReleases_returnsFalse() {
		extract(defaults());
		$hook = new TestWebHook($hookData);
		$rule = new ConfigRule($configData);
		$hook->set("event", "release");
		$hook->set("pre-release", true);
		$rule->set("pre-releases", false);
		$this->assertFalse($rule->compareTo($hook));
	}
	function test_compareTo_forPreReleasesWithNonReleaseEvent_returnsTrue() {
		extract(defaults());
		$hook = new TestWebHook($hookData);
		$rule = new ConfigRule($configData);
		$hook->set("event", "push");
		$rule->set("pre-releases", true);
		$this->assertTrue($rule->compareTo($hook));
	}
}
