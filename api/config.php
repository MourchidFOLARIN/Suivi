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
header('Vary: Cookie, Origin');
// Les réponses de l'API peuvent contenir des données personnelles : elles ne
// doivent être ni mises en cache par le navigateur ni par un proxy partagé.
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

set_exception_handler(function (Throwable $e): void {
    error_log('Unhandled application error: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }
    echo json_encode(['success' => false, 'message' => 'Une erreur interne est survenue. Réessayez plus tard.'], JSON_UNESCAPED_UNICODE);
});

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

    // Autoriser les origines de développement uniquement hors production.
    if (getenv('APP_ENV') === 'production') {
        return false;
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
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

        } else {
            // ── Mode Local (MySQL / XAMPP) ─────────────────────────
            $dsn = 'mysql:host=' . LOCAL_DB_HOST . ';dbname=' . LOCAL_DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, LOCAL_DB_USER, LOCAL_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            // En local, l'application reste immédiatement utilisable après
            // une mise à jour : la migration est exécutée une fois par session.
            // En production, elle reste strictement exécutée au démarrage du
            // conteneur par scripts/migrate.php.
            if (getenv('APP_ENV') !== 'production' && empty($_SESSION['local_db_migrated'])) {
                runDatabaseMigrations($pdo, 'mysql');
                $_SESSION['local_db_migrated'] = true;
            }

        }

        return $pdo;

    } catch (PDOException $e) {
        error_log('Database connection error: ' . $e->getMessage());
        throw new RuntimeException('Database connection unavailable.', 0, $e);
    }
}

function runDatabaseMigrations(PDO $pdo, string $driver): void
{
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
                    user_id         INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
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
                    date_maj        TIMESTAMP NOT NULL DEFAULT NOW(),
                    CONSTRAINT prospects_statut_check CHECK (statut IN ('nouveau', 'invite', 'presente', 'interesse', 'inscrit', 'perdu'))
                );
            ");

            // Garantir que toutes les colonnes existent (PostgreSQL)
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS nom VARCHAR(100);");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(150);");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS mot_de_passe VARCHAR(255);");
            $pdo->exec("ALTER TABLE prospects ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL;");
            $pdo->exec("CREATE INDEX IF NOT EXISTS prospects_user_date_ajout_idx ON prospects (user_id, date_ajout);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS prospects_user_statut_idx ON prospects (user_id, statut);");
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (attempt_key VARCHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, blocked_until TIMESTAMP NULL, updated_at TIMESTAMP NOT NULL DEFAULT NOW());");

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
                    user_id INT NOT NULL,
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
                    date_maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY prospects_user_date_ajout (user_id, date_ajout),
                    KEY prospects_user_statut (user_id, statut),
                    CONSTRAINT prospects_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_attempts (attempt_key VARCHAR(64) PRIMARY KEY, attempts INT NOT NULL DEFAULT 0, blocked_until DATETIME NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        }
}

function getUserColumns(PDO $pdo): array
{
    $columns = $pdo->query("
        SELECT column_name
        FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'users'
        ORDER BY ordinal_position
    ")->fetchAll(PDO::FETCH_COLUMN);

    return array_map('strtolower', $columns ?: []);
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

    $columnNames = getUserColumns($pdo);

    if (!in_array('id', $columnNames, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN id SERIAL;");
    }

    $sequenceName = 'users_id_seq';
    $sequenceExists = $pdo->query("SELECT 1 FROM pg_class WHERE relname = '{$sequenceName}'")->fetchColumn();
    if (!$sequenceExists) {
        $pdo->exec("CREATE SEQUENCE IF NOT EXISTS {$sequenceName}");
    }

    $pdo->exec("UPDATE users SET id = nextval('{$sequenceName}') WHERE id IS NULL");
    // Une séquence créée pour une table legacy démarre à 1. La synchroniser
    // évite une collision de clé primaire au prochain compte créé.
    $pdo->exec("SELECT setval('{$sequenceName}', COALESCE((SELECT MAX(id) FROM users), 1), true)");
    $pdo->exec("ALTER TABLE users ALTER COLUMN id SET DEFAULT nextval('{$sequenceName}')");
    $pdo->exec("ALTER TABLE users ALTER COLUMN id SET NOT NULL");

    $pkExists = $pdo->query("SELECT COUNT(*) FROM pg_constraint WHERE conrelid = 'users'::regclass AND contype = 'p'")->fetchColumn();
    if ((int) $pkExists === 0) {
        $pdo->exec("ALTER TABLE users ADD PRIMARY KEY (id)");
    }

    if (in_array('name', $columnNames, true) && !in_array('nom', $columnNames, true)) {
        $pdo->exec("ALTER TABLE users RENAME COLUMN name TO nom");
        $columnNames = getUserColumns($pdo);
    }

    if (in_array('nom', $columnNames, true) && in_array('name', $columnNames, true)) {
        $pdo->exec("UPDATE users SET nom = COALESCE(nom, name) WHERE nom IS NULL AND name IS NOT NULL");
    }

    foreach (['nom', 'email', 'mot_de_passe'] as $column) {
        if (!in_array($column, $columnNames, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN {$column} VARCHAR(255)");
        }
    }

    $pdo->exec("UPDATE users SET nom = COALESCE(nom, 'Utilisateur') WHERE nom IS NULL OR nom = ''");
    $pdo->exec("ALTER TABLE users ALTER COLUMN nom SET NOT NULL");
    $pdo->exec("ALTER TABLE users ALTER COLUMN email SET NOT NULL");
    $pdo->exec("ALTER TABLE users ALTER COLUMN mot_de_passe SET NOT NULL");

    if (in_array('name', $columnNames, true)) {
        $pdo->exec("ALTER TABLE users DROP COLUMN IF EXISTS name");
    }

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
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    try {
        $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(['success' => false, 'message' => 'Le corps de la requête doit être un JSON valide.'], 400);
    }

    if (!is_array($data) || array_is_list($data)) {
        respond(['success' => false, 'message' => 'Le corps de la requête doit être un objet JSON.'], 400);
    }

    return $data;
}

function respond($data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
