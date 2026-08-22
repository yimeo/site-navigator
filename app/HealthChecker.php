<?php

class HealthChecker
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function probe($url)
    {
        if (!$url) {
            return array('status' => 'unknown', 'code' => null, 'latency_ms' => null, 'error' => '未配置地址。', 'checked_at' => now_utc());
        }

        try {
            $url = UrlSafety::normalize($url);
            $parts = parse_url($url);
            $host = $parts['host'];
            $port = isset($parts['port']) ? (int) $parts['port'] : (strtolower($parts['scheme']) === 'https' ? 443 : 80);
            $ips = UrlSafety::resolvePublicIps($host);
            if (count($ips) === 0) {
                throw new RuntimeException('域名未解析到安全的公共 IP 地址。');
            }

            $handle = curl_init($url);
            curl_setopt_array($handle, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->config['http']['connect_timeout'],
                CURLOPT_TIMEOUT => $this->config['http']['timeout'],
                CURLOPT_USERAGENT => $this->config['http']['user_agent'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => array($host . ':' . $port . ':' . $ips[0]),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ));
            $startedAt = microtime(true);
            curl_exec($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            $latency = (int) round((microtime(true) - $startedAt) * 1000);
            $isUp = in_array($code, $this->config['health']['success_codes'], true);

            if (!$isUp && $code === 405) {
                return $this->probeWithGet($url, $host, $port, $ips[0]);
            }
            return array(
                'status' => $isUp ? 'up' : 'down',
                'code' => $code ?: null,
                'latency_ms' => $latency,
                'error' => $isUp ? '' : ($error !== '' ? $error : 'HTTP 状态码 ' . $code),
                'checked_at' => now_utc(),
            );
        } catch (Exception $exception) {
            return array('status' => 'down', 'code' => null, 'latency_ms' => null, 'error' => $exception->getMessage(), 'checked_at' => now_utc());
        }
    }

    public function refreshWebsite(array $website)
    {
        $primary = $this->probe($website['primary_url']);
        $backup = $website['backup_url'] ? $this->probe($website['backup_url']) : array('status' => 'unknown', 'code' => null, 'latency_ms' => null, 'error' => '', 'checked_at' => null);
        $this->saveHealth($website['id'], $primary, $backup);
        return array('primary' => $primary, 'backup' => $backup);
    }

    public function chooseDestination(array $website, $forceCheck = false)
    {
        $isStale = !$website['primary_checked_at'] || (time() - strtotime($website['primary_checked_at'] . ' UTC')) > $this->config['health']['stale_after_seconds'];
        if ($forceCheck || $isStale) {
            $health = $this->refreshWebsite($website);
            if ($health['primary']['status'] === 'up') {
                return array('url' => $website['primary_url'], 'role' => 'primary', 'health' => $health);
            }
            if ($website['backup_url'] && $health['backup']['status'] === 'up') {
                return array('url' => $website['backup_url'], 'role' => 'backup', 'health' => $health);
            }
            return null;
        }

        if ($website['primary_status'] === 'up' || $website['primary_status'] === 'unknown') {
            return array('url' => $website['primary_url'], 'role' => 'primary', 'health' => null);
        }
        if ($website['backup_url'] && ($website['backup_status'] === 'up' || $website['backup_status'] === 'unknown')) {
            return array('url' => $website['backup_url'], 'role' => 'backup', 'health' => null);
        }
        return null;
    }

    private function probeWithGet($url, $host, $port, $ip)
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->config['http']['connect_timeout'],
            CURLOPT_TIMEOUT => $this->config['http']['timeout'],
            CURLOPT_RANGE => '0-0',
            CURLOPT_USERAGENT => $this->config['http']['user_agent'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => array($host . ':' . $port . ':' . $ip),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $startedAt = microtime(true);
        curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        $isUp = in_array($code, $this->config['health']['success_codes'], true);
        return array('status' => $isUp ? 'up' : 'down', 'code' => $code ?: null, 'latency_ms' => $latency, 'error' => $isUp ? '' : ($error !== '' ? $error : 'HTTP 状态码 ' . $code), 'checked_at' => now_utc());
    }

    private function saveHealth($websiteId, array $primary, array $backup)
    {
        $statement = db()->prepare("INSERT OR REPLACE INTO website_health (
            website_id, primary_status, primary_code, primary_latency_ms, primary_error, primary_checked_at,
            backup_status, backup_code, backup_latency_ms, backup_error, backup_checked_at, updated_at
        ) VALUES (
            :website_id, :primary_status, :primary_code, :primary_latency_ms, :primary_error, :primary_checked_at,
            :backup_status, :backup_code, :backup_latency_ms, :backup_error, :backup_checked_at, :updated_at
        )");
        $statement->execute(array(
            ':website_id' => $websiteId,
            ':primary_status' => $primary['status'], ':primary_code' => $primary['code'], ':primary_latency_ms' => $primary['latency_ms'], ':primary_error' => $primary['error'], ':primary_checked_at' => $primary['checked_at'],
            ':backup_status' => $backup['status'], ':backup_code' => $backup['code'], ':backup_latency_ms' => $backup['latency_ms'], ':backup_error' => $backup['error'], ':backup_checked_at' => $backup['checked_at'],
            ':updated_at' => now_utc(),
        ));
    }
}
