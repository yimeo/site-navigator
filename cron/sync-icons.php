#!/usr/bin/env php
<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$all = in_array('--all', $argv, true);
$onlyId = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--id=') === 0) {
        $onlyId = (int) substr($argument, 5);
    }
}

$sql = 'SELECT * FROM websites WHERE is_active = 1';
$params = array();
if (!$all) {
    $sql .= " AND (icon_path IS NULL OR icon_path = '' OR icon_path = :default_icon)";
    $params[':default_icon'] = default_site_icon_path();
}
if ($onlyId) {
    $sql .= ' AND id = :id';
    $params[':id'] = $onlyId;
}
$sql .= ' ORDER BY id ASC';
$statement = db()->prepare($sql);
$statement->execute($params);
$websites = $statement->fetchAll();
$inspector = new SiteInspector(app_config());
$success = 0;

foreach ($websites as $website) {
    $icon = $inspector->refreshWebsiteIcon($website);
    if ($icon) { $success++; }
    echo sprintf("[%s] #%d %s | %s\n", now_utc(), $website['id'], $website['title'], $icon ? '图标已同步' : '未获取到图标');
}
echo sprintf("完成：共处理 %d 个网站，成功同步 %d 个图标。\n", count($websites), $success);
