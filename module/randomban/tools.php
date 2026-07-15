<?php

function randomBan($maxTime = 600){
	global $Event, $CQ;

	$role = $CQ->getGroupMemberInfo($Event['group_id'], $Event['user_id'], true)->role;
	$bot = $CQ->getGroupMemberInfo($Event['group_id'], config('bot'), true)->role;
	if($role == 'owner' || ($role == 'admin' && $bot == 'member')){
		return false;
	}else if($bot == 'member'){
		return false;
	}else if($bot == 'admin' && $role == 'admin'){
		return false;
	}

	// 加权随机禁言时长
	$roll = rand(1, 1000);
	if($roll == 1){                      // 0.1% 概率
		$time = 86400;                   // 禁言 1 天
	}else if($roll <= 11){               // 1% 概率
		$time = 43200;                   // 禁言 12 小时
	}else if($roll <= 31){               // 2% 概率
		$time = 21600;                   // 禁言 6 小时
	}else if($roll <= 81){               // 5% 概率
		$time = 3600;                    // 禁言 1 小时
	}else{                               // 91.9% 概率
		$time = rand(1, $maxTime);       // 普通 1~600 秒
	}

	$CQ->setGroupBan($Event['group_id'], $Event['user_id'], $time);
	return $time;
}

?>
