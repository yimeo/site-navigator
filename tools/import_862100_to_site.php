<?php

/**
 * One-time importer for publicly listed 862100.com candidates.
 * Usage: php import_862100_to_site.php /path/to/862100-candidates-clean.json
 */

$projectRoot = getenv('SITE_NAVIGATOR_ROOT');
if (!$projectRoot) {
    $projectRoot = dirname(__DIR__);
}
require_once rtrim($projectRoot, '/') . '/app/bootstrap.php';

if (!isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php import_862100_to_site.php /path/to/candidates.json\n");
    exit(2);
}

$entries = json_decode(file_get_contents($argv[1]), true);
if (!is_array($entries)) {
    fwrite(STDERR, "The candidates JSON could not be parsed.\n");
    exit(2);
}

$categoryOrder = array('动漫网站', '高清影院', '电影资讯', '电影搜索', '影片下载', '影视资源');
$categorySlugs = array(
    '动漫网站' => 'anime-sites',
    '高清影院' => 'hd-cinema',
    '电影资讯' => 'movie-news',
    '电影搜索' => 'movie-search',
    '影片下载' => 'movie-downloads',
    '影视资源' => 'video-resources',
);

function import_host_key($url)
{
    $host = parse_url((string) $url, PHP_URL_HOST);
    $host = strtolower(rtrim((string) $host, '.'));
    return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
}

$database = db();
$existingHosts = array();
foreach ($database->query('SELECT primary_url, backup_url FROM websites') as $website) {
    foreach (array($website['primary_url'], $website['backup_url']) as $url) {
        $host = import_host_key($url);
        if ($host !== '') {
            $existingHosts[$host] = true;
        }
    }
}

$categoryIds = array();
$findCategory = $database->prepare('SELECT id FROM categories WHERE name = :name LIMIT 1');
$addCategory = $database->prepare('INSERT INTO categories (name, slug, sort_order, is_active, created_at, updated_at) VALUES (:name, :slug, :sort_order, 1, :created_at, :updated_at)');
foreach ($categoryOrder as $position => $name) {
    $findCategory->execute(array(':name' => $name));
    $category = $findCategory->fetch();
    if (!$category) {
        $now = now_utc();
        $addCategory->execute(array(
            ':name' => $name,
            ':slug' => $categorySlugs[$name],
            ':sort_order' => 50 + ($position * 10),
            ':created_at' => $now,
            ':updated_at' => $now,
        ));
        $categoryIds[$name] = (int) $database->lastInsertId();
    } else {
        $categoryIds[$name] = (int) $category['id'];
    }
}

$insertWebsite = $database->prepare('INSERT INTO websites (category_id, title, description, primary_url, backup_url, icon_path, sort_order, is_active, is_featured, created_at, updated_at) VALUES (:category_id, :title, :description, :primary_url, NULL, :icon_path, :sort_order, 1, 0, :created_at, :updated_at)');
$summary = array(
    'source_count' => count($entries),
    'imported_count' => 0,
    'skipped_count' => 0,
    'imported_by_category' => array(),
    'skip_reasons' => array(),
    'skipped_examples' => array(),
);

$database->beginTransaction();
try {
    foreach ($entries as $entry) {
        $categoryName = isset($entry['category']) ? trim($entry['category']) : '';
        $title = isset($entry['title']) ? trim($entry['title']) : '';
        $description = isset($entry['description']) ? trim($entry['description']) : '';
        $inputUrl = isset($entry['primary_url']) ? trim($entry['primary_url']) : '';
        $reason = '';

        if (!isset($categoryIds[$categoryName])) {
            $reason = 'unknown_category';
        } elseif ($title === '') {
            $reason = 'empty_title';
        } else {
            try {
                $primaryUrl = UrlSafety::normalize($inputUrl);
                $host = import_host_key($primaryUrl);
                if ($host === '' || isset($existingHosts[$host])) {
                    $reason = 'duplicate_domain';
                }
            } catch (Exception $exception) {
                $reason = 'unsafe_or_unresolvable_url';
            }
        }

        if ($reason !== '') {
            $summary['skipped_count']++;
            $summary['skip_reasons'][$reason] = isset($summary['skip_reasons'][$reason]) ? $summary['skip_reasons'][$reason] + 1 : 1;
            if (count($summary['skipped_examples']) < 12) {
                $summary['skipped_examples'][] = array('title' => $title, 'url' => $inputUrl, 'reason' => $reason);
            }
            continue;
        }

        $now = now_utc();
        $insertWebsite->execute(array(
            ':category_id' => $categoryIds[$categoryName],
            ':title' => mb_strimwidth($title, 0, 120, '', 'UTF-8'),
            ':description' => mb_strimwidth($description, 0, 500, '', 'UTF-8'),
            ':primary_url' => $primaryUrl,
            ':icon_path' => default_site_icon_path(),
            ':sort_order' => 100,
            ':created_at' => $now,
            ':updated_at' => $now,
        ));
        $existingHosts[$host] = true;
        $summary['imported_count']++;
        $summary['imported_by_category'][$categoryName] = isset($summary['imported_by_category'][$categoryName]) ? $summary['imported_by_category'][$categoryName] + 1 : 1;
    }
    $database->commit();
} catch (Exception $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    throw $exception;
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
