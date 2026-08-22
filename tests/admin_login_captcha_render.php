<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION = array();
$_GET = array();
$_POST = array();
$_SERVER['SCRIPT_NAME'] = '/admin/index.php';

require dirname(__DIR__) . '/public/admin/index.php';
