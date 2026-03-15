<?php

global $Queue;
requireLvl(6);
use kjBot\SDK\CQCode;
loadModule('exp.tools');

$QQ = nextArg();
if(!(preg_match('/\d+/', $QQ, $match) && $match[0] == $QQ)){
    $QQ = parseQQ($QQ);
}
if(!$QQ){
    replyAndLeave('请指定要设置经验的对象。');
}
$exp = (int)nextArg();
setExp($QQ, $exp);

$Queue[]= sendBack('已将 '.CQCode::At($QQ).' 的经验设置为 '.$exp);

?>
