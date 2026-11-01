<?php
/**
 * Test runner: includes every tests/Unit/*.php and tests/Integration/*.php,
 * then prints a summary. Exit code is non-zero if anything failed.
 *
 * Usage: php tests/run.php
 */

require_once(__DIR__ . '/bootstrap.php');

$dirs = array(__DIR__ . '/Unit', __DIR__ . '/Integration');
foreach ($dirs as $d) {
    if (!is_dir($d)) {
        continue;
    }
    foreach (glob($d . '/*.php') as $f) {
        require $f;
    }
}

T::summary();
