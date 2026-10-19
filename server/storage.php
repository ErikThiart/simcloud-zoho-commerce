<?php
declare(strict_types=1);

function sc_db(array $config): PDO {
    $path = $config['database'];
    if ($path !== ':memory:' && (!str_starts_with($path, '/') || !is_dir(dirname($path)))) {
        throw new RuntimeException('Create the private database directory before starting the connector.');
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=10000');
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS products (sku TEXT PRIMARY KEY, product_id TEXT NOT NULL DEFAULT \'\', variant_id TEXT NOT NULL DEFAULT \'\', state TEXT NOT NULL, snapshot TEXT NOT NULL, message TEXT NOT NULL DEFAULT \'\')');
    $db->exec('CREATE TABLE IF NOT EXISTS ledger (id INTEGER PRIMARY KEY, order_id TEXT NOT NULL, line_id TEXT NOT NULL, reference TEXT NOT NULL UNIQUE, state TEXT NOT NULL, snapshot TEXT NOT NULL, provider_id TEXT NOT NULL DEFAULT \'\', provider_reference TEXT NOT NULL DEFAULT \'\', result TEXT NOT NULL DEFAULT \'\', polls INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, next_poll INTEGER NOT NULL DEFAULT 0, notified_at INTEGER NOT NULL DEFAULT 0, message TEXT NOT NULL DEFAULT \'\', UNIQUE(order_id,line_id))');
    $db->exec('CREATE INDEX IF NOT EXISTS ledger_work ON ledger(state,next_poll)');
    $stmt = $db->prepare('INSERT OR IGNORE INTO settings(name,value) VALUES(\'organization_id\',?)');
    $stmt->execute([$config['organization_id']]);
    if ($db->query('SELECT value FROM settings WHERE name=\'organization_id\'')->fetchColumn() !== $config['organization_id']) {
        throw new RuntimeException('Database belongs to a different Zoho organization.');
    }
    if ($path !== ':memory:') { chmod($path, 0600); }
    return $db;
}

function sc_encrypt(array $value, array $config): string {
    $key = base64_decode($config['encryption_key'], true);
    if ($key === false || strlen($key) !== 32) { throw new RuntimeException('Invalid encryption key.'); }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(json_encode($value, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'simcloud-zoho:v2', 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) { throw new RuntimeException('Authenticated encryption failed.'); }
    return 'v2:' . base64_encode($nonce . $tag . $ciphertext);
}

function sc_decrypt(string $value, array $config): array {
    $key = base64_decode($config['encryption_key'], true);
    if ($key === false || strlen($key) !== 32) { throw new RuntimeException('Invalid encryption key.'); }
    if (str_starts_with($value, 'v2:')) {
        $bytes = base64_decode(substr($value, 3), true);
        if ($bytes === false || strlen($bytes) < 30) { throw new RuntimeException('Encrypted record cannot be read.'); }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), 'simcloud-zoho:v2');
    } else {
        // Read existing alpha ledgers without rewriting or losing their encrypted data.
        if (!function_exists('sodium_crypto_secretbox_open')) { throw new RuntimeException('Legacy encrypted records require the Sodium extension for migration.'); }
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { throw new RuntimeException('Encrypted record cannot be read.'); }
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    }
    if ($plain === false) { throw new RuntimeException('Encrypted record integrity check failed.'); }
    $decoded = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) { throw new RuntimeException('Invalid encrypted record.'); }
    return $decoded;
}

function sc_transition(PDO $db, int $id, string $from, string $to, array $values = []): bool {
    $allowed = ['provider_id', 'provider_reference', 'result', 'polls', 'next_poll', 'message', 'notified_at'];
    $sql = 'UPDATE ledger SET state=?,updated_at=?';
    $params = [$to, time()];
    foreach ($values as $key => $value) {
        if (!in_array($key, $allowed, true)) { throw new RuntimeException('Unsupported ledger field.'); }
        $sql .= ',' . $key . '=?'; $params[] = $value;
    }
    $sql .= ' WHERE id=? AND state=?';
    $params[] = $id; $params[] = $from;
    $stmt = $db->prepare($sql); $stmt->execute($params);
    return $stmt->rowCount() === 1;
}

function sc_row(PDO $db, int $id): array {
    $stmt = $db->prepare('SELECT * FROM ledger WHERE id=?'); $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function sc_log(string $event, array $row = [], int $httpCode = 0): void {
    // Event labels are controlled by this source. Never pass provider bodies, exceptions or contact data.
    error_log(json_encode(['component' => 'simcloud-zoho', 'event' => $event, 'ledger_id' => (int) ($row['id'] ?? 0),
        'order_id' => sc_id($row['order_id'] ?? ''), 'provider_id' => sc_id($row['provider_id'] ?? ''), 'http_status' => $httpCode], JSON_THROW_ON_ERROR));
}
