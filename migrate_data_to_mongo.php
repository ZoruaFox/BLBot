<?php

/**
 * 将 storage/data 中的文件数据迁移到 MongoDB kv 存储。
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
$collectionName = trim((string)($config['mongoDataCollection'] ?? 'kv_store'));
if($collectionName === '') $collectionName = 'kv_store';

$client = new MongoDB\Client('mongodb://localhost:'.$dbPort, [
    'appName' => 'BLBotMigration',
    'username' => $dbUsername,
    'password' => $dbPassword,
    'authSource' => 'BLBot',
]);
$db = $client->selectDatabase('BLBot', ['typeMap' => ['array' => 'array', 'document' => 'array', 'root' => 'array']]);
$collection = $db->$collectionName;

$dataRoot = __DIR__.'/storage/data';
if(!is_dir($dataRoot)) {
    fwrite(STDERR, "storage/data 不存在。\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dataRoot, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

$total = 0;
$success = 0;
$failed = 0;

foreach($iterator as $fileInfo) {
    if(!$fileInfo->isFile()) continue;

    $absolutePath = $fileInfo->getPathname();
    $relative = substr($absolutePath, strlen($dataRoot) + 1);
    $relative = str_replace('\\', '/', $relative);

    $content = @file_get_contents($absolutePath);
    if($content === false) {
        fwrite(STDERR, "读取失败: {$relative}\n");
        $failed++;
        $total++;
        continue;
    }

    $mtime = $fileInfo->getMTime();
    $utc = new MongoDB\BSON\UTCDateTime($mtime * 1000);

    try {
        $result = $collection->updateOne(
            ['_id' => $relative],
            ['$set' => ['value' => $content, 'updated_at' => $utc]],
            ['upsert' => true],
        );

        if($result->isAcknowledged()) {
            $success++;
        } else {
            fwrite(STDERR, "写入未确认: {$relative}\n");
            $failed++;
        }
    } catch(Throwable $e) {
        fwrite(STDERR, "写入失败: {$relative} => {$e->getMessage()}\n");
        $failed++;
    }

    $total++;
}

echo "迁移完成：总计 {$total}，成功 {$success}，失败 {$failed}\n";
if($failed > 0) {
    exit(2);
}
