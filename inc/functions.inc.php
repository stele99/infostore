<?php

/* Capsule: hier wird ab anschalten Text in eigenes Store gepackt für auswertung subrutine und kann auch separat zurückgegeben werden*/
function lg($msg = "", $capsule = null) // -1 aus, 0: rückgabe, 1 anschalten
{
    global $NO_LOG;
    static $logstore;
    static $einrücken = "";
    static $capsuleOn = false;
    static $capsuleTxt = "";
    if ($NO_LOG) return;


    if (empty($msg) && $capsule === true) {
        return $capsuleTxt;
    }

    if ($capsule === true) {
        $capsuleOn = true;
        $capsuleTxt = "";
    }
    if ($capsule === false) {
        $capsuleOn = false;
        $capsuleTxt = "";
    }

    if (empty($msg)) {
        return $logstore;
    } else {
        if (substr($msg, 0, 2) == "##") $einrücken = "  ";
        if (substr($msg, 0, 2) == "# ") $einrücken = "";
        $logstore =  $logstore . $einrücken . $msg . "\n";
        if ($capsuleOn) $capsuleTxt .= $einrücken . $msg . "\n";

        if (substr($msg, 0, 2) == "##") $einrücken = "    ";
        if (substr($msg, 0, 2) == "# ") $einrücken = "  ";
    }
}

function getTimestamp($ts = 0)
{
    $ts = (empty($ts)) ? time() : $ts;
    return date('Y-m-d H:i:s', $ts);
}


function nf($zahl, $decimals = false)
{
    $zahl = floatval($zahl,);

    if ($decimals) {
        $zahl =        number_format($zahl, $decimals, ',', '.');
    } else {
        if ($zahl < 1) {
            $zahl =        number_format($zahl, 4, ',', '.');
        } else {
            $zahl =        number_format($zahl, 2, ',', '.');
        }
    }
    return $zahl;
}
