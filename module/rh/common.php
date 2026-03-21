<?php

function rhMongoOptions(array $options = []): array {
    if(function_exists('getMongoOperationOptions')) {
        return getMongoOperationOptions($options);
    }

    return $options;
}

function rhUseCollection(): bool {
    return function_exists('getDataBackend') && getDataBackend() === 'mongo';
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

function rhGetGroupState($groupId): ?array {
    $groupId = (string)$groupId;

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('group', $groupId)],
            rhMongoOptions(['projection' => ['state' => 1]]),
        );

        if($doc && isset($doc['state']) && is_array($doc['state'])) {
            return $doc['state'];
        }

        return null;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-group-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return null;
    }
}

function rhSetGroupState($groupId, array $state): bool {
    $groupId = (string)$groupId;

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

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-group-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhDeleteGroupState($groupId): bool {
    $groupId = (string)$groupId;

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('group', $groupId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/group 删除未确认');
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-group-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhGetUserData($userId): ?array {
    $userId = (string)$userId;

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('user', $userId)],
            rhMongoOptions(['projection' => ['data' => 1]]),
        );

        if($doc && isset($doc['data']) && is_array($doc['data'])) {
            return $doc['data'];
        }

        return null;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-user-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return null;
    }
}

function rhSetUserData($userId, array $data): bool {
    $userId = (string)$userId;

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

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-user-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhDeleteUserData($userId): bool {
    $userId = (string)$userId;

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('user', $userId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/user 删除未确认');
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-user-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhIsHorseLocked($userId): bool {
    $userId = (string)$userId;

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('lock', $userId)],
            rhMongoOptions(['projection' => ['locked' => 1]]),
        );

        if($doc) return !empty($doc['locked']);

        return false;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-lock-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhLockHorse($userId): bool {
    $userId = (string)$userId;

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

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-lock-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhUnlockHorse($userId): bool {
    $userId = (string)$userId;

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('lock', $userId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/lock 删除未确认');
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-lock-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhGetForceExpire($groupId): int {
    $groupId = (string)$groupId;

    try {
        $doc = rhGetCollection()->findOne(
            ['_id' => rhDocId('force', $groupId)],
            rhMongoOptions(['projection' => ['expire_at' => 1]]),
        );

        if($doc) {
            return (int)($doc['expire_at'] ?? 0);
        }

        return 0;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-force-read', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return 0;
    }
}

function rhSetForceExpire($groupId, int $expireAt): bool {
    $groupId = (string)$groupId;

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

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-force-write', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhClearForce($groupId): bool {
    $groupId = (string)$groupId;

    try {
        $result = rhGetCollection()->deleteOne(
            ['_id' => rhDocId('force', $groupId)],
            rhMongoOptions(),
        );

        if(!$result->isAcknowledged()) {
            throw new \RuntimeException('MongoDB rh/force 删除未确认');
        }

        return true;
    } catch(\Throwable $e) {
        if(function_exists('logPersistenceWarning')) {
            logPersistenceWarning('rh-force-delete', $e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        }
        return false;
    }
}

function rhHasActiveForce($groupId): bool {
    return rhGetForceExpire((string)$groupId) > time();
}
