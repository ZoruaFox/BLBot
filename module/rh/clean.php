<?php

loadModule('rh.common');

global $Event, $Queue;
requireAdmin();
rhDeleteGroupState($Event['group_id']);
rhClearForce($Event['group_id']);
$Queue[]= replyMessage('Done.');

?>
