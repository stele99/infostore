<?php
error_reporting(E_ALL ^ E_NOTICE);
ini_set("display_errors", "on");

DEFINE("ROOTPATH", '/home/www/4-host/app/infostore');
DEFINE("ROOTHTTP", "https://app.4-host.de/infostore");


// DB Init
$C["DB"]        = "sqlite";
$C["PDO"]       = 'sqlite:' . ROOTPATH . "/.data/data.sqlite3"; // Database Connection
$C["PDO_PWD"]   = "";
$C["PDO_USER"]  = "";

$C["DB_INIT"]  = true; // Init DB and create tables on first call

$db = initDB();
function initDB()
{
    global $C;
    if ($C["DB_INIT"] == true) {

        $sql = "
          CREATE TABLE IF NOT EXISTS data (
                          'id' INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                          userid          TEXT,
                          uid             TEXT,
                          iv              TEXT,
                          title           TEXT,
                          data            TEXT,
                          ts_changed      TEXT,
                          ts_created      TEXT
    			);
                
          CREATE TABLE IF NOT EXISTS users (
                          'id' INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                          userid          TEXT,
                          pwhash          TEXT,
                          verification    TEXT,
                          ts_created      TEXT
    			);
          
                
          CREATE TABLE IF NOT EXISTS shares (
                          'id' INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                          uid             TEXT,
                          storeid         TEXT,
                          key             TEXT,
                          mail            TEXT,
                          delay           TEXT,
                          iv              TEXT,
                          status          TEXT,
                          ts_changed      TEXT,
                          ts_created      TEXT
    			);                
                
        ";

        // Anpassen bei mysql
        if ($C["DB"] == "mysql") {
            $pdo = $C["PDO"];
            $db = new PDO($pdo, $C["PDO_USER"], $C["PDO_PWD"]);
            $sql = str_replace("AUTOINCREMENT", "AUTO_INCREMENT", $sql);
            $sql = str_replace("INTEGER", "INT", $sql);
            $sql = str_replace("TEXT", "VARCHAR(255)", $sql);
            $sql = str_replace("NUMERIC", "DECIMAL(10,2)", $sql);
        } else {

            $pdo = $C["PDO"];
            $db = new PDO($pdo);
        }
        $db->exec($sql);
    } // initDB nur bei Bedarf.

    if ($C["DB"] == "mysql") {
        $pdo = $C["PDO"];
        $db = new PDO($pdo, $C["PDO_USER"], $C["PDO_PWD"]);
    } else if ($C["DB"] == "sqlite") {

        $db = new PDO($C["PDO"]);
    }

    return $db;
}
