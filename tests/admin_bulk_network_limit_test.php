<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_SESSION['_csrf'] = str_repeat('b', 64);
$_SERVER['SCRIPT_NAME'] = '/admin/websites.php';
$_GET = array('page' => 1, 'per_page' => 200);
$_POST = array(
    'action' => 'bulk_site_action',
    '_csrf' => $_SESSION['_csrf'],
    'bulk_operation' => 'check',
    'website_ids' => range(1, 21),
);

ob_start();
require dirname(__DIR__) . '/public/admin/websites.php';
$html = ob_get_clean();

if (strpos($html, '每次最多处理 20 个网站') === false) {
    throw new RuntimeException('大批量检测未在请求外部网站前触发安全保护。');
}

echo "ADMIN_BULK_NETWORK_LIMIT_OK\n";
