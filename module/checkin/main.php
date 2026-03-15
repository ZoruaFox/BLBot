<?php

global $Event, $Queue, $User_id, $Message, $CQ;
requireLvl(0);
loadModule('credit.tools');
loadModule('exp.tools');
loadModule('attack.tools');
loadModule('jrrp.tools');
loadModule('nickname.tools');

$word = $Message ?? '签到';
$c1 = mb_substr($word, 0, 1);
$c2 = mb_substr($word, 1);

switch(getStatus($User_id)) {
    case 'imprisoned':
        $reply = "监狱里貌似没法{$word}呢…";
        break;

    case 'confined':
        $reply = "禁闭室里貌似没法{$word}呢…";
        break;

    case 'arknights':
    case 'genshin':
        $reply = "身处异世界的你貌似找不到要去哪里{$word}…";
        break;

    case 'universe':
        $reply = '你已经不在地球上了…';
        break;

    case 'saucer':
        $reply = "你被外星人抓走了，无法{$word}了…";
        break;

    case 'hospitalized':
    case 'free':
    default:
        $credit = getCredit($User_id);

        $lowMin = (int)config('checkinLowIncomeMin', '10000');
        $lowMax = (int)config('checkinLowIncomeMax', '100000');
        $highMin = (int)config('checkinHighIncomeMin', '1000');
        $highMax = (int)config('checkinHighIncomeMax', '10000');

        if($credit < 1000000) {
            $income = rand($lowMin, $lowMax);
        } else if($credit < 10000000) {
            $income = rand((int)ceil($lowMin - ($credit - 1000000) * 0.001), (int)ceil($lowMax - ($credit - 1000000) * 0.001));
        } else {
            $income = rand($highMin, $highMax);
        }
        $income = floor(1 + $income * getRp($Event['user_id']) / 50);
        $originLvl = getLvl($Event['user_id']);

        clearstatcache();
        $checkinFilePath = '../storage/data/checkin/'.$Event['user_id'];
        $lastCheckinTime = file_exists($checkinFilePath) ? filemtime($checkinFilePath) : 0;
        if($lastCheckinTime > 0 && 0 == (int)date('Ymd') - (int)date('Ymd', $lastCheckinTime)) {
            $replys = [
                "你今天{$word}过了！（震声",
                "{$word}过了www",
                "好像，{$word}，过了，呢？",
                "{$word}过了呢",
                "准备一直{$word}调戏我吗？",
                "其实你再怎么{$word}也无人在意 0 人在意 NBCS 哈",
                "{$word}{$word}，你早八{$word}了吗？",
                '嫌自己金币不够可以试试 #attack 别人',
                "Tips: 其实{$word}获得的金币一点用都没有",
                "Tips: {$word}的金币多少与今日人品有关哦！",
                "{$c1}{$c1}你的",
                "{$word}很积极，可是，你作业写完了吗？",
                "{$word}{$word}，希望你考试周复习也能跟{$word}一样积极^_^",
                "一直{$word}还是嫌金币不够的话可以试试 #checkout",
                "给你讲个鬼故事，你今天{$word}过了。",
                "你已经{$word}过了，但是你有没有听见孩子们的悲鸣？",
                "你…你失忆了？{$word}过了啊……",
                "还{$word}！再{$word}小心我扣光你的金币（",
                "{$word}过了啦（半恼）",
                "你不曾注意阴谋得逞者（指一直{$word}的你）在狞笑！",
                "{$word}成…失败！说不定今天你已经{$c1}过了呢？",
                "还{$word}？我{$c1}{$c1}你好不好？@".getNickname($Event["user_id"], $Event["group_id"])." {$word}！",
                "{$word}够了没…我都不知道说什么好……",
                "你是整天{$word}的屑[CQ:emoji,id=128052]？",
            ];
            $reply = $replys[array_rand($replys)];
        } else {
            $checkinData = json_decode(getData('checkin/stat'), true);
            if((int)date('Ymd') > (int)$checkinData['date']) {
                $checkinData['date'] = date('Ymd');
                $checkinData['checked'] = 0;
            }
            $checkinData['checked'] += 1;
            setData('checkin/stat', json_encode($checkinData));

            // 被外星人抓走的概率
            $currentHour = date('G'); // 获取当前的小时 (0 - 23)
            $abductionProbability = 0;
            if($currentHour >= 0 && $currentHour < 2) {
                $abductionProbability = 1; // 1%
            } else if($currentHour >= 3 && $currentHour < 5) {
                $abductionProbability = 5; // 5%
            } else if($currentHour >= 20 || $currentHour < 6) {
                $abductionProbability = 1; // 1%
            }
            // 判断是否被抓走
            if(rand(1, 100) <= $abductionProbability) {
                $data = getAttackData($Event['user_id']);
                $data['status'] = 'saucer';
                $abductionDuration = (int)config('abductionDuration', '86400');
                $data['end'] = date('Ymd', time() + $abductionDuration);
                $reply = '🛸天空上突然出现了一台飞碟，你被外星人抓走了…';
                $CQ->setGroupReaction($Event['group_id'], $Event['message_id'], '326');
                setAttackData($Event['user_id'], $data);
            } else {
                addCredit($Event['user_id'], $income);
                $checkinExp = (int)config('checkinExp', '1');
                addExp($Event['user_id'], $checkinExp);
                $reply = "{$word}成功，获得 {$income} 金币，{$checkinExp} 经验～";
                if(getLvl($Event['user_id']) > $originLvl) {
                    $reply .= "\n恭喜升级 Lv".getLvl($Event['user_id']).' 啦～';
                } else {
                    $exp = getExp($Event['user_id']);
                    $lvlMap = getLvlMap();
                    foreach($lvlMap as $lvl) {
                        if($lvl['lvl'] == $originLvl + 1) {
                            $expGap = $lvl['exp'] - $exp;
                            if($expGap <= 1e7) {
                                $reply .= "\n再{$word} {$expGap} 天即可升级 Lv{$lvl['lvl']}～";
                            }
                            break;
                        }
                    }
                }
                $today = date('md') == '0101' ? '今年' : '今天';
                $reply .= "\n你是{$today}第 {$checkinData['checked']} 个{$word}的～";
            }
            delData('checkin/'.$Event['user_id']);
            setData('checkin/'.$Event['user_id'], '');
        }
        break;
}

$Queue[] = replyMessage($reply);

