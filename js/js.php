<?php
$do = true;
/*******************************************************************************
 * This PHP File includes all JS files of the current folder and subfolters.
 * it can be used to structure JS FIles at development stage. For the html client
 * it puts all together and executes the intit function when everything is loaded.
 ********************************************************************************/

header('Content-Type: application/javascript');
$SUBDIR = false;
$dir = __DIR__;
extract_js_contents($dir);

function extract_js_contents($dir)
{
    global $do;
    global $SUBDIR;
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file == '.' || $file == '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path) && $SUBDIR) {
            extract_js_contents($path);
        } elseif (pathinfo($path, PATHINFO_EXTENSION) == 'js') {
            if ($do) {
                echo "/* ---- FILE: $path ---*/\n";
                readfile($path);
            }
        }
    }
}
if ($do) echo "window.onload = init;";
if (!$do) readfile($dir . "/scrambled.txt");
