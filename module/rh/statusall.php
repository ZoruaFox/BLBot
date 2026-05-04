<?php

loadModule('rh.common');

global $Queue;
requireMaster();

try {
    $collection = rhGetCollection();
} catch(\Throwable $e) {
    replyAndLeave('无法读取 RH 状态集合：'.$e->getMessage());
}

$staleSeconds = max(300, (int)config('rhCleanStaleSeconds', 1800));
$now = time();
$maxRows = 30;

$cursor = $collection->find(
    ['type' => 'group'],
    rhMongoOptions(['projection' => ['group_id' => 1, 'state' => 1, 'updated_at' => 1]]),
);

$rows = [];
$total = 0;
foreach($cursor as $doc) {
    $total++;

    $groupId = (string)($doc['group_id'] ?? '');
    $state = $doc['state'] ?? [];
    if($groupId === '' || !is_array($state)) continue;

    $status = (string)($state['status'] ?? 'unknown');
    $players = isset($state['players']) && is_array($state['players']) ? count($state['players']) : 0;
    $horse = (string)($state['horse'] ?? '');

    $updatedAt = 0;
    if(isset($doc['updated_at']) && $doc['updated_at'] instanceof \MongoDB\BSON\UTCDateTime) {
        $updatedAt = (int)$doc['updated_at']->toDateTime()->format('U');
    }
    $age = $updatedAt > 0 ? ($now - $updatedAt) : -1;
    $staleTag = ($age >= 0 && $age > $staleSeconds && in_array($status, ['initializing', 'starting', 'started'], true)) ? ' STALE' : '';

    $rows[] = [
        'group' => $groupId,
        'status' => $status,
        'players' => $players,
        'horse' => $horse,
        'age' => $age,
        'stale' => $staleTag,
    ];
}

usort($rows, function(array $a, array $b): int {
    return strcmp($a['group'], $b['group']);
});

if($rows === []) {
    $Queue[] = replyMessage('RH 状态总览：当前无群赛场状态文档。');
    return;
}

$msg = "RH 状态总览（group 文档 {$total} 条，展示最多 {$maxRows} 条）";
$displayed = 0;
foreach($rows as $row) {
    if($displayed >= $maxRows) break;
    $ageText = $row['age'] >= 0 ? $row['age'].'s' : 'n/a';
    $horseText = $row['horse'] !== '' ? $row['horse'] : '-';
    $msg .= "\n- {$row['group']} | {$row['status']} | p={$row['players']} | horse={$horseText} | age={$ageText}{$row['stale']}";
    $displayed++;
}

if(count($rows) > $displayed) {
    $msg .= "\n... 其余 ".(count($rows) - $displayed)." 条省略";
}

$Queue[] = replyMessage($msg);

?>