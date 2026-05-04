<?php

global $Message;

loadModule('rh.common');

if(fromGroup()) {
	$rh = ["赛马", "🐎", "🏇", "🐴", "🦄"];

	$rhData = rhGetGroupState($Event['group_id']);
	if(is_array($rhData) && ($rhData['status'] ?? '') == 'starting') {
		$horse = (string)($rhData['horse'] ?? '');
		if($horse !== '') {
			$rh[] = $horse;
			$rh[] = '赛'.$horse;
		}
	}

	foreach($rh as $word) {
		if($word == $Message) {
			loadModule('rh');
			leave();
		}
	}
}