<?php
use PHPUnit\Framework\TestCase;
include_once __dir__ . "/../deploy.php";
include_once __dir__ . "/utils.php";


function deploymentDefaults() {
	$logger = new TestLogger("nul");
	$rule = new ConfigRule([
		"repository"=>"https://test-repository",
		"destination"=>"deploy-test",
		"mode"=>"deploy"
	]);
	$hook = new TestWebhook(["repository"=>"https://test-repository"]);
	return ["logger"=>$logger, "rule"=>$rule, "hook"=>$hook];
}


final class DeploymentTest extends TestCase {
	function test_setup_forNonExistantDestination_createsDirectory() {
		extract(deploymentDefaults());
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["is_dir", "mkdir"])->getMock();
		$deploy->expects($this->once())->method("mkdir");
		$deploy->setup();
	}
	function test_setup_forDestinationNotCreated_returnsFalse() {
		extract(deploymentDefaults());
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["is_dir", "mkdir"])->getMock();
		$this->assertFalse($deploy->setup());
	}
	function test_setup_forDestinationNotWritable_returnsFalse() {
		extract(deploymentDefaults());
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["is_writable"])->getMock();
		$map = [[$rule->get("destination"), false]];
		$deploy->method("is_writable")->will($this->returnValueMap($map));
		$this->assertFalse($deploy->setup());
	}
	function test_setup_forWorkingDirectoryNotWritable_returnsFalse() {
		extract(deploymentDefaults());
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["is_writable"])->getMock();
		$map = [[getcwd(), false]];
		$deploy->method("is_writable")->will($this->returnValueMap($map));
		$this->assertFalse($deploy->setup());
	}
	function test_setup_forProvidedLogLevel_appliedToLogger() {
		extract(deploymentDefaults());
		$rule->set("log-level", "verbose");
		$deploy = new Deployment($rule, $hook, $logger);
		$deploy->setup();
		$this->assertEquals(LOG_VERBOSE, $logger->logLevel);
	}
	function test_setup_forModeDeployAndEmptyDestination_setsModeReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "deploy");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), 0]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$deploy->setup();
		$this->assertEquals("replace", $deploy->deployMode);
	}
	function test_setup_forModeDryRunAndEmptyDestination_setsModeReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "dry-run");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), 0]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$deploy->setup();
		$this->assertEquals("replace", $deploy->deployMode);
	}
	function test_setup_forModeDeployAndNonEmptyDestination_setsModeUpdate() {
		extract(deploymentDefaults());
		$rule->set("mode", "deploy");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), 1]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$deploy->setup();
		$this->assertEquals("replace", $deploy->deployMode);
	}
	function test_setup_forModeDryRunAndNonEmptyDestination_setsModeUpdate() {
		extract(deploymentDefaults());
		$rule->set("mode", "dry-run");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), 1]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$deploy->setup();
		$this->assertEquals("replace", $deploy->deployMode);
	}
	function test_setup_forModeUpdate_setsModeUpdate() {
		extract(deploymentDefaults());
		$rule->set("mode", "update");
		$deploy = new Deployment($rule, $hook, $logger);
		$deploy->setup();
		$this->assertEquals("update", $deploy->deployMode);		
	}
	function test_setup_forModeReplace_setsModeReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "replace");
		$deploy = new Deployment($rule, $hook, $logger);
		$deploy->setup();
		$this->assertEquals("replace", $deploy->deployMode);		
	}
	function test_setup_forForcedDeployment_setsModeReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "update");
		$hook->set("forced", true);
		$deploy = new Deployment($rule, $hook, $logger);
		$deploy->setup();
		$this->assertEquals("replace", $deploy->deployMode);		
	}
}