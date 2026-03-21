<?php

function rhMongoOptions(array $options = []): array {
    if(function_exists('getMongoOperationOptions')) {
        return getMongoOperationOptions($options);
    }

    return $options;
}

function rhUseCollection(): bool {
    if(!function_exists('getDataBackend') || getDataBackend() !== 'mongo') {
        return false;
    }

    global $Database;
    if(!isset($Database) || !class_exists('MongoDB\\BSON\\UTCDateTime')) {
        return false;
    }

    return function_exists('configBool') ? configBool('enableRhCollection', false) : false;
}

function rhCollectionDualWriteEnabled(): bool {
    return function_exists('configBool') ? configBool('rhCollectionDualWrite', true) : true;
}

function rhGetCollection() {
    global $Database;
    static $collection = null;

    if($collection !== null) return $collection;

    $collectionName = trim((string)config('mongoRhCollection', 'rh_states'));
    if($collectionName === '') $collectionName = 'rh_states';

    $collection = $Database->$collectionName;
    return $collection;
}

function rhDocId(string $type, string $id): string {
    return $type.':'.$id;
}

function rhLegacyGetGroupState($groupId): ?array {
    $raw = getData('rh/group/'.$groupId);
    if($raw === false || $raw === '') return null;

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function rhLegacySetGroupState($groupId, array $state): bool {
    return setData('rh/group/'.$groupId, json_encode($state, JSON_UNESCAPED_UNICODE)) !== false;
}

function rhLegacyDeleteGroupState($groupId): bool {
    return delData('rh/group/'.$groupId) !== false;
}

function rhLegacyGetUserData($userId): ?array {
    $raw = getData('rh/user/'.$userId);
    if($raw === false || $raw === '') return null;

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function rhLegacySetUserData($userId, array $data): bool {
    return setData('rh/user/'.$userId, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false;
}

function rhLegacyDeleteUserData($userId): bool {
    return delData('rh/user/'.$userId) !== false;
}

function rhLegacyIsHorseLocked($userId): bool {
    return getData('rh/lock/'.$userId) !== false;
}

function rhLegacyLockHorse($userId): bool {
    return setData('rh/lock/'.$userId, '1') !== false;
}

function rhLegacyUnlockHorse($userId): bool {
    return delData('rh/lock/'.$userId) !== false;
}

function rhLegacyGetForceExpire($groupId): int {
    return (int)getData('rh/force/group/'.$groupId);
}

function rhLegacySetForceExpire($groupId, int $expireAt): bool {
    return setData('rh/force/group/'.$groupId, (string)$expireAt) !== false;
}

function rhLegacyClearForce($groupId): bool {
    return delData('rh/force/group/'.$groupId) !== false;
}

function rhGetGroupState($groupId): ?array {
    $groupId = (string)$groupId;
    if(!rhUseCollection()) {
        return rhLegacyGetGroupState($groupId);
    }

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('group', $groupId)],
            rhMongoOptions(['projection' => ['state' => 1]]),
        );

        if($doc && isset($doc['state']) && is_array($doc['state'])) {
            return $doc['state'];
        }

        $legacy = rhLegacyGetGroupState($groupId);
        if($legacy !== null) {
            rhSetGroupState($groupId, $legacy);
        }

        return $legacy;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-group-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyGetGroupState($groupId);
    }
}

function rhSetGroupState($groupId, array $state): bool {
    $groupId = (string)$groupId;

    if(!rhUseCollection()) {
        return rhLegacySetGroupState($groupId, $state);
    }

    try {
        $result = rhGetCollection()->updateOne(
            ['_id' => rhDocId('group', $groupId)],
            [
                '$set' => [
                    'type' => 'group',
                    'group_id' => $groupId,
                    'state' => $state,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => rhDocId('group', $groupId)],
            ],
            rhMongoOptions(['upsert' => true]),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/group 写入未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacySetGroupState($groupId, $state);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-group-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacySetGroupState($groupId, $state);
    }
}

function rhDeleteGroupState($groupId): bool {
    $groupId = (string)$groupId;

    if(!rhUseCollection()) {
        return rhLegacyDeleteGroupState($groupId);
    }

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('group', $groupId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/group 删除未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacyDeleteGroupState($groupId);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-group-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyDeleteGroupState($groupId);
    }
}

function rhGetUserData($userId): ?array {
    $userId = (string)$userId;
    if(!rhUseCollection()) {
        return rhLegacyGetUserData($userId);
    }

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('user', $userId)],
            rhMongoOptions(['projection' => ['data' => 1]]),
        );

        if($doc && isset($doc['data']) && is_array($doc['data'])) {
            return $doc['data'];
        }

        $legacy = rhLegacyGetUserData($userId);
        if($legacy !== null) {
            rhSetUserData($userId, $legacy);
        }

        return $legacy;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-user-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyGetUserData($userId);
    }
}

function rhSetUserData($userId, array $data): bool {
    $userId = (string)$userId;

    if(!rhUseCollection()) {
        return rhLegacySetUserData($userId, $data);
    }

    try {
        $result = rhGetCollection()->updateOne(
            ['_id' => rhDocId('user', $userId)],
            [
                '$set' => [
                    'type' => 'user',
                    'user_id' => $userId,
                    'data' => $data,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => rhDocId('user', $userId)],
            ],
            rhMongoOptions(['upsert' => true]),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/user 写入未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacySetUserData($userId, $data);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-user-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacySetUserData($userId, $data);
    }
}

function rhDeleteUserData($userId): bool {
    $userId = (string)$userId;

    if(!rhUseCollection()) {
        return rhLegacyDeleteUserData($userId);
    }

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('user', $userId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/user 删除未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacyDeleteUserData($userId);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-user-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyDeleteUserData($userId);
    }
}

function rhIsHorseLocked($userId): bool {
    $userId = (string)$userId;

    if(!rhUseCollection()) {
        return rhLegacyIsHorseLocked($userId);
    }

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('lock', $userId)],
            rhMongoOptions(['projection' => ['locked' => 1]]),
        );

        if($doc) return !empty($doc['locked']);

        $legacyLocked = rhLegacyIsHorseLocked($userId);
        if($legacyLocked) {
            rhLockHorse($userId);
        }

        return $legacyLocked;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-lock-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyIsHorseLocked($userId);
    }
}

function rhLockHorse($userId): bool {
    $userId = (string)$userId;

    if(!rhUseCollection()) {
        return rhLegacyLockHorse($userId);
    }

    try {
        $result = rhGetCollection()->updateOne(
            ['_id' => rhDocId('lock', $userId)],
            [
                '$set' => [
                    'type' => 'lock',
                    'user_id' => $userId,
                    'locked' => true,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => rhDocId('lock', $userId)],
            ],
            rhMongoOptions(['upsert' => true]),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/lock 写入未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacyLockHorse($userId);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-lock-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyLockHorse($userId);
    }
}

function rhUnlockHorse($userId): bool {
    $userId = (string)$userId;

    if(!rhUseCollection()) {
        return rhLegacyUnlockHorse($userId);
    }

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('lock', $userId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/lock 删除未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacyUnlockHorse($userId);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-lock-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyUnlockHorse($userId);
    }
}

function rhGetForceExpire($groupId): int {
    $groupId = (string)$groupId;

    if(!rhUseCollection()) {
        return rhLegacyGetForceExpire($groupId);
    }

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('force', $groupId)],
            rhMongoOptions(['projection' => ['expire_at' => 1]]),
        );

        if($doc) {
            return (int)($doc['expire_at'] ?? 0);
        }

        $legacy = rhLegacyGetForceExpire($groupId);
        if($legacy > 0) {
            rhSetForceExpire($groupId, $legacy);
        }

        return $legacy;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-force-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyGetForceExpire($groupId);
    }
}

function rhSetForceExpire($groupId, int $expireAt): bool {
    $groupId = (string)$groupId;

    if(!rhUseCollection()) {
        return rhLegacySetForceExpire($groupId, $expireAt);
    }

    try {
        $result = rhGetCollection()->updateOne(
            ['_id' => rhDocId('force', $groupId)],
            [
                '$set' => [
                    'type' => 'force',
                    'group_id' => $groupId,
                    'expire_at' => $expireAt,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => rhDocId('force', $groupId)],
            ],
            rhMongoOptions(['upsert' => true]),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/force 写入未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacySetForceExpire($groupId, $expireAt);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-force-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacySetForceExpire($groupId, $expireAt);
    }
}

function rhClearForce($groupId): bool {
    $groupId = (string)$groupId;

    if(!rhUseCollection()) {
        return rhLegacyClearForce($groupId);
    }

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('force', $groupId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/force 删除未确认');
        }

        if(rhCollectionDualWriteEnabled()) {
            rhLegacyClearForce($groupId);
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-force-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return rhLegacyClearForce($groupId);
    }
}

function rhHasActiveForce($groupId): bool {
    return rhGetForceExpire((string)$groupId) > time();
}
