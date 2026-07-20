<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php <cobertura.xml> <minimum-lines> <minimum-branches>\n");
    exit(2);
}

[$script, $report, $minimumLines, $minimumBranches] = $argv;
unset($script);

if (! is_file($report)) {
    fwrite(STDERR, "Coverage report was not generated: {$report}\n");
    exit(1);
}

$xml = simplexml_load_file($report);
if ($xml === false || $xml->getName() !== 'coverage') {
    fwrite(STDERR, "Coverage report does not contain Cobertura project metrics.\n");
    exit(1);
}

$metrics = $xml->attributes();
$statements = (int) ($metrics['lines-valid'] ?? 0);
$coveredStatements = (int) ($metrics['lines-covered'] ?? 0);
$conditionals = (int) ($metrics['branches-valid'] ?? 0);
$coveredConditionals = (int) ($metrics['branches-covered'] ?? 0);

if ($statements === 0 || $conditionals === 0) {
    fwrite(STDERR, "Coverage report did not contain line and branch metrics; run PHPUnit with Xdebug path coverage enabled.\n");
    exit(1);
}

$lines = ($coveredStatements / $statements) * 100;
$branches = ($coveredConditionals / $conditionals) * 100;
printf("Backend coverage: %.2f%% lines, %.2f%% branches.\n", $lines, $branches);

if ($lines < (float) $minimumLines || $branches < (float) $minimumBranches) {
    fwrite(STDERR, sprintf("Required backend coverage is %s%% lines and %s%% branches.\n", $minimumLines, $minimumBranches));
    exit(1);
}
