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
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
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

            // Garantir que toutes les colonnes existent (MySQL)
            try { $pdo->exec("ALTER TABLE users ADD COLUMN nom VARCHAR(100) NOT NULL;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) UNIQUE NOT NULL;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE users ADD COLUMN mot_de_passe VARCHAR(255) NOT NULL;"); } catch (Exception $e) {}
            try { $pdo->exec("ALTER TABLE prospects ADD COLUMN user_id INT DEFAULT NULL;"); } catch (Exception $e) {}
        }
    } catch (Exception $e) {
        error_log('Init DB Error: ' . $e->getMessage());
    }
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
