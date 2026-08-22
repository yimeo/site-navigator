<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION = array();
$question = login_captcha_refresh();
if (!preg_match('/^\d+\s[+−]\s\d+\s= \?$/u', $question) || !isset($_SESSION['_login_captcha']['answer_hash'], $_SESSION['_login_captcha']['expires_at'])) {
    throw new RuntimeException('验证码未正确生成。');
}

$_SESSION['_login_captcha'] = array('question' => '6 + 2 = ?', 'answer_hash' => hash('sha256', '8'), 'expires_at' => time() + 60);
if (!verify_login_captcha('8') || isset($_SESSION['_login_captcha'])) {
    throw new RuntimeException('正确验证码未通过或未一次性失效。');
}

$_SESSION['_login_captcha'] = array('question' => '6 + 2 = ?', 'answer_hash' => hash('sha256', '8'), 'expires_at' => time() + 60);
if (verify_login_captcha('7') || isset($_SESSION['_login_captcha'])) {
    throw new RuntimeException('错误验证码不应通过。');
}

$_SESSION['_login_captcha'] = array('question' => '6 + 2 = ?', 'answer_hash' => hash('sha256', '8'), 'expires_at' => time() - 1);
if (verify_login_captcha('8') || isset($_SESSION['_login_captcha'])) {
    throw new RuntimeException('过期验证码不应通过。');
}

$admin = file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$css = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
if (strpos($admin, 'name="captcha_answer"') === false || strpos($admin, '验证码不正确或已过期') === false || strpos($admin, 'refresh_captcha=1') === false || strpos($css, '.captcha-question') === false) {
    throw new RuntimeException('登录页验证码控件或失败提示未正确输出。');
}

echo "ADMIN_LOGIN_CAPTCHA_OK\n";
