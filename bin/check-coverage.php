#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Checks that code coverage meets a minimum threshold.
 *
 * Usage: php bin/check-coverage.php <clover-file> <min-percentage>
 * Example: php bin/check-coverage.php coverage/clover.xml 80
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/check-coverage.php <clover-file> <min-percentage>\n");
    exit(1);
}

$cloverFile = $argv[1];
$minPercentage = (float) $argv[2];

if (!file_exists($cloverFile)) {
    fwrite(STDERR, sprintf("Clover file not found: %s\n", $cloverFile));
    exit(1);
}

$xml = new SimpleXMLElement(file_get_contents($cloverFile));
$metrics = $xml->xpath('//project/metrics');

if ([] === $metrics) {
    fwrite(STDERR, "No project metrics found in clover file.\n");
    exit(1);
}

$totalElements = (int) $metrics[0]['elements'];
$coveredElements = (int) $metrics[0]['coveredelements'];

if (0 === $totalElements) {
    fwrite(STDERR, "No coverable elements found.\n");
    exit(1);
}

$coverage = round($coveredElements / $totalElements * 100, 2);

echo sprintf("Code coverage: %.2f%% (%d/%d elements)\n", $coverage, $coveredElements, $totalElements);
echo sprintf("Minimum required: %.2f%%\n", $minPercentage);

if ($coverage < $minPercentage) {
    fwrite(STDERR, sprintf("FAIL: Coverage %.2f%% is below the minimum %.2f%%\n", $coverage, $minPercentage));
    exit(1);
}

echo "OK: Coverage meets the minimum threshold.\n";
