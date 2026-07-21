<?php
$m = new m_data();

$list = $m->getList($aData["f_userid"]);
$ajaxRet["status"] = "200";
$ajaxRet["msg"] = "Entries loaded: " . count($list);
$ajaxRet["data"] = $list;
