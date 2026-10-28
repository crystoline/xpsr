<?php

declare(strict_types=1);

/*
 * Turn PHPUnit results into GitHub Actions error annotations.
 *
 *   php scripts/ci-annotate.php var/junit.xml [var/phpunit.log]
 *
 * CI runs this when a test step fails, so the cause appears on the run's summary page and in
 * pull requests. Each failed or errored test becomes one annotation. A run can also fail without
 * a failed test, because this project fails on deprecations and warnings; JUnit reports do not
 * contain those, so PHPUnit's own summary of them is taken from the captured output instead.
 * GitHub shows up to 10 annotations per step.
 */

$junit = $argv[1] ?? '';
$log = $argv[2] ?? '';

$escapeMessage = static fn(string $text): string => strtr($text, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
$escapeProperty = static fn(string $text): string => strtr($text, ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']);
$count = 0;

if (is_file($junit) && ($report = simplexml_load_file($junit)) !== false) {
    foreach ($report->xpath('//testcase[failure or error]') ?: [] as $case) {
        $problem = $case->failure ?? $case->error;
        $title = (string) $case['class'] . '::' . (string) $case['name'];
        printf("::error title=%s::%s\n", $escapeProperty($title), $escapeMessage(substr(trim((string) $problem), 0, 3000)));
        ++$count;
    }
} else {
    echo "No JUnit report at {$junit}; the tests may not have started.\n";
}

// Deprecations, warnings, notices, and risky tests: PHPUnit prints "N tests triggered M PHP
// deprecations:" (and similar) sections before its final summary line.
if ($count === 0 && is_file($log)) {
    $output = (string) preg_replace('/\e\[[0-9;]*m/', '', (string) file_get_contents($log));
    if (preg_match('/^\d+ tests? triggered .*?(?=^(?:OK, but there were issues!|FAILURES!|ERRORS!)$)/ms', $output, $issues)) {
        $summary = preg_match('/^(?:OK, but there were issues!|FAILURES!|ERRORS!)\R(.+)$/m', $output, $line) ? trim($line[1]) : 'PHPUnit reported issues';
        printf("::error title=%s::%s\n", $escapeProperty($summary), $escapeMessage(substr(trim($issues[0]), 0, 3000)));
        ++$count;
    }
}

echo "{$count} annotation(s) written.\n";
