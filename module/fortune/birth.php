<?php

loadModule('fortune.tools');
requireMaster();
fortuneEnsureCalendarReady();

$targetArg = nextArg();
if(!$targetArg) {
    replyAndLeave("请提供要查询的用户 QQ 或 At。\n例如：#fortune birth 123456789");
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

$debug = fortuneGetProfileCalendarDebug($profile);

$solarDatetime = $debug['solar_datetime'] ?? ($profile['solar_datetime'] ?? $profile['birth_datetime']);
$lunarDate = $debug['lunar_date'] ?? ($profile['birth_lunar_date'] ?? '未知');
$lunarGanzhi = $debug['lunar_ganzhi'] ?? ($profile['birth_lunar_ganzhi'] ?? '未知');
$ganzhiRule = $debug['ganzhi_rule'] ?? ($profile['ganzhi_rule'] ?? fortuneGetGanzhiRuleDescription());
$zodiacLunarYear = $debug['zodiac_lunar_year'] ?? ($profile['zodiac_lunar_year'] ?? ($profile['zodiac'] ?? '未知'));

$bazi = $debug['bazi'] ?? ($profile['birth_bazi'] ?? []);
$baziText = '未知';
if(is_array($bazi) && isset($bazi['year'], $bazi['month'], $bazi['day'], $bazi['time'])) {
    $baziText = $bazi['year'].' '.$bazi['month'].' '.$bazi['day'].' '.$bazi['time'];
}

$wuxing = $debug['wuxing'] ?? ($profile['birth_wuxing'] ?? []);
$wuxingText = (is_array($wuxing) && count($wuxing)) ? implode(' | ', $wuxing) : '未知';

replyAndLeave(implode("\n", [
    '用户 '.$targetUserId.' 生日信息：',
    '出生公历：'.$solarDatetime.' '.$timeHint,
    '出生农历：'.$lunarDate,
    '出生干支：'.$lunarGanzhi,
    '干支口径：'.$ganzhiRule,
    '生肖：'.$zodiacLunarYear,
    '八字：'.$baziText,
    '八字五行：'.$wuxingText,
    '更新于：'.date('Y-m-d H:i:s', intval($profile['updated_at'] ?? time())),
]));
