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
$jobId = $client->tryOnClothes([
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

`tryOnClothes` covers clothing and accessory try-on. The most important parameters:

| Parameter | Type | Required | Description |
| --------- | ---- | -------- | ----------- |
| `model_image` | string | Yes | URL or base64 data URL of the person. |
| `garment_image` | string | Yes | URL or base64 data URL of the garment or accessory. |
| `category` | string | No | Item type: `auto`, `clothing`, `eyewear`, `footwear`, `headwear`, `jewelry`, `accessories`, or `others`. `auto` detects it for you. |
| `subcategory` | string | No | Required for `clothing` (`tops`, `bottoms`, `dresses`), `jewelry`, and `accessories`. |

Additional clothing options (`mode`, `num_samples`, `output_format`, `seed`) are documented in the [API reference](https://docs.tryiton.now).

## Other endpoints

```php
// Hairstyle try-on (see Client::HAIRCUTS for all supported values)
$client->tryOnHairstyle([
    'face_image' => $faceUrl,
    'haircut'    => 'BuzzCut',
    'hair_color' => 'ash blonde',
]);

// Tattoo try-on
$client->tryOnTattoo([
    'body_image'   => $bodyUrl,
    'design_image' => $designUrl,
    'placement'    => 'on the right forearm, small',
]);

// Poll a job manually, or check your credit balance
$status  = $client->getStatus($jobId);  // ['status' => ..., 'output' => [...], 'error' => ...]
$credits = $client->getCredits();        // ['on_demand' => ..., 'subscription' => ..., 'purchased' => ..., 'reserved' => ...]
```

## Error handling

All failures throw `TryItOn\TryItOnException`, which carries the HTTP status code and the API error name.

```php
use TryItOn\TryItOnException;

try {
    $client->tryOnClothes([ /* ... */ ]);
} catch (TryItOnException $e) {
    echo $e->status . ' ' . $e->errorName . ' ' . $e->getMessage(); // e.g. 429 OutOfCredits
}
```

## Notes

- Output image URLs expire 72 hours after completion. Download any results you want to keep.
- Failed jobs are never charged.

## Documentation

Full documentation, parameter reference, and guides: [docs.tryiton.now](https://docs.tryiton.now)

## License

MIT
