<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([__DIR__ . '/plugin', __DIR__ . '/tests'])
    ->name('*.php');

return (new Config())
    ->setUsingCache(false)
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'no_empty_phpdoc' => true,
    ]);
