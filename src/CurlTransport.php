<?php

declare(strict_types=1);

namespace TryItOn;

/**
 * The default HttpTransport: one cURL handle per request (ext-curl).
 */
final class CurlTransport implements HttpTransport
{
    private int $timeout;

    /**
     * @param int $timeout Whole-request timeout in seconds (CURLOPT_TIMEOUT); 0 waits forever.
     */
    public function __construct(int $timeout = 60)
    {
        $this->timeout = $timeout;
    }

    /**
     * @param non-empty-string $method
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws TryItOnException
     */
    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new TryItOnException('Network error: could not initialize cURL.', null, 'NetworkError');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // No curl_close(): the handle is freed when it goes out of scope (PHP 7 and 8),
        // and curl_close() is deprecated as of PHP 8.5.
        $raw = curl_exec($ch);
        if ($raw === false) {
            // Timeouts land here too (CURLE_OPERATION_TIMEDOUT), as "Network error: Operation timed out ...".
            throw new TryItOnException('Network error: ' . curl_error($ch), null, 'NetworkError');
        }

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'body' => (string) $raw,
        ];
    }
}
