<?php
declare(strict_types=1);

/**
 * Replays the same scenario against the PHP API and compares it with tests/reference/*.json (made by capture_node.php).
 *
 *   php tools/compare_php.php http://localhost:8080 [--origin=http://localhost:3000]
 *
 * Only localhost / 127.0.0.1 is accepted. The PHP server must already be running (this script starts nothing), against a
 * throw-away database, with an ERP in mock mode and the origin in its WEB_ORIGIN list. Compares status, headers, cookie flags,
 * key order, types (and values, except for the catalogue cases). Exit 0 = no difference, 1 = differences, 2 = bad arguments,
 * 3 = no server or missing reference files.
 */
require __DIR__ . '/lib/scenario.php';

$args = pf_parse_args($argv, 'Usage: php tools/compare_php.php http://localhost:<port> [--origin=http://localhost:3000]');
$email = 'pf-ref-' . bin2hex(random_bytes(4)) . '@example.test';
$password = 'RefTest2026' . bin2hex(random_bytes(2));

$steps = pf_scenario($email, $password, $args['origin']);
foreach ($steps as $step) {
    if (!is_file($args['dir'] . '/' . $step['case'] . '.json')) {
        fwrite(STDERR, 'Missing reference file for case ' . $step['case'] . ". Run tools/capture_node.php first.\n");
        exit(3);
    }
}

$results = pf_run($args['base'], $args['origin'], $steps, $email);

$failed = 0;
foreach ($results as $case => $now) {
    $ref = json_decode((string) file_get_contents($args['dir'] . '/' . $case . '.json'), true);
    if (!is_array($ref)) {
        fwrite(STDERR, "Reference file for $case is not valid JSON.\n");
        exit(3);
    }
    $diff = pf_diff($ref, $now);
    if ($diff === []) {
        echo "ok    $case\n";
        continue;
    }
    $failed++;
    echo "FAIL  $case\n";
    foreach ($diff as $line) {
        echo "        $line\n";
    }
}
echo ($failed === 0 ? 'No differences in ' . count($results) . " cases.\n" : "$failed of " . count($results) . " cases differ.\n");
exit($failed === 0 ? 0 : 1);
