<?php

loadModule('rh.common');

global $Event, $Queue;
requireMaster();

$targetGroupId = null;
if(fromGroup()) {
    $targetGroupId = (string)$Event['group_id'];
} else {
    $arg = nextArg();
    if(!$arg || !preg_match('/^\d+$/', (string)$arg)) {
        replyAndLeave('私聊使用时请提供群号：#rh.status <群号>');
    }
    $targetGroupId = (string)$arg;
}

$state = rhGetGroupState($targetGroupId);
$forceExpire = rhGetForceExpire($targetGroupId);
$forceActive = $forceExpire > time();

if(!$state) {
    $msg = "群 {$targetGroupId} 当前无赛马会话。";
    if($forceActive) {
        $msg .= "\nForce: active (剩余 ".max(0, $forceExpire - time())."s)";
    }
    $Queue[] = replyMessage($msg);
    return;
}

$status = (string)($state['status'] ?? 'unknown');
$players = isset($state['players']) && is_array($state['players']) ? $state['players'] : [];
$horse = (string)($state['horse'] ?? '');
$startTime = isset($state['time']) ? (int)$state['time'] : 0;
$elapsed = $startTime > 0 ? (time() - $startTime) : 0;

$msg = "RH 状态（群 {$targetGroupId}）"
    ."\nStatus: {$status}"
    ."\nPlayers: ".count($players)
    .($horse !== '' ? "\nHorse: {$horse}" : '')
    .($startTime > 0 ? "\nStarted At: ".date('Y-m-d H:i:s', $startTime)." (已过 {$elapsed}s)" : '')
    ."\nForce: ".($forceActive ? "active (剩余 ".max(0, $forceExpire - time())."s)" : 'inactive');

if($players !== []) {
    $msg .= "\nPlayer IDs: ".implode(', ', array_map('strval', $players));
}

$Queue[] = replyMessage($msg);

?>