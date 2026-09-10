<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$brandMark = setting('brand_mark', 'N');
$allowedThemes = array('daylight', 'night', 'forest', 'twilight', 'china-red', 'glazed-yellow', 'cloud-gray');
$theme = isset($_COOKIE['site_navigator_theme']) ? rawurldecode((string) $_COOKIE['site_navigator_theme']) : setting('default_theme', 'daylight');
if (!in_array($theme, $allowedThemes, true)) { $theme = 'daylight'; }
$themeClass = $theme === 'daylight' ? '' : ' theme-' . $theme;
$redirectDelay = setting('redirect_interstitial_delay', '2.6');
$redirectDelay = is_numeric($redirectDelay) && (float) $redirectDelay >= 0.5 && (float) $redirectDelay <= 60 ? (float) $redirectDelay : 2.6;
$redirectDelayLabel = rtrim(rtrim(number_format($redirectDelay, 1, '.', ''), '0'), '.');
$redirectDelayMilliseconds = (int) round($redirectDelay * 1000);
ob_start(function ($output) use ($brandMark, $redirectDelayLabel, $redirectDelayMilliseconds) {
    $output = str_replace('<span class="brand-mark">N</span>', '<span class="brand-mark">' . e($brandMark) . '</span>', $output);
    $output = str_replace('约 3 秒', '约 ' . e($redirectDelayLabel) . ' 秒', $output);
    return str_replace('},2600);', '},' . $redirectDelayMilliseconds . ');', $output);
});

function visitor_device_hash()
{
    $cookieName = 'site_navigator_device';
    $token = isset($_COOKIE[$cookieName]) ? $_COOKIE[$cookieName] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie($cookieName, $token, time() + 31536000, '/', '', $secure, true);
    }
    return hash('sha256', $token);
}

function record_unique_visit(array $website, $usedUrl, $urlRole)
{
    $pdo = db();
    $deviceHash = visitor_device_hash();
    $now = now_utc();
    $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT last_counted_at FROM website_device_visits WHERE website_id = :website_id AND device_hash = :device_hash');
        $statement->execute(array(':website_id' => $website['id'], ':device_hash' => $deviceHash));
        $lastCountedAt = $statement->fetchColumn();
        if ($lastCountedAt && $lastCountedAt >= $cutoff) {
            $pdo->commit();
            return false;
        }
        if ($lastCountedAt) {
            $statement = $pdo->prepare('UPDATE website_device_visits SET last_counted_at = :last_counted_at WHERE website_id = :website_id AND device_hash = :device_hash');
        } else {
            $statement = $pdo->prepare('INSERT INTO website_device_visits (website_id, device_hash, last_counted_at) VALUES (:website_id, :device_hash, :last_counted_at)');
        }
        $statement->execute(array(':website_id' => $website['id'], ':device_hash' => $deviceHash, ':last_counted_at' => $now));
        $statement = $pdo->prepare('UPDATE websites SET clicks = clicks + 1, updated_at = :updated_at WHERE id = :id');
        $statement->execute(array(':updated_at' => $now, ':id' => $website['id']));
        $statement = $pdo->prepare('INSERT INTO redirect_logs (website_id, used_url, url_role, referer) VALUES (:website_id, :used_url, :url_role, :referer)');
        $statement->execute(array(
            ':website_id' => $website['id'],
            ':used_url' => $usedUrl,
            ':url_role' => $urlRole,
            ':referer' => isset($_SERVER['HTTP_REFERER']) ? substr($_SERVER['HTTP_REFERER'], 0, 500) : '',
        ));
        $pdo->commit();
        return true;
    } catch (Exception $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$requestedUrl = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($id <= 0 && $requestedUrl !== '') {
    if (!preg_match('#^https?://#i', $requestedUrl)) {
        http_response_code(400);
        exit('无效的网站 URL。');
    }
    $website = false;
    foreach (db()->query('SELECT * FROM websites WHERE is_active = 1') as $candidate) {
        if (strcasecmp(rtrim($candidate['primary_url'], '/'), rtrim($requestedUrl, '/')) === 0 || ($candidate['backup_url'] && strcasecmp(rtrim($candidate['backup_url'], '/'), rtrim($requestedUrl, '/')) === 0)) {
            $website = get_website((int) $candidate['id']);
            break;
        }
    }
} else {
    $website = $id > 0 ? get_website($id) : false;
}
if (!$website || !(int) $website['is_active']) {
    http_response_code(404);
    exit('未找到该网站或该网站已停用。');
}

if (isset($_GET['track']) && $_GET['track'] === '1') {
    record_unique_visit($website, $website['primary_url'], 'primary');
    header('Cache-Control: no-store, max-age=0');
    http_response_code(204);
    exit;
}

$redirectMode = setting('redirect_mode', 'direct');
if ($redirectMode === 'domain_interstitial_direct') {
    $destination = array('url' => $website['primary_url'], 'role' => 'primary', 'health' => null);
} else {
    $checker = new HealthChecker(app_config());
    $destination = $checker->chooseDestination($website, true);
}
if ($destination === null) {
    http_response_code(503);
    $siteName = setting('site_name', app_config()['app_name']);
    ?>
    <!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/app.css') ?>"><title>暂时无法访问 — <?= e($siteName) ?></title></head>
    <body class="auth-body<?= e($themeClass) ?>"><main class="auth-card"><span class="brand-mark">N</span><h1>暂时无法访问</h1><p>“<?= e($website['title']) ?>”的主地址与备用地址当前均未通过连通性检测。请稍后重试，或向管理员反馈。</p><div class="auth-actions"><a class="button" href="<?= e($website['primary_url']) ?>" target="_blank" rel="noopener noreferrer">继续尝试访问</a><a class="button-secondary" href="/">返回导航首页</a></div></main></body></html>
    <?php
    exit;
}

record_unique_visit($website, $destination['url'], $destination['role']);

if ($redirectMode === 'interstitial' || $redirectMode === 'domain_interstitial' || $redirectMode === 'domain_interstitial_direct') {
    $siteName = setting('site_name', app_config()['app_name']);
    $template = setting('redirect_interstitial_template', "正在为你打开 {{site_title}}\n已为你确认可用访问地址。若浏览器没有自动跳转，请点击下方按钮继续。");
    $destinationHost = parse_url($destination['url'], PHP_URL_HOST);
    $template = str_replace(
        array('{{site_title}}', '{{site_name}}', '{{destination_domain}}', '{{continue_url}}'),
        array($website['title'], $siteName, $destinationHost ? $destinationHost : $destination['url'], $destination['url']),
        $template
    );
    header('Cache-Control: no-store, max-age=0');
    ?>
    <!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/app.css') ?>"><title>正在跳转 — <?= e($website['title']) ?></title></head>
    <body class="redirect-body<?= e($themeClass) ?>"><div class="redirect-aurora" aria-hidden="true"><i></i><i></i><i></i></div><main class="redirect-card"><header class="redirect-brand"><span class="brand-mark">N</span><span><?= e($siteName) ?></span><span class="redirect-brand-state"><i></i>安全连接</span></header><section class="redirect-site"><div class="redirect-site-icon"><img src="<?= e(website_icon_url($website['icon_path'])) ?>" alt=""></div><p class="redirect-kicker">正在前往</p><h1><?= e($website['title']) ?></h1><p class="redirect-target"><?= e($destinationHost ? $destinationHost : $destination['url']) ?></p></section><section class="redirect-template-panel"><span class="redirect-template-label">跳转提示</span><div class="redirect-template"><?= nl2br(e($template)) ?></div></section><div class="redirect-progress-meta"><span>正在准备目标页面</span><span>约 3 秒</span></div><div class="redirect-progress"><i></i></div><div class="redirect-actions"><a class="button redirect-continue" rel="noopener noreferrer" href="<?= e($destination['url']) ?>">立即继续访问 <span aria-hidden="true">→</span></a><a class="redirect-back" href="/">返回导航首页</a></div><footer class="redirect-footer"><i>✓</i> 已使用可用<?= $destination['role'] === 'backup' ? '备用' : '主' ?>地址建立转向</footer></main><script>window.setTimeout(function(){window.location.replace(<?= json_encode($destination['url'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);},2600);</script></body></html>
    <?php
    exit;
}

header('Cache-Control: no-store, max-age=0');
header('Location: ' . $destination['url'], true, 302);
exit;
