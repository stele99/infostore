<?php
$s = new m_share();
$shares = $s->getSharesByStoreId($aData["storeid"]);

$ajaxRet["status"] = "200";
$ajaxRet["msg"] = "";
$ajaxRet["data"] = $shares;
