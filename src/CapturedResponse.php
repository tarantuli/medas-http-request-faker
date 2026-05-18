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
     * Parses the name and value from a Set-Cookie header string.
     *
     * @return array{string, string}
     */
    public static function parseNameValue(string $setCookieHeader): array
    {
        $firstSegment = trim(explode(';', $setCookieHeader)[0]);
        [$encodedName, $encodedValue] = explode('=', $firstSegment, 2);

        return [rawurldecode(trim($encodedName)), rawurldecode(trim($encodedValue))];
    }

    /**
     * @param array<string, string[]> $headers
     */
    public function __construct(
        public int    $responseCode,
        public array  $headers,
        public string $body,
    )
    {
    }

    /**
     * Returns all Set-Cookie header values from the response.
     *
     * @return string[]
     */
    public function setCookies(): array
    {
        return $this->headers['Set-Cookie'] ?? [];
    }

    /**
     * Returns the value of the named cookie from the response Set-Cookie headers, or null
     * if no cookie with that name was set.
     */
    public function cookie(string $name): string|null
    {
        foreach ($this->setCookies() as $setCookieHeader) {
            [$cookieName, $cookieValue] = self::parseNameValue($setCookieHeader);

            if ($cookieName === $name) {
                return $cookieValue;
            }
        }

        return null;
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
