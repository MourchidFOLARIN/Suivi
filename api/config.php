<?php
/**
 * Configuration de la connexion à la base de données.
 *
 * ── En local (XAMPP/WAMP) ──────────────────────────────
 * Le fichier utilise MySQL si DATABASE_URL n'est pas définie.
 *
 * ── En production (Render) ────────────────────────────
 * Render injecte automatiquement DATABASE_URL dans l'environnement.
 * Auto-création de la table PostgreSQL si elle n'existe pas.
 */

define('LOCAL_DB_HOST', 'localhost');
define('LOCAL_DB_NAME', 'suivi_prospects');
define('LOCAL_DB_USER', 'root');
define('LOCAL_DB_PASS', '');

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

            // Auto-initialisation automatique de la table si nécessaire
            initPostgresIfNeeded($pdo);
        } else {
            // ── Mode Local (MySQL / XAMPP) ─────────────────────────
            $dsn = 'mysql:host=' . LOCAL_DB_HOST . ';dbname=' . LOCAL_DB_NAME . ';charset=utf8mb4';
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
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function initPostgresIfNeeded(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS prospects (
                id              SERIAL PRIMARY KEY,
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

        $count = (int) $pdo->query("SELECT COUNT(*) FROM prospects")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("
                INSERT INTO prospects (nom, prenom, telephone, email, source, statut, invitation_faite, date_invitation, presentation_faite, date_presentation, notes)
                VALUES
                ('AGOSSOU', 'Chimène', '0197001122', 'chimene.a@example.com', 'Ami(e)', 'presente', 1, '2026-08-10', 1, '2026-08-15', 'Très motivée, attend de voir les résultats de son cousin.'),
                ('DOSSOU', 'Ferdinand', '0197002233', NULL, 'Marché', 'invite', 1, '2026-08-18', 0, NULL, 'A dit qu il rappellerait après le week-end.'),
                ('HOUNTONDJI', 'Sandra', '0197003344', 'sandra.h@example.com', 'Réseaux sociaux', 'nouveau', 0, NULL, 0, NULL, 'Contact pris via Facebook, pas encore relancée.');
            ");
        }
    } catch (Exception $e) {
        error_log('Init Postgres Error: ' . $e->getMessage());
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
