#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @return array<string, mixed>
 */
function requirementsObject(mixed $value, string $context): array
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

$root = dirname(__DIR__);
$composerPath = $root.'/composer.json';

if (! is_file($composerPath)) {
    fwrite(STDERR, "composer.json does not exist.\n");
    exit(1);
}

try {
    $contents = file_get_contents($composerPath);

    if ($contents === false) {
        throw new RuntimeException('Unable to read composer.json.');
    }

    $composer = requirementsObject(json_decode($contents, true, 512, JSON_THROW_ON_ERROR), 'composer.json');
} catch (JsonException|RuntimeException $exception) {
    fwrite(STDERR, "Unable to parse composer.json: {$exception->getMessage()}\n");
    exit(1);
}

$requirements = requirementsObject($composer['require'] ?? [], 'composer.json require');

if ($requirements === []) {
    fwrite(STDERR, "composer.json does not define runtime requirements.\n");
    exit(1);
}

ksort($requirements, SORT_STRING);
$rows = [];

foreach ($requirements as $package => $constraint) {
    if (! is_string($constraint)) {
        fwrite(STDERR, "Every runtime requirement must use a string constraint.\n");
        exit(1);
    }

    $label = match (true) {
        $package === 'php' => 'PHP',
        str_starts_with($package, 'ext-') => sprintf('PHP extension `%s`', substr($package, 4)),
        default => "`{$package}`",
    };
    $label = str_replace('|', '\\|', $label);
    $constraint = str_replace('|', '\\|', $constraint);

    $rows[] = "| {$label} | `{$constraint}` |";
}

$contents = <<<'MARKDOWN'
---
title: Requirements
weight: 30
description: Runtime versions and dependencies enforced by Composer.
---

# Requirements

These are the runtime requirements enforced by `composer.json`.

| Requirement | Version |
| --- | --- |
MARKDOWN;

$contents .= "\n".implode("\n", $rows)."\n";
$docsDirectory = $root.'/docs';

if (! is_dir($docsDirectory) && ! mkdir($docsDirectory, 0777, true) && ! is_dir($docsDirectory)) {
    fwrite(STDERR, "Unable to create docs directory.\n");
    exit(1);
}

$written = file_put_contents($docsDirectory.'/requirements.md', $contents);

if ($written === false) {
    fwrite(STDERR, "Unable to write docs/requirements.md.\n");
    exit(1);
}

fwrite(STDOUT, "Generated docs/requirements.md from composer.json.\n");
