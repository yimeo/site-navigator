<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$database = db();
$categories = $database->query('SELECT id FROM categories ORDER BY sort_order ASC, id ASC LIMIT 1')->fetchAll();
if (count($categories) === 0) {
    throw new RuntimeException('网站搜索测试缺少可用分类。');
}
$categoryId = (int) $categories[0]['id'];
$database->beginTransaction();
$insert = $database->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, :backup_url, NULL, 100, 1, 0, :created_at, :updated_at)');
$insert->execute(array(
    ':category_id' => $categoryId,
    ':title' => '关键词搜索隐藏项',
    ':description' => '不应匹配本次查询',
    ':primary_url' => 'https://search-hidden.example/',
    ':backup_url' => null,
    ':created_at' => now_utc(),
    ':updated_at' => now_utc(),
));
$insert->execute(array(
    ':category_id' => $categoryId,
    ':title' => '关键词搜索目标',
    ':description' => '用于验证备用域名搜索',
    ':primary_url' => 'https://search-target.example/',
    ':backup_url' => 'https://needle-search.example/',
    ':created_at' => now_utc(),
    ':updated_at' => now_utc(),
));

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_GET = array('category_id' => $categoryId, 'keyword' => 'needle-search', 'page' => 1, 'per_page' => 20);
$_POST = array();
$_SERVER['SCRIPT_NAME'] = '/admin/websites.php';

try {
    ob_start();
    require dirname(__DIR__) . '/public/admin/websites.php';
    $html = ob_get_clean();
    $script = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
    $controller = file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
    $returnUrl = website_list_return_url(2, 20, $categoryId, 'needle-search');
    $expected = array(
        strpos($html, '关键词搜索目标') !== false,
        strpos($html, '关键词搜索隐藏项') === false,
        strpos($returnUrl, 'page=2') !== false && strpos($returnUrl, 'category_id=' . $categoryId) !== false && strpos($returnUrl, 'keyword=needle-search') !== false,
        strpos($controller, 'w.title LIKE :keyword OR w.primary_url LIKE :keyword OR w.backup_url LIKE :keyword OR w.description LIKE :keyword') !== false,
        strpos($controller, "\$params['keyword'] = \$keyword") !== false,
        strpos($script, 'setupWebsiteListSearch') !== false,
        strpos($script, "searchInput.name = 'keyword'") !== false,
        strpos($script, 'searchButton') === false,
        strpos($script, "window.setTimeout(submitKeywordSearch, 380)") !== false,
        strpos($script, "searchInput.addEventListener('compositionstart'") !== false,
        strpos($script, "searchInput.addEventListener('compositionend'") !== false,
        strpos($script, "pageInput.value = '1'") !== false,
        strpos($script, "hidden.name = '_return_keyword'") !== false,
        strpos($script, "#websites form[method=\"post\"], #editSiteForm, #addSiteForm, #batchImportForm, #csvImportModal form") !== false,
    );
    if (in_array(false, $expected, true)) {
        throw new RuntimeException('网站关键词搜索、分类组合或返回条件未正确生效。');
    }
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
}

echo "ADMIN_WEBSITE_SEARCH_OK\n";
