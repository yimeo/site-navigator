<?php

$config = require dirname(__DIR__) . '/config/app.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/UrlSafety.php';
require_once __DIR__ . '/SiteInspector.php';
require_once __DIR__ . '/HealthChecker.php';

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name($config['session_name']);
    session_set_cookie_params(0, '/', '', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', true);
    session_start();
}

function app_config()
{
    global $config;
    return $config;
}

function db()
{
    return Database::connection(app_config());
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function now_utc()
{
    return gmdate('Y-m-d H:i:s');
}

function setting($key, $fallback = '')
{
    static $settings = null;
    if ($settings === null) {
        $settings = array();
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return isset($settings[$key]) ? $settings[$key] : $fallback;
}

function csrf_token()
{
    if (!isset($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verify_csrf()
{
    $received = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
    if (!isset($_SESSION['_csrf']) || !is_string($received) || !hash_equals($_SESSION['_csrf'], $received)) {
        http_response_code(419);
        exit('请求已过期，请返回后重试。');
    }
}

function login_captcha_refresh()
{
    $left = random_int(3, 9);
    $right = random_int(1, 9);
    if (random_int(0, 1) === 1) {
        $question = $left . ' + ' . $right . ' = ?';
        $answer = $left + $right;
    } else {
        $larger = max($left, $right);
        $smaller = min($left, $right);
        $question = $larger . ' − ' . $smaller . ' = ?';
        $answer = $larger - $smaller;
    }
    $_SESSION['_login_captcha'] = array(
        'question' => $question,
        'answer_hash' => hash('sha256', (string) $answer),
        'expires_at' => time() + 300,
    );
    return $question;
}

function login_captcha_question()
{
    $captcha = isset($_SESSION['_login_captcha']) && is_array($_SESSION['_login_captcha']) ? $_SESSION['_login_captcha'] : null;
    if (!$captcha || !isset($captcha['question'], $captcha['answer_hash'], $captcha['expires_at']) || (int) $captcha['expires_at'] < time()) {
        return login_captcha_refresh();
    }
    return $captcha['question'];
}

function verify_login_captcha($answer)
{
    $captcha = isset($_SESSION['_login_captcha']) && is_array($_SESSION['_login_captcha']) ? $_SESSION['_login_captcha'] : null;
    unset($_SESSION['_login_captcha']);
    if (!$captcha || !isset($captcha['answer_hash'], $captcha['expires_at']) || (int) $captcha['expires_at'] < time()) {
        return false;
    }
    $answer = trim((string) $answer);
    return preg_match('/^\d{1,3}$/', $answer) === 1 && hash_equals($captcha['answer_hash'], hash('sha256', $answer));
}

function flash($key, $message = null)
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }
    $message = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $message;
}

function redirect_to($path)
{
    header('Location: ' . $path, true, 302);
    exit;
}

function is_admin()
{
    return isset($_SESSION['admin_id']) && (int) $_SESSION['admin_id'] > 0;
}

function require_admin()
{
    if (!is_admin()) {
        flash('error', '请先登录后台。');
        redirect_to('/admin/index.php');
    }
}

function normalize_slug($text)
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim($text, '-');
    if ($text === '') {
        $text = 'category-' . substr(md5(uniqid('', true)), 0, 8);
    }
    return $text;
}

function all_categories($activeOnly = false)
{
    $sql = 'SELECT * FROM categories';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order ASC, name ASC';
    return db()->query($sql)->fetchAll();
}

function get_website($id)
{
    $statement = db()->prepare('SELECT w.*, c.name AS category_name, c.slug AS category_slug, h.primary_status, h.primary_checked_at, h.backup_status, h.backup_checked_at FROM websites w JOIN categories c ON c.id = w.category_id LEFT JOIN website_health h ON h.website_id = w.id WHERE w.id = :id');
    $statement->execute(array(':id' => (int) $id));
    return $statement->fetch();
}

function public_icon_url($iconPath)
{
    if (!$iconPath) {
        return '';
    }
    return $iconPath . '?v=' . rawurlencode(substr(md5($iconPath), 0, 10));
}

function default_site_icon_path()
{
    return '/assets/images/default-site-icon.svg';
}

function website_icon_url($iconPath)
{
    return public_icon_url($iconPath ? $iconPath : default_site_icon_path());
}

function status_label($status)
{
    $labels = array('up' => '可用', 'down' => '不可用', 'unknown' => '未检测');
    return isset($labels[$status]) ? $labels[$status] : '未检测';
}
