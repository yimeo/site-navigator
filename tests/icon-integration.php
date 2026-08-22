#!/usr/bin/env php
<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$target = isset($argv[1]) ? $argv[1] : 'https://www.wikipedia.org/';
$inspector = new SiteInspector(app_config());
$metadata = $inspector->inspect($target);
$statement = db()->prepare('INSERT INTO websites (category_id, title, description, primary_url, is_active) VALUES (:category_id, :title, :description, :primary_url, 0)');
$categoryId = (int) db()->query('SELECT id FROM categories ORDER BY sort_order LIMIT 1')->fetchColumn();
$statement->execute(array(':category_id' => $categoryId, ':title' => $metadata['title'], ':description' => $metadata['description'], ':primary_url' => $metadata['primary_url']));
$id = (int) db()->lastInsertId();
$icon = $inspector->syncIcon($metadata['icon_url'], $id);
if (!$icon) {
    fwrite(STDERR, "未获取到图标；目标网站可能没有可公开下载的图标。\n");
    exit(2);
}
$absolutePath = app_config()['base_path'] . '/public' . $icon;
if (!is_file($absolutePath) || filesize($absolutePath) === 0) {
    fwrite(STDERR, "图标文件未写入本地目录。\n");
    exit(1);
}
echo "通过：已解析 {$metadata['title']} 并保存本地图标 {$icon}。\n";
