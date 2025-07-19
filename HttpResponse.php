<?php

/*
 * Copyright (c) 2025. Lorem ipsum dolor sit amet, consectetur adipiscing elit.
 * Morbi non lorem porttitor neque feugiat blandit. Ut vitae ipsum eget quam lacinia accumsan.
 * Etiam sed turpis ac ipsum condimentum fringilla. Maecenas magna.
 * Proin dapibus sapien vel ante. Aliquam erat volutpat. Pellentesque sagittis ligula eget metus.
 * Vestibulum commodo. Ut rhoncus gravida arcu.
 */

declare(strict_types=1);

namespace nova\plugin\http;

use nova\framework\core\Context;
use nova\framework\core\Logger;

/**
 * HTTP响应处理类
 *
 * 负责解析和处理cURL请求的响应数据，包括HTTP状态码、响应头、响应体和Cookie信息。
 * 提供调试模式下的详细日志记录功能。
 *
 * @package nova\plugin\http
 * @author Nova Framework
 */
class HttpResponse
{
    /**
     * 响应头数组
     *
     * @var array<string, string>
     */
    protected array $headers = [];

    /**
     * 响应体内容
     *
     * @var string
     */
    protected string $body = '';

    /**
     * HTTP状态码
     *
     * @var int
     */
    protected int $http_code;

    /**
     * 请求元数据信息
     *
     * @var array<string, mixed>
     */
    protected array $meta;

    /**
     * Cookie字符串
     *
     * @var string
     */
    private string $cookie = '';

    /**
     * 构造函数
     *
     * 初始化HTTP响应对象，解析cURL响应数据并设置相关属性。
     *
     * @param  resource              $curl            cURL资源句柄
     * @param  array<string, string> $request_headers 请求头数组
     * @param  string                $request_exec    cURL执行返回的原始响应数据
     * @throws HttpException         当cURL执行出错时抛出异常
     */
    public function __construct($curl, array $request_headers, string $request_exec)
    {
        if (curl_errno($curl)) {
            throw new HttpException('cURL error: ' . curl_error($curl));
        }

        $this->meta = curl_getinfo($curl);
        $this->http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $this->meta['execution_time'] = curl_getinfo($curl, CURLINFO_TOTAL_TIME);

        if (Context::instance()->isDebug()) {
            Logger::info('请求时间：' . $this->meta['execution_time'] . '秒');
        }

        $this->setBody($curl, $request_headers, $request_exec);
    }

    /**
     * 获取Cookie字符串
     *
     * @return string Cookie字符串，格式为 "name1=value1; name2=value2"
     */
    public function getCookie(): string
    {
        return $this->cookie;
    }

    /**
     * 获取响应体内容
     *
     * @return string 响应体字符串
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * 设置响应体内容
     *
     * 解析cURL响应数据，分离响应头和响应体，并设置相关属性。
     *
     * @param  resource              $client          cURL资源句柄
     * @param  array<string, string> $request_headers 请求头数组
     * @param  string                $request_exec    cURL执行返回的原始响应数据
     * @return void
     */
    protected function setBody($client, array $request_headers, string $request_exec): void
    {
        $header_len = curl_getinfo($client, CURLINFO_HEADER_SIZE);
        $header_string = substr($request_exec, 0, $header_len);
        $this->setHeaders($request_headers, $header_string);
        $this->body = substr($request_exec, $header_len);

        if (Context::instance()->isDebug()) {
            $this->logResponse();
        }
    }

    /**
     * 获取HTTP状态码
     *
     * @return int HTTP状态码，如200、404、500等
     */
    public function getHttpCode(): int
    {
        return $this->http_code;
    }

    /**
     * 获取响应头数组
     *
     * @return array<string, string> 响应头数组，键为头名称，值为头值
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * 设置响应头信息
     *
     * 解析响应头字符串，提取响应头信息和Cookie信息。
     *
     * @param  array<string, string> $request       请求头数组
     * @param  string                $header_string 响应头字符串
     * @return void
     */
    protected function setHeaders(array $request, string $header_string): void
    {
        $headers_arr = array_filter(array_map('trim', explode("\r\n", $header_string)), function ($value) {
            return str_contains($value, ':');
        });

        $headers = [];
        foreach ($headers_arr as $header) {
            list($key, $value) = explode(':', $header, 2);
            $headers[$key] = trim($value);
        }

        $this->headers = $headers;

        if (!preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header_string, $matches)) {
            return;
        }

        $cookie = '';
        foreach ($request as $value) {
            if (strtolower(substr($value, 0, 6)) === 'cookie') {
                $cookie = substr($value, 8);
                break;
            }
        }

        $cookies = [];
        parse_str(str_replace(';', '&', $cookie), $cookies);

        foreach ($matches[1] as $cookieString) {
            parse_str($cookieString, $_cookie);
            $cookies = array_merge($cookies, $_cookie);
        }

        $cookieArr = [];
        foreach ($cookies as $name => $value) {
            $cookieArr[] = $name . '=' . $value;
        }

        $this->cookie = implode('; ', $cookieArr);
    }

    /**
     * 记录响应日志
     *
     * 在调试模式下记录完整的HTTP响应信息，包括状态码、响应头和响应体。
     *
     * @return void
     */
    protected function logResponse(): void
    {
        $headers = "";
        foreach ($this->headers as $name => $value) {
            $headers .= $name . ': ' . $value . "\n";
        }
        $uri = $this->meta['url'];
        $rawResponse = <<<EOF
>>> RESPONSE START >>>
$this->http_code $uri
$headers

$this->body
>>> RESPONSE END>>>
EOF;

        Logger::info($rawResponse);
    }
}
