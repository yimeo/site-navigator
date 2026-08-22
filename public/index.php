<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'priority';
$allowedSorts = array('priority', 'popular', 'latest');
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'priority';
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
    return '/go.php?id=' . (int) $website['id'];
}
function public_card_href($website, $redirectMode)
{
    if ($redirectMode === 'domain_direct') {
        return $website['primary_url'];
    }
    return '/go.php?id=' . (int) $website['id'];
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
          <a href="#<?= e($group['category']['slug']) ?>"><?= e($group['category']['name']) ?></a>
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
      <div class="header-actions"><a class="button-secondary" href="/admin/index.php">后台管理</a></div>
    </header>

    <div class="content">
      <section class="hero">
        <div><h2><?= $query !== '' ? '“' . e($query) . '”的搜索结果' : e($homeHeroTitle) ?></h2><p><?= e($homeHeroDescription) ?></p></div>
        <div class="sort-control" data-sort-control>
          <nav class="sort-tabs" aria-label="排序方式" data-sort-tabs>
            <a class="<?= $sort === 'priority' ? 'active' : '' ?>" href="<?= e(sort_url('priority', $query)) ?>" data-sort-tab>优先推荐</a>
            <a class="<?= $sort === 'popular' ? 'active' : '' ?>" href="<?= e(sort_url('popular', $query)) ?>" data-sort-tab>人气最高</a>
            <a class="<?= $sort === 'latest' ? 'active' : '' ?>" href="<?= e(sort_url('latest', $query)) ?>" data-sort-tab>最新收录</a>
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
        <?php if (count($group['websites']) === 0) { continue; } $hasResult = true; ?>
        <section class="category-section" id="<?= e($group['category']['slug']) ?>">
          <div class="section-head"><h3 class="section-title"><?= e($group['category']['name']) ?> <span class="section-count"><?= count($group['websites']) ?> 个网站</span></h3></div>
          <div class="website-grid">
            <?php foreach ($group['websites'] as $website): ?>
              <?php $cardHref = public_card_href($website, $redirectMode); $cardLabelMode = $redirectLinkDisplay === 'blank' ? 'blank' : ($redirectMode === 'domain_direct' ? 'domain' : $redirectLinkDisplay); $cardUrlLabel = public_redirect_label($website, $cardLabelMode); ?>
              <article class="site-card">
                <a class="site-link" href="<?= e($cardHref) ?>" rel="noopener noreferrer" target="_blank" title="打开 <?= e($website['title']) ?>"<?= $redirectMode === 'domain_direct' ? ' data-direct-track-url="/go.php?id=' . (int) $website['id'] . '&amp;track=1"' : '' ?>>
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
</body>
</html>
