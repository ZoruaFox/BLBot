<?php

global $Event, $CQ;

if(!fromGroup())replyAndLeave("只能在群聊使用哦～");

$target = nextArg();
if(!$target){
	$magnification = floatval(getData('attack/group/'.$Event['group_id']));
	replyAndLeave("本群当前抢劫倍率：".$magnification.($CQ->getGroupMemberInfo($Event['group_id'], $Event['user_id'])->role == 'member' ? '' : "\n如需更改，可以使用 #attack.config <倍率>"));
}

$isMaster = ($Event['user_id'] == config('master'));

if(!$isMaster && $CQ->getGroupMemberInfo($Event['group_id'], $Event['user_id'])->role == 'member'){
	replyAndLeave("只支持群管理更改抢劫倍率哦～");
}

$cooldownSeconds = (int)config('attackConfigCooldown', 259200);
$cooldownDays = round($cooldownSeconds / 86400, 1);
if(!$isMaster && coolDown("attackConfig/{$Event['group_id']}")<0){
	replyAndLeave("抢劫倍率每 {$cooldownDays} 天只能更改一次～");
}

if(!is_numeric($target)){
	replyAndLeave('倍率只能设置数值哦～');
}
$target = floatval($target);
if($target > 100 || $target < 0.1){
	replyAndLeave('倍率只能设置 [0.1, 100] 中的值哦～');
}else if(!$isMaster && $target > 10 && $CQ->getGroupMemberInfo($Event['group_id'], $Event['user_id'])->role != 'owner'){
	replyAndLeave('高于 10 的抢劫倍率只能由群主设置哦~');
}

setData('attack/group/'.$Event['group_id'], $target);
coolDown("attackConfig/{$Event['group_id']}", $cooldownSeconds);

$replyMsg = "已将本群抢劫倍率设置为 $target ～";
if($isMaster) {
	$replyMsg .= "\n(强制覆写：检测到Master身份)";
}
replyAndLeave($replyMsg);

?>
