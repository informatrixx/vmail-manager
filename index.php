<?php

declare(strict_types=1);

require __DIR__ . '/lib/Database.php';

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    $configPath = __DIR__ . '/config.example.php';
}

$config = require $configPath;

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

$messages = [];
$errors = [];
$openModal = '';
$stickyAccount = null;
$stickyAlias = null;

try {
    $db = new Database($config['db'] ?? []);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>VMail Manager</title><p>Die Datenbankverbindung konnte nicht hergestellt werden.</p>';
    exit;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isLoggedIn(): bool
{
    return isset($_SESSION['admin'], $_SESSION['domains']) && is_array($_SESSION['domains']);
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function requireCsrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        throw new RuntimeException('Ungültige Anfrage. Bitte erneut versuchen.');
    }
}

function normalizeLocalPart(string $value): string
{
    $value = trim(mb_strtolower($value));
    if ($value === '' || strlen($value) > 64 || !preg_match('/^[a-z0-9][a-z0-9._+-]*$/', $value)) {
        throw new InvalidArgumentException('Der lokale Teil der Adresse ist ungültig.');
    }
    return $value;
}

function normalizeDomain(string $value): string
{
    $value = trim(mb_strtolower($value));
    if ($value === '' || strlen($value) > 255 || !preg_match('/^[a-z0-9.-]+\.[a-z0-9.-]+$/', $value)) {
        throw new InvalidArgumentException('Die Domain ist ungültig.');
    }
    return $value;
}

function splitEmail(string $value): array
{
    $value = trim($value);
    if (!str_contains($value, '@')) {
        throw new InvalidArgumentException('Die Zieladresse muss eine vollständige E-Mail-Adresse sein.');
    }
    [$local, $domain] = explode('@', $value, 2);
    return [normalizeLocalPart($local), normalizeDomain($domain)];
}

function currentDomain(array $domains): string
{
    $selected = (string)($_GET['domain'] ?? $_POST['domain'] ?? $_SESSION['domain'] ?? '');
    if ($selected !== '' && in_array($selected, $domains, true)) {
        $_SESSION['domain'] = $selected;
        return $selected;
    }

    $fallback = $domains[0] ?? '';
    $_SESSION['domain'] = $fallback;
    return $fallback;
}

function validateAllowedDomain(string $domain): void
{
    if (!in_array($domain, $_SESSION['domains'] ?? [], true)) {
        throw new RuntimeException('Für diese Domain besteht keine Berechtigung.');
    }
}

function mailboxPasswordHash(string $password, array $policy): string
{
    $min = (int)($policy['min_length'] ?? 8);
    if (mb_strlen($password) < $min) {
        throw new InvalidArgumentException('Das Passwort ist zu kurz.');
    }
    if (($policy['require_lowercase'] ?? true) && !preg_match('/[a-z]/', $password)) {
        throw new InvalidArgumentException('Das Passwort braucht mindestens einen Kleinbuchstaben.');
    }
    if (($policy['require_uppercase'] ?? true) && !preg_match('/[A-Z]/', $password)) {
        throw new InvalidArgumentException('Das Passwort braucht mindestens einen Großbuchstaben.');
    }
    if (($policy['require_number'] ?? true) && !preg_match('/[0-9]/', $password)) {
        throw new InvalidArgumentException('Das Passwort braucht mindestens eine Zahl.');
    }

    $saltAlphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $salt = '';
    for ($i = 0; $i < 16; $i++) {
        $salt .= $saltAlphabet[random_int(0, strlen($saltAlphabet) - 1)];
    }

    $hash = crypt($password, '$6$' . $salt . '$');
    if (!is_string($hash) || $hash === '' || !str_starts_with($hash, '$6$')) {
        throw new RuntimeException('Passwort konnte nicht gehasht werden.');
    }

    return '{SHA512-CRYPT}' . $hash;
}

function removeMaildir(array $account, string $template): void
{
    if ($template === '') {
        return;
    }

    $domain = (string)$account['domain'];
    $username = (string)$account['username'];
    $path = str_replace(['{domain}', '{username}'], [$domain, $username], $template);
    $base = strstr($template, '{', true);
    if ($base === false || $base === '') {
        throw new RuntimeException('Maildir-Template muss mit einem festen Basispfad beginnen.');
    }

    $realBase = realpath(rtrim($base, '/'));
    $realPath = realpath($path);
    if ($realBase === false || $realPath === false || !str_starts_with($realPath, $realBase . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Maildir-Pfad ist nicht sicher löschbar.');
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($realPath);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'login') {
            requireCsrf();
            $user = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $admin = ($config['admins'] ?? [])[$user] ?? null;

            if (!is_array($admin) || !password_verify($password, (string)($admin['password_hash'] ?? ''))) {
                throw new RuntimeException('Login fehlgeschlagen.');
            }

            session_regenerate_id(true);
            $_SESSION['admin'] = $user;
            $_SESSION['domains'] = array_values(array_map('strval', $admin['domains'] ?? []));
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($action === 'logout') {
            requireCsrf();
            $_SESSION = [];
            session_destroy();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if (!isLoggedIn()) {
            throw new RuntimeException('Bitte zuerst einloggen.');
        }

        requireCsrf();
        $domain = normalizeDomain((string)($_POST['domain'] ?? ''));
        validateAllowedDomain($domain);
        $_SESSION['domain'] = $domain;

        if ($action === 'account_save') {
            $id = (int)($_POST['id'] ?? 0);
            $commonname = trim((string)($_POST['commonname'] ?? ''));
            $quota = max(0, (int)($_POST['quota'] ?? 0));
            $data = [
                'domain' => $domain,
                'commonname' => $commonname,
                'quota' => $quota,
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'sendonly' => isset($_POST['sendonly']) ? 1 : 0,
            ];

            $password = (string)($_POST['password'] ?? '');
            if ($id === 0 || $password !== '') {
                $data['password'] = mailboxPasswordHash($password, $config['password_policy'] ?? []);
            }

            if ($id === 0) {
                $data['username'] = normalizeLocalPart((string)($_POST['username'] ?? ''));
                $db->createAccount($data);
                $messages[] = 'Benutzer wurde angelegt.';
            } else {
                if ($db->getAccount($id, $domain) === null) {
                    throw new RuntimeException('Benutzer wurde nicht gefunden.');
                }
                $db->updateAccount($id, $domain, $data);
                $messages[] = 'Benutzer wurde gespeichert.';
            }
        } elseif ($action === 'account_toggle') {
            $db->setAccountEnabled((int)($_POST['id'] ?? 0), $domain, ((int)($_POST['enabled'] ?? 0)) === 1);
            $messages[] = 'Status wurde geändert.';
        } elseif ($action === 'account_delete') {
            $account = $db->deleteAccount((int)($_POST['id'] ?? 0), $domain);
            if ($account === null) {
                throw new RuntimeException('Benutzer wurde nicht gefunden.');
            }
            if ((string)($_POST['delete_maildir'] ?? '') === '1') {
                removeMaildir($account, (string)($config['maildir_path_template'] ?? ''));
            }
            $messages[] = 'Benutzer wurde endgültig gelöscht.';
        } elseif ($action === 'alias_save') {
            $id = (int)($_POST['id'] ?? 0);
            $sourceType = (string)($_POST['source_type'] ?? 'address');
            $sourceUsername = $sourceType === 'catchall' ? null : normalizeLocalPart((string)($_POST['source_username'] ?? ''));
            [$destinationUsername, $destinationDomain] = splitEmail((string)($_POST['destination'] ?? ''));

            $db->saveAlias($id > 0 ? $id : null, [
                'source_username' => $sourceUsername,
                'source_domain' => $domain,
                'destination_username' => $destinationUsername,
                'destination_domain' => $destinationDomain,
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
            ]);
            $messages[] = 'Alias wurde gespeichert.';
        } elseif ($action === 'alias_delete') {
            $db->deleteAlias((int)($_POST['id'] ?? 0), $domain);
            $messages[] = 'Alias wurde gelöscht.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $failedAction = (string)($_POST['action'] ?? '');
        if ($failedAction === 'account_save') {
            $openModal = 'account';
            $stickyAccount = [
                'id' => (int)($_POST['id'] ?? 0),
                'username' => (string)($_POST['username'] ?? ''),
                'domain' => (string)($_POST['domain'] ?? ''),
                'commonname' => (string)($_POST['commonname'] ?? ''),
                'quota' => (int)($_POST['quota'] ?? 0),
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'sendonly' => isset($_POST['sendonly']) ? 1 : 0,
            ];
        } elseif ($failedAction === 'alias_save') {
            $openModal = 'alias';
            $stickyAlias = [
                'id' => (int)($_POST['id'] ?? 0),
                'source_username' => (string)($_POST['source_username'] ?? ''),
                'source_domain' => (string)($_POST['domain'] ?? ''),
                'destination' => (string)($_POST['destination'] ?? ''),
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'source_type' => (string)($_POST['source_type'] ?? 'address'),
            ];
        }
    }
}

$domains = [];
$domain = '';
$accounts = [];
$aliases = [];
$editAccount = null;
$editAlias = null;
$deletePreview = [];
$search = trim((string)($_GET['q'] ?? ''));

if (isLoggedIn()) {
    $domains = $db->domainsForAdmin($_SESSION['domains']);
    $domain = currentDomain($domains);
    if ($domain !== '') {
        validateAllowedDomain($domain);
        if (isset($_GET['edit_account'])) {
            $editAccount = $db->getAccount((int)$_GET['edit_account'], $domain);
            if ($editAccount !== null) {
                $openModal = 'account';
            }
        }
        if (isset($_GET['delete_account'])) {
            $editAccount = $db->getAccount((int)$_GET['delete_account'], $domain);
            if ($editAccount !== null) {
                $deletePreview = $db->aliasesForAccount((string)$editAccount['username'], (string)$editAccount['domain']);
            }
        }
        if (isset($_GET['edit_alias'])) {
            $editAlias = $db->getAlias((int)$_GET['edit_alias'], $domain);
            if ($editAlias !== null) {
                $openModal = 'alias';
            }
        }
        $accounts = $db->listAccounts($domain, $search);
        $aliases = $db->listAliases($domain);
    }
}

$accountForm = $stickyAccount ?? $editAccount;
$aliasForm = $stickyAlias ?? $editAlias;
$accountIsEdit = $accountForm !== null && (int)($accountForm['id'] ?? 0) > 0;
$aliasIsEdit = $aliasForm !== null && (int)($aliasForm['id'] ?? 0) > 0;
$aliasDestination = '';
if ($aliasForm !== null) {
    $aliasDestination = isset($aliasForm['destination'])
        ? (string)$aliasForm['destination']
        : (string)$aliasForm['destination_username'] . '@' . (string)$aliasForm['destination_domain'];
}
$aliasSourceType = 'address';
if ($aliasForm !== null) {
    $aliasSourceType = (string)($aliasForm['source_type'] ?? (($aliasIsEdit && ($aliasForm['source_username'] ?? null) === null) ? 'catchall' : 'address'));
}

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($config['app_name'] ?? 'VMail Manager') ?></title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="assets/app.js" defer></script>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <div>
            <p class="eyebrow">Postfix · Dovecot · SOGo</p>
            <h1><?= h($config['app_name'] ?? 'VMail Manager') ?></h1>
        </div>
        <?php if (isLoggedIn()): ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="ghost" type="submit">Logout</button>
            </form>
        <?php endif; ?>
    </header>

    <?php foreach ($messages as $message): ?>
        <div class="notice success"><?= h($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if (!isLoggedIn()): ?>
        <section class="panel login-panel">
            <h2>Admin Login</h2>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Benutzername
                    <input name="username" autocomplete="username" required>
                </label>
                <label>Passwort
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit">Einloggen</button>
            </form>
        </section>
    <?php elseif ($domain === ''): ?>
        <section class="panel">
            <h2>Keine Domain verfügbar</h2>
            <p>Für diesen Admin ist keine vorhandene Domain in der Datenbank freigegeben.</p>
        </section>
    <?php else: ?>
        <nav class="domain-nav">
            <?php foreach ($domains as $item): ?>
                <a class="<?= $item === $domain ? 'active' : '' ?>" href="?domain=<?= h($item) ?>"><?= h($item) ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="toolbar">
            <form method="get" class="search">
                <input type="hidden" name="domain" value="<?= h($domain) ?>">
                <input name="q" value="<?= h($search) ?>" placeholder="Benutzer suchen">
                <button type="submit">Suchen</button>
            </form>
            <button type="button" data-open-modal="account-modal">Neuer Benutzer</button>
            <button class="secondary" type="button" data-open-modal="alias-modal">Neuer Alias</button>
        </section>

        <?php if (isset($_GET['delete_account']) && $editAccount !== null): ?>
            <section class="panel danger-panel">
                <h2>Benutzer endgültig löschen</h2>
                <p><strong><?= h($editAccount['username'] . '@' . $editAccount['domain']) ?></strong> wird aus der Datenbank gelöscht.</p>
                <?php if ($deletePreview !== []): ?>
                    <p>Folgende Aliase werden ebenfalls aus der Datenbank entfernt:</p>
                    <ul>
                        <?php foreach ($deletePreview as $alias): ?>
                            <li><?= h(($alias['source_username'] ?? '*') . '@' . $alias['source_domain'] . ' → ' . $alias['destination_username'] . '@' . $alias['destination_domain']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form method="post" class="inline-actions">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="account_delete">
                    <input type="hidden" name="domain" value="<?= h($domain) ?>">
                    <input type="hidden" name="id" value="<?= h($editAccount['id']) ?>">
                    <?php if ((string)($config['maildir_path_template'] ?? '') !== ''): ?>
                        <label class="check"><input type="checkbox" name="delete_maildir" value="1"> Maildir ebenfalls löschen</label>
                    <?php endif; ?>
                    <button class="danger" type="submit">Endgültig löschen</button>
                    <a class="button secondary" href="?domain=<?= h($domain) ?>">Abbrechen</a>
                </form>
            </section>
        <?php endif; ?>

        <section class="stack">
            <div class="panel">
                <h2>Benutzer</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr><th>E-Mail</th><th>Name</th><th>Quota</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accounts as $account): ?>
                            <tr>
                                <td><?= h($account['username'] . '@' . $account['domain']) ?></td>
                                <td><?= h($account['commonname']) ?></td>
                                <td><?= h($account['quota']) ?> MB</td>
                                <td>
                                    <span class="badge <?= (int)$account['enabled'] === 1 ? 'on' : 'off' ?>"><?= (int)$account['enabled'] === 1 ? 'aktiv' : 'inaktiv' ?></span>
                                    <?php if ((int)$account['sendonly'] === 1): ?><span class="badge warn">sendonly</span><?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="?domain=<?= h($domain) ?>&edit_account=<?= h($account['id']) ?>">Bearbeiten</a>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="account_toggle">
                                        <input type="hidden" name="domain" value="<?= h($domain) ?>">
                                        <input type="hidden" name="id" value="<?= h($account['id']) ?>">
                                        <input type="hidden" name="enabled" value="<?= (int)$account['enabled'] === 1 ? '0' : '1' ?>">
                                        <button type="submit"><?= (int)$account['enabled'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                    </form>
                                    <a class="danger-link" href="?domain=<?= h($domain) ?>&delete_account=<?= h($account['id']) ?>">Löschen</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="stack">
            <div class="panel">
                <h2>Aliase</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr><th>Quelle</th><th>Ziel</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($aliases as $alias): ?>
                            <tr>
                                <td><?= h(($alias['source_username'] ?? '*') . '@' . $alias['source_domain']) ?></td>
                                <td><?= h($alias['destination_username'] . '@' . $alias['destination_domain']) ?></td>
                                <td><span class="badge <?= (int)$alias['enabled'] === 1 ? 'on' : 'off' ?>"><?= (int)$alias['enabled'] === 1 ? 'aktiv' : 'inaktiv' ?></span></td>
                                <td class="actions">
                                    <a href="?domain=<?= h($domain) ?>&edit_alias=<?= h($alias['id']) ?>">Bearbeiten</a>
                                    <form method="post">
                                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="alias_delete">
                                        <input type="hidden" name="domain" value="<?= h($domain) ?>">
                                        <input type="hidden" name="id" value="<?= h($alias['id']) ?>">
                                        <button class="danger-text" type="submit">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <div class="modal <?= $openModal === 'account' ? 'is-open' : '' ?>" id="account-modal" role="dialog" aria-modal="true" aria-labelledby="account-modal-title">
            <div class="modal-backdrop" data-close-modal></div>
            <section class="modal-panel">
                <div class="modal-head">
                    <h2 id="account-modal-title"><?= $accountIsEdit ? 'Benutzer bearbeiten' : 'Benutzer anlegen' ?></h2>
                    <button class="icon-button" type="button" data-close-modal aria-label="Schließen">×</button>
                </div>
                <form method="post" class="form-grid" data-account-form data-password-min="<?= h($config['password_policy']['min_length'] ?? 8) ?>">
                    <div class="form-error" data-form-error hidden></div>
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="account_save">
                    <input type="hidden" name="domain" value="<?= h($domain) ?>">
                    <input type="hidden" name="id" value="<?= h($accountForm['id'] ?? 0) ?>">
                    <label>Adresse
                        <div class="email-input">
                            <input name="username" value="<?= h($accountForm['username'] ?? '') ?>" <?= $accountIsEdit ? 'readonly' : 'required' ?> data-local-part>
                            <span>@<?= h($domain) ?></span>
                        </div>
                    </label>
                    <label>Anzeigename
                        <input name="commonname" value="<?= h($accountForm['commonname'] ?? '') ?>">
                    </label>
                    <label>Quota in MB
                        <input type="number" min="0" step="1" name="quota" value="<?= h($accountForm['quota'] ?? 0) ?>">
                    </label>
                    <label>Passwort <?= $accountIsEdit ? '<small>leer lassen für unverändert</small>' : '' ?>
                        <input type="password" name="password" <?= $accountIsEdit ? '' : 'required' ?> autocomplete="new-password" data-password-field>
                    </label>
                    <label class="check"><input type="checkbox" name="enabled" <?= !$accountForm || (int)$accountForm['enabled'] === 1 ? 'checked' : '' ?>> Aktiv</label>
                    <label class="check"><input type="checkbox" name="sendonly" <?= $accountForm && (int)$accountForm['sendonly'] === 1 ? 'checked' : '' ?>> Nur senden</label>
                    <div class="modal-actions">
                        <button type="submit">Speichern</button>
                        <button class="secondary" type="button" data-close-modal>Abbrechen</button>
                    </div>
                </form>
            </section>
        </div>

        <div class="modal <?= $openModal === 'alias' ? 'is-open' : '' ?>" id="alias-modal" role="dialog" aria-modal="true" aria-labelledby="alias-modal-title">
            <div class="modal-backdrop" data-close-modal></div>
            <section class="modal-panel">
                <div class="modal-head">
                    <h2 id="alias-modal-title"><?= $aliasIsEdit ? 'Alias bearbeiten' : 'Alias anlegen' ?></h2>
                    <button class="icon-button" type="button" data-close-modal aria-label="Schließen">×</button>
                </div>
                <form method="post" class="form-grid" data-alias-form>
                    <div class="form-error" data-form-error hidden></div>
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="alias_save">
                    <input type="hidden" name="domain" value="<?= h($domain) ?>">
                    <input type="hidden" name="id" value="<?= h($aliasForm['id'] ?? 0) ?>">
                    <label>Typ
                        <select name="source_type" data-source-type>
                            <option value="address" <?= $aliasSourceType === 'address' ? 'selected' : '' ?>>Adresse</option>
                            <option value="catchall" <?= $aliasSourceType === 'catchall' ? 'selected' : '' ?>>Catch-all</option>
                        </select>
                    </label>
                    <label data-source-local>Quelle
                        <div class="email-input">
                            <input name="source_username" value="<?= h($aliasForm['source_username'] ?? '') ?>" data-local-part>
                            <span>@<?= h($domain) ?></span>
                        </div>
                    </label>
                    <label>Zieladresse
                        <input name="destination" type="email" required value="<?= h($aliasDestination) ?>">
                    </label>
                    <label class="check"><input type="checkbox" name="enabled" <?= !$aliasForm || (int)$aliasForm['enabled'] === 1 ? 'checked' : '' ?>> Aktiv</label>
                    <div class="modal-actions">
                        <button type="submit">Speichern</button>
                        <button class="secondary" type="button" data-close-modal>Abbrechen</button>
                    </div>
                </form>
            </section>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
