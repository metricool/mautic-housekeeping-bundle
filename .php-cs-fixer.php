<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setCacheFile(sys_get_temp_dir().'/housekeeping.php-cs-fixer.cache')
    ->setRules([
        '@Symfony'               => true,
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => null,
                '='  => null,
            ],
        ],
        'phpdoc_to_comment' => false,
        'ordered_imports'   => true,
        'array_syntax'      => [
            'syntax' => 'short',
        ],
        'no_unused_imports'     => true,
        'no_alternative_syntax' => false,
        'header_comment'        => [
            'header' => '',
        ],
        'multiline_whitespace_before_semicolons'           => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'new_with_parentheses'                             => ['anonymous_class' => true],
        'no_superfluous_phpdoc_tags'                       => false,
    ])
    ->setFinder($finder);
