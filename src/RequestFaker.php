<?php

declare(strict_types=1);

namespace Medas\HttpRequestFaker;

use Medas\Core\{
    Attributes\Service,
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
readonly class RequestFaker
{
    public function __construct(
        private CacheManager                 $cacheManager,
        private DebugInformationGatherer     $debugInformationGatherer,
        private EntityManager                $entityManager,
        private HttpRequestHandler           $requestHandler,
        private OutputDataPrinter            $outputDataPrinter,
        private RequestFactory               $requestFactory,
        private Request\AuthenticationFinder $authenticationFinder,
        private Request\UriManager           $uriManager,
        private ResponseDispatcher           $responseDispatcher,
    )
    {
    }

    /**
     * Constructs a Request without dispatching anything.
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
    ): Request\Request
    {
        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $serverData[$key] = $value;
        }

        $request = new Request\Request(
            $method,
            $this->uriManager->fromString($uri),
            new Request\ServerData($serverData),
            new Request\BodyData($bodyData),
            new Request\FileData($fileData),
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
     * Exceptions from routing, authorization, and handler logic bubble up normally,
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
     * Use this to assert response data in tests.
     *
     * Note: this bypasses the PSR-15 middleware pipeline. Set $request->authentication->user
     * directly on the Request if your test requires an authenticated user.
     */
    public function captureDispatch(Request\Request $request): CapturedResponse
    {
        $this->prepare($request);

        $response = $this->requestHandler->processRequest($request);
        $job = $this->responseDispatcher->prepareJob($request, $response);

        // Apply ETag negotiation without sending headers or echoing
        $this->outputDataPrinter->prepare($job);

        return new CapturedResponse(
            responseCode: $job->responseCode,
            headers: $job->headers,
            body: $job->output,
        );
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
     * - EntityManager::clear() clears the identity map so entities are re-fetched
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
}
