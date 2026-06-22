<?php

declare(strict_types=1);

namespace TryItOn;

/**
 * Raised for API-level errors (bad request, auth, rate limit, out of credits,
 * server error) and for runtime failures surfaced while polling a job.
 */
class TryItOnException extends \Exception
{
    /** HTTP status code, or null for a runtime (job) failure. */
    public ?int $status;

    /** The API error name, e.g. "OutOfCredits" or "ProcessingError". */
    public ?string $errorName;

    public function __construct(string $message, ?int $status = null, ?string $errorName = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorName = $errorName;
    }
}
