<?php

global $Queue;
use Linfo\Linfo;
requireSeniorAdmin();

if(!class_exists('Linfo\Linfo')) {
    replyAndLeave('该功能需要 Linfo 库，请在项目根目录运行 `composer require linfo/linfo` 安装后再试。');
}

$linfo = new Linfo();
$parser = $linfo->getParser();

$load = $parser->getLoad();
$ram = $parser->getRam();
$uptime = $parser->getUpTime();

// 自动寻找根目录或第一个挂载点，而不是硬编码的第6个([5])
$mounts = $parser->getMounts();
$disk = !empty($mounts) ? (isset($mounts[0]) ? $mounts[0] : reset($mounts)) : ['used' => 0, 'size' => 1];
foreach ($mounts as $m) {
    if (isset($m['mount']) && $m['mount'] === '/') {
        $disk = $m;
        break;
    }
}

$usedRam = sprintf('%.2fG', ($ram['total']-$ram['free'])/1000/1000/1000);
$totalRam = sprintf('%.2fG', $ram['total']/1000/1000/1000);
$usedRamPercent = sprintf('%.2f%%', ($ram['total']-$ram['free'])/$ram['total']*100);

$usedDisk = sprintf('%.2fG', $disk['used']/1000/1000/1000);
$totalDisk = sprintf('%.2fG', $disk['size']/1000/1000/1000);
$usedDiskPercent = sprintf('%.2f%%', $disk['used']/$disk['size']*100);

$msg=<<<EOT
[System]
Load: {$load['now']} {$load['5min']} {$load['15min']}
Mem:  {$usedRam}/{$totalRam} ({$usedRamPercent})
Disk: {$usedDisk}/{$totalDisk} ({$usedDiskPercent})
Up:   {$uptime['text']}
EOT;

if(function_exists('opcache_get_status')) {
    $opcache = @opcache_get_status(false);
    if(is_array($opcache) && !empty($opcache['opcache_enabled']) && isset($opcache['opcache_statistics'], $opcache['memory_usage'])) {
        $opcStatus = $opcache['opcache_statistics'];
        $opcMemWasteRate = sprintf('%.2f%%', (($opcache['memory_usage']['current_wasted_percentage'] ?? 0) * 100));
        $opcHitRate = sprintf('%.2f%%', ($opcStatus['opcache_hit_rate'] ?? 0));
        $msg .= <<<EOT


[OPcache]
Status: enabled
Mem Waste Rate: {$opcMemWasteRate}
Cached/Max: ({$opcStatus['num_cached_scripts']}){$opcStatus['num_cached_keys']}/{$opcStatus['max_cached_keys']}
Hits/Miss: {$opcStatus['hits']}/{$opcStatus['misses']} ({$opcHitRate})
Restart(OOM Hash): {$opcStatus['oom_restarts']} {$opcStatus['hash_restarts']}
EOT;
    } else {
        $msg .= "\n\n[OPcache]\nStatus: extension loaded but disabled";
    }
} else {
    $msg .= "\n\n[OPcache]\nStatus: extension not available";
}


$Queue[]= sendBack($msg);

?>
