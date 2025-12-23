<?php

declare(strict_types=1);
/*******************************************************************************
 * Copyright (c) 2022. Ankio. All Rights Reserved.
 ******************************************************************************/

/**
 * 批量并发 HTTP 请求类
 *
 * 复用 HttpClient 的配置能力，支持代理、超时、自定义请求头等所有特性
 *
 * 使用示例：
 * ```php
 * $client = HttpClient::init()
 *     ->timeout(30)
 *     ->proxy('socks5h://127.0.0.1:1080')
 *     ->setHeader('X-Custom', 'value');
 *
 * $multi = new MultiHttp($urls, 5, $client);
 * $multi->execute(function($url, $httpCode, $content) {
 *     echo "Downloaded $url: $httpCode\n";
 * });
 * ```
 *
 * @package nova\plugin\http
 */

namespace nova\plugin\http;

use CurlMultiHandle;

class MultiHttp
{
    private array $urls;
    private int $maxThreads;
    private CurlMultiHandle $mh;
    private array $activeHandles = [];
    private ?HttpClient $clientTemplate;

    /**
     * @param array           $urls           要请求的 URL 列表
     * @param int             $maxThreads     最大并发数
     * @param HttpClient|null $clientTemplate HttpClient 模板，为 null 则使用默认配置
     */
    public function __construct(array $urls, int $maxThreads = 5, ?HttpClient $clientTemplate = null)
    {
        $this->urls = $urls;
        $this->maxThreads = $maxThreads;
        $this->clientTemplate = $clientTemplate ?? HttpClient::init();
        $this->mh = curl_multi_init();
    }

    /**
     * 执行批量请求
     *
     * @param callable $callback 回调函数 function(string $url, int $httpCode, string $content): void
     */
    public function execute(callable $callback): void
    {
        $this->initializeHandles();
        $this->executeMultiHandle($callback);
        $this->closeMultiHandle();
    }

    /**
     * 初始化线程池
     */
    private function initializeHandles(): void
    {
        for ($i = 0; $i < min($this->maxThreads, count($this->urls)); $i++) {
            if (!empty($this->urls)) {
                $this->addRequest(array_shift($this->urls));
            }
        }
    }

    /**
     * 添加一个请求到线程池
     */
    private function addRequest(string $url): void
    {
        // 使用 HttpClient 创建配置好的 curl 句柄
        $ch = $this->clientTemplate->createConfiguredHandle($url);

        curl_multi_add_handle($this->mh, $ch);
        $this->activeHandles[(int)$ch] = $url;
    }

    /**
     * 执行并发请求
     */
    private function executeMultiHandle(callable $callback): void
    {
        do {
            $status = curl_multi_exec($this->mh, $running);

            while ($done = curl_multi_info_read($this->mh)) {
                $ch = $done['handle'];
                $result = curl_multi_getcontent($ch);
                $handleId = (int)$ch;

                if (isset($this->activeHandles[$handleId])) {
                    $url = $this->activeHandles[$handleId];
                    $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $callback($url, $responseCode, $result);
                    unset($this->activeHandles[$handleId]);
                }

                curl_multi_remove_handle($this->mh, $ch);
                curl_close($ch);

                // 如果还有待处理的 URL，添加到线程池
                if (!empty($this->urls)) {
                    $this->addRequest(array_shift($this->urls));
                }
            }

            if ($running > 0) {
                curl_multi_select($this->mh);
            }
        } while ($running && $status == CURLM_OK);
    }

    /**
     * 关闭多线程句柄
     */
    private function closeMultiHandle(): void
    {
        curl_multi_close($this->mh);
    }
}
