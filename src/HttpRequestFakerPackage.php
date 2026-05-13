<?php

declare(strict_types=1);

namespace Medas\HttpRequestFaker;

use Medas\Core\{AsSingleton, BasePackage};
use Medas\HttpRequestHandler\HttpRequestHandlerPackage;

class HttpRequestFakerPackage extends BasePackage
{
    use AsSingleton;

    public function dependencies(): array
    {
        return [
            HttpRequestHandlerPackage::class,
        ];
    }

    public function sourceDirectory(): string
    {
        return __DIR__;
    }
}
