<?php

declare(strict_types=1);

use Medas\ObjectInstantiator\ObjectInstantiator;
use Medas\HttpRequestFaker\HttpRequestFakerPackage;
use Medas\ServiceManager\{ServiceConfig, ServiceManager};

chdir(__DIR__);

new ServiceManager(function (): ServiceConfig {
    $config = new ServiceConfig(ObjectInstantiator::class);

    $config->addPackages([
        HttpRequestFakerPackage::instance(),
    ]);

    return $config;
});
