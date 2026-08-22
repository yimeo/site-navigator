<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_GET = array();
$_POST = array();
$_SERVER['SCRIPT_NAME'] = '/admin/categories.php';

ob_start();
require dirname(__DIR__) . '/public/admin/categories.php';
$html = ob_get_clean();

if (strpos($html, '<h1>分类管理</h1>') === false || strpos($html, 'data-edit-category') === false || strpos($html, 'data-bulk-site-form') !== false) {
    throw new RuntimeException('独立分类管理页面未正确渲染。');
}

echo "ADMIN_CATEGORIES_VIEW_OK\n";
