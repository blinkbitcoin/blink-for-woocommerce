<?php
/**
 * Polices @codeCoverageIgnore annotations.
 *
 * An exemption from the coverage gate is a decision, and decisions need a
 * reason someone can disagree with. Every annotation must therefore:
 *
 *   1. be preceded by a "// coverage-ignore-reason:" comment of at least 20
 *      characters;
 *   2. appear in the register in docs/testing.md;
 *   3. not cover conditional logic. If a branch cannot be reached, the answer
 *      is to delete it, not to hide it from the report.
 *
 * Usage: php bin/check-coverage-ignores.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$sourceDir = $root . '/src';
$registerPath = $root . '/docs/testing.md';

const MIN_REASON_LENGTH = 20;

/** Constructs that mean the ignored code has branches. */
const CONDITIONAL_TOKENS = [
  T_IF,
  T_ELSE,
  T_ELSEIF,
  T_SWITCH,
  T_MATCH,
  T_FOREACH,
  T_FOR,
  T_WHILE,
  T_DO,
  T_CATCH,
  T_COALESCE,
  T_BOOLEAN_AND,
  T_BOOLEAN_OR,
  T_LOGICAL_AND,
  T_LOGICAL_OR,
];

$failures = [];
$found = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir));
foreach ($files as $file) {
  if (!$file->isFile() || $file->getExtension() !== 'php') {
    continue;
  }

  $path = str_replace($root . '/', '', $file->getPathname());
  $contents = (string) file_get_contents($file->getPathname());

  if (!str_contains($contents, '@codeCoverageIgnore')) {
    continue;
  }

  $lines = explode("\n", $contents);
  foreach ($lines as $index => $line) {
    if (!str_contains($line, '@codeCoverageIgnore')) {
      continue;
    }

    $lineNumber = $index + 1;
    $found[] = $path . ':' . $lineNumber;

    // 1. A reason, on one of the few lines above.
    $reason = '';
    for ($look = $index; $look >= max(0, $index - 5); $look--) {
      if (preg_match('/coverage-ignore-reason:\s*(.+)$/', $lines[$look], $m)) {
        $reason = trim($m[1]);
        break;
      }
    }

    if ($reason === '') {
      $failures[] = sprintf(
        '%s:%d has no "// coverage-ignore-reason:" comment above it.',
        $path,
        $lineNumber
      );
    } elseif (strlen($reason) < MIN_REASON_LENGTH) {
      $failures[] = sprintf(
        '%s:%d has a reason of %d characters; at least %d are required. Say what makes this unreachable.',
        $path,
        $lineNumber,
        strlen($reason),
        MIN_REASON_LENGTH
      );
    }

    // 2. Registered in the testing guide.
    $register = is_file($registerPath) ? (string) file_get_contents($registerPath) : '';
    if (!str_contains($register, $path)) {
      $failures[] = sprintf(
        '%s:%d is not listed in the coverage ignore register in docs/testing.md.',
        $path,
        $lineNumber
      );
    }
  }

  // 3. No conditionals inside an ignored region.
  foreach (conditionalTokensInIgnoredRegions($contents) as $problem) {
    $failures[] = $path . ': ' . $problem;
  }
}

if ($failures === []) {
  printf(
    "Coverage ignore check passed (%d annotation%s).\n",
    count($found),
    count($found) === 1 ? '' : 's'
  );
  exit(0);
}

fwrite(STDERR, "Coverage ignore check FAILED\n\n");
foreach ($failures as $failure) {
  fwrite(STDERR, '  ' . $failure . "\n");
}
fwrite(
  STDERR,
  "\nSee the coverage ignore register in docs/testing.md.\n" .
    "If a branch cannot be reached, delete it rather than exempting it.\n"
);
exit(1);

/**
 * Finds conditional constructs between @codeCoverageIgnoreStart and End.
 *
 * @return list<string>
 */
function conditionalTokensInIgnoredRegions(string $contents): array {
  $tokens = token_get_all($contents);
  $problems = [];
  $inIgnoredRegion = false;

  foreach ($tokens as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
      if (str_contains($token[1], '@codeCoverageIgnoreStart')) {
        $inIgnoredRegion = true;
      }
      if (str_contains($token[1], '@codeCoverageIgnoreEnd')) {
        $inIgnoredRegion = false;
      }
      continue;
    }

    if (!$inIgnoredRegion || !is_array($token)) {
      continue;
    }

    if (in_array($token[0], CONDITIONAL_TOKENS, true)) {
      $problems[] = sprintf(
        'line %d ignores conditional logic ("%s"). Conditional code is never unreachable glue; ' .
          'if the branch cannot run, delete it.',
        $token[2],
        trim($token[1])
      );
    }
  }

  return $problems;
}
