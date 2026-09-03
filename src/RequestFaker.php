<?php

declare(strict_types=1);

namespace Medas\HttpRequestFaker;

use Medas\Core\{
    Attributes\Service,
    Events\BeforeResponse,
    Events\DebugInformationGatherer,
    Interfaces\CacheManager,
    Interfaces\EntityManager
};
use Medas\HttpRequestHandler\{
    HttpRequestHandler,
    Request,
    RequestFactory,
    ResponseDispatcher,
    ResponseDispatcher\OutputDataPrinter,
    ResponseTypes\Response
};

#[Service]
class RequestFaker
{
    /** @var array<string, string> */
    private array $cookieJar = [];

    public function __construct(
        private readonly CacheManager                 $cacheManager,
        private readonly DebugInformationGatherer     $debugInformationGatherer,
        private readonly EntityManager                $entityManager,
        private readonly HttpRequestHandler           $requestHandler,
        private readonly OutputDataPrinter            $outputDataPrinter,
        private readonly RequestFactory               $requestFactory,
        private readonly Request\AuthenticationFinder $authenticationFinder,
        private readonly Request\UriManager           $uriManager,
        private readonly ResponseDispatcher           $responseDispatcher,
    )
    {
    }

    /**
     * Clears all cookies from the cookie jar.
     * Call this in test setUp() to ensure a clean state between test flows.
     */
    public function clearJar(): void
    {
        $this->cookieJar = [];
    }

    /**
     * Constructs a Request without dispatching anything.
     *
     * Cookies from the jar are merged with any explicitly passed $cookieData.
     * Explicitly passed cookies take precedence over jar cookies.
     *
     * Headers should be passed as standard HTTP header names; they are converted to the
     * PHP $_SERVER HTTP_* convention automatically:
     *   ['Authorization' => 'Bearer token']  →  ServerData['HTTP_AUTHORIZATION']
     *
     * AuthenticationFinder is run automatically so that auth-related headers (e.g. Bearer
     * tokens) are resolved into $request->authentication->user via the normal vote pipeline.
     * You can still override $request->authentication->user afterward for tests that don't
     * need full token parsing.
     */
    public function buildRequest(
        Request\Method $method,
        string         $uri,
        array          $headers = [],
        array          $serverData = [],
        array          $bodyData = [],
        array          $fileData = [],
        array          $cookieData = [],
    ): Request\Request
    {
        if (!array_key_exists('Accept', $headers)) {
            $headers['Accept'] = 'application/json';
        }

        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $serverData[$key] = $value;
        }

        // Explicit cookies take precedence over jar cookies
        $mergedCookieData = array_merge($this->cookieJar, $cookieData);

        $request = new Request\Request(
            $method,
            $this->uriManager->fromString($uri),
            new Request\ServerData($serverData),
            new Request\BodyData($bodyData),
            new Request\FileData($fileData),
            new Request\CookieData($mergedCookieData),
        );

        $this->authenticationFinder->find($request);

        return $request;
    }

    /**
     * Builds, sets, and fully dispatches a request, including sending HTTP headers and
     * echoing the response body. Intended for end-to-end smoke tests; prefer captureDispatch()
     * when you need to assert response data.
     */
    public function compileAndDispatchRequest(
        Request\Method $method,
        string         $uri,
        array          $headers = [],
        array          $serverData = [],
        array          $bodyData = [],
        array          $fileData = [],
    ): void
    {
        $request = $this->buildRequest($method, $uri, $headers, $serverData, $bodyData, $fileData);

        $this->prepare($request);
        $this->requestHandler->handle();
    }

    /**
     * Processes a request and returns the raw Response object.
     * Exceptions from routing, authorisation, and handler logic bubble up normally,
     * making this the right entry point for asserting that specific exceptions are thrown.
     *
     * Note: this bypasses the PSR-15 middleware pipeline. Set $request->authentication->user
     * directly on the Request if your test requires an authenticated user.
     */
    public function processRequest(Request\Request $request): Response
    {
        $this->prepare($request);

        return $this->requestHandler->processRequest($request);
    }

    /**
     * Processes a request and returns a CapturedResponse containing the response code,
     * headers, and body — without sending any HTTP output or headers.
     *
     * Set-Cookie headers from the response are automatically stored in the cookie jar
     * and included in later requests built via buildRequest(). Call clearJar() in
     * test setUp() to reset the cookie state between unrelated test flows.
     *
     * Note: this bypasses the PSR-15 middleware pipeline. Set $request->authentication->user
     * directly on the Request if your test requires an authenticated user.
     */
    public function captureDispatch(Request\Request $request): CapturedResponse
    {
        $this->prepare($request);

        $response = $this->requestHandler->processRequest($request);

        // Mirror HttpRequestHandler::handle(): the real lifecycle dispatches
        // BeforeResponse after processing, and that is where EntityManager flushes.
        // Without it, entities persisted by post-flush listeners (an EntityCreated
        // reactor, say) stay uncommitted and are dropped on the next clear().
        dispatch(new BeforeResponse());

        $job = $this->responseDispatcher->prepareJob($request, $response);

        // Apply ETag negotiation without sending headers or echoing
        $this->outputDataPrinter->prepare($job);

        $captured = new CapturedResponse(
            responseCode: $job->responseCode,
            headers: $job->headers(),
            body: $job->output,
        );

        $this->updateJarFromResponse($captured);

        return $captured;
    }

    /**
     * Clears all request-scoped state and pre-populates the request factory cache.
     *
     * Order is important: caches must be cleared before set() writes the request
     * into the memory cache.
     *
     * - CacheManager::clearAll() clears all Clearable caches (in-process memory caches,
     *   service-level memoization). Persistent caches are unaffected as they don't
     *   implement Clearable.
     * - EntityManager::clear() clears the identity map, so entities are re-fetched
     *   from the database rather than returned stale from a previous request.
     * - RequestFactory::set() pre-populates the memory cache so that services calling
     *   requestFactory->get() internally receive the correct request.
     */
    private function prepare(Request\Request $request): void
    {
        $this->cacheManager->clearAll();
        $this->entityManager->clear();

        $this->debugInformationGatherer->events = [];

        $this->requestFactory->set($request);
    }

    /**
     * Parses Set-Cookie headers from the response and updates the jar.
     * Cookies with Max-Age <= 0 are removed from the jar.
     */
    private function updateJarFromResponse(CapturedResponse $response): void
    {
        foreach ($response->setCookies() as $setCookieHeader) {
            [$name, $value] = CapturedResponse::parseNameValue($setCookieHeader);
            $maxAge = $this->parseMaxAge($setCookieHeader);

            if ($maxAge !== null && $maxAge <= 0) {
                unset($this->cookieJar[$name]);
            }
            else {
                $this->cookieJar[$name] = $value;
            }
        }
    }

    /**
     * Extracts the Max-Age attribute value from a Set-Cookie header string, or null if absent.
     */
    private function parseMaxAge(string $setCookieHeader): int|null
    {
        foreach (array_slice(explode(';', $setCookieHeader), 1) as $attribute) {
            $attribute = trim($attribute);

            if (stripos($attribute, 'Max-Age=') === 0) {
                return (int) substr($attribute, 8);
            }
        }

        return null;
    }
}
