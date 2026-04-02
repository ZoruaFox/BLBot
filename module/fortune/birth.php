<?php

loadModule('fortune.tools');
requireMaster();

$targetArg = nextArg();
if(!$targetArg) {
    replyAndLeave('请提供要查询的用户 QQ 或 At。\n例如：#fortune birth 123456789');
}

$targetUserId = fortuneResolveUserIdArg($targetArg);
if($targetUserId === null) {
    replyAndLeave('参数无效，请使用 QQ 号或 At 目标。');
}

$profile = fortuneLoadProfile($targetUserId);
if(!$profile) {
    replyAndLeave('该用户尚未设置生日信息。');
}

$timeHint = !empty($profile['time_defaulted']) ? '（未提供出生时分秒，已按午时默认）' : '';

replyAndLeave(implode("\n", [
    '用户 '.$targetUserId.' 生日信息：',
    '生日：'.$profile['birth_datetime'].' '.$timeHint,
    '生肖：'.$profile['zodiac'],
    '更新于：'.date('Y-m-d H:i:s', intval($profile['updated_at'] ?? time())),
]));
