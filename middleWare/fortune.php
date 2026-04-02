<?php

global $Message;

$keyword = trim($Message);
if(preg_match('/^(求签|求籤|抽签|抽籤|来一签|來一簽)$/u', $keyword)) {
    loadModule('fortune.draw');
    leave();
}
