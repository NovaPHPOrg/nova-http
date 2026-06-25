<?php

declare(strict_types=1);

/*
 * Copyright (c) 2025. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
 * Morbi non lorem porttitor neque feugiat blandit. Ut vitae ipsum eget quam lacinia accumsan.
 * Etiam sed turpis ac ipsum condimentum fringilla. Maecenas magna.
 * Proin dapibus sapien vel ante. Aliquam erat volutpat. Pellentesque sagittis ligula eget metus.
 * Vestibulum commodo. Ut rhoncus gravida arcu.
 */

namespace nova\plugin\http;

use CurlHandle;
use Error;
use nova\framework\cache\Cache;
use nova\framework\core\Context;

/**
 * HTTP 客户端类
 *
 * 基于 cURL 的 HTTP 客户端，支持 GET、POST、PUT、PATCH、DELETE 等请求方法
 * 提供链式调用接口，支持代理、超时、GZIP 压缩等功能
 *
 * @package nova\plugin\http
 * @author Nova Framework
 */
class HttpClient
{
    /** @var CurlHandle|null cURL 句柄 */
    private ?CurlHandle $curl = null;

    /** @var string 基础 URL */
    private string $base_url = "";

    /** @var string 请求路径 */
    private string $path = "";

    /** @var string URL 参数 */
    private string $url_params = "";

    /** @var array 请求头 */
    private array $headers = [];

    /** @var array cURL 选项配置 */
    private array $opts = [];

    protected Cache $cache;

    protected int $cacheTime = 0;

    /**
     * @param string $base_url 基础 URL
     */
    public function __construct($base_url = "")
    {
        $this->curl = curl_init();
        $this->setOption(CURLOPT_SSL_VERIFYPEER, false);
        $this->setOption(CURLOPT_SSL_VERIFYHOST, false);
        $this->setOption(CURLOPT_HEADER, 1);
        $this->setOption(CURLOPT_RETURNTRANSFER, 1);
        $this->headers['user-agent'] = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/104.0.5112.81 Safari/537.36 Edg/104.0.1293.54";
        $this->base_url = $base_url;
        $this->cache = Context::instance()->cache;
    }

    public function cache(int $cacheTime): HttpClient
    {
        $this->cacheTime = $cacheTime;
        return $this;
    }

    public static function init(string $base_url = ''): HttpClient
    {
        return new HttpClient($base_url);
    }

    public function proxy(string $url = ''): HttpClient
    {
        HttpProxy::apply($this, $url);
        return $this;
    }

    public function autoProxy(): HttpClient
    {
        HttpProxy::autoApply($this);
        return $this;
    }

    public function timeout(int $timeout = 30): HttpClient
    {
        $this->setOption(CURLOPT_TIMEOUT, $timeout);
        return $this;
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    public function setHeaders($headers = []): HttpClient
    {
        $this->headers = $headers;
        return $this;
    }

    public function setHeader($key, $value): HttpClient
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function get(): HttpClient
    {
        return $this->setOption(CURLOPT_HTTPGET, true);
    }

    public function setOption(int $curl_opt, $value): HttpClient
    {
        curl_setopt($this->curl, $curl_opt, $value);
        $this->opts[$curl_opt] = $value;
        return $this;
    }

    public function post($data, string $content_type = 'json'): self
    {
        $this->setOption(CURLOPT_POST, true);
        $this->setData($data, $content_type);
        return $this;
    }

    /**
     * @param array|string $data
     */
    private function setData($data, string $content_type = 'json'): void
    {
        $this->headers["Content-Type"] = $content_type;
        if ($content_type == 'form') {
            $this->headers["Content-Type"] = 'application/x-www-form-urlencoded';
            if (is_array($data)) {
                $data = http_build_query($data);
            }
        } elseif ($content_type == 'json') {
            $this->headers["Content-Type"] = 'application/json';
            if (is_array($data)) {
                $data = json_encode($data);
            }
        } elseif ($content_type == 'raw') {
            $this->headers["Content-Type"] = 'text/plain; charset=utf-8';
        }
        $this->setOption(CURLOPT_POSTFIELDS, $data);
    }

    public function getOpt($name): mixed
    {
        return $this->opts[$name] ?? null;
    }

    public function put(array $data, string $content_type = 'json'): HttpClient
    {
        $this->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $this->setData($data, $content_type);
        return $this;
    }

    public function patch(array $data, string $content_type = 'json'): HttpClient
    {
        $this->setOption(CURLOPT_CUSTOMREQUEST, "PATCH");
        $this->setData($data, $content_type);
        return $this;
    }

    public function delete(): HttpClient
    {
        $this->setOption(CURLOPT_CUSTOMREQUEST, "DELETE");
        return $this;
    }

    public function httpV1(): HttpClient
    {
        $this->setOption(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        return $this;
    }

    public function dns($dns): HttpClient
    {
        $this->setOption(CURLOPT_RESOLVE, $dns);
        return $this;
    }

    /**
     * @param array<string, mixed> $url_params
     */
    public function send(string $path = '', array $url_params = []): ?HttpResponse
    {
        $this->path = $path;
        if (count($url_params)) {
            $this->url_params = http_build_query($url_params);
        }

        $url = $this->url();
        $key = $this->cacheKey($url);

        if ($this->cacheTime > 0) {
            $response = $this->cache->get($key);
            if ($response instanceof HttpResponse) {
                return $response;
            }
        }

        $this->setOption(CURLOPT_URL, $url);
        $headers = $this->buildHeaderLines();
        $this->setOption(CURLOPT_HTTPHEADER, $headers);
        $this->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->setOption(CURLOPT_FOLLOWLOCATION, true);

        try {
            HttpRequestLogger::log($this, $url, $headers);

            $request_exec = curl_exec($this->curl);

            if ($request_exec === false) {
                throw new HttpException("HttpClient Error: " . curl_errno($this->curl) . " " . curl_error($this->curl));
            }

            $result = new HttpResponse($this->curl, $headers, $request_exec);
            if ($this->cacheTime > 0) {
                $this->cache->set($key, $result, $this->cacheTime);
            }

            return $result;
        } catch (Error $exception) {
            throw new HttpException($exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $url_params
     */
    public function stream(
        string $path = '',
        array $url_params = [],
        ?callable $onChunk = null,
        ?callable $onComplete = null
    ): void {
        $this->path = $path;
        if (count($url_params)) {
            $this->url_params = http_build_query($url_params);
        }

        $url = $this->url();
        $headers = $this->buildHeaderLines();

        $this->setOption(CURLOPT_URL, $url);
        $this->setOption(CURLOPT_HTTPHEADER, $headers);
        $this->setOption(CURLOPT_RETURNTRANSFER, false);
        $this->setOption(CURLOPT_FOLLOWLOCATION, true);
        $this->setOption(CURLOPT_HEADER, false);

        $responseHeaders = [];

        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$responseHeaders) {
            $trimmed = trim($line);
            if ($trimmed !== '' && str_contains($trimmed, ':')) {
                [$key, $value] = explode(':', $trimmed, 2);
                $responseHeaders[trim($key)] = trim($value);
            }
            return strlen($line);
        });

        curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use ($onChunk) {
            if (is_callable($onChunk)) {
                $onChunk($chunk);
            }
            return strlen($chunk);
        });

        try {
            HttpRequestLogger::log($this, $url, $headers, true);

            $ok = curl_exec($this->curl);
            if ($ok === false) {
                throw new HttpException("HttpClient(stream) Error: " . curl_errno($this->curl) . " " . curl_error($this->curl));
            }

            $httpCode = (int)curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
            $meta = curl_getinfo($this->curl);

            if (is_callable($onComplete)) {
                $onComplete($httpCode, $meta, $responseHeaders);
            }
        } catch (Error $exception) {
            throw new HttpException($exception->getMessage());
        } finally {
            curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, null);
            curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, null);
            $this->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->setOption(CURLOPT_HEADER, 1);
        }
    }

    /**
     * 缓存键纳入 method 与请求体，避免同 URL 不同 body 互相命中。
     */
    private function cacheKey(string $url): string
    {
        $body = $this->opts[CURLOPT_POSTFIELDS] ?? '';
        if (!is_string($body)) {
            $body = http_build_query((array)$body);
        }

        $method = $this->opts[CURLOPT_CUSTOMREQUEST]
            ?? (($this->opts[CURLOPT_POST] ?? false) ? 'POST' : 'GET');

        return md5($method . '|' . $url . '|' . $body);
    }

    private function url(): string
    {
        if (str_starts_with($this->path, "http")) {
            $url = $this->path;
        } else {
            $url = rtrim($this->base_url, '/') . "/" . ltrim($this->path, '/');
        }
        if ($this->url_params != '') {
            $url .= "?{$this->url_params}";
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private function buildHeaderLines(): array
    {
        $headers = [];
        foreach ($this->headers as $key => $header) {
            if (!is_int($key)) {
                $headers[] = "$key: $header";
            } else {
                $headers[] = $header;
            }
        }

        return $headers;
    }

    public function gzip(): HttpClient
    {
        $this->setOption(CURLOPT_ENCODING, "gzip");
        return $this;
    }

    public function createConfiguredHandle(string $url): CurlHandle
    {
        $ch = curl_init();

        foreach ($this->opts as $opt => $value) {
            if ($opt === CURLOPT_URL) {
                continue;
            }
            curl_setopt($ch, $opt, $value);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildHeaderLines());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return $ch;
    }
}
