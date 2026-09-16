#!/usr/bin/env php
<?php

declare(strict_types=1);

const PERMISSIVE_LICENSES = [
    '0BSD',
    'AFL-3.0',
    'Apache-1.0',
    'Apache-1.1',
    'Apache-2.0',
    'Artistic-2.0',
    'BSD-1-Clause',
    'BSD-2-Clause',
    'BSD-2-Clause-FreeBSD',
    'BSD-2-Clause-NetBSD',
    'BSD-3-Clause',
    'BSD-3-Clause-Clear',
    'BSD-3-Clause-LBNL',
    'BSD-3-Clause-Open-MPI',
    'BSD-4-Clause',
    'BSD-4-Clause-Shortened',
    'BSD-4-Clause-UC',
    'BSL-1.0',
    'BlueOak-1.0.0',
    'CC0-1.0',
    'FSFAP',
    'ISC',
    'MIT',
    'MIT-0',
    'NCSA',
    'PHP-3.0',
    'PHP-3.01',
    'PostgreSQL',
    'Python-2.0',
    'Unlicense',
    'Unicode-3.0',
    'Unicode-DFS-2015',
    'Unicode-DFS-2016',
    'WTFPL',
    'X11',
    'Zlib',
    'ZPL-2.0',
];

// Package-specific exceptions belong here only with a concrete justification.
const PACKAGE_EXCEPTIONS = [];

/**
 * @return array<string, mixed>
 */
function licenseObject(mixed $value, string $context): array
{
    if (! is_array($value)) {
        throw new RuntimeException("{$context} must be a JSON object.");
    }

    $object = [];

    foreach ($value as $key => $item) {
        if (! is_string($key)) {
            throw new RuntimeException("{$context} must use string keys.");
        }

        $object[$key] = $item;
    }

    return $object;
}

/**
 * @return list<array<string, mixed>>
 */
function licensePackageList(mixed $value, string $context): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$context} must be a JSON array.");
    }

    $packages = [];

    foreach ($value as $index => $package) {
        $packages[] = licenseObject($package, "{$context}[{$index}]");
    }

    return $packages;
}

/**
 * @param  list<string>  $tokens
 */
function parseOr(array $tokens, int &$position): bool
{
    $allowed = parseAnd($tokens, $position);

    while (($tokens[$position] ?? null) === 'OR') {
        $position++;
        $allowed = parseAnd($tokens, $position) || $allowed;
    }

    return $allowed;
}

/**
 * @param  list<string>  $tokens
 */
function parseAnd(array $tokens, int &$position): bool
{
    $allowed = parseTerm($tokens, $position);

    while (($tokens[$position] ?? null) === 'AND') {
        $position++;
        $allowed = parseTerm($tokens, $position) && $allowed;
    }

    return $allowed;
}

/**
 * @param  list<string>  $tokens
 */
function parseTerm(array $tokens, int &$position): bool
{
    $token = $tokens[$position] ?? null;

    if ($token === null) {
        throw new RuntimeException('Unexpected end of SPDX expression.');
    }

    if ($token === '(') {
        $position++;
        $allowed = parseOr($tokens, $position);

        if (($tokens[$position] ?? null) !== ')') {
            throw new RuntimeException('Unclosed parenthesis in SPDX expression.');
        }

        $position++;

        return $allowed;
    }

    if (in_array($token, [')', 'AND', 'OR', 'WITH'], true)) {
        throw new RuntimeException(sprintf('Unexpected token "%s" in SPDX expression.', $token));
    }

    $position++;

    if (($tokens[$position] ?? null) === 'WITH') {
        $position += 2;

        if (! isset($tokens[$position - 1]) || in_array($tokens[$position - 1], ['(', ')', 'AND', 'OR', 'WITH'], true)) {
            throw new RuntimeException('Missing exception identifier after WITH.');
        }

        return false;
    }

    return in_array($token, PERMISSIVE_LICENSES, true);
}

function isPermissiveExpression(string $expression): bool
{
    preg_match_all('/\(|\)|\bAND\b|\bOR\b|\bWITH\b|[A-Za-z0-9.+-]+/i', $expression, $matches);

    $tokens = array_map(
        static fn (string $token): string => in_array(strtoupper($token), ['AND', 'OR', 'WITH'], true)
            ? strtoupper($token)
            : $token,
        $matches[0],
    );

    if ($tokens === [] || preg_replace('/\s+/', '', implode('', $tokens)) !== preg_replace('/\s+/', '', $expression)) {
        throw new RuntimeException(sprintf('Invalid SPDX expression "%s".', $expression));
    }

    $position = 0;
    $allowed = parseOr($tokens, $position);

    if ($position !== count($tokens)) {
        throw new RuntimeException(sprintf('Unexpected token "%s" in SPDX expression.', $tokens[$position]));
    }

    return $allowed;
}

$lockPath = dirname(__DIR__).'/composer.lock';

if (! is_file($lockPath)) {
    fwrite(STDERR, "composer.lock does not exist. Run composer install first.\n");
    exit(1);
}

try {
    $contents = file_get_contents($lockPath);

    if ($contents === false) {
        throw new RuntimeException('Unable to read composer.lock.');
    }

    $lock = licenseObject(json_decode($contents, true, 512, JSON_THROW_ON_ERROR), 'composer.lock');
    $runtimePackages = licensePackageList($lock['packages'] ?? [], 'composer.lock packages');
    $developmentPackages = licensePackageList($lock['packages-dev'] ?? [], 'composer.lock packages-dev');
} catch (JsonException|RuntimeException $exception) {
    fwrite(STDERR, "Unable to parse composer.lock: {$exception->getMessage()}\n");
    exit(1);
}

$violations = [];
$packages = array_merge($runtimePackages, $developmentPackages);

foreach ($packages as $package) {
    $name = $package['name'] ?? null;
    $licenses = $package['license'] ?? [];

    if (! is_string($name) || $name === '') {
        $violations[] = '[unknown package]: missing package name';

        continue;
    }

    if (array_key_exists($name, PACKAGE_EXCEPTIONS)) {
        continue;
    }

    if (! is_array($licenses) || $licenses === []) {
        $violations[] = "{$name}: no license metadata";

        continue;
    }

    try {
        $permissive = array_any(
            $licenses,
            static fn (mixed $license): bool => is_string($license) && isPermissiveExpression($license),
        );
    } catch (RuntimeException $exception) {
        $violations[] = "{$name}: {$exception->getMessage()}";

        continue;
    }

    if (! $permissive) {
        $violations[] = sprintf('%s: %s', $name, implode(' OR ', array_map(
            static fn (mixed $license): string => is_string($license) ? $license : get_debug_type($license),
            $licenses,
        )));
    }
}

if ($violations !== []) {
    sort($violations, SORT_STRING);
    fwrite(STDERR, "Non-permissive or unknown dependency licenses:\n - ".implode("\n - ", $violations)."\n");
    exit(1);
}

printf("License check passed for %d dependencies.\n", count($packages));
