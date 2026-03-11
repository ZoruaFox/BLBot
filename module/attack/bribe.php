<?php

global $Event, $Queue;
loadModule('credit.tools');
loadModule('attack.tools');

requireLvl(1);

$status = getStatus($Event['user_id']);
$isMaster = ($Event['user_id'] == config('master'));

if ($isMaster) {
    replyAndLeave('你无法反向追溯自己所在的位面');
}

if ($status === 'free') {
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

$isPrison = ($status === 'imprisoned' || $status === 'confined');
if ($isPrison) {
    $minBribe = (int)config('mysticPrisonBribeMinAmount', 20000);
    $maxBribe = (int)config('mysticPrisonBribeMaxAmount', 80000);
    $baseRate = (int)config('mysticPrisonBribeBaseRate', 20);
} else {
    $minBribe = (int)config('mysticOtherBribeMinAmount', 20000);
    $maxBribe = (int)config('mysticOtherBribeMaxAmount', 100000);
    $baseRate = (int)config('mysticOtherBribeBaseRate', 5);
}

$amount = (int)nextArg();
if ($amount < $minBribe) {
    replyAndLeave("你尝试拿出一些钱，但是什么事也没有发生。");
}

if (getCredit($Event['user_id']) < $amount) {
    replyAndLeave('你好像并没有这么多钱哦…');
}

// probability math: baseRate -> max(100%)
$rateRange = $maxBribe - $minBribe;
if ($rateRange > 0) {
    $rate = $baseRate + ($amount - $minBribe) / $rateRange * (100 - $baseRate);
} else {
    $rate = 100;
}
if ($rate > 100) $rate = 100;

$rand = rand(1, 10000) / 100; // 0.01 to 100.00
$success = ($rand <= $rate);

if ($success) {
    decCredit($Event['user_id'], $amount, true);
    // free the user
    $data = getAttackData($Event['user_id']);
    $data['status'] = 'free';
    setAttackData($Event['user_id'], $data);
    
    $successMessages = [
        'imprisoned' => "你把 {$amount} 金币塞进了牢房门缝。片刻后，牢房门被神秘的力量“咔嗒”一声打开了，你现在自由了！",
        'confined' => "你把 {$amount} 金币放入了饭盒的暗格。禁闭室的灯突然熄灭，借着黑暗你轻易逃脱，现在重获自由了！",
        'hospitalized' => "你将 {$amount} 金币悄悄垫在病床下。你的病在神秘位面的干涉下消失了，你就这么出院了”",
        'arknights' => "你在公司食堂悄悄向小队医生的方向推送了 {$amount} 龙门币。一只有力的手把你直接扔出了舰船，你重回了自由的地球。",
        'genshin' => "你掏出了 {$amount} 金币并且大喊了一声“____，_____！”。这些金币化成了一堆粉色的球飞上天空，在你身边终于围满僵尸之后被一只穿着棕色衣服的柯基送回了地球。",
        'universe' => "你在真空中撒出了 {$amount} 金币。一艘神秘的飞船捕捉到了波动，将你牵引并空投回了地球。",
        'saucer' => "面对外星人，你缴纳了 {$amount} 金币的星际偷渡费。外星人很高兴，用传送光束把你扔回了家，黄金确实是硬通货啊。",
    ];
    $msg = $successMessages[$status] ?? "你献出了 {$amount} 金币，在某种神秘力量的干涉下，你获得了自由！";
    
    replyAndLeave($msg);
} else {
    decCredit($Event['user_id'], $amount, true);
    
    $failMessages = [
        'imprisoned' => "你把 {$amount} 金币塞进了门缝。门外传来了守卫的怒吼：‘竟敢在神秘力量眼皮底下行贿！这钱没收了！’",
        'confined' => "你把 {$amount} 金币塞进了禁闭室的角落。但过了一会钱消失了，你仍在面壁思过。",
        'hospitalized' => "你试图用 {$amount} 金币买通护士，但是护士长没收了这笔钱，并给你来了一发大号镇定剂。",
        'arknights' => "你试图用 {$amount} 龙门币贿赂某个制药公司首席执行官，但是对方并没有理你。’",
        'genshin' => "你甩出了 {$amount} 摩拉买了一堆煎蛋尝试复活，但是你上次煎蛋的冷却还没到，然而你也并没有用煎蛋，只是有人不想让你走而已。",
        'universe' => "你在真空中抛出了 {$amount} 金币。金币化作了宇宙尘埃，你眼睁睁看着它漂远……",
        'saucer' => "外星人拿走了你的 {$amount} 金币，并通过神经链告诉你：‘这点钱只够买张贴纸。’你依然被绑着。",
    ];
    $msg = $failMessages[$status] ?? "你把 {$amount} 金币丢进了虚空，随着金币消失，什么也没有发生。";
    
    replyAndLeave("{$msg}\n损失了 {$amount} 金币，好在状况没有变得更糟。");
}