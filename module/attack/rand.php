<?php

global $Event, $Queue, $CQ;

requireLvl(1);
loadModule('attack.tools');
loadModule('raffle.tools');
if(!fromGroup()) replyAndLeave('?');

$from = $Event['user_id'];
$target = getRandGroupMember();

if(!$target || !$target['user_id']) {
        replyAndLeave('没有随机到抢劫目标…');
}

$atTarget = validateAttackTarget($from, $target['user_id']);

$message = attack($from, $target['user_id'], $atTarget);
$Queue[] = replyMessage($message);
