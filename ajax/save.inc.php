<?php

$m = new m_data();
$m->loadfromUid($aData["f_uid"]);
foreach ($m->attr as $k => $val) {
    if (isset($aData["f_" . $k])) {
        $m->attr["$k"] = $aData["f_" . $k];
    }
}
$m->save();
$ajaxRet["status"] = "200";
$ajaxRet["msg"] = $m->attr["uid"] . " saved.";
$ajaxRet["data"]["uid"] = $m->attr["uid"];
