# nova-http

基于 cURL 的 HTTP 客户端库，提供单请求、批量并发请求和大文件下载功能。

## 组件

### 1. HttpClient - 单请求客户端

支持 GET、POST、PUT、PATCH、DELETE 等请求方法，提供链式调用接口。

```php
$response = HttpClient::init('https://api.example.com')
    ->timeout(30)
    ->proxy('socks5h://127.0.0.1:1080')
    ->setHeader('Authorization', 'Bearer token')
    ->get()
    ->send('/users/123');

echo $response->getBody();
```

### 2. MultiHttp - 批量并发请求

复用 HttpClient 的配置能力，支持代理、超时、自定义请求头等所有特性。

```php
$client = HttpClient::init()
    ->timeout(30)
    ->proxy('socks5h://127.0.0.1:1080')
    ->setHeader('X-Custom', 'value');

$urls = [
    'https://example.com/file1',
    'https://example.com/file2',
    'https://example.com/file3',
];

$multi = new MultiHttp($urls, 5, $client);
$multi->execute(function($url, $httpCode, $content) {
    echo "Downloaded $url: $httpCode\n";
});
```

### 3. HttpDownloadManager - 大文件下载管理器

支持单线程和多线程下载，自动检测服务器是否支持断点续传（Range 请求）。

#### 单线程下载

```php
$client = HttpClient::init()
    ->timeout(300)
    ->proxy('socks5h://127.0.0.1:1080');

$dm = new HttpDownloadManager($client);

$dm->download(
    'https://example.com/large-file.zip',
    '/path/to/save/file.zip',
    function(array $info) {
        $progress   = $info['totalProgress'] * 100;
        $downloaded = $info['totalDownloaded'];
        $total      = $info['totalSize'] ?: 1;
        $speedKB    = $info['speed'] / 1024;

        echo sprintf(
            "下载进度: %.2f%% (%.2f MB / %.2f MB) 速度: %.2f KB/s\n",
            $progress,
            $downloaded / 1024 / 1024,
            $total / 1024 / 1024,
            $speedKB
        );
    }
);
```

#### 多线程下载

```php
$dm = new HttpDownloadManager($client);

$dm->multiThreadDownload(
    'https://example.com/large-file.zip',
    '/path/to/save/file.zip',
    5, // 5个并发线程
    function(array $info) {
        $progress   = $info['totalProgress'] * 100;
        $downloaded = $info['totalDownloaded'];
        $total      = $info['totalSize'] ?: 1;
        $speedKB    = $info['speed'] / 1024;

        echo sprintf(
            "[chunk %d/%d] 下载进度: %.2f%% (%.2f MB / %.2f MB) 速度: %.2f KB/s\r",
            $info['chunkIndex'] + 1,
            $info['chunkCount'],
            $progress,
            $downloaded / 1024 / 1024,
            $total / 1024 / 1024,
            $speedKB
        );
    }
);
```

**特性**：
- 自动检测服务器是否支持 Range 请求
- 不支持 Range 时自动降级为单线程下载
- 复用 HttpClient 的所有配置（代理、超时、请求头等）
- 实时进度回调
- 自动清理临时文件

## 设计原则

### 数据结构优先
- HttpClient: 单个 curl 句柄 + 配置选项
- MultiHttp: curl_multi 句柄 + 活跃连接池
- HttpDownloadManager: 分片信息 + 临时文件管理

### 消除特殊情况
- 单线程下载 = 1个分片的多线程下载
- 不支持 Range = 自动降级为单线程
- MultiHttp 使用相同的配置模板创建多个请求

### 简洁实现
- 使用 PHP 原生 cURL 扩展
- 最少的抽象层
- 清晰的职责划分

## 错误处理

所有组件在错误时抛出 `HttpException`：

```php
try {
    $response = HttpClient::init()->get()->send('https://example.com');
} catch (HttpException $e) {
    echo "请求失败: " . $e->getMessage();
}
```