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
	$hookData = [
		"branch"=>"master",
		"repository"=>"https://test-repository",
		"event"=>"push",
	];
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
}
