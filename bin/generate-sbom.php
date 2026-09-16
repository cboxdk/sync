#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function sbomObject(mixed $value, string $context): array
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
function sbomPackageList(mixed $value, string $context): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$context} must be a JSON array.");
    }

    $packages = [];

    foreach ($value as $index => $package) {
        $packages[] = sbomObject($package, "{$context}[{$index}]");
    }

    return $packages;
}

/**
 * @param  array<string, mixed>  $package
 * @return array<string, mixed>
 */
function component(array $package, string $scope): array
{
    $packageName = $package['name'] ?? null;
    $version = $package['version'] ?? null;

    if (! is_string($packageName) || $packageName === '' || ! is_string($version) || $version === '') {
        throw new RuntimeException('Every locked package must have a non-empty name and version.');
    }

    [$group, $name] = array_pad(explode('/', $packageName, 2), 2, '');
    $purl = sprintf('pkg:composer/%s@%s', $packageName, rawurlencode($version));
    $component = [
        'type' => 'library',
        'bom-ref' => $purl,
        'group' => $group,
        'name' => $name,
        'version' => $version,
        'scope' => $scope,
        'purl' => $purl,
    ];

    if (isset($package['description']) && is_string($package['description']) && $package['description'] !== '') {
        $component['description'] = $package['description'];
    }

    if (isset($package['license']) && is_array($package['license']) && $package['license'] !== []) {
        $licenses = array_values(array_filter($package['license'], 'is_string'));
        sort($licenses, SORT_STRING);

        if ($licenses !== []) {
            $component['licenses'] = [[
                'expression' => implode(' OR ', array_map(
                    static fn (string $license): string => str_contains($license, ' ') ? "({$license})" : $license,
                    $licenses,
                )),
            ]];
        }
    }

    $externalReferences = [];

    foreach ([['source', 'vcs'], ['dist', 'distribution']] as [$key, $type]) {
        $reference = $package[$key] ?? null;
        $url = is_array($reference) ? ($reference['url'] ?? null) : null;

        if (is_string($url) && $url !== '') {
            $externalReferences[] = ['type' => $type, 'url' => $url];
        }
    }

    if ($externalReferences !== []) {
        usort(
            $externalReferences,
            static fn (array $left, array $right): int => [$left['type'], $left['url']] <=> [$right['type'], $right['url']],
        );
        $component['externalReferences'] = $externalReferences;
    }

    return $component;
}

function uuidV5(string $name): string
{
    $namespace = hex2bin('6ba7b8119dad11d180b400c04fd430c8');

    if ($namespace === false) {
        throw new RuntimeException('Unable to initialize UUID namespace.');
    }

    $bytes = substr(sha1($namespace.$name, true), 0, 16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x50);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12),
    );
}

$root = dirname(__DIR__);
$lockPath = $root.'/composer.lock';
$composerPath = $root.'/composer.json';

if (! is_file($lockPath) || ! is_file($composerPath)) {
    fwrite(STDERR, "composer.json and composer.lock are required.\n");
    exit(1);
}

try {
    $lockContents = file_get_contents($lockPath);
    $composerContents = file_get_contents($composerPath);

    if ($lockContents === false || $composerContents === false) {
        throw new RuntimeException('Unable to read Composer metadata.');
    }

    $lock = sbomObject(json_decode($lockContents, true, 512, JSON_THROW_ON_ERROR), 'composer.lock');
    $composer = sbomObject(json_decode($composerContents, true, 512, JSON_THROW_ON_ERROR), 'composer.json');
    $runtimePackages = sbomPackageList($lock['packages'] ?? [], 'composer.lock packages');
    $developmentPackages = sbomPackageList($lock['packages-dev'] ?? [], 'composer.lock packages-dev');
} catch (JsonException|RuntimeException $exception) {
    fwrite(STDERR, "Unable to parse Composer metadata: {$exception->getMessage()}\n");
    exit(1);
}

$components = [];

try {
    foreach ($runtimePackages as $package) {
        $components[] = component($package, 'required');
    }

    foreach ($developmentPackages as $package) {
        $components[] = component($package, 'optional');
    }
} catch (RuntimeException $exception) {
    fwrite(STDERR, "Unable to generate SBOM: {$exception->getMessage()}\n");
    exit(1);
}

usort($components, static fn (array $left, array $right): int => $left['bom-ref'] <=> $right['bom-ref']);

$rootName = is_string($composer['name'] ?? null) ? $composer['name'] : 'cboxdk/sync';
[$rootGroup, $rootComponentName] = array_pad(explode('/', $rootName, 2), 2, '');
$rootComponent = [
    'type' => is_string($composer['type'] ?? null) ? $composer['type'] : 'library',
    'bom-ref' => 'pkg:composer/'.$rootName,
    'group' => $rootGroup,
    'name' => $rootComponentName,
];

if (is_string($composer['description'] ?? null) && $composer['description'] !== '') {
    $rootComponent['description'] = $composer['description'];
}

$identity = json_encode($components, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => 'urn:uuid:'.uuidV5($identity),
    'version' => 1,
    'metadata' => [
        'component' => $rootComponent,
    ],
    'components' => $components,
];

$written = file_put_contents(
    $root.'/sbom.json',
    json_encode($bom, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
);

if ($written === false) {
    fwrite(STDERR, "Unable to write sbom.json.\n");
    exit(1);
}

printf("Generated sbom.json with %d components.\n", count($components));
