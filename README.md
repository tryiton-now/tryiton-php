# TryItOn PHP SDK — AI Virtual Try-On API

Official PHP client for the [TryItOn](https://tryiton.now) virtual try-on API. Add photoreal AI virtual try-on for clothing, accessories, hairstyles, and tattoos to your PHP or Laravel application with a few lines of code.

- Virtual clothing try-on and accessory try-on (eyewear, footwear, headwear, jewelry)
- Hairstyle and tattoo try-on
- PSR-4 autoloading, no third-party dependencies (uses cURL), with a built-in job polling helper

Full API reference: [docs.tryiton.now](https://docs.tryiton.now) · Get an API key: [tryiton.now/app/developer](https://tryiton.now/app/developer)

## Installation

```bash
composer require tryiton/tryiton
```

Requires PHP 7.4 or later with the `curl` and `json` extensions.

## Quickstart: run a virtual try-on

Submit a garment and a model photo, then wait for the generated result image.

```php
<?php
require 'vendor/autoload.php';

use TryItOn\Client;

$client = new Client(getenv('TRYITON_API_KEY'));

// Submit a clothing try-on
$jobId = $client->tryOnFashion([
    'model_image'   => 'https://example.com/model.jpg',
    'garment_image' => 'https://example.com/tshirt.jpg',
    'category'      => 'clothing',
    'subcategory'   => 'tops',
]);

// Poll until the job completes and return the output image URL(s)
$urls = $client->waitForResult($jobId);
echo $urls[0]; // CDN URL, available for 72 hours
```

Image inputs accept a public URL or a base64 data URL (`data:image/png;base64,...`).

## Core parameters

`tryOnFashion` covers clothing and accessory try-on. The most important parameters:

| Parameter | Type | Required | Description |
| --------- | ---- | -------- | ----------- |
| `model_image` | string | Yes | URL or base64 data URL of the person. |
| `garment_image` | string | Yes | URL or base64 data URL of the garment or accessory. |
| `category` | string | No | Item type: `auto`, `clothing`, `eyewear`, `footwear`, `headwear`, `jewelry`, `accessories`, or `others`. `auto` detects it for you. |
| `subcategory` | string | No | Required for `clothing` (`tops`, `bottoms`, `dresses`), `jewelry`, and `accessories`. |

Additional options (`mode` and `moderation_level` for clothing; `num_samples` 1–4 and `output_format` `png`/`jpeg` for every try-on, including hairstyle and tattoo) are documented in the [API reference](https://docs.tryiton.now).

## Other endpoints

```php
// Hairstyle try-on (see Client::HAIRCUTS for all supported values)
$client->tryOnHairstyle([
    'face_image' => $faceUrl,
    'haircut'    => 'BuzzCut',
    'hair_color' => 'ash blonde',
]);

// Tattoo try-on — place it with free text, or pin the exact spot with a
// region box (normalized 0-1, from the image's top-left corner).
$client->tryOnTattoo([
    'body_image'   => $bodyUrl,
    'design_image' => $designUrl,
    'placement'    => 'on the right forearm, small',
    // 'region'    => ['x' => 0.32, 'y' => 0.18, 'w' => 0.28, 'h' => 0.34],
]);

// Poll a job manually, or check your credit balance
$status  = $client->getStatus($jobId);  // ['status' => ..., 'output' => [...], 'error' => ...]
$credits = $client->getCredits();        // ['on_demand' => ..., 'subscription' => ..., 'purchased' => ..., 'reserved' => ...]
```

## Live try-on (real-time webcam)

Your server prepares garments and starts sessions with the API key. The shopper's browser streams with the
[`tryiton-live`](https://www.npmjs.com/package/tryiton-live) npm package, using only the session-scoped response.

```php
// Once per product, e.g. at catalog sync (cached per image + category):
$garment = $client->prepareLiveGarment([
    'garment_image' => $productImageUrl,
    'category'      => 'clothing',
    'subcategory'   => 'tops',
]);

// When a shopper opens live try-on. Return $start to the browser as-is:
$start = $client->startLiveSession([
    'garment_id'     => $garment['id'],
    'end_user_id'    => $shopper->id,               // one stream per end user
    'allowed_origin' => 'https://shop.example.com',  // locks the media token to your site
]);
header('Content-Type: application/json');
echo json_encode($start);

$client->getLiveSession($start['session']['id']);   // ['status' => ..., 'seconds' => ..., 'creditsCharged' => ..., ...]
$client->stopLiveSession($start['session']['id']);  // end it from your server
$client->getLiveConfig();                           // ['enabled' => ..., 'secondsPerCredit' => ..., 'maxSeconds' => ..., ...]
```

Billing is per `secondsPerCredit` seconds of streamed video, from the first try-on frame. Garment switches during a
session are free.

`prepareLiveGarment()` also takes optional overrides:

```php
$client->prepareLiveGarment([
    'garment_image'  => $productImageUrl,
    'category'       => 'footwear',
    'detail_images'  => [$sideViewUrl, $backViewUrl],             // up to 3 extra views; they only sharpen the description
    'description'    => 'black leather knee-high boots',          // your description (max 200), used in the generated prompt
    'instructions'   => 'Replace only the shoes with the boots.', // or your own prompt (max 1000), used verbatim
    'enhance_prompt' => false,                                    // keep the prompt as-is (default true)
]);
```

### Outfits

Combine 2–3 prepared garments into one outfit worn together. The outfit's `id` works wherever a garment id does
(`startLiveSession()`, the browser's `switchGarment`). To swap one item, prepare the new combination (cached) and switch to it.

```php
$top      = $client->prepareLiveGarment(['garment_image' => $shirtUrl, 'category' => 'clothing', 'subcategory' => 'tops']);
$trousers = $client->prepareLiveGarment(['garment_image' => $trousersUrl, 'category' => 'clothing', 'subcategory' => 'bottoms']);
$shoes    = $client->prepareLiveGarment(['garment_image' => $shoesUrl, 'category' => 'footwear']);

$outfit = $client->prepareLiveOutfit(['garment_ids' => [$top['id'], $trousers['id'], $shoes['id']]]);
// $outfit['category'] === 'outfit', $outfit['items'] = the garment ids top to bottom
$start = $client->startLiveSession(['garment_id' => $outfit['id'], 'end_user_id' => $shopper->id]);

// Later the shopper picks another top: prepare that outfit and switch the running stream to it.
$jacket = $client->prepareLiveGarment(['garment_image' => $jacketUrl, 'category' => 'clothing', 'subcategory' => 'tops']);
$next   = $client->prepareLiveOutfit(['garment_ids' => [$jacket['id'], $trousers['id'], $shoes['id']]]);
// Send $next['id'] to the browser, which switches with tryiton-live: await live.switchGarment(id);
```

`prepareLiveOutfit()` also takes `instructions` and `enhance_prompt`, as above.

## Error handling

All failures throw `TryItOn\TryItOnException`, which carries the HTTP status code and the API error name.

```php
use TryItOn\TryItOnException;

try {
    $client->tryOnFashion([ /* ... */ ]);
} catch (TryItOnException $e) {
    echo $e->status . ' ' . $e->errorName . ' ' . $e->getMessage(); // e.g. 429 OutOfCredits
}
```

`status` is `null` for failures that aren't an HTTP error response: `NetworkError` (including request timeouts),
`ProcessingError` (the job failed; the message is the reason), `Timeout` (`waitForResult` gave up) and `ConfigError`.

## Custom HTTP transport

Requests go through cURL by default. To add retries, logging or a proxy, or to stub the API in your own tests, implement
`TryItOn\HttpTransport` and pass it as the last constructor argument:

```php
$client = new Client(getenv('TRYITON_API_KEY'), 'https://tryiton.now/api/v1', 60, new MyTransport());
```

`send($method, $url, $headers, $body)` returns `['status' => int, 'body' => string]` for every HTTP status. The client
parses the JSON and raises the API errors. Your transport owns its timeout.

## Notes

- Output image URLs expire 72 hours after completion. Download any results you want to keep.
- Failed jobs are never charged.

## Documentation

Full documentation, parameter reference, and guides: [docs.tryiton.now](https://docs.tryiton.now)

## Development

```bash
composer install
composer test       # PHPUnit: unit tests + the shared SDK contract (tests/fixtures/sdk-cases.json)
composer analyse    # PHPStan, level 10
```

`tests/fixtures/sdk-cases.json` is the API contract every TryItOn SDK runs. It's generated from the backend, so don't
edit it by hand. Releases are git tags (`vX.Y.Z`); bump `Client::VERSION` and `CHANGELOG.md` to match before tagging.

## License

MIT
