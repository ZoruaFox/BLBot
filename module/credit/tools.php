<?php

function useMongoCreditCollection(): bool {
    static $enabled = null;
    if($enabled !== null) return $enabled;

    global $Database;
    $enabled = function_exists('getDataBackend')
        && getDataBackend() === 'mongo'
        && isset($Database)
        && class_exists('MongoDB\\BSON\\UTCDateTime');

    return $enabled;
}

function getMongoCreditCollection() {
    global $Database;
    static $collection = null;

    if($collection !== null) return $collection;

    $collectionName = trim((string)config('mongoCreditCollection', 'credits'));
    if($collectionName === '') $collectionName = 'credits';

    $collection = $Database->$collectionName;
    return $collection;
}

function getCreditMongoOptions(array $options = []): array {
    if(function_exists('getMongoOperationOptions')) {
        return getMongoOperationOptions($options);
    }

    return $options;
}

function creditAccountExists($QQ): bool {
    $QQ = (string)$QQ;

    if(!useMongoCreditCollection()) {
        return getData("credit/{$QQ}") !== false;
    }

    $doc = getMongoCreditCollection()->findOne(
        ['_id' => $QQ],
        getCreditMongoOptions(['projection' => ['_id' => 1]]),
    );

    return is_array($doc) && isset($doc['_id']);
}

function getCredit($QQ) {
    $QQ = (string)$QQ;

    if(!useMongoCreditCollection()) {
        return (int)getData("credit/{$QQ}");
    }

    $doc = getMongoCreditCollection()->findOne(
        ['_id' => $QQ],
        getCreditMongoOptions(['projection' => ['balance' => 1]]),
    );

    return (int)($doc['balance'] ?? 0);
}

function setCredit($QQ, $credit, $set = false){
    $QQ = (string)$QQ;
    $credit = (int)$credit;

    if(!useMongoCreditCollection()) {
        if($set) setData('credit.history', "* {$QQ} {$credit}\n", true);
        return setData("credit/{$QQ}", $credit);
    }

    $result = getMongoCreditCollection()->updateOne(
        ['_id' => $QQ],
        [
            '$set' => [
                'user_id' => $QQ,
                'balance' => $credit,
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ],
            '$setOnInsert' => ['_id' => $QQ],
        ],
        getCreditMongoOptions(['upsert' => true]),
    );

    if($set) {
        setData('credit.history', "* {$QQ} {$credit}\n", true);
    }

    return $result->isAcknowledged();
}

function addCredit($QQ, $income) {
    $QQ = (string)$QQ;
    $income = (int)$income;
    if($income < 0) return false;

    if(!useMongoCreditCollection()) {
        $newCredit = getCredit($QQ) + $income;
        if(is_float($newCredit) && $newCredit > PHP_INT_MAX) {
            $newCredit = PHP_INT_MAX; // 防止金币增加导致溢出变为负数
        }
        setData('credit.history', "+ {$QQ} {$income}\n", true);
        return setCredit($QQ, (int)$newCredit, true);
    }

    $result = getMongoCreditCollection()->updateOne(
        ['_id' => $QQ],
        [
            '$inc' => ['balance' => $income],
            '$set' => [
                'user_id' => $QQ,
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ],
            '$setOnInsert' => ['_id' => $QQ],
        ],
        getCreditMongoOptions(['upsert' => true]),
    );

    if($result->isAcknowledged()) {
        setData('credit.history', "+ {$QQ} {$income}\n", true);
        return true;
    }

    return false;
}

function decCredit($QQ, $pay, $force = false) {
    $QQ = (string)$QQ;
    $pay = (int)$pay;
    if($pay < 0) return false;

    if(!useMongoCreditCollection()) {
        $balance = getCredit($QQ);
        if($balance >= $pay || $force) {
            setData('credit.history', "- {$QQ} {$pay}\n", true);
            return setCredit($QQ, (int)($balance - $pay), true);
        }

        if($balance < 0) {
            replyAndLeave('余额不足且当前处于负债状态！你需要通过签到等方式偿还债务哦～（你还需要 '.($pay - $balance).' 个金币才能使用该功能）');
        } else if($balance == 0) {
            replyAndLeave('余额不足，签到即可获取金币哦！');
        } else {
            replyAndLeave('余额不足，还需要 '.($pay - $balance).' 个金币哦，多多签到获取金币吧！');
        }
    }

    if($force) {
        $result = getMongoCreditCollection()->updateOne(
            ['_id' => $QQ],
            [
                '$inc' => ['balance' => -$pay],
                '$set' => [
                    'user_id' => $QQ,
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ],
                '$setOnInsert' => ['_id' => $QQ],
            ],
            getCreditMongoOptions(['upsert' => true]),
        );

        if($result->isAcknowledged()) {
            setData('credit.history', "- {$QQ} {$pay}\n", true);
            return true;
        }

        return false;
    }

    $result = getMongoCreditCollection()->updateOne(
        [
            '_id' => $QQ,
            'balance' => ['$gte' => $pay],
        ],
        [
            '$inc' => ['balance' => -$pay],
            '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()],
        ],
        getCreditMongoOptions(['upsert' => false]),
    );

    if($result->isAcknowledged() && $result->getModifiedCount() > 0) {
        setData('credit.history', "- {$QQ} {$pay}\n", true);
        return true;
    }

    $balance = getCredit($QQ);
    if($balance < 0) {
        replyAndLeave('余额不足且当前处于负债状态！你需要通过签到等方式偿还债务哦～（你还需要 '.($pay - $balance).' 个金币才能使用该功能）');
    } else if($balance == 0) {
        replyAndLeave('余额不足，签到即可获取金币哦！');
    } else {
        replyAndLeave('余额不足，还需要 '.($pay - $balance).' 个金币哦，多多签到获取金币吧！');
    }
}

function transferCredit($from, $to, $transfer, $feeRatio = 0.01) {
    if($transfer <= 0) return 0;

    // Calculate fee strictly independently to avoid floating point multiplier issues
    $fee = ceil($transfer * $feeRatio);
    $pay = $transfer + $fee;
    if(is_float($pay) && $pay > PHP_INT_MAX) {
        replyAndLeave('转账金额过大，无法处理呢。');
    }

    $pay = (int)$pay;
    if($pay <= 0) {
        replyAndLeave('转账金额异常，无法处理呢。');
    }

    decCredit($from, $pay);
    $addResult = addCredit($to, (int)$transfer);
    if($addResult === false) {
        // 最佳努力回滚，避免扣款成功但入账失败
        addCredit($from, $pay);
        replyAndLeave('转账失败，资金已自动回滚，请稍后重试。');
    }

    return $fee;
}

