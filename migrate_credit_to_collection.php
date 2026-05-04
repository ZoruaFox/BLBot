<?php

/**
 * 将 legacy kv_store 中 credit/* 迁移到专用 credits 集合。
 *
 * 用法：
 * php migrate_credit_to_collection.php --dry-run
 * php migrate_credit_to_collection.php
 *
 * 说明：
 * - 幂等：使用 _id=user_id upsert，可重复执行
 * - 默认不删除 legacy key（credit/*）
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
$creditCollectionName = trim((string)($config['mongoCreditCollection'] ?? 'credits'));
if($creditCollectionName === '') $creditCollectionName = 'credits';

$client = new MongoDB\Client('mongodb://localhost:'.$dbPort, [
    'appName' => 'BLBotCreditMigration',
    'username' => $dbUsername,
    'password' => $dbPassword,
    'authSource' => 'BLBot',
]);
$db = $client->selectDatabase('BLBot', ['typeMap' => ['array' => 'array', 'document' => 'array', 'root' => 'array']]);
$kvCollection = $db->$kvCollectionName;
$creditCollection = $db->$creditCollectionName;

if(!$dryRun) {
    $creditCollection->createIndex(['user_id' => 1], ['background' => true]);
    $creditCollection->createIndex(['updated_at' => 1], ['background' => true]);
}

function tsFromDocValue($value): int {
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

$stats = [
    'scanned' => 0,
    'upserted' => 0,
    'skipped' => 0,
    'failed' => 0,
];

$cursor = $kvCollection->find(
    ['_id' => ['$regex' => '^credit/']],
    ['projection' => ['_id' => 1, 'value' => 1, 'updated_at' => 1]],
);

foreach($cursor as $doc) {
    $stats['scanned']++;

    $legacyId = (string)($doc['_id'] ?? '');
    $userId = substr($legacyId, strlen('credit/'));
    if($userId === '') {
        $stats['skipped']++;
        continue;
    }

    $balance = (int)($doc['value'] ?? 0);
    $updatedAt = tsFromDocValue($doc['updated_at'] ?? null);
    if($updatedAt <= 0) {
        $updatedAt = time();
    }

    if($dryRun) {
        $stats['upserted']++;
        echo "[dry-run] upsert user={$userId} balance={$balance} updated_at={$updatedAt}\n";
        continue;
    }

    try {
        $result = $creditCollection->updateOne(
            ['_id' => (string)$userId],
            [
                '$set' => [
                    'user_id' => (string)$userId,
                    'balance' => $balance,
                    'updated_at' => new MongoDB\BSON\UTCDateTime($updatedAt * 1000),
                ],
                '$setOnInsert' => ['_id' => (string)$userId],
            ],
            ['upsert' => true],
        );

        if($result->isAcknowledged()) {
            $stats['upserted']++;
        } else {
            $stats['failed']++;
            fwrite(STDERR, "写入未确认: user={$userId}\n");
        }
    } catch(Throwable $e) {
        $stats['failed']++;
        fwrite(STDERR, "迁移失败 user={$userId}: {$e->getMessage()}\n");
    }
}

echo "迁移完成".($dryRun ? '（dry-run）' : '')."\n";
echo "- 扫描 legacy credit 键: {$stats['scanned']}\n";
echo "- upsert 成功: {$stats['upserted']}\n";
echo "- 跳过: {$stats['skipped']}\n";
echo "- 失败: {$stats['failed']}\n";
echo "- 幂等说明: 使用固定 _id=user_id upsert，可安全重复执行。\n";

if($stats['failed'] > 0) {
    exit(2);
}
