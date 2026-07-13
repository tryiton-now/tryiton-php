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
 * @see https://docs.tryiton.now
 */
class Client
{
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
    private int $timeout;

    public function __construct(string $apiKey, string $baseUrl = 'https://tryiton.now/api/v1', int $timeout = 60)
    {
        if ($apiKey === '') {
            throw new TryItOnException('An apiKey is required.', null, 'ConfigError');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Put a garment or accessory on a person. Returns the job id.
     *
     * Accepts: model_image (required), garment_image (required), category,
     * subcategory, mode, num_samples (1-4), output_format ("png"/"jpeg"),
     * moderation_level ("conservative"/"permissive"/"none").
     *
     * @param array<string, mixed> $params
     */
    public function tryOnFashion(array $params): string
    {
        return $this->request('POST', '/tryon/fashion', $params)['jobId'];
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
        return $this->request('POST', '/tryon/hairstyle', $params)['jobId'];
    }

    /**
     * Ink a design onto skin. Returns the job id.
     * Accepts: body_image (required), design_image (required), placement,
     * region, num_samples (1-4), output_format ("png"/"jpeg").
     *
     * Position the ink two ways, and they compose: `placement` is free text
     * ("on the right forearm, small"); `region` pins the exact spot as
     * ['x' => .., 'y' => .., 'w' => .., 'h' => ..], normalized 0-1 from the
     * body image's top-left corner, each side at least 0.06. With a region,
     * `placement` only describes size/style.
     *
     * @param array<string, mixed> $params
     */
    public function tryOnTattoo(array $params): string
    {
        return $this->request('POST', '/tryon/tattoo', $params)['jobId'];
    }

    /**
     * Fetch the current status of a job.
     *
     * @return array{status: string, output: array<string>, error: ?array{name: string, message: string}}
     */
    public function getStatus(string $jobId): array
    {
        $data = $this->request('GET', '/status/' . rawurlencode($jobId));

        return [
            'status' => $data['status'] ?? 'processing',
            'output' => $data['output'] ?? [],
            'error' => $data['error'] ?? null,
        ];
    }

    /**
     * Fetch your current credit balance.
     *
     * @return array{on_demand: int, subscription: int, purchased: int, reserved: int}
     */
    public function getCredits(): array
    {
        return $this->request('GET', '/credits')['credits'];
    }

    /**
     * Poll a job until it completes, then return the output image URLs.
     * Throws TryItOnException if the job fails or the timeout is reached.
     *
     * @return array<string>
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
                $err = $status['error'] ?? [];
                throw new TryItOnException(
                    $err['message'] ?? 'Try-on failed.',
                    null,
                    $err['name'] ?? 'ProcessingError'
                );
            }
            if (microtime(true) > $deadline) {
                throw new TryItOnException("Timed out waiting for job {$jobId}.", null, 'Timeout');
            }
            usleep((int) ($pollInterval * 1_000_000));
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization: Bearer ' . $this->apiKey];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode(array_filter($body, static fn ($v) => $v !== null));
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $msg = curl_error($ch);
            curl_close($ch);
            throw new TryItOnException("Network error: {$msg}", null, 'NetworkError');
        }
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = [];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new TryItOnException(
                $data['message'] ?? "HTTP {$statusCode}",
                $statusCode,
                $data['error'] ?? null
            );
        }

        return $data;
    }
}
