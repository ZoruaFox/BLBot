<?php

loadModule('rh.common');

global $Event, $Queue;
requireAdmin();

$groupId = (string)$Event['group_id'];
$now = time();
$staleSeconds = max(300, (int)config('rhCleanStaleSeconds', 1800));

$stats = [
    'current_group_deleted' => 0,
    'current_force_cleared' => 0,
    'current_players_unlocked' => 0,
    'current_players_cd_reset' => 0,
    'stale_groups_deleted' => 0,
    'stale_players_unlocked' => 0,
    'stale_players_cd_reset' => 0,
    'expired_force_cleared' => 0,
    'orphan_locks_cleared' => 0,
    'errors' => [],
];

$playersToUnlock = [];
$activePlayers = [];

try {
    $currentGroupState = rhGetGroupState($groupId);
    if(is_array($currentGroupState) && isset($currentGroupState['players']) && is_array($currentGroupState['players'])) {
        foreach($currentGroupState['players'] as $player) {
            $playersToUnlock[(string)$player] = true;
        }
    }

    if(rhDeleteGroupState($groupId)) {
        $stats['current_group_deleted'] = 1;
    }
    if(rhClearForce($groupId)) {
        $stats['current_force_cleared'] = 1;
    }

    // 清理本群冷却
    coolDown('rh/group/'.$groupId, 0);
} catch(\Throwable $e) {
    $stats['errors'][] = '当前群清理失败: '.$e->getMessage();
}

try {
    $collection = rhGetCollection();

    // 收集活动赛场玩家（避免误删正常锁）并处理僵尸赛场
    $groupCursor = $collection->find(
        ['type' => 'group'],
        rhMongoOptions(['projection' => ['group_id' => 1, 'state' => 1, 'updated_at' => 1]]),
    );

    foreach($groupCursor as $doc) {
        $docGroupId = (string)($doc['group_id'] ?? '');
        $state = $doc['state'] ?? null;
        if(!is_array($state)) continue;

        $status = (string)($state['status'] ?? '');
        $players = isset($state['players']) && is_array($state['players']) ? $state['players'] : [];

        if(in_array($status, ['initializing', 'starting', 'started'], true)) {
            foreach($players as $player) {
                $activePlayers[(string)$player] = true;
            }
        }

        if($docGroupId === $groupId) {
            continue; // 当前群已在上方强制清理
        }

        if(!in_array($status, ['initializing', 'starting', 'started'], true)) {
            continue;
        }

        $updatedAt = 0;
        if(isset($doc['updated_at']) && $doc['updated_at'] instanceof \MongoDB\BSON\UTCDateTime) {
            $updatedAt = (int)$doc['updated_at']->toDateTime()->format('U');
        }

        if($updatedAt > 0 && ($now - $updatedAt) <= $staleSeconds) {
            continue;
        }

        // 认定为僵尸赛场，执行全量收口
        foreach($players as $player) {
            $playerId = (string)$player;
            if($playerId === '') continue;

            if(rhUnlockHorse($playerId)) {
                $stats['stale_players_unlocked']++;
            }
            coolDown('rh/user/'.$playerId, 0);
            $stats['stale_players_cd_reset']++;
            unset($activePlayers[$playerId]);
        }

        coolDown('rh/group/'.$docGroupId, 0);
        rhClearForce($docGroupId);

        if(rhDeleteGroupState($docGroupId)) {
            $stats['stale_groups_deleted']++;
        }
    }

    // 清理过期 force 文档
    $forceCursor = $collection->find(
        ['type' => 'force'],
        rhMongoOptions(['projection' => ['group_id' => 1, 'expire_at' => 1]]),
    );
    foreach($forceCursor as $doc) {
        $docGroupId = (string)($doc['group_id'] ?? '');
        $expireAt = (int)($doc['expire_at'] ?? 0);
        if($docGroupId === '' || $expireAt > $now) continue;

        if(rhClearForce($docGroupId)) {
            $stats['expired_force_cleared']++;
        }
    }

    // 清理孤儿锁（不属于任何活跃赛场玩家）
    $lockCursor = $collection->find(
        ['type' => 'lock'],
        rhMongoOptions(['projection' => ['user_id' => 1]]),
    );
    foreach($lockCursor as $doc) {
        $userId = (string)($doc['user_id'] ?? '');
        if($userId === '') continue;

        if(isset($playersToUnlock[$userId])) {
            continue;
        }
        if(isset($activePlayers[$userId])) {
            continue;
        }

        if(rhUnlockHorse($userId)) {
            $stats['orphan_locks_cleared']++;
        }
        coolDown('rh/user/'.$userId, 0);
    }
} catch(\Throwable $e) {
    $stats['errors'][] = '全局僵尸状态清理失败: '.$e->getMessage();
}

foreach(array_keys($playersToUnlock) as $playerId) {
    if(rhUnlockHorse($playerId)) {
        $stats['current_players_unlocked']++;
    }
    coolDown('rh/user/'.$playerId, 0);
    $stats['current_players_cd_reset']++;
}

$msg = "RH 清理完成\n"
    ."- 当前群状态清理: ".($stats['current_group_deleted'] ? 'yes' : 'no')."\n"
    ."- 当前群 force 清理: ".($stats['current_force_cleared'] ? 'yes' : 'no')."\n"
    ."- 当前群玩家解锁/重置冷却: {$stats['current_players_unlocked']}/{$stats['current_players_cd_reset']}\n"
    ."- 僵尸赛场清理: {$stats['stale_groups_deleted']}\n"
    ."- 僵尸赛场玩家解锁/重置冷却: {$stats['stale_players_unlocked']}/{$stats['stale_players_cd_reset']}\n"
    ."- 过期 force 清理: {$stats['expired_force_cleared']}\n"
    ."- 孤儿锁清理: {$stats['orphan_locks_cleared']}\n"
    ."- 僵尸判定阈值: {$staleSeconds}s";

if($stats['errors'] !== []) {
    $msg .= "\n\n告警：\n- ".implode("\n- ", $stats['errors']);
}

$Queue[] = replyMessage($msg);

?>
