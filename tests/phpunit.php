<?php

/**
 * Runs PHPUnit from wherever Composer installed it.
 *
 * `composer test` used to call a bare `phpunit`, which Composer only puts on
 * the PATH when this package has its own `vendor/`. Developed in place inside
 * a Craft project (a path repository, as usual for a plugin), it has none, and
 * the script failed with "phpunit: command not found" before running a single
 * test. This looks in the same places tests/bootstrap.php looks for the
 * autoloader, and passes every argument through.
 */

$candidates = [
    __DIR__ . '/../vendor/bin/phpunit',
    __DIR__ . '/../../../vendor/bin/phpunit',
    __DIR__ . '/../../../../vendor/bin/phpunit',
];

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $command = array_merge([PHP_BINARY, $candidate], array_slice($argv, 1));
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, dirname(__DIR__));

        exit($process === false ? 1 : proc_close($process));
    }
}

fwrite(STDERR, "PHPUnit not found. Run `composer install` in this package or in the Craft project that contains it.\n");
exit(1);
