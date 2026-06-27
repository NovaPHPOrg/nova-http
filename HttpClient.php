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
use InvalidArgumentException;
use nova\framework\cache\Cache;
use nova\framework\core\Context;
use nova\framework\core\Logger;

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
     * 构造函数
     *
     * @param string $base_url 基础 URL
     */
    public function __construct(string $base_url = "")
    {
        $this->curl = curl_init();
        $this->setOption(CURLOPT_SSL_VERIFYPEER, false);
        $this->setOption(CURLOPT_SSL_VERIFYHOST, false);
        $this->setOption(CURLOPT_HEADER, 1);
        $this->setOption(CURLOPT_RETURNTRANSFER, 1);
        $this->headers = [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Sec-Ch-Ua' => '"Chromium";v="136", "Google Chrome";v="136", "Not.A/Brand";v="99"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"macOS"',
            'Cache-Control' => 'max-age=0',
        ];
        $this->base_url = $base_url;
        $this->cache = Context::instance()->cache;
    }

    public function cache(int $cacheTime): HttpClient
    {
        $this->cacheTime = $cacheTime;
        return $this;
    }
    /**
     * 初始化 HTTP 客户端
     *
     * @param  string     $base_url 基础 URL
     * @return HttpClient
     */
    public static function init(string $base_url = ''): HttpClient
    {
        return new HttpClient($base_url);
    }

    /**
     * 设置代理（只接收完整 URL）
     * 例：socks5h://user:pass@127.0.0.1:1080
     *     http://proxy.local:3128
     *     socks4a://10.0.0.2:1080
     *
     * 传空字符串表示关闭代理。
     */
    public function proxy(string $url = ''): HttpClient
    {
        HttpProxy::apply($this,$url);
        return $this;
    }

    /**
     * 设置请求超时时间
     *
     * @param  int        $timeout 超时时间（秒），默认 30 秒
     * @return HttpClient
     */
    public function timeout(int $timeout = 30): HttpClient
    {
        $this->setOption(CURLOPT_TIMEOUT, $timeout);
        return $this;
    }

    /**
     * 析构函数，关闭 cURL 连接
     */
    public function __destruct()
    {

        curl_close($this->curl);
    }

    /**
     * 设置请求头
     *
     * @param  array      $headers 请求头数组
     * @return HttpClient
     */
    public function setHeaders($headers = []): HttpClient
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * 设置单个请求头
     *
     * @param  string     $key   请求头名称
     * @param  string     $value 请求头值
     * @return HttpClient
     */
    public function setHeader($key, $value): HttpClient
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * 设置 GET 请求方法
     *
     * @return HttpClient
     */
    public function get(): HttpClient
    {
        return $this->setOption(CURLOPT_HTTPGET, true);
    }

    /**
     * 设置 cURL 选项
     *
     * @param  int        $curl_opt cURL 选项常量
     * @param  mixed      $value    选项值
     * @return HttpClient
     */
    public function setOption(int $curl_opt, $value): HttpClient
    {
        curl_setopt($this->curl, $curl_opt, $value);
        $this->opts[$curl_opt] = $value;
        return $this;
    }

    /**
     * 设置 POST 请求方法
     *
     * @param  array|string $data         POST 数据
     * @param  string       $content_type 内容类型，支持 'json' 和 'form'
     * @return HttpClient
     */
    public function post($data, string $content_type = 'json'): self
    {
        $this->setOption(CURLOPT_POST, true);
        $this->setData($data, $content_type);
        return $this;
    }

    /**
     * 设置请求数据
     *
     * @param array|string $data         请求数据
     * @param string       $content_type 内容类型
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
        //$this->headers["content-length"] = mb_strlen($data);
        $this->setOption(CURLOPT_POSTFIELDS, $data);
    }

    /**
     * 获取 cURL 选项值
     *
     * @param  string $name 选项名称
     * @return mixed  选项值
     */
    public function getOpt($name): mixed
    {
        return $this->opts[$name] ?? null;
    }

    /**
     * 设置 PUT 请求方法
     *
     * @param  array      $data         PUT 数据
     * @param  string     $content_type 内容类型
     * @return HttpClient
     */
    public function put(array $data, string $content_type = 'json'): HttpClient
    {
        $this->setOption(CURLOPT_CUSTOMREQUEST, "PUT");
        $this->setData($data, $content_type);
        return $this;
    }

    /**
     * 设置 PATCH 请求方法
     *
     * @param  array      $data         PATCH 数据
     * @param  string     $content_type 内容类型
     * @return HttpClient
     */
    public function patch(array $data, string $content_type = 'json'): HttpClient
    {
        $this->setOption(CURLOPT_CUSTOMREQUEST, "PATCH");
        $this->setData($data, $content_type);
        return $this;
    }

    /**
     * 设置 DELETE 请求方法
     *
     * @return HttpClient
     */
    public function delete(): HttpClient
    {
        $this->setOption(CURLOPT_CUSTOMREQUEST, "DELETE");
        return $this;
    }

    /**
     * 设置 HTTP/1.1 协议版本
     *
     * @return HttpClient
     */
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
     * 发送 HTTP 请求
     *
     * @param  string            $path       请求路径
     * @param  array             $url_params URL 参数
     * @return HttpResponse|null 响应对象
     * @throws HttpException     当请求失败时抛出异常
     */
    public function send(string $path = '', array $url_params = []): ?HttpResponse
    {
        $this->path = $path;
        if (count($url_params)) {
            $this->url_params = http_build_query($url_params);
        }

        $url = $this->url();

        $key = md5($url);

        if ($this->cacheTime > 0) {
            $response = $this->cache->get($key);
            if ($response instanceof HttpResponse) {
                return $response;
            }
        }

        $this->setOption(CURLOPT_URL, $url);

        $headers = [];
        foreach ($this->headers as $key => $header) {

            if (!is_int($key)) {
                $headers[] = "$key: $header";
            } else {
                $headers[] = $header;
            }
        }

        $this->setOption(CURLOPT_HTTPHEADER, $headers);
        $this->setOption(CURLOPT_RETURNTRANSFER, true);
        $this->setOption(CURLOPT_FOLLOWLOCATION, true);

        try {

            if (Context::instance()->isDebug()) {
                $m = $this->getOpt(CURLOPT_CUSTOMREQUEST);
                if (empty($m)) {
                    if ($this->getOpt(CURLOPT_HTTPGET)) {
                        $m = "GET";
                    } else {
                        $m = "POST";
                    }
                }
                $headers_string = join("\n", $headers);
                $body = $this->getOpt(CURLOPT_POSTFIELDS);

                // 处理数组类型的 body（如 multipart/form-data）
                if (is_array($body)) {
                    $bodyParts = [];
                    foreach ($body as $key => $value) {
                        if ($value instanceof \CURLFile) {
                            $bodyParts[] = "$key: [FILE] " . $value->getFilename();
                        } else {
                            $bodyParts[] = "$key: $value";
                        }
                    }
                    $body = implode("\n", $bodyParts);
                }

                $rawReq = <<<EOF

>>> REQUEST START >>>
$m $url
$headers_string

$body
>>> REQUEST END>>>
EOF;

                Logger::info($rawReq);

            }

            $request_exec = curl_exec($this->curl);

            if ($request_exec === false) {
                throw new HttpException("HttpClient Error: " . curl_errno($this->curl) . " " . curl_error($this->curl));
            }

            $result =  new HttpResponse($this->curl, $headers, $request_exec);
            if ($this->cacheTime > 0) {
                $this->cache->set($key, $result, $this->cacheTime);
            }
            return $result;

        } catch (Error $exception) {
            throw new HttpException($exception->getMessage());
        }

    }

    /**
     * 以流式回调的方式发送 HTTP 请求
     *
     * 使用 cURL 的回调来逐块接收响应体，适合对接 SSE/JSONL 等流式接口。
     * 此方法不会返回完整响应体，也不做缓存。
     *
     * @param string               $path       请求路径
     * @param array<string, mixed> $url_params 追加的 URL 参数
     * @param callable|null        $onChunk    可选，接收每个响应体分片：function(string $chunk): void
     * @param callable|null        $onComplete 可选，请求完成回调：function(int $httpCode, array $meta, array $headers): void
     *
     * @return void
     * @throws HttpException
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

        $headers = [];
        foreach ($this->headers as $key => $header) {
            if (!is_int($key)) {
                $headers[] = "$key: $header";
            } else {
                $headers[] = $header;
            }
        }

        $this->setOption(CURLOPT_URL, $url);
        $this->setOption(CURLOPT_HTTPHEADER, $headers);
        // 流式：不将响应作为字符串返回，也不把头部拼到输出里
        $this->setOption(CURLOPT_RETURNTRANSFER, false);
        $this->setOption(CURLOPT_FOLLOWLOCATION, true);
        $this->setOption(CURLOPT_HEADER, false);

        $responseHeaders = [];

        // 响应体分片回调
        curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use ($onChunk) {
            if (is_callable($onChunk)) {
                $onChunk($chunk);
            }
            return strlen($chunk);
        });

        try {
            // 调试日志
            if (Context::instance()->isDebug()) {
                $m = $this->getOpt(CURLOPT_CUSTOMREQUEST);
                if (empty($m)) {
                    $m = $this->getOpt(CURLOPT_HTTPGET) ? 'GET' : 'POST';
                }
                $headers_string = join("\n", $headers);
                $body = $this->getOpt(CURLOPT_POSTFIELDS);
                $rawReq = <<<EOF

>>> REQUEST START (stream) >>>
$m $url
$headers_string

$body
>>> REQUEST END (stream) >>>
EOF;
                Logger::info($rawReq);
            }

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
            // 仅清除本方法设置的写回调；勿动 HEADERFUNCTION：
            // 对其置 null 会在句柄复用时让下次 curl_exec 抛
            // "Invalid callback , no array or string given"（多轮工具调用复用同一句柄即触发）
            curl_setopt($this->curl, CURLOPT_WRITEFUNCTION, null);
            $this->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->setOption(CURLOPT_HEADER, 1);
        }
    }

    /**
     * 构造完整的请求 URL
     *
     * @return string 完整的 URL
     */
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
     * 启用 GZIP 压缩支持
     *
     * @return HttpClient
     */
    public function gzip(): HttpClient
    {
        $this->setOption(CURLOPT_ENCODING, "gzip");
        return $this;
    }

    /**
     * 创建一个配置好的 curl 句柄（供 MultiHttp 等批量请求使用）
     *
     * @param  string     $url 目标 URL
     * @return CurlHandle 配置好的 curl 句柄
     * @internal 此方法用于内部批量请求，不建议直接使用
     */
    public function createConfiguredHandle(string $url): CurlHandle
    {
        $ch = curl_init();

        // 复制当前实例的所有配置
        foreach ($this->opts as $opt => $value) {
            // 跳过 URL 相关的选项，因为我们会单独设置
            if ($opt === CURLOPT_URL) {
                continue;
            }
            curl_setopt($ch, $opt, $value);
        }

        // 设置目标 URL
        curl_setopt($ch, CURLOPT_URL, $url);

        // 应用请求头
        $headers = [];
        foreach ($this->headers as $key => $header) {
            if (!is_int($key)) {
                $headers[] = "$key: $header";
            } else {
                $headers[] = $header;
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // 确保基本配置
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        return $ch;
    }

    public function autoProxy():self
    {
        HttpProxy::autoApply($this);
        return $this;
    }

}
