<?php

use kjBot\SDK\CQCode;
use kjBot\Frame\Message;
use kjBot\Frame\UnauthorizedException;
use kjBot\Frame\LvlLowException;

error_reporting(E_ALL ^ E_WARNING);

/**
 * 读取 HTTP 资源（带超时拦截）
 */
function fetchHttp($url, $timeout = 5, $contextOpts = []) {
    $defaultOpts = [
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    // 合并传入的上下文配置
    if(isset($contextOpts['http'])) {
        $defaultOpts['http'] = array_merge($defaultOpts['http'], $contextOpts['http']);
    }
    return @file_get_contents($url, false, stream_context_create($defaultOpts));
}

/**
 * 内存缓存
 */
$memoryCache_getData = [];
$memoryCache_config = [];

/**
 * 将配置值转换为布尔值
 */
function configBool(string $key, bool $defaultValue = false): bool {
    $value = config($key, $defaultValue);
    if(is_bool($value)) return $value;
    if(is_int($value)) return $value !== 0;
    if(is_string($value)) {
        $normalized = strtolower(trim($value));
        if(in_array($normalized, ['1', 'true', 'yes', 'on'], true)) return true;
        if(in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) return false;
    }
    return (bool)$value;
}

/**
 * 持久化后端（file|mongo）
 */
function getDataBackend(): string {
    static $backend = null;
    if($backend !== null) return $backend;

    $configured = strtolower(trim((string)config('dataBackend', 'file')));
    if(!in_array($configured, ['file', 'mongo'], true)) {
        $configured = 'file';
    }

    $backend = $configured;
    return $backend;
}

function getMongoDataCollection(bool $strict = false) {
    global $Database;
    static $collection = null;

    if($collection !== null) return $collection;

    if(!isset($Database)) {
        if($strict) {
            throw new \RuntimeException('MongoDB 连接未初始化，无法访问数据持久化后端。');
        }
        return null;
    }

    $collectionName = trim((string)config('mongoDataCollection', 'kv_store'));
    if($collectionName === '') $collectionName = 'kv_store';

    $collection = $Database->$collectionName;
    return $collection;
}

function getMongoCooldownCollection(bool $strict = false) {
    global $Database;
    static $collection = null;

    if($collection !== null) return $collection;

    if(!isset($Database)) {
        if($strict) {
            throw new \RuntimeException('MongoDB 连接未初始化，无法访问 cooldown 持久化后端。');
        }
        return null;
    }

    $collectionName = trim((string)config('mongoCooldownCollection', 'cooldowns'));
    if($collectionName === '') $collectionName = 'cooldowns';

    $collection = $Database->$collectionName;
    return $collection;
}

function getMongoCheckinCollection(bool $strict = false) {
    global $Database;
    static $collection = null;

    if($collection !== null) return $collection;

    if(!isset($Database)) {
        if($strict) {
            throw new \RuntimeException('MongoDB 连接未初始化，无法访问 checkin 持久化后端。');
        }
        return null;
    }

    $collectionName = trim((string)config('mongoCheckinCollection', 'checkins'));
    if($collectionName === '') $collectionName = 'checkins';

    $collection = $Database->$collectionName;
    return $collection;
}

function getMongoPrimaryReadPreference(): \MongoDB\Driver\ReadPreference {

    static $readPreference = null;
    if($readPreference !== null) return $readPreference;

    $mode = defined('MongoDB\\Driver\\ReadPreference::PRIMARY')
        ? \MongoDB\Driver\ReadPreference::PRIMARY
        : \MongoDB\Driver\ReadPreference::RP_PRIMARY;

    $readPreference = new \MongoDB\Driver\ReadPreference($mode);
    return $readPreference;
}

function getMongoOperationOptions(array $options = []): array {
    if(!isset($options['readPreference'])) {
        $options['readPreference'] = getMongoPrimaryReadPreference();
    }

    return $options;
}

function mongoUtcDateTimeFromTimestamp(int $timestamp): \MongoDB\BSON\UTCDateTime {
    return new \MongoDB\BSON\UTCDateTime($timestamp * 1000);
}

function mongoValueToTimestamp($value): int {
    if($value instanceof \MongoDB\BSON\UTCDateTime) {
        return (int)$value->toDateTime()->format('U');
    }

    if($value instanceof \DateTimeInterface) {
        return (int)$value->format('U');
    }

    if(is_numeric($value)) {
        return (int)$value;
    }

    return 0;
}

function logPersistenceWarning(string $scope, string $message): void {
    $line = date('Y-m-d H:i:s')." [persistence][{$scope}] {$message}\n";
    @file_put_contents('../storage/data/error.log', $line, FILE_APPEND);
}

function ensureMongoPersistenceReady(): void {
    static $checked = false;
    if($checked || getDataBackend() !== 'mongo') return;


    global $Database;
    if(!isset($Database)) {
        throw new \RuntimeException('MongoDB 数据库对象未初始化，无法启用 mongo 持久化后端。');
    }

    $Database->command(['ping' => 1], getMongoOperationOptions());

    $kvCollection = getMongoDataCollection(true);
    $kvCollection->createIndex(['updated_at' => 1], ['background' => true]);

    $probeKey = '__blbot/system/persistence_probe';
    $probeValue = (string)time();
    $writeResult = $kvCollection->updateOne(
        ['_id' => $probeKey],
        ['$set' => ['value' => $probeValue, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]],
        getMongoOperationOptions(['upsert' => true]),
    );

    if(!$writeResult->isAcknowledged()) {
        throw new \RuntimeException('MongoDB 持久化自检失败：写入探针未被确认。');
    }

    $probeDoc = $kvCollection->findOne(
        ['_id' => $probeKey],
        getMongoOperationOptions(['projection' => ['value' => 1]]),
    );

    if(!$probeDoc || !isset($probeDoc['value']) || (string)$probeDoc['value'] !== $probeValue) {
        throw new \RuntimeException('MongoDB 持久化自检失败：探针数据读取不一致。');
    }

    $cooldownCollection = getMongoCooldownCollection(true);
    $cooldownCollection->createIndex(['set_at' => 1], ['background' => true]);
    $cooldownCollection->createIndex(['updated_at' => 1], ['background' => true]);

    $cooldownProbeKey = '__blbot/system/cooldown_probe';
    $cooldownProbeSetAt = time();
    $cooldownWriteResult = $cooldownCollection->updateOne(
        ['_id' => $cooldownProbeKey],
        ['$set' => [
            'duration' => 0,
            'set_at' => mongoUtcDateTimeFromTimestamp($cooldownProbeSetAt),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ]],
        getMongoOperationOptions(['upsert' => true]),
    );

    if(!$cooldownWriteResult->isAcknowledged()) {
        throw new \RuntimeException('MongoDB cooldown 持久化自检失败：写入探针未被确认。');
    }

    $cooldownProbeDoc = $cooldownCollection->findOne(
        ['_id' => $cooldownProbeKey],
        getMongoOperationOptions(['projection' => ['set_at' => 1]]),
    );

        $cooldownProbeReadAt = mongoValueToTimestamp($cooldownProbeDoc['set_at'] ?? null);


    if(!$cooldownProbeDoc || abs($cooldownProbeReadAt - $cooldownProbeSetAt) > 1) {
        throw new \RuntimeException('MongoDB cooldown 持久化自检失败：探针数据读取不一致。');
    }

    if(configBool('enableCheckinCollection', false)) {
        $checkinCollection = getMongoCheckinCollection(true);
        $checkinCollection->createIndex(['user_id' => 1], ['background' => true]);
        $checkinCollection->createIndex(['last_checkin_at' => 1], ['background' => true]);
        $checkinCollection->createIndex(['updated_at' => 1], ['background' => true]);

        $checkinProbeKey = '__blbot/system/checkin_probe';
        $checkinProbeAt = time();
        $checkinProbeWriteResult = $checkinCollection->updateOne(
            ['_id' => $checkinProbeKey],
            ['$set' => [
                'type' => 'probe',
                'user_id' => '__probe__',
                'last_checkin_at' => mongoUtcDateTimeFromTimestamp($checkinProbeAt),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]],
            getMongoOperationOptions(['upsert' => true]),
        );

        if(!$checkinProbeWriteResult->isAcknowledged()) {
            throw new \RuntimeException('MongoDB checkin 持久化自检失败：写入探针未被确认。');
        }

        $checkinProbeDoc = $checkinCollection->findOne(
            ['_id' => $checkinProbeKey],
            getMongoOperationOptions(['projection' => ['last_checkin_at' => 1]]),
        );

        $checkinProbeReadAt = mongoValueToTimestamp($checkinProbeDoc['last_checkin_at'] ?? null);
        if(!$checkinProbeDoc || abs($checkinProbeReadAt - $checkinProbeAt) > 1) {
            throw new \RuntimeException('MongoDB checkin 持久化自检失败：探针数据读取不一致。');
        }
    }

    $checked = true;
}

function mongoSetCooldown(string $name, int $duration, int $setAt): bool {
    $collection = getMongoCooldownCollection(true);


    $result = $collection->updateOne(
        ['_id' => $name],
        ['$set' => [
            'duration' => $duration,
            'set_at' => mongoUtcDateTimeFromTimestamp($setAt),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ]],
        getMongoOperationOptions(['upsert' => true]),
    );

    if(!$result->isAcknowledged()) {
        return false;
    }

    // P1 过渡清理：写入专用集合成功后，删除 kv_store 中遗留 cooldown 键
    mongoDelData("coolDown/{$name}");
    mongoDelData("coolDownMeta/{$name}");

    return true;
}



function migrateLegacyCooldownFromKvStore(string $name): ?array {
    $legacyDurationKey = "coolDown/{$name}";
    $legacySetAtKey = "coolDownMeta/{$name}";

    $legacyDurationRaw = mongoGetData($legacyDurationKey);
    $legacySetAtRaw = mongoGetData($legacySetAtKey);

    if($legacyDurationRaw === false && $legacySetAtRaw === false) {
        return null;
    }

    $duration = (int)$legacyDurationRaw;
    $setAt = (int)$legacySetAtRaw;
    if($setAt <= 0) {
        $setAt = mongoGetDataUpdatedAt($legacyDurationKey);
    }
    if($setAt <= 0) {
        $setAt = time();
    }

    if(!mongoSetCooldown($name, $duration, $setAt)) {
        throw new \RuntimeException("MongoDB cooldown 迁移失败：{$name}");
    }

    return [
        'duration' => $duration,
        'set_at' => $setAt,
    ];
}



function mongoGetCooldown(string $name): ?array {
    $collection = getMongoCooldownCollection(true);

    $doc = $collection->findOne(
        ['_id' => $name],
        getMongoOperationOptions(['projection' => ['duration' => 1, 'set_at' => 1, 'updated_at' => 1]]),
    );

    if($doc) {
        $setAt = mongoValueToTimestamp($doc['set_at'] ?? null);
        if($setAt <= 0) {
            $setAt = mongoValueToTimestamp($doc['updated_at'] ?? null);
        }

        return [
            'duration' => (int)($doc['duration'] ?? 0),
            'set_at' => $setAt,
        ];
    }

    return migrateLegacyCooldownFromKvStore($name);
}

function useCheckinCollection(): bool {
    return getDataBackend() === 'mongo' && configBool('enableCheckinCollection', false);
}

function checkinCollectionDualWriteEnabled(): bool {
    return configBool('checkinCollectionDualWrite', true);
}

function getCheckinUserDocId(string $userId): string {
    return 'user:'.$userId;
}

function getCheckinStatDocId(): string {
    return 'stat';
}

function normalizeCheckinStat(array $stat, ?int $timestamp = null): array {
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

function getLegacyCheckinLastTimestamp(string $userId): int {
    clearstatcache();

    $checkinMeta = getData('checkinMeta/'.$userId);
    $lastCheckinTime = $checkinMeta ? (int)$checkinMeta : 0;

    if($lastCheckinTime <= 0 && getDataBackend() === 'file') {
        $checkinFilePath = '../storage/data/checkin/'.$userId;
        $lastCheckinTime = file_exists($checkinFilePath) ? filemtime($checkinFilePath) : 0;
    }

    return max(0, $lastCheckinTime);
}

function setLegacyCheckinLastTimestamp(string $userId, int $timestamp): void {
    delData('checkin/'.$userId);
    delData('checkinMeta/'.$userId);
    setData('checkin/'.$userId, '');
    setData('checkinMeta/'.$userId, (string)$timestamp);
}

function getLegacyCheckinStat(): array {
    $raw = getData('checkin/stat');
    $decoded = [];

    if($raw !== false && $raw !== '') {
        $parsed = json_decode($raw, true);
        if(is_array($parsed)) {
            $decoded = $parsed;
        }
    }

    return normalizeCheckinStat($decoded);
}

function setLegacyCheckinStat(array $stat): void {
    $normalized = normalizeCheckinStat($stat);
    setData('checkin/stat', json_encode($normalized, JSON_UNESCAPED_UNICODE));
}

function mongoSetCheckinLastTimestamp(string $userId, int $timestamp): bool {
    $collection = getMongoCheckinCollection(true);
    $docId = getCheckinUserDocId($userId);

    $result = $collection->updateOne(
        ['_id' => $docId],
        [
            '$set' => [
                'user_id' => $userId,
                'last_checkin_at' => mongoUtcDateTimeFromTimestamp($timestamp),
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ],
            '$setOnInsert' => ['_id' => $docId],
        ],
        getMongoOperationOptions(['upsert' => true]),
    );

    return $result->isAcknowledged();
}

function mongoGetCheckinLastTimestamp(string $userId): int {
    $collection = getMongoCheckinCollection(true);
    $docId = getCheckinUserDocId($userId);

    $doc = $collection->findOne(
        ['_id' => $docId],
        getMongoOperationOptions(['projection' => ['last_checkin_at' => 1, 'updated_at' => 1]]),
    );

    if(!$doc) {
        $legacyDoc = $collection->findOne(
            ['user_id' => $userId],
            getMongoOperationOptions([
                'sort' => ['updated_at' => -1],
                'projection' => ['_id' => 1, 'last_checkin_at' => 1, 'updated_at' => 1],
            ]),
        );

        if($legacyDoc) {
            $legacyTimestamp = mongoValueToTimestamp($legacyDoc['last_checkin_at'] ?? null);
            if($legacyTimestamp <= 0) {
                $legacyTimestamp = mongoValueToTimestamp($legacyDoc['updated_at'] ?? null);
            }

            if($legacyTimestamp > 0) {
                mongoSetCheckinLastTimestamp($userId, $legacyTimestamp);
                return $legacyTimestamp;
            }
        }

        return 0;
    }

    $timestamp = mongoValueToTimestamp($doc['last_checkin_at'] ?? null);
    if($timestamp <= 0) {
        $timestamp = mongoValueToTimestamp($doc['updated_at'] ?? null);
    }

    return max(0, $timestamp);
}

function mongoSetCheckinStat(array $stat): bool {
    $collection = getMongoCheckinCollection(true);
    $docId = getCheckinStatDocId();
    $normalized = normalizeCheckinStat($stat);

    $result = $collection->updateOne(
        ['_id' => $docId],
        [
            '$set' => [
                'type' => 'stat',
                'date' => $normalized['date'],
                'checked' => (int)$normalized['checked'],
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ],
            '$setOnInsert' => ['_id' => $docId],
        ],
        getMongoOperationOptions(['upsert' => true]),
    );

    return $result->isAcknowledged();
}

function mongoGetCheckinStat(): array {
    $collection = getMongoCheckinCollection(true);

    $doc = $collection->findOne(
        ['_id' => getCheckinStatDocId()],
        getMongoOperationOptions(['projection' => ['date' => 1, 'checked' => 1]]),
    );

    if($doc && is_array($doc)) {
        return normalizeCheckinStat($doc);
    }

    $legacy = getLegacyCheckinStat();
    if(!mongoSetCheckinStat($legacy)) {
        throw new \RuntimeException('MongoDB checkin/stat 迁移失败：写入新集合未被确认。');
    }

    return $legacy;
}

function getCheckinLastTimestamp($userId): int {
    $userId = (string)$userId;

    if(!useCheckinCollection()) {
        return getLegacyCheckinLastTimestamp($userId);
    }

    try {
        $lastCheckinTime = mongoGetCheckinLastTimestamp($userId);
        if($lastCheckinTime > 0) {
            return $lastCheckinTime;
        }

        $legacyCheckinTime = getLegacyCheckinLastTimestamp($userId);
        if($legacyCheckinTime > 0 && !mongoSetCheckinLastTimestamp($userId, $legacyCheckinTime)) {
            throw new \RuntimeException("MongoDB checkin 用户迁移失败：{$userId}");
        }

        return $legacyCheckinTime;
    } catch(\Throwable $e) {
        logPersistenceWarning('checkin-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        return getLegacyCheckinLastTimestamp($userId);
    }
}

function setCheckinLastTimestamp($userId, int $timestamp): void {
    $userId = (string)$userId;
    $timestamp = max(0, $timestamp);

    if(!useCheckinCollection()) {
        setLegacyCheckinLastTimestamp($userId, $timestamp);
        return;
    }

    try {
        if(!mongoSetCheckinLastTimestamp($userId, $timestamp)) {
            throw new \RuntimeException("MongoDB checkin 写入失败：{$userId}");
        }

        if(checkinCollectionDualWriteEnabled()) {
            setLegacyCheckinLastTimestamp($userId, $timestamp);
        }
    } catch(\Throwable $e) {
        logPersistenceWarning('checkin-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        setLegacyCheckinLastTimestamp($userId, $timestamp);
    }
}

function getCheckinStat(): array {
    if(!useCheckinCollection()) {
        return getLegacyCheckinStat();
    }

    try {
        return mongoGetCheckinStat();
    } catch(\Throwable $e) {
        logPersistenceWarning('checkin-stat-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        return getLegacyCheckinStat();
    }
}

function setCheckinStat(array $stat): void {
    $normalized = normalizeCheckinStat($stat);

    if(!useCheckinCollection()) {
        setLegacyCheckinStat($normalized);
        return;
    }

    try {
        if(!mongoSetCheckinStat($normalized)) {
            throw new \RuntimeException('MongoDB checkin/stat 写入失败。');
        }

        if(checkinCollectionDualWriteEnabled()) {
            setLegacyCheckinStat($normalized);
        }
    } catch(\Throwable $e) {
        logPersistenceWarning('checkin-stat-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        setLegacyCheckinStat($normalized);
    }
}

function increaseCheckinCount(?int $timestamp = null): array {
    $timestamp = $timestamp ?? time();
    $today = date('Ymd', $timestamp);

    $stat = getCheckinStat();
    if((int)$today > (int)$stat['date']) {
        $stat['date'] = $today;
        $stat['checked'] = 0;
    }

    $stat['checked'] += 1;
    setCheckinStat($stat);

    return normalizeCheckinStat($stat, $timestamp);
}


function normalizeStoredDataValue($data): string {
    if(is_string($data)) return $data;


    if(is_scalar($data) || $data === null) return (string)$data;

    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
    if($encoded === false) {
        return '';
    }

    return $encoded;
}





function mongoSetData(string $filePath, $data, bool $pending = false) {
    $collection = getMongoDataCollection(true);

    $value = normalizeStoredDataValue($data);

    if($pending) {
        $current = mongoGetData($filePath);
        $value = ($current === false ? '' : $current).$value;
    }

    $result = $collection->updateOne(
        ['_id' => $filePath],
        ['$set' => ['value' => $value, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]],
        getMongoOperationOptions(['upsert' => true]),
    );

    return $result->isAcknowledged() ? strlen($value) : false;
}




function mongoGetData(string $filePath) {
    $collection = getMongoDataCollection(true);

    $doc = $collection->findOne(
        ['_id' => $filePath],
        getMongoOperationOptions(['projection' => ['value' => 1]]),
    );
    if(!$doc || !array_key_exists('value', $doc)) return false;

    return normalizeStoredDataValue($doc['value']);
}




function mongoGetDataUpdatedAt(string $filePath): int {
    $collection = getMongoDataCollection(true);

    $doc = $collection->findOne(
        ['_id' => $filePath],
        getMongoOperationOptions(['projection' => ['updated_at' => 1]]),
    );
    if(!$doc || !isset($doc['updated_at'])) return 0;

    return mongoValueToTimestamp($doc['updated_at']);
}





function mongoDelData(string $filePath): bool {
    $collection = getMongoDataCollection(true);

    $result = $collection->deleteOne(['_id' => $filePath], getMongoOperationOptions());
    return $result->isAcknowledged();
}



function mongoGetDataFolderContents(string $folderPath): array {
    $collection = getMongoDataCollection(true);

    $prefix = trim($folderPath, '/');
    if($prefix !== '') $prefix .= '/';

    $regex = '^'.preg_quote($prefix, '/').'[^/]+(?:/.*)?$';
    $cursor = $collection->find(
        ['_id' => ['$regex' => $regex]],
        getMongoOperationOptions(['projection' => ['_id' => 1]]),
    );

    $children = [];
    foreach($cursor as $doc) {
        $id = (string)($doc['_id'] ?? '');
        if($id === '' || !str_starts_with($id, $prefix)) continue;

        $rest = substr($id, strlen($prefix));
        if($rest === false || $rest === '') continue;

        $child = explode('/', $rest, 2)[0];
        if($child !== '') $children[$child] = $child;
    }

    return array_values($children);
}



/**
 * APCu 数据缓存是否可用
 */
function useApcuDataCache(): bool {
    static $available = null;
    if($available !== null) return $available;

    $enabledByConfig = configBool('enableApcuDataCache', true);
    $hasFunctions = function_exists('apcu_fetch') && function_exists('apcu_store') && function_exists('apcu_delete');
    $apcEnabled = configBool('apc.enabled', true);
    $apcCliEnabled = PHP_SAPI !== 'cli' || configBool('apc.enable_cli', false);

    $available = $enabledByConfig && $hasFunctions && $apcEnabled && $apcCliEnabled;
    return $available;
}


function getApcuDataCacheTtl(): int {
    $ttl = (int)config('apcuDataCacheTtl', 0);
    return max(0, $ttl);
}

function getApcuDataCacheKey(string $filePath): string {
    return 'blbot:data:'.sha1($filePath);
}


/**
 * 读取配置文件
 * @param string $kay 键值
 * @param string $defaultValue 默认值
 * @return string|null
 */
function config(string $key, $defaultValue = null) {
    global $Config, $memoryCache_config;

    if(isset($memoryCache_config[$key])) return $memoryCache_config[$key];

    if($Config && array_key_exists($key, $Config)) {
        $memoryCache_config[$key] = $Config[$key];
        return $Config[$key];
    } else {
        return $defaultValue;
    }
}

/**
 * 给事件产生者发送私聊
 * @param string $msg 消息内容
 * @param bool $auto_escape 是否发送纯文本
 * @param bool $async 是否异步
 * @return kjBot\Frame\Message
 */
function sendPM(string $msg, bool $auto_escape = false, bool $async = false): Message {
    global $Event;

    return new Message($msg, $Event['user_id'], false, $auto_escape, $async);
}

function sendBackImmediately(string $msg, bool $auto_escape = false): mixed {
    global $Event, $CQ;
    if(fromGroup()) {
        return $CQ->sendGroupMsg($Event['group_id'], $msg, $auto_escape)->message_id;
    } else {
        return $CQ->sendPrivateMsg($Event['user_id'], $msg, $auto_escape)->message_id;
    }
}

/**
 * 消息从哪来发到哪
 * @param string $msg 消息内容
 * @param bool $auto_escape 是否发送纯文本
 * @param bool $async 是否异步
 * @return kjBot\Frame\Message
 */
function sendBack(string $msg, bool $auto_escape = false, bool $async = false): Message {
    global $Event;

    return new Message($msg, isset($Event['group_id']) ? $Event['group_id'] : $Event['user_id'], isset($Event['group_id']), $auto_escape, $async);
}

function replyMessage(string $msg, bool $auto_escape = false, bool $async = false): Message {
    global $Event;
    $msg = '[CQ:reply,id='.$Event['message_id'].']'.$msg;
    // $msg = '[CQ:at,qq='.$Event['user_id']."]\n".$msg;
    if(!rand(0, 15)) {
        $msg = str_replace("哦～", "喵～", $msg);
    }
    return sendBack($msg, $auto_escape, $async);
}

function pokeBack(int $user_id = 0) {
    global $Event, $CQ;
    if(!$user_id) $user_id = $Event['user_id'];
    if($user_id == Config('bot')) return;
    if(fromGroup()) {
        $CQ->groupPoke($Event['group_id'], $user_id);
    } else {
        $CQ->friendPoke($user_id);
    }
    return;
}

/**
 * 发送给 Master
 * @param string $msg 消息内容
 * @param bool $auto_escape 是否发送纯文本
 * @param bool $async 是否异步
 * @return kjBot\Frame\Message
 */
function sendMaster(string $msg, bool $auto_escape = false, bool $async = false): Message {
    return new Message($msg, config('master'), false, $auto_escape, $async);
}

function sendDevGroup(string $msg, bool $auto_escape = false, bool $async = false): ?Message {
    if(config('devgroup'))
        return new Message($msg, config('devgroup'), true, $auto_escape, $async);
}
/**
 * 记录数据
 * @param string $filePath 相对于 storage/data/ 的路径
 * @param $data 要存储的数据内容
 * @param bool $pending 是否追加写入（默认不追加）
 * @return mixed string|false
 */
function setData(string $filePath, $data, bool $pending = false) {
    global $memoryCache_getData;

    $result = false;
    if(getDataBackend() === 'mongo') {
        $result = mongoSetData($filePath, $data, $pending);
    } else {
        if(!is_dir(dirname('../storage/data/'.$filePath))) {
            @mkdir(dirname('../storage/data/'.$filePath), 0777, true);
        }
        $result = file_put_contents('../storage/data/'.$filePath, $data, $pending ? (FILE_APPEND | LOCK_EX) : LOCK_EX);
    }

    if($result === false) {
        return false;
    }

    if(!$pending) {
        $storedValue = normalizeStoredDataValue($data);
        $memoryCache_getData[$filePath] = $storedValue;
        if(useApcuDataCache()) {
            apcu_store(getApcuDataCacheKey($filePath), $storedValue, getApcuDataCacheTtl());
        }
    } else {
        unset($memoryCache_getData[$filePath]);
        if(useApcuDataCache()) {
            apcu_delete(getApcuDataCacheKey($filePath));
        }
    }

    return $result;
}



function delData(string $filePath) {
    global $memoryCache_getData;

    $result = true;
    if(getDataBackend() === 'mongo') {
        $result = mongoDelData($filePath);
    } else {
        if(file_exists('../storage/data/'.$filePath)) {
            $result = unlink('../storage/data/'.$filePath);
        }
    }

    if($result !== false) {
        unset($memoryCache_getData[$filePath]);
        if(useApcuDataCache()) {
            apcu_delete(getApcuDataCacheKey($filePath));
        }
    }

    return $result;
}



/**
 * 读取数据
 * @param $filePath 相对于 storage/data/ 的路径
 * @return mixed string|false
 */
function getData(string $filePath) {
    global $memoryCache_getData;
    if(isset($memoryCache_getData[$filePath])) return $memoryCache_getData[$filePath];

    if(useApcuDataCache()) {
        $cacheKey = getApcuDataCacheKey($filePath);
        $cached = apcu_fetch($cacheKey, $hit);
        if($hit) {
            $memoryCache_getData[$filePath] = $cached;
            return $cached;
        }
    }

    if(getDataBackend() === 'mongo') {
        $data = mongoGetData($filePath);
    } else {
        if(!file_exists('../storage/data/'.$filePath)) {
            $data = false;
        } else {
            $data = file_get_contents('../storage/data/'.$filePath);
        }
    }

    if($data === false) {
        if(useApcuDataCache()) {
            apcu_delete(getApcuDataCacheKey($filePath));
        }
        return false;
    }

    $memoryCache_getData[$filePath] = $data;
    if(useApcuDataCache()) {
        apcu_store(getApcuDataCacheKey($filePath), $data, getApcuDataCacheTtl());
    }

    return $data;
}



function getDataPath(string $filePath) {
    return '../storage/data/'.$filePath;
}

function getDataFolderContents(string $folderPath) {
    if(getDataBackend() === 'mongo') {
        return mongoGetDataFolderContents($folderPath);
    }

    $contents = scandir('../storage/data/'.$folderPath);
    return array_diff($contents, ['.', '..']);
}


/**
 * 缓存
 * @param string $cacheFileName 缓存文件名
 * @param $cache 要缓存的数据内容
 * @return mixed string|false
 */
function setCache(string $cacheFileName, $cache) {
    if(!is_dir(dirname('../storage/cache/'.$cacheFileName))) {
        @mkdir(dirname('../storage/cache/'.$cacheFileName), 0777, true);
    }
    return file_put_contents('../storage/cache/'.$cacheFileName, $cache, LOCK_EX);
}

function delCache(string $filePath) {
    return unlink('../storage/cache/'.$filePath);
}

/**
 * 取得缓存
 * @param $cacheFileName 缓存文件名
 * @return mixed string|false
 */
function getCache($cacheFileName) {
    if(!file_exists('../storage/cache/'.$cacheFileName)) return false;
    return file_get_contents('../storage/cache/'.$cacheFileName);
}

function getCachePath($cacheFileName) {
    return '../storage/cache/'.$cacheFileName;
}

function getCacheTime($cacheFileName) {
    return filemtime(getCachePath($cacheFileName));
}

function getCacheFolderContents(string $folderPath) {
    $contents = scandir('../storage/cache/'.$folderPath);
    return array_diff($contents, ['.', '..']);
}

function getAvatar($user_id, $large = false) {
    global $Config;
    $refreshInverval = $Config['avatarCacheTime'] ?? 86400;
    $size = $large ? 640 : 100;
    $cacheFile = "avatar/{$size}/{$user_id}";
    $avatar = getCache($cacheFile);
    if(!$avatar || time() - filemtime(getCachePath($cacheFile)) > $refreshInverval) {
        $avatar = fetchHttp("https://q1.qlogo.cn/g?b=qq&s={$size}&nk={$user_id}");
        if ($avatar) {
            $img = new Imagick();
            $img->readImageBlob($avatar);
            $img->setImageFormat('png');
            $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
            $avatar = $img->getImagesBlob();
            $img->clear();
            $img->destroy();
            setCache($cacheFile, $avatar);
        }
    }
    return $avatar;
}

function getImg(string $filePath) {
    return file_get_contents('../storage/img/'.$filePath);
}

function getFontPath(string $fontName) {
    return '../storage/font/'.$fontName;
}

function getConfig($group_id) {
    $json = getData('config/'.$group_id.'.json');
    if(!$json) {
        $json = '{"mode":"blacklist","commands":[],"silence":false}';
    }
    return json_decode($json, true);
}

function setConfig($group_id, $data) {
    setData('config/'.$group_id.'.json', json_encode($data));
}

/**
 * 清理缓存
 */
function clearCache() {
    $cacheDir = opendir('../storage/cache/');
    while(false !== ($file = readdir($cacheDir))) {
        if($file != "." && $file != "..") {
            unlink('../storage/cache/'.$file);
        }
    }
    closedir($cacheDir);
}

/**
 * 发送图片
 * @param string $str 图片（字符串形式）
 * @return string 图片对应的 base64 格式 CQ码
 */
function sendImg($str): string {
    return CQCode::Image('base64://'.base64_encode($str));
}

/**
 * 发送录音
 * @param string $str 录音（字符串形式）
 * @return string 录音对应的 base64 格式 CQ码
 */
function sendRec($str): string {
    return CQCode::Record('base64://'.base64_encode($str));
}

/**
 * 装载模块
 * @param string $module 模块名
 */
function loadModule(string $module) {
    global $Event;
    static $config;
    if(fromGroup()) {
        if(!$config) {
            $config = getConfig($Event['group_id']);
        }
        $baseCommand = explode('.', $module)[0];
        $moduleInList = in_array($baseCommand, $config['commands']);
        if(!in_array($baseCommand, ['config']) && !preg_match('/\.tools$/', $module)) {
            if($moduleInList && $config['mode'] == 'blacklist' || !$moduleInList && $config['mode'] == 'whitelist') {
                if(!$config['silence']) {
                    replyAndLeave('该指令已被群指令配置禁用。');
                } else {
                    leave();
                }
            }
        }
    }

    if($Event['user_id'] == "80000000") {
        // $Queue[]= replyMessage('请不要使用匿名！');
        leave();
    }
    if('.' === $module[0]) {
        $Queue[] = replyMessage('非法命令！');
        leave();
    }
    $moduleFile = str_replace('.', '/', strtolower($module), $count);
    if(0 === $count) {
        $moduleFile .= '/main';
    }
    $moduleFile .= '.php';

    if(file_exists('../module/'.$moduleFile)) {
        require_once('../module/'.$moduleFile);
    } else if(strlen($module) <= 15 && $module != '接龙') {
        $prefix = config('prefix', '/');
        replyAndLeave("指令 {$prefix}{$module} 不存在哦…不知道怎么使用 Bot ？发送 {$prefix}help 即可查看帮助～");
    }
}

function checkModule(string $module) {
    global $Event;
    if('.' === $module[0]) {
        $Queue[] = replyMessage('非法命令！');
        leave();
    }
    $moduleFile = str_replace('.', '/', $module, $count);
    if(0 === $count) {
        $moduleFile .= '/main';
    }
    $moduleFile .= '.php';
    return file_exists('../module/'.$moduleFile);
}

/**
 * 解析命令
 * @param string $str 命令字符串
 * @return mixed array|bool 解析结果数组 失败返回false
 */
function parseCommand(string $str) {
    // 正则表达式
    $regEx = '#(?:(?<s>[\'"])?(?<v>.+?)?(?:(?<!\\\\)\k<s>)|(?<u>[^\'"\s]+))#';
    // 匹配所有
    if(!preg_match_all($regEx, $str, $exp_list)) return false;
    // 遍历所有结果
    $cmd = array();
    foreach($exp_list['s'] as $id => $s) {
        // 判断匹配到的值
        $cmd[] = empty($s) ? $exp_list['u'][$id] : $exp_list['v'][$id];
    }
    return $cmd;
}

function pd() {
    throw new UnauthorizedException();
}

/**
 * 继续执行脚本需要指定等级
 * 是就继续，不是就抛出异常，返回权限不足
 */
function requireLvl($lvl = 0, $msg = '本指令', $resolve = null) {
    global $Event;
    loadModule('exp.tools');
    if (isMaster()) return; // 主人无视等级限制
    if(intval(getLvl($Event['user_id'])) < $lvl) {
        throw new LvlLowException($lvl, getLvl($Event['user_id']), $msg, $resolve);
    }
}

/**
 * 判断是否是机器人主人
 * @param bool 是就return true，不是return false
 */
function isMaster() {
    global $Event;
    return $Event['user_id'] == config('master');
}

/**
 * 继续执行脚本需要机器人主人权限
 * 是就继续，不是就抛出异常，返回权限不足
 */
function requireMaster() {
    if(!isMaster()) {
        throw new UnauthorizedException();
    }
}

/**
 * 判断是否是机器人主人管理
 * @param bool 是就return true，不是return false
 */
function isSeniorAdmin() {
    if(isMaster()) {
        return true;
    }
    global $Event;
    $qq = $Event['user_id'];
    $usertype = getData('usertype.json');
    if($usertype === false) return false; //无法打开黑名单时不再抛异常
    $usertype = json_decode($usertype)->SeniorAdmin;
    foreach($usertype as $person) {
        if($qq == $person) {
            return true;
        }
    }
    return false;
}

/**
 * 继续执行脚本需要管理权限
 * 是就继续，不是就抛出异常，返回权限不足
 */
function requireSeniorAdmin() {
    if(!isSeniorAdmin()) {
        throw new UnauthorizedException();
    }
}

/**
 * 判断是否是机器人主人低管
 * @param bool 是就return true，不是return false
 */
function isAdmin() {
    if(isSeniorAdmin()) {
        return true;
    }
    global $Event;
    $qq = $Event['user_id'];
    $usertype = getData('usertype.json');
    if($usertype === false) return false; //无法打开黑名单时不再抛异常
    $usertype = json_decode($usertype)->Admin;
    foreach($usertype as $person) {
        if($qq == $person) {
            return true;
        }
    }
    return false;
}

/**
 * 继续执行脚本需要低管权限
 * 是就继续，不是就抛出异常，返回权限不足
 */
function requireAdmin() {
    if(!isAdmin()) {
        throw new UnauthorizedException();
    }
}

/**
 * 判断是否是Insider
 * @param bool 是就return true，不是return false
 */
function isInsider() {
    if(isSeniorAdmin()) {
        return true;
    }
    global $Event;
    $qq = $Event['user_id'];
    $usertype = getData('usertype.json');
    if($usertype === false) return false; //无法打开黑名单时不再抛异常
    $usertype = json_decode($usertype)->Insider;
    foreach($usertype as $person) {
        if($qq == $person) {
            return true;
        }
    }
    return false;
}

function nextArg(bool $getRemaining = false) {
    global $Command;
    static $index = 0;

    if ($getRemaining) {
        return implode(' ', array_slice($Command, $index));
    }
    
    return isset($Command[$index]) ? $Command[$index++] : null;
}

/**
 * 冷却
 * 不指定冷却时间时将返回与冷却完成时间的距离，大于0表示已经冷却完成
 * @param string $name 冷却文件名称，对指定用户冷却需带上Q号
 * @param int $time 冷却时间
 */
function coolDown(string $name, $time = null): int {
    if(getDataBackend() === 'mongo') {
        if(null === $time) {
            $record = mongoGetCooldown($name);
            if(!$record) {
                return time();
            }

            $duration = (int)($record['duration'] ?? 0);
            $setAt = (int)($record['set_at'] ?? 0);
            return time() - $setAt - $duration;
        }

        $duration = (int)$time;
        if(!mongoSetCooldown($name, $duration, time())) {
            throw new \RuntimeException("MongoDB cooldown 写入失败：{$name}");
        }

        return -$duration;
    }

    if(null === $time) {
        $duration = (int)getData("coolDown/{$name}");
        $setAt = (int)getData("coolDownMeta/{$name}");

        if($setAt <= 0) {
            clearstatcache();
            $coolDownFile = "../storage/data/coolDown/{$name}";
            $setAt = file_exists($coolDownFile) ? filemtime($coolDownFile) : 0;
        }

        return time() - $setAt - $duration;
    }

    setData("coolDown/{$name}", (string)(int)$time);
    setData("coolDownMeta/{$name}", (string)time());
    return -(int)$time;
}




/**
 * 消息是否来自(指定)群
 * 指定参数时将判定是否来自该群
 * 不指定时将判定是否来自群聊
 * @param mixed $group=NULL 群号
 * @return bool
 */
function fromGroup($group = null): bool {
    global $Event;
    if($group == null) {
        return isset($Event['group_id']);
    } else {
        return ($Event['group_id'] == $group);
    }
}

/**
 * 退出模块
 * @param string $msg 返回信息
 * @param int $code 指定返回码
 * @throws Exception 用于退出模块
 */
function leave($msg = '', $code = 0): never {
    throw new \Exception($msg, $code);
}

function replyAndLeave($msg = '', $code = 0): never {
    global $Event;
    if($msg) {
        $msg = "[CQ:reply,id=".$Event['message_id']."]".$msg;
        // $msg = '[CQ:at,qq='.$Event['user_id']."]\n".$msg;
    }
    throw new \Exception($msg, $code);
}

/**
 * 检查是否在黑名单中
 * @return bool
 */
function inBlackList($qq): bool {
    $usertype = getData('usertype.json');
    if($usertype === false) return false; //无法打开黑名单时不再抛异常
    $usertype = json_decode($usertype)->Blacklist;
    foreach($usertype as $person) {
        if($qq == $person) {
            return true;
        }
    }
    return false;
}

function block($qq) {
    if($qq) if(inBlackList($qq)) exit;
}
