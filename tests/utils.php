<?php
class TestWebhook extends Webhook {
	function parse($data) {
		foreach ($data as $key=>$value)
			$this->set($key, $value);
	}
}
