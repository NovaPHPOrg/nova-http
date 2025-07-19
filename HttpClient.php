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

    /**
     * 构造函数
     *
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
     * 设置代理
     *
     * @param  string     $host     代理主机地址
     * @param  int        $port     代理端口
     * @param  string     $username 代理用户名
     * @param  string     $password 代理密码
     * @return HttpClient
     */
    public function proxy($host, $port, string $username = '', string $password = ''): HttpClient
    {

        if (!empty($host)) {
            $this->setOption(CURLOPT_PROXY, $host);
            $this->setOption(CURLOPT_PROXYPORT, $port);
        }
        if (!empty($username)) {
            $this->setOption(CURLOPT_PROXYUSERPWD, "$username:$password");
        }
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

            return new HttpResponse($this->curl, $headers, $request_exec);

        } catch (Error $exception) {
            throw new HttpException($exception->getMessage());
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
}
