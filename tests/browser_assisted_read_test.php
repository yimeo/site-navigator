<?php

$admin = file_get_contents(dirname(__DIR__) . '/public/admin/index.php');
$script = file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
$css = file_get_contents(dirname(__DIR__) . '/public/assets/css/app.css');
$requirements = array(
    strpos($admin, 'function browser_read_prefill') === false,
    strpos($admin, "isset(\$_GET['browser_read'])") === false,
    strpos($script, 'createBrowserReadBookmarklet') === false,
    strpos($script, '复制本机浏览器读取书签') === false,
    strpos($script, 'getManualAddReadFallbackMessage') !== false,
    strpos($script, '当前窗口和已填写内容已保留') !== false,
    strpos($script, '请直接填写“网站标题”和“网站介绍”') !== false,
    strpos($script, '若读取失败，直接补充标题和介绍后保存即可') !== false,
    strpos($css, '.browser-read-action') === false,
);
if (in_array(false, $requirements, true)) {
    fwrite(STDERR, "MANUAL_READ_FALLBACK_FAILED\n");
    exit(1);
}
echo "MANUAL_READ_FALLBACK_OK\n";
