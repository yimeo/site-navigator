#!/usr/bin/env php
<?php
$base = dirname(__DIR__);
$config = require $base . '/config/app.php';
$config['db_path'] = sys_get_temp_dir() . '/site-navigator-smoke-' . getmypid() . '.sqlite';
$config['icon_dir'] = sys_get_temp_dir() . '/site-navigator-icons-' . getmypid();
require_once $base . '/app/Database.php';
require_once $base . '/app/UrlSafety.php';

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "失败：" . $message . "\n");
        exit(1);
    }
}

try {
    $pdo = Database::connection($config);
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, '初始管理员未创建');
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() >= 4, '初始分类未创建');
    $homepageSettings = array(
        'brand_mark' => 'N',
        'home_directory_label' => '站点目录',
        'home_category_hint' => '按分类高效发现资源',
        'home_hero_title' => '探索经过整理的网站',
        'home_sidebar_health' => '主备域名自动检测',
        'home_sidebar_icon' => '图标同步保存至本地',
    );
    $settingStatement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key');
    foreach ($homepageSettings as $key => $value) {
        $settingStatement->execute(array(':key' => $key));
        assert_true($settingStatement->fetchColumn() === $value, '首页设置默认值缺失：' . $key);
    }

    $safe = UrlSafety::normalize('https://example.com');
    assert_true($safe === 'https://example.com/', '安全 URL 规范化失败');

    $blocked = false;
    try { UrlSafety::normalize('http://127.0.0.1'); } catch (InvalidArgumentException $exception) { $blocked = true; }
    assert_true($blocked, '本机地址未被拦截');

    $blocked = false;
    try { UrlSafety::normalize('ftp://example.com'); } catch (InvalidArgumentException $exception) { $blocked = true; }
    assert_true($blocked, '非 HTTP 协议未被拦截');

    $categoryId = (int) $pdo->query('SELECT id FROM categories ORDER BY sort_order LIMIT 1')->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO websites (category_id, title, description, primary_url, sort_order) VALUES (?, ?, ?, ?, ?)');
    $insert->execute(array($categoryId, '测试站点', '测试描述', 'https://example.com/', 1));
    assert_true((int) $pdo->query('SELECT COUNT(*) FROM websites')->fetchColumn() === 1, '网站记录未写入');
    $websiteId = (int) $pdo->lastInsertId();
    $deviceVisit = $pdo->prepare('INSERT INTO website_device_visits (website_id, device_hash, last_counted_at) VALUES (?, ?, ?)');
    $deviceVisit->execute(array($websiteId, str_repeat('a', 64), '2026-01-01 00:00:00'));
    $duplicateBlocked = false;
    try { $deviceVisit->execute(array($websiteId, str_repeat('a', 64), '2026-01-01 00:00:01')); } catch (PDOException $exception) { $duplicateBlocked = true; }
    assert_true($duplicateBlocked, '同网站同设备去重约束未生效');

    echo "通过：SQLite 初始化、URL 安全校验和网站数据读写均正常。\n";
} finally {
    if (is_file($config['db_path'])) { @unlink($config['db_path']); }
    if (is_dir($config['icon_dir'])) { @rmdir($config['icon_dir']); }
}
