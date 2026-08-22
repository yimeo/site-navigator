<?php

class SiteInspector
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function inspect($url)
    {
        $url = UrlSafety::normalize($url);
        $response = $this->request($url, 'text/html,application/xhtml+xml');
        if (!$response['ok']) {
            throw new RuntimeException('无法读取目标网站：' . $response['error']);
        }
        if (stripos($response['content_type'], 'html') === false && stripos($response['content_type'], 'xhtml') === false) {
            throw new RuntimeException('目标地址未返回 HTML 页面，无法提取标题与介绍。');
        }

        $html = substr($response['body'], 0, $this->config['http']['max_html_bytes']);
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);

        $title = $this->metaContent($xpath, 'property', 'og:title');
        if ($title === '') {
            $title = $this->nodeText($xpath, '//title');
        }
        $parts = parse_url($response['final_url']);
        $hostTitle = isset($parts['host']) ? $parts['host'] : '未命名网站';
        if ($this->isGenericTitle($title, $hostTitle)) {
            $brandTitle = $this->metaContent($xpath, 'name', 'application-name');
            if ($brandTitle === '') {
                $brandTitle = $this->metaContent($xpath, 'name', 'apple-mobile-web-app-title');
            }
            if ($brandTitle === '') {
                $brandTitle = $this->nodeText($xpath, '//img[@alt][normalize-space(@alt) != ""][1]/@alt');
            }
            if ($brandTitle !== '') {
                $title = $brandTitle;
            }
        }
        if ($title === '') {
            $title = $hostTitle;
        }

        $description = $this->metaContent($xpath, 'property', 'og:description');
        if ($description === '') {
            $description = $this->metaContent($xpath, 'name', 'description');
        }
        if ($description === '') {
            $description = $this->bodyExcerpt($document);
        }
        if ($description === '') {
            $description = '暂未读取到网站介绍。';
        }

        return array(
            'requested_url' => $url,
            'primary_url' => $response['final_url'],
            'title' => $this->cleanText($title, 120),
            'description' => $this->cleanText($description, 240),
            'icon_url' => $this->findIconUrl($xpath, $response['final_url']),
            'status_code' => $response['status'],
        );
    }

    public function syncIcon($iconUrl, $websiteId)
    {
        if (!$iconUrl) {
            return null;
        }
        $response = $this->request($iconUrl, 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8');
        if (!$response['ok']) {
            return null;
        }
        $contentType = strtolower(trim(explode(';', $response['content_type'])[0]));
        $allowed = array(
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/icon' => 'ico',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        );
        if (!isset($allowed[$contentType]) || strlen($response['body']) === 0 || strlen($response['body']) > $this->config['http']['max_icon_bytes']) {
            return null;
        }
        if (!is_dir($this->config['icon_dir'])) {
            mkdir($this->config['icon_dir'], 0755, true);
        }
        $filename = 'site-' . (int) $websiteId . '-' . substr(sha1($iconUrl . microtime(true)), 0, 12) . '.' . $allowed[$contentType];
        $absolutePath = rtrim($this->config['icon_dir'], '/') . '/' . $filename;
        if (file_put_contents($absolutePath, $response['body'], LOCK_EX) === false) {
            return null;
        }
        return rtrim($this->config['icon_web_path'], '/') . '/' . $filename;
    }

    public function refreshWebsiteIcon(array $website)
    {
        try {
            $metadata = $this->inspect($website['primary_url']);
            $icon = $this->syncIcon($metadata['icon_url'], $website['id']);
            if ($icon) {
                $statement = db()->prepare('UPDATE websites SET icon_path = :icon_path, updated_at = :updated_at WHERE id = :id');
                $statement->execute(array(':icon_path' => $icon, ':updated_at' => now_utc(), ':id' => $website['id']));
            }
            return $icon;
        } catch (Exception $exception) {
            return null;
        }
    }

    private function request($url, $accept)
    {
        $current = UrlSafety::normalize($url);
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $parts = parse_url($current);
            $host = $parts['host'];
            $port = isset($parts['port']) ? (int) $parts['port'] : (strtolower($parts['scheme']) === 'https' ? 443 : 80);
            $ips = UrlSafety::resolvePublicIps($host);
            if (count($ips) === 0) {
                return $this->failure('域名未解析到安全的公共 IP 地址。');
            }

            $handle = curl_init($current);
            curl_setopt_array($handle, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->config['http']['connect_timeout'],
                CURLOPT_TIMEOUT => $this->config['http']['timeout'],
                CURLOPT_USERAGENT => $this->config['http']['user_agent'],
                CURLOPT_HTTPHEADER => array('Accept: ' . $accept),
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => array($host . ':' . $port . ':' . $ips[0]),
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ));
            $startedAt = microtime(true);
            $raw = curl_exec($handle);
            $error = curl_error($handle);
            $info = curl_getinfo($handle);
            curl_close($handle);
            if ($raw === false) {
                return $this->failure($error !== '' ? $error : '请求失败。');
            }

            $headerSize = isset($info['header_size']) ? (int) $info['header_size'] : 0;
            $headerText = substr($raw, 0, $headerSize);
            $body = substr($raw, $headerSize);
            $headers = $this->parseHeaders($headerText);
            $status = isset($info['http_code']) ? (int) $info['http_code'] : 0;
            $contentType = isset($info['content_type']) ? $info['content_type'] : (isset($headers['content-type']) ? $headers['content-type'] : '');
            $latency = (int) round((microtime(true) - $startedAt) * 1000);

            if (in_array($status, array(301, 302, 303, 307, 308), true) && isset($headers['location'])) {
                try {
                    $current = UrlSafety::resolveUrl($current, $headers['location']);
                    if (!$current) {
                        return $this->failure('重定向地址不受支持。');
                    }
                    continue;
                } catch (Exception $exception) {
                    return $this->failure('重定向地址不安全。');
                }
            }

            return array(
                'ok' => $status >= 200 && $status < 400,
                'status' => $status,
                'body' => $body,
                'content_type' => $contentType,
                'headers' => $headers,
                'final_url' => $current,
                'latency_ms' => $latency,
                'error' => $status >= 200 && $status < 400 ? '' : 'HTTP 状态码 ' . $status,
            );
        }
        return $this->failure('重定向次数过多。');
    }

    private function parseHeaders($headerText)
    {
        $headers = array();
        $blocks = preg_split("/\r?\n\r?\n/", trim($headerText));
        $lines = preg_split("/\r?\n/", (string) end($blocks));
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return $headers;
    }

    private function metaContent(DOMXPath $xpath, $attribute, $name)
    {
        $nodes = $xpath->query('//meta[translate(@' . $attribute . ', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . strtolower($name) . '"]/@content');
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }
        return '';
    }

    private function nodeText(DOMXPath $xpath, $query)
    {
        $nodes = $xpath->query($query);
        return $nodes && $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
    }

    private function bodyExcerpt(DOMDocument $document)
    {
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//script|//style|//noscript|//svg') as $node) {
            $node->parentNode->removeChild($node);
        }
        $body = $xpath->query('//body');
        return $body && $body->length > 0 ? $body->item(0)->textContent : '';
    }

    private function findIconUrl(DOMXPath $xpath, $baseUrl)
    {
        $links = $xpath->query('//link[@href]');
        if ($links) {
            foreach ($links as $link) {
                $rel = strtolower((string) $link->getAttribute('rel'));
                if (strpos($rel, 'icon') !== false) {
                    try {
                        $url = UrlSafety::resolveUrl($baseUrl, $link->getAttribute('href'));
                        if ($url) {
                            return $url;
                        }
                    } catch (Exception $exception) {
                        continue;
                    }
                }
            }
        }
        $parts = parse_url($baseUrl);
        return UrlSafety::normalize($parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/favicon.ico');
    }

    private function cleanText($text, $length)
    {
        $text = preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')));
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $length, '…', 'UTF-8');
        }
        return substr($text, 0, $length);
    }

    private function isGenericTitle($title, $host)
    {
        $normalized = strtolower(trim($title));
        $host = strtolower(trim($host));
        $hostWithoutWww = preg_replace('/^www\./', '', $host);
        return $normalized === '' || in_array($normalized, array('首页', '主页', 'home', 'index'), true) || $normalized === $host || $normalized === $hostWithoutWww || $normalized === 'www.' . $hostWithoutWww;
    }

    private function failure($error)
    {
        return array('ok' => false, 'status' => 0, 'body' => '', 'content_type' => '', 'headers' => array(), 'final_url' => '', 'latency_ms' => 0, 'error' => $error);
    }
}
