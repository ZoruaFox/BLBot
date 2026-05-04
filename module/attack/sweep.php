<?php

global $Event, $CQ;

requireMaster();
loadModule('attack.tools');

if(!fromGroup()) {
    replyAndLeave('一键扫荡仅支持在群聊中使用。');
}

$from = $Event['user_id'];
$memberList = $CQ->getGroupMemberList($Event['group_id']);
$targets = [];

foreach($memberList as $member) {
    $target = intval($member->user_id);
    if($target == $from) continue;

    $status = getStatus($target);
    if($status === 'imprisoned' || $status === 'confined') {
        $targets[$target] = [
            'name' => ($member->card ? $member->card : $member->nickname),
        ];
    }
}

if(!count($targets)) {
    replyAndLeave('当前群聊没有可扫荡的在狱人员（监狱/禁闭室）。');
}

$successes = [];
$failedCount = 0;
$totalGain = 0;

foreach($targets as $target => $meta) {
    $before = getCredit($from);

    // 复用既有校验逻辑，确保行为与 #attack 一致
    $atTarget = validateAttackTarget($from, $target);
    $message = attack($from, $target, $atTarget);

    $gain = getCredit($from) - $before;
    if($gain > 0) {
        $successes[] = [
            'name' => $meta['name'],
            'gain' => $gain,
        ];
        $totalGain += $gain;
    } else {
        $failedCount++;
    }
}

$reply = "一键扫荡执行完毕：共处理 ".count($targets)." 名在狱人员。";
if(count($successes)) {
    $reply .= "\n\n成功抢劫名单：";
    foreach($successes as $item) {
        $reply .= "\n- {$item['name']}：获得 {$item['gain']} 金币";
    }
    $reply .= "\n合计获得 {$totalGain} 金币。";
} else {
    $reply .= "\n\n本次未获得金币。";
}

if($failedCount > 0) {
    $reply .= "\n其余 {$failedCount} 名未抢到金币（判定失败或目标余额不足）。";
}

replyAndLeave($reply);
