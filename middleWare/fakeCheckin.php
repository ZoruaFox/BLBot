<?php

$prob = (int)config('fakeCheckinProb', '200000');
if($prob > 0 && !rand(0, $prob))
	replyAndLeave('签到成功，获得 114514 金币，1919810 经验~');

?>
