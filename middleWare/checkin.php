<?php

global $Message;

if(strtolower($Message) == 'qd' || $Message == '签到'){
	loadModule('checkin');
	leave();
}

?>
