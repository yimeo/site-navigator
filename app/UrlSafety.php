<?php

class UrlSafety
{
    public static function normalize($url)
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('目标 URL 不能为空。');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('请输入有效的 HTTP 或 HTTPS 地址。');
        }

        $parts = parse_url($url);
        if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            throw new InvalidArgumentException('仅支持 HTTP 和 HTTPS 地址。');
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('URL 缺少可访问主机名。');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL 不允许包含用户名或密码。');
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], array(80, 443), true)) {
            throw new InvalidArgumentException('仅允许访问标准 HTTP/HTTPS 端口。');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        self::assertPublicHost($host);
        $parts['scheme'] = strtolower($parts['scheme']);
        $parts['host'] = $host;
        $parts['path'] = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

        $normalized = $parts['scheme'] . '://';
        if (strpos($host, ':') !== false) {
            $normalized .= '[' . $host . ']';
        } else {
            $normalized .= $host;
        }
        if (isset($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $parts['path'];
        if (isset($parts['query'])) {
            $normalized .= '?' . $parts['query'];
        }
        return $normalized;
    }

    public static function assertPublicHost($host)
    {
        $host = strtolower(trim($host));
        if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local') {
            throw new InvalidArgumentException('不允许访问本机或局域网地址。');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) {
                throw new InvalidArgumentException('不允许访问私有、保留或本机 IP 地址。');
            }
            return;
        }

        if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/i', $host)) {
            throw new InvalidArgumentException('主机名格式不正确。');
        }

        $ips = self::resolvePublicIps($host);
        if (count($ips) === 0) {
            throw new InvalidArgumentException('无法解析此域名的公共 IP 地址。');
        }
    }

    public static function resolvePublicIps($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host) ? array($host) : array();
        }

        $ips = array();
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            foreach ($ipv4 as $ip) {
                if (self::isPublicIp($ip)) {
                    $ips[] = $ip;
                }
            }
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && self::isPublicIp($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }

    public static function isPublicIp($ip)
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    public static function resolveUrl($baseUrl, $candidate)
    {
        $candidate = html_entity_decode(trim($candidate), ENT_QUOTES, 'UTF-8');
        if ($candidate === '' || stripos($candidate, 'data:') === 0 || stripos($candidate, 'javascript:') === 0) {
            return null;
        }
        if (preg_match('#^https?://#i', $candidate)) {
            return self::normalize($candidate);
        }

        $base = parse_url($baseUrl);
        if (!$base || !isset($base['scheme'], $base['host'])) {
            return null;
        }
        if (substr($candidate, 0, 2) === '//') {
            return self::normalize($base['scheme'] . ':' . $candidate);
        }
        if (substr($candidate, 0, 1) === '/') {
            return self::normalize($base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . $candidate);
        }

        $path = isset($base['path']) ? $base['path'] : '/';
        $directory = substr($path, 0, strrpos($path, '/') + 1);
        return self::normalize($base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . $directory . $candidate);
    }
}
