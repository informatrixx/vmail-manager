# VMail Manager

Dependency-free PHP frontend for administering virtual mailboxes and aliases used by Postfix, Dovecot and SOGo.

The application is intentionally small: PHP, HTML, CSS, JavaScript and PDO only. No Composer, npm or external runtime dependencies are required.

## Features

- Admin login with password hashes stored in a PHP configuration file
- Per-admin domain assignments
- Mailbox creation and editing
- Mailbox activation/deactivation and send-only mode
- Quota management in MB
- Mailbox password changes using SHA512-CRYPT for Dovecot/SOGo
- Permanent database deletion, optionally including the Maildir
- Alias management for normal aliases, catch-all aliases and external destinations
- SQLite mode for offline development
- MariaDB/MySQL mode for production
- CSRF protection, prepared statements and server-side domain authorization

## Requirements

- PHP 8.1 or newer
- PHP extensions: `PDO`, `pdo_mysql` for MariaDB/MySQL, `pdo_sqlite` for SQLite, `session`, `mbstring`
- Nginx with PHP-FPM for production
- A MariaDB/MySQL database containing the existing `vmail` schema for production

## Local development with SQLite

Copy the example configuration and initialize the test database:

```bash
cp config.example.php config.php
php scripts/init_sqlite.php
```

The initialization script creates a separate SQLite database at `data/vmail-test.sqlite` with dummy domains, accounts and aliases. It does not import production password hashes.

For a quick local smoke test, the PHP development server can be used:

```bash
php -S 127.0.0.1:8080
```

The normal deployment target is Nginx with PHP-FPM; the built-in server is optional.

Test admin accounts:

| User | Password | Domains |
| --- | --- | --- |
| `admin` | `Admin123` | all SQLite test domains |
| `domainadmin` | `Domain123` | `autismus-asperger.test` |

Test mailbox password: `Mailbox123`.

These credentials are for the generated SQLite test database only and must not be used in production.

## Configuration

Copy `config.example.php` to `config.php`. The real `config.php` is ignored by Git and must never be committed.

### SQLite

```php
'db' => [
    'driver' => 'sqlite',
    'path' => __DIR__ . '/data/vmail-test.sqlite',
],
```

### MariaDB/MySQL

```php
'db' => [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => 3307,
    'name' => 'vmail',
    'user' => 'vmail_manager',
    'password' => 'database-password',
],
```

Use a database user with only the required privileges on the `vmail` database. The application reads and writes `domains`, `accounts` and `aliases`.

### Admins and domain assignments

Each admin entry contains a PHP password hash and a list of allowed domains:

```php
'admins' => [
    'admin' => [
        'password_hash' => '$2y$12$...',
        'domains' => ['example.org', 'example.net'],
    ],
],
```

Generate a hash with:

```bash
php -r 'echo password_hash("NewAdminPassword123", PASSWORD_DEFAULT), PHP_EOL;'
```

### Maildir deletion

Maildir deletion is disabled by default:

```php
'maildir_path_template' => '',
```

If the actual Maildir layout matches the template, it can be enabled:

```php
'maildir_path_template' => '/var/vmail/{domain}/{username}',
```

`{domain}` and `{username}` are replaced at runtime. For `office@example.org`, the resulting path is `/var/vmail/example.org/office`.

Only enable this when the PHP-FPM user has the required permissions and the path has been tested. The application restricts deletion to the configured base path.

## Nginx and PHP-FPM

For an application mounted below `/vmail-manager/` in an existing Nginx `server` block:

```nginx
location ^~ /vmail-manager/ {
    alias /var/www/vmail-manager/;
    index index.php;
    try_files $uri $uri/ /vmail-manager/index.php?$query_string;
}

location ~ ^/vmail-manager/(.+\.php)$ {
    alias /var/www/vmail-manager/$1;
    include snippets/fastcgi-php.conf;
    fastcgi_param SCRIPT_FILENAME /var/www/vmail-manager/$1;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location ^~ /vmail-manager/data/ {
    deny all;
}

location ~ ^/vmail-manager/.*\.(sql|sqlite|db|conf|part|md)$ {
    deny all;
}
```

Adjust the PHP-FPM socket to the installed version. Validate and reload Nginx:

```bash
nginx -t
systemctl reload nginx
```

The PHP-FPM user needs read access to the application and write access to `data/` only when SQLite is used. For production MariaDB/MySQL mode, the application directory can remain read-only after deployment.

## Production deployment

Do not deploy these files from the development host:

- `config.php`
- `data/`
- SQL dumps and server configuration excerpts

Example deployment of tracked files:

```bash
rsync -avz --delete \
  --exclude 'config.php' \
  --exclude 'data/' \
  --exclude '*.sql' \
  --exclude '*.conf.part' \
  --exclude 'postfix-sql-cf.txt' \
  ./ user@example.org:/var/www/vmail-manager/
```

Create and configure `config.php` separately on the target host. Verify ownership, PHP-FPM access, Nginx configuration and the database connection before enabling Maildir deletion.

## Security notes

- Never commit `config.php`, database files, SQL dumps or real password hashes.
- Use HTTPS in production.
- Use a dedicated database account with minimal privileges.
- Keep the application outside public access except through the intended Nginx location.
- Keep `maildir_path_template` empty until deletion has been verified with a non-production test account.
- Test admin passwords in `config.example.php` are not production credentials.

## License

VMail Manager is licensed under the GNU Affero General Public License v3.0 only. See [LICENSE](LICENSE).
