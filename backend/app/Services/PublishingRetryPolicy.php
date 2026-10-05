<?php

namespace App\Services;

use Carbon\Carbon;

class PublishingRetryPolicy
{
    public const CATEGORY_AUTHENTICATION = 'AUTHENTICATION';
    public const CATEGORY_AUTHORIZATION = 'AUTHORIZATION';
    public const CATEGORY_RATE_LIMITED = 'RATE_LIMITED';
    public const CATEGORY_NETWORK = 'NETWORK';
    public const CATEGORY_TIMEOUT = 'TIMEOUT';
    public const CATEGORY_PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';
    public const CATEGORY_VALIDATION = 'VALIDATION';
    public const CATEGORY_MEDIA_INVALID = 'MEDIA_INVALID';
    public const CATEGORY_QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    public const CATEGORY_DUPLICATE = 'DUPLICATE';
    public const CATEGORY_PERMANENT_PROVIDER_ERROR = 'PERMANENT_PROVIDER_ERROR';
    public const CATEGORY_UNKNOWN = 'UNKNOWN';

    /**
     * Centralized policy table.
     */
    protected static array $policies = [
        self::CATEGORY_AUTHENTICATION => [
            'retryable' => false,
            'max_attempts' => 1,
            'base_backoff' => 0,
            'jitter' => 0,
            'user_message' => 'Social account authentication expired or invalid. Please reconnect your account.',
        ],
        self::CATEGORY_AUTHORIZATION => [
            'retryable' => false,
            'max_attempts' => 1,
            'base_backoff' => 0,
            'jitter' => 0,
            'user_message' => 'Missing required permissions for this publishing operation. Please grant permissions.',
        ],
        self::CATEGORY_RATE_LIMITED => [
            'retryable' => true,
            'max_attempts' => 5,
            'base_backoff' => 60, // 60s, 120s, 240s...
            'jitter' => 15,
            'user_message' => 'Publishing temporarily rate limited by provider. Retrying automatically.',
        ],
        self::CATEGORY_NETWORK => [
            'retryable' => true,
            'max_attempts' => 4,
            'base_backoff' => 10,
            'jitter' => 5,
            'user_message' => 'Transient network communication error with provider. Retrying automatically.',
        ],
        self::CATEGORY_TIMEOUT => [
            'retryable' => true,
            'max_attempts' => 3,
            'base_backoff' => 20,
            'jitter' => 5,
            'user_message' => 'Connection or remote upload timed out. Retrying automatically.',
        ],
        self::CATEGORY_PROVIDER_UNAVAILABLE => [
            'retryable' => true,
            'max_attempts' => 4,
            'base_backoff' => 30,
            'jitter' => 10,
            'user_message' => 'Provider API service is temporarily unavailable. Retrying automatically.',
        ],
        self::CATEGORY_VALIDATION => [
            'retryable' => false,
            'max_attempts' => 1,
            'base_backoff' => 0,
            'jitter' => 0,
            'user_message' => 'Content or metadata failed platform validation requirements.',
        ],
        self::CATEGORY_MEDIA_INVALID => [
            'retryable' => false,
            'max_attempts' => 1,
            'base_backoff' => 0,
            'jitter' => 0,
            'user_message' => 'Media file does not meet the provider technical specifications.',
        ],
        self::CATEGORY_QUOTA_EXCEEDED => [
            'retryable' => false,
            'max_attempts' => 1,
            'base_backoff' => 0,
            'jitter' => 0,
            'user_message' => 'Provider publishing quota exceeded for today. Please wait for quota reset.',
        ],
        self::CATEGORY_DUPLICATE => [
            'retryable' => false,
            'max_attempts' => 1,
            'base_backoff' => 0,
            'jitter' => 0,
            'user_message' => 'Duplicate submission detected. Post was already created on the provider.',
        ],
        self::CATEGORY_PERMANENT_PROVIDER_ERROR => [
            'retryable' => false,
            'max_attempts' => 1,
            'base_backoff' => 0,
            'jitter' => 0,
            'user_message' => 'Provider rejected the publication request permanently.',
        ],
        self::CATEGORY_UNKNOWN => [
            'retryable' => true,
            'max_attempts' => 2,
            'base_backoff' => 15,
            'jitter' => 5,
            'user_message' => 'An unexpected error occurred during publishing. Retrying.',
        ],
    ];

    /**
     * Map existing legacy or provider specific codes to canonical categories.
     */
    public static function normalizeCategory(?string $category): string
    {
        if (empty($category)) {
            return self::CATEGORY_UNKNOWN;
        }

        $upper = strtoupper(trim($category));

        if (isset(self::$policies[$upper])) {
            return $upper;
        }

        return match ($upper) {
            'AUTH_REQUIRED', 'AUTHENTICATION_REQUIRED', 'INVALID_CREDENTIALS', 'TOKEN_EXPIRED' => self::CATEGORY_AUTHENTICATION,
            'FORBIDDEN', 'PERMISSION_DENIED', 'INSUFFICIENT_SCOPE' => self::CATEGORY_AUTHORIZATION,
            'TOO_MANY_REQUESTS', 'THROTTLED' => self::CATEGORY_RATE_LIMITED,
            'CONNECTION_ERROR', 'DNS_FAILURE', 'CURL_ERROR' => self::CATEGORY_NETWORK,
            'TIMED_OUT', 'DEADLINE_EXCEEDED' => self::CATEGORY_TIMEOUT,
            'SERVICE_UNAVAILABLE', 'BAD_GATEWAY', 'GATEWAY_TIMEOUT', 'INTERNAL_SERVER_ERROR' => self::CATEGORY_PROVIDER_UNAVAILABLE,
            'INVALID_ARGUMENT', 'VALIDATION_FAILED', 'MALFORMED_REQUEST' => self::CATEGORY_VALIDATION,
            'INVALID_MEDIA', 'UNSUPPORTED_MEDIA_TYPE', 'CORRUPT_MEDIA', 'INVALID_ASPECT_RATIO' => self::CATEGORY_MEDIA_INVALID,
            'QUOTA_EXCEEDED', 'DAILY_LIMIT_EXCEEDED' => self::CATEGORY_QUOTA_EXCEEDED,
            'ALREADY_EXISTS', 'DUPLICATE_POST' => self::CATEGORY_DUPLICATE,
            'PERMANENT_FAILURE', 'REJECTED' => self::CATEGORY_PERMANENT_PROVIDER_ERROR,
            default => self::CATEGORY_UNKNOWN,
        };
    }

    /**
     * Get policy configuration for a canonical category.
     */
    public function getPolicy(string $category): array
    {
        $normalized = self::normalizeCategory($category);
        return self::$policies[$normalized];
    }

    /**
     * Determine whether an attempt is retryable.
     */
    public function isRetryable(string $category, int $currentAttempts): bool
    {
        $policy = $this->getPolicy($category);
        if (!$policy['retryable']) {
            return false;
        }

        return $currentAttempts < $policy['max_attempts'];
    }

    /**
     * Calculate backoff seconds using exponential backoff plus randomized jitter.
     */
    public function calculateBackoffSeconds(string $category, int $attempt): int
    {
        $policy = $this->getPolicy($category);
        if (!$policy['retryable']) {
            return 0;
        }

        $base = $policy['base_backoff'];
        $jitterMax = $policy['jitter'];

        // Exponential backoff: base * 2^(attempt - 1)
        $exponent = max(0, $attempt - 1);
        $backoff = (int) ($base * pow(2, $exponent));

        // Add uniform random jitter
        $jitter = $jitterMax > 0 ? rand(1, $jitterMax) : 0;

        return $backoff + $jitter;
    }

    /**
     * Calculate the timestamp for next retry.
     */
    public function calculateNextRetry(string $category, int $attempt): ?Carbon
    {
        if (!$this->isRetryable($category, $attempt)) {
            return null;
        }

        $seconds = $this->calculateBackoffSeconds($category, $attempt);
        return now()->addSeconds($seconds);
    }

    /**
     * Get the standardized user-facing error message.
     */
    public function getUserMessage(string $category): string
    {
        $policy = $this->getPolicy($category);
        return $policy['user_message'];
    }
}
