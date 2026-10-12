<?php
declare(strict_types=1);
session_start();

define('APP_NAME', 'حسابداری روزانه');
define('DB_DIR', __DIR__ . '/storage');
define('DB_PATH', DB_DIR . '/accounting.sqlite');

if (!is_dir(DB_DIR)) {
    mkdir(DB_DIR, 0755, true);
}
if (!file_exists(DB_PATH)) {
    @touch(DB_PATH);
}

try {
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    // Safe schema upgrades for existing installations.
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (id TEXT PRIMARY KEY)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        bank_name TEXT NOT NULL DEFAULT '',
        account_type TEXT NOT NULL DEFAULT 'bank',
        opening_balance INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL CHECK(kind IN ('income','expense')),
        amount INTEGER NOT NULL CHECK(amount > 0),
        account_id INTEGER NOT NULL,
        category TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        transaction_date TEXT NOT NULL,
        reference_no TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(account_id) REFERENCES accounts(id) ON DELETE RESTRICT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        kind TEXT NOT NULL DEFAULT 'both' CHECK(kind IN ('income','expense','both')),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT OR IGNORE INTO categories(name,kind) VALUES ('عمومی','both'),('حقوق و دستمزد','income'),('فروش','income'),('خرید کالا','expense'),('اجاره','expense'),('قبوض','expense'),('حمل‌ونقل','expense'),('خوراک','expense'),('درمان','expense'),('آموزش','both'),('سایر','both')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transfers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        from_account_id INTEGER NOT NULL,
        to_account_id INTEGER NOT NULL,
        amount INTEGER NOT NULL CHECK(amount > 0),
        transfer_date TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CHECK(from_account_id != to_account_id),
        FOREIGN KEY(from_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
        FOREIGN KEY(to_account_id) REFERENCES accounts(id) ON DELETE RESTRICT
    )");
    $transferCols = array_column($pdo->query('PRAGMA table_info(transfers)')->fetchAll(), 'name');
    foreach ([
        'destination_ref_type' => "ALTER TABLE transfers ADD COLUMN destination_ref_type TEXT NOT NULL DEFAULT 'card'",
        'destination_ref' => "ALTER TABLE transfers ADD COLUMN destination_ref TEXT NOT NULL DEFAULT ''",
        'destination_owner' => "ALTER TABLE transfers ADD COLUMN destination_owner TEXT NOT NULL DEFAULT ''",
    ] as $col => $sql) { if (!in_array($col, $transferCols, true)) $pdo->exec($sql); }
} catch (Throwable $e) {
    http_response_code(500);
    exit('خطا در راه‌اندازی پایگاه داده. افزونه PDO SQLite و دسترسی نوشتن پوشه storage را بررسی کنید.');
}

function e(string|int|null $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('درخواست نامعتبر است. صفحه را تازه‌سازی کنید.');
    }
}
function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: index.php?page=login');
        exit;
    }
}
function fa_digits(string|int|float $value): string {
    return strtr((string)$value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}
function en_digits(string $value): string {
    $value = strtr($value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    return str_replace([',', '٬', ' '], '', $value);
}
function toman(int $amount): string { return fa_digits(number_format($amount)) . ' تومان'; }
function valid_date(string $date): bool {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}
function redirect_page(string $page, string $msg = ''): never {
    $url = 'index.php?page=' . rawurlencode($page);
    if ($msg !== '') $url .= '&msg=' . rawurlencode($msg);
    header('Location: ' . $url);
    exit;
}
