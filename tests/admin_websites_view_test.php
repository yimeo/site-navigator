<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_GET = array('page' => 1, 'per_page' => 50);
$_POST = array();
$_SERVER['SCRIPT_NAME'] = '/admin/websites.php';

ob_start();
require dirname(__DIR__) . '/public/admin/websites.php';
$html = ob_get_clean();

if (strpos($html, '<h1>网站列表</h1>') === false || strpos($html, 'data-bulk-site-form') === false || strpos($html, 'name="per_page"') === false || strpos($html, 'value="50" selected') === false) {
    throw new RuntimeException('独立网站列表、每页数量选择或批量管理标记未正确渲染。');
}

echo "ADMIN_WEBSITES_VIEW_OK\n";
