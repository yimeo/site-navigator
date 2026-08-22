<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION = array(
    '_csrf' => str_repeat('a', 64),
    '_login_captcha' => array(
        'question' => '6 + 2 = ?',
        'answer_hash' => hash('sha256', '8'),
        'expires_at' => time() + 60,
    ),
);
$_GET = array();
$_POST = array(
    'action' => 'login',
    '_csrf' => $_SESSION['_csrf'],
    'username' => 'test-admin',
    'password' => 'not-used',
    'captcha_answer' => '7',
);
$_SERVER['SCRIPT_NAME'] = '/admin/index.php';

require dirname(__DIR__) . '/public/admin/index.php';
