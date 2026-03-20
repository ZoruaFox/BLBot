<?php

global $Queue, $Message;

$trash = trim(nextArg(true) ?? '');
if(!$trash) replyAndLeave('想查什么垃圾呢？');

if(preg_match('/\[CQ:/', $trash)) {
    replyAndLeave('要查询的物品必须是纯文本哦…');
}

// 缓存使用哈希键，避免特殊字符导致路径问题
$cacheKey = 'trash/'.md5(mb_strtolower($trash, 'UTF-8'));
$text = getData($cacheKey);
if(!$text) {
    $apiTemplate = config('trashApiTemplate', 'https://api.vvhan.com/api/lajifenlei?name={keyword}');
    $apiUrl = str_replace('{keyword}', urlencode($trash), $apiTemplate);

    $resp = fetchHttp($apiUrl, 6);
    $items = [];

    if($resp !== false && $resp !== null && trim($resp) !== '') {
        $result = json_decode($resp, true);
        if(is_array($result)) {
            // 1) 兼容原接口：kw_arr
            if(isset($result['kw_arr']) && is_array($result['kw_arr'])) {
                foreach($result['kw_arr'] as $kw) {
                    $name = $kw['Name'] ?? $kw['name'] ?? null;
                    $type = $kw['TypeKey'] ?? $kw['type'] ?? $kw['sort'] ?? null;
                    if($name && $type) {
                        $items[] = ['name' => $name, 'type' => $type];
                    }
                }
            }

            // 2) 兼容常见单条返回
            if(!count($items)) {
                $name = $result['Name'] ?? $result['name'] ?? $result['keyword'] ?? null;
                $type = $result['TypeKey'] ?? $result['type'] ?? $result['sort'] ?? $result['category'] ?? null;
                if($name && $type) {
                    $items[] = ['name' => $name, 'type' => $type];
                }
            }

            // 3) 兼容常见 data 列表 / data 对象
            if(!count($items) && isset($result['data'])) {
                $data = $result['data'];
                if(is_array($data)) {
                    $rows = isset($data[0]) ? $data : [$data];
                    foreach($rows as $row) {
                        if(!is_array($row)) continue;
                        $name = $row['Name'] ?? $row['name'] ?? $row['keyword'] ?? null;
                        $type = $row['TypeKey'] ?? $row['type'] ?? $row['sort'] ?? $row['category'] ?? null;
                        if($name && $type) {
                            $items[] = ['name' => $name, 'type' => $type];
                        }
                    }
                }
            }
        }
    }

    if(!count($items)) {
        $text = "垃圾分类服务暂时不可用，或未查询到“{$trash}”的分类。";
        // 查询失败不缓存，避免临时故障导致长期错误结果
    } else {
        $text = 'Bot 觉得：';
        foreach($items as $item) {
            $text .= "\n{$item['name']} 是 {$item['type']}";
        }
        setData($cacheKey, $text);
    }
}

$Queue[] = replyMessage(trim($text));
