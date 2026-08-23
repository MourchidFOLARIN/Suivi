<?php
/**
 * Configuration de la connexion à la base de données.
 *
 * ── En local (XAMPP/WAMP) ──────────────────────────────
 * Le fichier utilise MySQL si DATABASE_URL n'est pas définie.
 *
 * ── En production (Render) ────────────────────────────
 * Render injecte automatiquement DATABASE_URL dans l'environnement.
 * Auto-création des tables PostgreSQL/MySQL si elles n'existent pas.
 */

if (session_status() === PHP_SESSION_NONE) {
    // Configuration sécurisée des cookies de session (avec support reverse-proxy HTTPS)
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
              (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_start([
        'cookie_lifetime' => 0,
        'cookie_path'     => '/',
        'cookie_secure'   => $secure,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

define('LOCAL_DB_HOST', 'localhost');
define('LOCAL_DB_NAME', 'suivi_prospects');
define('LOCAL_DB_USER', 'root');
define('LOCAL_DB_PASS', '');

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

function isAllowedOrigin(string $origin): bool
{
    $origin = trim($origin);
    if ($origin === '') {
        return false;
    }

    $host = parse_url($origin, PHP_URL_HOST);
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== false && $host === $httpHost) {
        return true;
    }

    $allowedOrigins = array_filter(array_map('trim', explode(',', getenv('APP_ALLOWED_ORIGINS') ?: '')));
    foreach ($allowedOrigins as $allowedOrigin) {
        if ($allowedOrigin === $origin) {
            return true;
        }
    }

    $defaultAllowed = [
        'http://localhost',
        'http://localhost:80',
        'http://localhost:8080',
        'http://127.0.0.1',
        'http://127.0.0.1:80',
        'http://127.0.0.1:8080',
    ];

    return in_array($origin, $defaultAllowed, true);
}

function setCorsHeaders(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && isAllowedOrigin($origin)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-CSRFToken');
}

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function ensureCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        return;
    }

    $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_X_CSRFTOKEN'] ?? null;
    if ($received === null && isset($_POST['csrf_token'])) {
        $received = $_POST['csrf_token'];
    }

    if ($received === null || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $received)) {
        respond(['success' => false, 'message' => 'Token CSRF invalide. Rechargez la page et réessayez.'], 419);
    }
}

function getPDO(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    try {
        $databaseUrl = getenv('DATABASE_URL');

        if ($databaseUrl) {
            // ── Mode Production (Render PostgreSQL) ────────────────
            $parsed = parse_url($databaseUrl);
            $host   = $parsed['host'];
            $port   = $parsed['port'] ?? 5432;
            $dbname = ltrim($parsed['path'], '/');
            $user   = $parsed['user'];
            $pass   = $parsed['pass'];

            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            if (empty($_SESSION['db_initialized'])) {
                initDatabaseIfNeeded($pdo, 'pgsql');
                $_SESSION['db_initialized'] = true;
            }
        } else {
            // ── Mode Local (MySQL / XAMPP) ─────────────────────────
            $dsn = 'mysql:host=' . LOCAL_DB_HOST . ';dbname=' . LOCAL_DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, LOCAL_DB_USER, LOCAL_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            if (empty($_SESSION['db_initialized'])) {
                initDatabaseIfNeeded($pdo, 'mysql');
                $_SESSION['db_initialized'] = true;
            }
        }

        return $pdo;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Connexion à la base de données impossible : ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function initDatabaseIfNeeded(PDO $pdo, string $driver): void
{
    try {
        if ($driver === 'pgsql') {
            // 1. Table users (PostgreSQL)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id              SERIAL PRIMARY KEY,
                    nom             VARCHAR(100) NOT NULL,
                    email           VARCHAR(150) UNIQUE NOT NULL,
                    mot_de_passe    VARCHAR(255) NOT NULL,
                    date_creation   TIMESTAMP NOT NULL DEFAULT NOW()
                );
            ");

            repairPostgresUserTable($pdo);

            // 2. Table prospects (PostgreSQL)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS prospects (
                    id              SERIAL PRIMARY KEY,
                    user_id         INT DEFAULT NULL,
                    nom             VARCHAR(100) NOT NULL,
                    prenom          VARCHAR(100) NOT NULL,
                    telephone       VARCHAR(30)  NOT NULL,
                    email           VARCHAR(150) DEFAULT NULL,
                    source          VARCHAR(100) DEFAULT NULL,
                    statut          VARCHAR(20)  NOT NULL DEFAULT 'nouveau',
                    invitation_faite    SMALLINT NOT NULL DEFAULT 0,
                    date_invitation     DATE     DEFAULT NULL,
                    presentation_faite  SMALLINT NOT NULL DEFAULT 0,
                    date_presentation   DATE     DEFAULT NULL,
                    date_inscription    DATE     DEFAULT NULL,
                    prochaine_relance   DATE     DEFAULT NULL,
                    notes           TEXT     DEFAULT NULL,
                    date_ajout      TIMESTAMP NOT NULL DEFAULT NOW(),
                    date_maj        TIMESTAMP NOT NULL DEFAULT NOW()
                );
            ");

            // Garantir que toutes les colonnes existent (PostgreSQL)
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS nom VARCHAR(100);");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(150);");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS mot_de_passe VARCHAR(255);");
            $pdo->exec("ALTER TABLE prospects ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL;");

        } else {
            // 1. Table users (MySQL)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nom VARCHAR(100) NOT NULL,
                    email VARCHAR(150) UNIQUE NOT NULL,
                    mot_de_passe VARCHAR(255) NOT NULL,
                    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 2. Table prospects (MySQL)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS prospects (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT DEFAULT NULL,
                    nom VARCHAR(100) NOT NULL,
                    prenom VARCHAR(100) NOT NULL,
                    telephone VARCHAR(30) NOT NULL,
                    email VARCHAR(150) DEFAULT NULL,
                    source VARCHAR(100) DEFAULT NULL,
                    statut ENUM('nouveau', 'invite', 'presente', 'interesse', 'inscrit', 'perdu') NOT NULL DEFAULT 'nouveau',
                    invitation_faite TINYINT(1) NOT NULL DEFAULT 0,
                    date_invitation DATE DEFAULT NULL,
                    presentation_faite TINYINT(1) NOT NULL DEFAULT 0,
                    date_presentation DATE DEFAULT NULL,
                    date_inscription DATE DEFAULT NULL,
                    prochaine_relance DATE DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    date_ajout DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    date_maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // Garantir que les colonnes existent sans casser les tables déjà créées
            $requiredColumns = [
                'users' => [
                    ['nom', 'VARCHAR(100) NULL'],
                    ['email', 'VARCHAR(150) NULL'],
                    ['mot_de_passe', 'VARCHAR(255) NULL'],
                ],
                'prospects' => [
                    ['user_id', 'INT NULL DEFAULT NULL'],
                ],
            ];

            foreach ($requiredColumns as $table => $columns) {
                foreach ($columns as [$column, $definition]) {
                    $checkStmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
                    );
                    $checkStmt->execute([$table, $column]);
                    if ((int) $checkStmt->fetchColumn() === 0) {
                        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('Init DB Error: ' . $e->getMessage());
    }
}

function repairPostgresUserTable(PDO $pdo): void
{
    $tableCheck = $pdo->query("SELECT to_regclass('public.users')")->fetchColumn();
    if (!$tableCheck) {
        $pdo->exec("
            CREATE TABLE users (
                id SERIAL PRIMARY KEY,
                nom VARCHAR(100) NOT NULL,
                email VARCHAR(150) UNIQUE NOT NULL,
                mot_de_passe VARCHAR(255) NOT NULL,
                date_creation TIMESTAMP NOT NULL DEFAULT NOW()
            );
        ");
        return;
    }

    $columns = $pdo->query("
        SELECT column_name, is_nullable, data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'users'
        ORDER BY ordinal_position
    ")->fetchAll();

    $columnNames = array_column($columns, 'column_name');

    if (!in_array('id', $columnNames, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN id SERIAL;");
    }

    $sequenceName = 'users_id_seq';
    $sequenceExists = $pdo->query("SELECT 1 FROM pg_class WHERE relname = '{$sequenceName}'")->fetchColumn();
    if (!$sequenceExists) {
        $pdo->exec("CREATE SEQUENCE IF NOT EXISTS {$sequenceName}");
    }

    $pdo->exec("UPDATE users SET id = nextval('{$sequenceName}') WHERE id IS NULL");
    $pdo->exec("ALTER TABLE users ALTER COLUMN id SET DEFAULT nextval('{$sequenceName}')");
    $pdo->exec("ALTER TABLE users ALTER COLUMN id SET NOT NULL");

    $pkExists = $pdo->query("SELECT COUNT(*) FROM pg_constraint WHERE conrelid = 'users'::regclass AND contype = 'p'")->fetchColumn();
    if ((int) $pkExists === 0) {
        $pdo->exec("ALTER TABLE users ADD PRIMARY KEY (id)");
    }

    foreach (['nom', 'email', 'mot_de_passe'] as $column) {
        if (!in_array($column, $columnNames, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$column} VARCHAR(255)");
        }
    }

    $pdo->exec("ALTER TABLE users ALTER COLUMN nom SET NOT NULL");
    $pdo->exec("ALTER TABLE users ALTER COLUMN email SET NOT NULL");
    $pdo->exec("ALTER TABLE users ALTER COLUMN mot_de_passe SET NOT NULL");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique_idx ON users (LOWER(email))");
}

function requireAuth(): array
{
    if (empty($_SESSION['user_id'])) {
        respond(['success' => false, 'message' => 'Non authentifié. Veuillez vous connecter.'], 401);
    }
    return [
        'id'    => (int)$_SESSION['user_id'],
        'nom'   => $_SESSION['user_nom'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
    ];
}

function jsonInput(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function respond($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
