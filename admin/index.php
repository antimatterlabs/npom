<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api/bootstrap.php';

$config = npom_config()['admin'] ?? [];
$passwordHash = trim((string) ($config['password_hash'] ?? ''));
$sessionName = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($config['session_name'] ?? 'npom_admin'));
if ($sessionName === '') {
    $sessionName = 'npom_admin';
}

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store');

function admin_is_configured(string $passwordHash): bool
{
    return $passwordHash !== '';
}

function admin_is_authenticated(): bool
{
    return (bool) ($_SESSION['npom_admin_authenticated'] ?? false);
}

function admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_format_datetime(?string $value): string
{
    if (!$value) {
        return '';
    }

    $time = strtotime($value . ' UTC');
    if ($time === false) {
        return $value;
    }

    return date('M j, Y g:i A', $time);
}

function admin_interest_label(string $value): string
{
    $labels = [
        '' => 'Not selected',
        'email-updates' => 'Email updates',
        'volunteer' => 'Volunteering',
        'professional' => 'Professional support',
        'donation' => 'Financial contribution',
        'community' => 'Community engagement',
        'other' => 'Other',
    ];

    return $labels[$value] ?? $value;
}

function admin_csv_download(PDO $pdo, string $type): void
{
    $today = gmdate('Y-m-d');

    if ($type === 'subscribers') {
        $filename = "npom-subscribers-{$today}.csv";
        $columns = ['id', 'email', 'source', 'mailgun_status', 'created_at', 'updated_at'];
        $stmt = $pdo->query(
            'SELECT id, email, source, mailgun_status, created_at, updated_at
             FROM subscribers
             ORDER BY created_at DESC, id DESC'
        );
    } elseif ($type === 'contacts') {
        $filename = "npom-contact-messages-{$today}.csv";
        $columns = ['id', 'name', 'email', 'interest', 'message', 'mailgun_status', 'created_at'];
        $stmt = $pdo->query(
            'SELECT id, name, email, interest, message, mailgun_status, created_at
             FROM contact_messages
             ORDER BY created_at DESC, id DESC'
        );
    } else {
        http_response_code(404);
        echo 'Unknown export.';
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fputcsv($out, $columns);
    foreach ($stmt as $row) {
        fputcsv($out, array_map(static fn ($column) => (string) ($row[$column] ?? ''), $columns));
    }
    fclose($out);
    exit;
}

$configured = admin_is_configured($passwordHash);
$error = '';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ./');
    exit;
}

if ($configured && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    if (password_verify($password, $passwordHash)) {
        session_regenerate_id(true);
        $_SESSION['npom_admin_authenticated'] = true;
        header('Location: ./');
        exit;
    }

    $error = 'That password did not work.';
}

$pdo = null;
$subscriberCount = 0;
$contactCount = 0;
$recentSubscribers = [];
$recentContacts = [];

if ($configured && admin_is_authenticated()) {
    $pdo = npom_db();

    $export = (string) ($_GET['export'] ?? '');
    if ($export !== '') {
        admin_csv_download($pdo, $export);
    }

    $subscriberCount = (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn();
    $contactCount = (int) $pdo->query('SELECT COUNT(*) FROM contact_messages')->fetchColumn();
    $recentSubscribers = $pdo->query(
        'SELECT email, source, mailgun_status, created_at
         FROM subscribers
         ORDER BY created_at DESC, id DESC
         LIMIT 25'
    )->fetchAll();
    $recentContacts = $pdo->query(
        'SELECT name, email, interest, message, mailgun_status, created_at
         FROM contact_messages
         ORDER BY created_at DESC, id DESC
         LIMIT 25'
    )->fetchAll();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex,nofollow" />
  <title>NPOM Form Admin</title>
  <style>
    :root {
      --ink: #171412;
      --muted: #6f6a66;
      --line: #e7e2dd;
      --paper: #faf9f6;
      --gold: #e8a43d;
      --brown: #793518;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--paper);
      color: var(--ink);
      font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      line-height: 1.5;
    }

    a {
      color: var(--brown);
      font-weight: 800;
      text-decoration: none;
    }

    a:hover {
      text-decoration: underline;
    }

    .wrap {
      width: min(1120px, calc(100% - 32px));
      margin: 0 auto;
      padding: 36px 0 56px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      align-items: flex-start;
      margin-bottom: 32px;
    }

    h1, h2 {
      margin: 0;
      line-height: 1.05;
    }

    h1 {
      font-size: clamp(2rem, 5vw, 3.5rem);
      letter-spacing: -0.03em;
    }

    h2 {
      font-size: 1.25rem;
      margin-bottom: 14px;
    }

    .muted {
      color: var(--muted);
      margin: 8px 0 0;
    }

    .panel {
      background: #fff;
      border: 1px solid var(--line);
      padding: 22px;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 28px;
    }

    .stat {
      background: #fff;
      border: 1px solid var(--line);
      padding: 22px;
    }

    .stat strong {
      display: block;
      font-size: 2.25rem;
      line-height: 1;
      color: var(--brown);
    }

    .stat span {
      color: var(--muted);
      font-weight: 700;
    }

    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin: 0 0 28px;
    }

    .button,
    button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 42px;
      border: 0;
      background: var(--brown);
      color: #fff;
      padding: 0 16px;
      font-weight: 900;
      cursor: pointer;
      text-decoration: none;
    }

    .button.secondary {
      background: var(--ink);
    }

    input {
      width: 100%;
      min-height: 46px;
      border: 1px solid var(--line);
      padding: 0 12px;
      font: inherit;
    }

    label {
      display: block;
      font-weight: 900;
      margin-bottom: 8px;
    }

    .login {
      max-width: 440px;
      margin-top: 20px;
    }

    .error {
      margin: 0 0 14px;
      color: #9f1239;
      font-weight: 800;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.92rem;
    }

    th, td {
      border-bottom: 1px solid var(--line);
      padding: 11px 8px;
      text-align: left;
      vertical-align: top;
    }

    th {
      color: var(--muted);
      font-size: 0.74rem;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .message {
      max-width: 420px;
      color: var(--muted);
    }

    .badge {
      display: inline-block;
      background: #f3eee8;
      color: var(--brown);
      padding: 2px 7px;
      font-size: 0.74rem;
      font-weight: 900;
      text-transform: uppercase;
    }

    .section {
      margin-top: 28px;
    }

    @media (max-width: 760px) {
      .topbar,
      .grid {
        grid-template-columns: 1fr;
        display: grid;
      }

      table,
      thead,
      tbody,
      th,
      td,
      tr {
        display: block;
      }

      thead {
        display: none;
      }

      tr {
        border-bottom: 1px solid var(--line);
        padding: 10px 0;
      }

      td {
        border: 0;
        padding: 4px 0;
      }
    }
  </style>
</head>
<body>
  <main class="wrap">
    <div class="topbar">
      <div>
        <h1>NPOM Form Admin</h1>
        <p class="muted">View recent submissions and export form data.</p>
      </div>
      <?php if ($configured && admin_is_authenticated()): ?>
        <a class="button secondary" href="?logout=1">Log out</a>
      <?php endif; ?>
    </div>

    <?php if (!$configured): ?>
      <section class="panel">
        <h2>Admin password required</h2>
        <p class="muted">Set an admin password hash in <code>api/config.php</code> before this screen can be used.</p>
        <p class="muted">Generate a hash with:</p>
        <pre>php -r 'echo password_hash("your-strong-password", PASSWORD_DEFAULT), PHP_EOL;'</pre>
      </section>
    <?php elseif (!admin_is_authenticated()): ?>
      <section class="panel login">
        <h2>Log in</h2>
        <?php if ($error !== ''): ?>
          <p class="error"><?= admin_h($error) ?></p>
        <?php endif; ?>
        <form method="post" action="./">
          <label for="password">Password</label>
          <input id="password" name="password" type="password" required autocomplete="current-password" />
          <div style="margin-top:14px;">
            <button type="submit">Enter admin</button>
          </div>
        </form>
      </section>
    <?php else: ?>
      <section class="grid" aria-label="Submission totals">
        <div class="stat">
          <strong><?= $subscriberCount ?></strong>
          <span>Subscribers</span>
        </div>
        <div class="stat">
          <strong><?= $contactCount ?></strong>
          <span>Contact messages</span>
        </div>
      </section>

      <nav class="actions" aria-label="Exports">
        <a class="button" href="?export=subscribers">Download subscriber CSV</a>
        <a class="button" href="?export=contacts">Download contact messages CSV</a>
      </nav>

      <section class="panel section">
        <h2>Latest Subscribers</h2>
        <table>
          <thead>
            <tr>
              <th>Email</th>
              <th>Source</th>
              <th>Mailgun</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$recentSubscribers): ?>
              <tr><td colspan="4">No subscribers yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentSubscribers as $row): ?>
              <tr>
                <td><?= admin_h((string) $row['email']) ?></td>
                <td><?= admin_h((string) $row['source']) ?></td>
                <td><span class="badge"><?= admin_h((string) $row['mailgun_status']) ?></span></td>
                <td><?= admin_h(admin_format_datetime((string) $row['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>

      <section class="panel section">
        <h2>Latest Contact Messages</h2>
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Interest</th>
              <th>Message</th>
              <th>Mailgun</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$recentContacts): ?>
              <tr><td colspan="6">No contact messages yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentContacts as $row): ?>
              <tr>
                <td><?= admin_h((string) $row['name']) ?></td>
                <td><?= admin_h((string) $row['email']) ?></td>
                <td><?= admin_h(admin_interest_label((string) $row['interest'])) ?></td>
                <td class="message"><?= admin_h((string) $row['message']) ?></td>
                <td><span class="badge"><?= admin_h((string) $row['mailgun_status']) ?></span></td>
                <td><?= admin_h(admin_format_datetime((string) $row['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>

