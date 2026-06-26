<?php

declare(strict_types=1);

namespace nova\plugin\http;

use InvalidArgumentException;

use function nova\framework\config;

/**
 * HTTP 代理解析与应用。
 */
final class HttpProxy
{
    /**
     * 环境变量代理，按优先级依次尝试（与 curl / wget / apt 等常见约定对齐）。
     *
     * @var list<array{names: list<string>, scheme: string}>
     */
    private const ENV_PROXY_GROUPS = [
        ['names' => ['ALL_PROXY', 'all_proxy'], 'scheme' => 'http'],
        ['names' => ['HTTPS_PROXY', 'https_proxy'], 'scheme' => 'http'],
        ['names' => ['HTTP_PROXY', 'http_proxy'], 'scheme' => 'http'],
        ['names' => ['SOCKS_PROXY', 'socks_proxy', 'socks5_proxy', 'sock_proxy'], 'scheme' => 'socks5'],
    ];

    /**
     * 自动设置代理：http.proxy → 常见代理环境变量。
     * 同时设置 no_proxy（配置 http.no_proxy 或环境变量 no_proxy）。
     */
    public static function autoApply(HttpClient $client): void
    {
        $proxy = self::resolve();
        if ($proxy !== '') {
            self::apply($client, $proxy);
        }

        $noProxy = self::resolveNoProxy();
        if ($noProxy !== '') {
            $client->setOption(CURLOPT_NOPROXY, $noProxy);
        }
    }

    /** 解析代理地址：配置 http.proxy 优先，其次环境变量。 */
    public static function resolve(): string
    {
        $fromConfig = trim((string)(config('http.proxy') ?? ''));
        if ($fromConfig !== '') {
            return self::normalizeUrl($fromConfig);
        }

        return self::fromEnv();
    }

    private static function normalizeUrl(string $url, string $defaultScheme = 'http'): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = $defaultScheme . '://' . $url;
        }

        return $url;
    }

    private static function fromEnv(): string
    {
        foreach (self::ENV_PROXY_GROUPS as $group) {
            foreach ($group['names'] as $name) {
                $value = self::readEnv($name);
                if ($value !== '') {
                    return self::normalizeUrl($value, $group['scheme']);
                }
            }
        }

        return '';
    }

    private static function readEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false && isset($_ENV[$name]) && is_string($_ENV[$name])) {
            $value = $_ENV[$name];
        }
        if ($value === false) {
            return '';
        }

        return trim($value);
    }

    /**
     * 设置代理（只接收完整 URL）；传空字符串表示关闭代理。
     */
    public static function apply(HttpClient $client, string $url = ''): void
    {
        if ($url === '') {
            $client->setOption(CURLOPT_PROXY, '');
            $client->setOption(CURLOPT_PROXYPORT, 0);
            $client->setOption(CURLOPT_PROXYUSERPWD, null);
            $client->setOption(CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            return;
        }

        $u = parse_url($url);
        if ($u === false || empty($u['scheme']) || empty($u['host'])) {
            throw new InvalidArgumentException("Bad proxy url: $url");
        }

        $scheme = strtolower($u['scheme']);
        $host = $u['host'];
        $port = $u['port'] ?? 0;
        $username = $u['user'] ?? '';
        $password = $u['pass'] ?? '';

        $type = match ($scheme) {
            'socks4' => CURLPROXY_SOCKS4,
            'socks4a' => CURLPROXY_SOCKS4A,
            'socks5h' => defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : 7,
            'socks5' => CURLPROXY_SOCKS5,
            default => CURLPROXY_HTTP,
        };

        $client->setOption(CURLOPT_PROXY, $host);
        if ($port) {
            $client->setOption(CURLOPT_PROXYPORT, (int)$port);
        }
        $client->setOption(CURLOPT_PROXYTYPE, $type);

        if ($username !== '') {
            $client->setOption(CURLOPT_PROXYUSERPWD, $username . ':' . $password);
        }
    }

    /** 解析 no_proxy 列表（逗号分隔）。 */
    public static function resolveNoProxy(): string
    {
        $fromConfig = trim((string)(config('http.no_proxy') ?? ''));
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        foreach (['NO_PROXY', 'no_proxy'] as $name) {
            $value = self::readEnv($name);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
