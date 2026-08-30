<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when a verification code is requested again before the resend
 * cooldown has elapsed. Renders as 429 with the seconds still to wait so the
 * SPA can seed its countdown even after a page reload.
 */
class VerificationCodeThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Please wait before requesting another code.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'retry_after' => $this->retryAfter,
        ], 429, ['Retry-After' => $this->retryAfter]);
    }
}
