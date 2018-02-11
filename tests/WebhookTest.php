<?php
use PHPUnit\Framework\TestCase;
include_once __dir__ . "/../deploy.php";
include_once __dir__ . "/utils.php";


function webhookDefaults() {
	$hookData = ["repository"=>"https://test-repository"];
	return ["hookData"=>$hookData];
}


final class WebhookTest extends TestCase {
	function test_collectChanges_forAddedFiles_includedInModifiedArray() {
		extract(webhookDefaults());
		$hook = new TestWebhook($hookData);
		$hook->set("commits", [
			[
				"added"=>["a", "b"],
				"modified"=>[],
				"removed"=>[]
			]
		]);
		$this->assertEquals(["a", "b"], $hook->collectChanges()["modified"]);
	}
	function test_collectChanges_forModifiedFiles_includedInModifiedArray() {
		extract(webhookDefaults());
		$hook = new TestWebhook($hookData);
		$hook->set("commits", [
			[
				"added"=>[],
				"modified"=>["a", "b"],
				"removed"=>[]
			]
		]);
		$this->assertEquals(["a", "b"], $hook->collectChanges()["modified"]);
	}
	function test_collectChanges_forRemovedFiles_includedInRemovedArray() {
		extract(webhookDefaults());
		$hook = new TestWebhook($hookData);
		$hook->set("commits", [
			[
				"added"=>[],
				"modified"=>[],
				"removed"=>["a", "b"]
			]
		]);
		$this->assertEquals(["a", "b"], $hook->collectChanges()["removed"]);
	}
	function test_collectChanges_forAddedThenRemovedFiles_includedInRemovedArray() {
		extract(webhookDefaults());
		$hook = new TestWebhook($hookData);
		$hook->set("commits", [
			[
				"added"=>["a", "b"],
				"modified"=>[],
				"removed"=>[]
			],
			[
				"added"=>[],
				"modified"=>[],
				"removed"=>["a", "b"]
			]
		]);
		$this->assertEquals(["a", "b"], $hook->collectChanges()["removed"]);
	}
	function test_collectChanges_forModifiedThenRemovedFiles_includedInRemovedArray() {
		extract(webhookDefaults());
		$hook = new TestWebhook($hookData);
		$hook->set("commits", [
			[
				"added"=>[],
				"modified"=>["a", "b"],
				"removed"=>[]
			],
			[
				"added"=>[],
				"modified"=>[],
				"removed"=>["a", "b"]
			]
		]);
		$this->assertEquals(["a", "b"], $hook->collectChanges()["removed"]);
	}
	function test_collectChanges_forRemovedThenAddedFiles_includedInModifiedArray() {
		extract(webhookDefaults());
		$hook = new TestWebhook($hookData);
		$hook->set("commits", [
			[
				"added"=>[],
				"modified"=>[],
				"removed"=>["a", "b"]
			],
			[
				"added"=>["a", "b"],
				"modified"=>[],
				"removed"=>[]
			]
		]);
		$this->assertEquals(["a", "b"], $hook->collectChanges()["modified"]);
	}
	function test_collectChanges_forComplexCommitList_listsFilesAsExpected() {
		extract(webhookDefaults());
		$hook = new TestWebhook($hookData);
		$hook->set("commits", [
			[
				"added"=>["a", "b", "c", "d"],
				"modified"=>[],
				"removed"=>[]
			],
			[
				"added"=>["e", "f"],
				"modified"=>["a", "c"],
				"removed"=>[]
			],
			[
				"added"=>["g"],
				"modified"=>["b", "f"],
				"removed"=>["a"]
			],
			[
				"added"=>["h"],
				"modified"=>["c", "d", "e", "f"],
				"removed"=>["b"]
			],
			[
				"added"=>["b"],
				"modified"=>["c", "h"],
				"removed"=>["d"]
			],
			[
				"added"=>["i"],
				"modified"=>[],
				"removed"=>["c"]
			]
		]);
		$changes = $hook->collectChanges();
		$this->assertContains("a", $changes["removed"]);
		$this->assertContains("b", $changes["modified"]);
		$this->assertContains("c", $changes["removed"]);
		$this->assertContains("d", $changes["removed"]);
		$this->assertContains("e", $changes["modified"]);
		$this->assertContains("f", $changes["modified"]);
		$this->assertContains("g", $changes["modified"]);
		$this->assertContains("h", $changes["modified"]);
		$this->assertContains("i", $changes["modified"]);
		$this->assertNotContains("a", $changes["modified"]);
		$this->assertNotContains("b", $changes["removed"]);
		$this->assertNotContains("c", $changes["modified"]);
		$this->assertNotContains("d", $changes["modified"]);
		$this->assertNotContains("e", $changes["removed"]);
		$this->assertNotContains("f", $changes["removed"]);
		$this->assertNotContains("g", $changes["removed"]);
		$this->assertNotContains("h", $changes["removed"]);
		$this->assertNotContains("i", $changes["removed"]);
	}
}
