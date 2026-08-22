<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'test-admin';
$_POST = array();

ob_start();
require dirname(__DIR__) . '/public/admin/index.php';
ob_end_clean();

$withoutWww = bulk_import_url_candidates('example.com', false);
$withWww = bulk_import_url_candidates('example.com', true);
$explicit = bulk_import_url_candidates('http://example.com', true);

if ($withoutWww !== array('https://example.com/', 'http://example.com/')) {
    throw new RuntimeException('未勾选 www 时的协议候选不符合预期。');
}
if ($withWww !== array('https://www.example.com/', 'http://www.example.com/')) {
    throw new RuntimeException('勾选 www 时的候选不符合预期。');
}
if ($explicit !== array('http://www.example.com/')) {
    throw new RuntimeException('显式协议与 www 补齐组合不符合预期。');
}

echo "BULK_IMPORT_URL_CANDIDATES_OK\n";
