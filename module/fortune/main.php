<?php

global $Command;
loadModule('fortune.tools');

$sub = nextArg();
if(!$sub) {
    loadModule('fortune.draw');
    return;
}

$subNormalized = mb_strtolower(trim($sub), 'UTF-8');
$map = [
    'draw' => 'draw',
    'qian' => 'draw',
    'divine' => 'draw',
    'fortune' => 'draw',
    '求签' => 'draw',
    '求籤' => 'draw',
    '抽签' => 'draw',
    '抽籤' => 'draw',

    'set' => 'setbirth',
    'setbirth' => 'setbirth',
    'setbirthday' => 'setbirth',
    'birthday' => 'setbirth',
    '设置生日' => 'setbirth',
    '生日' => 'setbirth',
    '设置生辰' => 'setbirth',

    'clear' => 'clearbirth',
    'clearbirth' => 'clearbirth',
    'deletebirth' => 'clearbirth',
    '清除生日' => 'clearbirth',
    '删除生日' => 'clearbirth',

    'showbirth' => 'birth',
    'querybirth' => 'birth',
    'birth' => 'birth',
    'birthinfo' => 'birth',
    '查看生日' => 'birth',
    '生日信息' => 'birth',

    'help' => 'help',
    'h' => 'help',
    '帮助' => 'help',
    '?' => 'help',
];

if(!isset($map[$subNormalized])) {
    replyAndLeave("未知子命令：{$sub}\n\n".fortuneHelpText());
}

loadModule('fortune.'.$map[$subNormalized]);
