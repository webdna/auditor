<?php

/**
 * The resave benchmark (TS-12 step 3, BR-36): re-saves a section's entries with Auditor off and
 * on, a few runs each, and prints the median overhead. Not part of `composer check` — timing is
 * noisy on shared CI runners — so it is run by hand, and its result is recorded where it is used.
 *
 *   docker compose run --rm php php tests/bench/resave.php --entries=1000 --runs=3
 *
 * The last line is "overhead: N%"; the exit status is 1 when N is above 10, or when the run failed.
 */

$options = getopt('', ['entries::', 'runs::']);
$entries = (int)($options['entries'] ?? 1000);
$runs = (int)($options['runs'] ?? 3);
if ($entries < 1 || $runs < 1) {
    fwrite(STDERR, "--entries and --runs must be at least 1\n");
    exit(64);
}

$root = dirname(__DIR__, 2);
$result = "$root/tests/_output/bench-resave.json";
@unlink($result);

putenv('AUDITOR_BENCH=1');
putenv("AUDITOR_BENCH_ENTRIES=$entries");
putenv("AUDITOR_BENCH_RUNS=$runs");

echo "Re-saving $entries entries, $runs runs each way…\n";
exec(sprintf('cd %s && vendor/bin/codecept run bench --no-colors 2>&1', escapeshellarg($root)), $output, $status);
if ($status !== 0 || !is_file($result)) {
    echo implode("\n", $output), "\n";
    fwrite(STDERR, "The benchmark did not complete.\n");
    exit(1);
}

$data = json_decode((string)file_get_contents($result), true);
foreach (['off', 'on'] as $side) {
    foreach ($data['runs'][$side] as $i => $seconds) {
        printf("%-3s run %d: %.2fs\n", $side, $i + 1, $seconds);
    }
}
printf("median off: %.2fs, median on: %.2fs\n", $data['medianOff'], $data['medianOn']);
printf("overhead: %.1f%%\n", $data['overhead']);

exit($data['overhead'] <= 10 ? 0 : 1);
