<?php

namespace BLBot;

class Database {
    private $collection;
    private $primaryKey;
    private $defaultValue;
    private $collectionName;
    private $backend;

    public function __construct($collectionName, $options = []) {
        global $Database;

        $this->collectionName = (string)$collectionName;
        $this->primaryKey = $options['key'] ?? 'user_id';
        $this->defaultValue = $options['default'] ?? null;
        $this->backend = function_exists('getDataBackend') ? getDataBackend() : 'file';

        if($this->backend === 'mongo' && isset($Database)) {
            $this->collection = $Database->{$this->collectionName};
        } else {
            $this->collection = null;
        }
    }

    private function ensureMongoCollectionReady(): void {
        if($this->collection !== null) {
            return;
        }

        throw new \RuntimeException(
            "Database({$this->collectionName}) 未初始化：当前 dataBackend={$this->backend}，或 MongoDB 连接尚未就绪。"
        );
    }

    private function getFileStorePath(): string {
        return 'db/'.$this->collectionName.'.json';
    }

    private function readFileStore(): array {
        $json = getData($this->getFileStorePath());
        if($json === false || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeFileStore(array $store): bool {
        return setData(
            $this->getFileStorePath(),
            json_encode($store, JSON_UNESCAPED_UNICODE),
        ) !== false;
    }

    private function normalizeKey($key): string {
        return (string)$key;
    }

    public function set($key, $data, $options = []) {
        if($this->backend === 'mongo') {
            $this->ensureMongoCollectionReady();

            if(!isset($options['upsert'])) {
                $options['upsert'] = true;
            }

            return $this->collection->updateOne(
                [$this->primaryKey => $key],
                ['$set' => $data],
                $options,
            )->isAcknowledged();
        }

        $store = $this->readFileStore();
        $id = $this->normalizeKey($key);
        $exists = array_key_exists($id, $store);
        $upsert = $options['upsert'] ?? true;
        if(!$exists && !$upsert) {
            return false;
        }

        $doc = $exists && is_array($store[$id]) ? $store[$id] : [];
        if(is_array($data)) {
            $doc = array_merge($doc, $data);
        } else {
            $doc['value'] = $data;
        }

        $store[$id] = $doc;
        return $this->writeFileStore($store);
    }

    public function push($key, $dataName, $data, $sort = null, $upsert = true) {
        if($this->backend === 'mongo') {
            $this->ensureMongoCollectionReady();

            if(!$sort) {
                $operator = [$dataName => $data];
            } else {
                $operator = [
                    $dataName => [
                        '$each' => $data ? [$data] : [],
                        '$sort' => $sort,
                    ]
                ];
            }
            return $this->collection->updateOne(
                [$this->primaryKey => $key],
                ['$push' => $operator],
                ['upsert' => $upsert],
            )->isAcknowledged();
        }

        $store = $this->readFileStore();
        $id = $this->normalizeKey($key);
        $exists = array_key_exists($id, $store);
        if(!$exists && !$upsert) {
            return false;
        }

        $doc = $exists && is_array($store[$id]) ? $store[$id] : [];
        $list = isset($doc[$dataName]) && is_array($doc[$dataName]) ? $doc[$dataName] : [];

        if($data !== null) {
            $list[] = $data;
        }

        if($sort && is_array($sort)) {
            usort($list, function($a, $b) use ($sort) {
                foreach($sort as $field => $direction) {
                    $av = is_array($a) && array_key_exists($field, $a) ? $a[$field] : null;
                    $bv = is_array($b) && array_key_exists($field, $b) ? $b[$field] : null;
                    if($av == $bv) continue;

                    $cmp = $av <=> $bv;
                    return (int)$direction >= 0 ? $cmp : -$cmp;
                }
                return 0;
            });
        }

        $doc[$dataName] = $list;
        $store[$id] = $doc;
        return $this->writeFileStore($store);
    }

    public function pull($key, $dataName, $data, $upsert = true) {
        if($this->backend === 'mongo') {
            $this->ensureMongoCollectionReady();

            return $this->collection->updateOne(
                [$this->primaryKey => $key],
                ['$pull' => [$dataName => $data]],
                ['upsert' => $upsert],
            )->isAcknowledged();
        }

        $store = $this->readFileStore();
        $id = $this->normalizeKey($key);
        $exists = array_key_exists($id, $store);
        if(!$exists && !$upsert) {
            return false;
        }

        $doc = $exists && is_array($store[$id]) ? $store[$id] : [];
        $list = isset($doc[$dataName]) && is_array($doc[$dataName]) ? $doc[$dataName] : [];
        $target = serialize($data);
        $list = array_values(array_filter($list, fn($item) => serialize($item) !== $target));

        $doc[$dataName] = $list;
        $store[$id] = $doc;
        return $this->writeFileStore($store);
    }

    public function get($key, $projection = null) {
        if($this->backend === 'mongo') {
            $this->ensureMongoCollectionReady();

            $options = [];
            if($projection) {
                if(gettype($projection) == 'string') {
                    $projection = [$projection];
                }
                $options['projection'] = array_combine($projection, array_fill(0, count($projection), 1));
            }
            return $this->collection->findOne(
                [$this->primaryKey => $key],
                $options,
            ) ?? $this->defaultValue;
        }

        $store = $this->readFileStore();
        $id = $this->normalizeKey($key);
        if(!array_key_exists($id, $store)) {
            return $this->defaultValue;
        }

        $doc = $store[$id];
        if(!$projection || !is_array($doc)) {
            return $doc;
        }

        if(gettype($projection) == 'string') {
            $projection = [$projection];
        }

        $result = [];
        foreach($projection as $field) {
            if(array_key_exists($field, $doc)) {
                $result[$field] = $doc[$field];
            }
        }

        return $result;
    }

    public function remove($key, $data, $upsert = true) {
        if($this->backend === 'mongo') {
            $this->ensureMongoCollectionReady();

            if(!is_array($data)) {
                $data = [$data];
            }
            return $this->collection->updateOne(
                [$this->primaryKey => $key],
                ['$unset' => array_flip($data)],
                ['upsert' => $upsert],
            )->isAcknowledged();
        }

        $store = $this->readFileStore();
        $id = $this->normalizeKey($key);
        $exists = array_key_exists($id, $store);
        if(!$exists && !$upsert) {
            return false;
        }

        $doc = $exists && is_array($store[$id]) ? $store[$id] : [];
        if(!is_array($data)) {
            $data = [$data];
        }
        foreach($data as $field) {
            unset($doc[$field]);
        }

        $store[$id] = $doc;
        return $this->writeFileStore($store);
    }

    public function delete($key) {
        if($this->backend === 'mongo') {
            $this->ensureMongoCollectionReady();

            return $this->collection->deleteOne(
                [$this->primaryKey => $key],
            )->getDeletedCount();
        }

        $store = $this->readFileStore();
        $id = $this->normalizeKey($key);
        if(!array_key_exists($id, $store)) {
            return 0;
        }

        unset($store[$id]);
        return $this->writeFileStore($store) ? 1 : 0;
    }
}

