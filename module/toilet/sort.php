<?php

requireLvl(6);

// Order from MetroMan
$order = [
    'beijing',
    'shanghai',
    'shanghai_suburban',
    'guangzhou',
    'foshan',
    'guangdong',
    'shenzhen',
    'hongkong',
    'taipei',
    'nanjing',
    'chongqing',
    'wuhan',
    'chengdu',
    'tianjin',
    'dalian',
    'suzhou',
    'hangzhou',
    'shaoxing',
    'haining',
    'zhengzhou',
    'xian',
    'kunming',
    'ningbo',
    'changsha',
    'changchun',
    'hefei',
    'wuxi',
    'shenyang',
    'nanning',
    'nanchang',
    'qingdao',
    'kaohsiung',
    'dongguan',
    'shijiazhuang',
    'xiamen',
    'fuzhou',
    'harbin',
    'guiyang',
    'urumqi',
    'wenzhou',
    'jinan',
    'lanzhou',
    'changzhou',
    'xuzhou',
    'macau',
    'huhhot',
    'taichung',
    'taiyuan',
    'luoyang',
    'wuhu',
    'jinhua',
    'nantong',
    'taizhou'
];

$newToiletInfo = $newCitiesMeta = [];
$toiletInfo = json_decode(getData('toilet/toiletInfo.json'), true);
$citiesMeta = json_decode(getData('toilet/citiesMeta.json'), true);
setCache('toilet/'.time().'.bak', json_encode($toiletInfo));

foreach($order as $city) {
    if(array_key_exists($city, $toiletInfo)) {
        $newToiletInfo[$city] = $toiletInfo[$city];
        if (isset($citiesMeta[$city])) {
            $newCitiesMeta[$city] = $citiesMeta[$city];
        }
        unset($toiletInfo[$city]);
    }
}
// 追加在源数据中有但不在 MetroMan 排序列表里的其他城市（或者新抓取的城市）
foreach($toiletInfo as $city => $data) {
    $newToiletInfo[$city] = $data;
    if (isset($citiesMeta[$city])) {
        $newCitiesMeta[$city] = $citiesMeta[$city];
    }
}

setData('toilet/toiletInfo.json', json_encode($newToiletInfo));
setData('toilet/citiesMeta.json', json_encode($newCitiesMeta));
replyAndLeave('Done.');
