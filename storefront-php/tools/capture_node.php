<?php
declare(strict_types=1);

/**
 * Records the Node API's answers as reference files.
 *
 *   php tools/capture_node.php http://localhost:4000 [--origin=http://localhost:3000]
 *
 * Only localhost / 127.0.0.1 is accepted. The Node API must already be running (this script starts nothing) with the origin
 * in its WEB_ORIGIN list and with an ERP in mock mode. It creates ONE throw-away test account (pf-ref-...@example.test).
 * Writes tests/reference/<case>.json. The signup limit is 10 per hour and the login limit 10 per 15 minutes: do not run it
 * more than a few times in a row, or restart the Node API between runs.
 */
require __DIR__ . '/lib/scenario.php';

$args = pf_parse_args($argv, 'Usage: php tools/capture_node.php http://localhost:<port> [--origin=http://localhost:3000]');
$email = 'pf-ref-' . bin2hex(random_bytes(4)) . '@example.test';
$password = 'RefTest2026' . bin2hex(random_bytes(2));

$results = pf_run($args['base'], $args['origin'], pf_scenario($email, $password, $args['origin']), $email);

if (!is_dir($args['dir']) && !mkdir($args['dir'], 0775, true) && !is_dir($args['dir'])) {
    fwrite(STDERR, "Cannot create tests/reference.\n");
    exit(3);
}
foreach ($results as $case => $result) {
    $file = $args['dir'] . '/' . $case . '.json';
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($file, $json . "\n") === false) {
        fwrite(STDERR, "Cannot write $case.json\n");
        exit(3);
    }
    echo str_pad($case, 32) . $result['status'] . "\n";
}
echo count($results) . " reference files written to tests/reference/\n";
exit(0);
