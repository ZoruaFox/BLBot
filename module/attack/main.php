<?php

global $Event, $Queue, $CQ;

requireLvl(1);
loadModule('attack.tools');

$from = $Event['user_id'];
$target = nextArg() ?? '';
if(preg_match('/^@/', $target)) {
    replyAndLeave("要抢劫谁呢？\n(注：复制含有“@”的消息，@ 会失效。可以手动重新 @ 或者直接输入 QQ 号。)");
}
if(!(preg_match('/\d+/', $target, $match) && $match[0] == $target)) {
    $target = parseQQ($target);
}
$target = intval($target);

$atTarget = validateAttackTarget($from, $target);

$message = attack($from, $target, $atTarget);
$Queue[] = replyMessage($message);
