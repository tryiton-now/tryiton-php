<?php

declare(strict_types=1);

namespace TryItOn\Tests;

use PHPUnit\Framework\TestCase;
use TryItOn\Client;
use TryItOn\TryItOnException;

/**
 * Runs the shared SDK contract (tests/fixtures/sdk-cases.json) against this SDK.
 *
 * The fixture is generated: edit tryiton/backend/api-contract/sdk-cases.json and
 * run `node tryiton-sdk/sync-contract.mjs` instead. Canonical names are
 * camelCase; call() maps them to the PHP methods and their snake_case keys.
 */
final class ContractTest extends TestCase
{
    /** Methods that also accept camelCase param keys; their cases run both ways. */
    private const CAMEL_CASE_METHODS = ['prepareLiveGarment', 'startLiveSession'];

    /** @var \stdClass|null */
    private static $fixture;

    /**
     * @return iterable<string, array{\stdClass, bool}>
     */
    public static function methodCases(): iterable
    {
        foreach (self::fixture()->methodCases as $case) {
            yield $case->name => [$case, false];
            if (in_array($case->method, self::CAMEL_CASE_METHODS, true) && self::toArray($case->params) !== []) {
                yield $case->name . ' (camelCase keys)' => [$case, true];
            }
        }
    }

    /**
     * @dataProvider methodCases
     */
    public function testMethodCase(\stdClass $case, bool $camelCaseKeys): void
    {
        $transport = new FakeTransport(array_map(static fn (\stdClass $r): array => self::response($r), $case->responses));
        $client = self::client($transport);

        $result = null;
        $error = null;
        try {
            $result = self::call($client, $case->method, self::toArray($case->params), $camelCaseKeys);
        } catch (TryItOnException $e) {
            $error = $e;
        }

        self::assertRequests($case->expectRequests, $transport->requests);

        if (property_exists($case, 'expectError')) {
            self::assertNotNull($error, 'Expected a TryItOnException.');
            self::assertError($case->expectError, $error);

            return;
        }
        if ($error !== null) {
            throw $error;
        }

        $expected = property_exists($case, 'expectResultIsResponseBody') && $case->expectResultIsResponseBody
            ? self::toArray($case->responses[count($case->responses) - 1]->body)
            : self::toArray($case->expectResult);
        self::assertSame(self::normalize($expected), self::normalize($result));
    }

    /**
     * @return iterable<string, array{\stdClass, string}>
     */
    public static function errorCases(): iterable
    {
        foreach (self::fixture()->errorCases as $case) {
            foreach (['getCredits', 'getLiveSession'] as $method) {
                yield "{$case->name} via {$method}" => [$case, $method];
            }
        }
    }

    /**
     * @dataProvider errorCases
     */
    public function testErrorCase(\stdClass $case, string $method): void
    {
        $transport = new FakeTransport([self::response($case->response)]);
        $client = self::client($transport);

        try {
            if ($method === 'getCredits') {
                $client->getCredits();
            } else {
                $client->getLiveSession('s1');
            }
            self::fail('Expected a TryItOnException.');
        } catch (TryItOnException $e) {
            self::assertError($case->expectError, $e);
        }
        self::assertCount(1, $transport->requests);
    }

    public function testUserAgentMatchesTheContractPattern(): void
    {
        self::assertMatchesRegularExpression(
            '~' . self::fixture()->userAgent->pattern . '~',
            'tryiton-php/' . Client::VERSION
        );
    }

    /**
     * Call a canonical method on the PHP client.
     *
     * @param array<string, mixed> $params Canonical (camelCase) params.
     * @return mixed
     */
    private static function call(Client $client, string $method, array $params, bool $camelCaseKeys)
    {
        $options = $camelCaseKeys ? $params : self::snakeKeys($params);
        switch ($method) {
            case 'tryOnFashion':
                return $client->tryOnFashion($options);
            case 'tryOnHairstyle':
                return $client->tryOnHairstyle($options);
            case 'tryOnTattoo':
                return $client->tryOnTattoo($options);
            case 'getStatus':
                return $client->getStatus($params['jobId']);
            case 'waitForResult':
                // The smallest interval waitForResult accepts: poll back to back.
                return $client->waitForResult($params['jobId'], 0.0);
            case 'getCredits':
                return $client->getCredits();
            case 'getLiveConfig':
                return $client->getLiveConfig();
            case 'prepareLiveGarment':
                return $client->prepareLiveGarment($options);
            case 'startLiveSession':
                return $client->startLiveSession($options);
            case 'getLiveSession':
                return $client->getLiveSession($params['sessionId']);
            case 'stopLiveSession':
                return $client->stopLiveSession($params['sessionId']);
        }
        self::fail("No PHP mapping for the canonical method \"{$method}\". Add it to ContractTest::call().");
    }

    /**
     * @param list<\stdClass> $expected
     * @param list<array{method: string, url: string, headers: array<string, string>, body: ?string}> $actual
     */
    private static function assertRequests(array $expected, array $actual): void
    {
        $fixture = self::fixture();
        self::assertCount(count($expected), $actual, 'Number of requests sent.');

        foreach ($expected as $i => $exp) {
            $req = $actual[$i];
            self::assertSame($exp->method, $req['method'], "Request #{$i} method.");
            self::assertStringStartsWith($fixture->baseUrl, $req['url'], "Request #{$i} base URL.");
            self::assertSame($exp->path, substr($req['url'], strlen($fixture->baseUrl)), "Request #{$i} path.");
            self::assertSame($fixture->authHeader, $req['headers']['Authorization'] ?? null, "Request #{$i} Authorization.");
            self::assertSame('tryiton-php/' . Client::VERSION, $req['headers']['User-Agent'] ?? null, "Request #{$i} User-Agent.");

            if (property_exists($exp, 'body')) {
                self::assertNotNull($req['body'], "Request #{$i} has a body.");
                self::assertSame('application/json', $req['headers']['Content-Type'] ?? null, "Request #{$i} Content-Type.");
                // Objects stay stdClass here, so {} must not arrive as [] ...
                self::assertEquals($exp->body, json_decode($req['body'], false, 512, JSON_THROW_ON_ERROR), "Request #{$i} body.");
                // ... and the strict comparison catches type changes ("2" for 2).
                self::assertSame(
                    self::normalize(self::toArray($exp->body)),
                    self::normalize(json_decode($req['body'], true, 512, JSON_THROW_ON_ERROR)),
                    "Request #{$i} body types."
                );
            } elseif ($exp->method === 'GET') {
                self::assertNull($req['body'], "Request #{$i} (GET) has no body.");
                self::assertArrayNotHasKey('Content-Type', $req['headers'], "Request #{$i} (GET) Content-Type.");
            }
        }
    }

    private static function assertError(\stdClass $expected, TryItOnException $error): void
    {
        // HTTP errors carry the status; runtime failures (a failed job, a timeout) have a null status.
        self::assertSame($expected->status, $error->status, 'Error status.');
        self::assertSame($expected->errorName, $error->errorName, 'Error name.');
        if (property_exists($expected, 'messageAnyOf')) {
            self::assertContains($error->getMessage(), $expected->messageAnyOf, 'Error message.');
        } else {
            self::assertSame($expected->message, $error->getMessage(), 'Error message.');
        }
    }

    private static function client(FakeTransport $transport): Client
    {
        $fixture = self::fixture();

        return new Client($fixture->apiKey, $fixture->baseUrl, 60, $transport);
    }

    /**
     * @return array{status: int, body: string}
     */
    private static function response(\stdClass $response): array
    {
        return [
            'status' => $response->status,
            'body' => property_exists($response, 'raw') ? $response->raw : json_encode($response->body, JSON_THROW_ON_ERROR),
        ];
    }

    private static function fixture(): \stdClass
    {
        if (self::$fixture === null) {
            $json = file_get_contents(__DIR__ . '/fixtures/sdk-cases.json');
            self::assertIsString($json, 'Could not read tests/fixtures/sdk-cases.json.');
            self::$fixture = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        }

        return self::$fixture;
    }

    /**
     * A decoded fixture value (stdClass objects) as plain PHP arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function toArray($value)
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Top-level camelCase keys to snake_case (modelImage => model_image).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function snakeKeys(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $out[strtolower((string) preg_replace('/[A-Z]/', '_$0', $key))] = $value;
        }

        return $out;
    }

    /**
     * Sort string-keyed arrays recursively so comparisons ignore key order.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::normalize($item);
        }
        if ($out !== [] && array_keys($out) !== range(0, count($out) - 1)) {
            ksort($out);
        }

        return $out;
    }
}
