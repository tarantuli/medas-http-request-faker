# medas-http-request-faker

Part of the [Medas framework](https://github.com/tarantuli/medas-core).

## Description

A testing utility that drives `medas-http-request-handler` in-process without a real HTTP server. `RequestFaker` builds `Request` objects programmatically, clears request-scoped state between calls, and either dispatches requests fully (for smoke tests) or captures their response into a `CapturedResponse` (for assertion-based tests).

**Key behaviours:**

- `buildRequest()` constructs a `Request` object from method, URI, headers, body data, and cookies. Standard HTTP header names are automatically converted to the `HTTP_*` `$_SERVER` convention. `AuthenticationFinder` runs automatically so bearer tokens are resolved to user objects through the normal vote pipeline.
- `captureDispatch()` processes the request and response pipeline entirely in memory — no `header()` calls, no `echo`. Returns a `CapturedResponse` with the status code, headers, and body.
- `processRequest()` returns the raw `Response` object; exceptions from routing, authorization, and handler logic bubble up normally, making it ideal for testing that specific exceptions are thrown.
- **Cookie jar** — `Set-Cookie` headers from responses are stored and automatically merged into subsequent `buildRequest()` calls. Cookies with `Max-Age <= 0` are removed from the jar. Call `clearJar()` in test `setUp()` to reset state between unrelated test flows.
- `prepare()` calls `CacheManager::clearAll()` and `EntityManager::clear()` before each request, ensuring a clean request-scoped state without affecting persistent caches.

`CapturedResponse` exposes:

| Member           | Description                                                                       |
|------------------|-----------------------------------------------------------------------------------|
| `$responseCode`  | HTTP status code                                                                  |
| `$headers`       | All response headers as `array<string, string[]>`                                 |
| `$body`          | Raw response body string                                                          |
| `$parsedBody`    | Auto-parsed from `Content-Type` (JSON → array, form-encoded → array, else `null`) |
| `json()`         | Decodes body as JSON; throws `\JsonException` on failure                          |
| `cookie(name)`   | Returns a named cookie value from `Set-Cookie` headers                            |
| `setCookies()`   | Returns all `Set-Cookie` header values                                            |
| `isSuccessful()` | Returns `true` for 2xx status codes                                               |

## Usage

### Package developer context

Register the package — typically only in test bootstraps:

```php
use Medas\HttpRequestFaker\HttpRequestFakerPackage;

HttpRequestFakerPackage::instance();
```

**Basic GET request:**

```php
use Medas\HttpRequestFaker\RequestFaker;
use Medas\HttpRequestHandler\Request\Method;
use PHPUnit\Framework\TestCase;

class InvoiceApiTest extends TestCase
{
    private RequestFaker $faker;

    protected function setUp(): void
    {
        $this->faker = service(RequestFaker::class);
        $this->faker->clearJar();
    }

    public function testListInvoices(): void
    {
        $request = $this->faker->buildRequest(Method::Get, '/invoices');
        $response = $this->faker->captureDispatch($request);

        self::assertSame(200, $response->responseCode);
        self::assertIsArray($response->parsedBody);
    }
}
```

**POST with a JSON body:**

```php
$request = $this->faker->buildRequest(
    method: Method::Post,
    uri: '/invoices',
    bodyData: [
        'customerId' => $customer->id(),
        'amountCents' => 9900,
    ],
);

$response = $this->faker->captureDispatch($request);

self::assertSame(201, $response->responseCode);
self::assertArrayHasKey('id', $response->parsedBody);
```

**Authenticated request via bearer token:**

```php
$request = $this->faker->buildRequest(
    method: Method::Get,
    uri: '/invoices',
    headers: ['Authorization' => 'Bearer my-client:secret-key'],
);

$response = $this->faker->captureDispatch($request);
self::assertTrue($response->isSuccessful());
```

**Injecting an authenticated user directly** (faster than a full token round-trip):

```php
use Medas\HttpRequestHandler\Authentication\Authentication;

$request = $this->faker->buildRequest(Method::Get, '/invoices');

// Skip the full bearer-token pipeline for this test
$request->authentication->user = $this->testUser;

$response = $this->faker->captureDispatch($request);
```

**Testing that an exception is thrown:**

```php
use Medas\HttpRequestHandler\Exceptions\RequestNotAuthorized;

$request = $this->faker->buildRequest(Method::Delete, '/invoices/' . $invoice->id());

$this->expectException(RequestNotAuthorized::class);
$this->faker->processRequest($request);
```

**Cookie-based session test:**

```php
// Login — response sets a session cookie
$loginRequest = $this->faker->buildRequest(
    method: Method::Post,
    uri: '/login',
    bodyData: ['userName' => 'alice', 'password' => 'secret'],
);
$loginResponse = $this->faker->captureDispatch($loginRequest);

// Cookie is now in the jar — later requests include it automatically
$profileRequest = $this->faker->buildRequest(Method::Get, '/profile');
$profileResponse = $this->faker->captureDispatch($profileRequest);

self::assertSame(200, $profileResponse->responseCode);
```

### Backend user context

This package is for testing only and should never be registered in production bootstraps. It depends on `RequestFactory::set()` which throws `RequestDataMutationOutsideOfCli` when called outside of `PHP_SAPI === 'cli'`, so it is safe by design — misconfiguring it in a web context will fail loudly.
