<?php

class Database
{
    private static $connection;

    public static function connection(array $config)
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $directory = dirname($config['db_path']);
        if (!is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        self::$connection = new PDO('sqlite:' . $config['db_path']);
        self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$connection->exec('PRAGMA foreign_keys = ON');
        self::$connection->exec('PRAGMA busy_timeout = 5000');
        self::migrate(self::$connection);
        self::seed(self::$connection, $config);
        return self::$connection;
    }

    private static function migrate(PDO $pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            sort_order INTEGER NOT NULL DEFAULT 100,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS websites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            primary_url TEXT NOT NULL,
            backup_url TEXT,
            icon_path TEXT,
            sort_order INTEGER NOT NULL DEFAULT 100,
            clicks INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_featured INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS website_health (
            website_id INTEGER PRIMARY KEY,
            primary_status TEXT NOT NULL DEFAULT 'unknown',
            primary_code INTEGER,
            primary_latency_ms INTEGER,
            primary_error TEXT,
            primary_checked_at TEXT,
            backup_status TEXT NOT NULL DEFAULT 'unknown',
            backup_code INTEGER,
            backup_latency_ms INTEGER,
            backup_error TEXT,
            backup_checked_at TEXT,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(website_id) REFERENCES websites(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS redirect_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            website_id INTEGER NOT NULL,
            used_url TEXT NOT NULL,
            url_role TEXT NOT NULL,
            referer TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(website_id) REFERENCES websites(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS website_device_visits (
            website_id INTEGER NOT NULL,
            device_hash TEXT NOT NULL,
            last_counted_at TEXT NOT NULL,
            PRIMARY KEY (website_id, device_hash),
            FOREIGN KEY(website_id) REFERENCES websites(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_categories_order ON categories(is_active, sort_order)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_websites_listing ON websites(category_id, is_active, sort_order)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_websites_clicks ON websites(is_active, clicks DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_redirect_logs_created ON redirect_logs(created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_visits_counted ON website_device_visits(last_counted_at)');
    }

    private static function seed(PDO $pdo, array $config)
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $statement->execute(array(':username' => $config['default_admin']['username']));
        if ((int) $statement->fetchColumn() === 0) {
            $statement = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (:username, :password_hash)');
            $statement->execute(array(
                ':username' => $config['default_admin']['username'],
                ':password_hash' => password_hash($config['default_admin']['password'], PASSWORD_DEFAULT),
            ));
        }

        $count = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        if ($count === 0) {
            $categories = array(
                array('开发工具', 'developer-tools', 10),
                array('人工智能', 'artificial-intelligence', 20),
                array('设计资源', 'design-resources', 30),
                array('学习社区', 'learning-community', 40),
            );
            $statement = $pdo->prepare('INSERT INTO categories (name, slug, sort_order) VALUES (:name, :slug, :sort_order)');
            foreach ($categories as $category) {
                $statement->execute(array(':name' => $category[0], ':slug' => $category[1], ':sort_order' => $category[2]));
            }
        }

        $settings = array(
            'site_name' => $config['app_name'],
            'brand_mark' => 'N',
            'site_subtitle' => '发现值得访问的网站',
            'seo_title' => $config['app_name'] . ' - 发现值得访问的网站',
            'seo_description' => '发现值得访问的网站',
            'seo_keywords' => $config['app_name'] . ',网站导航,网址导航,优质网站',
            'home_directory_label' => '站点目录',
            'home_category_hint' => '按分类高效发现资源',
            'home_hero_title' => '探索经过整理的网站',
            'home_hero_description' => '发现值得访问的网站。所有跳转优先使用可用的主地址，主地址异常时自动尝试备用地址。',
            'home_sidebar_health' => '主备域名自动检测',
            'home_sidebar_icon' => '图标同步保存至本地',
            'health_check_interval' => '600',
            'redirect_mode' => 'direct',
            'redirect_link_display' => 'id',
            'redirect_interstitial_delay' => '2.6',
            'redirect_interstitial_template' => "正在为你打开 {{site_title}}\n已为你确认可用访问地址。若浏览器没有自动跳转，请点击下方按钮继续。",
            'home_sort_default' => 'priority',
            'home_sort_priority_visible' => '1',
            'home_sort_popular_visible' => '1',
            'home_sort_latest_visible' => '1',
        );
        $statement = $pdo->prepare('INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (:key, :value)');
        foreach ($settings as $key => $value) {
            $statement->execute(array(':key' => $key, ':value' => $value));
        }
    }
}
