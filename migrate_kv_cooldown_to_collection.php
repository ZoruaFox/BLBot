<?php

/**
 * 将 MongoDB kv_store 中的 coolDown / coolDownMeta 迁移到专用 cooldowns 集合。
 *
 * 适用场景：
 * - 已经在 dataBackend=mongo 下运行了一段时间
 * - cooldown 旧数据仍在 kv_store 中
 *
 * 用法：
 * php migrate_kv_cooldown_to_collection.php
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
    'appName' => 'BLBotCooldownMigration',
    'username' => $dbUsername,
    'password' => $dbPassword,
    'authSource' => 'BLBot',
]);
$db = $client->selectDatabase('BLBot', ['typeMap' => ['array' => 'array', 'document' => 'array', 'root' => 'array']]);
$kvCollection = $db->$kvCollectionName;
$cooldownCollection = $db->$cooldownCollectionName;

$cooldownCollection->createIndex(['set_at' => 1], ['background' => true]);
$cooldownCollection->createIndex(['updated_at' => 1], ['background' => true]);

$durations = [];
$meta = [];

$durationCursor = $kvCollection->find(
    ['_id' => ['$regex' => '^coolDown/']],
    ['projection' => ['_id' => 1, 'value' => 1, 'updated_at' => 1]],
);
foreach($durationCursor as $doc) {
    $id = (string)($doc['_id'] ?? '');
    $name = substr($id, strlen('coolDown/'));
    if($name === '') continue;

    $durations[$name] = [
        'duration' => (int)($doc['value'] ?? 0),
        'updated_at' => ($doc['updated_at'] instanceof MongoDB\BSON\UTCDateTime)
            ? (int)$doc['updated_at']->toDateTime()->format('U')
            : 0,
    ];
}

$metaCursor = $kvCollection->find(
    ['_id' => ['$regex' => '^coolDownMeta/']],
    ['projection' => ['_id' => 1, 'value' => 1, 'updated_at' => 1]],
);
foreach($metaCursor as $doc) {
    $id = (string)($doc['_id'] ?? '');
    $name = substr($id, strlen('coolDownMeta/'));
    if($name === '') continue;

    $setAt = (int)($doc['value'] ?? 0);
    $updatedAt = ($doc['updated_at'] instanceof MongoDB\BSON\UTCDateTime)
        ? (int)$doc['updated_at']->toDateTime()->format('U')
        : 0;

    if($setAt <= 0) $setAt = $updatedAt;

    $meta[$name] = [
        'set_at' => $setAt,
        'updated_at' => $updatedAt,
    ];
}

$names = array_values(array_unique(array_merge(array_keys($durations), array_keys($meta))));
$success = 0;
$failed = 0;

foreach($names as $name) {
    $duration = (int)($durations[$name]['duration'] ?? 0);
    $setAt = (int)($meta[$name]['set_at'] ?? 0);

    if($setAt <= 0) {
        $setAt = (int)($durations[$name]['updated_at'] ?? 0);
    }
    if($setAt <= 0) {
        $setAt = (int)($meta[$name]['updated_at'] ?? 0);
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
            $kvCollection->deleteMany(['_id' => ['$in' => ["coolDown/{$name}", "coolDownMeta/{$name}"]]]);
            $success++;
        } else {
            fwrite(STDERR, "写入未确认: {$name}\n");
            $failed++;
        }
    } catch(Throwable $e) {
        fwrite(STDERR, "迁移失败: {$name} => {$e->getMessage()}\n");
        $failed++;
    }
}

echo "迁移完成\n";
echo "- 识别 cooldown 键: ".count($names)."\n";
echo "- 成功: {$success}\n";
echo "- 失败: {$failed}\n";

if($failed > 0) {
    exit(2);
}
