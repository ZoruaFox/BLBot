<?php

global $Event;

loadModule('fortune.tools');

$operator = (string)$Event['user_id'];
$arg = nextArg();
$targetUserId = $operator;

if($arg) {
    $possibleTarget = fortuneResolveUserIdArg($arg);
    if($possibleTarget === null) {
        replyAndLeave('参数无效，请使用 QQ 号或 At 目标。');
    }

    if($possibleTarget !== $operator && !isMaster()) {
        replyAndLeave('只有 Master 可以清除他人的生日信息。');
    }

    $targetUserId = $possibleTarget;
}

$profileExisted = fortuneLoadProfile($targetUserId) !== null;
fortuneDeleteProfile($targetUserId);

$canIterateDaily = getDataBackend() === 'mongo' || is_dir(getDataPath('fortune/daily'));
if($canIterateDaily) {
    $dateFolders = getDataFolderContents('fortune/daily');
    foreach($dateFolders as $dateFolder) {
        delData('fortune/daily/'.$dateFolder.'/'.intval($targetUserId).'.json');
    }
}

if(!$profileExisted) {
    replyAndLeave('未找到可清除的生日信息。');
}

if($targetUserId === $operator) {
    replyAndLeave('你的生日信息与求签缓存已清除。');
}

replyAndLeave('已清除用户 '.$targetUserId.' 的生日信息与求签缓存。');
