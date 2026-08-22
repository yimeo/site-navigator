# 站界导航

**站界导航**是一个基于 **NGINX + PHP 7.0 + SQLite** 的轻量网站导航系统。它提供公开导航页和受保护的管理后台，适合按分类维护网站资源，并在目标站主域名不可用时自动尝试备用域名。

> 本仓库只包含可公开的应用源码与示例配置；不会提交生产域名、服务器资料、管理员密码、SQLite 数据库、同步图标或运行排查记录。

## 功能概览

| 功能 | 说明 |
| --- | --- |
| 分类与排序 | 分类可设置优先级；前台支持精选推荐、人气最高、最新收录。 |
| 网站管理 | 单个添加、批量添加、CSV 导入导出、分类筛选、关键词自动搜索和分页。 |
| 自动读取 | 输入 HTTP/HTTPS 地址后读取标题、介绍与图标；受限站点可直接手动补充资料。 |
| 主备域名 | `go.php` 优先跳转主地址，主地址不可用时尝试备用地址。 |
| 访问统计 | 同一设备对同一站点 24 小时内仅计一次有效访问。 |
| 本地图标 | 受控下载网站图标至本地；无图标时使用默认影视播放图标。 |
| 后台安全 | Session 登录、密码哈希、CSRF、登录数学验证码、PDO 预处理和 URL 安全校验。 |

## 公开仓库安全边界

`.gitignore` 会排除以下生产或运行时文件：

| 排除内容 | 原因 |
| --- | --- |
| `config/app.php` | 其中保存本机路径和首次初始化管理员配置。 |
| `storage/navigation.sqlite` | 包含管理员账户、站点资料与访问数据。 |
| `public/assets/icons/` | 运行过程中下载的第三方图标缓存。 |
| `research/`、排查记录与任务清单 | 与特定部署、导入或运维过程有关。 |

## 环境要求

生产环境需要 NGINX、PHP 7.0-FPM、SQLite、cURL、DOM/XML 和 mbstring 扩展。代码遵循 PHP 7.0 语法，但 PHP 7.0 已停止维护；建议将其部署在受控、隔离的环境中，并在条件允许时升级至受支持的 PHP 版本。

```bash
# Debian/Ubuntu 环境示意；PHP 7.0 的软件源请按系统版本准备。
sudo apt install nginx php7.0-fpm php7.0-sqlite3 php7.0-curl php7.0-xml php7.0-mbstring
```

## 快速开始

克隆仓库后，先创建**仅保存在服务器本机**的配置文件，再修改管理员初始密码。不要将此文件提交回 Git。

```bash
cp config/app.php.example config/app.php
chmod 640 config/app.php
# 编辑 config/app.php：替换 default_admin.username 与 default_admin.password
```

然后准备可写的 SQLite 和图标目录。推荐代码目录为只读，只有运行目录可由 PHP-FPM 服务账户写入。

```bash
sudo mkdir -p /var/www/site-navigator
sudo rsync -a --delete ./ /var/www/site-navigator/
sudo chown -R root:www-data /var/www/site-navigator
sudo chmod -R 750 /var/www/site-navigator
sudo chown -R www-data:www-data /var/www/site-navigator/storage /var/www/site-navigator/public/assets/icons
sudo chmod -R 775 /var/www/site-navigator/storage /var/www/site-navigator/public/assets/icons
```

复制 `deploy/nginx-site-navigator.conf` 到 NGINX 虚拟主机目录，并按实际环境调整 `server_name`、`root` 与 PHP-FPM Socket 路径。

```bash
sudo cp deploy/nginx-site-navigator.conf /etc/nginx/sites-available/site-navigator
sudo ln -s /etc/nginx/sites-available/site-navigator /etc/nginx/sites-enabled/site-navigator
sudo nginx -t
sudo systemctl reload nginx
```

首次访问会创建 `storage/navigation.sqlite`、管理员账户和示例分类。请使用您在 `config/app.php` 中自行设置的管理员信息登录 `/admin/index.php`；登录页会要求填写验证码。

## 计划任务

健康检测与图标同步可以通过计划任务运行。以下示例每 10 分钟更新主备地址状态、每天凌晨同步缺失图标；请将 PHP 路径和 Web 服务账户改为实际值。

```cron
*/10 * * * * www-data cd /var/www/site-navigator && /usr/bin/php cron/check-sites.php >> /var/log/site-navigator-health.log 2>&1
20 3 * * * www-data cd /var/www/site-navigator && /usr/bin/php cron/sync-icons.php >> /var/log/site-navigator-icons.log 2>&1
```

也可以手动操作单个网站：

```bash
php cron/check-sites.php --id=12
php cron/sync-icons.php --id=12
```

## 本地开发与测试

开发环境可使用 PHP 内置服务器验证页面；生产仍应使用 NGINX 与 PHP-FPM。

```bash
php -S 127.0.0.1:8080 -t public
php tests/smoke.php
find app public cron -name '*.php' -print0 | xargs -0 -n1 php -l
```

## 安全注意事项

系统仅允许服务端请求访问公网 HTTP/HTTPS 地址，并限制协议、端口、主机、DNS 解析、重定向、响应格式和响应大小。管理员仍应只收录可信站点，定期检查服务器日志，并把 `config/app.php`、数据库文件和备份目录保留在公开仓库之外。
