<?php

declare(strict_types=1);

/*
 * Turn failed tests from a PHPUnit JUnit report into GitHub Actions error annotations.
 *
 *   php scripts/ci-annotate.php var/junit.xml
 *
 * CI runs this when a test step fails, so the failing test names and messages appear on the
 * run's summary page and in pull requests. GitHub shows up to 10 annotations per step.
 */

$file = $argv[1] ?? '';
if (!is_file($file)) {
    echo "No JUnit report at {$file}; the tests may not have started.\n";
    exit(0);
}
$report = simplexml_load_file($file);
if ($report === false) {
    echo "Could not read {$file}.\n";
    exit(0);
}

$escape = static fn(string $text): string => strtr($text, ['%' => '%25', "\r" => '%0D', "\n" => '%0A', ':' => '%3A', ',' => '%2C']);
$count = 0;
foreach ($report->xpath('//testcase[failure or error]') ?: [] as $case) {
    $problem = $case->failure ?? $case->error;
    $title = (string) $case['class'] . '::' . (string) $case['name'];
    // Keep annotations readable: the first part of the message and trace is enough to act on.
    $message = substr(trim((string) $problem), 0, 3000);
    printf("::error title=%s::%s\n", $escape($title), strtr($message, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']));
    ++$count;
}
echo "{$count} failing test(s) annotated.\n";
