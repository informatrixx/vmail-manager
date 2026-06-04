<?php

declare(strict_types=1);

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    $configPath = __DIR__ . '/../config.example.php';
}

$config = require $configPath;
$dbConfig = $config['db'] ?? [];

if (($dbConfig['driver'] ?? 'sqlite') !== 'sqlite') {
    fwrite(STDERR, "The configured database driver is not sqlite.\n");
    exit(1);
}

$path = (string)($dbConfig['path'] ?? '');
if ($path === '') {
    fwrite(STDERR, "SQLite path is missing.\n");
    exit(1);
}

$dir = dirname($path);
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
}
chmod($dir, 0775);

function testMailboxHash(string $password): string
{
    $hash = crypt($password, '$6$vmailtest$');
    if (!is_string($hash) || !str_starts_with($hash, '$6$')) {
        throw new RuntimeException('Unable to create SHA512-CRYPT hash.');
    }
    return '{SHA512-CRYPT}' . $hash;
}

$pdo = new PDO('sqlite:' . $path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec('DROP TABLE IF EXISTS tlspolicies');
$pdo->exec('DROP TABLE IF EXISTS aliases');
$pdo->exec('DROP TABLE IF EXISTS accounts');
$pdo->exec('DROP TABLE IF EXISTS domains');

$pdo->exec(
    'CREATE TABLE domains (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL UNIQUE
    )'
);

$pdo->exec(
    'CREATE TABLE accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        domain TEXT NOT NULL,
        password TEXT NOT NULL,
        commonname TEXT NOT NULL,
        quota INTEGER DEFAULT 0,
        enabled INTEGER DEFAULT 1,
        sendonly INTEGER DEFAULT 0,
        UNIQUE (username, domain),
        FOREIGN KEY (domain) REFERENCES domains(domain)
    )'
);

$pdo->exec(
    'CREATE TABLE aliases (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_username TEXT DEFAULT NULL,
        source_domain TEXT NOT NULL,
        destination_username TEXT NOT NULL,
        destination_domain TEXT NOT NULL,
        enabled INTEGER NOT NULL DEFAULT 1,
        UNIQUE (source_username, source_domain, destination_username, destination_domain),
        FOREIGN KEY (source_domain) REFERENCES domains(domain)
    )'
);

$pdo->exec(
    'CREATE TABLE tlspolicies (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        domain TEXT NOT NULL UNIQUE,
        policy TEXT NOT NULL,
        params TEXT DEFAULT NULL
    )'
);

$domains = ['autismus-asperger.test', 'silberschneider.test', 'kobaude.test'];
$stmt = $pdo->prepare('INSERT INTO domains (domain) VALUES (?)');
foreach ($domains as $domain) {
    $stmt->execute([$domain]);
}

$hash = testMailboxHash('Mailbox123');
$accounts = [
    ['postmaster', 'autismus-asperger.test', 'Postmaster', 0, 1, 0],
    ['office', 'autismus-asperger.test', 'Office', 2048, 1, 0],
    ['newsletter', 'autismus-asperger.test', 'Newsletter Versand', 512, 1, 1],
    ['ute', 'silberschneider.test', 'Ute Silberschneider', 1024, 1, 0],
    ['chris', 'kobaude.test', 'Christian Schein', 1024, 0, 0],
];
$stmt = $pdo->prepare(
    'INSERT INTO accounts (username, domain, password, commonname, quota, enabled, sendonly)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
foreach ($accounts as $account) {
    $stmt->execute([$account[0], $account[1], $hash, $account[2], $account[3], $account[4], $account[5]]);
}

$aliases = [
    ['info', 'autismus-asperger.test', 'office', 'autismus-asperger.test', 1],
    [null, 'autismus-asperger.test', 'postmaster', 'autismus-asperger.test', 1],
    ['kontakt', 'silberschneider.test', 'ute', 'silberschneider.test', 1],
    ['extern', 'kobaude.test', 'helpdesk', 'example.net', 1],
];
$stmt = $pdo->prepare(
    'INSERT INTO aliases (source_username, source_domain, destination_username, destination_domain, enabled)
     VALUES (?, ?, ?, ?, ?)'
);
foreach ($aliases as $alias) {
    $stmt->execute($alias);
}

echo "SQLite test database initialized: {$path}\n";
echo "Admin login: admin / Admin123\n";
echo "Mailbox test password: Mailbox123\n";

chmod($path, 0664);
if (function_exists('posix_getpwnam') && posix_getpwnam('www-data') !== false) {
    @chown($dir, 'www-data');
    @chgrp($dir, 'www-data');
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
}
