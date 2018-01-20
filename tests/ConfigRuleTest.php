<?php
use PHPUnit\Framework\TestCase;
include __dir__ . "/../deploy.php";


final class ConfigRuleTest extends TestCase {
	function test_validate_forAllRequiredOptions_returnsTrue() {
		$data = ["repository"=>"https://test-repository", "destination"=>"deploy-test", "mode"=>"deploy"];
		$rule = new ConfigRule($data);
		$this->assertTrue($rule->validate());
	}
	function test_validate_forMissingRequiredOptions_returnsFalse() {
		$required = ["repository"=>"https://test-repository", "destination"=>"deploy-test", "mode"=>"deploy"];
		foreach (ConfigRule::REQUIRED as $option) {
			$data = $required;
			unset($data[$option]);
			$rule = new ConfigRule($data);
			$this->assertFalse($rule->validate());
		}
		$rule = new ConfigRule([]);
		$this->assertFalse($rule->validate());
	}
}
