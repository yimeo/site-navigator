#!/usr/bin/env php
<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$onlyId = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--id=') === 0) {
        $onlyId = (int) substr($argument, 5);
    }
}

$sql = 'SELECT w.*, h.primary_status, h.primary_checked_at, h.backup_status, h.backup_checked_at FROM websites w LEFT JOIN website_health h ON h.website_id = w.id WHERE w.is_active = 1';
$params = array();
if ($onlyId) {
    $sql .= ' AND w.id = :id';
    $params[':id'] = $onlyId;
}
$sql .= ' ORDER BY w.id ASC';
$statement = db()->prepare($sql);
$statement->execute($params);
$websites = $statement->fetchAll();
$checker = new HealthChecker(app_config());
$up = 0;
$down = 0;

foreach ($websites as $website) {
    $result = $checker->refreshWebsite($website);
    if ($result['primary']['status'] === 'up') { $up++; } else { $down++; }
    echo sprintf("[%s] #%d %s | 主：%s%s\n", now_utc(), $website['id'], $website['title'], $result['primary']['status'], $website['backup_url'] ? ' | 备：' . $result['backup']['status'] : '');
}
echo sprintf("完成：共检测 %d 个网站，主地址可用 %d 个，不可用 %d 个。\n", count($websites), $up, $down);
