<?php

declare(strict_types=1);

namespace Medas\HttpRequestFaker;

/**
 * Holds the fully resolved output of a dispatched request, captured without sending any HTTP
 * headers or echoing to stdout. Use in tests to assert response code, headers, and body.
 */
readonly class CapturedResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int    $responseCode,
        public array  $headers,
        public string $body,
    )
    {
    }

    /**
     * Decodes the body as JSON and returns the result.
     * Throws \JsonException if the body is not valid JSON.
     */
    public function json(): mixed
    {
        return json_decode($this->body, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Returns true when the response code is in the 2xx range.
     */
    public function isSuccessful(): bool
    {
        return $this->responseCode >= 200 && $this->responseCode < 300;
    }
}
