<?php

global $Event, $CQ, $Queue;
loadModule('randomBan.tools');

$role = $CQ->getGroupMemberInfo($Event['group_id'], $Event['user_id'], true)->role;
$bot = $CQ->getGroupMemberInfo($Event['group_id'], config('bot'), true)->role;

// 权限守卫：不可禁言的场景直接 replyAndLeave 终止
if($role == 'owner' || ($role == 'admin' && $bot == 'member')){
	replyAndLeave("身为".($role == 'owner'?"群主":"管理员")."就可以这样调戏我嘛？");
}
if($bot == 'member'){
	replyAndLeave("Bot 不是管理员呜呜呜");
}
if($bot == 'admin' && $role == 'admin'){
	$randTime = rand(1, 600);
	replyAndLeave("您已被禁…等下，你也是管理？".(rand(0,5)?'':'那要不你就假装被禁言 '.intval($randTime / 60).'分'.($randTime % 60).'秒吧～'));
}

// 仅普通成员可到达此处
$t = randomBan();

// 格式化禁言时长
if($t >= 86400){
	$timeStr = intval($t / 86400).'天';
}else if($t >= 3600){
	$h = intval($t / 3600);
	$m = intval(($t % 3600) / 60);
	$timeStr = $h.'小时'.($m > 0 ? $m.'分' : '');
}else{
	$timeStr = intval($t / 60).'分'.($t % 60).'秒';
}
$Queue[]= replyMessage('您已被禁言 '.$timeStr.'～');

?>
