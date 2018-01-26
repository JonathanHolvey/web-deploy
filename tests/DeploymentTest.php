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
			->setMethods(["is_writable", "mkdir"])->getMock();
		$map = [[$rule->get("destination"), false]];
		$deploy->method("is_writable")->will($this->returnValueMap($map));
		$this->assertFalse($deploy->setup());
	}
	function test_setup_forWorkingDirectoryNotWritable_returnsFalse() {
		extract(deploymentDefaults());
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["is_writable", "mkdir"])->getMock();
		$map = [[getcwd(), false]];
		$deploy->method("is_writable")->will($this->returnValueMap($map));
		$this->assertFalse($deploy->setup());
	}
	function test_setup_forProvidedLogLevel_appliedToLogger() {
		extract(deploymentDefaults());
		$rule->set("log-level", "verbose");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["is_writable", "mkdir"])->getMock();
		$deploy->setup();
		$this->assertEquals(LOG_VERBOSE, $logger->logLevel);
	}
	function test_getMode_forModeDeployAndEmptyDestination_returnsReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "deploy");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), false, 0]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$this->assertEquals("replace", $deploy->getMode());
	}
	function test_getMode_forModeDryRunAndEmptyDestination_returnsReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "dry-run");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), false, 0]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$this->assertEquals("replace", $deploy->getMode());
	}
	function test_getMode_forModeDeployAndNonEmptyDestination_returnsUpdate() {
		extract(deploymentDefaults());
		$rule->set("mode", "deploy");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), false, 1]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$this->assertEquals("update", $deploy->getMode());
	}
	function test_getMode_forModeDryRunAndNonEmptyDestination_returnsUpdate() {
		extract(deploymentDefaults());
		$rule->set("mode", "dry-run");
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["countFiles"])->getMock();
		$map = [[$rule->get("destination"), false, 1]];
		$deploy->method("countFiles")->will($this->returnValueMap($map));
		$this->assertEquals("update", $deploy->getMode());
	}
	function test_getMode_forModeUpdate_returnsUpdate() {
		extract(deploymentDefaults());
		$rule->set("mode", "update");
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertEquals("update", $deploy->getMode());		
	}
	function test_getMode_forModeReplace_returnsReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "replace");
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertEquals("replace", $deploy->getMode());		
	}
	function test_getMode_forForcedDeployment_returnsReplace() {
		extract(deploymentDefaults());
		$rule->set("mode", "update");
		$hook->set("forced", true);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertEquals("replace", $deploy->getMode());		
	}
	function test_deployFiles_forProblemZipFile_returnsFalse() {
		extract(deploymentDefaults());
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["getArchive"])->getMock();
		$deploy->method("getArchive")->willReturn(false);
		$this->assertFalse($deploy->deployFiles("replace"));
	}
	function test_deployFiles_forAllFilesTrue_deploysAllFiles() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>["a"], "modified"=>[], "removed"=>[]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["a", "b", "c"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->expects($this->exactly(3))->method("writeFile")
			->withConsecutive(
				[$this->equalTo("a")],
				[$this->equalTo("b")],
				[$this->equalTo("c")]
			);
		$deploy->deployFiles(true);
	}
	function test_deployFiles_forallFilesFalse_deploysNewFiles() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>["a", "b"], "modified"=>[], "removed"=>[]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["a", "b", "c"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->expects($this->exactly(2))->method("writeFile")
			->withConsecutive(
				[$this->equalTo("a")],
				[$this->equalTo("b")]
			);
		$deploy->deployFiles(false);
	}
	function test_deployFiles_forallFilesFalse_deploysModifiedFiles() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>[], "modified"=>["a", "b"], "removed"=>[]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["a", "b", "c"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->expects($this->exactly(2))->method("writeFile")
			->withConsecutive(
				[$this->equalTo("a")],
				[$this->equalTo("b")]
			);
		$deploy->deployFiles(false);
	}
	function test_deployFiles_forallFilesFalse_deletesRemovedFiles() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>[], "modified"=>[], "removed"=>["a", "b"]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["c"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive", "file_exists"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->method("file_exists")->willReturn(true);
		$deploy->expects($this->exactly(2))->method("removeFile")
			->withConsecutive(
				[$this->equalTo("a")],
				[$this->equalTo("b")]
			);
		$deploy->deployFiles(false);
	}
	function test_deployFiles_forIgnoredFilesModified_doesNotDeployFiles() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a", "b"]);
		$hook->set("commits", [["added"=>[], "modified"=>["a", "b", "c"], "removed"=>[]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["a", "b", "c"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->expects($this->exactly(1))->method("writeFile")
			->withConsecutive(
				[$this->equalTo("c")]
			);
		$deploy->deployFiles(true);
	}
	function test_deployFiles_forIgnoredFilesRemoved_doesNotDeleteFiles() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a", "b"]);
		$hook->set("commits", [["added"=>[], "modified"=>[], "removed"=>["a", "b", "c"]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn([]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive", "file_exists"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->method("file_exists")->willReturn(true);
		$deploy->expects($this->exactly(1))->method("removeFile")
			->withConsecutive(
				[$this->equalTo("c")]
			);
		$deploy->deployFiles(true);
	}
	function test_deployFiles_forDryRunMode_doesNotDeployFiles() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>["a", "b"], "modified"=>["c"], "removed"=>[]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["a", "b", "c"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->expects($this->never())->method("writeFile");
		$deploy->deployFiles(true, true);
	}
	function test_deployFiles_forDryRunMode_doesNotDeleteFiles() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>[], "modified"=>[], "removed"=>["a", "b"]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["c"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->expects($this->never())->method("removeFile");
		$deploy->deployFiles(true, true);
	}
	function test_deployFiles_forDryRunMode_doesNotCreateDirectories() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>["a/b", "c/d"], "modified"=>[], "removed"=>[]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn(["a/b", "c/d"]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["writeFile", "removeFile", "getArchive", "mkdir"])->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->expects($this->never())->method("mkdir");
		$deploy->deployFiles(true, true);
	}
	function test_deployFiles_forEmptyDirectoryWhenContentsRemoved_deletesDirectory() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>[""], "modified"=>[], "removed"=>["a/b", "c/d"]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn([]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["removeFile", "getArchive", "cleanDirs", "countFiles", "file_exists"])
			->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->method("countFiles")->willReturn(0);
		$deploy->method("removeFile")->willReturn(true);
		$deploy->method("file_exists")->willReturn(true);
		$deploy->expects($this->exactly(2))->method("cleanDirs")
			->withConsecutive(
				[$this->equalTo("a")],
				[$this->equalTo("c")]
			);
		$deploy->deployFiles(true);
	}
	function test_deployFiles_forEmptyDirectoryWhenContentsNotRemoved_doesNotDeleteDirectory() {
		extract(deploymentDefaults());
		$hook->set("commits", [["added"=>[""], "modified"=>[], "removed"=>["a/b", "c/d", "e,f"]]]);
		$zip = $this->getMockBuilder("GitHubZip")
			->setMethods(["listFiles", "getFromIndex"])->getMock();
		$zip->method("listFiles")->willReturn([]);
		$deploy = $this->getMockBuilder("Deployment")
			->setConstructorArgs([$rule, $hook, $logger])
			->setMethods(["removeFile", "getArchive", "cleanDirs", "countFiles", "file_exists"])
			->getMock();
		$deploy->method("getArchive")->willReturn($zip);
		$deploy->method("countFiles")->willReturn(0);
		$deploy->method("removeFile")->willReturn(true);
		$map = [["a/b", true], ["c/d", false], ["e/f", false]];
		$deploy->method("file_exists")->willReturnMap($map);
		$deploy->expects($this->exactly(1))->method("cleanDirs")
			->withConsecutive(
				[$this->equalTo("a")]
			);
		$deploy->deployFiles(true);
	}
	function test_isIgnored_forIgnoredPatternMatchesFileInDestinationRoot_returnsTrue() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertTrue($deploy->isIgnored("a"));
	}
	function test_isIgnored_forIgnoredPatternDoesNotMatchFileInDestinationRoot_returnsFalse() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertFalse($deploy->isIgnored("b"));
	}
	function test_isIgnored_forIgnoredPatternMatchesDirectoryInDestinationRoot_returnsTrue() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertTrue($deploy->isIgnored("a/b"));
	}
	function test_isIgnored_forIgnoredPatternDoesNotMatchDirectoryInDestinationRoot_returnsFalse() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertFalse($deploy->isIgnored("b/b"));
	}
	function test_isIgnored_forIgnoredPatternMatchesFileInSubdirectory_returnsTrue() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a/b"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertTrue($deploy->isIgnored("a/b"));
	}
	function test_isIgnored_forIgnoredPatternDoesNotMatchFileInSubdirectory_returnsFalse() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a/b"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertFalse($deploy->isIgnored("b/b"));
	}
	function test_isIgnored_forIgnoredPatternDoesNotMatchSubdirectory_returnsFalse() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a/b"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertFalse($deploy->isIgnored("b/b/c"));
	}
	function test_isIgnored_forIgnoredPatternMatchesWildcards_returnsTrue() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a*", "b/*"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertTrue($deploy->isIgnored("ab"));		
		$this->assertTrue($deploy->isIgnored("a/b"));		
		$this->assertTrue($deploy->isIgnored("b/b"));		
		$this->assertTrue($deploy->isIgnored("b/b/c"));		
	}
	function test_isIgnored_forIgnoredPatternDoesNotMatchWildcards_returnsFalse() {
		extract(deploymentDefaults());
		$rule->set("ignore", ["a*", "b/*"]);
		$deploy = new Deployment($rule, $hook, $logger);
		$this->assertFalse($deploy->isIgnored("bb"));		
		$this->assertFalse($deploy->isIgnored("c"));		
	}
}
