# NPOM Email Forms Live Setup

This site keeps `index.html` as a static page and uses small PHP endpoints for form submissions:

- `api/subscribe.php` stores footer email signups.
- `api/contact.php` stores contact form messages and can notify NPOM through Mailgun.
- `api/config.php` is intentionally ignored by git because it contains secrets.
- `storage/npom.sqlite` is intentionally ignored by git because it contains submitted form data.

## 1. Confirm PHP extensions on Cloudways

Create a temporary file such as `php-check.php` on the live application:

```php
<?php
echo 'PDO: ' . (class_exists('PDO') ? 'yes' : 'no') . PHP_EOL;
echo 'SQLite: ' . (extension_loaded('pdo_sqlite') ? 'yes' : 'no') . PHP_EOL;
echo 'cURL: ' . (extension_loaded('curl') ? 'yes' : 'no') . PHP_EOL;
```

Visit it in the browser or run it over SSH:

```bash
php php-check.php
```

Delete `php-check.php` after checking.

If `SQLite: yes` and `cURL: yes`, use the SQLite setup below.

## 2. Create the live config

Copy the example config:

```bash
cp api/config.example.php api/config.php
```

Edit `api/config.php`.

Use a long random string for `hash_salt`.

For Mailgun, fill these fields:

```php
'mailgun' => [
    'enabled' => true,
    'endpoint' => 'https://api.mailgun.net/v3',
    'api_key' => 'key-your-real-mailgun-api-key',
    'domain' => 'mg.your-domain.ca',
    'from' => 'North Preston Outreach Ministry <no-reply@mg.your-domain.ca>',
    'notify_to' => 'brandon@antimatterlabs.ca',
    'mailing_list' => 'updates@mg.your-domain.ca',
    'subscribe_to_list' => true,
    'notify_on_subscribe' => false,
],
```

If the Mailgun domain is in the EU region, use:

```php
'endpoint' => 'https://api.eu.mailgun.net/v3',
```

## 3. SQLite setup

Best live layout:

```text
public_html/
  index.html
  api/
private/
  npom.sqlite
```

In `api/config.php`, point SQLite to the private file:

```php
'database' => [
    'driver' => 'sqlite',
    'sqlite_path' => '/home/master/applications/APP_ID/private/npom.sqlite',
],
```

The exact path depends on the Cloudways application path. The important rule is: keep the SQLite file outside the public web root when possible.

If the file does not exist, the PHP app will create it on first successful form submission. The folder must be writable by PHP.

## 4. MySQL fallback

If Cloudways does not have `pdo_sqlite`, switch the config to MySQL/MariaDB:

```php
'database' => [
    'driver' => 'mysql',
    'mysql' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'your_cloudways_db_name',
        'username' => 'your_cloudways_db_user',
        'password' => 'your_cloudways_db_password',
        'charset' => 'utf8mb4',
    ],
],
```

The PHP app creates its own `subscribers` and `contact_messages` tables automatically.

## 5. Admin/export screen

The admin screen lives at:

```text
/admin/
```

It is disabled until `api/config.php` has an admin password hash.

Generate a password hash:

```bash
php -r 'echo password_hash("replace-with-a-strong-password", PASSWORD_DEFAULT), PHP_EOL;'
```

Add the generated hash to `api/config.php`:

```php
'admin' => [
    'admin_pass' => 'npom_admin',
    'password_hash' => '$2y$10$example-generated-hash-goes-here',
    'session_name' => 'npom_admin',
],
```

`admin_pass` is the admin login/username value. It exists so password managers can save a normal username/password pair. The actual secret is the password that generated `password_hash`.

After login, the admin screen shows:

- subscriber count
- contact message count
- latest 25 subscribers
- latest 25 contact messages
- subscriber CSV export
- contact message CSV export

Use a strong password and do not commit `api/config.php`.

## 6. Test live

Submit the footer email form and the main contact form.

Then check the admin screen or database.

For SQLite:

```bash
sqlite3 /path/to/private/npom.sqlite 'select id,email,created_at from subscribers order by id desc limit 5;'
sqlite3 /path/to/private/npom.sqlite 'select id,name,email,created_at from contact_messages order by id desc limit 5;'
```

If the `sqlite3` command is not installed, use the protected `/admin/` export screen, a local copy of the database, or a PHP-based database viewer over SSH only. Do not add an unprotected public export endpoint.

## 7. Mailgun notes

- The Mailgun API key must only live in `api/config.php`.
- Do not put the API key in `index.html` or browser JavaScript.
- If `subscribe_to_list` is true, footer signups are sent to the configured Mailgun mailing list.
- Contact messages are always stored locally first. Mailgun is used for notification email when configured.
- If Mailgun is temporarily down, the local database still keeps the submission.

## 8. Files that should not be committed or uploaded publicly

- `api/config.php`
- `storage/*.sqlite`
- any database export files
