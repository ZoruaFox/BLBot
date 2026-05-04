<?php

global $Event;

loadModule('fortune.tools');
loadModule('jrrp.tools');

fortuneEnsureCalendarReady();

$userId = (string)$Event['user_id'];
$profile = fortuneLoadProfile($userId);
if(!$profile) {
    replyAndLeave("你还没有设置生日信息，先发送 #fortune.setbirth <日期> [时间] 进行设置。\n例如：#fortune.setbirth 1999-01-01 23:30");
}

$dateYmd = fortuneGetTodayDateYmd();
$cached = fortuneLoadDailyResult($userId, $dateYmd);
if($cached && intval($cached['algo_version'] ?? 0) === fortuneGetAlgoVersion() && !empty($cached['reply'])) {
    replyAndLeave($cached['reply']);
}

$draw = fortuneComputeDraw($userId, $profile, time());
$reply = fortuneBuildReply($draw);

$draw['reply'] = $reply;
$draw['saved_at'] = time();
fortuneSaveDailyResult($userId, $dateYmd, $draw);

replyAndLeave($reply);
