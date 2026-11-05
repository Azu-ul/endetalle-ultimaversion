<?php
$root = '/home/claude/testrun/endetalle-app/public';
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p !== '/' && is_file($root.$p)) { return false; }
$_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['SCRIPT_FILENAME'] = $root.'/index.php';
require $root.'/index.php';
