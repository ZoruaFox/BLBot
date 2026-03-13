<?php

function getCredit($QQ) {
    return (int)getData("credit/{$QQ}");
}

function setCredit($QQ, $credit, $set = false){
    if($set)setData('credit.history', "* {$QQ} {$credit}\n", true);
    return setData("credit/{$QQ}", (int)$credit);
}

function addCredit($QQ, $income) {
    $income = (int)$income;
    if($income < 0) return false;
    setData('credit.history', "+ {$QQ} {$income}\n", true);
    return setCredit($QQ, getCredit($QQ)+$income, true);
}

function decCredit($QQ, $pay, $force = false) {
    $pay = (int)$pay;
    if($pay < 0) return false;
    $balance = getCredit($QQ);
    if($balance >= $pay || $force) {
        setData('credit.history', "- {$QQ} {$pay}\n");
        return setCredit($QQ, (int)($balance - $pay), true);
    } else {
        if($balance < 0) {
            replyAndLeave('余额不足且当前处于负债状态！你需要通过签到等方式偿还债务哦～（你还需要 '.($pay - $balance).' 个金币才能使用该功能）');
        } else if($balance == 0) {
            replyAndLeave('余额不足，签到即可获取金币哦！');
        } else {
            replyAndLeave('余额不足，还需要 '.($pay - $balance).' 个金币哦，多多签到获取金币吧！');
        }
    }
}

function transferCredit($from, $to, $transfer, $feeRatio = 0.01) {
    if ($transfer <= 0) return 0;
    
    // Calculate fee strictly independently to avoid floating point multiplier issues
    $fee = ceil($transfer * $feeRatio);
    
    // Explicit integer casting to protect decCredit/addCredit
    decCredit($from, (int)($transfer + $fee));
    addCredit($to, (int)$transfer);
    
    return $fee;
}
