<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_SESSION['_flash'] = array(
    'success' => '已收录“示例网站”。',
    'error' => '已跳过 1 条：示例失败原因。',
);
$_POST = array();

ob_start();
require dirname(__DIR__) . '/public/admin/index.php';
$html = ob_get_clean();

if (strpos($html, 'toast toast-success') === false || strpos($html, 'toast toast-error') === false) {
    throw new RuntimeException('通知窗口状态标记未正确渲染。');
}
if (strpos($html, 'data-close-toast') === false || strpos($html, '已收录“示例网站”。') === false || strpos($html, '已跳过 1 条：示例失败原因。') === false) {
    throw new RuntimeException('通知窗口内容或关闭控件未正确渲染。');
}

echo "ADMIN_TOAST_RENDER_OK\n";
