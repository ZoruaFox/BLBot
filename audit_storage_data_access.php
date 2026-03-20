<?php

/**
 * 审计仍然直接访问 storage/data 的代码路径，辅助完成 P0 迁移收口。
 *
 * 用法：
 * php audit_storage_data_access.php
 */

$root = __DIR__;
$targets = [
    '/storage/data/',
    '../storage/data/',
    '../../storage/data/',
    'storage/data/',
    'filemtime(',
    'scandir(',
];

$skipDirs = [
    '.git',
    'vendor',
    'storage',
    'node_modules',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($skipDirs): bool {
            if($current->isDir()) {
                return !in_array($current->getFilename(), $skipDirs, true);
            }

            return strtolower($current->getExtension()) === 'php';
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

$findings = [];

foreach($iterator as $fileInfo) {
    $path = $fileInfo->getPathname();
    $relativePath = substr($path, strlen($root) + 1);
    $lines = @file($path);
    if($lines === false) {
        continue;
    }

    foreach($lines as $lineNumber => $line) {
        foreach($targets as $target) {
            if(str_contains($line, $target)) {
                $findings[] = [
                    'file' => str_replace('\\', '/', $relativePath),
                    'line' => $lineNumber + 1,
                    'match' => trim($line),
                ];
                break;
            }
        }
    }
}

if($findings === []) {
    echo "未发现直接访问 storage/data 的 PHP 代码路径。\n";
    exit(0);
}

foreach($findings as $finding) {
    echo $finding['file'].':'.$finding['line']."\n";
    echo '  '.$finding['match']."\n\n";
}

echo '共发现 '.count($findings)." 处待审计引用。\n";
