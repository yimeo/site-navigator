<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_SESSION['_csrf'] = str_repeat('a', 64);
$_SERVER['SCRIPT_NAME'] = '/admin/websites.php';
$_GET = array('page' => 1, 'per_page' => 20);
$_POST = array(
    'action' => 'bulk_site_action',
    '_csrf' => $_SESSION['_csrf'],
    'bulk_operation' => 'delete',
    'website_ids' => array(),
);

ob_start();
require dirname(__DIR__) . '/public/admin/websites.php';
$html = ob_get_clean();

if (strpos($html, '请先选择至少一个网站。') === false) {
    throw new RuntimeException('批量操作未阻止空选择请求。');
}

echo "ADMIN_BULK_GUARD_OK\n";
