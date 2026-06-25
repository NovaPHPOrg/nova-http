# nova-http

基于 cURL 的 HTTP 客户端库，提供单请求、批量并发请求和大文件下载功能。

## 组件

| 类                                | 职责                      |
|----------------------------------|-------------------------|
| `HttpClient`                     | 单请求客户端，链式配置与发送          |
| `HttpProxy`                      | 代理解析与应用（配置 / 环境变量 / 认证） |
| `HttpRequestLogger`              | debug 模式下输出请求日志         |
| `MultiHttp`                      | 批量并发请求                  |
| `HttpDownloadManager`            | 大文件单线程 / 多线程下载          |
| `HttpResponse` / `HttpException` | 响应封装与异常                 |

### 1. HttpClient - 单请求客户端

支持 GET、POST、PUT、PATCH、DELETE 等请求方法，提供链式调用接口。

```php
use nova\plugin\http\HttpClient;

$response = HttpClient::init('https://api.example.com')
    ->timeout(30)
    ->autoProxy()
    ->setHeader('Authorization', 'Bearer token')
    ->get()
    ->send('/users/123');

echo $response->getBody();
```

手动指定代理：

```php
$response = HttpClient::init('https://api.example.com')
    ->proxy('socks5h://user:pass@127.0.0.1:1080')
    ->get()
    ->send('/users/123');
```

### 2. MultiHttp - 批量并发请求

复用 HttpClient 的配置能力，支持代理、超时、自定义请求头等所有特性。

```php
use nova\plugin\http\HttpClient;
use nova\plugin\http\MultiHttp;

$client = HttpClient::init()
    ->timeout(30)
    ->autoProxy()
    ->setHeader('X-Custom', 'value');

$urls = [
    'https://example.com/file1',
    'https://example.com/file2',
    'https://example.com/file3',
];

$multi = new MultiHttp($urls, 5, $client);
$multi->execute(function ($url, $httpCode, $content) {
    echo "Downloaded $url: $httpCode\n";
});
```

### 3. HttpDownloadManager - 大文件下载管理器

支持单线程和多线程下载，自动检测服务器是否支持断点续传（Range 请求）。

#### 单线程下载

```php
use nova\plugin\http\HttpClient;
use nova\plugin\http\HttpDownloadManager;

$client = HttpClient::init()
    ->timeout(300)
    ->autoProxy();

$dm = new HttpDownloadManager($client);

$dm->download(
    'https://example.com/large-file.zip',
    '/path/to/save/file.zip',
    function (array $info) {
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
    5,
    function (array $info) {
        echo sprintf(
            "[chunk %d/%d] 进度: %.2f%%\r",
            $info['chunkIndex'] + 1,
            $info['chunkCount'],
            $info['totalProgress'] * 100
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

## 代理（HttpProxy）

代理逻辑集中在 `HttpProxy`，`HttpClient::proxy()` / `autoProxy()` 仅为链式入口。

### 自动代理 autoProxy()

```php
HttpClient::init('https://api.example.com')
    ->autoProxy()
    ->get()
    ->send('/path');
```

**解析优先级：**

1. 配置项 `http.proxy`
2. 环境变量（按顺序取第一个非空值）：
    - `ALL_PROXY` / `all_proxy`
    - `HTTPS_PROXY` / `https_proxy`
    - `HTTP_PROXY` / `http_proxy`
    - `SOCKS_PROXY` / `socks_proxy` / `socks5_proxy` / `sock_proxy`

**绕过列表 no_proxy：**

- 配置项 `http.no_proxy`
- 环境变量 `NO_PROXY` / `no_proxy`（逗号分隔，如 `localhost,127.0.0.1,.internal.com`）

对应 cURL 的 `CURLOPT_NOPROXY`，匹配到的目标主机不走代理。

### 配置示例（config.php）

```php
'http' => [
    'proxy'    => 'http://user:pass@10.196.72.194:7890',
    'no_proxy' => 'localhost,127.0.0.1,.local',
    'timeout'  => 15,
],
```

### 环境变量示例

```bash
export HTTPS_PROXY=http://user:pass@127.0.0.1:7890
export NO_PROXY=localhost,127.0.0.1
```

未带 scheme 的地址会自动补前缀：HTTP/HTTPS 类变量补 `http://`，SOCKS 类变量补 `socks5://`。

### 带账号密码的代理

支持标准 URL 格式，用户名密码写在 URL 中：

```text
http://username:password@host:port
https://username:password@host:port
socks5://username:password@host:port
socks5h://username:password@host:port
```

配置、`autoProxy()`、手动 `proxy()` 均生效。密码含 `@`、`:` 等特殊字符时需 URL 编码（如 `@` → `%40`）。

当前通过 `CURLOPT_PROXYUSERPWD` 传递凭据，适用于 Basic 及常见 SOCKS 认证；NTLM / Negotiate 等企业代理可能需要额外配置
`CURLOPT_PROXYAUTH`。

### 手动指定代理

```php
// 关闭代理
$client->proxy('');

// HTTP / HTTPS 代理
$client->proxy('http://127.0.0.1:7890');

// SOCKS5（域名由代理端解析）
$client->proxy('socks5h://127.0.0.1:1080');
```

### 静态解析（不发起请求）

```php
use nova\plugin\http\HttpProxy;

$proxy   = HttpProxy::resolve();      // 代理地址
$noProxy = HttpProxy::resolveNoProxy(); // 绕过列表
```

## 调试日志（HttpRequestLogger）

应用 `debug => true` 时，`send()` / `stream()` 会通过 `HttpRequestLogger` 输出请求详情（方法、URL、请求头、Body），便于排查网络问题。日志不包含响应体。

## 设计原则

### 数据结构优先

- `HttpClient`：单个 curl 句柄 + 配置选项
- `HttpProxy`：代理 URL 解析 → cURL 选项
- `MultiHttp`：curl_multi 句柄 + 活跃连接池
- `HttpDownloadManager`：分片信息 + 临时文件管理

### 消除特殊情况

- 单线程下载 = 1 个分片的多线程下载
- 不支持 Range = 自动降级为单线程
- `MultiHttp` 使用相同的配置模板创建多个请求
- 代理 / 日志与核心请求逻辑分离，避免 `HttpClient` 膨胀

### 简洁实现
- 使用 PHP 原生 cURL 扩展
- 最少的抽象层
- 清晰的职责划分

## 错误处理

所有组件在错误时抛出 `HttpException`：

```php
use nova\plugin\http\HttpClient;
use nova\plugin\http\HttpException;

try {
    $response = HttpClient::init()->autoProxy()->get()->send('https://example.com');
} catch (HttpException $e) {
    echo '请求失败: ' . $e->getMessage();
}
```
