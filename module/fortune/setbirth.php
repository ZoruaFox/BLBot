<?php

global $Event;

loadModule('fortune.tools');
fortuneEnsureCalendarReady();

$first = nextArg();
if(!$first) {
    replyAndLeave("请提供生日参数。\n例如：#fortune.setbirth 1999-01-01 23:30");
}

$operator = (string)$Event['user_id'];
$targetUserId = $operator;
$remaining = trim(nextArg(true));
$fullInput = trim($first.' '.$remaining);

$birthInput = $fullInput;
$parts = preg_split('/\s+/u', $fullInput, -1, PREG_SPLIT_NO_EMPTY);

if(count($parts) >= 2) {
    $possibleTarget = fortuneResolveUserIdArg($parts[0]);
    $candidateBirth = trim(implode(' ', array_slice($parts, 1)));

    if($possibleTarget !== null && fortuneParseBirthDateTime($candidateBirth) !== null) {
        if($possibleTarget !== $operator && !isMaster()) {
            replyAndLeave('只有你自己或 Master 可以设置他人生日。');
        }
        $targetUserId = $possibleTarget;
        $birthInput = $candidateBirth;
    }
}

if($birthInput === '') {
    replyAndLeave("请提供生日日期。\n例如：#fortune.setbirth 1999-01-01 23:30");
}

$birth = fortuneParseBirthDateTime($birthInput);
if(!$birth) {
    replyAndLeave('生日格式无法识别。支持示例：1999-01-01、1999/01/01、1999年1月1日、19990101，时间可选如 23:30。');
}

$profile = fortuneCreateProfile($targetUserId, $birth);
if(!fortuneSaveProfile($targetUserId, $profile)) {
    replyAndLeave('生日信息保存失败，请稍后重试。');
}

if($targetUserId === $operator) {
    replyAndLeave('生日信息已保存。为保护隐私，机器人不会回显你的生日明文。');
}

replyAndLeave('已为用户 '.$targetUserId.' 保存生日信息。');
