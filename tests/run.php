<?php

declare(strict_types=1);

/**
 * Runs the plugin's logic tests:  php tests/run.php
 */

require_once __DIR__ . '/bootstrap.php';

$tests = [];
foreach (\glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require_once $file;
    $class = \basename($file, '.php');
    if (\class_exists($class)) {
        $tests[] = $class;
    }
}

foreach ($tests as $class) {
    (new $class())->run();
}

$failed = \count(TestCase::$failures);
echo \PHP_EOL;
foreach (TestCase::$failures as $failure) {
    echo "FAIL  " . $failure . \PHP_EOL . \PHP_EOL;
}
\printf(
    "%s  %d assertions passed, %d failed  (%d test classes)%s",
    $failed === 0 ? 'OK  ' : 'FAIL',
    TestCase::$passed,
    $failed,
    \count($tests),
    \PHP_EOL
);

exit($failed === 0 ? 0 : 1);
