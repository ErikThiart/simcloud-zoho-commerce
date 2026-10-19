<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bootstrap.php';
umask(0077);
try {
    $config = sc_config();
    $lock = fopen(dirname($config['database']) . '/worker.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
    [$zoho, $simcloud, $mailer] = sc_bindings($config);
    sc_work(sc_db($config), $config, $zoho, $simcloud, $mailer);
    flock($lock, LOCK_UN); fclose($lock);
} catch (Throwable $error) {
    sc_log('worker_failed');
    fwrite(STDERR, "SIMcloud worker failed. Check private configuration and service availability. No failed create is automatically retried.\n");
    exit(1);
}
