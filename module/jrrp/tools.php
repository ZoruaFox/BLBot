<?php

function rol($num, $k, $bits = 64) {
    // PHP doesn't have native 64-bit int limits that exactly match python's shifting out of the box in all OSes
    // But since it's 64-bit environment, we can emulate it:
    $mask = (1 << $bits) - 1;
    if ($mask === 0) $mask = -1; // Fallback for exactly 64-bit bounds
    
    // Equivalent to (num << k) & mask
    $b1 = ($num << $k) & $mask;
    return $b1;
}

function get_hash($string) {
    // Ensure we are working with big ints, using string manipulation or GMP if strictly needed.
    // However, PHP ints are 64-bit usually.
    $num = 5381;
    $len = strlen($string);
    for ($i = 0; $i < $len; $i++) {
        $num = (rol($num, 5) ^ $num ^ ord($string[$i]));
    }
    return $num ^ 12218072394304324399; // May overflow float if not careful, but works implicitly as int in 64bit
}

function getRp($user, $timestamp = 0){
	date_default_timezone_set('Asia/Shanghai');
	if(!$timestamp) $timestamp = time();
	
    $yday = (intval(date('z', $timestamp)) + 1);
    $year = date('Y', $timestamp);
    $mday = date('j', $timestamp);

    $hash1 = get_hash("asdfgbn" . $yday . "12#3$45" . $year . "IUY");
    $hash2 = get_hash("QWERTY" . $user . "0*8&6" . $mday . "kjhg");
    
    $num = round(abs($hash1 / 3 + $hash2 / 3) / 527) % 1001;
    
    if ($num >= 970) {
        $num2 = 100;
    } else {
        $num2 = round($num / 969 * 99);
    }
    
    return intval($num2);
}

?>
