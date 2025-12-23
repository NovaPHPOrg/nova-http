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

use InvalidArgumentException;

/**
 * 大文件下载管理器
 *
 * 支持单线程和多线程下载，复用 HttpClient 的所有配置能力（代理、超时、请求头等）
 *
 * 使用示例：
 * ```php
 * $client = HttpClient::init()
 *     ->timeout(300)
 *     ->proxy('socks5h://127.0.0.1:1080');
 *
 * $dm = new HttpDownloadManager($client);
 *
 * // 单线程下载
 * $dm->download($url, $savePath, function($downloaded, $total) {
 *     echo sprintf("Progress: %.2f%%\n", $downloaded / $total * 100);
 * });
 *
 * // 多线程下载（5个线程）
 * $dm->multiThreadDownload($url, $savePath, 5, function($downloaded, $total) {
 *     echo sprintf("Progress: %.2f%%\n", $downloaded / $total * 100);
 * });
 * ```
 *
 * @package nova\plugin\http
 */
class HttpDownloadManager
{
    /** @var HttpClient HTTP 客户端模板 */
    private HttpClient $client;

    /**
     * 构造函数
     *
     * @param HttpClient|null $client HTTP 客户端模板，为 null 则使用默认配置
     */
    public function __construct(?HttpClient $client = null)
    {
        $this->client = $client ?? HttpClient::init();
    }

    /**
     * 获取文件信息（大小和是否支持断点续传）
     *
     * @param  string                                $url 文件 URL
     * @return array{size: int, supportsRange: bool} 文件信息
     * @throws HttpException
     */
    private function getFileInfo(string $url): array
    {
        $client = clone $this->client;
        $client->setOption(CURLOPT_NOBODY, true); // HEAD 请求
        $client->setOption(CURLOPT_FOLLOWLOCATION, true);

        $response = $client->send($url);

        if ($response === null) {
            throw new HttpException("Failed to get file info from $url");
        }

        $headers = $response->getHeaders();
        $size = 0;
        $supportsRange = false;

        // 查找 Content-Length
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower($key);
            if ($lowerKey === 'content-length') {
                $size = (int)$value;
            }
            if ($lowerKey === 'accept-ranges' && strtolower($value) === 'bytes') {
                $supportsRange = true;
            }
        }

        return [
            'size' => $size,
            'supportsRange' => $supportsRange
        ];
    }

    /**
     * 单线程下载
     *
     * @param  string        $url        文件 URL
     * @param  string        $savePath   保存路径
     * @param  callable|null $onProgress 进度回调 function(array $info): void
     *                                   $info 结构：
     *                                   - mode            : 'single'
     *                                   - chunkIndex      : 分片索引（单线程恒为 0）
     *                                   - chunkCount      : 分片总数（单线程恒为 1）
     *                                   - chunkDownloaded : 当前分片已下载字节数
     *                                   - chunkSize       : 当前分片总字节数
     *                                   - chunkProgress   : 当前分片进度（0.0 ~ 1.0）
     *                                   - totalDownloaded : 总已下载字节数
     *                                   - totalSize       : 文件总字节数
     *                                   - totalProgress   : 总体进度（0.0 ~ 1.0）
     *                                   - speed           : 当前平均下载速度（字节/秒）
     *                                   - elapsed         : 已耗时（秒）
     * @return bool          是否下载成功
     * @throws HttpException
     */
    public function download(string $url, string $savePath, ?callable $onProgress = null): bool
    {
        // 获取文件大小
        $fileInfo = $this->getFileInfo($url);
        $totalSize = $fileInfo['size'];

        // 下载阶段必须跟随重定向，否则像 nmap 这类下载地址只会返回 302 而没有实体内容
        // HttpClient::send() 内部会自己打开 FOLLOWLOCATION，但这里我们直接用 createConfiguredHandle，
        // 所以需要显式打开。
        $this->client->setOption(CURLOPT_FOLLOWLOCATION, true);

        if ($totalSize === 0) {
            throw new HttpException("Cannot determine file size for $url");
        }

        $startTime = microtime(true);

        // 创建目标目录
        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 打开文件用于写入
        $fp = fopen($savePath, 'wb');
        if ($fp === false) {
            throw new HttpException("Cannot open file for writing: $savePath");
        }

        try {
            // 创建 curl 句柄并复用配置
            $ch = $this->client->createConfiguredHandle($url);

            // 直接写入文件句柄（让 curl 处理 I/O）
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, false);

            // 进度回调
            if (is_callable($onProgress)) {
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (
                    $resource,
                    int $downloadTotal,
                    int $downloaded,
                    int $uploadTotal,
                    int $uploaded
                ) use ($onProgress, $totalSize, $startTime) {
                    if (!is_callable($onProgress)) {
                        return;
                    }

                    $now     = microtime(true);
                    $elapsed = max($now - $startTime, 0.000001);

                    $chunkSize = $downloadTotal > 0 ? $downloadTotal : $totalSize;
                    $chunkSize = max($chunkSize, 1);

                    $info = [
                        'mode'            => 'single',
                        'chunkIndex'      => 0,
                        'chunkCount'      => 1,
                        'chunkDownloaded' => $downloaded,
                        'chunkSize'       => $chunkSize,
                        'chunkProgress'   => $downloaded / $chunkSize,
                        'totalDownloaded' => $downloaded,
                        'totalSize'       => $chunkSize,
                        'totalProgress'   => $downloaded / $chunkSize,
                        'speed'           => $downloaded / $elapsed,
                        'elapsed'         => $elapsed,
                    ];

                    $onProgress($info);
                });
            }

            $result = curl_exec($ch);

            if ($result === false) {
                throw new HttpException("Download failed: " . curl_error($ch));
            }

            curl_close($ch);
            return true;
        } finally {
            fclose($fp);
        }
    }

    /**
     * 多线程下载
     *
     * @param  string        $url        文件 URL
     * @param  string        $savePath   保存路径
     * @param  int           $threads    线程数（默认 5）
     * @param  callable|null $onProgress 进度回调 function(array $info): void
     *                                   $info 结构：
     *                                   - mode            : 'multi'
     *                                   - chunkIndex      : 当前分片索引
     *                                   - chunkCount      : 分片总数
     *                                   - chunkDownloaded : 当前分片已下载字节数
     *                                   - chunkSize       : 当前分片总字节数
     *                                   - chunkProgress   : 当前分片进度（0.0 ~ 1.0）
     *                                   - totalDownloaded : 所有分片累计已下载字节数
     *                                   - totalSize       : 文件总字节数
     *                                   - totalProgress   : 总体进度（0.0 ~ 1.0）
     *                                   - speed           : 当前平均整体下载速度（字节/秒）
     *                                   - elapsed         : 已耗时（秒）
     * @return bool          是否下载成功
     * @throws HttpException
     */
    public function multiThreadDownload(
        string $url,
        string $savePath,
        int $threads = 5,
        ?callable $onProgress = null
    ): bool {
        if ($threads < 1) {
            throw new InvalidArgumentException("Threads must be at least 1");
        }

        // 获取文件信息
        $fileInfo = $this->getFileInfo($url);
        $totalSize = $fileInfo['size'];
        $supportsRange = $fileInfo['supportsRange'];

        // 多线程下载同样需要确保所有分片请求都会跟随重定向
        $this->client->setOption(CURLOPT_FOLLOWLOCATION, true);

        if ($totalSize === 0) {
            throw new HttpException("Cannot determine file size for $url");
        }

        $startTime = microtime(true);

        // 如果不支持 Range 或线程数为 1，降级到单线程下载
        if (!$supportsRange || $threads === 1) {
            return $this->download($url, $savePath, $onProgress);
        }

        // 创建目标目录
        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // 计算分片
        $chunks = $this->calculateChunks($totalSize, $threads);
        $tempFiles = [];
        $downloadedBytes = array_fill(0, count($chunks), 0);

        try {
            // 为每个分片创建临时文件
            foreach ($chunks as $i => $chunk) {
                $tempFiles[$i] = tempnam(sys_get_temp_dir(), 'download_');
            }

            // 创建 Range 请求的 URL 列表
            $rangeUrls = [];
            foreach ($chunks as $i => $chunk) {
                $rangeUrls[$i] = [
                    'url' => $url,
                    'start' => $chunk['start'],
                    'end' => $chunk['end'],
                    'tempFile' => $tempFiles[$i]
                ];
            }

            // 使用 curl_multi 并发下载
            $this->downloadChunks($rangeUrls, $downloadedBytes, $totalSize, $onProgress, $startTime);

            // 合并文件
            $this->mergeChunks($tempFiles, $savePath);

            return true;
        } finally {
            // 清理临时文件
            foreach ($tempFiles as $tempFile) {
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
        }
    }

    /**
     * 计算分片信息
     *
     * @param  int                                     $totalSize 文件总大小
     * @param  int                                     $threads   线程数
     * @return array<int, array{start: int, end: int}> 分片信息
     */
    private function calculateChunks(int $totalSize, int $threads): array
    {
        $chunks = [];
        $chunkSize = (int)ceil($totalSize / $threads);

        for ($i = 0; $i < $threads; $i++) {
            $start = $i * $chunkSize;
            $end = min($start + $chunkSize - 1, $totalSize - 1);

            // 最后一个分片可能小于 chunkSize
            if ($start > $end) {
                break;
            }

            $chunks[] = [
                'start' => $start,
                'end' => $end
            ];
        }

        return $chunks;
    }

    /**
     * 并发下载分片（使用 curl_multi）
     *
     * @param array         $rangeUrls        Range 请求信息
     * @param array         &$downloadedBytes 已下载字节数（按分片索引）
     * @param int           $totalSize        文件总大小
     * @param callable|null $onProgress       进度回调（见 multiThreadDownload 的说明）
     * @param float         $startTime        下载开始时间戳（用于计算整体速度）
     */
    private function downloadChunks(
        array $rangeUrls,
        array &$downloadedBytes,
        int $totalSize,
        ?callable $onProgress,
        float $startTime
    ): void {
        $mh = curl_multi_init();
        $handles = [];
        $fileHandles = [];
        $chunkCount = count($rangeUrls);

        // 为每个分片创建 curl 句柄
        foreach ($rangeUrls as $i => $info) {
            // 创建带 Range 头的客户端
            $client = clone $this->client;
            $rangeHeader = sprintf('bytes=%d-%d', $info['start'], $info['end']);
            $client->setHeader('Range', $rangeHeader);

            $ch = $client->createConfiguredHandle($info['url']);

            // 打开临时文件
            $fp = fopen($info['tempFile'], 'wb');
            if ($fp === false) {
                throw new HttpException("Cannot open temp file: {$info['tempFile']}");
            }

            // 直接写入文件句柄
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, false);

            // 进度回调
            if (is_callable($onProgress)) {
                curl_setopt($ch, CURLOPT_NOPROGRESS, false);
                curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function (
                    $resource,
                    int $downloadTotal,
                    int $downloaded,
                    int $uploadTotal,
                    int $uploaded
                ) use (&$downloadedBytes, $i, $totalSize, $onProgress, $info, $startTime, $chunkCount) {
                    if (!is_callable($onProgress)) {
                        return;
                    }

                    $downloadedBytes[$i] = $downloaded;
                    $totalDownloaded     = array_sum($downloadedBytes);

                    $now     = microtime(true);
                    $elapsed = max($now - $startTime, 0.000001);

                    $chunkSize = ($info['end'] - $info['start'] + 1);
                    $chunkSize = max($chunkSize, 1);

                    $infoArr = [
                        'mode'            => 'multi',
                        'chunkIndex'      => $i,
                        'chunkCount'      => $chunkCount,
                        'chunkDownloaded' => $downloaded,
                        'chunkSize'       => $chunkSize,
                        'chunkProgress'   => $downloaded / $chunkSize,
                        'totalDownloaded' => $totalDownloaded,
                        'totalSize'       => $totalSize,
                        'totalProgress'   => $totalSize > 0 ? $totalDownloaded / $totalSize : 0.0,
                        'speed'           => $totalDownloaded / $elapsed,
                        'elapsed'         => $elapsed,
                    ];

                    $onProgress($infoArr);
                });
            }

            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
            $fileHandles[$i] = $fp;
        }

        // 执行并发下载
        $running = 0;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        // 清理资源
        foreach ($handles as $ch) {
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        // 关闭文件句柄
        foreach ($fileHandles as $fp) {
            fclose($fp);
        }
    }

    /**
     * 合并分片文件
     *
     * @param  array         $tempFiles 临时文件列表
     * @param  string        $savePath  最终保存路径
     * @throws HttpException
     */
    private function mergeChunks(array $tempFiles, string $savePath): void
    {
        $output = fopen($savePath, 'wb');
        if ($output === false) {
            throw new HttpException("Cannot open output file: $savePath");
        }

        try {
            foreach ($tempFiles as $tempFile) {
                if (!file_exists($tempFile)) {
                    throw new HttpException("Temp file not found: $tempFile");
                }

                $input = fopen($tempFile, 'rb');
                if ($input === false) {
                    throw new HttpException("Cannot open temp file: $tempFile");
                }

                try {
                    while (!feof($input)) {
                        $data = fread($input, 8192);
                        if ($data === false) {
                            break;
                        }
                        fwrite($output, $data);
                    }
                } finally {
                    fclose($input);
                }
            }
        } finally {
            fclose($output);
        }
    }
}
