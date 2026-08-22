<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$database = db();
$category = $database->query('SELECT id FROM categories ORDER BY sort_order ASC, id ASC LIMIT 1')->fetch();
if (!$category) {
    throw new RuntimeException('编辑读取测试缺少可用分类。');
}

$database->beginTransaction();
$insert = $database->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, NULL, NULL, 100, 1, 0, :created_at, :updated_at)');
$originalUrl = 'https://edit-inspect-original.example/';
$insert->execute(array(
    ':category_id' => (int) $category['id'],
    ':title' => '编辑读取测试网站',
    ':description' => '用于验证读取操作不会保存新域名。',
    ':primary_url' => $originalUrl,
    ':created_at' => now_utc(),
    ':updated_at' => now_utc(),
));
$websiteId = (int) $database->lastInsertId();

try {
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_username'] = 'test-admin';
    $_SESSION['_csrf'] = str_repeat('c', 64);
    $_SERVER['SCRIPT_NAME'] = '/admin/websites.php';
    $_GET = array('page' => 1, 'per_page' => 20);
    $_POST = array(
        'action' => 'inspect_edit_site',
        '_csrf' => $_SESSION['_csrf'],
        'website_id' => $websiteId,
        'primary_url' => 'http://127.0.0.1/',
        'title' => '不应保存的标题',
    );

    ob_start();
    require dirname(__DIR__) . '/public/admin/websites.php';
    $html = ob_get_clean();
    $website = get_website($websiteId);

    if (!$website || $website['primary_url'] !== $originalUrl || strpos($html, 'id="editSiteForm"><input type="hidden" name="action" value="update_site"') === false || strpos($html, 'data-inspect-edit-site') === false || strpos($html, 'type="button" data-inspect-edit-site') === false || strpos($html, 'class="modal-backdrop open" id="editSiteModal"') === false || strpos($html, 'http://127.0.0.1/') === false || strpos($html, '请注意') === false || strpos($html, 'window.editSiteError') === false) {
        throw new RuntimeException('编辑读取失败后未保留弹窗、输入或错误提示，或读取与保存按钮动作未正确分离。');
    }
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
}

echo "ADMIN_EDIT_INSPECT_NO_SAVE_OK\n";
