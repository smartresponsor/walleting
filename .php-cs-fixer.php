<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/migrations',
    ])
    ->exclude([
        'var',
        'vendor',
        'node_modules',
    ])
    ->name('*.php');

$config = new Config();

$config
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'single_import_per_statement' => true,
        'no_unreachable_default_argument_value' => false,
        'logical_operators' => false,
        'error_suppression' => false,
        'set_type_to_cast' => false,
        'static_lambda' => false,
        'psr_autoloading' => false,
        'string_line_ending' => false,
        'non_printable_character' => false,
        'ereg_to_preg' => false,
        'modern_serialization_methods' => false,
        'native_function_invocation' => false,
        'native_constant_invocation' => false,
        'no_alias_functions' => false,
        'function_to_constant' => false,
        'no_php4_constructor' => false,
        'ordered_traits' => false,
        'no_homoglyph_names' => false,
        'single_line_comment_style' => false,
        'phpdoc_to_comment' => false,
        'no_superfluous_phpdoc_tags' => false,
        'comment_to_phpdoc' => false,
    ])
    ->setFinder($finder);

return $config;
