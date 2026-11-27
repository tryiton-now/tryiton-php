<?php

declare(strict_types=1);

namespace TryItOn;

/**
 * Live (real-time webcam) try-on endpoints of Client. Split out of Client.php
 * to keep it small; call them on a Client, not on this trait.
 *
 * @internal
 *
 * @phpstan-import-type LiveGarment from Client
 * @phpstan-import-type LiveSession from Client
 * @phpstan-import-type LiveSessionStart from Client
 */
trait LiveTryOn
{
    /**
     * Live (real-time webcam) try-on availability and pricing for your account.
     *
     * Live try-on is server side only here: your server prepares garments and
     * starts sessions with the API key; the end user's browser streams with the
     * `tryiton-live` npm package, using the startLiveSession() response
     * (session-scoped credentials, never your API key).
     *
     * @return array{enabled: bool, secondsPerCredit: int, maxSeconds: int, minCredits: int, maxConcurrentSessions: int}
     */
    public function getLiveConfig(): array
    {
        $data = $this->request('GET', '/live/config');
        unset($data['ok']);

        /** @var array{enabled: bool, secondsPerCredit: int, maxSeconds: int, minCredits: int, maxConcurrentSessions: int} $config */
        $config = $data;

        return $config;
    }

    /**
     * Prepare a garment for live try-on. Returns the garment
     * (id, category, subcategory, description, prompt, enhancePrompt, items,
     * createdAt).
     *
     * Do it once per product, ahead of time (e.g. at catalog sync): it's cached
     * per image and category, so starting a session or switching garments later
     * is instant.
     *
     * Accepts: garment_image (required; URL or base64 data URL, a product photo
     * on a plain background works best), category (recommended; omit to let it
     * be detected), subcategory (e.g. "tops" for clothing).
     *
     * Optional overrides: detail_images (up to 3 extra views, URLs or data
     * URLs, that only sharpen the description), description (your item
     * description, max 200 characters, used inside the generated prompt),
     * instructions (your own prompt, max 1000 characters, used verbatim instead
     * of the generated one), enhance_prompt (false keeps the prompt as-is;
     * default true). camelCase keys (garmentImage, detailImages, ...) work too.
     *
     * @param array<string, mixed> $params
     * @return LiveGarment
     */
    public function prepareLiveGarment(array $params): array
    {
        $body = [
            'garment_image' => self::param($params, 'garmentImage', 'garment_image'),
            'category' => $params['category'] ?? null,
            'subcategory' => $params['subcategory'] ?? null,
            'detail_images' => self::param($params, 'detailImages', 'detail_images'),
            'description' => $params['description'] ?? null,
            'instructions' => $params['instructions'] ?? null,
            'enhance_prompt' => self::param($params, 'enhancePrompt', 'enhance_prompt'),
        ];

        /** @var LiveGarment $garment */
        $garment = self::arrayField($this->request('POST', '/live/garments', $body), 'garment');

        return $garment;
    }

    /**
     * Prepare an outfit: 2 or 3 prepared garments (e.g. a top, trousers and
     * shoes) worn together. Returns the outfit, shaped like a garment with
     * category "outfit" and items (its garment ids, top to bottom). Its id
     * works wherever a garment id does (startLiveSession, the browser's
     * switchGarment). Cached per set of garments and overrides, in any order.
     *
     * Accepts: garment_ids (required; 2-3 distinct prepared garment ids),
     * instructions (your own prompt, max 1000 characters; the items' own
     * instructions don't carry over), enhance_prompt (false keeps the prompt
     * as-is; default true). camelCase keys (garmentIds, ...) work too.
     *
     * @param array<string, mixed> $params
     * @return LiveGarment
     */
    public function prepareLiveOutfit(array $params): array
    {
        $body = [
            'garment_ids' => self::param($params, 'garmentIds', 'garment_ids'),
            'instructions' => $params['instructions'] ?? null,
            'enhance_prompt' => self::param($params, 'enhancePrompt', 'enhance_prompt'),
        ];

        /** @var LiveGarment $outfit */
        $outfit = self::arrayField($this->request('POST', '/live/outfits', $body), 'outfit');

        return $outfit;
    }

    /**
     * Start a live session for one end user. Holds the first credit.
     *
     * Send the result to the browser unchanged (e.g. json_encode() it in your
     * response); it streams with `connectLiveTryOn` from the `tryiton-live` npm
     * package. Contains session, clientSecret, connection, secondsPerCredit,
     * maxSeconds, secondsLeft and garment (with its stream input, or null).
     *
     * Accepts: garment_id (a prepared garment or outfit to start with),
     * max_seconds (cap this session, 10..maxSeconds), end_user_id (your id for
     * the end user: one stream per end user, a restart ends their previous
     * one), allowed_origin (lock the media token to your site, e.g.
     * "https://shop.example.com"). camelCase keys (garmentId, ...) work too.
     *
     * @param array<string, mixed> $params
     * @return LiveSessionStart
     */
    public function startLiveSession(array $params = []): array
    {
        $body = [
            'garment_id' => self::param($params, 'garmentId', 'garment_id'),
            'max_seconds' => self::param($params, 'maxSeconds', 'max_seconds'),
            'end_user_id' => self::param($params, 'endUserId', 'end_user_id'),
            'allowed_origin' => self::param($params, 'allowedOrigin', 'allowed_origin'),
        ];

        /** @var LiveSessionStart $start */
        $start = $this->request('POST', '/live/sessions', $body);

        return $start;
    }

    /**
     * Status and usage of a live session (id, status, endReason, seconds,
     * creditsCharged, endUserId, createdAt, endedAt).
     *
     * @return LiveSession
     */
    public function getLiveSession(string $sessionId): array
    {
        /** @var LiveSession $session */
        $session = self::arrayField($this->request('GET', '/live/sessions/' . rawurlencode($sessionId)), 'session');

        return $session;
    }

    /**
     * End a live session from your server (e.g. the user left). Idempotent.
     * Returns the ended session.
     *
     * @return LiveSession
     */
    public function stopLiveSession(string $sessionId): array
    {
        /** @var LiveSession $session */
        $session = self::arrayField(
            $this->request('POST', '/live/sessions/' . rawurlencode($sessionId) . '/stop', []),
            'session'
        );

        return $session;
    }
}
