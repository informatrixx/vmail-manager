<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;
    private string $driver;

    public function __construct(array $config)
    {
        $this->driver = (string)($config['driver'] ?? 'sqlite');

        if ($this->driver === 'sqlite') {
            $path = (string)($config['path'] ?? '');
            if ($path === '') {
                throw new RuntimeException('SQLite path is missing.');
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }

            $this->pdo = new PDO('sqlite:' . $path);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } elseif ($this->driver === 'mysql') {
            $host = (string)($config['host'] ?? '127.0.0.1');
            $port = (int)($config['port'] ?? 3306);
            $name = (string)($config['name'] ?? 'vmail');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
            $this->pdo = new PDO($dsn, (string)($config['user'] ?? ''), (string)($config['password'] ?? ''));
        } else {
            throw new RuntimeException('Unsupported database driver.');
        }

        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function domainsForAdmin(array $allowedDomains): array
    {
        if ($allowedDomains === []) {
            return [];
        }

        $rows = $this->queryList(
            'SELECT domain FROM domains WHERE domain IN (' . $this->placeholders($allowedDomains) . ') ORDER BY domain',
            $allowedDomains
        );

        return array_map(static fn(array $row): string => (string)$row['domain'], $rows);
    }

    public function listAccounts(string $domain, string $search = ''): array
    {
        $params = [$domain];
        $where = 'domain = ?';

        if ($search !== '') {
            $where .= ' AND (username LIKE ? OR commonname LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
        }

        return $this->queryList(
            "SELECT id, username, domain, commonname, quota, enabled, sendonly
             FROM accounts
             WHERE {$where}
             ORDER BY enabled DESC, username ASC",
            $params
        );
    }

    public function getAccount(int $id, string $domain): ?array
    {
        return $this->queryOne(
            'SELECT id, username, domain, commonname, quota, enabled, sendonly FROM accounts WHERE id = ? AND domain = ?',
            [$id, $domain]
        );
    }

    public function createAccount(array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO accounts (username, domain, password, commonname, quota, enabled, sendonly)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['username'],
            $data['domain'],
            $data['password'],
            $data['commonname'],
            $data['quota'],
            $data['enabled'],
            $data['sendonly'],
        ]);
    }

    public function updateAccount(int $id, string $domain, array $data): void
    {
        $fields = [
            'commonname = ?',
            'quota = ?',
            'enabled = ?',
            'sendonly = ?',
        ];
        $params = [
            $data['commonname'],
            $data['quota'],
            $data['enabled'],
            $data['sendonly'],
        ];

        if (isset($data['password'])) {
            $fields[] = 'password = ?';
            $params[] = $data['password'];
        }

        $params[] = $id;
        $params[] = $domain;

        $stmt = $this->pdo->prepare('UPDATE accounts SET ' . implode(', ', $fields) . ' WHERE id = ? AND domain = ?');
        $stmt->execute($params);
    }

    public function setAccountEnabled(int $id, string $domain, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE accounts SET enabled = ? WHERE id = ? AND domain = ?');
        $stmt->execute([$enabled ? 1 : 0, $id, $domain]);
    }

    public function deleteAccount(int $id, string $domain): ?array
    {
        $account = $this->getAccount($id, $domain);
        if ($account === null) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->deleteAliasesForAddress((string)$account['username'], (string)$account['domain']);
            $stmt = $this->pdo->prepare('DELETE FROM accounts WHERE id = ? AND domain = ?');
            $stmt->execute([$id, $domain]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $account;
    }

    public function aliasesForAccount(string $username, string $domain): array
    {
        return $this->queryList(
            'SELECT id, source_username, source_domain, destination_username, destination_domain, enabled
             FROM aliases
             WHERE (source_username = ? AND source_domain = ?)
                OR (destination_username = ? AND destination_domain = ?)
             ORDER BY source_domain, source_username, destination_domain, destination_username',
            [$username, $domain, $username, $domain]
        );
    }

    public function listAliases(string $domain): array
    {
        return $this->queryList(
            'SELECT id, source_username, source_domain, destination_username, destination_domain, enabled
             FROM aliases
             WHERE source_domain = ?
             ORDER BY source_username IS NOT NULL DESC, source_username ASC, destination_domain ASC, destination_username ASC',
            [$domain]
        );
    }

    public function getAlias(int $id, string $domain): ?array
    {
        return $this->queryOne(
            'SELECT id, source_username, source_domain, destination_username, destination_domain, enabled FROM aliases WHERE id = ? AND source_domain = ?',
            [$id, $domain]
        );
    }

    public function saveAlias(?int $id, array $data): void
    {
        if ($id === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO aliases (source_username, source_domain, destination_username, destination_domain, enabled)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $data['source_username'],
                $data['source_domain'],
                $data['destination_username'],
                $data['destination_domain'],
                $data['enabled'],
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE aliases
             SET source_username = ?, destination_username = ?, destination_domain = ?, enabled = ?
             WHERE id = ? AND source_domain = ?'
        );
        $stmt->execute([
            $data['source_username'],
            $data['destination_username'],
            $data['destination_domain'],
            $data['enabled'],
            $id,
            $data['source_domain'],
        ]);
    }

    public function deleteAlias(int $id, string $domain): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM aliases WHERE id = ? AND source_domain = ?');
        $stmt->execute([$id, $domain]);
    }

    private function deleteAliasesForAddress(string $username, string $domain): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM aliases
             WHERE (source_username = ? AND source_domain = ?)
                OR (destination_username = ? AND destination_domain = ?)'
        );
        $stmt->execute([$username, $domain, $username, $domain]);
    }

    private function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function queryList(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
