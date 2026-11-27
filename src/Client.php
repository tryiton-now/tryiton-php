<?php

declare(strict_types=1);

namespace TryItOn;

/**
 * Client for the TryItOn virtual try-on API.
 *
 * Example:
 *   $client = new \TryItOn\Client('YOUR_API_KEY');
 *   $jobId = $client->tryOnFashion([
 *       'model_image'   => 'https://example.com/model.jpg',
 *       'garment_image' => 'https://example.com/tshirt.jpg',
 *       'category'      => 'clothing',
 *       'subcategory'   => 'tops',
 *   ]);
 *   $urls = $client->waitForResult($jobId);
 *
 * Responses are plain arrays. Their shapes are declared below for PHPStan and
 * Psalm; import them with `@phpstan-import-type LiveSession from \TryItOn\Client`.
 *
 * @phpstan-type LiveGarment array{id: string, category: string, subcategory: string|null, description: string|null, prompt: string, enhancePrompt: bool, items: list<string>|null, createdAt: string}
 * @phpstan-type LiveSession array{id: string, status: 'active'|'ended', endReason: string|null, seconds: int, creditsCharged: int, endUserId: string|null, createdAt: string, endedAt: string|null}
 * @phpstan-type LiveSessionStart array{ok: true, session: LiveSession, clientSecret: string, connection: array{provider: string, model: string, token: string}, secondsPerCredit: int, maxSeconds: int, secondsLeft: int, garment: array{id: string, category: string, subcategory: string|null, description: string|null, prompt: string, enhancePrompt: bool, items: list<string>|null, createdAt: string, input: array{prompt: string, enhancePrompt: bool, referenceImage: string}}|null}
 *
 * @see https://docs.tryiton.now
 */
class Client
{
    use LiveTryOn;

    /**
     * SDK version, sent in the User-Agent header. Keep it in sync with the
     * release tag (vX.Y.Z) and CHANGELOG.md.
     */
    public const VERSION = '2.2.0';

    public const HAIRCUTS = [
        'Afro', 'BobCut', 'BowlCut', 'BoxBraids', 'BuzzCut', 'Chignon', 'CombOver',
        'CornrowBraids', 'CurlyBob', 'CurlyShag', 'DoubleBun', 'Dreadlocks', 'FauxHawk',
        'FishtailBraid', 'LongCurly', 'LongHairTiedUp', 'LongHimeCut', 'LongStraight',
        'LongTwintails', 'LongWavy', 'LongWavyCurtainBangs', 'ManBun', 'MessyTousled',
        'PixieCut', 'Pompadour', 'Ponytail', 'ShortCurlyPixie', 'ShortTwintails',
        'ShoulderLengthHair', 'Spiky', 'TexturedFringe', 'TwinBraids', 'Updo', 'WavyShag',
    ];

    private string $apiKey;
    private string $baseUrl;
    private HttpTransport $transport;

    /**
     * @param string $apiKey Your API key from https://tryiton.now/app/developer
     * @param string $baseUrl Override the API base URL.
     * @param int $timeout Per-request timeout in seconds for the default cURL transport.
     * @param HttpTransport|null $transport Send requests through your own transport
     *        (retries, logging, a proxy, a test stub). It owns its timeout, so
     *        $timeout is ignored when you pass one.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://tryiton.now/api/v1',
        int $timeout = 60,
        ?HttpTransport $transport = null
    ) {
        if ($apiKey === '') {
            throw new TryItOnException('An apiKey is required.', null, 'ConfigError');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? new CurlTransport($timeout);
    }

    /**
     * Put a garment or accessory on a person. Returns the job id.
     *
     * Accepts: model_image (required), garment_image (required), category,
     * subcategory, num_samples (1-4), output_format ("png"/"jpeg").
     *
     * Deprecated keys: "mode" is ignored by the API, and "moderation_level" is
     * validated but has no effect. Both will be removed in 3.0.
     *
     * @param array<string, mixed> $params
     */
    public function tryOnFashion(array $params): string
    {
        return self::stringField($this->request('POST', '/tryon/fashion', $params), 'jobId');
    }

    /**
     * Restyle a person's hair. Returns the job id.
     * Accepts: face_image (required), haircut (required), hair_color,
     * num_samples (1-4), output_format ("png"/"jpeg").
     *
     * @param array<string, mixed> $params
     */
    public function tryOnHairstyle(array $params): string
    {
        return self::stringField($this->request('POST', '/tryon/hairstyle', $params), 'jobId');
    }

    /**
     * Ink a design onto skin. Returns the job id.
     * Accepts: body_image (required), design_image (required), placement,
     * region, num_samples (1-4), output_format ("png"/"jpeg").
     *
     * `region` is ['x', 'y', 'w', 'h'] normalized 0-1 from the top-left, each side
     * >= 0.06; with it, `placement` only describes size/style.
     *
     * @param array<string, mixed> $params
     */
    public function tryOnTattoo(array $params): string
    {
        return self::stringField($this->request('POST', '/tryon/tattoo', $params), 'jobId');
    }

    /**
     * Fetch the current status of a job.
     *
     * `output` holds the result image URLs once completed (they expire after
     * 72h). `error` is why the job failed, or null: the API sends a plain
     * string; the array form is the older shape, kept for code written
     * against it. waitForResult() turns either into a TryItOnException.
     *
     * @return array{status: 'processing'|'completed'|'failed', output: list<string>, error: string|array{name?: string, message?: string}|null}
     */
    public function getStatus(string $jobId): array
    {
        $data = $this->request('GET', '/status/' . rawurlencode($jobId));

        /** @var array{status: 'processing'|'completed'|'failed', output: list<string>, error: string|array{name?: string, message?: string}|null} $status */
        $status = [
            'status' => $data['status'] ?? 'processing',
            'output' => $data['output'] ?? [],
            'error' => $data['error'] ?? null,
        ];

        return $status;
    }

    /**
     * Fetch your current credit balance.
     *
     * @return array{on_demand: int, subscription: int, purchased: int, reserved: int}
     */
    public function getCredits(): array
    {
        /** @var array{on_demand: int, subscription: int, purchased: int, reserved: int} $credits */
        $credits = self::arrayField($this->request('GET', '/credits'), 'credits');

        return $credits;
    }

    /**
     * Poll a job until it completes, then return the output image URLs.
     * Throws TryItOnException if the job fails (errorName "ProcessingError",
     * the job's own reason as the message, null status) or the timeout is
     * reached (errorName "Timeout").
     *
     * @param float $pollInterval Seconds between polls; 0 polls back to back.
     * @param float $timeout Seconds to wait before giving up.
     * @return list<string>
     */
    public function waitForResult(string $jobId, float $pollInterval = 2.0, float $timeout = 120.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $status = $this->getStatus($jobId);
            if ($status['status'] === 'completed') {
                return $status['output'];
            }
            if ($status['status'] === 'failed') {
                throw self::jobFailure($status['error']);
            }
            if (microtime(true) > $deadline) {
                throw new TryItOnException("Timed out waiting for job {$jobId}.", null, 'Timeout');
            }
            usleep((int) ($pollInterval * 1_000_000));
        }
    }

    /**
     * Send one request and return the decoded JSON body.
     * Throws TryItOnException on a network failure or a non-2xx response.
     *
     * @param 'GET'|'POST' $method
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'tryiton-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $payload = self::encodeBody($body);
            $headers['Content-Type'] = 'application/json';
        }

        try {
            $response = $this->transport->send($method, $this->baseUrl . $path, $headers, $payload);
        } catch (TryItOnException $e) {
            throw $e;
        } catch (\Exception $e) {
            // A custom transport's own exception (e.g. from a PSR-18 client): same contract as a cURL failure.
            throw new TryItOnException('Network error: ' . $e->getMessage(), null, 'NetworkError', $e);
        }
        $statusCode = $response['status'];

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            // API envelope: { ok: false, error: { name, message } }. Older shapes put strings at the top level.
            $apiError = isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
            $message = $apiError['message'] ?? $data['message'] ?? null;
            $name = $apiError['name'] ?? $data['error'] ?? null;
            throw new TryItOnException(
                is_string($message) && $message !== '' ? $message : "HTTP {$statusCode}",
                $statusCode,
                is_string($name) ? $name : null
            );
        }

        return $data;
    }

    /**
     * The JSON request body, with null (unset) options left out.
     *
     * @param array<string, mixed> $body
     */
    private static function encodeBody(array $body): string
    {
        $fields = array_filter($body, static fn ($v): bool => $v !== null);
        // json_encode([]) is "[]", but the API expects a JSON object.
        if ($fields === []) {
            return '{}';
        }

        $json = json_encode($fields);
        if ($json === false) {
            // e.g. a string that isn't valid UTF-8. Without this, an empty body would be sent.
            throw new TryItOnException(
                'Could not encode the request as JSON: ' . json_last_error_msg(),
                null,
                'InvalidRequest'
            );
        }

        return $json;
    }

    /**
     * The job's failure reason as an exception. GET /status sends it as a
     * plain string; { name, message } is the older shape.
     *
     * @param string|array{name?: string, message?: string}|null $error
     */
    private static function jobFailure($error): TryItOnException
    {
        if (is_string($error) && $error !== '') {
            return new TryItOnException($error, null, 'ProcessingError');
        }
        if (is_array($error)) {
            return new TryItOnException($error['message'] ?? 'Try-on failed.', null, $error['name'] ?? 'ProcessingError');
        }

        return new TryItOnException('Try-on failed.', null, 'ProcessingError');
    }

    /**
     * A string field of a 2xx response.
     *
     * @param array<mixed> $data
     */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw self::unexpectedResponse($key);
        }

        return $value;
    }

    /**
     * An object field of a 2xx response, as an array.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function arrayField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            throw self::unexpectedResponse($key);
        }

        return $value;
    }

    /**
     * A 2xx response without the field the method returns (e.g. an HTML page
     * from a proxy). Thrown instead of letting a TypeError escape.
     */
    private static function unexpectedResponse(string $key): TryItOnException
    {
        return new TryItOnException("Unexpected API response: no \"{$key}\" field.", null, 'InvalidResponse');
    }

    /**
     * A param by its camelCase key, falling back to the snake_case one.
     *
     * @param array<string, mixed> $params
     * @return mixed
     */
    private static function param(array $params, string $camel, string $snake)
    {
        return $params[$camel] ?? $params[$snake] ?? null;
    }
}
