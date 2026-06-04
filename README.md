# VMail Manager

Dependency-free PHP frontend for managing virtual mail users and aliases for a Postfix, Dovecot and SOGo setup.

## Local SQLite test setup

```bash
php scripts/init_sqlite.php
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080`.

Default test logins from `config.example.php`:

- `admin` / `Admin123`
- `domainadmin` / `Domain123`

Mailbox test password created by the SQLite init script:

- `Mailbox123`

## Configuration

Copy `config.example.php` to `config.php` and adjust it.

Use SQLite for offline development:

```php
'db' => [
    'driver' => 'sqlite',
    'path' => __DIR__ . '/data/vmail-test.sqlite',
],
```

Use MariaDB/MySQL for production:

```php
'db' => [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => 3307,
    'name' => 'vmail',
    'user' => 'vmail_manager',
    'password' => '...',
],
```

Admin passwords must be created with `password_hash()`:

```bash
php -r 'echo password_hash("NewAdminPassword123", PASSWORD_DEFAULT), PHP_EOL;'
```

Set `maildir_path_template` only if the webserver user may delete mailboxes and the path pattern is known, for example:

```php
'maildir_path_template' => '/var/vmail/{domain}/{username}',
```
