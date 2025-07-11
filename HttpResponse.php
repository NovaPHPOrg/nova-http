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

class HttpResponse
{
    protected array $headers = [];
    protected string $body = '';
    protected int $http_code;
    protected array $meta;
    private string $cookie = '';

    /**
     * @throws HttpException
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

    public function getCookie(): string
    {
        return $this->cookie;
    }

    public function getBody(): string
    {
        return $this->body;
    }

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

    public function getHttpCode(): int
    {
        return $this->http_code;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

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
