<?php

/**
 * 将 storage/data 中的文件迁移到 MongoDB。
 *
 * 说明：
 * - 常规键值数据 => kv_store（或 mongoDataCollection）
 * - coolDown / coolDownMeta => cooldowns（或 mongoCooldownCollection）
 *
 * 用法：
 * php migrate_data_to_mongo.php
 */

require __DIR__.'/vendor/autoload.php';

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
$cooldownCollectionName = trim((string)($config['mongoCooldownCollection'] ?? 'cooldowns'));
if($cooldownCollectionName === '') $cooldownCollectionName = 'cooldowns';

$client = new MongoDB\Client('mongodb://localhost:'.$dbPort, [
    'appName' => 'BLBotMigration',
    'username' => $dbUsername,
    'password' => $dbPassword,
    'authSource' => 'BLBot',
]);
$db = $client->selectDatabase('BLBot', ['typeMap' => ['array' => 'array', 'document' => 'array', 'root' => 'array']]);
$kvCollection = $db->$kvCollectionName;
$cooldownCollection = $db->$cooldownCollectionName;

$kvCollection->createIndex(['updated_at' => 1], ['background' => true]);
$cooldownCollection->createIndex(['set_at' => 1], ['background' => true]);
$cooldownCollection->createIndex(['updated_at' => 1], ['background' => true]);

$dataRoot = __DIR__.'/storage/data';
if(!is_dir($dataRoot)) {
    fwrite(STDERR, "storage/data 不存在。\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dataRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

$stats = [
    'total_files' => 0,
    'kv_success' => 0,
    'kv_failed' => 0,
    'cooldown_files' => 0,
    'cooldown_success' => 0,
    'cooldown_failed' => 0,
];

$cooldownDurations = [];
$cooldownMeta = [];

foreach($iterator as $fileInfo) {
    if(!$fileInfo->isFile()) continue;

    $absolutePath = $fileInfo->getPathname();
    $relative = substr($absolutePath, strlen($dataRoot) + 1);
    $relative = str_replace('\\', '/', $relative);
    $stats['total_files']++;

    $content = @file_get_contents($absolutePath);
    if($content === false) {
        fwrite(STDERR, "读取失败: {$relative}\n");
        $stats['kv_failed']++;
        continue;
    }

    $mtime = $fileInfo->getMTime();

    if(str_starts_with($relative, 'coolDown/')) {
        $name = substr($relative, strlen('coolDown/'));
        if($name !== '') {
            $cooldownDurations[$name] = [
                'duration' => (int)$content,
                'updated_at' => $mtime,
            ];
            $stats['cooldown_files']++;
        }
        continue;
    }

    if(str_starts_with($relative, 'coolDownMeta/')) {
        $name = substr($relative, strlen('coolDownMeta/'));
        if($name !== '') {
            $setAt = (int)$content;
            if($setAt <= 0) $setAt = $mtime;

            $cooldownMeta[$name] = [
                'set_at' => $setAt,
                'updated_at' => $mtime,
            ];
            $stats['cooldown_files']++;
        }
        continue;
    }

    try {
        $result = $kvCollection->updateOne(
            ['_id' => $relative],
            ['$set' => ['value' => $content, 'updated_at' => new MongoDB\BSON\UTCDateTime($mtime * 1000)]],
            ['upsert' => true],
        );

        if($result->isAcknowledged()) {
            $stats['kv_success']++;
        } else {
            fwrite(STDERR, "KV 写入未确认: {$relative}\n");
            $stats['kv_failed']++;
        }
    } catch(Throwable $e) {
        fwrite(STDERR, "KV 写入失败: {$relative} => {$e->getMessage()}\n");
        $stats['kv_failed']++;
    }
}

$allCooldownNames = array_values(array_unique(array_merge(array_keys($cooldownDurations), array_keys($cooldownMeta))));
foreach($allCooldownNames as $name) {
    $duration = (int)($cooldownDurations[$name]['duration'] ?? 0);
    $setAt = (int)($cooldownMeta[$name]['set_at'] ?? 0);

    if($setAt <= 0) {
        $setAt = (int)($cooldownDurations[$name]['updated_at'] ?? 0);
    }
    if($setAt <= 0) {
        $setAt = (int)($cooldownMeta[$name]['updated_at'] ?? 0);
    }
    if($setAt <= 0) {
        $setAt = time();
    }

    try {
        $result = $cooldownCollection->updateOne(
            ['_id' => $name],
            ['$set' => [
                'duration' => $duration,
                'set_at' => new MongoDB\BSON\UTCDateTime($setAt * 1000),
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ]],
            ['upsert' => true],
        );

        if($result->isAcknowledged()) {
            $stats['cooldown_success']++;
        } else {
            fwrite(STDERR, "Cooldown 写入未确认: {$name}\n");
            $stats['cooldown_failed']++;
        }
    } catch(Throwable $e) {
        fwrite(STDERR, "Cooldown 写入失败: {$name} => {$e->getMessage()}\n");
        $stats['cooldown_failed']++;
    }
}

echo "迁移完成\n";
echo "- 扫描文件数: {$stats['total_files']}\n";
echo "- KV 成功: {$stats['kv_success']}，失败: {$stats['kv_failed']}\n";
echo "- Cooldown 文件识别数: {$stats['cooldown_files']}\n";
echo "- Cooldown 成功: {$stats['cooldown_success']}，失败: {$stats['cooldown_failed']}\n";

if($stats['kv_failed'] > 0 || $stats['cooldown_failed'] > 0) {
    exit(2);
}
