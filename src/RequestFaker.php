<?php

declare(strict_types=1);

namespace Medas\HttpRequestFaker;

use Medas\Core\{Attributes\Service, Events\DebugInformationGatherer};
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
        private DebugInformationGatherer $debugInformationGatherer,
        private HttpRequestHandler       $requestHandler,
        private OutputDataPrinter        $outputDataPrinter,
        private RequestFactory           $requestFactory,
        private Request\UriManager       $uriManager,
        private ResponseDispatcher       $responseDispatcher,
    )
    {
    }

    /**
     * Constructs a Request without setting it on the factory or dispatching anything.
     * Useful when you need to modify the request (e.g., set an authenticated user) before
     * calling processRequest() or captureDispatch().
     */
    public function buildRequest(
        Request\Method $method,
        string         $uri,
        array          $serverData = [],
        array          $postData = [],
        array          $bodyData = [],
        array          $fileData = [],
    ): Request\Request
    {
        return new Request\Request(
            $method,
            $this->uriManager->fromString($uri),
            new Request\ServerData($serverData),
            new Request\PostData($postData),
            new Request\BodyData($bodyData),
            new Request\FileData($fileData),
        );
    }

    /**
     * Builds, sets, and fully dispatches a request, including sending HTTP headers and
     * echoing the response body. Intended for end-to-end smoke tests; prefer captureDispatch()
     * when you need to assert response data.
     */
    public function compileAndDispatchRequest(
        Request\Method $method,
        string         $uri,
        array          $serverData = [],
        array          $postData = [],
        array          $bodyData = [],
        array          $fileData = [],
    ): void
    {
        $this->debugInformationGatherer->events = [];
        $request = $this->buildRequest($method, $uri, $serverData, $postData, $bodyData, $fileData);

        $this->requestFactory->set($request);
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
        $this->debugInformationGatherer->events = [];
        $this->requestFactory->set($request);

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
        $this->debugInformationGatherer->events = [];

        $this->requestFactory->set($request);

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
}
