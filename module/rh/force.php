<?php

global $Event;

requireMaster();
if(!fromGroup()) {
    replyAndLeave('只能在群聊使用哦～');
}

$rhData = getData('rh/group/'.$Event['group_id']);
$rhPlayers = [];
if($rhData) {
    $rhData = json_decode($rhData, true);
    if(isset($rhData['players']) && is_array($rhData['players'])) {
        $rhPlayers = $rhData['players'];
    }
    switch($rhData['status']) {
        case 'initializing':
        case 'starting':
        case 'started':
            replyAndLeave('当前赛马场正在使用中，暂时无法 force 开场哦～');
            break;
        case 'banned':
            replyAndLeave('本群赛马场已被禁用，force 不会绕过封禁状态。');
            break;
    }
}

// Master 强制覆写冷却：群赛马场冷却 + 自身坐骑冷却
coolDown('rh/group/'.$Event['group_id'], 0);
coolDown('rh/user/'.$Event['user_id'], 0);

// 同步清除本场参与者（若有记录）的马匹冷却与锁定，避免冲突残留
foreach($rhPlayers as $player) {
    coolDown('rh/user/'.$player, 0);
    delData('rh/lock/'.$player);
}

sendBackImmediately('[CQ:reply,id='.$Event['message_id'].']【Force】已强制覆写赛马冷却，正在按正常流程开场…');

// 直接开始赛马流程（仍保留金币、锁定、人数等常规校验）
loadModule('rh');
leave();
