<?php
// Folders to traverse. Multiple in() calls can be chained.
$finder = Symfony\CS\Finder\DefaultFinder::create()->in(__DIR__ . '/library')
                                                   ->in(__DIR__ . '/tests');

$config = Symfony\CS\Config\Config::create();
$config
    ->level(Symfony\CS\FixerInterface::PSR2_LEVEL)
    ->fixers(array(
        '-remove_lines_between_uses',
        'concat_with_spaces',
        'multiline_array_trailing_comma',
        'namespace_no_leading_whitespace',
        'object_operator',
        'operators_spaces',
        //'ordered_use', // WARNING: causes problems with traits
        'return',
        'single_array_no_trailing_comma',
        'spaces_before_semicolon',
        'spaces_cast',
        'unused_use',
        'whitespacy_lines',
        'short_array_syntax',
    ))
    ->finder($finder);

return $config;
