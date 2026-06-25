<?php

declare(strict_types=1);

namespace nova\plugin\http;

use CURLFile;
use nova\framework\core\Context;
use nova\framework\core\Logger;

/**
 * HTTP 请求调试日志。
 */
final class HttpRequestLogger
{
    public static function log(HttpClient $client, string $url, array $headers, bool $stream = false): void
    {
        if (!Context::instance()->isDebug()) {
            return;
        }

        $method = self::resolveMethod($client);
        $headersString = join("\n", $headers);
        $body = self::formatBody($client->getOpt(CURLOPT_POSTFIELDS));
        $tag = $stream ? '(stream) ' : '';

        $rawReq = <<<EOF

>>> REQUEST START {$tag}>>>
{$method} {$url}
{$headersString}

{$body}
>>> REQUEST END {$tag}>>>
EOF;

        Logger::info($rawReq);
    }

    private static function resolveMethod(HttpClient $client): string
    {
        $method = $client->getOpt(CURLOPT_CUSTOMREQUEST);
        if (!empty($method)) {
            return (string)$method;
        }

        return $client->getOpt(CURLOPT_HTTPGET) ? 'GET' : 'POST';
    }

    private static function formatBody(mixed $body): string
    {
        if (!is_array($body)) {
            return (string)($body ?? '');
        }

        $parts = [];
        foreach ($body as $key => $value) {
            if ($value instanceof CURLFile) {
                $parts[] = "$key: [FILE] " . $value->getFilename();
            } else {
                $parts[] = "$key: $value";
            }
        }

        return implode("\n", $parts);
    }
}
