<?php

declare(strict_types=1);

namespace Exp\NaviiData;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class HttpClient
{
    private Client $client;

    public function __construct(
        private string $userAgent,
        private string $baseUrl,
        private int $connectTimeout,
        private int $totalTimeout,
        private int $maxRetry,
        private Logger $logger,
        private CircuitBreaker $breaker,
    ) {
        $this->client = new Client([
            'base_uri'        => $baseUrl,
            'http_errors'     => false,
            'connect_timeout' => $connectTimeout,
            'timeout'         => $totalTimeout,
            'headers'         => [
                'User-Agent'      => $userAgent,
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ja,en;q=0.5',
                'Accept-Encoding' => 'gzip, deflate',
            ],
        ]);
    }

    /**
     * @param array<string, string|int> $query
     * @return array{status:int, body:string, bytes:int, duration_ms:int}
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path . ($query ? ('?' . http_build_query($query)) : '');
        $attempt = 0;
        $backoffSec = 60;

        while (true) {
            $attempt++;
            $start = microtime(true);
            $error = null;
            $resp = null;

            try {
                $resp = $this->client->get($path, [
                    RequestOptions::QUERY => $query,
                ]);
            } catch (ConnectException $e) {
                $error = 'connect: ' . $e->getMessage();
            } catch (RequestException $e) {
                $error = 'request: ' . $e->getMessage();
                if ($e->hasResponse()) {
                    $resp = $e->getResponse();
                }
            } catch (GuzzleException $e) {
                $error = 'guzzle: ' . $e->getMessage();
            }

            $durationMs = (int)round((microtime(true) - $start) * 1000);

            if ($resp !== null) {
                $status = $resp->getStatusCode();
                $body = (string)$resp->getBody();
                $bytes = strlen($body);
                $this->logger->recordFetch($url, $status, $bytes, $durationMs, $this->userAgent, $error);

                if ($status === 200) {
                    $this->breaker->recordSuccess();
                    return [
                        'status'      => $status,
                        'body'        => $body,
                        'bytes'       => $bytes,
                        'duration_ms' => $durationMs,
                    ];
                }

                if ($status === 429 || $status === 403) {
                    $this->breaker->trip("HTTP {$status} from {$url}");
                    throw new RuntimeException("HTTP {$status} -> stopped: {$url}");
                }

                if ($status >= 500 && $status < 600 && $attempt <= $this->maxRetry) {
                    $this->logger->warn("HTTP {$status} retry {$attempt}/{$this->maxRetry} after {$backoffSec}s: {$url}");
                    sleep($backoffSec);
                    $backoffSec *= 3;
                    continue;
                }

                $this->breaker->recordFailure("HTTP {$status}");
                throw new RuntimeException("HTTP {$status}: {$url}");
            }

            $this->logger->recordFetch($url, null, null, $durationMs, $this->userAgent, $error);

            if ($attempt <= $this->maxRetry) {
                $this->logger->warn("transport error retry {$attempt}/{$this->maxRetry} after {$backoffSec}s: {$error}");
                sleep($backoffSec);
                $backoffSec *= 3;
                continue;
            }

            $this->breaker->recordFailure((string)$error);
            throw new RuntimeException('transport error: ' . (string)$error);
        }
    }
}
