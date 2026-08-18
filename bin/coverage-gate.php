<?php
/**
 * Fails the build unless every gated file has 100% line AND branch coverage.
 *
 * Reads php-code-coverage's own exported object rather than the Cobertura XML.
 * Cobertura only records per-line "condition-coverage", which is weaker than
 * php-code-coverage's branch model and would quietly downgrade this to a line
 * gate: a file reported 100% by Cobertura measured 60/61 branches here.
 *
 * Path coverage is deliberately not gated. It is combinatorial -- a fully
 * covered class routinely sits at 25% paths -- so requiring it would mean
 * writing tests for combinations that cannot occur.
 *
 * Usage: php bin/coverage-gate.php [path/to/merged.cov]
 */

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\CodeCoverage;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

$reportPath = $argv[1] ?? $root . '/build/coverage/merged.cov';
$configPath = $root . '/coverage-gate.json';

if (!is_file($reportPath)) {
  fwrite(STDERR, "Coverage export not found at {$reportPath}\nRun bin/run-coverage.sh first.\n");
  exit(1);
}
if (!is_file($configPath)) {
  fwrite(STDERR, "Missing {$configPath}\n");
  exit(1);
}

$config = json_decode((string) file_get_contents($configPath), true);
if (!is_array($config) || !is_array($config['include'] ?? null)) {
  fwrite(STDERR, "coverage-gate.json must define an \"include\" array.\n");
  exit(1);
}
$include = $config['include'];
$exclude = is_array($config['exclude'] ?? null) ? $config['exclude'] : [];

$coverage = require $reportPath;
if (!$coverage instanceof CodeCoverage) {
  fwrite(STDERR, "{$reportPath} did not contain a CodeCoverage object.\n");
  exit(1);
}

$relative = static function (string $path) use ($root): string {
  $path = str_replace('\\', '/', $path);
  $prefix = str_replace('\\', '/', $root) . '/';

  return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
};

$isGated = static function (string $file) use ($include, $exclude): bool {
  foreach ($exclude as $prefix) {
    if (str_starts_with($file, $prefix)) {
      return false;
    }
  }
  foreach ($include as $prefix) {
    if (str_starts_with($file, $prefix)) {
      return true;
    }
  }

  return false;
};

$failures = [];
$checked = 0;

$data = $coverage->getData();
$lineCoverage = $data->lineCoverage();
$functionCoverage = $data->functionCoverage();

foreach ($lineCoverage as $absolute => $lines) {
  $path = $relative($absolute);
  if (!$isGated($path)) {
    continue;
  }

  // Interfaces and abstract declarations have no executable lines. They still
  // register a branch that can never be executed, so counting them would make
  // the gate permanently unsatisfiable.
  $executable = array_filter($lines, static fn($tests): bool => is_array($tests));
  if ($executable === []) {
    continue;
  }

  $checked++;
  $problems = [];

  $uncoveredLines = array_keys(
    array_filter($executable, static fn(array $tests): bool => $tests === [])
  );
  if ($uncoveredLines !== []) {
    $problems[] = 'uncovered lines: ' . implode(', ', $uncoveredLines);
  }

  $missedBranches = [];
  foreach ($functionCoverage[$absolute] ?? [] as $name => $function) {
    foreach ($function['branches'] ?? [] as $branch) {
      if ((int) ($branch['hit'] ?? 0) === 0) {
        $missedBranches[] = sprintf(
          '%s() lines %d-%d',
          $name,
          (int) ($branch['line_start'] ?? 0),
          (int) ($branch['line_end'] ?? 0)
        );
      }
    }
  }
  if ($missedBranches !== []) {
    $problems[] = 'unexecuted branches: ' . implode('; ', array_unique($missedBranches));
  }

  if ($problems !== []) {
    $failures[$path] = $problems;
  }
}

if ($checked === 0) {
  fwrite(STDERR, "No gated files found in the coverage export.\n");
  fwrite(STDERR, "Either coverage-gate.json's include list is wrong, or the suites did not run.\n");
  exit(1);
}

if ($failures === []) {
  fwrite(STDOUT, "Coverage gate passed: {$checked} file(s) at 100% line and branch coverage.\n");
  exit(0);
}

fwrite(STDERR, "Coverage gate FAILED\n\n");
foreach ($failures as $path => $problems) {
  fwrite(STDERR, $path . "\n");
  foreach ($problems as $problem) {
    fwrite(STDERR, '  ' . $problem . "\n");
  }
  fwrite(STDERR, "\n");
}
fwrite(STDERR, sprintf("%d of %d gated file(s) below 100%%.\n", count($failures), $checked));
exit(1);
