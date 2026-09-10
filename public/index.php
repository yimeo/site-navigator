<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

function public_domain_path_host($url)
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\./i', 'www.', $host);
}

$prettyDomainPath = trim((string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH), '/');
if ($prettyDomainPath !== '' && strpos($prettyDomainPath, '/') === false && strpos($prettyDomainPath, '.') !== false && !preg_match('/\.(?:php|css|js|ico|png|jpg|jpeg|gif|svg|webp)$/i', $prettyDomainPath)) {
    $prettyHost = strtolower(rawurldecode($prettyDomainPath));
    $prettyWebsite = null;
    foreach (db()->query('SELECT * FROM websites WHERE is_active = 1') as $candidate) {
        if (public_domain_path_host($candidate['primary_url']) === $prettyHost || ($candidate['backup_url'] && public_domain_path_host($candidate['backup_url']) === $prettyHost)) {
            $prettyWebsite = $candidate;
            break;
        }
    }
    if ($prettyWebsite) {
        $_GET['id'] = (int) $prettyWebsite['id'];
        require __DIR__ . '/go.php';
        exit;
    }
}

$sortVisibility = array(
    'priority' => setting('home_sort_priority_visible', '1') === '1',
    'popular' => setting('home_sort_popular_visible', '1') === '1',
    'latest' => setting('home_sort_latest_visible', '1') === '1',
);
$allowedSorts = array_keys(array_filter($sortVisibility));
if (!$allowedSorts) {
    $allowedSorts = array('priority');
}
$sortDefault = setting('home_sort_default', 'priority');
if (!in_array($sortDefault, $allowedSorts, true)) {
    $sortDefault = $allowedSorts[0];
}
$sort = isset($_GET['sort']) ? $_GET['sort'] : $sortDefault;
if (!in_array($sort, $allowedSorts, true)) {
    $sort = $sortDefault;
}
$query = isset($_GET['q']) ? trim($_GET['q']) : '';

$orderBy = 'w.is_featured DESC, w.sort_order ASC, w.title COLLATE NOCASE ASC';
if ($sort === 'popular') {
    $orderBy = 'w.clicks DESC, w.is_featured DESC, w.sort_order ASC, w.title COLLATE NOCASE ASC';
} elseif ($sort === 'latest') {
    $orderBy = 'datetime(w.created_at) DESC, w.is_featured DESC, w.sort_order ASC';
}

$sql = 'SELECT w.*, c.name AS category_name, c.slug AS category_slug, c.sort_order AS category_order, h.primary_status, h.backup_status
        FROM websites w
        INNER JOIN categories c ON c.id = w.category_id
        LEFT JOIN website_health h ON h.website_id = w.id
        WHERE w.is_active = 1 AND c.is_active = 1';
$params = array();
if ($query !== '') {
    $sql .= ' AND (w.title LIKE :query OR w.description LIKE :query OR c.name LIKE :query)';
    $params[':query'] = '%' . $query . '%';
}
$sql .= ' ORDER BY c.sort_order ASC, ' . $orderBy;
$statement = db()->prepare($sql);
$statement->execute($params);
$websites = $statement->fetchAll();

$categories = all_categories(true);
$homeMobileGridColumns = in_array((int) setting('home_mobile_grid_columns', '1'), array(1, 2), true) ? (int) setting('home_mobile_grid_columns', '1') : 1;
$homeGridColumns = in_array((int) setting('home_grid_columns', '4'), array(3, 4, 5, 6), true) ? (int) setting('home_grid_columns', '4') : 4;
$homeCategoryConfig = json_decode(setting('home_category_config', '{}'), true);
if (!is_array($homeCategoryConfig)) { $homeCategoryConfig = array(); }
$grouped = array();
foreach ($categories as $category) {
    $grouped[$category['id']] = array('category' => $category, 'websites' => array());
}
foreach ($websites as $website) {
    if (isset($grouped[$website['category_id']])) {
        $grouped[$website['category_id']]['websites'][] = $website;
    }
}

$summary = db()->query('SELECT COUNT(*) AS sites, COALESCE(SUM(clicks), 0) AS clicks FROM websites WHERE is_active = 1')->fetch();
$categoryCount = (int) db()->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn();
$siteName = setting('site_name', app_config()['app_name']);
$brandMark = setting('brand_mark', 'N');
$subtitle = setting('site_subtitle', '发现值得访问的网站');
$homeDirectoryLabel = setting('home_directory_label', '站点目录');
$homeCategoryHint = setting('home_category_hint', '按分类高效发现资源');
$homeHeroTitle = setting('home_hero_title', '探索经过整理的网站');
$homeHeroDescription = setting('home_hero_description', '发现值得访问的网站。所有跳转优先使用可用的主地址，主地址异常时自动尝试备用地址。');
$homeSidebarHealth = setting('home_sidebar_health', '主备域名自动检测');
$homeSidebarIcon = setting('home_sidebar_icon', '图标同步保存至本地');
$seoTitle = setting('seo_title', $siteName . ' - ' . $subtitle);
$seoDescription = setting('seo_description', $subtitle);
$seoKeywords = setting('seo_keywords', $siteName . ',网站导航,网址导航');
$redirectMode = setting('redirect_mode', 'direct');
$redirectLinkDisplay = setting('redirect_link_display', 'id');
function sort_url($sort, $query)
{
    $params = array('sort' => $sort);
    if ($query !== '') {
        $params['q'] = $query;
    }
    return '/?' . http_build_query($params);
}
function public_redirect_label($website, $displayMode)
{
    if ($displayMode === 'blank') {
        return '';
    }
    if ($displayMode === 'domain') {
        $host = parse_url($website['primary_url'], PHP_URL_HOST);
        return $host ? $host : $website['primary_url'];
    }
    return '/go/?id=' . (int) $website['id'];
}
function public_card_href($website, $redirectMode)
{
    if ($redirectMode === 'domain_direct') {
        return $website['primary_url'];
    }
    if ($redirectMode === 'domain_interstitial' || $redirectMode === 'domain_interstitial_direct') {
        $host = public_domain_path_host($website['primary_url']);
        return '/go/?url=' . $website['primary_url'];
    }
    return '/go/?id=' . (int) $website['id'];
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="<?= e($seoDescription) ?>">
  <meta name="keywords" content="<?= e($seoKeywords) ?>">
  <title><?= e($seoTitle) ?></title>
  <link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="/"><span class="brand-mark"><?= e($brandMark) ?></span><span><?= e($siteName) ?></span></a>
    <div class="sidebar-label">网站分类</div>
    <nav class="category-nav" aria-label="网站分类">
      <?php foreach ($grouped as $group): ?>
        <?php if (count($group['websites']) > 0): ?>
          <?php $navConfig = isset($homeCategoryConfig[(int) $group['category']['id']]) ? $homeCategoryConfig[(int) $group['category']['id']] : array('mode' => 'home'); ?><a href="<?= $navConfig['mode'] === 'page' ? e('/category/?slug=' . rawurlencode($group['category']['slug'])) : '#' . e($group['category']['slug']) ?>"><?= e($group['category']['name']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"><?= e($homeSidebarHealth) ?><br><?= e($homeSidebarIcon) ?></div>
  </aside>

  <main class="main">
    <header class="topbar">
      <div class="page-intro"><h1><?= e($homeDirectoryLabel) ?></h1><p><?= e($homeCategoryHint) ?></p></div>
      <form class="search" method="get" action="/" role="search" data-search-form>
        <label class="sr-only" for="site-search">搜索网站名称、介绍或分类</label>
        <input id="site-search" name="q" value="<?= e($query) ?>" placeholder="搜索网站名称、介绍或分类" autocomplete="off" aria-describedby="site-search-error" data-search-input>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <button class="search-submit" type="submit" aria-label="搜索网站" data-search-submit><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="5.8"></circle><path d="m16 16 4.2 4.2"></path></svg><span data-search-label>搜索</span></button>
        <span class="search-error" id="site-search-error" role="alert" data-search-error></span>
      </form>
      <div class="header-actions"><label class="theme-picker"><span aria-hidden="true">主题</span><select data-theme-switch aria-label="选择页面主题"><option value="daylight">晴空蓝</option><option value="night">深夜黑</option><option value="forest">森林绿</option><option value="twilight">暮光紫</option><option value="china-red">中国红</option><option value="glazed-yellow">琉璃黄</option><option value="cloud-gray">云雾灰</option></select></label></div>
    </header>

    <div class="content">
      <section class="hero">
        <div><h2><?= $query !== '' ? '“' . e($query) . '”的搜索结果' : e($homeHeroTitle) ?></h2><p><?= e($homeHeroDescription) ?></p></div>
        <div class="sort-control" data-sort-control>
          <nav class="sort-tabs" aria-label="排序方式" data-sort-tabs>
            <?php if ($sortVisibility['priority']): ?><a class="<?= $sort === 'priority' ? 'active' : '' ?>" href="<?= e(sort_url('priority', $query)) ?>" data-sort-tab>优先推荐</a><?php endif; ?>
            <?php if ($sortVisibility['popular']): ?><a class="<?= $sort === 'popular' ? 'active' : '' ?>" href="<?= e(sort_url('popular', $query)) ?>" data-sort-tab>人气最高</a><?php endif; ?>
            <?php if ($sortVisibility['latest']): ?><a class="<?= $sort === 'latest' ? 'active' : '' ?>" href="<?= e(sort_url('latest', $query)) ?>" data-sort-tab>最新收录</a><?php endif; ?>
          </nav>
          <span class="sort-switch-status" data-sort-status role="status" aria-live="polite">正在切换排序…</span>
        </div>
      </section>

      <section class="stats" aria-label="站点统计">
        <div class="stat"><span class="stat-number"><?= (int) $summary['sites'] ?></span><span class="stat-label">已收录网站</span></div>
        <div class="stat"><span class="stat-number"><?= $categoryCount ?></span><span class="stat-label">优先分类</span></div>
        <div class="stat"><span class="stat-number"><?= number_format((int) $summary['clicks']) ?></span><span class="stat-label">有效访问次数</span></div>
      </section>

      <?php $hasResult = false; foreach ($grouped as $group): ?>
        <?php if (count($group['websites']) === 0) { continue; } $hasResult = true; $categoryConfig = isset($homeCategoryConfig[(int) $group['category']['id']]) ? $homeCategoryConfig[(int) $group['category']['id']] : array('limit' => 0, 'mode' => 'home'); $categoryTotal = count($group['websites']); $categoryLimit = isset($categoryConfig['limit']) ? (int) $categoryConfig['limit'] : 0; $shownWebsites = $categoryLimit > 0 ? array_slice($group['websites'], 0, $categoryLimit) : $group['websites']; $categoryHasMore = $categoryLimit > 0 && $categoryTotal > $categoryLimit; ?>
        <section class="category-section" id="<?= e($group['category']['slug']) ?>">
          <div class="section-head"><h3 class="section-title"><?= e($group['category']['name']) ?> <span class="section-count"><?= $categoryTotal ?> 个网站</span></h3><?php if ($categoryHasMore || (isset($categoryConfig['mode']) && $categoryConfig['mode'] === 'page')): ?><a class="section-more" href="<?= e('/category/?slug=' . rawurlencode($group['category']['slug'])) ?>">更多</a><?php endif; ?></div>
          <div class="website-grid">
            <?php foreach ($shownWebsites as $website): ?>
              <?php $cardHref = public_card_href($website, $redirectMode); $cardLabelMode = $redirectLinkDisplay === 'blank' ? 'blank' : ($redirectMode === 'domain_direct' ? 'domain' : $redirectLinkDisplay); $cardUrlLabel = public_redirect_label($website, $cardLabelMode); ?>
              <article class="site-card">
                <a class="site-link" href="<?= e($cardHref) ?>" rel="noopener noreferrer" target="_blank" title="打开 <?= e($website['title']) ?>"<?= $redirectMode === 'domain_direct' ? ' data-direct-track-url="/go/?id=' . (int) $website['id'] . '&amp;track=1"' : '' ?>>
                  <?php if ($website['icon_path']): ?>
                    <img class="favicon" src="<?= e(website_icon_url($website['icon_path'])) ?>" alt="" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='grid'">
                    <span class="fallback-icon hidden" aria-hidden="true"><?= e(mb_substr($website['title'], 0, 1, 'UTF-8')) ?></span>
                  <?php else: ?>
                    <img class="favicon" src="<?= e(website_icon_url(null)) ?>" alt="默认网站图标" loading="lazy">
                  <?php endif; ?>
                  <span class="site-body">
                    <span class="site-title-row"><span class="site-title"><?= e($website['title']) ?></span><?php if ((int) $website['is_featured']): ?><span class="featured" aria-label="精选">★</span><?php endif; ?></span>
                    <span class="site-desc"><?= e($website['description']) ?></span>
                    <?php if ($cardUrlLabel !== ''): ?><span class="site-url"><?= e($cardUrlLabel) ?></span><?php endif; ?>
                  </span>
                </a>
                <span class="site-meta" title="主域名状态：<?= e(status_label($website['primary_status'])) ?>"><i class="health <?= e($website['primary_status']) ?>"></i> <?= number_format((int) $website['clicks']) ?></span>
              </article>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <?php if (!$hasResult): ?><div class="empty">未找到匹配网站。请尝试更换关键词，或在后台添加一个新站点。</div><?php endif; ?>
    </div>
  </main>
</div>
<script>
(function () {
  var form = document.querySelector('[data-search-form]');
  if (!form) { return; }
  var button = form.querySelector('[data-search-submit]');
  var label = form.querySelector('[data-search-label]');
  var searchInput = form.querySelector('[data-search-input]');
  var searchError = form.querySelector('[data-search-error]');
  function clearSearchError() {
    form.classList.remove('has-error');
    if (searchInput) { searchInput.removeAttribute('aria-invalid'); }
    if (searchError) { searchError.textContent = ''; }
  }
  function showSearchError(message) {
    form.classList.add('has-error');
    if (searchInput) {
      searchInput.setAttribute('aria-invalid', 'true');
      searchInput.focus();
    }
    if (searchError) { searchError.textContent = message; }
  }
  function resetSearchState() {
    form.removeAttribute('data-searching');
    if (button) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.classList.remove('is-loading');
    }
    if (label) { label.textContent = '搜索'; }
  }
  form.addEventListener('submit', function (event) {
    if (!searchInput || searchInput.value.trim() === '') {
      event.preventDefault();
      resetSearchState();
      showSearchError('请输入搜索关键词。');
      return;
    }
    if (form.getAttribute('data-searching') === 'true') {
      event.preventDefault();
      return;
    }
    form.setAttribute('data-searching', 'true');
    if (button) {
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.classList.add('is-loading');
    }
    if (label) { label.textContent = '搜索中…'; }
  });
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      if (searchInput.value.trim() !== '') { clearSearchError(); }
    });
  }
  window.addEventListener('pageshow', resetSearchState);

  var sortControl = document.querySelector('[data-sort-control]');
  var sortTabs = document.querySelector('[data-sort-tabs]');
  var sortSwitching = false;
  function resetSortState() {
    sortSwitching = false;
    if (sortControl) { sortControl.classList.remove('is-switching'); }
    if (sortTabs) { sortTabs.removeAttribute('aria-busy'); }
    Array.prototype.forEach.call(document.querySelectorAll('[data-sort-tab]'), function (tab) {
      tab.classList.remove('is-pending');
      tab.removeAttribute('aria-disabled');
    });
  }
  Array.prototype.forEach.call(document.querySelectorAll('[data-sort-tab]'), function (tab) {
    tab.addEventListener('click', function (event) {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || (typeof event.button !== 'undefined' && event.button !== 0)) { return; }
      if (tab.classList.contains('active')) {
        event.preventDefault();
        return;
      }
      if (sortSwitching) {
        event.preventDefault();
        return;
      }
      sortSwitching = true;
      if (sortControl) { sortControl.classList.add('is-switching'); }
      if (sortTabs) { sortTabs.setAttribute('aria-busy', 'true'); }
      tab.classList.add('is-pending');
      tab.setAttribute('aria-disabled', 'true');
    });
  });
  window.addEventListener('pageshow', resetSortState);

  document.addEventListener('click', function (event) {
    var link = event.target;
    while (link && link.nodeType === 1 && !link.hasAttribute('data-direct-track-url')) { link = link.parentNode; }
    if (!link || link.nodeType !== 1 || !link.hasAttribute('data-direct-track-url')) { return; }
    var trackUrl = link.getAttribute('data-direct-track-url');
    if (!trackUrl) { return; }
    if (navigator.sendBeacon && navigator.sendBeacon(trackUrl, new Blob([''], { type: 'text/plain;charset=UTF-8' }))) { return; }
    var trackingImage = new Image();
    trackingImage.src = trackUrl + '&_=' + Date.now();
  }, true);
}());
</script>
<script>window.siteNavigatorDefaultTheme = <?= json_encode(setting('default_theme', 'daylight')) ?>;</script><script src="/assets/js/app.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
<style>
  .website-grid{grid-template-columns:repeat(<?= $homeGridColumns ?>,minmax(0,1fr))}
  @media(max-width:800px){.website-grid{grid-template-columns:repeat(<?= $homeMobileGridColumns ?>,minmax(0,1fr))}}
  body.theme-night{--bg:#0b1120;--surface:#111827;--surface-muted:#172033;--ink:#f3f6fb;--muted:#a9b5c7;--line:#29364b;--brand:#78a7ff;--brand-deep:#9abaff;--sidebar:#060a13}
  body.theme-forest{--bg:#eef7f1;--surface:#fff;--surface-muted:#f4fbf6;--ink:#173329;--muted:#5d766a;--line:#d5e8dc;--brand:#18845a;--brand-deep:#0d6543;--sidebar:#12382b}
  body.theme-twilight{--bg:#f5f1fb;--surface:#fff;--surface-muted:#faf7ff;--ink:#2b2142;--muted:#76698c;--line:#e4daf1;--brand:#7955c6;--brand-deep:#5a389f;--sidebar:#24183d}
  body.theme-night .brand-mark,body.theme-forest .brand-mark,body.theme-twilight .brand-mark{background:linear-gradient(135deg,var(--brand),var(--brand-deep));box-shadow:0 8px 18px rgba(0,0,0,.25)}
  body.theme-night .search input,body.theme-forest .search input,body.theme-twilight .search input{color:var(--ink);background:var(--surface);border-color:var(--line)}
  body.theme-night .search-submit,body.theme-forest .search-submit,body.theme-twilight .search-submit{background:linear-gradient(135deg,var(--brand),var(--brand-deep));border-color:var(--brand);box-shadow:0 4px 10px rgba(0,0,0,.2)}
  body.theme-night .website-card,body.theme-night .stat,body.theme-night .empty,body.theme-night .list-panel,body.theme-night .admin-panel,body.theme-forest .website-card,body.theme-forest .stat,body.theme-forest .empty,body.theme-forest .list-panel,body.theme-forest .admin-panel,body.theme-twilight .website-card,body.theme-twilight .stat,body.theme-twilight .empty,body.theme-twilight .list-panel,body.theme-twilight .admin-panel{color:var(--ink);background:var(--surface);border-color:var(--line)}
  body.theme-night .sort-tabs,body.theme-forest .sort-tabs,body.theme-twilight .sort-tabs{background:var(--surface-muted)}
  body.theme-night .sort-tabs a.active,body.theme-forest .sort-tabs a.active,body.theme-twilight .sort-tabs a.active{color:var(--brand-deep);background:var(--surface)}
  body.theme-night .topbar{background:rgba(11,17,32,.88);border-bottom-color:rgba(41,54,75,.9)}
  body.theme-night .sidebar{box-shadow:10px 0 28px rgba(0,0,0,.18)}
  body.theme-night .theme-picker{background:#172033;border-color:#354765;box-shadow:0 4px 14px rgba(0,0,0,.28)}
  body.theme-night .theme-picker select{background:#111827;border-color:#354765}
  .topbar{gap:28px}
  .page-intro{flex:0 0 205px;min-width:205px}
  .page-intro p{white-space:nowrap}
  .theme-picker{display:inline-flex;align-items:center;gap:7px;height:38px;padding:4px 6px 4px 11px;color:var(--muted);background:color-mix(in srgb,var(--surface) 92%,var(--brand) 8%);border:1px solid var(--line);border-radius:12px;box-shadow:0 3px 10px color-mix(in srgb,var(--brand) 10%,transparent);font-size:12px;font-weight:700;transition:border-color .2s,box-shadow .2s}
  .theme-picker:hover,.theme-picker:focus-within{border-color:var(--brand);box-shadow:0 4px 14px color-mix(in srgb,var(--brand) 18%,transparent)}
  .theme-picker select{height:28px;min-width:78px;padding:0 23px 0 9px;color:var(--ink);background:var(--surface);border:1px solid var(--line);border-radius:8px;outline:0;font:inherit;font-size:12px;cursor:pointer}
  .theme-picker select:focus{border-color:var(--brand);box-shadow:0 0 0 3px color-mix(in srgb,var(--brand) 16%,transparent)}
  @media(max-width:600px){.sidebar .brand{display:flex;align-items:center;justify-content:space-between;padding-right:0}.sidebar .brand .header-actions{display:flex;margin-left:auto}.sidebar .brand .theme-picker{display:inline-flex;width:auto;height:25px;gap:1px;margin-left:auto;padding:1px 0 1px 2px;border-radius:6px;font-size:9px}.sidebar .brand .theme-picker span{display:inline}.sidebar .brand .theme-picker select{width:45px;max-width:45px;height:21px;min-height:21px;padding:0 5px 0 0;border:0;border-radius:4px;background:transparent;color:var(--ink);font-size:9px;appearance:auto;-webkit-appearance:auto}}
</style>
<script>
  (function(){
    var actions=document.querySelector('.topbar .header-actions'),brand=document.querySelector('.sidebar .brand');
    function moveTheme(){if(!actions||!brand)return;if(window.matchMedia('(max-width:600px)').matches){if(actions.parentNode!==brand)brand.appendChild(actions);}else{var topbar=document.querySelector('.topbar');if(topbar&&actions.parentNode!==topbar)topbar.appendChild(actions);}}
    function syncTheme(){var s=document.querySelector('[data-theme-switch]'),v=s&&s.value||'daylight';document.body.classList.remove('theme-night','theme-forest','theme-twilight');if(v!=='daylight')document.body.classList.add('theme-'+v);try{localStorage.setItem('site-navigator-theme',v);}catch(e){}}
    if(actions&&brand){brand.addEventListener('click',function(e){if(window.matchMedia('(max-width:600px)').matches&&e.target.closest('.theme-picker')){e.preventDefault();e.stopPropagation();}});moveTheme();window.addEventListener('resize',moveTheme);var s=document.querySelector('[data-theme-switch]');if(s){s.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();});s.addEventListener('pointerdown',function(e){e.stopPropagation();});s.addEventListener('change',function(e){e.stopPropagation();syncTheme();});}}
  }());
</script>
</body>
</html>

<style>
body.theme-china-red{--bg:#fff5f5;--surface:#fff;--surface-muted:#fff0f0;--ink:#3b1115;--muted:#8f5b60;--line:#f0c7ca;--brand:#c92d3a;--brand-deep:#921d29;--sidebar:#4b1018}
body.theme-glazed-yellow{--bg:#fffaf0;--surface:#fff;--surface-muted:#fff5d9;--ink:#3d2b08;--muted:#8a7340;--line:#ead59a;--brand:#d99a08;--brand-deep:#9b6800;--sidebar:#4c3504}
body.theme-cloud-gray{--bg:#f1f3f5;--surface:#fff;--surface-muted:#e7eaee;--ink:#20262d;--muted:#69737e;--line:#cbd1d8;--brand:#66717d;--brand-deep:#414b55;--sidebar:#2f3740}
body.theme-china-red .website-card,body.theme-china-red .stat,body.theme-china-red .empty,body.theme-china-red .list-panel,body.theme-china-red .admin-panel,body.theme-glazed-yellow .website-card,body.theme-glazed-yellow .stat,body.theme-glazed-yellow .empty,body.theme-glazed-yellow .list-panel,body.theme-glazed-yellow .admin-panel,body.theme-cloud-gray .website-card,body.theme-cloud-gray .stat,body.theme-cloud-gray .empty,body.theme-cloud-gray .list-panel,body.theme-cloud-gray .admin-panel{color:var(--ink);background:var(--surface);border-color:var(--line)}
body.theme-china-red .search input,body.theme-glazed-yellow .search input{color:var(--ink);background:var(--surface);border-color:var(--line)}
body.theme-china-red .search-submit,body.theme-glazed-yellow .search-submit{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand-deep));border-color:var(--brand)}
body.theme-china-red .sort-tabs,body.theme-glazed-yellow .sort-tabs{background:var(--surface-muted)}
body.theme-china-red .sort-tabs a.active,body.theme-glazed-yellow .sort-tabs a.active{color:var(--brand-deep);background:var(--surface)}
body.theme-china-red .brand-mark,body.theme-glazed-yellow .brand-mark{background:linear-gradient(135deg,var(--brand),var(--brand-deep));box-shadow:0 8px 18px rgba(0,0,0,.2)}
body.theme-china-red .theme-picker,body.theme-glazed-yellow .theme-picker{border-color:var(--line);background:var(--surface)}
body.theme-china-red .theme-picker select,body.theme-glazed-yellow .theme-picker select{color:var(--ink);background:var(--surface);border-color:var(--line)}
</style>

<style>body.theme-cloud-gray .search input,body.theme-cloud-gray .theme-picker select{color:#20262d;background:#fff;border-color:#cbd1d8}body.theme-cloud-gray .search-submit{color:#fff;background:linear-gradient(135deg,#66717d,#414b55);border-color:#66717d}body.theme-cloud-gray .sort-tabs{background:#e7eaee}body.theme-cloud-gray .sort-tabs a.active{color:#414b55;background:#fff}body.theme-cloud-gray .brand-mark{background:linear-gradient(135deg,#88939e,#414b55);box-shadow:0 8px 18px rgba(31,40,49,.2)}body.theme-cloud-gray .theme-picker{border-color:#cbd1d8;background:#fff}</style>
