<?php

namespace Brigada\Hq;

/** What HQ answered to one batch. status 0 = no answer (network error, timeout). */
final class Delivery
{
    public function __construct(
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        public readonly int $accepted = 0,
        public readonly int $dropped = 0,
        public readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 202;
    }

    /** Worth trying again later: no answer, rate limited or a server error. */
    public function retryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    /** The key is wrong or revoked: retrying cannot help. */
    public function refused(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }
}
