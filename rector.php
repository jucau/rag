<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodeQuality\Rector\FuncCall\InlineIsAInstanceOfRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\CodingStyle\Rector\FuncCall\CountArrayToEmptyArrayComparisonRector;
use Rector\Config\RectorConfig;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withCache(
        cacheDirectory: __DIR__ . '/storage/rector',
        cacheClass: FileCacheStorage::class
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        phpunitCodeQuality: true,
    )
    ->withParallel(
        timeoutSeconds: 300,
        maxNumberOfProcess: 4
    )
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/public',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LaravelSetList::LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::ARRAY_STR_FUNCTIONS_TO_STATIC_CALL,
    ])
    ->withPhpSets(
        php84: true,
    )
    ->withSkip([
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,
        ClosureToArrowFunctionRector::class,
        CountArrayToEmptyArrayComparisonRector::class,
        DisallowedEmptyRuleFixerRector::class,
        ExplicitBoolCompareRector::class,
        InlineIsAInstanceOfRector::class,
        ReturnBinaryOrToEarlyReturnRector::class,
        SimplifyEmptyCheckOnEmptyArrayRector::class,
        StringClassNameToClassConstantRector::class,
    ]);
