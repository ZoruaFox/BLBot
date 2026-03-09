<?php

requireLvl(3);
global $Event, $Queue;

$devgroup = config('devgroup');
if(!$devgroup) {
    replyAndLeave('你尚未在 config.ini 中配置 devgroup 哦！');
}

if(!isset($Event['group_id']) || $Event['group_id'] != $devgroup) {
    replyAndLeave('请在 Bot 开发群 ('.$devgroup.') 内使用本指令哦~');
}
$Queue[] = sendMaster("{$Event['user_id']} 申请加群");
$Queue[] = replyMessage('已提交申请，请耐心等候回复哦');
