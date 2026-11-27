# Changelog

All notable changes to `tryiton/tryiton` are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Versions are git tags (`vX.Y.Z`).

## [Unreleased]

## [2.2.0] - 2026-11-27

### Added

- `prepareLiveGarment()` takes optional overrides: `detail_images` (up to 3 extra views that sharpen the description),
  `description` (your item description, used in the generated prompt), `instructions` (your own prompt, used
  verbatim) and `enhance_prompt` (`false` keeps the prompt as-is; default `true`). camelCase keys work too.
- `prepareLiveOutfit()` (`POST /live/outfits`): combine 2 or 3 prepared garments (`garment_ids`, plus optional
  `instructions` and `enhance_prompt`) into one outfit, returned in the garment shape. Its `id` works wherever a
  garment id does.
- The `LiveGarment` shape gains `prompt`, `enhancePrompt` and `items` (an outfit's garment ids top to bottom, `null`
  for a single garment); `category` can now also be `"outfit"`. New `LiveSessionStart` `@phpstan-type` alias for the
  `startLiveSession()` response.

### Changed

- The live methods moved from `Client.php` into the `LiveTryOn` trait that `Client` uses. No API change.

### Deprecated

- The `mode` and `moderation_level` keys of `tryOnFashion()`: the API ignores `mode` and validates `moderation_level` without applying it. Removed in 3.0.

## [2.1.0] - 2026-11-26

### Added

- Live (real-time webcam) try-on endpoints: `getLiveConfig()`, `prepareLiveGarment()`, `startLiveSession()`,
  `getLiveSession()` and `stopLiveSession()`. The live methods accept snake_case or camelCase param keys.
- `Client::VERSION`, sent on every request as `User-Agent: tryiton-php/<version>`.
- Optional `HttpTransport` interface with the default `CurlTransport`, passed as a new optional last constructor
  argument (`new Client($key, $baseUrl, $timeout, $transport)`) for retries, logging, proxies or test stubs. Existing
  constructor calls are unchanged.
- `TryItOnException` takes an optional `$previous` exception. A custom transport's own exception reaches the caller as
  a `NetworkError` with the original as `getPrevious()`.
- Precise PHPDoc array shapes for every response (`@return array{...}`, plus the `LiveSession` and `LiveGarment`
  `@phpstan-type` aliases). Methods still return plain arrays.
- Contract tests against the shared SDK cases, PHPUnit and PHPStan (level 10) setup, and CI on PHP 7.4 to 8.3.

### Fixed

- Standard API errors (`{ "ok": false, "error": { "name", "message" } }`) escaped as a PHP `TypeError` under
  `strict_types` instead of a `TryItOnException`. They now raise `TryItOnException` with `errorName` and the message
  from `error.name` / `error.message`. The older top-level `error` / `message` strings still work.
- A POST with no options set (`startLiveSession()`, `stopLiveSession()`) sends `{}` instead of `[]`: the API expects
  a JSON object.
- `waitForResult()` dropped a failed job's reason and threw "Try-on failed." because `GET /status` sends `error` as a
  plain string. The string is now the exception message, with `errorName` `"ProcessingError"`. `getStatus()` documents
  `error` as that string (or the older `{ name, message }` array).
- A 2xx response without the expected field (e.g. an HTML page from a proxy) raised a PHP `TypeError` or notice. It now
  raises `TryItOnException` with `errorName` `"InvalidResponse"`.
- A param that can't be JSON-encoded (e.g. a string that isn't valid UTF-8) sent an empty body. It now raises
  `TryItOnException` with `errorName` `"InvalidRequest"` before any request is made.
- No more `curl_close()` call, which is deprecated as of PHP 8.5 (the handle is freed when it goes out of scope).

## [2.0.0] - 2026-07-14

### Changed

- **Breaking:** `tryOnClothes()` is renamed to `tryOnFashion()`.

## [1.1.0] - 2026-07-14

### Added

- Documented the tattoo `region` param (a normalized `x`/`y`/`w`/`h` box) for exact placement. Params are passed
  through as-is, so no code change was needed.

## [1.0.0] - 2026-06-24

### Added

- `num_samples` and `output_format` on every try-on.

### Removed

- **Breaking:** the `seed`, `segmentation_free` and `garment_photo_type` params.

## [0.1.0] - 2026-06-22

### Added

- First release: `tryOnClothes()`, `tryOnHairstyle()`, `tryOnTattoo()`, `getStatus()`, `waitForResult()`,
  `getCredits()` and `TryItOnException`.

[Unreleased]: https://github.com/tryiton-now/tryiton-php/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/tryiton-now/tryiton-php/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/tryiton-now/tryiton-php/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/tryiton-now/tryiton-php/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/tryiton-now/tryiton-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/tryiton-now/tryiton-php/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/tryiton-now/tryiton-php/releases/tag/v0.1.0
