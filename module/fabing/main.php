<?php

// https://github.com/vikiboss/60s

global $Event;

loadModule('nickname.tools');
requireLvl(1);

$target = nextArg(true);
if(!$target) $target = getNickname($Event['user_id']);
replyAndLeave(fetchHttp('https://60s.viki.moe/v2/fabing?encoding=text&name='.urlencode($target), 3));
