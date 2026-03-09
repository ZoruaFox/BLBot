<?php

if(function_exists('fastcgi_finish_request')) fastcgi_finish_request();

require('init.php');

use kjBot\Frame\Message;

try {
    $listen = config('Listen');
    $whiteListJson = getData('whitelist.json');
    $whiteList = $whiteListJson ? json_decode($whiteListJson, true)['groups'] : null;
    if($whiteList && !in_array($Event['group_id'], $whiteList) && isset($Event['group_id'])) {
        $Queue[] = sendMaster('No access at '.$Event['group_id']);
        $Queue[] = sendDevGroup('No access at '.$Event['group_id']);
        $CQ->setGroupLeave($Event['group_id']);
        exit();
    }

    // 处理用户黑白名单 (来自 config.ini)
    $userListMode = config('userListMode', 'none');
    if($userListMode !== 'none' && isset($Event['user_id'])) {
        $usersStr = config('userListUsers', '');
        $uList = $usersStr === '' ? [] : array_map('trim', explode(',', $usersStr));
        if($userListMode === 'blacklist' && in_array((string)$Event['user_id'], $uList)) {
            exit(); // 黑名单用户直接丢弃
        }
        if($userListMode === 'whitelist' && !in_array((string)$Event['user_id'], $uList)) {
            // 除了master也作为白名单的一员，但如果有明确报错的话
            $master = config('master', '');
            if($Event['user_id'] != $master) {
                exit();
            }
        }
    }

    // 处理群聊黑白名单 (来自 config.ini)，兼容旧版 whitelist.json
    $groupListMode = config('groupListMode', 'none');
    if($groupListMode !== 'none' && isset($Event['group_id'])) {
        $groupsStr = config('groupListGroups', '');
        $gList = $groupsStr === '' ? [] : array_map('trim', explode(',', $groupsStr));
        if($groupListMode === 'blacklist' && in_array((string)$Event['group_id'], $gList)) {
            exit(); // 如果是黑名单则不要响应
        }
        if($groupListMode === 'whitelist' && !in_array((string)$Event['group_id'], $gList)) {
            // 如果是白名单，且不在白名单内，也可以向原版一样退群
            // 但用户可能只指不想响应，所以这里简单 exit 处理
            exit();
        }
    }

    switch($Event['post_type']) {
        case 'message':
        case 'notice':
        case 'request':
        case 'meta_event':
            require($Event['post_type'].'Processor.php');
            break;
        default:
            $Queue[] = sendMaster('Unknown post type '.$Event['post_type'].', Event:'."\n".var_export($_SERVER, true));
    }

    //调试
    if($Debug && $Event['user_id'] == $DebugListen) {
        $Queue[] = sendMaster(var_export($Event, true)."\n\n".var_export($Queue, true));
    }

} catch (\Exception $e) {
    if($e->getMessage()) {
        if(preg_match('/\[CQ:reply,id=(-?\d+?)\]/', $e->getMessage())) {
            $Queue[] = sendBack($e->getMessage(), false, true);
        } else {
            $Queue[] = replyMessage($e->getMessage(), false, true);
        }
    }
}

try {
    //将队列中的消息发出
    foreach($Queue as $msg) {
        if($msg !== null) {
            $MsgSender->send($msg);
        }
    }
} catch (\Exception $e) {
    if($e->getCode() == -11) {
        try {
            $MsgSender0->send($msg);
        } catch (\Exception $e) {
        }
    }
    setData('error.log', var_dump($Event).$e.$e->getCode()."\n", true);
}