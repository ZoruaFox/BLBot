<?php

/**
 * 将 legacy kv_store 中 rh/* 迁移到专用 rh_states 集合。
 *
 * 覆盖范围：
 * - rh/group/* => _id=group:<group_id>
 * - rh/user/* => _id=user:<user_id>
 * - rh/lock/* => _id=lock:<user_id>
 * - rh/force/group/* => _id=force:<group_id>
 *
 * 用法：
 * php migrate_rh_to_collection.php --dry-run
 * php migrate_rh_to_collection.php
 *
 * 说明：
 * - 幂等：固定 _id upsert，可重复执行
 * - 默认不删除 legacy key
 */

$autoloadFile = __DIR__.'/vendor/autoload.php';
if(!file_exists($autoloadFile)) {
    fwrite(STDERR, "未找到 vendor/autoload.php，请先执行 composer install。\n");
    exit(1);
}
require $autoloadFile;

$dryRun = in_array('--dry-run', $argv, true);

$configFile = __DIR__.'/config.ini';
if(!file_exists($configFile)) {
    fwrite(STDERR, "config.ini 不存在，请先复制 config.ini.example 并完成配置。\n");
    exit(1);
}

$config = parse_ini_file($configFile, false);
if($config === false) {
    fwrite(STDERR, "读取 config.ini 失败。\n");
    exit(1);
}

$dbPort = $config['dbPort'] ?? 27017;
$dbUsername = $config['dbUsername'] ?? null;
$dbPassword = $config['dbPassword'] ?? null;
$kvCollectionName = trim((string)($config['mongoDataCollection'] ?? 'kv_store'));
if($kvCollectionName === '') $kvCollectionName = 'kv_store';
$rhCollectionName = trim((string)($config['mongoRhCollection'] ?? 'rh_states'));
if($rhCollectionName === '') $rhCollectionName = 'rh_states';

$client = new MongoDB\Client('mongodb://localhost:'.$dbPort, [
    'appName' => 'BLBotRhMigration',
    'username' => $dbUsername,
    'password' => $dbPassword,
    'authSource' => 'BLBot',
]);
$db = $client->selectDatabase('BLBot', ['typeMap' => ['array' => 'array', 'document' => 'array', 'root' => 'array']]);
$kvCollection = $db->$kvCollectionName;
$rhCollection = $db->$rhCollectionName;

if(!$dryRun) {
    $rhCollection->createIndex(['type' => 1], ['background' => true]);
    $rhCollection->createIndex(['group_id' => 1], ['background' => true]);
    $rhCollection->createIndex(['user_id' => 1], ['background' => true]);
    $rhCollection->createIndex(['updated_at' => 1], ['background' => true]);
}

$stats = [
    'group' => ['scanned' => 0, 'upserted' => 0, 'failed' => 0],
    'user' => ['scanned' => 0, 'upserted' => 0, 'failed' => 0],
    'lock' => ['scanned' => 0, 'upserted' => 0, 'failed' => 0],
    'force' => ['scanned' => 0, 'upserted' => 0, 'failed' => 0],
];

$upsert = static function(array $filter, array $update) use ($rhCollection, $dryRun): bool {
    if($dryRun) {
        return true;
    }

    $result = $rhCollection->updateOne($filter, $update, ['upsert' => true]);
    return $result->isAcknowledged();
};

// rh/group/*
$cursor = $kvCollection->find(
    ['_id' => ['$regex' => '^rh/group/']],
    ['projection' => ['_id' => 1, 'value' => 1]],
);
foreach($cursor as $doc) {
    $stats['group']['scanned']++;
    $id = (string)($doc['_id'] ?? '');
    $groupId = substr($id, strlen('rh/group/'));
    if($groupId === '') continue;

    $state = json_decode((string)($doc['value'] ?? ''), true);
    if(!is_array($state)) $state = [];

    $ok = false;
    try {
        $ok = $upsert(
            ['_id' => 'group:'.$groupId],
            [
                '$set' => [
                    'type' => 'group',
                    'group_id' => (string)$groupId,
                    'state' => $state,
                    'updated_at' => new MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => 'group:'.$groupId],
            ],
        );
    } catch(Throwable $e) {
        $ok = false;
    }

    if($ok) {
        $stats['group']['upserted']++;
        if($dryRun) echo "[dry-run] group {$groupId}\n";
    } else {
        $stats['group']['failed']++;
    }
}

// rh/user/*
$cursor = $kvCollection->find(
    ['_id' => ['$regex' => '^rh/user/']],
    ['projection' => ['_id' => 1, 'value' => 1]],
);
foreach($cursor as $doc) {
    $stats['user']['scanned']++;
    $id = (string)($doc['_id'] ?? '');
    $userId = substr($id, strlen('rh/user/'));
    if($userId === '') continue;

    $data = json_decode((string)($doc['value'] ?? ''), true);
    if(!is_array($data)) $data = [];

    $ok = false;
    try {
        $ok = $upsert(
            ['_id' => 'user:'.$userId],
            [
                '$set' => [
                    'type' => 'user',
                    'user_id' => (string)$userId,
                    'data' => $data,
                    'updated_at' => new MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => 'user:'.$userId],
            ],
        );
    } catch(Throwable $e) {
        $ok = false;
    }

    if($ok) {
        $stats['user']['upserted']++;
        if($dryRun) echo "[dry-run] user {$userId}\n";
    } else {
        $stats['user']['failed']++;
    }
}

// rh/lock/*
$cursor = $kvCollection->find(
    ['_id' => ['$regex' => '^rh/lock/']],
    ['projection' => ['_id' => 1]],
);
foreach($cursor as $doc) {
    $stats['lock']['scanned']++;
    $id = (string)($doc['_id'] ?? '');
    $userId = substr($id, strlen('rh/lock/'));
    if($userId === '') continue;

    $ok = false;
    try {
        $ok = $upsert(
            ['_id' => 'lock:'.$userId],
            [
                '$set' => [
                    'type' => 'lock',
                    'user_id' => (string)$userId,
                    'locked' => true,
                    'updated_at' => new MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => 'lock:'.$userId],
            ],
        );
    } catch(Throwable $e) {
        $ok = false;
    }

    if($ok) {
        $stats['lock']['upserted']++;
        if($dryRun) echo "[dry-run] lock {$userId}\n";
    } else {
        $stats['lock']['failed']++;
    }
}

// rh/force/group/*
$cursor = $kvCollection->find(
    ['_id' => ['$regex' => '^rh/force/group/']],
    ['projection' => ['_id' => 1, 'value' => 1]],
);
foreach($cursor as $doc) {
    $stats['force']['scanned']++;
    $id = (string)($doc['_id'] ?? '');
    $groupId = substr($id, strlen('rh/force/group/'));
    if($groupId === '') continue;

    $expireAt = (int)($doc['value'] ?? 0);

    $ok = false;
    try {
        $ok = $upsert(
            ['_id' => 'force:'.$groupId],
            [
                '$set' => [
                    'type' => 'force',
                    'group_id' => (string)$groupId,
                    'expire_at' => $expireAt,
                    'updated_at' => new MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => 'force:'.$groupId],
            ],
        );
    } catch(Throwable $e) {
        $ok = false;
    }

    if($ok) {
        $stats['force']['upserted']++;
        if($dryRun) echo "[dry-run] force {$groupId} expire_at={$expireAt}\n";
    } else {
        $stats['force']['failed']++;
    }
}

$totalFailed = $stats['group']['failed'] + $stats['user']['failed'] + $stats['lock']['failed'] + $stats['force']['failed'];

echo "迁移完成".($dryRun ? '（dry-run）' : '')."\n";
foreach(['group', 'user', 'lock', 'force'] as $scope) {
    echo "- {$scope}: 扫描 {$stats[$scope]['scanned']} / 成功 {$stats[$scope]['upserted']} / 失败 {$stats[$scope]['failed']}\n";
}
echo "- 幂等说明: 固定 _id upsert，可安全重复执行。\n";

if($totalFailed > 0) {
    exit(2);
}
