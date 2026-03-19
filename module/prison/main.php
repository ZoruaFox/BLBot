<?php

global $Event, $CQ;

requireMaster();
loadModule('attack.tools');

function prisonStatusName(string $status): string {
    $map = [
        'free' => '自由',
        'imprisoned' => '监狱',
        'confined' => '禁闭室',
        'hospitalized' => '医院',
        'arknights' => '明日方舟',
        'genshin' => '原神',
        'universe' => '宇宙',
        'saucer' => '飞碟',
    ];
    return $map[$status] ?? $status;
}

function prisonParseStatus(?string $raw): ?string {
    if(!$raw) return null;
    $value = trim(mb_strtolower($raw, 'UTF-8'));

    $map = [
        'free' => 'free',
        '自由' => 'free',
        'release' => 'free',
        '释放' => 'free',

        'imprisoned' => 'imprisoned',
        'prison' => 'imprisoned',
        'jail' => 'imprisoned',
        '监狱' => 'imprisoned',
        '監獄' => 'imprisoned',

        'confined' => 'confined',
        'solitary' => 'confined',
        '禁闭' => 'confined',
        '禁闭室' => 'confined',

        'hospitalized' => 'hospitalized',
        'hospital' => 'hospitalized',
        '医院' => 'hospitalized',
        '住院' => 'hospitalized',

        'arknights' => 'arknights',
        'ark' => 'arknights',
        '明日方舟' => 'arknights',
        '方舟' => 'arknights',

        'genshin' => 'genshin',
        '原神' => 'genshin',

        'universe' => 'universe',
        'space' => 'universe',
        '宇宙' => 'universe',

        'saucer' => 'saucer',
        'ufo' => 'saucer',
        '飞碟' => 'saucer',
        '外星人' => 'saucer',
    ];

    return $map[$value] ?? null;
}

function prisonFormatYmd($ymd): string {
    $value = intval($ymd);
    if($value > 29991231) return '∞';
    $text = strval($value);
    if(strlen($text) !== 8) return $text;
    return substr($text, 0, 4).'/'.substr($text, 4, 2).'/'.substr($text, 6, 2);
}

function prisonEstimateDays(string $ymd): ?int {
    if(intval($ymd) > 29991231) return null;
    $endTs = strtotime(prisonFormatYmd($ymd));
    if(!$endTs) return null;
    $todayTs = strtotime(date('Y/m/d'));
    $diff = (int)ceil(($endTs - $todayTs) / 86400);
    return max(1, $diff);
}

function prisonBuildStatusAppliedMessage(string $status, string $at, string $end): string {
    $dateText = prisonFormatYmd($end);
    $days = prisonEstimateDays($end);
    $daysText = $days === null ? '∞' : strval($days);

    switch($status) {
        case 'imprisoned':
            return "已判处 {$at} 有期牢饭 {$daysText} 天，刑期至（{$dateText}）。";
        case 'confined':
            return "已将 {$at} 关入小黑屋 {$daysText} 天，结束禁闭时间为（{$dateText}）。";
        case 'hospitalized':
            return "已安排 {$at} 住院观察 {$daysText} 天，预计（{$dateText}）恢复活动。";
        case 'genshin':
            return "已将 {$at} 流放至提瓦特大陆，预计（{$dateText}）返回地球。";
        case 'arknights':
            return "已将 {$at} 派遣至罗德岛，预计（{$dateText}）返回地球。";
        case 'universe':
            return "已将 {$at} 发射至近地轨道，预计（{$dateText}）返回地球。";
        case 'saucer':
            return "已将 {$at} 移交外星文明观察，预计（{$dateText}）返回地球。";
        case 'free':
        default:
            return "已为 {$at} 办理释放手续，现已恢复自由状态。";
    }
}

function prisonParseDurationDays(string $value): ?int {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/\s+/u', '', $value);
    if($value === '') return null;

    // 统一常见中文单位
    $value = str_replace(['小時', '小时', '時'], 'h', $value);
    $value = str_replace(['分鐘', '分钟'], 'm', $value);
    $value = str_replace(['天'], 'd', $value);
    $value = str_replace(['周', '週', '礼拜', '禮拜'], 'w', $value);
    $value = str_replace(['个月', '個月', '月'], 'mo', $value);
    $value = str_replace(['年'], 'y', $value);
    $value = str_replace('后', '', $value);

    if(preg_match('/^\+?\d+$/', $value)) {
        return min(max(1, intval($value)), 1000000);
    }

    // 兼容如 +7d、7d12h30m、2w、1mo、1y
    if(!preg_match('/^\+?(\d+(?:mo|y|w|d|h|m|mins?|minutes?|min|hours?|hour|days?|day|weeks?|week|months?|month|years?|year))+$/u', $value)) {
        return null;
    }

    preg_match_all('/(\d+)(mo|y|w|d|h|m|mins?|minutes?|min|hours?|hour|days?|day|weeks?|week|months?|month|years?|year)/u', $value, $parts, PREG_SET_ORDER);
    if(!count($parts)) return null;

    $totalMinutes = 0;
    foreach($parts as $part) {
        $num = intval($part[1]);
        $unit = $part[2];
        if($num <= 0) continue;

        if(in_array($unit, ['y', 'year', 'years'])) {
            $totalMinutes += $num * 365 * 1440;
        } else if(in_array($unit, ['mo', 'month', 'months'])) {
            $totalMinutes += $num * 30 * 1440;
        } else if(in_array($unit, ['w', 'week', 'weeks'])) {
            $totalMinutes += $num * 7 * 1440;
        } else if(in_array($unit, ['d', 'day', 'days'])) {
            $totalMinutes += $num * 1440;
        } else if(in_array($unit, ['h', 'hour', 'hours'])) {
            $totalMinutes += $num * 60;
        } else if(in_array($unit, ['m', 'min', 'mins', 'minute', 'minutes'])) {
            $totalMinutes += $num;
        }
    }

    if($totalMinutes <= 0) return null;
    return min((int)ceil($totalMinutes / 1440), 1000000);
}

function prisonParseEndDate(string $raw): ?string {
    $value = trim($raw);

    if(preg_match('/^(∞|inf|forever|永久)$/iu', $value)) {
        return '99999999';
    }

    // 相对日期关键字
    $keywordMap = [
        'today' => 0,
        '今天' => 0,
        'tomorrow' => 1,
        '明天' => 1,
        '后天' => 2,
        '大后天' => 3,
    ];
    $keyword = mb_strtolower($value, 'UTF-8');
    if(array_key_exists($keyword, $keywordMap)) {
        $dayOffset = $keywordMap[$keyword];
        if($dayOffset <= 0) return null;
        return date('Ymd', time() + $dayOffset * 86400);
    }

    // YYYYMMDD
    if(preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
        $day = intval($m[3]);
        if(!checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d%02d%02d', $year, $month, $day);
        if(intval($date) <= intval(date('Ymd'))) {
            return null;
        }
        return $date;
    }

    // YYYY-MM-DD / YYYY/MM/DD / YYYY.MM.DD（可带时间部分）
    if(preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/', $value, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
        $day = intval($m[3]);
        if(!checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d%02d%02d', $year, $month, $day);
        if(intval($date) <= intval(date('Ymd'))) {
            return null;
        }
        return $date;
    }

    // DD-MM-YYYY / DD/MM/YYYY / DD.MM.YYYY（可带时间部分）
    if(preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/', $value, $m)) {
        $day = intval($m[1]);
        $month = intval($m[2]);
        $year = intval($m[3]);
        if(!checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d%02d%02d', $year, $month, $day);
        if(intval($date) <= intval(date('Ymd'))) {
            return null;
        }
        return $date;
    }

    // YYYY年M月D日 / YYYY年M月D号（可带时间部分）
    if(preg_match('/^(\d{4})年\s*(\d{1,2})月\s*(\d{1,2})(?:日|号)?(?:\s+\d{1,2}:\d{1,2}(?::\d{1,2})?)?$/u', $value, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
        $day = intval($m[3]);
        if(!checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d%02d%02d', $year, $month, $day);
        if(intval($date) <= intval(date('Ymd'))) {
            return null;
        }
        return $date;
    }

    // M月D日 / M月D号（自动取未来最近一次）
    if(preg_match('/^(\d{1,2})月\s*(\d{1,2})(?:日|号)?$/u', $value, $m)) {
        $month = intval($m[1]);
        $day = intval($m[2]);
        $year = intval(date('Y'));
        if(!checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d%02d%02d', $year, $month, $day);
        if(intval($date) <= intval(date('Ymd'))) {
            $year++;
            if(!checkdate($month, $day, $year)) return null;
            $date = sprintf('%04d%02d%02d', $year, $month, $day);
        }
        return $date;
    }

    // MM-DD / MM/DD / MM.DD（自动取未来最近一次）
    if(preg_match('/^(\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
        $month = intval($m[1]);
        $day = intval($m[2]);
        $year = intval(date('Y'));
        if(!checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d%02d%02d', $year, $month, $day);
        if(intval($date) <= intval(date('Ymd'))) {
            $year++;
            if(!checkdate($month, $day, $year)) return null;
            $date = sprintf('%04d%02d%02d', $year, $month, $day);
        }
        return $date;
    }

    // Duration in days/hours/minutes/weeks/months/years
    $days = prisonParseDurationDays($value);
    if($days !== null) {
        return date('Ymd', time() + $days * 86400);
    }

    return null;
}

function prisonHelpAndLeave(): never {
    replyAndLeave(<<<EOT
用法：
#prison <QQ/At> <截止日期|时长> <状态码>
#prison <QQ/At> free
#prison list

状态码支持：
- 监狱: imprisoned / prison / jail / 监狱
- 禁闭室: confined / 禁闭室 / 禁闭
- 医院: hospitalized / hospital / 医院 / 住院
- 原神: genshin / 原神
- 明日方舟: arknights / ark / 明日方舟 / 方舟
- 宇宙: universe / space / 宇宙
- 飞碟: saucer / ufo / 飞碟 / 外星人
- 自由: free / release / 自由 / 释放

时间参数示例：
- 截止日期：20251231 / 2025-12-31 / 2025/12/31 / 2025.12.31
- 中文日期：2025年12月31日 / 12月31日 / 12-31
- 其他日期：31-12-2025 / 31/12/2025
- 相对日期：明天 / 后天 / 大后天
- 时长：7 / +7 / 7d / 7天 / 36h / 90m / 2w / 1mo / 1y / 7d12h30m
- 永久：∞ / forever / 永久

注意：
1) 本指令仅限 Master 使用。
2) attack 状态按“天”结算，小时与分钟会自动向上折算到天。
EOT
);
}

$first = nextArg();
if(!$first) {
    prisonHelpAndLeave();
}

$firstLower = mb_strtolower(trim($first), 'UTF-8');
if(in_array($firstLower, ['help', 'h', '--help', '帮助', '?'])) {
    prisonHelpAndLeave();
}

if(in_array($firstLower, ['list', 'ls', '列表'])) {
    if(!fromGroup()) {
        replyAndLeave('状态列表仅支持在群聊中查看。');
    }

    $memberList = $CQ->getGroupMemberList($Event['group_id']);
    $rows = [];
    foreach($memberList as $member) {
        $status = getStatus($member->user_id);
        if($status === 'free') continue;

        $rows[] = [
            'nickname' => ($member->card ? $member->card : $member->nickname),
            'status' => $status,
            'end' => getStatusEndTime($member->user_id),
        ];
    }

    if(!count($rows)) {
        replyAndLeave('本群当前没有处于特殊状态的成员。');
    }

    usort($rows, function($a, $b) {
        $ea = $a['end'] == '∞' ? strtotime('2999/12/31') : strtotime($a['end']);
        $eb = $b['end'] == '∞' ? strtotime('2999/12/31') : strtotime($b['end']);
        return $ea <=> $eb;
    });

    $reply = '本群状态列表：';
    foreach($rows as $row) {
        $reply .= "\n@{$row['nickname']}\n　状态：".prisonStatusName($row['status'])."\n　截止：{$row['end']}";
    }
    replyAndLeave($reply);
}

$target = $first;
if(!(preg_match('/\d+/', $target, $match) && $match[0] == $target)) {
    $target = parseQQ($target);
}
$target = intval($target);
if(!$target) {
    replyAndLeave('目标解析失败，请使用 QQ 号或正确的 @ 提及。');
}

$isMaster = isMaster();
if(!$isMaster && $target == config('master')) {
    replyAndLeave('不允许对 Master 操作该状态。');
}

if(fromGroup()) {
    $targetInGroup = false;
    foreach($CQ->getGroupMemberList($Event['group_id']) as $groupMember) {
        if($groupMember->user_id == $target) {
            $targetInGroup = true;
            break;
        }
    }
    if(!$targetInGroup) {
        replyAndLeave('目标不在当前群内，无法直接在本群执行状态变更。');
    }
}

$second = nextArg();
if(!$second) {
    replyAndLeave('参数不足：请至少提供“时间+状态”或直接使用 free。可用 #prison help 查看示例。');
}

$third = nextArg();
$status = null;
$end = null;

if(!$third) {
    $status = prisonParseStatus($second);
    if($status !== 'free') {
        replyAndLeave('参数不足：非 free 状态需要“时间 + 状态码”。例如：#prison @某人 7d imprisoned');
    }
    $end = '0';
} else {
    $status = prisonParseStatus($third);
    if(!$status) {
        replyAndLeave('状态码无法识别，请检查拼写。可用 #prison help 查看支持列表。');
    }
    if($status === 'free') {
        $end = '0';
    } else {
        $end = prisonParseEndDate($second);
        if(!$end) {
            replyAndLeave('时间参数无法识别，或截止日期不晚于今天。可用 #prison help 查看可用格式。');
        }
    }
}

$data = getAttackData($target);
$oldStatus = $data['status'] ?? 'free';
$oldEnd = $data['end'] ?? '0';

$data['status'] = $status;
$data['end'] = strval($end);
if(!array_key_exists('count', $data) || !is_array($data['count'])) {
    $data['count'] = ['date' => date('Ymd'), 'times' => 0];
}
setAttackData($target, $data);

$at = "[CQ:at,qq={$target}]";
$newStatusName = prisonStatusName($status);
$oldStatusName = prisonStatusName($oldStatus);

$msg = prisonBuildStatusAppliedMessage($status, $at, $data['end']);
$msg .= "\n状态变更：{$oldStatusName} → {$newStatusName}";
if($oldStatus !== 'free') {
    $msg .= '（原截止：'.prisonFormatYmd($oldEnd).'）';
}

replyAndLeave($msg);
