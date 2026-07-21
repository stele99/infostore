<?php
$s = new m_share($aData["uid"]);

$s->attr["uid"]     = $aData["uid"];
$s->attr["storeid"] = $aData["storeid"];
$s->attr["key"]     = $aData["key"];
$s->attr["mail"]    = $aData["mail"];
$s->attr["delay"]   = $aData["delay"];
$s->attr["status"]  = $aData["status"];
$s->attr["ts_status"]  = $aData["ts_status"];
$s->attr["iv"]      = $aData["iv"];

$s->save();

$ajaxRet["status"] = "200";
$ajaxRet["msg"] = "";
$ajaxRet["data"] = $list;
