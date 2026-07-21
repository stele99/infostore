<?php
require_once "./inc/config.inc.php";
require_once ROOTPATH . "/inc/functions.inc.php";
require_once ROOTPATH . "/src/class_dbobject.php";
require_once ROOTPATH . "/src/class_data.php";
require_once ROOTPATH . "/src/class_user.php";
require_once ROOTPATH . "/src/class_share.php";

$ajaxRet["status"] = "204";
$ajaxRet["msg"]   = "no content";
if (isset($_GET["m"])) {
    $method = $_GET["m"];
    $fn_inc = ROOTPATH . "/ajax/$method.inc.php";
    $aData = json_decode(file_get_contents('php://input'), true);
    if (file_exists($fn_inc)) {
        require_once $fn_inc;
    }
} else {
    $ajaxRet["status"] = 404;
    $ajaxRet["msg"] = "Method not found";
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($ajaxRet);
