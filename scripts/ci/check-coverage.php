<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/ci/check-coverage.php <clover.xml> <min-percent>\n");
    exit(2);
}

$file = $argv[1];
$minimum = (float) $argv[2];

if (! is_file($file)) {
    fwrite(STDERR, "Coverage file not found: {$file}\n");
    exit(1);
}

$xml = simplexml_load_file($file);
if (! $xml) {
    fwrite(STDERR, "Could not parse coverage file: {$file}\n");
    exit(1);
}

$metrics = $xml->xpath('/coverage/project/metrics')[0] ?? null;
if (! $metrics) {
    fwrite(STDERR, "Coverage metrics not found in: {$file}\n");
    exit(1);
}

$covered = (int) ($metrics['coveredstatements'] ?? 0);
$statements = (int) ($metrics['statements'] ?? 0);
$coverage = $statements > 0 ? round(($covered / $statements) * 100, 2) : 0.0;

echo "Line coverage: {$coverage}% ({$covered}/{$statements}), minimum: {$minimum}%\n";

if ($coverage < $minimum) {
    fwrite(STDERR, "Coverage gate failed.\n");
    exit(1);
}

exit(0);
