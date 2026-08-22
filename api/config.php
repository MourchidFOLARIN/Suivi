<?php
/**
 * Configuration de la connexion à la base de données.
 *
 * ── En local (XAMPP/WAMP) ──────────────────────────────
 * Le fichier utilise MySQL si DATABASE_URL n'est pas définie.
 * Crée un fichier .env.local (ignoré par Git) avec tes paramètres
 * ou modifie les constantes LOCAL_* ci-dessous.
 *
 * ── En production (Render) ────────────────────────────
 * Render injecte automatiquement DATABASE_URL dans l'environnement.
 * Aucune configuration supplémentaire n'est nécessaire.
 */

// ── Constantes locales (modifie-les pour ton XAMPP) ────
define('LOCAL_DB_HOST', 'localhost');
define('LOCAL_DB_NAME', 'suivi_prospects');
define('LOCAL_DB_USER', 'root');
define('LOCAL_DB_PASS', '');

// ── En-têtes HTTP ───────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function getPDO(): PDO
{
    try {
        $databaseUrl = getenv('DATABASE_URL');

        if ($databaseUrl) {
            // ── Mode Production (Render PostgreSQL) ────────────────
            // DATABASE_URL format: postgres://user:pass@host:port/dbname
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
            $dsn = 'mysql:host=' . LOCAL_DB_HOST
                 . ';dbname=' . LOCAL_DB_NAME
                 . ';charset=utf8mb4';
            $pdo = new PDO($dsn, LOCAL_DB_USER, LOCAL_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $pdo;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Connexion à la base de données impossible : ' . $e->getMessage(),
        ]);
        exit;
    }
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
