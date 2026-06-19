<?php

declare(strict_types=1);

const NPOM_INTERESTS = [
    '',
    'email-updates',
    'volunteer',
    'professional',
    'donation',
    'community',
    'other',
];

function npom_default_config(): array
{
    return require __DIR__ . '/config.example.php';
}

function npom_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = npom_default_config();
    $localConfigPath = __DIR__ . '/config.php';

    if (is_file($localConfigPath)) {
        $local = require $localConfigPath;
        if (is_array($local)) {
            $config = npom_array_merge_recursive_distinct($config, $local);
        }
    }

    return $config;
}

function npom_array_merge_recursive_distinct(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = npom_array_merge_recursive_distinct($base[$key], $value);
            continue;
        }

        $base[$key] = $value;
    }

    return $base;
}

function npom_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function npom_error_response(string $message, int $status = 400, ?Throwable $error = null): void
{
    $payload = [
        'ok' => false,
        'message' => $message,
    ];

    if (($error !== null) && (bool) (npom_config()['app']['debug'] ?? false)) {
        $payload['debug'] = $error->getMessage();
    }

    npom_json_response($payload, $status);
}

function npom_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        npom_json_response(['ok' => true]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        npom_error_response('This endpoint only accepts POST requests.', 405);
    }
}

function npom_request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function npom_string(array $data, string $key, int $max = 5000): string
{
    $value = trim((string) ($data[$key] ?? ''));
    if (mb_strlen($value) > $max) {
        $value = mb_substr($value, 0, $max);
    }

    return $value;
}

function npom_email(array $data, string $key = 'email'): string
{
    return mb_strtolower(npom_string($data, $key, 254));
}

function npom_check_honeypot(array $data): void
{
    if (trim((string) ($data['website'] ?? '')) !== '') {
        npom_json_response(['ok' => true]);
    }
}

function npom_client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $salt = (string) (npom_config()['app']['hash_salt'] ?? '');

    return hash('sha256', $salt . '|' . $ip);
}

function npom_user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

function npom_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function npom_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = npom_config()['database'] ?? [];
    $driver = (string) ($config['driver'] ?? 'sqlite');

    if ($driver === 'mysql') {
        $mysql = $config['mysql'] ?? [];
        $charset = $mysql['charset'] ?? 'utf8mb4';
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $mysql['host'] ?? '127.0.0.1',
            (int) ($mysql['port'] ?? 3306),
            $mysql['database'] ?? '',
            $charset
        );

        $pdo = new PDO($dsn, (string) ($mysql['username'] ?? ''), (string) ($mysql['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $path = (string) ($config['sqlite_path'] ?? dirname(__DIR__) . '/storage/npom.sqlite');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }

    npom_migrate($pdo, $driver);

    return $pdo;
}

function npom_migrate(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS subscribers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(254) NOT NULL UNIQUE,
                source VARCHAR(80) NOT NULL DEFAULT 'footer',
                ip_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(500) NOT NULL,
                mailgun_status VARCHAR(30) NOT NULL DEFAULT 'skipped',
                mailgun_response TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS contact_messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                email VARCHAR(254) NOT NULL,
                interest VARCHAR(60) NOT NULL DEFAULT '',
                message TEXT NOT NULL,
                ip_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(500) NOT NULL,
                mailgun_status VARCHAR(30) NOT NULL DEFAULT 'skipped',
                mailgun_response TEXT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            source TEXT NOT NULL DEFAULT 'footer',
            ip_hash TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            mailgun_status TEXT NOT NULL DEFAULT 'skipped',
            mailgun_response TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS contact_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            interest TEXT NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            ip_hash TEXT NOT NULL,
            user_agent TEXT NOT NULL,
            mailgun_status TEXT NOT NULL DEFAULT 'skipped',
            mailgun_response TEXT,
            created_at TEXT NOT NULL
        )"
    );
}

function npom_upsert_subscriber(PDO $pdo, string $email, string $source): int
{
    $now = npom_now();
    $stmt = $pdo->prepare('SELECT id FROM subscribers WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);
    $existing = $stmt->fetch();

    if ($existing) {
        $update = $pdo->prepare(
            'UPDATE subscribers
             SET source = :source, ip_hash = :ip_hash, user_agent = :user_agent, updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'source' => $source,
            'ip_hash' => npom_client_ip_hash(),
            'user_agent' => npom_user_agent(),
            'updated_at' => $now,
            'id' => (int) $existing['id'],
        ]);

        return (int) $existing['id'];
    }

    $insert = $pdo->prepare(
        'INSERT INTO subscribers (email, source, ip_hash, user_agent, created_at, updated_at)
         VALUES (:email, :source, :ip_hash, :user_agent, :created_at, :updated_at)'
    );
    $insert->execute([
        'email' => $email,
        'source' => $source,
        'ip_hash' => npom_client_ip_hash(),
        'user_agent' => npom_user_agent(),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function npom_update_subscriber_mailgun(PDO $pdo, int $id, string $status, string $response): void
{
    $stmt = $pdo->prepare(
        'UPDATE subscribers SET mailgun_status = :status, mailgun_response = :response WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'response' => mb_substr($response, 0, 5000),
        'id' => $id,
    ]);
}

function npom_insert_contact(PDO $pdo, array $contact): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO contact_messages
            (name, email, interest, message, ip_hash, user_agent, created_at)
         VALUES
            (:name, :email, :interest, :message, :ip_hash, :user_agent, :created_at)'
    );
    $stmt->execute([
        'name' => $contact['name'],
        'email' => $contact['email'],
        'interest' => $contact['interest'],
        'message' => $contact['message'],
        'ip_hash' => npom_client_ip_hash(),
        'user_agent' => npom_user_agent(),
        'created_at' => npom_now(),
    ]);

    return (int) $pdo->lastInsertId();
}

function npom_update_contact_mailgun(PDO $pdo, int $id, string $status, string $response): void
{
    $stmt = $pdo->prepare(
        'UPDATE contact_messages SET mailgun_status = :status, mailgun_response = :response WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'response' => mb_substr($response, 0, 5000),
        'id' => $id,
    ]);
}

function npom_mailgun_configured(): bool
{
    $config = npom_config()['mailgun'] ?? [];

    return (bool) ($config['enabled'] ?? false)
        && trim((string) ($config['api_key'] ?? '')) !== ''
        && trim((string) ($config['domain'] ?? '')) !== '';
}

function npom_mailgun_request(string $method, string $path, array $fields): array
{
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => 'cURL extension is not available.',
        ];
    }

    $config = npom_config()['mailgun'] ?? [];
    $endpoint = rtrim((string) ($config['endpoint'] ?? 'https://api.mailgun.net/v3'), '/');
    $apiKey = (string) ($config['api_key'] ?? '');
    $url = $endpoint . '/' . ltrim($path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_USERPWD => 'api:' . $apiKey,
        CURLOPT_TIMEOUT => 12,
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => $curlError,
        ];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => (string) $body,
    ];
}

function npom_mailgun_response_summary(array $response): string
{
    return 'HTTP ' . (int) ($response['status'] ?? 0) . ': ' . (string) ($response['body'] ?? '');
}

function npom_mailgun_status(array $response): string
{
    if ((int) ($response['status'] ?? 0) === 0) {
        return str_contains((string) ($response['body'] ?? ''), 'skipped') ? 'skipped' : 'failed';
    }

    return $response['ok'] ? 'ok' : 'failed';
}

function npom_mailgun_subscribe(string $email): array
{
    $config = npom_config()['mailgun'] ?? [];

    if (!npom_mailgun_configured() || !(bool) ($config['subscribe_to_list'] ?? false)) {
        return ['ok' => true, 'status' => 0, 'body' => 'Mailgun list subscription skipped.'];
    }

    $list = trim((string) ($config['mailing_list'] ?? ''));
    if ($list === '') {
        return ['ok' => false, 'status' => 0, 'body' => 'Mailgun mailing_list is not configured.'];
    }

    return npom_mailgun_request('POST', '/lists/' . rawurlencode($list) . '/members', [
        'address' => $email,
        'subscribed' => 'yes',
        'upsert' => 'yes',
    ]);
}

function npom_mailgun_notify(string $subject, string $text, ?string $replyTo = null): array
{
    $config = npom_config()['mailgun'] ?? [];
    $notifyTo = trim((string) ($config['notify_to'] ?? ''));

    if (!npom_mailgun_configured() || $notifyTo === '') {
        return ['ok' => true, 'status' => 0, 'body' => 'Mailgun notification skipped.'];
    }

    $fields = [
        'from' => (string) ($config['from'] ?? 'North Preston Outreach Ministry <no-reply@example.com>'),
        'to' => $notifyTo,
        'subject' => $subject,
        'text' => $text,
    ];

    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $fields['h:Reply-To'] = $replyTo;
    }

    return npom_mailgun_request('POST', '/' . rawurlencode((string) $config['domain']) . '/messages', $fields);
}
