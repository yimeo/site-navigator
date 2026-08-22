<?php
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

function bulk_import_url_candidates($input, $addWww, $preferHttps = true)
{
    $address = trim($input);
    if ($address === '') {
        throw new InvalidArgumentException('域名不能为空。');
    }
    if ($addWww && !preg_match('#^(?:https?://)?www\.#i', $address)) {
        $address = preg_replace('#^(https?://)?#i', '$1www.', $address, 1);
    }
    if (preg_match('#^https?://#i', $address)) {
        return array(UrlSafety::normalize($address));
    }
    $schemes = $preferHttps ? array('https://', 'http://') : array('http://', 'https://');
    return array_values(array_unique(array(
        UrlSafety::normalize($schemes[0] . $address),
        UrlSafety::normalize($schemes[1] . $address),
    )));
}

function prefers_https(array $input)
{
    return !isset($input['prefer_https']) || (string) $input['prefer_https'] !== '0';
}

function bulk_import_inspect_first_available(SiteInspector $inspector, array $candidates)
{
    $lastError = '无法访问目标网站。';
    foreach ($candidates as $candidate) {
        try {
            return $inspector->inspect($candidate);
        } catch (Exception $exception) {
            $lastError = $exception->getMessage();
        }
    }
    throw new RuntimeException($lastError);
}

function site_host_key($url)
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\./i', '', $host);
}

function assert_site_hosts_available($primaryUrl, $backupUrl, $excludeId = 0)
{
    $targetHosts = array_filter(array(site_host_key($primaryUrl), $backupUrl ? site_host_key($backupUrl) : ''));
    if (count($targetHosts) === 0) {
        throw new InvalidArgumentException('域名格式无效。');
    }
    $statement = db()->prepare('SELECT id, primary_url, backup_url FROM websites WHERE id != :id');
    $statement->execute(array(':id' => (int) $excludeId));
    foreach ($statement->fetchAll() as $website) {
        $existingHosts = array_filter(array(site_host_key($website['primary_url']), $website['backup_url'] ? site_host_key($website['backup_url']) : ''));
        foreach ($targetHosts as $targetHost) {
            if (in_array($targetHost, $existingHosts, true)) {
                throw new InvalidArgumentException('已收录同域名网站（' . $targetHost . '），请改为编辑现有网站。');
            }
        }
    }
}

function csv_export_cell($value)
{
    $value = (string) $value;
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

function csv_import_boolean($value, $default)
{
    $value = strtolower(trim((string) $value));
    if (in_array($value, array('1', 'true', 'yes', 'y', '是', '显示', '精选'), true)) {
        return 1;
    }
    if (in_array($value, array('0', 'false', 'no', 'n', '否', '隐藏', '不精选'), true)) {
        return 0;
    }
    return $default ? 1 : 0;
}

function csv_import_trim($value)
{
    $value = trim((string) $value);
    return preg_replace('/^\xEF\xBB\xBF/', '', $value);
}

function admin_view_name()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : 'index.php';
    if ($script === 'categories.php') {
        return 'categories';
    }
    if ($script === 'websites.php') {
        return 'websites';
    }
    return 'dashboard';
}

function admin_view_url($view, array $params = array())
{
    $paths = array(
        'dashboard' => '/admin/index.php',
        'categories' => '/admin/categories.php',
        'websites' => '/admin/websites.php',
    );
    $path = isset($paths[$view]) ? $paths[$view] : $paths['dashboard'];
    return count($params) > 0 ? $path . '?' . http_build_query($params, '', '&') : $path;
}

function website_list_per_page($value)
{
    $allowed = array(20, 50, 100, 200);
    $perPage = (int) $value;
    return in_array($perPage, $allowed, true) ? $perPage : 20;
}

function website_list_category_id($value)
{
    return max(0, (int) $value);
}

function website_list_keyword($value)
{
    $keyword = preg_replace('/\s+/u', ' ', trim((string) $value));
    return function_exists('mb_strimwidth') ? mb_strimwidth($keyword, 0, 120, '', 'UTF-8') : substr($keyword, 0, 120);
}

function bulk_network_operation_limit()
{
    return 20;
}

function website_list_return_url($page = 1, $perPage = 20, $categoryId = null, $keyword = null)
{
    if ($categoryId === null) {
        global $returnCategoryId;
        $categoryId = isset($returnCategoryId) ? $returnCategoryId : 0;
    }
    if ($keyword === null) {
        global $returnKeyword;
        $keyword = isset($returnKeyword) ? $returnKeyword : '';
    }
    $params = array('page' => max(1, (int) $page), 'per_page' => website_list_per_page($perPage));
    if (website_list_category_id($categoryId) > 0) {
        $params['category_id'] = website_list_category_id($categoryId);
    }
    $keyword = website_list_keyword($keyword);
    if ($keyword !== '') {
        $params['keyword'] = $keyword;
    }
    return admin_view_url('websites', $params);
}

$metadata = null;
$editMetadata = null;
$editPrefill = null;
$addSitePrefill = null;
$batchImportPrefill = null;
$inspectAddWww = false;
$notice = flash('success');
$error = flash('error');
$action = isset($_POST['action']) ? $_POST['action'] : '';
if ($action === '' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['website_id'], $_POST['primary_url'], $_POST['title'], $_POST['category_id'])) {
    $action = 'update_site';
}
$adminView = admin_view_name();
$returnView = isset($_POST['_return_view']) ? $_POST['_return_view'] : $adminView;
if (!in_array($returnView, array('dashboard', 'categories', 'websites'), true)) {
    $returnView = $adminView;
}
$returnPage = max(1, (int) (isset($_POST['_return_page']) ? $_POST['_return_page'] : (isset($_GET['page']) ? $_GET['page'] : 1)));
$returnPerPage = website_list_per_page(isset($_POST['_return_per_page']) ? $_POST['_return_per_page'] : (isset($_GET['per_page']) ? $_GET['per_page'] : 20));
$returnCategoryId = website_list_category_id(isset($_POST['_return_category_id']) ? $_POST['_return_category_id'] : (isset($_GET['category_id']) ? $_GET['category_id'] : 0));
$returnKeyword = website_list_keyword(isset($_POST['_return_keyword']) ? $_POST['_return_keyword'] : (isset($_GET['keyword']) ? $_GET['keyword'] : ''));

if ($action === 'login') {
    verify_csrf();
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $captchaAnswer = isset($_POST['captcha_answer']) ? $_POST['captcha_answer'] : '';
    if (!verify_login_captcha($captchaAnswer)) {
        login_captcha_refresh();
        $error = '验证码不正确或已过期，请重新输入。';
    } else {
        $statement = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(array(':username' => $username));
        $user = $statement->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['_login_captcha']);
            $_SESSION['admin_id'] = (int) $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            redirect_to('/admin/index.php');
        }
        login_captcha_refresh();
        $error = '用户名或密码不正确。';
    }
}

if (!is_admin() && isset($_GET['refresh_captcha'])) {
    login_captcha_refresh();
    redirect_to('/admin/index.php');
}

$loginCaptchaQuestion = !is_admin() ? login_captcha_question() : '';

if (!is_admin()):
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>后台登录</title><link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/app.css') ?>"></head>
<body class="auth-body"><main class="auth-card"><span class="brand-mark">N</span><h1>导航系统后台</h1><p>登录后管理网站、分类、可用性及本地图标。</p><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="action" value="login"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><div class="form-field"><label>用户名</label><input name="username" required autocomplete="username"></div><div class="form-field"><label>密码</label><input type="password" name="password" required autocomplete="current-password"></div><div class="form-field"><label>验证码</label><div class="captcha-row"><span class="captcha-question" aria-label="验证码题目"><?= e($loginCaptchaQuestion) ?></span><input type="text" name="captcha_answer" inputmode="numeric" pattern="[0-9]{1,3}" maxlength="3" required autocomplete="off" placeholder="请输入结果"><a class="captcha-refresh" href="/admin/index.php?refresh_captcha=1">换一题</a></div><span class="form-hint">验证码 5 分钟内有效，提交后即失效。</span></div><button class="button" style="width:100%" type="submit">登录管理后台</button></form></main></body></html>
<?php exit; endif;

if ($action !== '') {
    verify_csrf();
    try {
        if ($action === 'logout') {
            $_SESSION = array();
            session_destroy();
            redirect_to('/admin/index.php');
        }

        if ($action === 'process_site_task') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $task = isset($_POST['task']) ? $_POST['task'] : '';
                if (!in_array($task, array('check', 'sync_icon'), true)) {
                    throw new InvalidArgumentException('不支持的分段任务。');
                }
                $website = get_website((int) (isset($_POST['website_id']) ? $_POST['website_id'] : 0));
                if (!$website) {
                    throw new RuntimeException('网站不存在或已被删除。');
                }
                if ($task === 'check') {
                    $health = (new HealthChecker(app_config()))->refreshWebsite($website);
                    $message = '主地址' . status_label($health['primary']['status']) . ($website['backup_url'] ? '；备用地址' . status_label($health['backup']['status']) : '');
                } else {
                    $icon = (new SiteInspector(app_config()))->refreshWebsiteIcon($website);
                    if (!$icon) {
                        throw new RuntimeException('未找到可同步图标，已保留现有图标。');
                    }
                    $message = '图标已同步到本地。';
                }
                echo json_encode(array('ok' => true, 'title' => $website['title'], 'message' => $message), JSON_UNESCAPED_UNICODE);
            } catch (Exception $exception) {
                http_response_code(400);
                echo json_encode(array('ok' => false, 'message' => $exception->getMessage()), JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        if ($action === 'inspect_edit_site_json') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $siteId = (int) (isset($_POST['website_id']) ? $_POST['website_id'] : 0);
                $website = get_website($siteId);
                if (!$website) {
                    throw new RuntimeException('网站不存在或已被删除。');
                }
                $inspector = new SiteInspector(app_config());
                $metadata = bulk_import_inspect_first_available($inspector, bulk_import_url_candidates(isset($_POST['primary_url']) ? $_POST['primary_url'] : '', isset($_POST['add_www']), prefers_https($_POST)));
                echo json_encode(array('ok' => true, 'metadata' => $metadata, 'message' => '已读取网站资料，请确认后点击“保存修改”。'), JSON_UNESCAPED_UNICODE);
            } catch (Exception $exception) {
                http_response_code(400);
                echo json_encode(array('ok' => false, 'message' => $exception->getMessage()), JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        if ($action === 'inspect_site_json') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $inspector = new SiteInspector(app_config());
                $metadata = bulk_import_inspect_first_available($inspector, bulk_import_url_candidates(isset($_POST['primary_url']) ? $_POST['primary_url'] : '', isset($_POST['add_www']), prefers_https($_POST)));
                echo json_encode(array('ok' => true, 'metadata' => $metadata, 'message' => '已读取网站资料，请确认后点击“保存网站”。'), JSON_UNESCAPED_UNICODE);
            } catch (Exception $exception) {
                http_response_code(400);
                echo json_encode(array('ok' => false, 'message' => $exception->getMessage()), JSON_UNESCAPED_UNICODE);
            }
            exit;
        }

        if ($action === 'inspect') {
            $inspector = new SiteInspector(app_config());
            $inspectUrl = isset($_POST['primary_url']) ? $_POST['primary_url'] : (isset($_POST['target_url']) ? $_POST['target_url'] : '');
            $inspectAddWww = isset($_POST['add_www']);
            $metadata = bulk_import_inspect_first_available($inspector, bulk_import_url_candidates($inspectUrl, $inspectAddWww, prefers_https($_POST)));
            $notice = '已读取网站信息。您仍可手动修改所有资料后再保存。';
        }

        if ($action === 'inspect_edit_site') {
            $siteId = (int) (isset($_POST['website_id']) ? $_POST['website_id'] : 0);
            $website = get_website($siteId);
            if (!$website) {
                throw new RuntimeException('网站不存在。');
            }
            $inspector = new SiteInspector(app_config());
            $editPrefill = $website;
            $editPrefill['primary_url'] = isset($_POST['primary_url']) ? $_POST['primary_url'] : $website['primary_url'];
            $editPrefill['backup_url'] = isset($_POST['backup_url']) ? $_POST['backup_url'] : $website['backup_url'];
            $editPrefill['title'] = isset($_POST['title']) ? $_POST['title'] : $website['title'];
            $editPrefill['description'] = isset($_POST['description']) ? $_POST['description'] : $website['description'];
            $editPrefill['category_id'] = isset($_POST['category_id']) ? (int) $_POST['category_id'] : $website['category_id'];
            $editPrefill['sort_order'] = isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : $website['sort_order'];
            $editPrefill['is_active'] = isset($_POST['is_active']) ? 1 : 0;
            $editPrefill['is_featured'] = isset($_POST['is_featured']) ? 1 : 0;
            $editPrefill['add_www'] = isset($_POST['add_www']) ? 1 : 0;
            $editPrefill['prefer_https'] = prefers_https($_POST) ? 1 : 0;
            $editPrefill['icon_url'] = isset($_POST['icon_url']) ? $_POST['icon_url'] : '';
            $editMetadata = bulk_import_inspect_first_available($inspector, bulk_import_url_candidates(isset($_POST['primary_url']) ? $_POST['primary_url'] : '', isset($_POST['add_www']), prefers_https($_POST)));
            $editPrefill['primary_url'] = $editMetadata['primary_url'];
            $editPrefill['title'] = $editMetadata['title'];
            $editPrefill['description'] = $editMetadata['description'];
            $editPrefill['icon_url'] = $editMetadata['icon_url'];
            $notice = '已读取网站资料。确认后点击“保存修改”即可更新标题、介绍和图标。';
        }

        if ($action === 'save_redirect_settings') {
            $redirectMode = isset($_POST['redirect_mode']) ? $_POST['redirect_mode'] : 'direct';
            $linkDisplay = isset($_POST['redirect_link_display']) ? $_POST['redirect_link_display'] : 'id';
            $delayInput = trim(isset($_POST['redirect_interstitial_delay']) ? $_POST['redirect_interstitial_delay'] : '2.6');
            $template = trim(isset($_POST['redirect_interstitial_template']) ? $_POST['redirect_interstitial_template'] : '');
            if (!in_array($redirectMode, array('direct', 'interstitial', 'domain_direct'), true)) {
                throw new InvalidArgumentException('跳转模式无效。');
            }
            if (!in_array($linkDisplay, array('domain', 'id', 'blank'), true)) {
                throw new InvalidArgumentException('链接显示方式无效。');
            }
            if (!is_numeric($delayInput) || (float) $delayInput < 0.5 || (float) $delayInput > 60) {
                throw new InvalidArgumentException('自动转向时间请设置在 0.5 到 60 秒之间。');
            }
            $delay = rtrim(rtrim(number_format(round((float) $delayInput, 1), 1, '.', ''), '0'), '.');
            if ($template === '') {
                $template = "正在为你打开 {{site_title}}\n已为你确认可用访问地址。若浏览器没有自动跳转，请点击下方按钮继续。";
            }
            $template = mb_strimwidth($template, 0, 1000, '', 'UTF-8');
            $settings = array(
                'redirect_mode' => $redirectMode,
                'redirect_link_display' => $linkDisplay,
                'redirect_interstitial_delay' => $delay,
                'redirect_interstitial_template' => $template,
            );
            $statement = db()->prepare('INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)');
            foreach ($settings as $key => $value) {
                $statement->execute(array(':key' => $key, ':value' => $value, ':updated_at' => now_utc()));
            }
            flash('success', 'URL 跳转设置已保存。');
            redirect_to('/admin/index.php#redirect-settings');
        }

        if ($action === 'save_site_settings') {
            $siteNameInput = trim(isset($_POST['site_name']) ? $_POST['site_name'] : setting('site_name', app_config()['app_name']));
            $siteSubtitleInput = trim(isset($_POST['site_subtitle']) ? $_POST['site_subtitle'] : setting('site_subtitle', '发现值得访问的网站'));
            $brandMarkInput = trim(isset($_POST['brand_mark']) ? $_POST['brand_mark'] : setting('brand_mark', 'N'));
            $seoTitleInput = trim(isset($_POST['seo_title']) ? $_POST['seo_title'] : '');
            $seoDescriptionInput = trim(isset($_POST['seo_description']) ? $_POST['seo_description'] : '');
            $seoKeywordsInput = trim(isset($_POST['seo_keywords']) ? $_POST['seo_keywords'] : '');
            $homeDirectoryLabelInput = trim(isset($_POST['home_directory_label']) ? $_POST['home_directory_label'] : setting('home_directory_label', '站点目录'));
            $homeCategoryHintInput = trim(isset($_POST['home_category_hint']) ? $_POST['home_category_hint'] : setting('home_category_hint', '按分类高效发现资源'));
            $homeHeroTitleInput = trim(isset($_POST['home_hero_title']) ? $_POST['home_hero_title'] : setting('home_hero_title', '探索经过整理的网站'));
            $homeHeroDescriptionInput = trim(isset($_POST['home_hero_description']) ? $_POST['home_hero_description'] : setting('home_hero_description', '发现值得访问的网站。所有跳转优先使用可用的主地址，主地址异常时自动尝试备用地址。'));
            $homeSidebarHealthInput = trim(isset($_POST['home_sidebar_health']) ? $_POST['home_sidebar_health'] : setting('home_sidebar_health', '主备域名自动检测'));
            $homeSidebarIconInput = trim(isset($_POST['home_sidebar_icon']) ? $_POST['home_sidebar_icon'] : setting('home_sidebar_icon', '图标同步保存至本地'));
            if ($siteNameInput === '') {
                throw new InvalidArgumentException('网站名不能为空。');
            }
            if ($siteSubtitleInput === '') {
                throw new InvalidArgumentException('网站简介不能为空。');
            }
            if ($brandMarkInput === '') {
                throw new InvalidArgumentException('品牌图标短标识不能为空。');
            }
            if ($homeDirectoryLabelInput === '' || $homeCategoryHintInput === '' || $homeHeroTitleInput === '' || $homeHeroDescriptionInput === '' || $homeSidebarHealthInput === '' || $homeSidebarIconInput === '') {
                throw new InvalidArgumentException('首页展示文案不能为空。');
            }
            $siteNameInput = mb_strimwidth($siteNameInput, 0, 80, '', 'UTF-8');
            $siteSubtitleInput = mb_strimwidth($siteSubtitleInput, 0, 160, '', 'UTF-8');
            $brandMarkInput = mb_substr($brandMarkInput, 0, 3, 'UTF-8');
            $homeDirectoryLabelInput = mb_strimwidth($homeDirectoryLabelInput, 0, 80, '', 'UTF-8');
            $homeCategoryHintInput = mb_strimwidth($homeCategoryHintInput, 0, 100, '', 'UTF-8');
            $homeHeroTitleInput = mb_strimwidth($homeHeroTitleInput, 0, 120, '', 'UTF-8');
            $homeHeroDescriptionInput = mb_strimwidth($homeHeroDescriptionInput, 0, 300, '', 'UTF-8');
            $homeSidebarHealthInput = mb_strimwidth($homeSidebarHealthInput, 0, 80, '', 'UTF-8');
            $homeSidebarIconInput = mb_strimwidth($homeSidebarIconInput, 0, 80, '', 'UTF-8');
            $seoTitleInput = mb_strimwidth($seoTitleInput !== '' ? $seoTitleInput : $siteNameInput . ' - ' . $siteSubtitleInput, 0, 120, '', 'UTF-8');
            $seoDescriptionInput = mb_strimwidth($seoDescriptionInput !== '' ? $seoDescriptionInput : $siteSubtitleInput, 0, 300, '', 'UTF-8');
            $seoKeywordsInput = mb_strimwidth($seoKeywordsInput !== '' ? $seoKeywordsInput : $siteNameInput . ',网站导航,网址导航', 0, 500, '', 'UTF-8');
            $settings = array(
                'site_name' => $siteNameInput,
                'brand_mark' => $brandMarkInput,
                'site_subtitle' => $siteSubtitleInput,
                'seo_title' => $seoTitleInput,
                'seo_description' => $seoDescriptionInput,
                'seo_keywords' => $seoKeywordsInput,
                'home_directory_label' => $homeDirectoryLabelInput,
                'home_category_hint' => $homeCategoryHintInput,
                'home_hero_title' => $homeHeroTitleInput,
                'home_hero_description' => $homeHeroDescriptionInput,
                'home_sidebar_health' => $homeSidebarHealthInput,
                'home_sidebar_icon' => $homeSidebarIconInput,
            );
            $statement = db()->prepare('INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (:key, :value, :updated_at)');
            foreach ($settings as $key => $value) {
                $statement->execute(array(':key' => $key, ':value' => $value, ':updated_at' => now_utc()));
            }
            flash('success', '网站设置已保存，首页文案和 SEO 信息已同步更新。');
            redirect_to('/admin/index.php#site-settings');
        }

        if ($action === 'update_admin_account') {
            $newUsername = trim(isset($_POST['username']) ? $_POST['username'] : '');
            $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
            $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
            $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
            if (!preg_match('/^[A-Za-z0-9_-]{3,60}$/', $newUsername)) {
                throw new InvalidArgumentException('管理员名称仅可使用 3 至 60 位字母、数字、下划线或连字符。');
            }
            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                throw new InvalidArgumentException('请填写当前密码、新密码和确认密码。');
            }
            if (strlen($newPassword) < 10) {
                throw new InvalidArgumentException('新密码至少需要 10 个字符。');
            }
            if ($newPassword !== $confirmPassword) {
                throw new InvalidArgumentException('两次输入的新密码不一致。');
            }
            $statement = db()->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
            $statement->execute(array(':id' => (int) $_SESSION['admin_id']));
            $currentUser = $statement->fetch();
            if (!$currentUser || !password_verify($currentPassword, $currentUser['password_hash'])) {
                throw new InvalidArgumentException('当前密码不正确。');
            }
            if (password_verify($newPassword, $currentUser['password_hash'])) {
                throw new InvalidArgumentException('新密码不能与当前密码相同。');
            }
            if ($newUsername !== $currentUser['username']) {
                $statement = db()->prepare('SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1');
                $statement->execute(array(':username' => $newUsername, ':id' => (int) $_SESSION['admin_id']));
                if ($statement->fetch()) {
                    throw new InvalidArgumentException('该管理员名称已被使用。');
                }
            }
            $statement = db()->prepare('UPDATE users SET username = :username, password_hash = :password_hash WHERE id = :id');
            $statement->execute(array(':username' => $newUsername, ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => (int) $_SESSION['admin_id']));
            $_SESSION['admin_username'] = $newUsername;
            session_regenerate_id(true);
            flash('success', '管理员名称和密码已同时更新。下次登录请使用新的账户信息。');
            redirect_to('/admin/index.php#site-settings');
        }

        if ($action === 'export_websites_csv') {
            $statement = db()->query('SELECT w.*, c.name AS category_name FROM websites w JOIN categories c ON c.id = w.category_id ORDER BY c.sort_order ASC, w.sort_order ASC, w.id ASC');
            $filename = 'site-navigator-websites-' . gmdate('Ymd-His') . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, max-age=0');
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array('分类名称', '网站标题', '网站介绍', '主域名', '备用域名', '显示优先级', '前台展示', '精选推荐'));
            foreach ($statement->fetchAll() as $website) {
                fputcsv($output, array(
                    csv_export_cell($website['category_name']),
                    csv_export_cell($website['title']),
                    csv_export_cell($website['description']),
                    csv_export_cell($website['primary_url']),
                    csv_export_cell($website['backup_url']),
                    (int) $website['sort_order'],
                    (int) $website['is_active'] ? '是' : '否',
                    (int) $website['is_featured'] ? '是' : '否',
                ));
            }
            fclose($output);
            exit;
        }

        if ($action === 'import_websites_csv') {
            if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file']) || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('请选择可读取的 CSV 文件。');
            }
            $file = $_FILES['csv_file'];
            if ((int) $file['size'] <= 0 || (int) $file['size'] > 2 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('CSV 文件无效或超过 2MB 限制。');
            }
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new InvalidArgumentException('仅支持导入 .csv 文件。');
            }
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                throw new RuntimeException('无法读取上传的 CSV 文件。');
            }
            $headers = fgetcsv($handle);
            if (!$headers || count($headers) < 2) {
                fclose($handle);
                throw new InvalidArgumentException('CSV 表头无效。请先下载导出模板后再编辑导入。');
            }
            $headerAliases = array(
                '分类名称' => 'category', 'category' => 'category',
                '网站标题' => 'title', 'title' => 'title',
                '网站介绍' => 'description', 'description' => 'description',
                '主域名' => 'primary_url', 'primary_url' => 'primary_url',
                '备用域名' => 'backup_url', 'backup_url' => 'backup_url',
                '显示优先级' => 'sort_order', 'sort_order' => 'sort_order',
                '前台展示' => 'is_active', 'is_active' => 'is_active',
                '精选推荐' => 'is_featured', 'is_featured' => 'is_featured',
            );
            $columns = array();
            foreach ($headers as $index => $header) {
                $normalizedHeader = strtolower(csv_import_trim($header));
                if (isset($headerAliases[$normalizedHeader])) {
                    $columns[$headerAliases[$normalizedHeader]] = $index;
                }
            }
            if (!isset($columns['category']) || !isset($columns['primary_url'])) {
                fclose($handle);
                throw new InvalidArgumentException('CSV 必须包含“分类名称”和“主域名”列。');
            }
            $categoryMap = array();
            foreach (db()->query('SELECT id, name FROM categories')->fetchAll() as $category) {
                $categoryMap[mb_strtolower(trim($category['name']), 'UTF-8')] = (int) $category['id'];
            }
            $insert = db()->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, :backup_url, :icon_path, :sort_order, :is_active, :is_featured, :created_at, :updated_at)');
            $imported = 0;
            $skipped = array();
            $line = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > 202) {
                    $skipped[] = '超过单次 200 条限制的后续记录未导入。';
                    break;
                }
                $value = function ($key) use ($row, $columns) {
                    return isset($columns[$key]) && isset($row[$columns[$key]]) ? csv_import_trim($row[$columns[$key]]) : '';
                };
                $categoryName = $value('category');
                $primaryInput = $value('primary_url');
                if ($categoryName === '' && $primaryInput === '') {
                    continue;
                }
                try {
                    $categoryKey = mb_strtolower($categoryName, 'UTF-8');
                    if (!isset($categoryMap[$categoryKey])) {
                        throw new InvalidArgumentException('未找到分类“' . mb_strimwidth($categoryName, 0, 30, '', 'UTF-8') . '”。');
                    }
                    $primaryUrl = bulk_import_url_candidates($primaryInput, false)[0];
                    $backupInput = $value('backup_url');
                    $backupUrl = $backupInput !== '' ? bulk_import_url_candidates($backupInput, false)[0] : null;
                    if ($backupUrl === $primaryUrl) {
                        $backupUrl = null;
                    }
                    assert_site_hosts_available($primaryUrl, $backupUrl);
                    $fallbackTitle = (string) parse_url($primaryUrl, PHP_URL_HOST);
                    $title = $value('title');
                    $description = $value('description');
                    $createdAt = now_utc();
                    $insert->execute(array(
                        ':category_id' => $categoryMap[$categoryKey],
                        ':title' => mb_strimwidth($title !== '' ? $title : $fallbackTitle, 0, 120, '', 'UTF-8'),
                        ':description' => mb_strimwidth($description !== '' ? $description : '暂无网站介绍。', 0, 500, '', 'UTF-8'),
                        ':primary_url' => $primaryUrl,
                        ':backup_url' => $backupUrl,
                        ':icon_path' => default_site_icon_path(),
                        ':sort_order' => max(0, (int) $value('sort_order')),
                        ':is_active' => csv_import_boolean($value('is_active'), true),
                        ':is_featured' => csv_import_boolean($value('is_featured'), false),
                        ':created_at' => $createdAt,
                        ':updated_at' => $createdAt,
                    ));
                    $imported++;
                } catch (Exception $exception) {
                    $skipped[] = '第 ' . $line . ' 行：' . mb_strimwidth($exception->getMessage(), 0, 100, '', 'UTF-8');
                }
            }
            fclose($handle);
            if ($imported > 0) {
                flash('success', 'CSV 导入完成：已新增 ' . $imported . ' 个网站。图标与健康状态可在列表中批量同步。');
            }
            if (count($skipped) > 0) {
                $details = array_slice($skipped, 0, 6);
                $remaining = count($skipped) - count($details);
                flash('error', '已跳过 ' . count($skipped) . ' 条。' . implode('；', $details) . ($remaining > 0 ? '；另有 ' . $remaining . ' 条未显示。' : ''));
            }
            if ($imported === 0 && count($skipped) === 0) {
                flash('error', 'CSV 中没有可导入的网站记录。');
            }
            redirect_to(website_list_return_url($returnPage, $returnPerPage));
        }

        if ($action === 'bulk_import_sites') {
            $rawInput = isset($_POST['batch_sites']) ? trim($_POST['batch_sites']) : '';
            $categoryId = (int) (isset($_POST['category_id']) ? $_POST['category_id'] : 0);
            $addWww = isset($_POST['add_www']);
            $preferHttps = prefers_https($_POST);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
            $sortOrder = max(0, (int) (isset($_POST['sort_order']) ? $_POST['sort_order'] : 100));
            if ($rawInput === '') {
                throw new InvalidArgumentException('请至少输入一个网站域名。');
            }
            $categoryStatement = db()->prepare('SELECT id FROM categories WHERE id = :id');
            $categoryStatement->execute(array(':id' => $categoryId));
            if (!$categoryStatement->fetch()) {
                throw new InvalidArgumentException('请选择有效的所属分类。');
            }
            $lines = preg_split('/\r\n|\r|\n/', $rawInput);
            $entries = array();
            foreach ($lines as $lineNumber => $line) {
                $line = trim($line);
                if ($line !== '') {
                    $entries[] = array('line' => $lineNumber + 1, 'value' => $line);
                }
            }
            if (count($entries) === 0) {
                throw new InvalidArgumentException('请至少输入一个网站域名。');
            }
            if (count($entries) > 20) {
                throw new InvalidArgumentException('一次最多导入 20 行网站，请分批提交。');
            }

            $inspector = new SiteInspector(app_config());
            $healthChecker = new HealthChecker(app_config());
            $insert = db()->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, :backup_url, :icon_path, :sort_order, :is_active, :is_featured, :created_at, :updated_at)');
            $iconUpdate = db()->prepare('UPDATE websites SET icon_path = :icon_path WHERE id = :id');
            $imported = 0;
            $skipped = array();

            foreach ($entries as $entry) {
                $parts = array_map('trim', explode(',', $entry['value']));
                if (count($parts) > 2 || $parts[0] === '') {
                    $skipped[] = '第 ' . $entry['line'] . ' 行：格式应为主域名或“主域名,备用域名”。';
                    continue;
                }
                $primaryInput = $parts[0];
                $backupInput = isset($parts[1]) ? $parts[1] : '';
                try {
                    $primaryCandidates = bulk_import_url_candidates($primaryInput, $addWww, $preferHttps);
                    $primaryMetadata = null;
                    $primaryError = '';
                    try {
                        $primaryMetadata = bulk_import_inspect_first_available($inspector, $primaryCandidates);
                    } catch (Exception $exception) {
                        $primaryError = $exception->getMessage();
                    }

                    $backupMetadata = null;
                    $backupError = '';
                    if ($backupInput !== '') {
                        try {
                            $backupMetadata = bulk_import_inspect_first_available($inspector, bulk_import_url_candidates($backupInput, $addWww, $preferHttps));
                        } catch (Exception $exception) {
                            $backupError = $exception->getMessage();
                        }
                    }
                    if (!$primaryMetadata && !$backupMetadata) {
                        throw new RuntimeException($primaryError !== '' ? $primaryError : $backupError);
                    }

                    $metadataForSite = $primaryMetadata ? $primaryMetadata : $backupMetadata;
                    $primaryUrl = $primaryMetadata ? $primaryMetadata['primary_url'] : $primaryCandidates[0];
                    $backupUrl = $backupMetadata ? $backupMetadata['primary_url'] : null;
                    if ($primaryMetadata && $backupUrl === $primaryUrl) {
                        $backupUrl = null;
                    }
                    assert_site_hosts_available($primaryUrl, $backupUrl);

                    $createdAt = now_utc();
                    $insert->execute(array(
                        ':category_id' => $categoryId,
                        ':title' => mb_strimwidth($metadataForSite['title'], 0, 120, '', 'UTF-8'),
                        ':description' => mb_strimwidth($metadataForSite['description'], 0, 500, '', 'UTF-8'),
                        ':primary_url' => $primaryUrl,
                        ':backup_url' => $backupUrl,
                        ':icon_path' => default_site_icon_path(),
                        ':sort_order' => $sortOrder,
                        ':is_active' => $isActive,
                        ':is_featured' => $isFeatured,
                        ':created_at' => $createdAt,
                        ':updated_at' => $createdAt,
                    ));
                    $siteId = (int) db()->lastInsertId();
                    try {
                        $icon = $inspector->syncIcon($metadataForSite['icon_url'], $siteId);
                        if ($icon) {
                            $iconUpdate->execute(array(':icon_path' => $icon, ':id' => $siteId));
                        }
                    } catch (Exception $exception) {
                    }
                    try {
                        $healthChecker->refreshWebsite(array('id' => $siteId, 'primary_url' => $primaryUrl, 'backup_url' => $backupUrl));
                    } catch (Exception $exception) {
                    }
                    $imported++;
                    if ($backupInput !== '' && !$backupMetadata) {
                        $skipped[] = '第 ' . $entry['line'] . ' 行：主域名已收录，备用域名无法访问，已忽略备用域名。';
                    }
                } catch (Exception $exception) {
                    $skipped[] = '第 ' . $entry['line'] . ' 行：' . mb_strimwidth($exception->getMessage(), 0, 120, '', 'UTF-8');
                }
            }

            if ($imported > 0) {
                flash('success', '批量添加完成：已收录 ' . $imported . ' 个网站。');
            }
            if (count($skipped) > 0) {
                $details = array_slice($skipped, 0, 6);
                $remaining = count($skipped) - count($details);
                flash('error', '已跳过 ' . count($skipped) . ' 条。' . implode('；', $details) . ($remaining > 0 ? '；另有 ' . $remaining . ' 条未显示。' : ''));
            }
            if ($imported === 0 && count($skipped) === 0) {
                flash('error', '没有可导入的网站。');
            }
            redirect_to(website_list_return_url($returnPage, $returnPerPage));
        }

        if ($action === 'save_site' || $action === 'update_site') {
            $addWww = isset($_POST['add_www']);
            $preferHttps = prefers_https($_POST);
            $primaryCandidates = bulk_import_url_candidates(isset($_POST['primary_url']) ? $_POST['primary_url'] : '', $addWww, $preferHttps);
            $primaryUrl = $primaryCandidates[0];
            $backupInput = trim(isset($_POST['backup_url']) ? $_POST['backup_url'] : '');
            $backupUrl = $backupInput !== '' ? bulk_import_url_candidates($backupInput, $addWww, $preferHttps)[0] : null;
            if ($backupUrl === $primaryUrl) {
                throw new InvalidArgumentException('备用域名不能与主域名相同。');
            }
            $title = trim(isset($_POST['title']) ? $_POST['title'] : '');
            $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
            $categoryId = (int) (isset($_POST['category_id']) ? $_POST['category_id'] : 0);
            if ($title === '' || $categoryId <= 0) {
                throw new InvalidArgumentException('请确认网站标题与所属分类。');
            }
            $categoryStatement = db()->prepare('SELECT id FROM categories WHERE id = :id');
            $categoryStatement->execute(array(':id' => $categoryId));
            if (!$categoryStatement->fetch()) {
                throw new InvalidArgumentException('所选分类不存在。');
            }
            $payload = array(
                ':category_id' => $categoryId,
                ':title' => mb_strimwidth($title, 0, 120, '', 'UTF-8'),
                ':description' => mb_strimwidth($description, 0, 500, '', 'UTF-8'),
                ':primary_url' => $primaryUrl,
                ':backup_url' => $backupUrl,
                ':sort_order' => max(0, (int) (isset($_POST['sort_order']) ? $_POST['sort_order'] : 100)),
                ':is_active' => isset($_POST['is_active']) ? 1 : 0,
                ':is_featured' => isset($_POST['is_featured']) ? 1 : 0,
                ':updated_at' => now_utc(),
            );
            if ($action === 'save_site') {
                assert_site_hosts_available($primaryUrl, $backupUrl);
                $statement = db()->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, :backup_url, :icon_path, :sort_order, :is_active, :is_featured, :created_at, :updated_at)');
                $payload[':created_at'] = now_utc();
                $payload[':icon_path'] = default_site_icon_path();
                $statement->execute($payload);
                $siteId = (int) db()->lastInsertId();
                $iconUrl = trim(isset($_POST['icon_url']) ? $_POST['icon_url'] : '');
                $icon = null;
                if ($iconUrl !== '') {
                    $icon = (new SiteInspector(app_config()))->syncIcon($iconUrl, $siteId);
                    if ($icon) {
                        $update = db()->prepare('UPDATE websites SET icon_path = :icon_path WHERE id = :id');
                        $update->execute(array(':icon_path' => $icon, ':id' => $siteId));
                    }
                }
                $iconMessage = $icon ? '，图标已同步到本地。' : '；未读取到目标网站图标，系统已使用默认图标，后续同步时会自动替换。';
                flash('success', '已收录“' . $payload[':title'] . '”，标题和介绍已同步' . $iconMessage);
            } else {
                $siteId = (int) $_POST['website_id'];
                if (!get_website($siteId)) {
                    throw new RuntimeException('网站不存在。');
                }
                assert_site_hosts_available($primaryUrl, $backupUrl, $siteId);
                $payload[':id'] = $siteId;
                $statement = db()->prepare('UPDATE websites SET category_id=:category_id, title=:title, description=:description, primary_url=:primary_url, backup_url=:backup_url, sort_order=:sort_order, is_active=:is_active, is_featured=:is_featured, updated_at=:updated_at WHERE id=:id');
                $statement->execute($payload);
                $iconUrl = trim(isset($_POST['icon_url']) ? $_POST['icon_url'] : '');
                if ($iconUrl !== '') {
                    $icon = (new SiteInspector(app_config()))->syncIcon($iconUrl, $siteId);
                    if ($icon) {
                        $update = db()->prepare('UPDATE websites SET icon_path = :icon_path WHERE id = :id');
                        $update->execute(array(':icon_path' => $icon, ':id' => $siteId));
                    }
                }
                flash('success', '网站资料已更新。' . (!empty($icon) ? '图标已同步到本地。' : ''));
            }
            redirect_to($returnView === 'websites' ? website_list_return_url($returnPage, $returnPerPage) : admin_view_url('dashboard'));
        }

        if ($action === 'add_category') {
            $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
            if ($name === '') {
                throw new InvalidArgumentException('分类名称不能为空。');
            }
            $slug = normalize_slug(isset($_POST['slug']) && trim($_POST['slug']) !== '' ? $_POST['slug'] : $name);
            $statement = db()->prepare('INSERT INTO categories (name, slug, sort_order, is_active, updated_at) VALUES (:name, :slug, :sort_order, 1, :updated_at)');
            $statement->execute(array(':name' => $name, ':slug' => $slug, ':sort_order' => max(0, (int) $_POST['sort_order']), ':updated_at' => now_utc()));
            flash('success', '分类已添加。');
            redirect_to(admin_view_url('categories'));
        }

        if ($action === 'update_category') {
            $id = (int) (isset($_POST['category_id']) ? $_POST['category_id'] : 0);
            $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
            $slugInput = trim(isset($_POST['slug']) ? $_POST['slug'] : '');
            $sortOrder = max(0, (int) (isset($_POST['sort_order']) ? $_POST['sort_order'] : 0));
            if ($id <= 0 || $name === '' || $slugInput === '') {
                throw new InvalidArgumentException('优先级、分类名称和标识均不能为空。');
            }
            $name = mb_strimwidth($name, 0, 60, '', 'UTF-8');
            $slug = normalize_slug($slugInput);
            if ($slug === '') {
                throw new InvalidArgumentException('分类标识格式无效，请使用字母、数字和连字符。');
            }
            $current = db()->prepare('SELECT id FROM categories WHERE id = :id');
            $current->execute(array(':id' => $id));
            if (!$current->fetch()) {
                throw new RuntimeException('分类不存在。');
            }
            $duplicate = db()->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) AND id != :id LIMIT 1');
            $duplicate->execute(array(':name' => $name, ':id' => $id));
            if ($duplicate->fetch()) {
                throw new InvalidArgumentException('已存在同名分类，请换一个名称。');
            }
            $duplicate = db()->prepare('SELECT id FROM categories WHERE LOWER(slug) = LOWER(:slug) AND id != :id LIMIT 1');
            $duplicate->execute(array(':slug' => $slug, ':id' => $id));
            if ($duplicate->fetch()) {
                throw new InvalidArgumentException('已存在相同分类标识，请换一个标识。');
            }
            $statement = db()->prepare('UPDATE categories SET name = :name, slug = :slug, sort_order = :sort_order, updated_at = :updated_at WHERE id = :id');
            $statement->execute(array(':name' => $name, ':slug' => $slug, ':sort_order' => $sortOrder, ':updated_at' => now_utc(), ':id' => $id));
            flash('success', '分类资料已更新。');
            redirect_to(admin_view_url('categories'));
        }

        if ($action === 'delete_category') {
            $id = (int) (isset($_POST['category_id']) ? $_POST['category_id'] : 0);
            $category = db()->prepare('SELECT id, name FROM categories WHERE id = :id');
            $category->execute(array(':id' => $id));
            $category = $category->fetch();
            if (!$category) {
                throw new RuntimeException('分类不存在或已被删除。');
            }
            $count = db()->prepare('SELECT COUNT(*) FROM websites WHERE category_id = :category_id');
            $count->execute(array(':category_id' => $id));
            $siteCount = (int) $count->fetchColumn();
            if ($siteCount > 0) {
                throw new RuntimeException('“' . $category['name'] . '”下还有 ' . $siteCount . ' 个网站，不能删除。请先将网站迁移到其他分类或删除相关网站。');
            }
            $statement = db()->prepare('DELETE FROM categories WHERE id = :id');
            $statement->execute(array(':id' => $id));
            flash('success', '空分类“' . $category['name'] . '”已删除。');
            redirect_to(admin_view_url('categories'));
        }

        if ($action === 'move_category') {
            $id = (int) $_POST['category_id'];
            $direction = $_POST['direction'] === 'up' ? 'up' : 'down';
            $current = db()->prepare('SELECT * FROM categories WHERE id = :id');
            $current->execute(array(':id' => $id));
            $current = $current->fetch();
            if (!$current) {
                throw new RuntimeException('分类不存在。');
            }
            $operator = $direction === 'up' ? '<' : '>';
            $ordering = $direction === 'up' ? 'DESC' : 'ASC';
            $neighbor = db()->prepare('SELECT * FROM categories WHERE sort_order ' . $operator . ' :sort_order ORDER BY sort_order ' . $ordering . ' LIMIT 1');
            $neighbor->execute(array(':sort_order' => $current['sort_order']));
            $neighbor = $neighbor->fetch();
            if ($neighbor) {
                $pdo = db();
                $pdo->beginTransaction();
                $update = $pdo->prepare('UPDATE categories SET sort_order = :sort_order, updated_at = :updated_at WHERE id = :id');
                $update->execute(array(':sort_order' => $neighbor['sort_order'], ':updated_at' => now_utc(), ':id' => $current['id']));
                $update->execute(array(':sort_order' => $current['sort_order'], ':updated_at' => now_utc(), ':id' => $neighbor['id']));
                $pdo->commit();
            }
            flash('success', '分类优先级已更新。');
            redirect_to(admin_view_url('categories'));
        }

        if ($action === 'toggle_site') {
            $statement = db()->prepare('UPDATE websites SET is_active = CASE is_active WHEN 1 THEN 0 ELSE 1 END, updated_at = :updated_at WHERE id = :id');
            $statement->execute(array(':updated_at' => now_utc(), ':id' => (int) $_POST['website_id']));
            flash('success', '网站状态已更新。');
            redirect_to(website_list_return_url($returnPage, $returnPerPage));
        }

        if ($action === 'delete_site') {
            $website = get_website((int) $_POST['website_id']);
            if ($website && $website['icon_path'] && $website['icon_path'] !== default_site_icon_path()) {
                $path = app_config()['base_path'] . '/public' . $website['icon_path'];
                if (is_file($path)) { @unlink($path); }
            }
            $statement = db()->prepare('DELETE FROM websites WHERE id = :id');
            $statement->execute(array(':id' => (int) $_POST['website_id']));
            flash('success', '网站及其健康状态记录已删除。');
            redirect_to(website_list_return_url($returnPage, $returnPerPage));
        }

        if ($action === 'check_site') {
            $website = get_website((int) $_POST['website_id']);
            if (!$website) { throw new RuntimeException('网站不存在。'); }
            $health = (new HealthChecker(app_config()))->refreshWebsite($website);
            flash('success', '检测完成：主地址' . status_label($health['primary']['status']) . ($website['backup_url'] ? '；备用地址' . status_label($health['backup']['status']) : '') . '。');
            redirect_to(website_list_return_url($returnPage, $returnPerPage));
        }

        if ($action === 'sync_icon') {
            $website = get_website((int) $_POST['website_id']);
            if (!$website) { throw new RuntimeException('网站不存在。'); }
            $icon = (new SiteInspector(app_config()))->refreshWebsiteIcon($website);
            flash($icon ? 'success' : 'error', $icon ? '图标已同步到本地。' : '未能同步图标，已保留原有图标。');
            redirect_to(website_list_return_url($returnPage, $returnPerPage));
        }

        if ($action === 'bulk_site_action') {
            $operation = isset($_POST['bulk_operation']) ? $_POST['bulk_operation'] : '';
            if (!in_array($operation, array('delete', 'check', 'sync_icon', 'set_category'), true)) {
                throw new InvalidArgumentException('请选择有效的批量操作。');
            }
            $targetCategoryId = (int) (isset($_POST['bulk_category_id']) ? $_POST['bulk_category_id'] : 0);
            if ($operation === 'set_category') {
                $categoryStatement = db()->prepare('SELECT id FROM categories WHERE id = :id');
                $categoryStatement->execute(array(':id' => $targetCategoryId));
                if (!$categoryStatement->fetch()) {
                    throw new InvalidArgumentException('请选择要设置的有效分类。');
                }
            }
            $selectedIds = isset($_POST['website_ids']) && is_array($_POST['website_ids']) ? $_POST['website_ids'] : array();
            $selectedIds = array_values(array_unique(array_filter(array_map('intval', $selectedIds), function ($id) { return $id > 0; })));
            if (count($selectedIds) === 0) {
                throw new InvalidArgumentException('请先选择至少一个网站。');
            }
            if (in_array($operation, array('check', 'sync_icon'), true) && count($selectedIds) > bulk_network_operation_limit()) {
                throw new InvalidArgumentException('为避免后台长时间阻塞，未启用分段处理时每次最多处理 ' . bulk_network_operation_limit() . ' 个网站。请使用支持分段进度的最新浏览器，或分批选择网站后重试。');
            }
            $placeholders = array();
            $params = array();
            foreach ($selectedIds as $index => $id) {
                $key = ':id' . $index;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $statement = db()->prepare('SELECT w.*, c.name AS category_name, h.primary_status, h.primary_checked_at, h.backup_status, h.backup_checked_at FROM websites w JOIN categories c ON c.id = w.category_id LEFT JOIN website_health h ON h.website_id = w.id WHERE w.id IN (' . implode(',', $placeholders) . ')');
            $statement->execute($params);
            $selectedWebsites = $statement->fetchAll();
            if (count($selectedWebsites) === 0) {
                throw new RuntimeException('未找到所选网站。');
            }
            $successCount = 0;
            $failed = array();
            if ($operation === 'set_category') {
                $updateCategory = db()->prepare('UPDATE websites SET category_id = :category_id, updated_at = :updated_at WHERE id = :id');
                foreach ($selectedWebsites as $website) {
                    try {
                        $updateCategory->execute(array(':category_id' => $targetCategoryId, ':updated_at' => now_utc(), ':id' => (int) $website['id']));
                        $successCount++;
                    } catch (Exception $exception) {
                        $failed[] = $website['title'];
                    }
                }
                flash('success', '批量设置分类完成：已更新 ' . $successCount . ' 个网站。');
            } elseif ($operation === 'delete') {
                $delete = db()->prepare('DELETE FROM websites WHERE id = :id');
                foreach ($selectedWebsites as $website) {
                    try {
                        if ($website['icon_path'] && $website['icon_path'] !== default_site_icon_path()) {
                            $path = app_config()['base_path'] . '/public' . $website['icon_path'];
                            if (is_file($path)) { @unlink($path); }
                        }
                        $delete->execute(array(':id' => (int) $website['id']));
                        $successCount++;
                    } catch (Exception $exception) {
                        $failed[] = $website['title'];
                    }
                }
                flash('success', '批量删除完成：已删除 ' . $successCount . ' 个网站。');
            } elseif ($operation === 'check') {
                $checker = new HealthChecker(app_config());
                foreach ($selectedWebsites as $website) {
                    try {
                        $checker->refreshWebsite($website);
                        $successCount++;
                    } catch (Exception $exception) {
                        $failed[] = $website['title'];
                    }
                }
                flash('success', '批量检测完成：已检测 ' . $successCount . ' 个网站。');
            } else {
                $inspector = new SiteInspector(app_config());
                foreach ($selectedWebsites as $website) {
                    try {
                        if ($inspector->refreshWebsiteIcon($website)) {
                            $successCount++;
                        } else {
                            $failed[] = $website['title'];
                        }
                    } catch (Exception $exception) {
                        $failed[] = $website['title'];
                    }
                }
                flash('success', '批量图标同步完成：成功 ' . $successCount . ' 个网站。');
            }
            if (count($failed) > 0) {
                flash('error', '未完成 ' . count($failed) . ' 个：' . implode('、', array_slice($failed, 0, 6)) . (count($failed) > 6 ? ' 等。' : '。'));
            }
            redirect_to(website_list_return_url($returnPage, $returnPerPage));
        }
    } catch (Exception $exception) {
        $error = $exception->getMessage();
        if ($action === 'save_site') {
            $addSitePrefill = array(
                'primary_url' => isset($_POST['primary_url']) ? $_POST['primary_url'] : '',
                'backup_url' => isset($_POST['backup_url']) ? $_POST['backup_url'] : '',
                'title' => isset($_POST['title']) ? $_POST['title'] : '',
                'description' => isset($_POST['description']) ? $_POST['description'] : '',
                'icon_url' => isset($_POST['icon_url']) ? $_POST['icon_url'] : '',
                'category_id' => isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0,
                'sort_order' => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 100,
                'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'add_www' => isset($_POST['add_www']) ? 1 : 0,
                'prefer_https' => prefers_https($_POST) ? 1 : 0,
            );
        } elseif ($action === 'bulk_import_sites') {
            $batchImportPrefill = array(
                'batch_sites' => isset($_POST['batch_sites']) ? $_POST['batch_sites'] : '',
                'category_id' => isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0,
                'sort_order' => isset($_POST['sort_order']) ? (int) $_POST['sort_order'] : 100,
                'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'add_www' => isset($_POST['add_www']) ? 1 : 0,
                'prefer_https' => prefers_https($_POST) ? 1 : 0,
            );
        }
    }
}

$categories = all_categories(false);
$categoryCounts = array();
foreach (db()->query('SELECT category_id, COUNT(*) AS site_count FROM websites GROUP BY category_id') as $countRow) {
    $categoryCounts[(int) $countRow['category_id']] = (int) $countRow['site_count'];
}
$websiteListCategoryId = website_list_category_id(isset($_GET['category_id']) ? $_GET['category_id'] : $returnCategoryId);
$websiteListCategory = null;
foreach ($categories as $category) {
    if ((int) $category['id'] === $websiteListCategoryId) {
        $websiteListCategory = $category;
        break;
    }
}
if (!$websiteListCategory) {
    $websiteListCategoryId = 0;
}
$websitePerPage = website_list_per_page(isset($_GET['per_page']) ? $_GET['per_page'] : $returnPerPage);
$websiteKeyword = website_list_keyword(isset($_GET['keyword']) ? $_GET['keyword'] : $returnKeyword);
$websiteWhere = array();
$websiteQueryParams = array();
if ($websiteListCategoryId > 0) {
    $websiteWhere[] = 'w.category_id = :category_id';
    $websiteQueryParams[':category_id'] = $websiteListCategoryId;
}
if ($websiteKeyword !== '') {
    $websiteWhere[] = '(w.title LIKE :keyword OR w.primary_url LIKE :keyword OR w.backup_url LIKE :keyword OR w.description LIKE :keyword)';
    $websiteQueryParams[':keyword'] = '%' . $websiteKeyword . '%';
}
$websiteWhereSql = count($websiteWhere) > 0 ? ' WHERE ' . implode(' AND ', $websiteWhere) : '';
$websiteCountStatement = db()->prepare('SELECT COUNT(*) FROM websites w' . $websiteWhereSql);
foreach ($websiteQueryParams as $parameter => $value) {
    $websiteCountStatement->bindValue($parameter, $value, $parameter === ':category_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$websiteCountStatement->execute();
$websiteTotal = (int) $websiteCountStatement->fetchColumn();
$websiteTotalPages = max(1, (int) ceil($websiteTotal / $websitePerPage));
$websitePage = min(max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1)), $websiteTotalPages);
$websiteOffset = ($websitePage - 1) * $websitePerPage;
$websites = array();
if ($adminView === 'websites') {
    $websiteListSql = 'SELECT w.*, c.name AS category_name, h.primary_status, h.backup_status, h.primary_checked_at, h.backup_checked_at FROM websites w JOIN categories c ON c.id = w.category_id LEFT JOIN website_health h ON h.website_id = w.id';
    $websiteListSql .= $websiteWhereSql;
    $websiteListSql .= ' ORDER BY c.sort_order ASC, w.sort_order ASC, w.title COLLATE NOCASE ASC LIMIT :limit OFFSET :offset';
    $websiteStatement = db()->prepare($websiteListSql);
    foreach ($websiteQueryParams as $parameter => $value) {
        $websiteStatement->bindValue($parameter, $value, $parameter === ':category_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $websiteStatement->bindValue(':limit', $websitePerPage, PDO::PARAM_INT);
    $websiteStatement->bindValue(':offset', $websiteOffset, PDO::PARAM_INT);
    $websiteStatement->execute();
    $websites = $websiteStatement->fetchAll();
}
$statRows = db()->query("SELECT COUNT(*) AS site_count, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count, SUM(clicks) AS clicks FROM websites")->fetch();
$healthy = (int) db()->query("SELECT COUNT(*) FROM website_health WHERE primary_status = 'up'")->fetchColumn();
$siteName = setting('site_name', app_config()['app_name']);
$brandMark = setting('brand_mark', 'N');
$siteSubtitle = setting('site_subtitle', '发现值得访问的网站');
$seoTitle = setting('seo_title', $siteName . ' - ' . $siteSubtitle);
$seoDescription = setting('seo_description', $siteSubtitle);
$seoKeywords = setting('seo_keywords', $siteName . ',网站导航,网址导航');
$homeDirectoryLabel = setting('home_directory_label', '站点目录');
$homeCategoryHint = setting('home_category_hint', '按分类高效发现资源');
$homeHeroTitle = setting('home_hero_title', '探索经过整理的网站');
$homeHeroDescription = setting('home_hero_description', '发现值得访问的网站。所有跳转优先使用可用的主地址，主地址异常时自动尝试备用地址。');
$homeSidebarHealth = setting('home_sidebar_health', '主备域名自动检测');
$homeSidebarIcon = setting('home_sidebar_icon', '图标同步保存至本地');
$adminUsername = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : '';
$redirectMode = setting('redirect_mode', 'direct');
$redirectLinkDisplay = setting('redirect_link_display', 'id');
$redirectDelay = setting('redirect_interstitial_delay', '2.6');
$redirectDelay = is_numeric($redirectDelay) && (float) $redirectDelay >= 0.5 && (float) $redirectDelay <= 60 ? $redirectDelay : '2.6';
$redirectTemplate = setting('redirect_interstitial_template', "正在为你打开 {{site_title}}\n已为你确认可用访问地址。若浏览器没有自动跳转，请点击下方按钮继续。");
$openModal = ($metadata || ($action === 'inspect' && $error) || $addSitePrefill) ? ' open' : '';
$editOpenModal = ($editPrefill || ($action === 'inspect_edit_site' && $error)) ? ' open' : '';
ob_start(function ($output) use ($redirectDelay) { return str_replace('<input value="2.6 秒" disabled>', '<input type="number" name="redirect_interstitial_delay" value="' . e($redirectDelay) . '" min="0.5" max="60" step="0.1" required>', $output); });
?>
<!doctype html>
<html lang="zh-CN">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($siteName) ?> — 管理后台</title><link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/app.css') ?>"></head>
<body>
<div class="admin-layout">
  <header class="admin-nav"><a class="brand" href="/"><span class="brand-mark">N</span><span><?= e($siteName) ?> 管理台</span></a><nav class="admin-nav-links"><a href="/" target="_blank">查看网站</a><a href="/admin/index.php"<?= $adminView === 'dashboard' ? ' class="active"' : '' ?>>总览设置</a><a href="/admin/categories.php"<?= $adminView === 'categories' ? ' class="active"' : '' ?>>分类管理</a><a href="/admin/websites.php"<?= $adminView === 'websites' ? ' class="active"' : '' ?>>网站列表</a><form method="post" style="margin:0"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button type="submit" style="background:none;border:0;color:#d0d5dd;padding:6px 8px">退出</button></form></nav></header>
  <main class="admin-content">
    <?php if ($adminView === 'dashboard'): ?>
    <section class="panel" id="homepage-copy-settings">
      <h2>首页展示文案</h2>
      <p>设置前台顶部目录名称、分类提示、首页主视觉说明及侧栏功能标签。保存后会直接应用到首页。</p>
      <form method="post" class="grid-form">
        <input type="hidden" name="action" value="save_site_settings">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div class="form-field span-6"><label>品牌图标短标识</label><input name="brand_mark" value="<?= e($brandMark) ?>" maxlength="3" required><span class="form-hint">显示在前台、后台和跳转页彩色图标内，建议 1–3 个字符。</span></div>
        <div class="form-field span-6"><label>目录名称</label><input name="home_directory_label" value="<?= e($homeDirectoryLabel) ?>" maxlength="80" required><span class="form-hint">默认文案：站点目录</span></div>
        <div class="form-field span-6"><label>分类提示</label><input name="home_category_hint" value="<?= e($homeCategoryHint) ?>" maxlength="100" required><span class="form-hint">默认文案：按分类高效发现资源</span></div>
        <div class="form-field span-12"><label>首页主标题</label><input name="home_hero_title" value="<?= e($homeHeroTitle) ?>" maxlength="120" required><span class="form-hint">默认文案：探索经过整理的网站</span></div>
        <div class="form-field span-12"><label>首页说明</label><textarea name="home_hero_description" rows="4" maxlength="300" required><?= e($homeHeroDescription) ?></textarea><span class="form-hint">用于首页主标题下方的完整说明。</span></div>
        <div class="form-field span-6"><label>侧栏功能标签一</label><input name="home_sidebar_health" value="<?= e($homeSidebarHealth) ?>" maxlength="80" required><span class="form-hint">默认文案：主备域名自动检测</span></div>
        <div class="form-field span-6"><label>侧栏功能标签二</label><input name="home_sidebar_icon" value="<?= e($homeSidebarIcon) ?>" maxlength="80" required><span class="form-hint">默认文案：图标同步保存至本地</span></div>
        <div class="span-12"><button class="button" type="submit">保存首页文案</button></div>
      </form>
    </section>
    <?php endif; ?>
    <div class="toast-stack" aria-live="polite" aria-atomic="true"><?php if ($notice): ?><aside class="toast toast-success" role="status" data-toast><span class="toast-icon" aria-hidden="true">✓</span><div class="toast-copy"><strong>操作已完成</strong><p><?= e($notice) ?></p></div><button class="toast-close" type="button" data-close-toast aria-label="关闭提示">×</button></aside><?php endif; ?><?php if ($error): ?><aside class="toast toast-error" role="alert" data-toast><span class="toast-icon" aria-hidden="true">!</span><div class="toast-copy"><strong>请注意</strong><p><?= e($error) ?></p></div><button class="toast-close" type="button" data-close-toast aria-label="关闭提示">×</button></aside><?php endif; ?></div>
    <div class="admin-heading"><div><h1><?= $adminView === 'categories' ? '分类管理' : ($adminView === 'websites' ? '网站列表' : '总览设置') ?></h1><p><?= $adminView === 'categories' ? '独立管理分类名称、标识与展示优先级。' : ($adminView === 'websites' ? '分页查看网站，并进行选择、批量检测、图标同步或删除。' : '管理网站设置、跳转规则与账户安全。') ?></p></div><?php if ($adminView === 'websites'): ?><div class="admin-heading-actions"><button class="button-secondary" type="button" data-open-modal="batchImportModal">批量添加网站</button><button class="button" type="button" data-open-modal="addSiteModal">+ 添加网站</button></div><?php endif; ?></div>
    <?php if ($adminView === 'dashboard'): ?><section class="admin-stats"><div class="admin-stat"><strong><?= (int) $statRows['site_count'] ?></strong><span>网站总数</span></div><div class="admin-stat"><strong><?= (int) $statRows['active_count'] ?></strong><span>前台展示中</span></div><div class="admin-stat"><strong><?= $healthy ?></strong><span>主地址可用</span></div><div class="admin-stat"><strong><?= number_format((int) $statRows['clicks']) ?></strong><span>累计跳转</span></div></section><?php endif; ?>

    <?php if ($adminView === 'dashboard'): ?><section class="panel" id="site-settings"><h2>网站设置</h2><p>在此管理网站名称、前台简介与搜索引擎展示信息；保存后会立即应用到前台页面。</p><div class="settings-grid"><div class="settings-card"><h3>网站与 SEO</h3><p>SEO 标题、简介和关键词用于浏览器标题及搜索引擎信息。留空时会按网站名和简介自动生成。</p><form method="post" class="grid-form"><input type="hidden" name="action" value="save_site_settings"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><div class="form-field span-6"><label>网站名</label><input name="site_name" value="<?= e($siteName) ?>" maxlength="80" required><span class="form-hint">显示在前台页头和后台品牌区域。</span></div><div class="form-field span-6"><label>网站简介</label><input name="site_subtitle" value="<?= e($siteSubtitle) ?>" maxlength="160" required><span class="form-hint">显示在前台页面主标题下方。</span></div><div class="form-field span-12"><label>SEO 标题</label><input name="seo_title" value="<?= e($seoTitle) ?>" maxlength="120" placeholder="例如：站界导航 - 发现值得访问的网站"><span class="form-hint">建议控制在 60 个中文字符以内。</span></div><div class="form-field span-12"><label>SEO Keywords（关键词）</label><input name="seo_keywords" value="<?= e($seoKeywords) ?>" maxlength="500" placeholder="例如：网站导航,网址导航,优质网站"><span class="form-hint">请用英文逗号分隔关键词。</span></div><div class="form-field span-12"><label>SEO 简介</label><textarea name="seo_description" rows="4" maxlength="300" placeholder="简要说明网站内容和特点"><?= e($seoDescription) ?></textarea><span class="form-hint">建议控制在 120 个中文字符以内。</span></div><div class="span-12"><button class="button" type="submit">保存网站设置</button></div></form></div><div class="settings-card security-card"><h3>账户安全</h3><p>输入当前密码后，可一次保存新的管理员名称和密码。</p><form method="post" class="grid-form"><input type="hidden" name="action" value="update_admin_account"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><div class="form-field span-12"><label>管理员名称</label><input name="username" value="<?= e($adminUsername) ?>" minlength="3" maxlength="60" pattern="[A-Za-z0-9_-]{3,60}" autocomplete="username" required></div><div class="form-field span-12"><label>当前密码</label><input type="password" name="current_password" autocomplete="current-password" required></div><div class="form-field span-6"><label>新密码</label><input type="password" name="new_password" minlength="10" autocomplete="new-password" required></div><div class="form-field span-6"><label>确认新密码</label><input type="password" name="confirm_password" minlength="10" autocomplete="new-password" required></div><div class="span-12"><button class="button-secondary" type="submit">保存账户安全设置</button></div></form></div></div></section>

    <section class="panel" id="redirect-settings"><h2>URL 网站跳转管理</h2><p>系统直跳和过渡页跳转都会经过主备检测与访问记录；直接网站域名转向会让前台卡片 A 链接直接指向网站主域名，不经过系统跳转入口。</p><form method="post" class="grid-form"><input type="hidden" name="action" value="save_redirect_settings"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><div class="form-field span-4"><label>跳转模式</label><select name="redirect_mode"><option value="direct"<?= $redirectMode === 'direct' ? ' selected' : '' ?>>系统直接跳转</option><option value="interstitial"<?= $redirectMode === 'interstitial' ? ' selected' : '' ?>>带跳转页面转向</option><option value="domain_direct"<?= $redirectMode === 'domain_direct' ? ' selected' : '' ?>>直接网站域名转向</option></select><span class="form-hint">域名直连模式下会始终使用网站主域名。</span></div><div class="form-field span-4"><label>前台 URL 显示</label><select name="redirect_link_display"><option value="domain"<?= $redirectLinkDisplay === 'domain' ? ' selected' : '' ?>>显示网站域名</option><option value="id"<?= $redirectLinkDisplay === 'id' ? ' selected' : '' ?>>显示 ID 地址（/go.php?id=1）</option><option value="blank"<?= $redirectLinkDisplay === 'blank' ? ' selected' : '' ?>>空白（隐藏地址）</option></select><span class="form-hint">选择空白后仅隐藏卡片地址文字，不影响点击跳转。</span></div><div class="form-field span-4"><label>自动转向时间</label><input value="2.6 秒" disabled><span class="form-hint">仅过渡页模式下生效。</span></div><div class="form-field span-12"><label>跳转页面模板文案</label><textarea name="redirect_interstitial_template" rows="5"><?= e($redirectTemplate) ?></textarea><span class="form-hint">可用占位符：{{site_title}}、{{site_name}}、{{destination_domain}}、{{continue_url}}。为保证访问安全，模板会作为纯文本显示，不执行 HTML 或脚本。</span></div><div class="span-12"><button class="button" type="submit">保存跳转设置</button></div></form></section><?php endif; ?>

    <?php if ($adminView === 'categories'): ?><section class="panel" id="categories"><h2>分类与优先级</h2><p>优先级数值越小，前台展示越靠前。可直接使用上移、下移快速调整。</p>
      <div class="table-wrap"><table><thead><tr><th>优先级</th><th>分类名称</th><th>标识</th><th>网站数</th><th>操作</th></tr></thead><tbody>
      <?php foreach ($categories as $category): $siteCount = isset($categoryCounts[(int) $category['id']]) ? $categoryCounts[(int) $category['id']] : 0; ?>
        <tr><td><?= (int) $category['sort_order'] ?></td><td><strong><?= e($category['name']) ?></strong></td><td><code><?= e($category['slug']) ?></code></td><td><?= $siteCount ?></td><td><div class="actions"><button class="button-secondary small" type="button" data-edit-category data-category-id="<?= (int) $category['id'] ?>" data-category-name="<?= e($category['name']) ?>" data-category-slug="<?= e($category['slug']) ?>" data-category-sort-order="<?= (int) $category['sort_order'] ?>">编辑</button><form method="post"><input type="hidden" name="action" value="move_category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><input type="hidden" name="direction" value="up"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button class="button-secondary small">上移</button></form><form method="post"><input type="hidden" name="action" value="move_category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><input type="hidden" name="direction" value="down"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button class="button-secondary small">下移</button></form><?php if ($siteCount === 0): ?><form method="post"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button class="button-danger small">删除</button></form><?php else: ?><span class="tag" title="请先迁移或删除该分类下的网站">不可删除</span><?php endif; ?></div></td></tr>
      <?php endforeach; ?></tbody></table></div>
      <form method="post" class="grid-form" style="margin-top:18px"><input type="hidden" name="action" value="add_category"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><div class="form-field span-4"><label>新分类名称</label><input name="name" placeholder="例如：办公协作" required></div><div class="form-field span-4"><label>URL 标识（可选）</label><input name="slug" placeholder="office-tools"></div><div class="form-field span-2"><label>优先级</label><input type="number" min="0" name="sort_order" value="100"></div><div class="span-2"><button class="button" type="submit">添加分类</button></div></form>
    </section><?php endif; ?>

    <?php if ($adminView === 'websites'): ?><section class="panel" id="websites"><div class="list-panel-heading"><div><h2>网站与域名状态</h2><p><?= $websiteListCategory ? '当前只显示“' . e($websiteListCategory['name']) . '”分类的 ' . $websiteTotal . ' 个网站。' : '共 ' . $websiteTotal . ' 个网站；可按分类筛选显示。' ?></p></div><form method="get" class="page-size-form"><label>按分类显示 <select name="category_id" onchange="this.form.submit()"><option value="0">全部分类</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"<?= $websiteListCategoryId === (int) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?>（<?= isset($categoryCounts[(int) $category['id']]) ? (int) $categoryCounts[(int) $category['id']] : 0 ?>）</option><?php endforeach; ?></select></label><label>每页显示 <select name="per_page" onchange="this.form.submit()"><?php foreach (array(20, 50, 100, 200) as $option): ?><option value="<?= $option ?>"<?= $websitePerPage === $option ? ' selected' : '' ?>><?= $option ?> 条</option><?php endforeach; ?></select></label><input type="hidden" name="page" value="1"></form></div>
      <form method="post" id="bulkSiteForm" data-bulk-site-form><input type="hidden" name="action" value="bulk_site_action"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="_return_view" value="websites"><input type="hidden" name="_return_page" value="<?= $websitePage ?>"><input type="hidden" name="_return_per_page" value="<?= $websitePerPage ?>"><input type="hidden" name="_return_category_id" value="<?= (int) $websiteListCategoryId ?>"><div class="bulk-toolbar"><div class="selection-tools"><button class="button-secondary small" type="button" data-select-all-sites>全选本页</button><button class="button-secondary small" type="button" data-invert-site-selection>反选</button><span data-selected-site-count>已选择 0 项</span></div><div class="bulk-actions"><select name="bulk_operation" required><option value="">选择批量操作</option><option value="check">批量检测</option><option value="sync_icon">批量同步图标</option><option value="delete">批量删除</option></select><button class="button-danger small" type="submit">执行操作</button></div></div></form><p class="bulk-network-note">批量检测和图标同步会逐站分段处理。选择大量网站时请保持当前页面打开，系统会持续显示进度，不会用一个长请求阻塞后台。</p>
      <div class="table-wrap"><table><thead><tr><th><input type="checkbox" form="bulkSiteForm" aria-label="选择本页所有网站" data-select-all-sites-checkbox></th><th>网站</th><th>分类</th><th>主 / 备用域名</th><th>健康状态</th><th>人气</th><th>状态</th><th>操作</th></tr></thead><tbody>
      <?php if (!$websites): ?><tr><td colspan="8" style="text-align:center;color:#667085;padding:26px">当前页没有网站。</td></tr><?php endif; ?>
      <?php foreach ($websites as $website): ?>
        <tr><td><input type="checkbox" form="bulkSiteForm" name="website_ids[]" value="<?= (int) $website['id'] ?>" data-site-selection aria-label="选择 <?= e($website['title']) ?>"></td><td><div class="table-site"><?php if ($website['icon_path']): ?><img class="favicon" src="<?= e(public_icon_url($website['icon_path'])) ?>" alt=""><?php else: ?><span class="fallback-icon"><?= e(mb_substr($website['title'], 0, 1, 'UTF-8')) ?></span><?php endif; ?><div><strong><?= e($website['title']) ?></strong><span class="url-text" title="<?= e($website['description']) ?>"><?= e($website['description']) ?></span></div></div></td><td><?= e($website['category_name']) ?></td><td><span class="url-text" title="<?= e($website['primary_url']) ?>"><?= e($website['primary_url']) ?></span><?php if ($website['backup_url']): ?><span class="url-text" title="<?= e($website['backup_url']) ?>">备：<?= e($website['backup_url']) ?></span><?php endif; ?></td><td><span class="tag <?= e($website['primary_status']) ?>"><i class="health <?= e($website['primary_status']) ?>"></i> 主 <?= e(status_label($website['primary_status'])) ?></span><?php if ($website['backup_url']): ?> <span class="tag <?= e($website['backup_status']) ?>">备 <?= e(status_label($website['backup_status'])) ?></span><?php endif; ?></td><td><?= number_format((int) $website['clicks']) ?></td><td><?= (int) $website['is_active'] ? '<span class="tag up">展示</span>' : '<span class="tag">隐藏</span>' ?></td><td><div class="actions"><button class="button-secondary small" type="button" data-edit-site='<?= e(json_encode($website, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE)) ?>'>编辑</button><form method="post"><input type="hidden" name="action" value="check_site"><input type="hidden" name="website_id" value="<?= (int) $website['id'] ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="_return_page" value="<?= $websitePage ?>"><input type="hidden" name="_return_per_page" value="<?= $websitePerPage ?>"><button class="button-secondary small">检测</button></form><form method="post"><input type="hidden" name="action" value="sync_icon"><input type="hidden" name="website_id" value="<?= (int) $website['id'] ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="_return_page" value="<?= $websitePage ?>"><input type="hidden" name="_return_per_page" value="<?= $websitePerPage ?>"><button class="button-secondary small">图标</button></form><form method="post"><input type="hidden" name="action" value="delete_site"><input type="hidden" name="website_id" value="<?= (int) $website['id'] ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="_return_page" value="<?= $websitePage ?>"><input type="hidden" name="_return_per_page" value="<?= $websitePerPage ?>"><button class="button-danger small">删除</button></form></div></td></tr>
      <?php endforeach; ?></tbody></table></div>
      <?php if ($websiteTotalPages > 1): ?><nav class="pagination" aria-label="网站分页"><a class="button-secondary small<?= $websitePage <= 1 ? ' disabled' : '' ?>"<?= $websitePage > 1 ? ' href="' . e(website_list_return_url(1, $websitePerPage, $websiteListCategoryId)) . '"' : '' ?>>首页</a><a class="button-secondary small<?= $websitePage <= 1 ? ' disabled' : '' ?>"<?= $websitePage > 1 ? ' href="' . e(website_list_return_url($websitePage - 1, $websitePerPage, $websiteListCategoryId)) . '"' : '' ?>>上一页</a><?php for ($pageNumber = 1; $pageNumber <= $websiteTotalPages; $pageNumber++): ?><?php if ($pageNumber === $websitePage): ?><span class="button-secondary small current-page" aria-current="page"><?= $pageNumber ?></span><?php else: ?><a class="button-secondary small" href="<?= e(website_list_return_url($pageNumber, $websitePerPage, $websiteListCategoryId)) ?>"><?= $pageNumber ?></a><?php endif; ?><?php endfor; ?><a class="button-secondary small<?= $websitePage >= $websiteTotalPages ? ' disabled' : '' ?>"<?= $websitePage < $websiteTotalPages ? ' href="' . e(website_list_return_url($websitePage + 1, $websitePerPage, $websiteListCategoryId)) . '"' : '' ?>>下一页</a><a class="button-secondary small<?= $websitePage >= $websiteTotalPages ? ' disabled' : '' ?>"<?= $websitePage < $websiteTotalPages ? ' href="' . e(website_list_return_url($websiteTotalPages, $websitePerPage, $websiteListCategoryId)) . '"' : '' ?>>尾页</a></nav><?php endif; ?>
    </section><?php endif; ?>
  </main>
</div>

<div class="modal-backdrop<?= $openModal ?>" id="addSiteModal"><section class="modal"><div class="modal-header"><h2 id="siteModalTitle">添加网站</h2><button class="icon-button" type="button" data-close-modal="addSiteModal">×</button></div><p style="margin-top:0;color:#667085">可直接手动填写网站资料；也可先输入主域名，再点击“读取网站数据”自动填充标题、介绍和图标。读取后的内容仍可继续修改。</p><?php if ($metadata): ?><div class="preview"><?php if ($metadata['icon_url']): ?><span class="fallback-icon">I</span><?php else: ?><span class="fallback-icon">?</span><?php endif; ?><div><p class="preview-title"><?= e($metadata['title']) ?></p><p class="preview-desc">已读取网站数据，可继续调整后保存。</p></div></div><?php endif; ?><form method="post" class="grid-form" id="addSiteForm"><input type="hidden" name="action" value="save_site"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><div class="form-field span-12"><label>主域名</label><div class="input-with-action"><input type="text" inputmode="url" name="primary_url" id="add_primary_url" value="<?= e($metadata ? $metadata['primary_url'] : '') ?>" placeholder="example.com 或 https://example.com" required autofocus><button class="button-secondary" type="button" data-inspect-add-site>读取网站数据</button></div><span class="form-hint">可直接填写域名；系统会自动补齐协议，并只允许公网 HTTP/HTTPS 地址。</span><label class="inline-check"><input type="checkbox" name="add_www" id="add_add_www"<?= $inspectAddWww ? ' checked' : '' ?>> 自动补齐 www</label></div><div class="form-field span-6"><label>网站标题</label><input name="title" id="add_title" value="<?= e($metadata ? $metadata['title'] : '') ?>" placeholder="例如：示例网站" required></div><div class="form-field span-6"><label>所属分类</label><select name="category_id" id="add_category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div><div class="form-field span-12"><label>网站介绍</label><textarea name="description" id="add_description" placeholder="简要说明该网站的用途和特色"><?= e($metadata ? $metadata['description'] : '') ?></textarea></div><div class="form-field span-6"><label>图标 URL（可选）</label><input name="icon_url" id="add_icon_url" value="<?= e($metadata ? $metadata['icon_url'] : '') ?>" placeholder="读取后会自动填入"></div><div class="form-field span-6"><label>备用域名（可选）</label><input name="backup_url" id="add_backup_url" placeholder="backup.example.com"></div><div class="settings-row span-6"><div class="form-field"><label>显示优先级</label><input type="number" min="0" name="sort_order" value="100"></div><div class="form-field"><label>显示选项</label><div class="checkbox-options"><label><input type="checkbox" name="is_featured"> 精选推荐</label><label><input type="checkbox" name="is_active" checked> 前台展示</label></div></div></div><div class="span-12"><button class="button" type="submit" data-add-save-button>保存网站</button><span class="form-hint" data-add-save-hint>读取仅回填当前表单；点击“保存网站”后才会收录。</span></div></form></section></div>

<div class="modal-backdrop" id="batchImportModal"><section class="modal"><div class="modal-header"><h2>批量添加网站</h2><button class="icon-button" type="button" data-close-modal="batchImportModal">×</button></div><p class="batch-import-intro">每行填写一个网站，格式为“主域名”或“主域名,备用域名”。例如：<code>1.com</code>、<code>1.com,2.com</code>。未填写协议时会先尝试 HTTPS，再尝试 HTTP；不可访问、不安全、重复或无法读取资料的网站会自动跳过。</p><form method="post" class="grid-form" id="batchImportForm"><input type="hidden" name="action" value="bulk_import_sites"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><div class="form-field span-12"><label>网站域名列表</label><textarea name="batch_sites" rows="10" maxlength="5000" placeholder="1.com&#10;1.com,2.com&#10;https://example.com" required></textarea><span class="form-hint">一次最多导入 20 行；每行最多填写 1 个主域名和 1 个备用域名。</span></div><div class="form-field span-6"><label>所属分类</label><select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div><div class="form-field span-6"><label>显示优先级</label><input type="number" min="0" name="sort_order" value="100"></div><div class="form-field span-12"><label>导入选项</label><div class="checkbox-options"><label><input type="checkbox" name="add_www"> 自动补齐 www</label><label><input type="checkbox" name="is_active" checked> 前台展示</label><label><input type="checkbox" name="is_featured"> 精选推荐</label></div><span class="form-hint">勾选“自动补齐 www”后，<code>1.com</code> 会按 <code>www.1.com</code> 尝试访问。</span></div><div class="span-12"><button class="button" type="submit">开始批量添加</button></div></form></section></div>

<div class="modal-backdrop" id="csvImportModal"><section class="modal"><div class="modal-header"><h2>导入网站 CSV</h2><button class="icon-button" type="button" data-close-modal="csvImportModal">×</button></div><p class="batch-import-intro">请先导出当前数据作为模板。CSV 首行应包含：<code>分类名称</code>、<code>网站标题</code>、<code>网站介绍</code>、<code>主域名</code>、<code>备用域名</code>、<code>显示优先级</code>、<code>前台展示</code>、<code>精选推荐</code>。分类名称须已存在；同域名、无效地址和错误分类会自动跳过。</p><form method="post" enctype="multipart/form-data" class="grid-form"><input type="hidden" name="action" value="import_websites_csv"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="_return_view" value="websites"><input type="hidden" name="_return_page" value="<?= $websitePage ?>"><input type="hidden" name="_return_per_page" value="<?= $websitePerPage ?>"><div class="form-field span-12"><label>CSV 文件</label><input type="file" name="csv_file" accept=".csv,text/csv" required><span class="form-hint">仅支持 UTF-8 CSV，最大 2MB、每次最多导入 200 条。导入不会自动访问目标网站；完成后可在列表中批量检测并同步图标。</span></div><div class="span-12"><button class="button" type="submit">开始导入 CSV</button></div></form></section></div>

<div class="modal-backdrop" id="editCategoryModal"><section class="modal"><div class="modal-header"><h2>编辑分类资料</h2><button class="icon-button" type="button" data-close-modal="editCategoryModal">×</button></div><p style="margin-top:0;color:#667085">可同时修改分类优先级、显示名称和 URL 标识。标识变更不会影响已归属到该分类的网站。</p><form method="post" class="grid-form" id="editCategoryForm"><input type="hidden" name="action" value="update_category"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="category_id" id="edit_category_record_id"><div class="form-field span-4"><label>优先级</label><input type="number" min="0" name="sort_order" id="edit_category_sort_order" required></div><div class="form-field span-8"><label>分类名称</label><input name="name" id="edit_category_name" maxlength="60" required></div><div class="form-field span-12"><label>标识</label><input name="slug" id="edit_category_slug" pattern="[A-Za-z0-9-]+" placeholder="developer-tools" required><span class="form-hint">使用英文字母、数字和连字符；须保持唯一。</span></div><div class="span-12"><button class="button" type="submit">保存分类资料</button></div></form></section></div>

<div class="modal-backdrop<?= $editOpenModal ?>" id="editSiteModal"><section class="modal"><div class="modal-header"><h2>编辑网站资料</h2><button class="icon-button" type="button" data-close-modal="editSiteModal">×</button></div><?php if ($editMetadata): ?><div class="preview"><span class="fallback-icon">I</span><div><p class="preview-title"><?= e($editMetadata['title']) ?></p><p class="preview-desc">已读取网站数据，可继续调整后保存修改。</p></div></div><?php endif; ?><form method="post" class="grid-form" id="editSiteForm"><input type="hidden" name="action" value="update_site"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="website_id" id="edit_id" value="<?= e($editPrefill ? $editPrefill['id'] : '') ?>"><div class="form-field span-12"><label>主域名</label><div class="input-with-action"><input name="primary_url" id="edit_primary_url" value="<?= e($editPrefill ? $editPrefill['primary_url'] : '') ?>" required><button class="button-secondary" type="button" data-inspect-edit-site>读取网站数据</button></div><label class="inline-check"><input type="checkbox" name="add_www" id="edit_add_www"<?= $editPrefill && !empty($editPrefill['add_www']) ? ' checked' : '' ?>> 自动补齐 www</label></div><div class="form-field span-6"><label>网站标题</label><input name="title" id="edit_title" value="<?= e($editPrefill ? $editPrefill['title'] : '') ?>" required></div><div class="form-field span-6"><label>所属分类</label><select name="category_id" id="edit_category_id"><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"<?= $editPrefill && (int) $editPrefill['category_id'] === (int) $category['id'] ? ' selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></div><div class="form-field span-12"><label>网站介绍</label><textarea name="description" id="edit_description"><?= e($editPrefill ? $editPrefill['description'] : '') ?></textarea></div><div class="form-field span-6"><label>图标 URL（可选）</label><input name="icon_url" id="edit_icon_url" value="<?= e($editPrefill && isset($editPrefill['icon_url']) ? $editPrefill['icon_url'] : '') ?>" placeholder="读取后会自动填入"></div><div class="form-field span-6"><label>备用域名（可选）</label><input name="backup_url" id="edit_backup_url" value="<?= e($editPrefill ? $editPrefill['backup_url'] : '') ?>"></div><div class="settings-row span-6"><div class="form-field"><label>显示优先级</label><input type="number" min="0" name="sort_order" id="edit_sort_order" value="<?= e($editPrefill ? $editPrefill['sort_order'] : '100') ?>"></div><div class="form-field"><label>显示选项</label><div class="checkbox-options"><label><input type="checkbox" name="is_featured" id="edit_is_featured"<?= $editPrefill && !empty($editPrefill['is_featured']) ? ' checked' : '' ?>> 精选推荐</label><label><input type="checkbox" name="is_active" id="edit_is_active"<?= !$editPrefill || !empty($editPrefill['is_active']) ? ' checked' : '' ?>> 前台展示</label></div></div></div><div class="span-12"><button class="button" type="submit" data-edit-save-button>保存修改</button><span class="form-hint" data-edit-save-hint>读取仅回填当前表单；点击“保存修改”后才会同步更新网站列表。</span></div></form></section></div>
<?php if ($addSitePrefill): ?><script>window.addSitePrefill = <?= json_encode($addSitePrefill, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>; window.addSiteError = <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script><?php endif; ?>
<?php if ($batchImportPrefill): ?><script>window.batchImportPrefill = <?= json_encode($batchImportPrefill, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>; window.batchImportError = <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script><?php endif; ?>
<?php if ($editPrefill && $action === 'inspect_edit_site' && $error): ?><script>window.editSiteError = <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script><?php endif; ?>
<script src="/assets/js/app.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body></html>
