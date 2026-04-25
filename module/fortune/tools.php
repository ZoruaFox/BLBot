<?php

function fortuneHelpText(): string {
    return <<<EOT
用法：
#fortune                    求取今日灵签
#fortune draw               求取今日灵签
#fortune setbirth <日期> [时间]
#fortune setbirth <QQ/At> <日期> [时间]   （仅 Master 可为他人设置）
#fortune clearbirth [QQ/At]
#fortune birth <QQ/At>      （仅 Master 可查看）

中文子命令：
#fortune 求签
#fortune 设置生日 <日期> [时间]
#fortune 清除生日

日期支持示例：
1999-01-01
1999/01/01
1999.01.01
1999年1月1日
19990101

时间支持示例：
23
23:30
23:30:59
23点30分

说明：
1) 求签结果按“同用户同自然日固定”缓存，次日自动重算。
2) 生日信息默认不回显，仅 Master 可查询指定用户生日。
EOT;
}

function fortuneEnsureCalendarReady(): void {
    if(class_exists('com\\nlf\\calendar\\Solar')) {
        return;
    }

    replyAndLeave('求签系统依赖未安装：缺少 6tail/lunar-php。请先在项目根目录安装 Composer 并执行 composer require 6tail/lunar-php:^1.4');
}

function fortuneGetSolarClass(): string {
    fortuneEnsureCalendarReady();
    return 'com\\nlf\\calendar\\Solar';
}

function fortuneGetGanzhiRuleDescription(): string {
    return '年/月按立春当日切换，日按晚子时当天，时按当前时辰';
}

function fortuneResolveGanzhiPillars($lunar): array {
    $year = $lunar->getYearInGanZhiByLiChun();
    $month = $lunar->getMonthInGanZhi();
    $day = $lunar->getDayInGanZhiExact2();
    $time = $lunar->getTimeInGanZhi();

    return [
        'year' => $year,
        'month' => $month,
        'day' => $day,
        'time' => $time,
    ];
}

function fortuneFormatGanzhiPillars(array $pillars): string {
    return $pillars['year'].'年 '.$pillars['month'].'月 '.$pillars['day'].'日 '.$pillars['time'].'时';
}

function fortunePillarToWuXing(string $pillar): string {
    $utilClass = 'com\\nlf\\calendar\\util\\LunarUtil';
    if(!class_exists($utilClass)) {
        return '';
    }

    $gan = mb_substr($pillar, 0, 1, 'UTF-8');
    $zhi = mb_substr($pillar, 1, 1, 'UTF-8');
    $ganMap = $utilClass::$WU_XING_GAN;
    $zhiMap = $utilClass::$WU_XING_ZHI;

    return ($ganMap[$gan] ?? '').($zhiMap[$zhi] ?? '');
}

function fortuneResolvePillarsWuXing(array $pillars): array {
    return [
        fortunePillarToWuXing($pillars['year']),
        fortunePillarToWuXing($pillars['month']),
        fortunePillarToWuXing($pillars['day']),
        fortunePillarToWuXing($pillars['time']),
    ];
}

function fortuneClamp(float $value, float $min, float $max): float {
    if($value < $min) return $min;
    if($value > $max) return $max;
    return $value;
}

function fortuneToInt($value): int {
    return intval($value);
}

function fortuneSeed(string $seed): int {
    $hash = crc32($seed);
    if($hash < 0) {
        $hash += 4294967296;
    }
    return (int)$hash;
}

function fortuneGetAlgoVersion(): int {
    return 2;
}

function fortuneUnitFromSeed(string $seed): float {
    return (fortuneSeed($seed) + 0.5) / 4294967296.0;
}

function fortuneEpsilonProbability(float $value): float {
    return fortuneClamp($value, 1.0e-6, 1.0 - 1.0e-6);
}

function fortuneNormalCdf(float $x): float {
    $a1 = 0.254829592;
    $a2 = -0.284496736;
    $a3 = 1.421413741;
    $a4 = -1.453152027;
    $a5 = 1.061405429;
    $p = 0.3275911;

    $sign = $x < 0 ? -1.0 : 1.0;
    $z = abs($x) / sqrt(2.0);
    $t = 1.0 / (1.0 + $p * $z);
    $erf = 1.0 - (((((($a5 * $t + $a4) * $t + $a3) * $t + $a2) * $t + $a1) * $t) * exp(-$z * $z));

    return fortuneClamp(0.5 * (1.0 + $sign * $erf), 0.0, 1.0);
}

function fortuneLogit(float $probability): float {
    $p = fortuneEpsilonProbability($probability);
    return log($p / (1.0 - $p));
}

function fortuneSigmoid(float $value): float {
    if($value >= 0.0) {
        $expNeg = exp(-$value);
        return 1.0 / (1.0 + $expNeg);
    }

    $expPos = exp($value);
    return $expPos / (1.0 + $expPos);
}

function fortuneSignedPowerStretch(float $probability, float $gamma = 0.72): float {
    $p = fortuneClamp($probability, 0.0, 1.0);
    $gamma = max(0.05, $gamma);

    $signed = 2.0 * $p - 1.0;
    $magnitude = pow(abs($signed), $gamma);
    $stretched = ($signed >= 0.0 ? 1.0 : -1.0) * $magnitude;

    return fortuneClamp(($stretched + 1.0) / 2.0, 0.0, 1.0);
}

function fortuneFactorToPercentile(string $name, float $score): float {
    $clamped = fortuneClamp($score, 0.0, 100.0);

    if($name === 'base_rp') {
        return fortuneClamp(($clamped + 0.5) / 101.0, 0.0, 1.0);
    }

    $params = [
        'almanac' => ['mean' => 50.0, 'std' => 12.5],
        'deity' => ['mean' => 50.0, 'std' => 11.5],
        'zodiac' => ['mean' => 55.0, 'std' => 14.0],
        'bazi' => ['mean' => 50.0, 'std' => 12.0],
    ];

    $cfg = $params[$name] ?? ['mean' => 50.0, 'std' => 12.0];
    $z = ($clamped - $cfg['mean']) / $cfg['std'];

    return fortuneNormalCdf($z);
}

function fortuneBlendPercentiles(array $percentiles, array $weights): float {
    $sumWeights = 0.0;
    $sumLogit = 0.0;

    foreach($weights as $name => $weight) {
        $w = floatval($weight);
        if($w <= 0.0) continue;

        $p = floatval($percentiles[$name] ?? 0.5);
        $sumLogit += $w * fortuneLogit($p);
        $sumWeights += $w;
    }

    if($sumWeights <= 0.0) {
        return 0.5;
    }

    return fortuneSigmoid($sumLogit / $sumWeights);
}

function fortuneGetScoreCalibrationPath(): string {
    return 'fortune/calibration/score_v2.json';
}

function fortuneCreateScoreCalibrationState(): array {
    $binSize = 0.5;
    $binCount = intval(round(100.0 / $binSize)) + 1;

    return [
        'version' => fortuneGetAlgoVersion(),
        'bin_size' => $binSize,
        'bins' => array_fill(0, $binCount, 0),
        'total' => 0,
        'updated_at' => time(),
    ];
}

function fortuneLoadScoreCalibrationState(): array {
    $state = fortuneReadJson(fortuneGetScoreCalibrationPath());
    if(!is_array($state)) {
        return fortuneCreateScoreCalibrationState();
    }

    $binSize = floatval($state['bin_size'] ?? 0.5);
    if($binSize <= 0.0) {
        $binSize = 0.5;
    }

    $binCount = intval(round(100.0 / $binSize)) + 1;
    $bins = $state['bins'] ?? [];

    if(!is_array($bins) || count($bins) !== $binCount) {
        $bins = array_fill(0, $binCount, 0);
    } else {
        foreach($bins as $i => $value) {
            $bins[$i] = max(0, intval($value));
        }
    }

    return [
        'version' => fortuneGetAlgoVersion(),
        'bin_size' => $binSize,
        'bins' => $bins,
        'total' => array_sum($bins),
        'updated_at' => intval($state['updated_at'] ?? time()),
    ];
}

function fortuneScoreToBinIndex(float $score, float $binSize, int $binCount): int {
    $normalized = fortuneClamp($score, 0.0, 100.0);
    if($normalized >= 100.0) {
        return $binCount - 1;
    }

    $idx = intval(floor($normalized / $binSize));
    if($idx < 0) {
        return 0;
    }
    if($idx >= $binCount) {
        return $binCount - 1;
    }
    return $idx;
}

function fortuneEstimateEmpiricalPercentile(float $score, array $state): ?float {
    $bins = $state['bins'] ?? [];
    $total = intval($state['total'] ?? 0);
    if($total <= 0 || !is_array($bins) || !count($bins)) {
        return null;
    }

    $binSize = floatval($state['bin_size'] ?? 0.5);

    $normalized = fortuneClamp($score, 0.0, 100.0);
    if($normalized >= 100.0) {
        $normalized = 100.0 - 1.0e-9;
    }

    $scaled = $normalized / $binSize;
    $idx = intval(floor($scaled));
    if($idx < 0) {
        $idx = 0;
    }
    if($idx >= count($bins)) {
        $idx = count($bins) - 1;
    }
    $fraction = fortuneClamp($scaled - $idx, 0.0, 1.0);

    $cum = 0;
    for($i = 0; $i < $idx; $i++) {
        $cum += intval($bins[$i] ?? 0);
    }

    $at = intval($bins[$idx] ?? 0);
    return fortuneClamp(($cum + $at * $fraction) / $total, 0.0, 1.0);
}

function fortuneRecordScoreCalibrationSample(array $state, float $score): array {
    $bins = $state['bins'] ?? [];
    if(!is_array($bins) || !count($bins)) {
        $state = fortuneCreateScoreCalibrationState();
        $bins = $state['bins'];
    }

    $binSize = floatval($state['bin_size'] ?? 0.5);
    $idx = fortuneScoreToBinIndex($score, $binSize, count($bins));
    $bins[$idx] = intval($bins[$idx] ?? 0) + 1;

    $state['bins'] = $bins;
    $state['total'] = array_sum($bins);
    $state['updated_at'] = time();

    return $state;
}

function fortuneCalibratePercentile(float $rawScore, float $fallbackPercentile): array {
    $state = fortuneLoadScoreCalibrationState();
    $samplesBefore = intval($state['total'] ?? 0);

    $empiricalPercentile = fortuneEstimateEmpiricalPercentile($rawScore, $state);
    $warmup = fortuneClamp($samplesBefore / 2000.0, 0.0, 1.0);
    if($empiricalPercentile === null) {
        $empiricalPercentile = $fallbackPercentile;
        $warmup = 0.0;
    }

    $fallback = fortuneClamp($fallbackPercentile, 0.0, 1.0);
    $empirical = fortuneClamp($empiricalPercentile, 0.0, 1.0);
    $percentile = (1.0 - $warmup) * $fallback + $warmup * $empirical;

    $updatedState = fortuneRecordScoreCalibrationSample($state, $rawScore);
    fortuneWriteJson(fortuneGetScoreCalibrationPath(), $updatedState);

    return [
        'percentile' => fortuneClamp($percentile, 0.0, 1.0),
        'fallback_pct' => $fallback,
        'empirical_pct' => $empirical,
        'warmup' => $warmup,
        'samples_before' => $samplesBefore,
    ];
}

function fortuneGetProfilePath(string $userId): string {
    return 'fortune/profile/'.intval($userId).'.json';
}

function fortuneGetDailyPath(string $userId, string $dateYmd): string {
    return 'fortune/daily/'.$dateYmd.'/'.intval($userId).'.json';
}

function fortuneReadJson(string $path): ?array {
    $raw = getData($path);
    if($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function fortuneWriteJson(string $path, array $data): bool {
    return setData($path, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false;
}

function fortuneLoadProfile(string $userId): ?array {
    return fortuneReadJson(fortuneGetProfilePath($userId));
}

function fortuneSaveProfile(string $userId, array $profile): bool {
    return fortuneWriteJson(fortuneGetProfilePath($userId), $profile);
}

function fortuneDeleteProfile(string $userId): bool {
    return delData(fortuneGetProfilePath($userId)) !== false;
}

function fortuneLoadDailyResult(string $userId, string $dateYmd): ?array {
    return fortuneReadJson(fortuneGetDailyPath($userId, $dateYmd));
}

function fortuneSaveDailyResult(string $userId, string $dateYmd, array $result): bool {
    return fortuneWriteJson(fortuneGetDailyPath($userId, $dateYmd), $result);
}

function fortuneResolveUserIdArg(?string $arg): ?string {
    if(!$arg) return null;
    if(preg_match('/^\d+$/', $arg)) {
        return $arg;
    }

    $qq = parseQQ($arg);
    if($qq && preg_match('/^\d+$/', $qq)) {
        return $qq;
    }

    return null;
}

function fortuneNormalizeTimePart(string $raw): ?array {
    $value = trim($raw);
    if($value === '') {
        return [12, 0, 0, true];
    }

    $value = str_replace('：', ':', $value);

    if(preg_match('/^(\d{1,2})$/', $value, $m)) {
        $h = intval($m[1]);
        if($h < 0 || $h > 23) return null;
        return [$h, 0, 0, false];
    }

    if(preg_match('/^(\d{1,2})\s*:\s*(\d{1,2})(?:\s*:\s*(\d{1,2}))?$/u', $value, $m)) {
        $h = intval($m[1]);
        $i = intval($m[2]);
        $s = isset($m[3]) ? intval($m[3]) : 0;
        if($h < 0 || $h > 23 || $i < 0 || $i > 59 || $s < 0 || $s > 59) return null;
        return [$h, $i, $s, false];
    }

    if(preg_match('/^(\d{1,2})\s*(?:点|時|时)(?:\s*(\d{1,2})\s*分?)?(?:\s*(\d{1,2})\s*秒?)?$/u', $value, $m)) {
        $h = intval($m[1]);
        $i = isset($m[2]) && $m[2] !== '' ? intval($m[2]) : 0;
        $s = isset($m[3]) && $m[3] !== '' ? intval($m[3]) : 0;
        if($h < 0 || $h > 23 || $i < 0 || $i > 59 || $s < 0 || $s > 59) return null;
        return [$h, $i, $s, false];
    }

    return null;
}

function fortuneParseBirthDateTime(string $raw): ?array {
    $value = trim($raw);
    if($value === '') {
        return null;
    }

    $year = 0;
    $month = 0;
    $day = 0;
    $timeRaw = '';

    if(preg_match('/^(\d{4})(\d{2})(\d{2})(?:\s+(.*))?$/u', $value, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
        $day = intval($m[3]);
        $timeRaw = trim($m[4] ?? '');
    } else if(preg_match('/^(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})(?:\s*(?:日|号))?\s*(.*)$/u', $value, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
        $day = intval($m[3]);
        $timeRaw = trim($m[4] ?? '');
    } else if(preg_match('/^(\d{4})\s+(\d{1,2})\s+(\d{1,2})(?:\s+(.*))?$/u', $value, $m)) {
        $year = intval($m[1]);
        $month = intval($m[2]);
        $day = intval($m[3]);
        $timeRaw = trim($m[4] ?? '');
    } else {
        return null;
    }

    if($year < 1900 || $year > intval(date('Y'))) {
        return null;
    }

    if(!checkdate($month, $day, $year)) {
        return null;
    }

    $timeInfo = fortuneNormalizeTimePart($timeRaw);
    if(!$timeInfo) {
        return null;
    }

    [$hour, $minute, $second, $isDefaultTime] = $timeInfo;

    $birthTs = strtotime(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second));
    if($birthTs === false || $birthTs > time()) {
        return null;
    }

    return [
        'year' => $year,
        'month' => $month,
        'day' => $day,
        'hour' => $hour,
        'minute' => $minute,
        'second' => $second,
        'is_default_time' => $isDefaultTime,
        'date_ymd' => sprintf('%04d%02d%02d', $year, $month, $day),
        'time_his' => sprintf('%02d%02d%02d', $hour, $minute, $second),
        'datetime' => sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second),
    ];
}

function fortuneCreateProfile(string $userId, array $birth): array {
    $solarClass = fortuneGetSolarClass();

    $solar = $solarClass::fromYmdHms(
        $birth['year'],
        $birth['month'],
        $birth['day'],
        $birth['hour'],
        $birth['minute'],
        $birth['second'],
    );
    $lunar = $solar->getLunar();
    $pillars = fortuneResolveGanzhiPillars($lunar);
    $pillarWuXing = fortuneResolvePillarsWuXing($pillars);

    $existing = fortuneLoadProfile($userId) ?? [];
    $createdAt = intval($existing['created_at'] ?? time());

    return [
        'user_id' => (string)$userId,
        'birth_date' => $birth['date_ymd'],
        'birth_time' => $birth['time_his'],
        'birth_datetime' => $birth['datetime'],
        'solar_datetime' => $solar->toYmdHms(),
        'time_defaulted' => (bool)$birth['is_default_time'],
        // 业务口径采用“春节换年”的生肖，避免立春边界导致认知偏差。
        'zodiac' => $lunar->getYearShengXiao(),
        'zodiac_lunar_year' => $lunar->getYearShengXiao(),
        'birth_lunar_date' => $lunar->toString(),
        'birth_lunar_ganzhi' => fortuneFormatGanzhiPillars($pillars),
        'ganzhi_rule' => fortuneGetGanzhiRuleDescription(),
        'birth_bazi' => [
            'year' => $pillars['year'],
            'month' => $pillars['month'],
            'day' => $pillars['day'],
            'time' => $pillars['time'],
            'year_wuxing' => $pillarWuXing[0],
            'month_wuxing' => $pillarWuXing[1],
            'day_wuxing' => $pillarWuXing[2],
            'time_wuxing' => $pillarWuXing[3],
        ],
        'birth_wuxing' => $pillarWuXing,
        'created_at' => $createdAt,
        'updated_at' => time(),
    ];
}

function fortuneGetProfileCalendarDebug(array $profile): ?array {
    $birthDate = strval($profile['birth_date'] ?? '');
    if(!preg_match('/^\d{8}$/', $birthDate)) {
        return null;
    }

    $birthTime = strval($profile['birth_time'] ?? '120000');
    if(!preg_match('/^\d{6}$/', $birthTime)) {
        $birthTime = '120000';
    }

    $year = intval(substr($birthDate, 0, 4));
    $month = intval(substr($birthDate, 4, 2));
    $day = intval(substr($birthDate, 6, 2));
    $hour = intval(substr($birthTime, 0, 2));
    $minute = intval(substr($birthTime, 2, 2));
    $second = intval(substr($birthTime, 4, 2));

    if(!checkdate($month, $day, $year)) {
        return null;
    }
    if($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
        return null;
    }

    $solarClass = fortuneGetSolarClass();
    try {
        $solar = $solarClass::fromYmdHms($year, $month, $day, $hour, $minute, $second);
    } catch(\Throwable $e) {
        return null;
    }

    $lunar = $solar->getLunar();
    $pillars = fortuneResolveGanzhiPillars($lunar);
    $pillarWuXing = fortuneResolvePillarsWuXing($pillars);

    return [
        'solar_datetime' => $solar->toYmdHms(),
        'lunar_date' => $lunar->toString(),
        'lunar_ganzhi' => fortuneFormatGanzhiPillars($pillars),
        'ganzhi_rule' => fortuneGetGanzhiRuleDescription(),
        'zodiac_lunar_year' => $lunar->getYearShengXiao(),
        'bazi' => [
            'year' => $pillars['year'],
            'month' => $pillars['month'],
            'day' => $pillars['day'],
            'time' => $pillars['time'],
            'year_wuxing' => $pillarWuXing[0],
            'month_wuxing' => $pillarWuXing[1],
            'day_wuxing' => $pillarWuXing[2],
            'time_wuxing' => $pillarWuXing[3],
        ],
        'wuxing' => $pillarWuXing,
    ];
}

function fortuneGetLevelByScore(float $score): string {
    $levels = [
        ['name' => '上上签', 'min' => 90],
        ['name' => '上中签', 'min' => 80],
        ['name' => '上下签', 'min' => 70],
        ['name' => '中上签', 'min' => 60],
        ['name' => '中中签', 'min' => 50],
        ['name' => '中下签', 'min' => 40],
        ['name' => '下上签', 'min' => 30],
        ['name' => '下中签', 'min' => 20],
        ['name' => '下下签', 'min' => 0],
    ];

    foreach($levels as $level) {
        if($score >= $level['min']) {
            return $level['name'];
        }
    }

    return '下下签';
}

function fortuneGetShiChenByHour(int $hour): string {
    if($hour == 23 || $hour == 0) return '子时';
    if($hour == 1 || $hour == 2) return '丑时';
    if($hour == 3 || $hour == 4) return '寅时';
    if($hour == 5 || $hour == 6) return '卯时';
    if($hour == 7 || $hour == 8) return '辰时';
    if($hour == 9 || $hour == 10) return '巳时';
    if($hour == 11 || $hour == 12) return '午时';
    if($hour == 13 || $hour == 14) return '未时';
    if($hour == 15 || $hour == 16) return '申时';
    if($hour == 17 || $hour == 18) return '酉时';
    if($hour == 19 || $hour == 20) return '戌时';
    return '亥时';
}

function fortuneExtractWuxingSet(array $wuxingList): array {
    $set = [];
    foreach($wuxingList as $item) {
        $chars = preg_split('//u', (string)$item, -1, PREG_SPLIT_NO_EMPTY);
        foreach($chars as $char) {
            if(in_array($char, ['金', '木', '水', '火', '土'])) {
                $set[$char] = true;
            }
        }
    }
    return array_keys($set);
}

function fortuneCountShared(array $left, array $right): int {
    $l = array_fill_keys($left, true);
    $shared = 0;
    foreach($right as $item) {
        if(isset($l[$item])) {
            $shared++;
        }
    }
    return $shared;
}

function fortuneGetBaseRp(string $userId, int $timestamp): float {
    if(function_exists('getRp')) {
        return floatval(getRp($userId, $timestamp));
    }

    $seed = fortuneSeed('rp|'.$userId.'|'.date('Ymd', $timestamp));
    return floatval($seed % 101);
}

function fortunePickTemplate(string $level, int $seed): array {
    $templates = [
        '上上签' => [
            ['签文' => '鸿运开泰，诸事得时，贵人相引，所谋多成。', '解签' => '气数正盛，宜乘势而上，先难后易。'],
            ['签文' => '云开月朗，福泽相随，百事可图，宜果断行。', '解签' => '时运已至，行动胜过犹豫。'],
            ['签文' => '天心相护，谋定而成，名利并进，家宅安宁。', '解签' => '顺势布局，重在稳准。'],
        ],
        '上中签' => [
            ['签文' => '吉曜照命，进退有据，谋事多利，守正得福。', '解签' => '机遇已开，保持节奏即可。'],
            ['签文' => '时来运转，贵助渐显，先小后大，终有收成。', '解签' => '先取确定性，再图突破。'],
            ['签文' => '花开有期，心诚则灵，勤而不躁，所愿可近。', '解签' => '把握主线，勿分散精力。'],
        ],
        '上下签' => [
            ['签文' => '风和日暖，行事可进，谨言慎行，成果可期。', '解签' => '运势偏好，细节决定上限。'],
            ['签文' => '星辉有助，先稳后快，善借他力，可得佳音。', '解签' => '协作优于单打独斗。'],
            ['签文' => '路逢良机，贵在审时，择机而发，自有回响。', '解签' => '选对时点，比蛮干更重要。'],
        ],
        '中上签' => [
            ['签文' => '中和有吉，谋事可成，戒急戒躁，渐入佳境。', '解签' => '保持专注，耐心换增益。'],
            ['签文' => '平中见喜，守拙得巧，步步为营，终能见效。', '解签' => '今天适合推进长期事项。'],
            ['签文' => '时局稳健，先修根基，厚积薄发，后劲可观。', '解签' => '先把底盘打牢。'],
        ],
        '中中签' => [
            ['签文' => '阴阳相半，吉凶并行，谨慎求进，方可无失。', '解签' => '宜稳不宜赌，先易后难。'],
            ['签文' => '平运当值，守成有余，变动宜缓，静待时机。', '解签' => '控制节奏比追求速度更重要。'],
            ['签文' => '机缘未满，持心自定，少欲则安，多思则明。', '解签' => '清单化执行，减少临场波动。'],
        ],
        '中下签' => [
            ['签文' => '小阻当前，行事需缓，避其锋芒，可保安稳。', '解签' => '不宜硬碰，宜迂回处理。'],
            ['签文' => '晦明参半，得失同来，守住底线，转机可待。', '解签' => '今天适合止损与复盘。'],
            ['签文' => '心浮则失，心定则得，凡事多核，过关无忧。', '解签' => '先查错，再推进。'],
        ],
        '下上签' => [
            ['签文' => '逆风有压，宜守不宜攻，修身整顿，以待来机。', '解签' => '减目标、重执行，可避损耗。'],
            ['签文' => '事多牵绊，强行求进易折，暂避锋芒更利。', '解签' => '谨慎沟通，少承诺。'],
            ['签文' => '霾未尽散，行止当慎，缓一步可少三分险。', '解签' => '把风险显性化再动作。'],
        ],
        '下中签' => [
            ['签文' => '运势偏寒，诸事多掣，守静养锐，切忌冒进。', '解签' => '以防守策略为主。'],
            ['签文' => '纷扰易生，口舌当防，少争多避，可免后患。', '解签' => '信息先核实，情绪后表达。'],
            ['签文' => '前路多岔，择一而行，勿贪多线，保全为先。', '解签' => '聚焦一件最重要的事。'],
        ],
        '下下签' => [
            ['签文' => '黑云压阵，百事宜守，远是非，近本分，方得安宁。', '解签' => '今天重在避险，不宜硬闯。'],
            ['签文' => '时运不济，谋事多阻，先止损，后图新机。', '解签' => '把错误成本压到最低。'],
            ['签文' => '晦气未散，言行宜谨，退一步海阔，守一线生机。', '解签' => '以稳定和休整为主。'],
        ],
    ];

    $pool = $templates[$level] ?? $templates['中中签'];
    $index = $seed % count($pool);
    return $pool[$index];
}

function fortuneComputeDraw(string $userId, array $profile, int $timestamp): array {
    $solarClass = fortuneGetSolarClass();

    date_default_timezone_set('Asia/Shanghai');

    $solar = $solarClass::fromYmdHms(
        intval(date('Y', $timestamp)),
        intval(date('m', $timestamp)),
        intval(date('d', $timestamp)),
        intval(date('H', $timestamp)),
        intval(date('i', $timestamp)),
        intval(date('s', $timestamp)),
    );
    $lunar = $solar->getLunar();

    $birthDate = strval($profile['birth_date']);
    $birthTime = strval($profile['birth_time'] ?? '120000');

    $birthSolar = $solarClass::fromYmdHms(
        intval(substr($birthDate, 0, 4)),
        intval(substr($birthDate, 4, 2)),
        intval(substr($birthDate, 6, 2)),
        intval(substr($birthTime, 0, 2)),
        intval(substr($birthTime, 2, 2)),
        intval(substr($birthTime, 4, 2)),
    );
    $birthLunar = $birthSolar->getLunar();

    $nowPillars = fortuneResolveGanzhiPillars($lunar);
    $birthPillars = fortuneResolveGanzhiPillars($birthLunar);

    $dayYi = $lunar->getDayYi();
    $dayJi = $lunar->getDayJi();
    $timeYi = $lunar->getTimeYi();
    $timeJi = $lunar->getTimeJi();
    $jiShen = $lunar->getDayJiShen();
    $xiongSha = $lunar->getDayXiongSha();

    $baseRp = fortuneGetBaseRp($userId, $timestamp);

    $almanacScore = fortuneClamp(
        50
        + (count($dayYi) - count($dayJi)) * 5
        + (count($timeYi) - count($timeJi)) * 4
        + ($lunar->getDayTianShenLuck() === '吉' ? 8 : -8)
        + ($lunar->getTimeTianShenLuck() === '吉' ? 6 : -6),
        0,
        100,
    );

    $deityScore = fortuneClamp(50 + (count($jiShen) - count($xiongSha)) * 4, 0, 100);

    // 求签评分统一使用“春节换年”生肖口径。
    $birthZodiac = $birthLunar->getYearShengXiao();
    $zodiacScore = 55.0;
    if($birthZodiac !== '') {
        if($birthZodiac === $lunar->getDayShengXiao()) $zodiacScore += 12;
        if($birthZodiac === $lunar->getTimeShengXiao()) $zodiacScore += 8;
        if($birthZodiac === $lunar->getDayChongShengXiao()) $zodiacScore -= 26;
        if($birthZodiac === $lunar->getTimeChongShengXiao()) $zodiacScore -= 12;
    }
    $zodiacScore = fortuneClamp($zodiacScore, 0, 100);

    $birthPillarList = [$birthPillars['year'], $birthPillars['month'], $birthPillars['day'], $birthPillars['time']];
    $nowPillarList = [$nowPillars['year'], $nowPillars['month'], $nowPillars['day'], $nowPillars['time']];

    $pillarMatch = 0;
    $stemMatch = 0;
    $branchMatch = 0;
    for($i = 0; $i < 4; $i++) {
        if($birthPillarList[$i] === $nowPillarList[$i]) {
            $pillarMatch++;
        }

        $birthStem = mb_substr($birthPillarList[$i], 0, 1);
        $nowStem = mb_substr($nowPillarList[$i], 0, 1);
        if($birthStem === $nowStem) {
            $stemMatch++;
        }

        $birthBranch = mb_substr($birthPillarList[$i], 1);
        $nowBranch = mb_substr($nowPillarList[$i], 1);
        if($birthBranch === $nowBranch) {
            $branchMatch++;
        }
    }

    $birthWuxingSet = fortuneExtractWuxingSet(fortuneResolvePillarsWuXing($birthPillars));
    $nowWuxingSet = fortuneExtractWuxingSet(fortuneResolvePillarsWuXing($nowPillars));
    $sharedWuxing = fortuneCountShared($birthWuxingSet, $nowWuxingSet);

    $birthDayZhi = mb_substr($birthPillars['day'], 1, 1, 'UTF-8');
    $clashPenalty = $birthDayZhi === $lunar->getDayChong() ? 10 : 0;

    $baziScore = fortuneClamp(
        38
        + $pillarMatch * 12
        + $stemMatch * 4
        + $branchMatch * 4
        + $sharedWuxing * 5
        - $clashPenalty,
        0,
        100,
    );

    $factorScores = [
        'base_rp' => round($baseRp, 2),
        'almanac' => round($almanacScore, 2),
        'deity' => round($deityScore, 2),
        'zodiac' => round($zodiacScore, 2),
        'bazi' => round($baziScore, 2),
    ];

    $factorWeights = [
        'base_rp' => 0.24,
        'almanac' => 0.22,
        'deity' => 0.10,
        'zodiac' => 0.14,
        'bazi' => 0.30,
    ];

    $rawBlend = 0.0;
    foreach($factorWeights as $name => $weight) {
        $rawBlend += floatval($factorScores[$name]) * floatval($weight);
    }
    $rawBlend = fortuneClamp($rawBlend, 0.0, 100.0);

    $factorPercentiles = [];
    foreach($factorScores as $name => $value) {
        $factorPercentiles[$name] = fortuneFactorToPercentile($name, floatval($value));
    }

    $fusedPercentile = fortuneBlendPercentiles($factorPercentiles, $factorWeights);

    $percentileValues = array_values($factorPercentiles);
    $percentileSpread = max($percentileValues) - min($percentileValues);
    $dateYmd = date('Ymd', $timestamp);
    $fallbackPercentile = fortuneSignedPowerStretch($fusedPercentile, 0.88);
    $calibration = fortuneCalibratePercentile($rawBlend, $fallbackPercentile);

    $microJitter = (fortuneUnitFromSeed('fortune-micro|'.$userId.'|'.$dateYmd) - 0.5) * 0.01;
    $finalPercentile = fortuneClamp(floatval($calibration['percentile']) + $microJitter, 0.0, 1.0);

    $score = round($finalPercentile * 100.0, 2);
    $level = fortuneGetLevelByScore($score);

    $template = fortunePickTemplate($level, fortuneSeed('tpl|'.$userId.'|'.$dateYmd.'|'.$level));

    $yiSummary = array_slice(array_values(array_filter(array_unique(array_merge($dayYi, $timeYi)))), 0, 6);
    $jiSummary = array_slice(array_values(array_filter(array_unique(array_merge($dayJi, $timeJi)))), 0, 6);

    if(!count($yiSummary)) {
        $yiSummary = ['静心', '复盘', '谨慎推进'];
    }
    if(!count($jiSummary)) {
        $jiSummary = ['急躁冒进', '口舌争执', '冲动决策'];
    }

    return [
        'algo_version' => fortuneGetAlgoVersion(),
        'user_id' => (string)$userId,
        'date' => $dateYmd,
        'draw_at' => date('Y-m-d H:i:s', $timestamp),
        'hour' => intval(date('H', $timestamp)),
        'shichen' => fortuneGetShiChenByHour(intval(date('H', $timestamp))),
        'score' => $score,
        'level' => $level,
        'template' => $template,
        'yi' => $yiSummary,
        'ji' => $jiSummary,
        'lunar' => [
            'ymd_cn' => $lunar->toString(),
            'ganzhi' => fortuneFormatGanzhiPillars($nowPillars),
            'ganzhi_rule' => fortuneGetGanzhiRuleDescription(),
            'jieqi' => $lunar->getJieQi(),
            'day_luck' => $lunar->getDayTianShenLuck(),
            'time_luck' => $lunar->getTimeTianShenLuck(),
            'day_chong' => $lunar->getDayChongDesc(),
            'day_sha' => $lunar->getDaySha(),
            'day_shengxiao' => $lunar->getDayShengXiao(),
            'time_shengxiao' => $lunar->getTimeShengXiao(),
        ],
        'weights' => [
            'base_rp' => round($baseRp, 2),
            'almanac' => round($almanacScore, 2),
            'deity' => round($deityScore, 2),
            'zodiac' => round($zodiacScore, 2),
            'bazi' => round($baziScore, 2),
            'jitter' => round($microJitter * 100.0, 2),
            'raw_blend' => round($rawBlend, 2),
            'base_rp_pct' => round($factorPercentiles['base_rp'], 4),
            'almanac_pct' => round($factorPercentiles['almanac'], 4),
            'deity_pct' => round($factorPercentiles['deity'], 4),
            'zodiac_pct' => round($factorPercentiles['zodiac'], 4),
            'bazi_pct' => round($factorPercentiles['bazi'], 4),
            'fused_pct' => round($fusedPercentile, 4),
            'factor_spread' => round($percentileSpread, 4),
            'fallback_pct' => round(floatval($calibration['fallback_pct']), 4),
            'empirical_pct' => round(floatval($calibration['empirical_pct']), 4),
            'calibration_warmup' => round(floatval($calibration['warmup']), 4),
            'calibration_samples_before' => intval($calibration['samples_before']),
            'micro_jitter' => round($microJitter, 4),
            'final_pct' => round($finalPercentile, 4),
        ],
    ];
}

function fortuneBuildReply(array $draw): string {
    $lunar = $draw['lunar'];
    $template = $draw['template'];

    $jieqiText = $lunar['jieqi'] ? ('，节气：'.$lunar['jieqi']) : '';

    return implode("\n", [
        '【今日灵签】'.$draw['level'],
        '签值：'.$draw['score'].' / 100',
        '起签时辰：'.$draw['shichen'].'（'.$draw['draw_at'].'）',
        '黄历：'.$lunar['ymd_cn'].$jieqiText,
        '干支：'.$lunar['ganzhi'],
        '值日/值时：'.$lunar['day_luck'].' / '.$lunar['time_luck'].'，冲'.$lunar['day_chong'].'，煞'.$lunar['day_sha'],
        '宜：'.implode('、', $draw['yi']),
        '忌：'.implode('、', $draw['ji']),
        '签文：'.$template['签文'],
        '解签：'.$template['解签'],
        '注：同一用户同一自然日结果固定，次日自动刷新。',
    ]);
}

function fortuneGetTodayDateYmd(int $timestamp = null): string {
    $timestamp = $timestamp ?? time();
    return date('Ymd', $timestamp);
}
