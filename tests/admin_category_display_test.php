<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$database = db();
$categoryRows = $database->query('SELECT id FROM categories ORDER BY sort_order ASC, id ASC LIMIT 2')->fetchAll();
if (count($categoryRows) === 0) {
    throw new RuntimeException('分类显示测试缺少可用分类。');
}
$category = $categoryRows[0];
$selectedCategoryId = (int) $category['id'];
$otherCategoryId = isset($categoryRows[1]) ? (int) $categoryRows[1]['id'] : 0;
$database->beginTransaction();
$insert = $database->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, NULL, NULL, 100, 1, 0, :created_at, :updated_at)');
$insert->execute(array(
    ':category_id' => $selectedCategoryId,
    ':title' => '分类显示测试网站',
    ':description' => '用于验证后台按分类显示模式。',
    ':primary_url' => 'https://category-display-test.example/',
    ':created_at' => now_utc(),
    ':updated_at' => now_utc(),
));
if ($otherCategoryId === 0) {
    $categoryInsert = $database->prepare('INSERT INTO categories (name, slug, sort_order, is_active, created_at, updated_at) VALUES (:name, :slug, 999, 1, :created_at, :updated_at)');
    $categoryInsert->execute(array(':name' => '分类筛选测试分类', ':slug' => 'category-filter-test', ':created_at' => now_utc(), ':updated_at' => now_utc()));
    $otherCategoryId = (int) $database->lastInsertId();
}
$insert->execute(array(
    ':category_id' => $otherCategoryId,
    ':title' => '分类筛选隐藏网站',
    ':description' => '不应显示在当前分类筛选结果中。',
    ':primary_url' => 'https://category-filter-hidden.example/',
    ':created_at' => now_utc(),
    ':updated_at' => now_utc(),
));

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_GET = array('category_id' => $selectedCategoryId, 'page' => 1, 'per_page' => 20);
$_POST = array();
$_SERVER['SCRIPT_NAME'] = '/admin/websites.php';

try {
    ob_start();
    require dirname(__DIR__) . '/public/admin/websites.php';
    $html = ob_get_clean();

    if (strpos($html, 'name="category_id"') === false || strpos($html, 'value="' . $selectedCategoryId . '" selected') === false || strpos($html, '分类显示测试网站') === false || strpos($html, '分类筛选隐藏网站') !== false || strpos($html, 'name="_return_category_id" value="' . $selectedCategoryId . '"') === false) {
        throw new RuntimeException('单分类筛选结果、分类控件或返回参数未正确渲染。');
    }

    if (strpos($html, 'category-group-row') !== false) {
        throw new RuntimeException('单分类筛选不应展示全部分类分组标题。');
    }
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
}

echo "ADMIN_CATEGORY_DISPLAY_OK\n";
