<?php

declare(strict_types=1);

/**
 * Syntax-checks every PHP example in the documentation.
 *
 * An example is often the first code an adopter copies, and a fragment that
 * does not even parse costs them the time it takes to find out. This cannot
 * prove an example is RIGHT - fragments lean on context they do not show - but
 * it proves every one of them is PHP, which is where the last three broken
 * examples failed.
 *
 * A block that is deliberately not standalone PHP can opt out with the fence
 * info string `php no-lint`.
 */
$root = dirname(__DIR__);
$files = array_merge(
    [$root.'/README.md'],
    iterator_to_array((function () use ($root): Generator {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/docs', FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'md') {
                yield $file->getPathname();
            }
        }
    })(), false),
);
sort($files);

$failures = 0;
$checked = 0;
$scratch = sys_get_temp_dir().'/lint-docs-'.getmypid().'.php';
foreach ($files as $path) {
    $markdown = file_get_contents($path);
    if ($markdown === false) {
        continue;
    }
    preg_match_all('/^```php([^\n]*)\n(.*?)^```/ms', $markdown, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($blocks as $block) {
        if (str_contains($block[1][0], 'no-lint')) {
            continue;
        }
        $code = $block[2][0];
        $source = str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n".$code;
        file_put_contents($scratch, $source);
        $output = [];
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($scratch).' 2>&1', $output, $status);
        $checked++;
        if ($status !== 0) {
            $failures++;
            $line = substr_count(substr($markdown, 0, $block[2][1]), "\n") + 1;
            fwrite(STDERR, sprintf("%s:%d\n  %s\n", substr($path, strlen($root) + 1), $line, trim(preg_replace('/ in .*? on line/', ' on line', implode("\n  ", $output)) ?? '')));
        }
    }
}
@unlink($scratch);

fwrite($failures === 0 ? STDOUT : STDERR, sprintf("%d of %d PHP examples in the docs parse.\n", $checked - $failures, $checked));
exit($failures === 0 ? 0 : 1);
