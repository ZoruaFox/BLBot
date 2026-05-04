<?php

/**
 * 将 checkin 领域数据从 legacy kv_store 迁移到专用 checkins 集合。
 *
 * 目标文档结构：
 * - 用户签到时间：_id = user:<QQ>
 * - 全局统计：_id = stat
 *
 * 用法：
 * php migrate_checkin_to_collection.php --dry-run
 * php migrate_checkin_to_collection.php
 *
 * 说明：
 * - 幂等：使用固定 _id upsert，可重复执行
 * - 默认不删除 legacy kv key（checkin/*, checkinMeta/*, checkin/stat）
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
$checkinCollectionName = trim((string)($config['mongoCheckinCollection'] ?? 'checkins'));
if($checkinCollectionName === '') $checkinCollectionName = 'checkins';

$client = new MongoDB\Client('mongodb://localhost:'.$dbPort, [
    'appName' => 'BLBotCheckinMigration',
    'username' => $dbUsername,
    'password' => $dbPassword,
    'authSource' => 'BLBot',
]);
$db = $client->selectDatabase('BLBot', ['typeMap' => ['array' => 'array', 'document' => 'array', 'root' => 'array']]);
$kvCollection = $db->$kvCollectionName;
$checkinCollection = $db->$checkinCollectionName;

if(!$dryRun) {
    $checkinCollection->createIndex(['user_id' => 1], ['background' => true]);
    $checkinCollection->createIndex(['last_checkin_at' => 1], ['background' => true]);
    $checkinCollection->createIndex(['updated_at' => 1], ['background' => true]);
}

function docTimeToTimestamp($value): int {
    if($value instanceof MongoDB\BSON\UTCDateTime) {
        return (int)$value->toDateTime()->format('U');
    }

    if($value instanceof DateTimeInterface) {
        return (int)$value->format('U');
    }

    if(is_numeric($value)) {
        return (int)$value;
    }

    return 0;
}

function normalizeStat(array $stat, ?int $timestamp = null): array {
    $referenceTime = $timestamp ?? time();
    $today = date('Ymd', $referenceTime);

    $date = (string)($stat['date'] ?? '');
    if(!preg_match('/^\d{8}$/', $date)) {
        $date = $today;
    }

    $checked = max(0, (int)($stat['checked'] ?? 0));

    return [
        'date' => $date,
        'checked' => $checked,
    ];
}

$stats = [
    'existing_user_docs' => 0,
    'legacy_checkinMeta_docs' => 0,
    'legacy_checkin_docs' => 0,
    'users_upserted' => 0,
    'users_failed' => 0,
    'stat_upserted' => 0,
    'stat_failed' => 0,
];

$userCandidates = [];

$existingUserCursor = $checkinCollection->find(
    ['user_id' => ['$exists' => true]],
    ['projection' => ['_id' => 1, 'user_id' => 1, 'last_checkin_at' => 1, 'updated_at' => 1]],
);

foreach($existingUserCursor as $doc) {
    $stats['existing_user_docs']++;

    $userId = trim((string)($doc['user_id'] ?? ''));
    if($userId === '') {
        $id = (string)($doc['_id'] ?? '');
        if(str_starts_with($id, 'user:')) {
            $userId = substr($id, strlen('user:'));
        }
    }

    if($userId === '') continue;

    $timestamp = docTimeToTimestamp($doc['last_checkin_at'] ?? null);
    if($timestamp <= 0) {
        $timestamp = docTimeToTimestamp($doc['updated_at'] ?? null);
    }

    if(!isset($userCandidates[$userId]) || $timestamp > $userCandidates[$userId]) {
        $userCandidates[$userId] = $timestamp;
    }
}

$metaCursor = $kvCollection->find(
    ['_id' => ['$regex' => '^checkinMeta/']],
    ['projection' => ['_id' => 1, 'value' => 1, 'updated_at' => 1]],
);

foreach($metaCursor as $doc) {
    $stats['legacy_checkinMeta_docs']++;

    $id = (string)($doc['_id'] ?? '');
    $userId = substr($id, strlen('checkinMeta/'));
    if($userId === '') continue;

    $timestamp = (int)($doc['value'] ?? 0);
    if($timestamp <= 0) {
        $timestamp = docTimeToTimestamp($doc['updated_at'] ?? null);
    }

    if(!isset($userCandidates[$userId]) || $timestamp > $userCandidates[$userId]) {
        $userCandidates[$userId] = $timestamp;
    }
}

$checkinCursor = $kvCollection->find(
    ['_id' => ['$regex' => '^checkin/']],
    ['projection' => ['_id' => 1, 'updated_at' => 1]],
);

foreach($checkinCursor as $doc) {
    $stats['legacy_checkin_docs']++;

    $id = (string)($doc['_id'] ?? '');
    $userId = substr($id, strlen('checkin/'));
    if($userId === '' || $userId === 'stat') continue;

    $timestamp = docTimeToTimestamp($doc['updated_at'] ?? null);
    if($timestamp <= 0) continue;

    if(!isset($userCandidates[$userId]) || $timestamp > $userCandidates[$userId]) {
        $userCandidates[$userId] = $timestamp;
    }
}

$nowUtc = new MongoDB\BSON\UTCDateTime();

ksort($userCandidates, SORT_NATURAL);
foreach($userCandidates as $userId => $timestamp) {
    if($timestamp <= 0) continue;

    $docId = 'user:'.$userId;

    if($dryRun) {
        $stats['users_upserted']++;
        echo "[dry-run] upsert {$docId} last_checkin_at={$timestamp}\n";
        continue;
    }

    try {
        $result = $checkinCollection->updateOne(
            ['_id' => $docId],
            [
                '$set' => [
                    'user_id' => (string)$userId,
                    'last_checkin_at' => new MongoDB\BSON\UTCDateTime($timestamp * 1000),
                    'updated_at' => $nowUtc,
                ],
                '$setOnInsert' => ['_id' => $docId],
            ],
            ['upsert' => true],
        );

        if($result->isAcknowledged()) {
            $stats['users_upserted']++;
        } else {
            $stats['users_failed']++;
            fwrite(STDERR, "用户文档写入未确认: {$docId}\n");
        }
    } catch(Throwable $e) {
        $stats['users_failed']++;
        fwrite(STDERR, "用户文档迁移失败 {$docId}: {$e->getMessage()}\n");
    }
}

$statCandidates = [];

$existingCanonicalStat = $checkinCollection->findOne(
    ['_id' => 'stat'],
    ['projection' => ['date' => 1, 'checked' => 1, 'updated_at' => 1]],
);
if($existingCanonicalStat) {
    $statCandidates[] = [
        'source' => 'checkins:stat',
        'stat' => normalizeStat($existingCanonicalStat),
        'updated_at' => docTimeToTimestamp($existingCanonicalStat['updated_at'] ?? null),
    ];
}

$legacyStatDoc = $kvCollection->findOne(
    ['_id' => 'checkin/stat'],
    ['projection' => ['value' => 1, 'updated_at' => 1]],
);
if($legacyStatDoc) {
    $legacyStat = [];
    if(isset($legacyStatDoc['value'])) {
        $decoded = json_decode((string)$legacyStatDoc['value'], true);
        if(is_array($decoded)) {
            $legacyStat = $decoded;
        }
    }

    $statCandidates[] = [
        'source' => 'kv:checkin/stat',
        'stat' => normalizeStat($legacyStat),
        'updated_at' => docTimeToTimestamp($legacyStatDoc['updated_at'] ?? null),
    ];
}

$stat = ['date' => date('Ymd'), 'checked' => 0];
if($statCandidates !== []) {
    usort($statCandidates, static function(array $a, array $b): int {
        return ($b['updated_at'] ?? 0) <=> ($a['updated_at'] ?? 0);
    });

    $stat = $statCandidates[0]['stat'];
}

if($dryRun) {
    $stats['stat_upserted'] = 1;
    echo '[dry-run] upsert stat date='.$stat['date'].' checked='.$stat['checked']."\n";
} else {
    try {
        $result = $checkinCollection->updateOne(
            ['_id' => 'stat'],
            [
                '$set' => [
                    'type' => 'stat',
                    'date' => $stat['date'],
                    'checked' => (int)$stat['checked'],
                    'updated_at' => new MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => 'stat'],
            ],
            ['upsert' => true],
        );

        if($result->isAcknowledged()) {
            $stats['stat_upserted'] = 1;
        } else {
            $stats['stat_failed'] = 1;
            fwrite(STDERR, "stat 文档写入未确认\n");
        }
    } catch(Throwable $e) {
        $stats['stat_failed'] = 1;
        fwrite(STDERR, "stat 文档迁移失败: {$e->getMessage()}\n");
    }
}

echo "迁移完成".($dryRun ? '（dry-run）' : '')."\n";
echo "- 现有 checkins 用户文档扫描: {$stats['existing_user_docs']}\n";
echo "- legacy checkinMeta 扫描: {$stats['legacy_checkinMeta_docs']}\n";
echo "- legacy checkin 扫描: {$stats['legacy_checkin_docs']}\n";
echo "- 用户文档 upsert 成功: {$stats['users_upserted']}，失败: {$stats['users_failed']}\n";
echo "- stat 文档 upsert 成功: {$stats['stat_upserted']}，失败: {$stats['stat_failed']}\n";
echo "- 幂等说明: 使用固定 _id（user:<QQ>/stat）进行 upsert，可安全重复执行。\n";

if($stats['users_failed'] > 0 || $stats['stat_failed'] > 0) {
    exit(2);
}
