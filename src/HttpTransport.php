<?php

declare(strict_types=1);

namespace TryItOn;

/**
 * Sends one HTTP request for the Client and returns the raw response.
 *
 * The default is CurlTransport. Pass your own as the Client's last
 * constructor argument to add retries, logging or a proxy, to route through
 * a PSR-18 client, or to stub the API in tests.
 *
 * A transport only moves bytes: it returns every HTTP status (4xx and 5xx
 * included) and leaves JSON parsing and API errors to the Client. On a
 * network failure it throws TryItOnException with a null status and the
 * errorName "NetworkError". Any other \Exception it throws reaches the caller
 * wrapped in such a TryItOnException (the original is getPrevious()).
 */
interface HttpTransport
{
    /**
     * @param non-empty-string $method "GET" or "POST".
     * @param string $url Absolute URL; path segments are already escaped.
     * @param array<string, string> $headers Header name => value.
     * @param string|null $body The JSON request body, or null to send none.
     * @return array{status: int, body: string} The HTTP status code and the raw response body (possibly empty).
     * @throws TryItOnException On a network failure or timeout.
     * @throws \Exception A custom transport may throw anything; the Client wraps it as NetworkError.
     */
    public function send(string $method, string $url, array $headers, ?string $body): array;
}
