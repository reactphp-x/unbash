<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use function ReactphpX\Unbash\parse;

$source = $argv[1] ?? 'if [ -f "$1" ]; then cat "$1"; fi';

$ast = parse($source);

echo json_encode($ast, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
