<?php
$m = new m_data();
$m->loadfromUid($aData["f_uid"]);
$m->delete();
$ajaxRet["status"] = "200";
$ajaxRet["msg"] = $m->attr["uid"] . " deleted.";
