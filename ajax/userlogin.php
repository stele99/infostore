<?php

$userId            = $aData["userid"];
$pw                = $aData["pw"];
$verificationI     = $aData["verification"];
$u = new m_user();

$verification = $u->checkLogin($userId, $pw);
if ($verification == 403) {
    $ajaxRet["status"]   = 403;
    $ajaxRet["msg"]      = "Store already exists, but you are not authorized.";
} elseif ($verification == 404) {
    // crate User
    $u->create($userId, $verificationI, $pw);
    $ajaxRet["status"]   = 201;
    $ajaxRet["msg"]      = "New store created";
    $ajaxRet["data"]     = $verificationI;
} else {
    $ajaxRet["status"]   = 200;
    $ajaxRet["msg"]      = "Serverside Authorized!";
    $ajaxRet["data"]     = $verification;
}
echo "hier";
