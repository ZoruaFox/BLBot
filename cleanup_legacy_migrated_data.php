<?php

/**
 * Mongo 迁移收口清理脚本：清理已完成领域迁移后遗留的 legacy kv/file 数据。
 *
 * 覆盖范围：
 * - coolDown/*, coolDownMeta/*
 * - checkin/*, checkinMeta/*, checkin/stat
 * - credit/*（不含 credit.history）
 * - attack/user/*（不含 attack/group/*）
 *
 * 用法：
 * php cleanup_legacy_migrated_data.php --dry-run
 * php cleanup_legacy_migrated_data.php
 * php cleanup_legacy_migrated_data.php --include-file-storage
 * php cleanup_legacy_migrated_data.php --force
 *
 * 说明：
 * - 默认 dry-run=false（直接执行）。建议先加 --dry-run 预览。
 * - 若检测到 dual-write 仍开启，会给出警告并终止；可用 --force 忽略。
 */

$autoloadFile = __DIR__.'/vendor/autoload.php';
if(!file_exists($autoloadFile)) {
    fwrite(STDERR, "未找到 vendor/autoload.php，请先执行 composer install。\n");
    exit(1);
}
require $autoloadFile;

$dryRun = in_array('--dry-run', $argv, true);
$force = in_array('--force', $argv, true);
$includeFileStorage = in_array('--include-file-storage', $argv, true);

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

$toBool = static function($value, bool $default = false): bool {
    if(is_bool($value)) return $value;
    if(is_int($value)) return $value !== 0;
    if(is_string($value)) {
        $normalized = strtolower(trim($value));
        if(in_array($normalized, ['1', 'true', 'yes', 'on'], true)) return true;
        if(in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) return false;
    }

    return $default;
};

$dataBackend = strtolower(trim((string)($config['dataBackend'] ?? 'file')));
if($dataBackend !== 'mongo') {
    fwrite(STDERR, "当前 dataBackend={$dataBackend}，该清理脚本仅建议在 mongo 后端使用。\n");
    exit(1);
}

$checkinDualWrite = $toBool($config['checkinCollectionDualWrite'] ?? true, true);
$attackDualWrite = $toBool($config['attackCollectionDualWrite'] ?? true, true);

if(($checkinDualWrite || $attackDualWrite) && !$force) {
    fwrite(STDERR, "检测到 dual-write 仍开启：\n");
    if($checkinDualWrite) fwrite(STDERR, "- checkinCollectionDualWrite=true\n");
    if($attackDualWrite) fwrite(STDERR, "- attackCollectionDualWrite=true\n");
    fwrite(STDERR, "请先关闭 dual-write 后再清理，或使用 --force 强制执行。\n");
    exit(1);
}

$dbPort = $config['dbPort'] ?? 27017;
$dbUsername = $config['dbUsername'] ?? null;
$dbPassword = $config['dbPassword'] ?? null;
$kvCollectionName = trim((string)($config['mongoDataCollection'] ?? 'kv_store'));
if($kvCollectionName === '') $kvCollectionName = 'kv_store';

$client = new MongoDB\Client('mongodb://localhost:'.$dbPort, [
    'appName' => 'BLBotLegacyCleanup',
    'username' => $dbUsername,
    'password' => $dbPassword,
    'authSource' => 'BLBot',
]);
$db = $client->selectDatabase('BLBot');
$kvCollection = $db->$kvCollectionName;

$filters = [
    ['_id' => ['$regex' => '^coolDown/']],
    ['_id' => ['$regex' => '^coolDownMeta/']],
    ['_id' => ['$regex' => '^checkin/']],
    ['_id' => ['$regex' => '^checkinMeta/']],
    ['_id' => ['$regex' => '^credit/']],
    ['_id' => ['$regex' => '^attack/user/']],
    ['_id' => 'checkin/stat'],
];

$query = ['$or' => $filters];

$legacyIds = [];
$cursor = $kvCollection->find($query, ['projection' => ['_id' => 1]]);
foreach($cursor as $doc) {
    $id = (string)($doc['_id'] ?? '');
    if($id === '') continue;
    $legacyIds[$id] = $id;
}
$legacyIds = array_values($legacyIds);

$stats = [
    'kv_found' => count($legacyIds),
    'kv_deleted' => 0,
    'file_found' => 0,
    'file_deleted' => 0,
    'file_failed' => 0,
];

if($dryRun) {
    echo "[dry-run] 计划删除 kv_store 键数量: {$stats['kv_found']}\n";
    foreach(array_slice($legacyIds, 0, 50) as $id) {
        echo "  - {$id}\n";
    }
    if(count($legacyIds) > 50) {
        echo '  ... 其余 '.(count($legacyIds) - 50)." 条省略\n";
    }
} else {
    if($legacyIds !== []) {
        $result = $kvCollection->deleteMany(['_id' => ['$in' => $legacyIds]]);
        $stats['kv_deleted'] = (int)$result->getDeletedCount();
    }
}

$collectFilePaths = static function(string $root): array {
    $targets = [
        'coolDown',
        'coolDownMeta',
        'checkin',
        'checkinMeta',
        'attack/user',
        'credit',
    ];

    $paths = [];
    foreach($targets as $target) {
        $base = $root.'/'.$target;
        if(!file_exists($base)) continue;

        if(is_file($base)) {
            $paths[] = $base;
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach($iterator as $fileInfo) {
            if(!$fileInfo->isFile()) continue;

            $path = $fileInfo->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));

            // 保留 credit.history（仍用于流水）
            if($relative === 'credit.history') continue;

            $paths[] = $path;
        }
    }

    return array_values(array_unique($paths));
};

if($includeFileStorage) {
    $dataRoot = __DIR__.'/storage/data';
    if(is_dir($dataRoot)) {
        $filePaths = $collectFilePaths($dataRoot);
        $stats['file_found'] = count($filePaths);

        if($dryRun) {
            echo "[dry-run] 计划删除 storage/data 文件数量: {$stats['file_found']}\n";
            foreach(array_slice($filePaths, 0, 50) as $path) {
                echo "  - ".str_replace('\\', '/', substr($path, strlen($dataRoot) + 1))."\n";
            }
            if(count($filePaths) > 50) {
                echo '  ... 其余 '.(count($filePaths) - 50)." 条省略\n";
            }
        } else {
            foreach($filePaths as $path) {
                if(@unlink($path)) {
                    $stats['file_deleted']++;
                } else {
                    $stats['file_failed']++;
                }
            }
        }
    }
}

echo "清理完成".($dryRun ? '（dry-run）' : '')."\n";
echo "- kv_store 识别 legacy 键: {$stats['kv_found']}\n";
echo "- kv_store 实际删除: {$stats['kv_deleted']}\n";
echo "- storage/data 识别文件: {$stats['file_found']}\n";
echo "- storage/data 实际删除: {$stats['file_deleted']}\n";
echo "- storage/data 删除失败: {$stats['file_failed']}\n";
