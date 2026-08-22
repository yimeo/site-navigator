<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_POST = array();

ob_start();
require dirname(__DIR__) . '/public/admin/index.php';
ob_end_clean();

if (site_host_key('https://www.example.com/path') !== 'example.com' || site_host_key('http://example.com/') !== 'example.com') {
    throw new RuntimeException('同域名归一规则未正确忽略协议、www 和路径。');
}

$existing = db()->query('SELECT id, primary_url FROM websites LIMIT 1')->fetch();
if ($existing) {
    $host = site_host_key($existing['primary_url']);
    $blocked = false;
    try {
        assert_site_hosts_available('https://www.' . $host . '/', null);
    } catch (InvalidArgumentException $exception) {
        $blocked = true;
    }
    if (!$blocked) {
        throw new RuntimeException('已有网站的同域名重复收录未被阻止。');
    }
}

echo "SITE_HOST_DUPLICATE_OK\n";
