<?php

global $Config, $Event, $Message, $Queue;

if(!preg_match('/^\[.+\]$/', preg_replace('/\[CQ:(emoji|face|emoji|at).+?\]/', '', $Message)) && !preg_match('/^(\[.+\])?\//', $Message)) {
    if (!function_exists('parsePicId')) {
        function parsePicId($str) {
            return preg_replace('/\[CQ:image,file=.+?fileid=(.+?)_.+?\]/', '$1', $str);
        }
    }

    $threshold = isset($Config['repeatThreshold']) ? (int)$Config['repeatThreshold'] : 3;
    if ($threshold <= 1) {
        $threshold = 2; // 避免阈值过低导致每次发言都复读
    }

    $parsedMsg = parsePicId($Message);
    
    $stateJson = getData('repeat/'.$Event['group_id'].'.json');
    $state = $stateJson ? json_decode($stateJson, true) : ['msg' => '', 'count' => 0, 'repeated' => false];
    
    if (isset($state['msg']) && $state['msg'] === $parsedMsg) {
        $state['count']++;
    } else {
        $state['msg'] = $parsedMsg;
        $state['count'] = 1;
        $state['repeated'] = false;
    }
    
    if ($state['count'] >= $threshold && empty($state['repeated'])) {
        $state['repeated'] = true;
        if(coolDown('repeat/'.$Event['group_id']) > 0) {
            coolDown('repeat/'.$Event['group_id'], 60);
            $Queue[] = sendBack($Message);
        }
        setData('repeat/'.$Event['group_id'].'.json', json_encode($state, JSON_UNESCAPED_UNICODE));
        leave();
    } else {
        setData('repeat/'.$Event['group_id'].'.json', json_encode($state, JSON_UNESCAPED_UNICODE));
    }
}

