<?php

global $Event, $Queue;
loadModule('credit.tools');
loadModule('attack.tools');

requireLvl(1);

$status = getStatus($Event['user_id']);
if ($status !== 'imprisoned' && $status !== 'confined') {
    replyAndLeave('你现在又不在监狱里……');
}

$target = nextArg() ?? '';
if(preg_match('/^@/', $target)) {
    replyAndLeave("你尝试拿出一些钱，你要把这些金币交给谁呢……\n(注：复制含有“@”的消息，@ 会失效。可以手动重新 @ 或者直接输入 QQ 号。)");
}
if(!(preg_match('/\d+/', $target, $match) && $match[0] == $target)) {
    $target = parseQQ($target);
}
$target = intval($target);

$master = (int)config('master');
if(!$target) {
    replyAndLeave("你尝试拿出一些钱，但是并不明白该怎么做。");
}
if($target !== $master) {
    replyAndLeave("你尝试拿出一些钱给狱友，但是什么事也没有发生。");
}

$amount = (int)nextArg();
if ($amount < 20000) {
    replyAndLeave("“你尝试拿出一些钱，但是什么事也没有发生。");
}

if (getCredit($Event['user_id']) < $amount) {
    replyAndLeave('你好像并没有这么多钱哦…');
}

// probability math: 20000 -> 5%, 100000 -> 100%
$rate = 5 + ($amount - 20000) / 80000 * 95;
if ($rate > 100) $rate = 100;

$rand = rand(1, 10000) / 100; // 0.01 to 100.00
$success = ($rand <= $rate);

if ($success) {
    decCredit($Event['user_id'], $amount, true);
    // free the user
    $data = getAttackData($Event['user_id']);
    $data['status'] = 'free';
    setAttackData($Event['user_id'], $data);
    replyAndLeave("你把 {$amount} 金币塞进了门缝。牢房门被神秘的力量“咔嗒”一声打开了，你现在自由了！");
} else {
    decCredit($Event['user_id'], $amount, true);
    replyAndLeave("你把 {$amount} 金币塞进了门缝，随着金币消失，什么也没有发生”\n损失了 {$amount} 金币，好在没有发送其他的事情。");
}