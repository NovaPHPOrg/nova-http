<?php

declare(strict_types=1);
/*******************************************************************************
 * Copyright (c) 2022. Ankio. All Rights Reserved.
 ******************************************************************************/

namespace nova\plugin\http;

/**
 * 批量并发 HTTP 请求。
 *
 * 两种入口共用同一套并发循环 {@see self::run()}：
 * - 实例式：(new MultiHttp($urls, 5, $client))->execute(fn($url, $resp) => ...)
 * - 请求式：MultiHttp::runRequests($requests, 5, fn($url, $resp, $provider, $index) => ...)
 *   每个 request 形如 ['url' => string, 'client' => HttpClient, 'provider' => mixed?, 'index' => int?]
 *
 * 失败的请求（curl 出错或无响应体）不会触发回调。
 *
 * @package nova\plugin\http
 */
class MultiHttp
{
    /** @var list<string> */
    private array $urls;

    private int $maxThreads;

    private HttpClient $clientTemplate;

    /**
     * @param list<string> $urls 要请求的 URL 列表
     * @param int             $maxThreads     最大并发数
     * @param HttpClient|null $clientTemplate HttpClient 模板，为 null 则使用默认配置
     */
    public function __construct(array $urls, int $maxThreads = 5, ?HttpClient $clientTemplate = null)
    {
        $this->urls = array_values($urls);
        $this->maxThreads = $maxThreads;
        $this->clientTemplate = $clientTemplate ?? HttpClient::init();
    }

    /**
     * 用统一模板并发请求一组 URL。
     *
     * @param callable(string, HttpResponse): void $callback
     */
    public function execute(callable $callback): void
    {
        $requests = array_map(
            fn(string $url): array => ['url' => $url, 'client' => $this->clientTemplate],
            $this->urls,
        );

        self::run($requests, $this->maxThreads, $callback);
    }

    /**
     * 每项可指定独立 HttpClient（如 uapis 需 Authorization）。
     *
     * @param list<array{url: string, client: HttpClient, provider?: mixed, index?: int}> $requests
     * @param callable(string, HttpResponse, mixed, int): void $callback
     */
    public static function runRequests(array $requests, int $maxThreads, callable $callback): void
    {
        self::run(array_values($requests), $maxThreads, $callback);
    }

    /**
     * 并发执行队列：始终保持最多 $maxThreads 个在途请求，完成一个补一个。
     *
     * @param list<array{url: string, client: HttpClient, provider?: mixed, index?: int}> $queue
     * @param callable $callback
     */
    private static function run(array $queue, int $maxThreads, callable $callback): void
    {
        if ($queue === []) {
            return;
        }

        $maxThreads = max(1, $maxThreads);
        $mh = curl_multi_init();

        /** @var array<int, array{url: string, client: HttpClient, provider?: mixed, index?: int}> $active */
        $active = [];

        $launch = static function () use (&$queue, &$active, $mh): void {
            $entry = array_shift($queue);
            $ch = $entry['client']->createConfiguredHandle($entry['url']);
            curl_multi_add_handle($mh, $ch);
            $active[(int)$ch] = $entry;
        };

        for ($i = 0; $i < $maxThreads && $queue !== []; $i++) {
            $launch();
        }

        do {
            $status = curl_multi_exec($mh, $running);

            while ($done = curl_multi_info_read($mh)) {
                $ch = $done['handle'];
                $id = (int)$ch;
                $entry = $active[$id] ?? null;
                unset($active[$id]);

                // 仅成功且有响应体才回调；失败请求静默跳过，不喂 null 给调用方
                $content = $done['result'] === CURLE_OK ? curl_multi_getcontent($ch) : null;
                if ($entry !== null && is_string($content)) {
                    $callback(
                        $entry['url'],
                        new HttpResponse($ch, [], $content),
                        $entry['provider'] ?? null,
                        $entry['index'] ?? 0,
                    );
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if ($queue !== []) {
                    $launch();
                }
            }

            // select 在无就绪 fd 时返回 -1，必须退让，否则空转打满 CPU
            if ($running > 0 && curl_multi_select($mh) === -1) {
                usleep(100);
            }
        } while ($running && $status === CURLM_OK);

        curl_multi_close($mh);
    }
}
