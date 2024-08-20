<?php

declare(strict_types=1);

namespace nova\plugin\http;

use nova\framework\App;
use nova\framework\log\Logger;

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

        if (App::getInstance()->debug) {
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

    public function getHttpCode(): int
    {
        return $this->http_code;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    protected function setBody($client, array $request_headers, string $request_exec): void
    {
        $header_len = curl_getinfo($client, CURLINFO_HEADER_SIZE);
        $header_string = substr($request_exec, 0, $header_len);
        $this->setHeaders($request_headers, $header_string);
        $this->body = substr($request_exec, $header_len);

        if (App::getInstance()->debug) {
            $this->logResponse();
        }
    }

    protected function setHeaders(array $request, string $header_string): void
    {
        $headers_arr = array_filter(array_map('trim', explode("\r\n", $header_string)), function($value) {
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
        Logger::info('┌---------------------HTTP RESPONSE---------------------');
        Logger::info('│');
        Logger::info('│');
        Logger::info('│' . $this->http_code);
        Logger::info('│' . $this->meta['url']);
        Logger::info('│');
        Logger::info('│');
        Logger::info('│' . $this->body);
        Logger::info('└------------------------------------------------------');
    }
}
