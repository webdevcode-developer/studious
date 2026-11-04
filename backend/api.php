<?php
/**
 * Studious — single-file JSON API.
 *
 * Every backend endpoint lives in this file. Apache (.htaccess) routes all
 * requests that are not real files to api.php, and the router below dispatches
 * on the request path, e.g.  POST /auth/login  or  GET /admin/users.
 *
 *  - Configuration : .env (or real environment variables, which take priority)
 *  - Database      : created and migrated automatically on first request
 *  - Payments      : Paystack (initialize, verify, signed webhook)
 *  - File storage  : Google Drive (production) or local /uploads (development)
 *
 * Requires PHP 8.1+ with pdo_mysql, curl, openssl and fileinfo.
 */
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

const APP_VERSION    = '2.0.0';
const SCHEMA_VERSION = 1;

/* =========================================================================
 * 1. ENVIRONMENT (.env loader + helpers)
 * ========================================================================= */

function load_env(): void
{
    $files = [__DIR__ . '/.env', dirname(__DIR__) . '/.env', '/etc/secrets/.env']; // last one: Render secret file
    foreach ($files as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            $value = trim($value);
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strrpos($value, $quote);
                $value = ($end !== false && $end > 0) ? substr($value, 1, $end - 1) : substr($value, 1);
            } else {
                $value = trim((string)preg_replace('/\s+#.*$/', '', $value));
            }
            // Real environment variables (Render, Docker, Apache SetEnv) always win.
            if ($key !== '' && getenv($key) === false && !isset($_ENV[$key])) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
load_env();

function env(string $key, string $default = ''): string
{
    $v = getenv($key);
    if ($v === false) {
        $v = $_ENV[$key] ?? false;
    }
    return ($v === false || $v === '') ? $default : (string)$v;
}

/** First non-empty value among several variable names (used for Clever Cloud fallbacks). */
function env_first(array $keys, string $default = ''): string
{
    foreach ($keys as $k) {
        $v = env($k);
        if ($v !== '') {
            return $v;
        }
    }
    return $default;
}

function env_bool(string $key, bool $default = false): bool
{
    $v = strtolower(env($key));
    if ($v === '') {
        return $default;
    }
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

@date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));

/* =========================================================================
 * 2. HTTP HELPERS
 * ========================================================================= */

final class ApiError extends RuntimeException
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function ok(array $data = [], int $status = 200): never
{
    respond($status, ['ok' => true] + $data);
}

function fail(int $status, string $message): never
{
    throw new ApiError($status, $message);
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
}

function normalize_origin(string $url): string
{
    $p = parse_url(trim($url));
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return '';
    }
    return strtolower($p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
}

function send_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $list = array_filter(array_map('trim', explode(',', env('ALLOWED_ORIGINS', env('FRONTEND_URL')))));
    $allowed = in_array('*', $list, true);
    if (!$allowed && $origin !== '') {
        $allowed = in_array(normalize_origin($origin), array_map('normalize_origin', $list), true);
    }
    if ($origin !== '' && $allowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function request_route(): string
{
    if (!empty($_GET['route'])) {
        return '/' . trim((string)$_GET['route'], '/');
    }
    $path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api.php')), '/');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . ltrim($path, '/');
    if (str_starts_with($path, '/api.php')) {
        $path = substr($path, 8);
    }
    if ($path === '/api' || str_starts_with($path, '/api/')) {
        $path = substr($path, 4);
    }
    return '/' . trim($path, '/');
}

function input(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $ct = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($ct, 'application/json')) {
        $json = json_decode(file_get_contents('php://input') ?: '', true);
        $data = is_array($json) ? $json : [];
    } else {
        $data = $_POST;
    }
    return $data;
}

/** Trimmed string input. */
function inp(string $key, string $default = ''): string
{
    $v = input()[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

function truthy(string $v): bool
{
    return !in_array(strtolower($v), ['', '0', 'false', 'off', 'no'], true);
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower((string)$k) === 'authorization') {
                $h = (string)$v;
            }
        }
    }
    return preg_match('/^Bearer\s+(\S+)/i', $h, $m) ? $m[1] : null;
}

function client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        return substr(trim(explode(',', $xff)[0]), 0, 64);
    }
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function api_base_url(): string
{
    $configured = env('API_URL');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $proto = trim(explode(',', $proto)[0]);
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function frontend_url(): string
{
    $u = env('FRONTEND_URL');
    if ($u === '') {
        $u = $_SERVER['HTTP_ORIGIN'] ?? '';
    }
    return rtrim($u, '/');
}

/* =========================================================================
 * 3. DATABASE (connection, automatic creation + migrations)
 * ========================================================================= */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $host = env_first(['DB_HOST', 'MYSQL_ADDON_HOST'], '127.0.0.1');
    $port = env_first(['DB_PORT', 'MYSQL_ADDON_PORT'], '3306');
    $name = env_first(['DB_NAME', 'MYSQL_ADDON_DB'], 'studious');
    $user = env_first(['DB_USER', 'MYSQL_ADDON_USER'], 'root');
    $pass = env_first(['DB_PASS', 'MYSQL_ADDON_PASSWORD'], '');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $pass, $opts);
    } catch (PDOException $e) {
        // 1049 = unknown database -> create it automatically (local XAMPP / any server that allows it).
        if ((int)$e->getCode() !== 1049 || !env_bool('AUTO_SETUP', true)) {
            throw $e;
        }
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $name);
        if ($safe === '') {
            throw new RuntimeException('Invalid DB_NAME.');
        }
        $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $opts);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$safe}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO($dsn, $user, $pass, $opts);
    }
    // Keep PHP and MySQL on the same clock so NOW() comparisons are correct.
    $offset = (new DateTimeImmutable('now'))->format('P');
    $pdo->exec("SET time_zone = '{$offset}'");
    ensure_schema($pdo);
    return $pdo;
}

function meta_get(PDO $pdo, string $key): ?string
{
    try {
        $q = $pdo->prepare('SELECT meta_value FROM app_meta WHERE meta_key = ?');
        $q->execute([$key]);
        $v = $q->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (PDOException $e) {
        return null; // table does not exist yet
    }
}

function setup_signature(): string
{
    return SCHEMA_VERSION . '|' . strtolower(env('ADMIN_EMAIL')) . '|' . (env_bool('SEED_DEMO_DATA') ? '1' : '0');
}

function ensure_schema(PDO $pdo): void
{
    if (!env_bool('AUTO_SETUP', true)) {
        return;
    }
    $sig = setup_signature();
    if (meta_get($pdo, 'setup_signature') === $sig) {
        return; // already set up: costs a single indexed lookup per request
    }
    run_setup($pdo, $sig);
}

function run_setup(PDO $pdo, string $sig): void
{
    $pdo->query("SELECT GET_LOCK('studious_setup', 30)")->fetchColumn();
    try {
        if (meta_get($pdo, 'setup_signature') === $sig) {
            return; // another request finished while we waited for the lock
        }
        foreach (schema_statements() as $sql) {
            $pdo->exec($sql);
        }

        if ((int)$pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
            $ins = $pdo->prepare('INSERT INTO plans(name,description,price,duration_days) VALUES(?,?,?,?)');
            $ins->execute(['7-day access', 'A short pass for focused revision.', 500, 7]);
            $ins->execute(['Monthly access', 'Full library access for 30 days.', 1500, 30]);
            $ins->execute(['Semester access', 'Longer access for a full semester.', 5000, 180]);
        }

        if (env_bool('SEED_DEMO_DATA')) {
            seed_demo_content($pdo);
        }

        $adminEmail = strtolower(env('ADMIN_EMAIL'));
        $adminPass  = env('ADMIN_PASSWORD');
        if ($adminEmail !== '' && $adminPass !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $q = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $q->execute([$adminEmail]);
            $id = $q->fetchColumn();
            if ($id === false) {
                $pdo->prepare("INSERT INTO users(name,email,password_hash,role,level) VALUES(?,?,?,'admin','Admin')")
                    ->execute([env('ADMIN_NAME', 'Studious Admin'), $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
            } else {
                $pdo->prepare("UPDATE users SET role='admin' WHERE id = ?")->execute([$id]);
            }
        }

        $pdo->prepare('REPLACE INTO app_meta(meta_key, meta_value) VALUES(?, ?)')->execute(['setup_signature', $sig]);
        $pdo->prepare('REPLACE INTO app_meta(meta_key, meta_value) VALUES(?, ?)')->execute(['schema_version', (string)SCHEMA_VERSION]);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('studious_setup')")->fetchColumn();
    }
}

function schema_statements(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS app_meta(
            meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
            meta_value VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS schools(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            short_name VARCHAR(40) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_school_name(name)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS departments(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            school_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(180) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_department(school_id,name),
            CONSTRAINT fk_dept_school FOREIGN KEY(school_id) REFERENCES schools(id)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS users(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('student','admin') NOT NULL DEFAULT 'student',
            school_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            level VARCHAR(30) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_school FOREIGN KEY(school_id) REFERENCES schools(id),
            CONSTRAINT fk_user_dept FOREIGN KEY(department_id) REFERENCES departments(id),
            INDEX idx_user_role(role)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS auth_tokens(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_token_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_token_expiry(expires_at)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS login_attempts(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            ip VARCHAR(64) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_attempt_email(email,created_at),
            INDEX idx_attempt_ip(ip,created_at)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS questions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            school_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL,
            level VARCHAR(30) NOT NULL,
            curriculum_version VARCHAR(100) NOT NULL,
            course_code VARCHAR(40) NOT NULL,
            title VARCHAR(240) NOT NULL,
            exam_year SMALLINT UNSIGNED NOT NULL,
            question_text MEDIUMTEXT NOT NULL,
            options_json JSON NULL,
            correct_answer MEDIUMTEXT NULL,
            explanation MEDIUMTEXT NULL,
            image_file VARCHAR(255) NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_q_school FOREIGN KEY(school_id) REFERENCES schools(id),
            CONSTRAINT fk_q_dept FOREIGN KEY(department_id) REFERENCES departments(id),
            INDEX idx_q_browse(school_id,department_id,level,course_code,exam_year),
            INDEX idx_q_published(is_published)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS materials(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            school_id BIGINT UNSIGNED NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL,
            level VARCHAR(30) NOT NULL,
            curriculum_version VARCHAR(100) NOT NULL,
            course_code VARCHAR(40) NOT NULL,
            title VARCHAR(240) NOT NULL,
            description TEXT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(12) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_m_school FOREIGN KEY(school_id) REFERENCES schools(id),
            CONSTRAINT fk_m_dept FOREIGN KEY(department_id) REFERENCES departments(id),
            INDEX idx_m_browse(school_id,department_id,level,course_code)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS plans(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            price DECIMAL(12,2) NOT NULL,
            duration_days INT UNSIGNED NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS payment_requests(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            reference VARCHAR(80) NOT NULL UNIQUE,
            status ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(40) NOT NULL DEFAULT 'paystack',
            paystack_id VARCHAR(40) NULL,
            channel VARCHAR(40) NULL,
            gateway_response VARCHAR(255) NULL,
            paid_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_pay_user FOREIGN KEY(user_id) REFERENCES users(id),
            CONSTRAINT fk_pay_plan FOREIGN KEY(plan_id) REFERENCES plans(id),
            INDEX idx_pay_status(status)
        ) ENGINE=InnoDB",

        "CREATE TABLE IF NOT EXISTS subscriptions(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            payment_request_id BIGINT UNSIGNED NOT NULL UNIQUE,
            status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_sub_user FOREIGN KEY(user_id) REFERENCES users(id),
            CONSTRAINT fk_sub_plan FOREIGN KEY(plan_id) REFERENCES plans(id),
            CONSTRAINT fk_sub_payment FOREIGN KEY(payment_request_id) REFERENCES payment_requests(id),
            INDEX idx_sub_access(user_id,status,starts_at,ends_at)
        ) ENGINE=InnoDB",
    ];
}

function seed_demo_content(PDO $pdo): void
{
    $pdo->exec("INSERT IGNORE INTO schools(name,short_name) VALUES('Demo University','DEMO')");
    foreach (['Computer Science', 'Business Administration', 'Mass Communication'] as $dept) {
        $pdo->prepare("INSERT IGNORE INTO departments(school_id,name) SELECT id,? FROM schools WHERE name='Demo University'")->execute([$dept]);
    }
    $pdo->exec("INSERT INTO questions(school_id,department_id,level,curriculum_version,course_code,title,exam_year,question_text,options_json,correct_answer,explanation,is_published)
        SELECT s.id,d.id,'200','2024/2025','CSC201','Introduction to Algorithms',2024,'Which of the following best describes an algorithm?',
        '{\"A\":\"A finite sequence of well-defined steps\",\"B\":\"A computer monitor\",\"C\":\"A programming language only\",\"D\":\"A database table\"}',
        'A — A finite sequence of well-defined steps','An algorithm is a finite, ordered set of unambiguous instructions for solving a problem. The steps should produce a result and terminate.',1
        FROM schools s JOIN departments d ON d.school_id=s.id AND d.name='Computer Science'
        WHERE s.name='Demo University' AND NOT EXISTS(SELECT 1 FROM questions WHERE course_code='CSC201' AND title='Introduction to Algorithms')");
    
    // Ladoke Akintola University Of Technology
    $pdo->exec("INSERT IGNORE INTO schools(name,short_name) VALUES('Ladoke Akintola University Of Technology','LAUTECH')");
    $pdo->exec("INSERT INTO departments (school_id,name) VALUES
        -- Faculty of Computing and Informatics
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Computer Science'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Cyber Security Science'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Information Systems'),
        
        -- Faculty of Engineering and Technology
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Agricultural Engineering'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Electrical Engineering'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Computer Engineering'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Chemical Engineering'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Civil Engineering'),
        
        -- Faculty of Agricultural Sciences
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Agricultural Economics'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Animal Production and Health'),
        
        -- Faculty of Pure and Applied Sciences
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Pure and Applied Biology'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Biochemistry'),
        
        -- College of Health Sciences
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Anatomy'),
        ((SELECT id FROM schools WHERE name='Ladoke Akintola University Of Technology'), 'Department of Nursing Science')
    ");
}

/* =========================================================================
 * 4. AUTH + ACCESS
 * ========================================================================= */

function auth_user(): ?array
{
    static $done = false, $user = null;
    if ($done) {
        return $user;
    }
    $done = true;
    $token = bearer_token();
    if (!$token) {
        return null;
    }
    $q = db()->prepare(
        'SELECT u.id,u.name,u.email,u.role,u.school_id,u.department_id,u.level
         FROM auth_tokens t JOIN users u ON u.id=t.user_id
         WHERE t.token_hash=? AND t.expires_at>NOW() AND u.is_active=1 LIMIT 1'
    );
    $q->execute([hash('sha256', $token)]);
    $row = $q->fetch();
    $user = $row ? public_user($row) : null;
    return $user;
}

function public_user(array $u): array
{
    return [
        'id'            => (int)$u['id'],
        'name'          => $u['name'],
        'email'         => $u['email'],
        'role'          => $u['role'],
        'school_id'     => $u['school_id'] !== null ? (int)$u['school_id'] : null,
        'department_id' => $u['department_id'] !== null ? (int)$u['department_id'] : null,
        'level'         => $u['level'],
    ];
}

function issue_token(int $userId): string
{
    $pdo = db();
    $token = bin2hex(random_bytes(32));
    $days = max(1, (int)env('TOKEN_TTL_DAYS', '30'));
    $pdo->exec('DELETE FROM auth_tokens WHERE expires_at < NOW()');
    $pdo->prepare('INSERT INTO auth_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(), INTERVAL ? DAY))')
        ->execute([$userId, hash('sha256', $token), $days]);
    return $token;
}

function active_subscription(int $userId): ?array
{
    $q = db()->prepare(
        "SELECT p.name, s.starts_at, s.ends_at,
                (SELECT MAX(ends_at) FROM subscriptions WHERE user_id=? AND status='active' AND ends_at>NOW()) AS access_until
         FROM subscriptions s JOIN plans p ON p.id=s.plan_id
         WHERE s.user_id=? AND s.status='active' AND s.starts_at<=NOW() AND s.ends_at>NOW()
         ORDER BY s.ends_at DESC LIMIT 1"
    );
    $q->execute([$userId, $userId]);
    $row = $q->fetch();
    return $row ?: null;
}

function user_has_access(array $user): bool
{
    return $user['role'] === 'admin' || active_subscription((int)$user['id']) !== null;
}

/* =========================================================================
 * 5. FILE STORAGE (Google Drive in production, local disk for development)
 * ========================================================================= */

function drive_configured(): bool
{
    return env('GOOGLE_SERVICE_ACCOUNT_JSON') !== '' || env('GOOGLE_SERVICE_ACCOUNT_FILE') !== '';
}

function drive_token(): string
{
    static $cached = null, $expires = 0;
    if ($cached !== null && $expires > time() + 60) {
        return $cached;
    }
    $raw = env('GOOGLE_SERVICE_ACCOUNT_JSON');
    if ($raw === '') {
        $path = env('GOOGLE_SERVICE_ACCOUNT_FILE');
        if ($path !== '' && is_file($path)) {
            $raw = (string)file_get_contents($path);
        }
    }
    $sa = json_decode($raw, true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        fail(503, 'Google Drive service account is not configured correctly.');
    }
    $now = time();
    $b64 = fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
    ]));
    $sig = '';
    if (!openssl_sign($unsigned, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        fail(502, 'Could not sign the Google Drive token request.');
    }
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . $b64($sig),
        ]),
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$res, true);
    if ($code !== 200 || empty($json['access_token'])) {
        fail(502, 'Google Drive authentication failed.');
    }
    $cached = (string)$json['access_token'];
    $expires = $now + (int)($json['expires_in'] ?? 3000);
    return $cached;
}

function drive_upload(string $tmp, string $filename, string $mime): string
{
    $meta = ['name' => $filename, 'mimeType' => $mime];
    if (env('GOOGLE_DRIVE_FOLDER_ID') !== '') {
        $meta['parents'] = [env('GOOGLE_DRIVE_FOLDER_ID')];
    }
    $boundary = 'studious' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode($meta) . "\r\n"
          . "--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n" . file_get_contents($tmp) . "\r\n--{$boundary}--";
    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . drive_token(),
            'Content-Type: multipart/related; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ],
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$res, true);
    if ($code < 200 || $code >= 300 || empty($json['id'])) {
        fail(502, 'Google Drive upload failed: ' . ($json['error']['message'] ?? 'unknown error'));
    }
    return 'drive:' . $json['id'];
}

function drive_read(string $id): array
{
    $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?alt=media');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . drive_token()],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
    ]);
    $data = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream');
    curl_close($ch);
    if ($code < 200 || $code >= 300 || $data === false) {
        fail(404, 'File not found in Google Drive.');
    }
    return ['data' => $data, 'mime' => $mime];
}

function upload_dir(): string
{
    $dir = env('UPLOAD_DIR', __DIR__ . '/uploads');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return rtrim($dir, '/\\');
}

/** Validates and stores an uploaded file. Returns ['key','size','ext','mime']. */
function store_upload(array $file, array $allowedExt): ?array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($err !== UPLOAD_ERR_OK) {
        fail(422, 'Upload failed (code ' . (int)$err . '). The file may exceed the server upload limit.');
    }
    $max = (int)env('MAX_UPLOAD_MB', '20') * 1048576;
    if ((int)$file['size'] > $max) {
        fail(413, 'File is larger than ' . (int)env('MAX_UPLOAD_MB', '20') . ' MB.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        fail(415, 'Unsupported file type. Allowed: ' . implode(', ', $allowedExt) . '.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: 'application/octet-stream';
    $mimeMap = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'], 'gif' => ['image/gif'],
        'doc'  => ['application/msword', 'application/CDFV2', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'txt'  => ['text/plain'],
    ];
    if (isset($mimeMap[$ext]) && !in_array($mime, $mimeMap[$ext], true)) {
        fail(415, 'The file content does not match its extension.');
    }
    if (drive_configured()) {
        $key = drive_upload($file['tmp_name'], basename((string)$file['name']), $mime);
    } else {
        $key = bin2hex(random_bytes(18)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], upload_dir() . '/' . $key)) {
            fail(500, 'Could not save the uploaded file.');
        }
    }
    return ['key' => $key, 'size' => (int)$file['size'], 'ext' => $ext, 'mime' => $mime];
}

/* ---- Signed, expiring download links (so <img>/<a> work from the static frontend) ---- */

function app_key(): string
{
    $k = env('APP_KEY');
    if ($k === '') {
        fail(500, 'APP_KEY is not configured on the server.');
    }
    return $k;
}

function sign_file_url(string $file, string $kind, int $uid, int $ttl = 3600): string
{
    $exp = time() + $ttl;
    $sig = hash_hmac('sha256', "{$file}|{$kind}|{$uid}|{$exp}", app_key());
    return api_base_url() . '/download?' . http_build_query(['file' => $file, 'k' => $kind, 'u' => $uid, 'exp' => $exp, 'sig' => $sig]);
}

/* =========================================================================
 * 6. PAYSTACK
 * ========================================================================= */

function paystack_request(string $method, string $path, ?array $body = null): array
{
    $secret = env('PAYSTACK_SECRET_KEY');
    if ($secret === '') {
        fail(503, 'Payments are not configured yet (PAYSTACK_SECRET_KEY is missing).');
    }
    $ch = curl_init('https://api.paystack.co' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $secret, 'Content-Type: application/json', 'Accept: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        fail(502, 'Could not reach Paystack: ' . $err);
    }
    $json = json_decode((string)$raw, true);
    if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['status'])) {
        fail(502, 'Paystack error: ' . (is_array($json) ? ($json['message'] ?? 'request failed') : 'invalid response'));
    }
    return $json;
}

function kobo(float|string $amount): int
{
    return (int)round((float)$amount * 100);
}

function payment_view(array $r): array
{
    return [
        'id'        => (int)$r['id'],
        'reference' => $r['reference'],
        'status'    => $r['status'],
        'amount'    => (float)$r['amount'],
        'plan_name' => $r['plan_name'] ?? null,
        'channel'   => $r['channel'] ?? null,
        'paid_at'   => $r['paid_at'] ?? null,
        'created_at' => $r['created_at'] ?? null,
    ];
}

/**
 * Verifies a transaction with Paystack and (idempotently) activates the subscription.
 * Used by the redirect-callback verification, the webhook and the admin "re-verify" button.
 * The browser redirect alone is never trusted as proof of payment.
 */
function settle_payment(string $reference): array
{
    $verified = paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
    $data = $verified['data'] ?? [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT r.*, p.duration_days, p.name AS plan_name FROM payment_requests r JOIN plans p ON p.id=r.plan_id WHERE r.reference=? FOR UPDATE');
        $q->execute([$reference]);
        $r = $q->fetch();
        if (!$r) {
            fail(404, 'Payment reference not found.');
        }
        if ($r['status'] === 'success') {
            $pdo->commit();
            return ['status' => 'success', 'already_processed' => true, 'message' => 'Payment already confirmed.'] + payment_view($r);
        }

        $pStatus = (string)($data['status'] ?? '');
        $gateway = substr((string)($data['gateway_response'] ?? ''), 0, 255);

        if ($pStatus === 'success') {
            $currency = strtoupper(env('PAYSTACK_CURRENCY', 'NGN'));
            if ((int)($data['amount'] ?? 0) !== kobo($r['amount']) || strtoupper((string)($data['currency'] ?? '')) !== $currency) {
                $pdo->prepare("UPDATE payment_requests SET status='failed', gateway_response=? WHERE id=?")
                    ->execute(['Amount or currency mismatch', $r['id']]);
                $pdo->commit();
                error_log("Studious: amount/currency mismatch for {$reference}");
                return ['status' => 'failed', 'message' => 'The amount paid does not match the selected plan. Contact support with your reference.'] + payment_view($r);
            }
            // Stack on top of any subscription that is still running.
            $s = $pdo->prepare("SELECT MAX(ends_at) FROM subscriptions WHERE user_id=? AND status='active' AND ends_at>NOW()");
            $s->execute([$r['user_id']]);
            $latest = $s->fetchColumn();
            $now = new DateTimeImmutable('now');
            $start = ($latest && strtotime((string)$latest) > time()) ? new DateTimeImmutable((string)$latest) : $now;
            $end = $start->modify('+' . (int)$r['duration_days'] . ' days');

            $pdo->prepare("UPDATE payment_requests SET status='success', paid_at=NOW(), paystack_id=?, channel=?, gateway_response=? WHERE id=?")
                ->execute([(string)($data['id'] ?? ''), substr((string)($data['channel'] ?? ''), 0, 40), $gateway, $r['id']]);
            $pdo->prepare("INSERT INTO subscriptions(user_id,plan_id,payment_request_id,status,starts_at,ends_at) VALUES(?,?,?,'active',?,?)")
                ->execute([$r['user_id'], $r['plan_id'], $r['id'], $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
            $pdo->commit();
            return ['status' => 'success', 'message' => 'Payment confirmed. Your subscription is active.', 'ends_at' => $end->format(DATE_ATOM)]
                + payment_view(['status' => 'success'] + $r);
        }

        if (in_array($pStatus, ['failed', 'reversed'], true)) {
            $pdo->prepare("UPDATE payment_requests SET status='failed', gateway_response=? WHERE id=?")->execute([$gateway, $r['id']]);
            $pdo->commit();
            return ['status' => 'failed', 'message' => $gateway !== '' ? $gateway : 'Payment failed.'] + payment_view(['status' => 'failed'] + $r);
        }

        $pdo->commit(); // abandoned / ongoing: leave pending, the customer may still complete it
        return ['status' => 'pending', 'message' => 'Payment has not been completed yet.'] + payment_view($r);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* =========================================================================
 * 7. ROUTE HANDLERS — PUBLIC
 * ========================================================================= */

function h_index(array $p = []): never
{
    ok([
        'name' => env('APP_NAME', 'Studious') . ' API', 'version' => APP_VERSION, 'status' => 'ok',
        'docs' => 'See README.md. Auth: Authorization: Bearer <token> from /auth/login or /auth/register.',
        'endpoints' => array_map(fn($r) => $r[0] . ' ' . $r[1], $GLOBALS['ROUTES']),
    ]);
}

function h_health(array $p = []): never
{
    $dbOk = true;
    try {
        db()->query('SELECT 1');
    } catch (Throwable $e) {
        $dbOk = false;
    }
    respond($dbOk ? 200 : 503, ['ok' => $dbOk, 'database' => $dbOk, 'time' => date(DATE_ATOM)]);
}

function h_meta(array $p = []): never
{
    ok(['app_name' => env('APP_NAME', 'Studious'), 'currency' => strtoupper(env('PAYSTACK_CURRENCY', 'NGN'))]);
}

function throttle_check(string $email): void
{
    $pdo = db();
    $q = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email=? AND created_at > (NOW() - INTERVAL 15 MINUTE)');
    $q->execute([$email]);
    $byEmail = (int)$q->fetchColumn();
    $q = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)');
    $q->execute([client_ip()]);
    $byIp = (int)$q->fetchColumn();
    if ($byEmail >= 8 || $byIp >= 30) {
        fail(429, 'Too many failed sign-in attempts. Please wait 15 minutes and try again.');
    }
}

function h_register(array $p = []): never
{
    $name = inp('name');
    $email = strtolower(inp('email'));
    $password = (string)(input()['password'] ?? '');
    $school = (int)inp('school_id');
    $dept = (int)inp('department_id');
    $level = inp('level');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !$school || !$dept || $level === '') {
        fail(422, 'Name, a valid email, an 8+ character password, institution, department and level are required.');
    }
    $pdo = db();
    $q = $pdo->prepare('SELECT id FROM departments WHERE id=? AND school_id=? AND is_active=1');
    $q->execute([$dept, $school]);
    if (!$q->fetch()) {
        fail(422, 'That department does not belong to the selected institution.');
    }
    try {
        $pdo->prepare("INSERT INTO users(name,email,password_hash,role,school_id,department_id,level) VALUES(?,?,?,'student',?,?,?)")
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $school, $dept, $level]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail(409, 'That email is already registered.');
        }
        throw $e;
    }
    $id = (int)$pdo->lastInsertId();
    $q = $pdo->prepare('SELECT id,name,email,role,school_id,department_id,level FROM users WHERE id=?');
    $q->execute([$id]);
    ok(['token' => issue_token($id), 'user' => public_user($q->fetch())], 201);
}

function h_login(array $p = []): never
{
    $email = strtolower(inp('email'));
    $password = (string)(input()['password'] ?? '');
    throttle_check($email);
    $pdo = db();
    $q = $pdo->prepare('SELECT id,name,email,password_hash,role,school_id,department_id,level,is_active FROM users WHERE email=? LIMIT 1');
    $q->execute([$email]);
    $u = $q->fetch();
    if (!$u || !$u['is_active'] || !password_verify($password, $u['password_hash'])) {
        $pdo->prepare('INSERT INTO login_attempts(email,ip) VALUES(?,?)')->execute([$email, client_ip()]);
        fail(401, 'Email or password is incorrect, or the account is disabled.');
    }
    $pdo->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]);
    ok(['token' => issue_token((int)$u['id']), 'user' => public_user($u)]);
}

function h_logout(array $p = []): never
{
    $t = bearer_token();
    if ($t) {
        db()->prepare('DELETE FROM auth_tokens WHERE token_hash=?')->execute([hash('sha256', $t)]);
    }
    ok(['message' => 'Signed out.']);
}

function h_me(array $p = []): never
{
    ok(['user' => auth_user()]);
}

function h_schools(array $p = []): never
{
    ok(['data' => db()->query('SELECT id,name,short_name FROM schools WHERE is_active=1 ORDER BY name')->fetchAll()]);
}

function h_departments(array $p = []): never
{
    $sid = (int)($_GET['school_id'] ?? 0);
    $q = db()->prepare('SELECT id,school_id,name FROM departments WHERE is_active=1 AND (?=0 OR school_id=?) ORDER BY name');
    $q->execute([$sid, $sid]);
    ok(['data' => $q->fetchAll()]);
}

function h_plans(array $p = []): never
{
    $rows = db()->query('SELECT id,name,description,price,duration_days FROM plans WHERE is_active=1 ORDER BY price')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['price'] = (float)$r['price'];
        $r['duration_days'] = (int)$r['duration_days'];
    }
    ok(['data' => $rows]);
}

function h_catalog(array $p = []): never
{
    $sql = 'SELECT q.id,q.title,q.course_code,q.exam_year,q.level,s.name AS school,d.name AS department
            FROM questions q JOIN schools s ON s.id=q.school_id JOIN departments d ON d.id=q.department_id
            WHERE q.is_published=1';
    $args = [];
    if ((int)($_GET['school_id'] ?? 0) > 0) {
        $sql .= ' AND q.school_id=?';
        $args[] = (int)$_GET['school_id'];
    }
    $term = trim((string)($_GET['q'] ?? ''));
    if ($term !== '') {
        $sql .= ' AND (q.title LIKE ? OR q.course_code LIKE ?)';
        $args[] = "%{$term}%";
        $args[] = "%{$term}%";
    }
    $sql .= ' ORDER BY q.created_at DESC LIMIT 30';
    $q = db()->prepare($sql);
    $q->execute($args);
    ok(['data' => $q->fetchAll()]);
}

/** Signed download. No bearer header needed so <img> and <a> tags work from the static frontend. */
function h_download(array $p = []): never
{
    $file = (string)($_GET['file'] ?? '');
    $kind = (string)($_GET['k'] ?? '');
    $uid  = (int)($_GET['u'] ?? 0);
    $exp  = (int)($_GET['exp'] ?? 0);
    $sig  = (string)($_GET['sig'] ?? '');
    $expect = hash_hmac('sha256', "{$file}|{$kind}|{$uid}|{$exp}", app_key());
    if ($file === '' || $exp < time() || !hash_equals($expect, $sig)) {
        fail(403, 'This download link is invalid or has expired. Reload the page to get a new one.');
    }
    $pdo = db();
    if ($kind === 'm') {
        $q = $pdo->prepare('SELECT title,file_type FROM materials WHERE file_name=? AND is_published=1 LIMIT 1');
        $q->execute([$file]);
        $rec = $q->fetch();
        $u = $pdo->prepare('SELECT id,role FROM users WHERE id=? AND is_active=1');
        $u->execute([$uid]);
        $usr = $u->fetch();
        if (!$rec || !$usr || !user_has_access($usr)) {
            fail(403, 'An active subscription is required to open this material.');
        }
        $title = $rec['title'];
        $ext = strtolower($rec['file_type']);
    } elseif ($kind === 'q') {
        $q = $pdo->prepare('SELECT title FROM questions WHERE image_file=? AND is_published=1 LIMIT 1');
        $q->execute([$file]);
        $rec = $q->fetch();
        if (!$rec) {
            fail(404, 'File not found.');
        }
        $title = $rec['title'];
        $ext = '';
    } else {
        fail(400, 'Invalid download request.');
    }

    if (str_starts_with($file, 'drive:')) {
        $id = substr($file, 6);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            fail(404, 'File not found.');
        }
        $dl = drive_read($id);
        $data = $dl['data'];
        $mime = $dl['mime'];
        $length = strlen($data);
    } else {
        $name = basename($file);
        $path = upload_dir() . '/' . $name;
        if (!preg_match('/^[a-f0-9]{36}\.[a-z0-9]+$/', $name) || !is_file($path)) {
            fail(404, 'File not found.');
        }
        $data = null;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $length = (int)filesize($path);
        $ext = $ext !== '' ? $ext : strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
    $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'text/plain'], true);
    $safe = trim((string)preg_replace('/[^A-Za-z0-9._ -]/', '', $title)) ?: 'study-material';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $length);
    header('Cache-Control: private, max-age=300');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . ($ext !== '' ? '.' . $ext : '') . '"');
    if ($data !== null) {
        echo $data;
    } else {
        readfile($path);
    }
    exit;
}

/* =========================================================================
 * 8. ROUTE HANDLERS — SIGNED-IN STUDENTS
 * ========================================================================= */

function h_dashboard(array $p = []): never
{
    $u = auth_user();
    $pdo = db();
    $school = $u['school_id'] ?? 0;
    $q = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE is_published=1 AND (?=0 OR school_id=?)');
    $q->execute([$school, $school]);
    $questions = (int)$q->fetchColumn();
    $q = $pdo->prepare('SELECT COUNT(*) FROM materials WHERE is_published=1 AND (?=0 OR school_id=?)');
    $q->execute([$school, $school]);
    $materials = (int)$q->fetchColumn();
    $sub = active_subscription((int)$u['id']);
    ok([
        'user' => $u,
        'counts' => ['questions' => $questions, 'materials' => $materials],
        'premium' => user_has_access($u),
        'subscription' => $sub ? ['plan' => $sub['name'], 'starts_at' => $sub['starts_at'], 'ends_at' => $sub['access_until'] ?: $sub['ends_at']] : null,
    ]);
}

function h_questions(array $p = []): never
{
    $u = auth_user();
    $admin = $u['role'] === 'admin';
    $premium = user_has_access($u);
    $where = ['q.is_published=1'];
    $args = [];

    $school = $admin ? (int)($_GET['school_id'] ?? 0) : (int)$u['school_id'];
    $dept = $admin ? (int)($_GET['department_id'] ?? 0) : (int)$u['department_id'];
    if ($school) { $where[] = 'q.school_id=?'; $args[] = $school; }
    if ($dept) { $where[] = 'q.department_id=?'; $args[] = $dept; }
    $level = array_key_exists('level', $_GET) ? trim((string)$_GET['level']) : ($admin ? '' : $u['level']);
    if ($level !== '') { $where[] = 'q.level=?'; $args[] = $level; }
    $course = trim((string)($_GET['course'] ?? ''));
    if ($course !== '') { $where[] = 'q.course_code LIKE ?'; $args[] = "%{$course}%"; }
    $term = trim((string)($_GET['q'] ?? ''));
    if ($term !== '') { $where[] = '(q.title LIKE ? OR q.question_text LIKE ?)'; $args[] = "%{$term}%"; $args[] = "%{$term}%"; }
    $id = (int)($_GET['id'] ?? 0);
    if ($id) { $where[] = 'q.id=?'; $args[] = $id; }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $cond = implode(' AND ', $where);

    $pdo = db();
    $c = $pdo->prepare("SELECT COUNT(*) FROM questions q WHERE {$cond}");
    $c->execute($args);
    $total = (int)$c->fetchColumn();

    $q = $pdo->prepare(
        "SELECT q.*, s.name AS school, d.name AS department FROM questions q
         JOIN schools s ON s.id=q.school_id JOIN departments d ON d.id=q.department_id
         WHERE {$cond} ORDER BY q.course_code, q.exam_year DESC, q.id LIMIT {$limit} OFFSET {$offset}"
    );
    $q->execute($args);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'course_code' => $r['course_code'], 'title' => $r['title'],
            'exam_year' => (int)$r['exam_year'], 'level' => $r['level'], 'curriculum_version' => $r['curriculum_version'],
            'school' => $r['school'], 'department' => $r['department'], 'question_text' => $r['question_text'],
            'options' => $r['options_json'] ? json_decode($r['options_json'], true) : null,
            'image_url' => $r['image_file'] ? sign_file_url($r['image_file'], 'q', (int)$u['id']) : null,
            // Answers and notes are never sent to non-subscribers.
            'correct_answer' => $premium ? $r['correct_answer'] : null,
            'explanation' => $premium ? $r['explanation'] : null,
        ];
    }
    ok(['premium' => $premium, 'page' => $page, 'limit' => $limit, 'total' => $total, 'data' => $out]);
}

function h_materials(array $p = []): never
{
    $u = auth_user();
    $admin = $u['role'] === 'admin';
    $premium = user_has_access($u);
    $where = ['m.is_published=1'];
    $args = [];
    $school = $admin ? (int)($_GET['school_id'] ?? 0) : (int)$u['school_id'];
    if ($school) { $where[] = 'm.school_id=?'; $args[] = $school; }
    if ((int)($_GET['department_id'] ?? 0) > 0) { $where[] = 'm.department_id=?'; $args[] = (int)$_GET['department_id']; }
    $q = db()->prepare(
        'SELECT m.id,m.level,m.curriculum_version,m.course_code,m.title,m.description,m.file_name,m.file_type,m.file_size,d.name AS department
         FROM materials m JOIN departments d ON d.id=m.department_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY m.created_at DESC LIMIT 100'
    );
    $q->execute($args);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[] = [
            'id' => (int)$r['id'], 'level' => $r['level'], 'curriculum_version' => $r['curriculum_version'],
            'course_code' => $r['course_code'], 'title' => $r['title'], 'description' => $r['description'],
            'file_type' => $r['file_type'], 'file_size' => (int)$r['file_size'], 'department' => $r['department'],
            'download_url' => $premium ? sign_file_url($r['file_name'], 'm', (int)$u['id']) : null,
        ];
    }
    ok(['premium' => $premium, 'data' => $out]);
}

/* ---- Payments ---- */

function h_payment_initialize(array $p = []): never
{
    $u = auth_user();
    $pdo = db();
    $q = $pdo->prepare('SELECT * FROM plans WHERE id=? AND is_active=1');
    $q->execute([(int)inp('plan_id')]);
    $plan = $q->fetch();
    if (!$plan) {
        fail(422, 'Choose a valid plan.');
    }
    if ($u['role'] === 'admin') {
        fail(422, 'Administrator accounts already have full access.');
    }
    $reference = 'STD' . strtoupper(bin2hex(random_bytes(10)));
    $pdo->prepare("INSERT INTO payment_requests(user_id,plan_id,amount,reference,status,payment_method) VALUES(?,?,?,?,'pending','paystack')")
        ->execute([$u['id'], $plan['id'], $plan['price'], $reference]);

    $callback = env('PAYSTACK_CALLBACK_URL', frontend_url() . '/');
    try {
        $res = paystack_request('POST', '/transaction/initialize', [
            'email'        => $u['email'],
            'amount'       => kobo($plan['price']),
            'currency'     => strtoupper(env('PAYSTACK_CURRENCY', 'NGN')),
            'reference'    => $reference,
            'callback_url' => $callback,
            'metadata'     => ['user_id' => $u['id'], 'plan_id' => (int)$plan['id'], 'app' => 'studious'],
        ]);
        if (empty($res['data']['authorization_url'])) {
            fail(502, 'Paystack did not return a checkout URL.');
        }
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE payment_requests SET status='failed', gateway_response=? WHERE reference=?")
            ->execute([substr($e->getMessage(), 0, 255), $reference]);
        throw $e;
    }
    ok(['reference' => $reference, 'authorization_url' => $res['data']['authorization_url']]);
}

function h_payment_verify(array $p = []): never
{
    $u = auth_user();
    $reference = trim((string)($_GET['reference'] ?? ''));
    if ($reference === '') {
        fail(422, 'reference is required.');
    }
    $q = db()->prepare('SELECT user_id FROM payment_requests WHERE reference=?');
    $q->execute([$reference]);
    $r = $q->fetch();
    if (!$r || ((int)$r['user_id'] !== (int)$u['id'] && $u['role'] !== 'admin')) {
        fail(404, 'Payment reference not found.');
    }
    ok(['payment' => settle_payment($reference)]);
}

function h_payments_mine(array $p = []): never
{
    $q = db()->prepare('SELECT r.*, p.name AS plan_name FROM payment_requests r JOIN plans p ON p.id=r.plan_id WHERE r.user_id=? ORDER BY r.created_at DESC LIMIT 100');
    $q->execute([auth_user()['id']]);
    ok(['data' => array_map('payment_view', $q->fetchAll())]);
}

/** Paystack webhook: configure https://YOUR-API/payments/webhook in the Paystack dashboard. */
function h_payment_webhook(array $p = []): never
{
    $raw = file_get_contents('php://input') ?: '';
    $secret = env('PAYSTACK_SECRET_KEY');
    $sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
    if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha512', $raw, $secret), $sig)) {
        fail(401, 'Invalid webhook signature.');
    }
    $event = json_decode($raw, true);
    if (is_array($event) && ($event['event'] ?? '') === 'charge.success') {
        $reference = (string)($event['data']['reference'] ?? '');
        if ($reference !== '') {
            $q = db()->prepare('SELECT 1 FROM payment_requests WHERE reference=?');
            $q->execute([$reference]);
            if ($q->fetchColumn()) {
                try {
                    settle_payment($reference);
                } catch (Throwable $e) {
                    error_log('Studious webhook: ' . $e->getMessage());
                    fail(500, 'Could not apply payment; Paystack will retry.');
                }
            }
        }
    }
    ok(['received' => true]);
}

/* =========================================================================
 * 9. ROUTE HANDLERS — ADMIN
 * ========================================================================= */

function toggle_row(string $table, int $id): void
{
    $allowed = ['questions', 'materials', 'plans', 'schools', 'departments', 'users'];
    if (!in_array($table, $allowed, true)) {
        fail(500, 'Invalid table.');
    }
    $col = $table === 'questions' || $table === 'materials' ? 'is_published' : 'is_active';
    $st = db()->prepare("UPDATE {$table} SET {$col}=1-{$col} WHERE id=?");
    $st->execute([$id]);
    $q = db()->prepare("SELECT {$col} FROM {$table} WHERE id=?");
    $q->execute([$id]);
    $v = $q->fetchColumn();
    if ($v === false) {
        fail(404, 'Record not found.');
    }
}

function assert_dept_in_school(int $school, int $dept): void
{
    $q = db()->prepare('SELECT 1 FROM departments WHERE id=? AND school_id=?');
    $q->execute([$dept, $school]);
    if (!$q->fetchColumn()) {
        fail(422, 'The department does not belong to the selected institution.');
    }
}

function h_admin_overview(array $p = []): never
{
    $pdo = db();
    $count = fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
    ok([
        'students'  => $count("SELECT COUNT(*) FROM users WHERE role='student'"),
        'questions' => $count('SELECT COUNT(*) FROM questions'),
        'materials' => $count('SELECT COUNT(*) FROM materials'),
        'payments'  => $count('SELECT COUNT(*) FROM payment_requests'),
        'pending'   => $count("SELECT COUNT(*) FROM payment_requests WHERE status='pending'"),
        'active_subscriptions' => $count("SELECT COUNT(*) FROM subscriptions WHERE status='active' AND starts_at<=NOW() AND ends_at>NOW()"),
        'revenue'   => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payment_requests WHERE status='success'")->fetchColumn(),
    ]);
}

/* ---- questions ---- */

function h_admin_questions_list(array $p = []): never
{
    $rows = db()->query(
        'SELECT q.id,q.course_code,q.title,q.level,q.curriculum_version,q.exam_year,q.is_published,s.name AS school,d.name AS department
         FROM questions q JOIN schools s ON s.id=q.school_id JOIN departments d ON d.id=q.department_id
         ORDER BY q.created_at DESC, q.id DESC LIMIT 200'
    )->fetchAll();
    foreach ($rows as &$r) { $r['is_published'] = (bool)$r['is_published']; }
    ok(['data' => $rows]);
}

function h_admin_questions_create(array $p = []): never
{
    $school = (int)inp('school_id');
    $dept = (int)inp('department_id');
    $required = ['level', 'curriculum_version', 'course_code', 'title', 'question_text'];
    foreach ($required as $f) {
        if (inp($f) === '') {
            fail(422, 'Missing required field: ' . $f . '.');
        }
    }
    $year = (int)inp('exam_year');
    if (!$school || !$dept || $year < 1950 || $year > 2100) {
        fail(422, 'Institution, department and a valid exam year are required.');
    }
    assert_dept_in_school($school, $dept);
    $opts = inp('options_json');
    if ($opts !== '' && !is_array(json_decode($opts, true))) {
        fail(422, 'Options must be valid JSON, e.g. {"A":"Option one","B":"Option two"}.');
    }
    $img = store_upload($_FILES['image'] ?? [], ['png', 'jpg', 'jpeg', 'webp', 'gif']);
    db()->prepare(
        'INSERT INTO questions(school_id,department_id,level,curriculum_version,course_code,title,exam_year,question_text,options_json,correct_answer,explanation,image_file,is_published)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $school, $dept, inp('level'), inp('curriculum_version'), inp('course_code'), inp('title'), $year,
        inp('question_text'), $opts !== '' ? $opts : null, inp('correct_answer'), inp('explanation'),
        $img['key'] ?? null, truthy(inp('is_published')) ? 1 : 0,
    ]);
    ok(['message' => 'Question saved.', 'id' => (int)db()->lastInsertId()], 201);
}

function h_admin_questions_import(array $p = []): never
{
    $f = $_FILES['csv'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail(422, 'Choose a CSV file.');
    }
    $fh = fopen($f['tmp_name'], 'r');
    $head = fgetcsv($fh, 0, ',', '"', '\\');
    if (!$head) {
        fail(422, 'The CSV file is empty.');
    }
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);
    $idx = array_flip(array_map(fn($h) => strtolower(trim((string)$h)), $head));
    foreach (['level', 'curriculum_version', 'course_code', 'title', 'exam_year', 'question_text'] as $c) {
        if (!isset($idx[$c])) {
            fail(422, "CSV is missing the required column: {$c}. Use the sample template.");
        }
    }
    if (!(isset($idx['school_id']) || isset($idx['school'])) || !(isset($idx['department_id']) || isset($idx['department']))) {
        fail(422, 'CSV needs school/school_id and department/department_id columns.');
    }

    $pdo = db();
    $schoolsById = $schoolsByName = [];
    foreach ($pdo->query('SELECT id,name FROM schools')->fetchAll() as $s) {
        $schoolsById[(int)$s['id']] = true;
        $schoolsByName[strtolower($s['name'])] = (int)$s['id'];
    }
    $deptById = $deptByName = [];
    foreach ($pdo->query('SELECT id,school_id,name FROM departments')->fetchAll() as $d) {
        $deptById[(int)$d['id']] = (int)$d['school_id'];
        $deptByName[$d['school_id'] . '|' . strtolower($d['name'])] = (int)$d['id'];
    }
    $get = fn(array $row, string $c): string => isset($idx[$c]) ? trim((string)($row[$idx[$c]] ?? '')) : '';
    $publish = truthy(inp('publish_import'));
    $ins = $pdo->prepare(
        'INSERT INTO questions(school_id,department_id,level,curriculum_version,course_code,title,exam_year,question_text,options_json,correct_answer,explanation,is_published)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $n = 0;
    $skipped = [];
    $line = 1;
    $pdo->beginTransaction();
    try {
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($n >= 2000) { $skipped[] = "Row limit (2000) reached at line {$line}."; break; }
            if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) { continue; }
            $sid = $get($row, 'school_id') !== '' ? (int)$get($row, 'school_id') : ($schoolsByName[strtolower($get($row, 'school'))] ?? 0);
            $did = $get($row, 'department_id') !== '' ? (int)$get($row, 'department_id') : ($deptByName[$sid . '|' . strtolower($get($row, 'department'))] ?? 0);
            $year = (int)$get($row, 'exam_year');
            $opts = $get($row, 'options_json');
            $reason = null;
            if (!$sid || !isset($schoolsById[$sid])) { $reason = 'unknown institution'; }
            elseif (!$did || ($deptById[$did] ?? 0) !== $sid) { $reason = 'unknown department for that institution'; }
            elseif ($get($row, 'course_code') === '' || $get($row, 'question_text') === '' || $get($row, 'level') === '' || $get($row, 'curriculum_version') === '') { $reason = 'missing required value'; }
            elseif ($year < 1950 || $year > 2100) { $reason = 'invalid exam year'; }
            elseif ($opts !== '' && !is_array(json_decode($opts, true))) { $reason = 'options_json is not valid JSON'; }
            if ($reason !== null) {
                if (count($skipped) < 20) { $skipped[] = "Line {$line}: {$reason}."; }
                continue;
            }
            $ins->execute([
                $sid, $did, $get($row, 'level'), $get($row, 'curriculum_version'), $get($row, 'course_code'),
                $get($row, 'title') !== '' ? $get($row, 'title') : $get($row, 'course_code'), $year, $get($row, 'question_text'),
                $opts !== '' ? $opts : null, $get($row, 'correct_answer'), $get($row, 'explanation'), $publish ? 1 : 0,
            ]);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    } finally {
        fclose($fh);
    }
    ok(['message' => "Imported {$n} question(s).", 'imported' => $n, 'skipped' => $skipped]);
}

function h_admin_questions_toggle(array $p): never
{
    toggle_row('questions', (int)$p['id']);
    ok(['message' => 'Publication status updated.']);
}

/* ---- materials ---- */

function h_admin_materials_list(array $p = []): never
{
    $rows = db()->query(
        'SELECT m.id,m.title,m.course_code,m.level,m.file_type,m.file_size,m.is_published,s.name AS school,d.name AS department
         FROM materials m JOIN schools s ON s.id=m.school_id JOIN departments d ON d.id=m.department_id
         ORDER BY m.created_at DESC, m.id DESC LIMIT 200'
    )->fetchAll();
    foreach ($rows as &$r) { $r['is_published'] = (bool)$r['is_published']; }
    ok(['data' => $rows]);
}

function h_admin_materials_create(array $p = []): never
{
    $school = (int)inp('school_id');
    $dept = (int)inp('department_id');
    foreach (['level', 'curriculum_version', 'course_code', 'title'] as $f) {
        if (inp($f) === '') {
            fail(422, 'Missing required field: ' . $f . '.');
        }
    }
    if (!$school || !$dept) {
        fail(422, 'Institution and department are required.');
    }
    assert_dept_in_school($school, $dept);
    $file = store_upload($_FILES['material'] ?? [], ['pdf', 'doc', 'docx', 'txt', 'png', 'jpg', 'jpeg', 'webp']);
    if (!$file) {
        fail(422, 'Choose a file to upload.');
    }
    db()->prepare(
        'INSERT INTO materials(school_id,department_id,level,curriculum_version,course_code,title,description,file_name,file_type,file_size,is_published)
         VALUES(?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $school, $dept, inp('level'), inp('curriculum_version'), inp('course_code'), inp('title'), inp('description'),
        $file['key'], strtoupper($file['ext']), $file['size'], truthy(inp('is_published')) ? 1 : 0,
    ]);
    ok(['message' => 'Material uploaded.', 'id' => (int)db()->lastInsertId()], 201);
}

function h_admin_materials_toggle(array $p): never
{
    toggle_row('materials', (int)$p['id']);
    ok(['message' => 'Publication status updated.']);
}

/* ---- payments ---- */

function h_admin_payments_list(array $p = []): never
{
    $rows = db()->query(
        'SELECT r.*, u.name AS user_name, u.email, p.name AS plan_name
         FROM payment_requests r JOIN users u ON u.id=r.user_id JOIN plans p ON p.id=r.plan_id
         ORDER BY r.created_at DESC, r.id DESC LIMIT 200'
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = payment_view($r) + ['user_name' => $r['user_name'], 'email' => $r['email'], 'gateway_response' => $r['gateway_response']];
    }
    ok(['data' => $out]);
}

function h_admin_payments_verify(array $p): never
{
    $q = db()->prepare('SELECT reference FROM payment_requests WHERE id=?');
    $q->execute([(int)$p['id']]);
    $ref = $q->fetchColumn();
    if (!$ref) {
        fail(404, 'Payment not found.');
    }
    ok(['payment' => settle_payment((string)$ref)]);
}

/* ---- plans ---- */

function h_admin_plans_list(array $p = []): never
{
    $rows = db()->query('SELECT id,name,description,price,duration_days,is_active FROM plans ORDER BY price')->fetchAll();
    foreach ($rows as &$r) {
        $r['price'] = (float)$r['price'];
        $r['duration_days'] = (int)$r['duration_days'];
        $r['is_active'] = (bool)$r['is_active'];
    }
    ok(['data' => $rows]);
}

function h_admin_plans_create(array $p = []): never
{
    $price = (float)inp('price');
    $days = (int)inp('duration_days');
    if (inp('name') === '' || $price <= 0 || $days < 1) {
        fail(422, 'A name, a price above zero and a duration of at least 1 day are required.');
    }
    db()->prepare('INSERT INTO plans(name,description,price,duration_days,is_active) VALUES(?,?,?,?,?)')
        ->execute([inp('name'), inp('description'), $price, $days, truthy(inp('is_active')) ? 1 : 0]);
    ok(['message' => 'Plan created.'], 201);
}

function h_admin_plans_toggle(array $p): never
{
    toggle_row('plans', (int)$p['id']);
    ok(['message' => 'Plan status updated.']);
}

/* ---- institutions & departments ---- */

function h_admin_taxonomy(array $p = []): never
{
    $pdo = db();
    $schools = $pdo->query('SELECT id,name,short_name,is_active FROM schools ORDER BY name')->fetchAll();
    $depts = $pdo->query('SELECT d.id,d.school_id,d.name,d.is_active,s.name AS school FROM departments d JOIN schools s ON s.id=d.school_id ORDER BY s.name,d.name')->fetchAll();
    foreach ($schools as &$s) { $s['id'] = (int)$s['id']; $s['is_active'] = (bool)$s['is_active']; }
    foreach ($depts as &$d) { $d['id'] = (int)$d['id']; $d['school_id'] = (int)$d['school_id']; $d['is_active'] = (bool)$d['is_active']; }
    ok(['schools' => $schools, 'departments' => $depts]);
}

function h_admin_school_create(array $p = []): never
{
    if (inp('name') === '') {
        fail(422, 'Institution name is required.');
    }
    try {
        db()->prepare('INSERT INTO schools(name,short_name) VALUES(?,?)')->execute([inp('name'), inp('short_name')]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { fail(409, 'That institution already exists.'); }
        throw $e;
    }
    ok(['message' => 'Institution added.'], 201);
}

function h_admin_department_create(array $p = []): never
{
    if (inp('name') === '' || !(int)inp('school_id')) {
        fail(422, 'Institution and department name are required.');
    }
    try {
        db()->prepare('INSERT INTO departments(school_id,name) VALUES(?,?)')->execute([(int)inp('school_id'), inp('name')]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { fail(409, 'That department already exists for the institution (or the institution is invalid).'); }
        throw $e;
    }
    ok(['message' => 'Department added.'], 201);
}

function h_admin_school_toggle(array $p): never
{
    toggle_row('schools', (int)$p['id']);
    ok(['message' => 'Institution status updated.']);
}

function h_admin_department_toggle(array $p): never
{
    toggle_row('departments', (int)$p['id']);
    ok(['message' => 'Department status updated.']);
}

/* ---- users ---- */

function h_admin_users_list(array $p = []): never
{
    $rows = db()->query(
        'SELECT u.id,u.name,u.email,u.role,u.level,u.is_active,u.created_at,s.name AS school,d.name AS department
         FROM users u LEFT JOIN schools s ON s.id=u.school_id LEFT JOIN departments d ON d.id=u.department_id
         ORDER BY u.created_at DESC, u.id DESC LIMIT 300'
    )->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['is_active'] = (bool)$r['is_active']; }
    ok(['data' => $rows]);
}

function h_admin_users_toggle(array $p): never
{
    if ((int)$p['id'] === (int)auth_user()['id']) {
        fail(422, 'You cannot disable your own account.');
    }
    toggle_row('users', (int)$p['id']);
    ok(['message' => 'User status updated.']);
}

/* =========================================================================
 * 10. ROUTER
 * ========================================================================= */

// [method, path pattern, handler, access: public|user|admin]
$ROUTES = [
    ['GET',  '/',                              'h_index',                     'public'],
    ['GET',  '/health',                        'h_health',                    'public'],
    ['GET',  '/meta',                          'h_meta',                      'public'],
    ['POST', '/auth/register',                 'h_register',                  'public'],
    ['POST', '/auth/login',                    'h_login',                     'public'],
    ['POST', '/auth/logout',                   'h_logout',                    'public'],
    ['GET',  '/auth/me',                       'h_me',                        'user'],
    ['GET',  '/schools',                       'h_schools',                   'public'],
    ['GET',  '/departments',                   'h_departments',               'public'],
    ['GET',  '/plans',                         'h_plans',                     'public'],
    ['GET',  '/catalog',                       'h_catalog',                   'public'],
    ['GET',  '/download',                      'h_download',                  'public'],
    ['GET',  '/dashboard',                     'h_dashboard',                 'user'],
    ['GET',  '/questions',                     'h_questions',                 'user'],
    ['GET',  '/materials',                     'h_materials',                 'user'],
    ['GET',  '/payments',                      'h_payments_mine',             'user'],
    ['POST', '/payments/initialize',           'h_payment_initialize',        'user'],
    ['GET',  '/payments/verify',               'h_payment_verify',            'user'],
    ['POST', '/payments/webhook',              'h_payment_webhook',           'public'],
    ['GET',  '/admin/overview',                'h_admin_overview',            'admin'],
    ['GET',  '/admin/questions',               'h_admin_questions_list',      'admin'],
    ['POST', '/admin/questions',               'h_admin_questions_create',    'admin'],
    ['POST', '/admin/questions/import',        'h_admin_questions_import',    'admin'],
    ['POST', '/admin/questions/{id}/toggle',   'h_admin_questions_toggle',    'admin'],
    ['GET',  '/admin/materials',               'h_admin_materials_list',      'admin'],
    ['POST', '/admin/materials',               'h_admin_materials_create',    'admin'],
    ['POST', '/admin/materials/{id}/toggle',   'h_admin_materials_toggle',    'admin'],
    ['GET',  '/admin/payments',                'h_admin_payments_list',       'admin'],
    ['POST', '/admin/payments/{id}/verify',    'h_admin_payments_verify',     'admin'],
    ['GET',  '/admin/plans',                   'h_admin_plans_list',          'admin'],
    ['POST', '/admin/plans',                   'h_admin_plans_create',        'admin'],
    ['POST', '/admin/plans/{id}/toggle',       'h_admin_plans_toggle',        'admin'],
    ['GET',  '/admin/taxonomy',                'h_admin_taxonomy',            'admin'],
    ['POST', '/admin/schools',                 'h_admin_school_create',       'admin'],
    ['POST', '/admin/schools/{id}/toggle',     'h_admin_school_toggle',       'admin'],
    ['POST', '/admin/departments',             'h_admin_department_create',   'admin'],
    ['POST', '/admin/departments/{id}/toggle', 'h_admin_department_toggle',   'admin'],
    ['GET',  '/admin/users',                   'h_admin_users_list',          'admin'],
    ['POST', '/admin/users/{id}/toggle',       'h_admin_users_toggle',        'admin'],
];

function dispatch(array $routes): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'HEAD') {
        $method = 'GET';
    }
    $path = request_route();
    $allowed = [];
    foreach ($routes as [$m, $pattern, $handler, $access]) {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (!preg_match($re, $path, $match)) {
            continue;
        }
        if ($m !== $method) {
            $allowed[] = $m;
            continue;
        }
        if ($access !== 'public') {
            $user = auth_user();
            if (!$user) {
                fail(401, 'Authentication required. Please sign in.');
            }
            if ($access === 'admin' && $user['role'] !== 'admin') {
                fail(403, 'Administrator access required.');
            }
        }
        $params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
        $handler($params);
        return;
    }
    if ($allowed) {
        header('Allow: ' . implode(', ', array_unique($allowed)));
        fail(405, 'Method not allowed.');
    }
    fail(404, 'Unknown endpoint.');
}

send_security_headers();
send_cors();

try {
    dispatch($ROUTES);
} catch (ApiError $e) {
    respond($e->status, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Studious API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(500, ['ok' => false, 'error' => env_bool('APP_DEBUG') ? $e->getMessage() : 'Server error. Check the server logs and configuration.']);
}
