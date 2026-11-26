<?php

declare(strict_types=1);

namespace TryItOn\Tests;

use TryItOn\HttpTransport;

/**
 * Replies with canned responses in order and records every request.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{status: int, body: string}> */
    private array $responses;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /**
     * @param list<array{status: int, body: string}> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $response = array_shift($this->responses);
        if ($response === null) {
            // An \Error, not an \Exception, so the Client doesn't wrap it into a NetworkError.
            throw new \Error("Unexpected request #" . count($this->requests) . ": {$method} {$url}");
        }

        return $response;
    }
}
