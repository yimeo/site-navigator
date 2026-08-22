<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$database = db();
$category = $database->query('SELECT id FROM categories ORDER BY sort_order ASC, id ASC LIMIT 1')->fetch();
if (!$category) {
    throw new RuntimeException('分页导航测试缺少可用分类。');
}
$selectedCategoryId = (int) $category['id'];

$database->beginTransaction();
$insert = $database->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, NULL, NULL, :sort_order, 1, 0, :created_at, :updated_at)');
try {
    for ($index = 1; $index <= 121; $index++) {
        $insert->execute(array(
            ':category_id' => $selectedCategoryId,
            ':title' => '分页导航测试网站 ' . $index,
            ':description' => '用于验证完整分页导航。',
            ':primary_url' => 'https://pagination-navigation-' . $index . '.example/',
            ':sort_order' => 1000 + $index,
            ':created_at' => now_utc(),
            ':updated_at' => now_utc(),
        ));
    }

    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_username'] = 'test-admin';
    $_GET = array('category_id' => $selectedCategoryId, 'page' => 3, 'per_page' => 20);
    $_POST = array();
    $_SERVER['SCRIPT_NAME'] = '/admin/websites.php';

    ob_start();
    require dirname(__DIR__) . '/public/admin/websites.php';
    $html = ob_get_clean();

    if (strpos($html, '>首页<') === false || strpos($html, '>上一页<') === false || strpos($html, '>下一页<') === false || strpos($html, '>尾页<') === false || strpos($html, 'aria-current="page">3</span>') === false || strpos($html, 'page=1&amp;per_page=20&amp;category_id=' . $selectedCategoryId) === false) {
        throw new RuntimeException('完整分页导航、当前页状态或分类筛选参数未正确渲染。');
    }
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
}

echo "ADMIN_PAGINATION_NAVIGATION_OK\n";
