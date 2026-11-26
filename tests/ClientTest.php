<?php

declare(strict_types=1);

namespace TryItOn\Tests;

use PHPUnit\Framework\TestCase;
use TryItOn\Client;
use TryItOn\CurlTransport;
use TryItOn\HttpTransport;
use TryItOn\TryItOnException;

/**
 * SDK behavior beyond the shared contract: construction, the transport seam,
 * and failures the contract fixture can't express.
 */
final class ClientTest extends TestCase
{
    public function testExistingConstructorCallsStillWork(): void
    {
        $default = new Client('key');
        $custom = new Client('key', 'https://example.test/api/v1/', 5);

        self::assertInstanceOf(CurlTransport::class, self::privateProperty($default, 'transport'));
        self::assertSame(60, self::privateProperty(self::privateProperty($default, 'transport'), 'timeout'));
        self::assertSame(5, self::privateProperty(self::privateProperty($custom, 'transport'), 'timeout'));
        self::assertSame('https://example.test/api/v1', self::privateProperty($custom, 'baseUrl'));
    }

    public function testAnEmptyApiKeyIsAConfigError(): void
    {
        try {
            new Client('');
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertSame('ConfigError', $e->errorName);
            self::assertNull($e->status);
        }
    }

    public function testACurlFailureIsANetworkError(): void
    {
        // Nothing listens on port 1, so the connection is refused straight away.
        $client = new Client('key', 'http://127.0.0.1:1/api/v1', 5);

        try {
            $client->getCredits();
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertSame('NetworkError', $e->errorName);
            self::assertNull($e->status);
            self::assertStringStartsWith('Network error: ', $e->getMessage());
        }
    }

    public function testATransportsOwnExceptionIsWrappedAsANetworkError(): void
    {
        $cause = new \RuntimeException('connection reset');
        $transport = new class ($cause) implements HttpTransport {
            /** @var \RuntimeException */
            private $cause;

            public function __construct(\RuntimeException $cause)
            {
                $this->cause = $cause;
            }

            public function send(string $method, string $url, array $headers, ?string $body): array
            {
                throw $this->cause;
            }
        };

        try {
            (new Client('key', 'https://api.test/api/v1', 60, $transport))->getCredits();
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertSame('NetworkError', $e->errorName);
            self::assertNull($e->status);
            self::assertSame('Network error: connection reset', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }

    public function testABodyThatIsNotValidJsonIsRejectedBeforeSending(): void
    {
        $transport = new FakeTransport([]);
        $client = new Client('key', 'https://api.test/api/v1', 60, $transport);

        try {
            $client->tryOnFashion(['model_image' => "\xB1\x31", 'garment_image' => 'https://example.com/shirt.jpg']);
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertSame('InvalidRequest', $e->errorName);
            self::assertNull($e->status);
        }
        self::assertSame([], $transport->requests);
    }

    public function testASuccessWithoutTheExpectedFieldIsAnInvalidResponse(): void
    {
        $transport = new FakeTransport([['status' => 200, 'body' => '<html>captive portal</html>']]);
        $client = new Client('key', 'https://api.test/api/v1', 60, $transport);

        try {
            $client->tryOnFashion(['model_image' => 'a', 'garment_image' => 'b']);
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertSame('InvalidResponse', $e->errorName);
            self::assertNull($e->status);
        }
    }

    public function testWaitForResultTimesOut(): void
    {
        $processing = ['status' => 200, 'body' => '{"ok":true,"status":"processing","output":[],"error":null}'];
        $client = new Client('key', 'https://api.test/api/v1', 60, new FakeTransport(array_fill(0, 50, $processing)));

        try {
            $client->waitForResult('job_1', 0.0, 0.0);
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertSame('Timeout', $e->errorName);
            self::assertNull($e->status);
        }
    }

    public function testWaitForResultStillReadsTheOlderErrorObject(): void
    {
        $failed = ['status' => 200, 'body' => '{"ok":true,"status":"failed","output":[],"error":{"name":"BadInput","message":"No face found."}}'];
        $client = new Client('key', 'https://api.test/api/v1', 60, new FakeTransport([$failed]));

        try {
            $client->waitForResult('job_1', 0.0);
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertSame('BadInput', $e->errorName);
            self::assertSame('No face found.', $e->getMessage());
            self::assertNull($e->status);
        }
    }

    public function testTheVersionHasAChangelogEntry(): void
    {
        $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
        self::assertIsString($changelog);
        self::assertStringContainsString('## [' . Client::VERSION . ']', $changelog, 'Add the release to CHANGELOG.md.');
    }

    /**
     * @return mixed
     */
    private static function privateProperty(object $object, string $name)
    {
        $property = new \ReflectionProperty($object, $name);
        if (PHP_VERSION_ID < 80100) {
            // Needed before PHP 8.1; a no-op since, and deprecated as of 8.5.
            $property->setAccessible(true);
        }

        return $property->getValue($object);
    }
}
