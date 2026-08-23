<?php
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function authenticateUser(array $user, string $nom): void
{
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_nom'] = $nom;
    $_SESSION['user_email'] = $user['email'];
}

function authText(array $data, string $key, int $maxLength): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value)) {
        respond(['success' => false, 'message' => "Le champ {$key} est invalide."], 422);
    }
    $value = trim($value);
    if ($value === '' || strlen($value) > $maxLength) {
        respond(['success' => false, 'message' => "Le champ {$key} est invalide."], 422);
    }
    return $value;
}

function loginAttemptKey(string $email): string
{
    return hash('sha256', strtolower($email) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function enforceLoginRateLimit(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare('SELECT blocked_until FROM auth_attempts WHERE attempt_key = ?');
    $stmt->execute([loginAttemptKey($email)]);
    $blockedUntil = $stmt->fetchColumn();
    if ($blockedUntil && strtotime((string) $blockedUntil) > time()) {
        respond(['success' => false, 'message' => 'Trop de tentatives. Réessayez dans 15 minutes.'], 429);
    }
}

function recordFailedLogin(PDO $pdo, string $email): void
{
    $key = loginAttemptKey($email);
    $stmt = $pdo->prepare('SELECT attempts FROM auth_attempts WHERE attempt_key = ?');
    $stmt->execute([$key]);
    $attempts = ((int) $stmt->fetchColumn()) + 1;
    $blockedUntil = $attempts >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null;

    if ($attempts === 1) {
        $stmt = $pdo->prepare('INSERT INTO auth_attempts (attempt_key, attempts, blocked_until) VALUES (?, ?, ?)');
        $stmt->execute([$key, $attempts, $blockedUntil]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE auth_attempts SET attempts = ?, blocked_until = ? WHERE attempt_key = ?');
    $stmt->execute([$attempts, $blockedUntil, $key]);
}

function clearFailedLogins(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare('DELETE FROM auth_attempts WHERE attempt_key = ?');
    $stmt->execute([loginAttemptKey($email)]);
}

if ($method === 'POST') {
    verifyCsrfToken();
    $data = jsonInput();

    if ($action === 'register') {
        $nom = authText($data, 'nom', 100);
        $email = authText($data, 'email', 150);
        $motDePasse = authText($data, 'mot_de_passe', 255);
        enforceLoginRateLimit($pdo, $email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'message' => 'L’email est invalide.'], 422);
        }
        if (strlen($motDePasse) < 8) {
            respond(['success' => false, 'message' => 'Le mot de passe doit contenir au moins 8 caractères.'], 422);
        }

        // Vérifier si l'email existe déjà
        $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            respond(['success' => false, 'message' => 'Cet email est déjà utilisé.'], 409);
        }

        // Créer l'utilisateur
        $hash = password_hash($motDePasse, PASSWORD_BCRYPT);
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $userColumns = getUserColumns($pdo);
        $nomColumn = in_array('nom', $userColumns, true) ? 'nom' : 'name';

        try {
            if ($driver === 'pgsql') {
                $stmt = $pdo->prepare("INSERT INTO users ({$nomColumn}, email, mot_de_passe) VALUES (?, ?, ?) RETURNING id");
                $stmt->execute([$nom, $email, $hash]);
                $row = $stmt->fetch();
                $userId = (int) $row['id'];
            } else {
                $stmt = $pdo->prepare("INSERT INTO users ({$nomColumn}, email, mot_de_passe) VALUES (?, ?, ?)");
                $stmt->execute([$nom, $email, $hash]);
                $userId = (int) $pdo->lastInsertId();
            }

            // Connecter l'utilisateur
            authenticateUser(['id' => $userId, 'email' => $email], $nom);

            respond([
                'success' => true,
                'message' => 'Inscription réussie.',
                'csrf_token' => ensureCsrfToken(),
                'user' => [
                    'id'    => $userId,
                    'nom'   => $nom,
                    'email' => $email
                ]
            ], 201);
        } catch (Exception $e) {
            error_log('Registration error: ' . $e->getMessage());
            respond(['success' => false, 'message' => "Erreur lors de l'inscription. Réessayez plus tard."], 500);
        }
    }

    if ($action === 'login') {
        $email = authText($data, 'email', 150);
        $motDePasse = authText($data, 'mot_de_passe', 255);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($motDePasse, $user['mot_de_passe'] ?? $user['password'] ?? '')) {
            $userNom = $user['nom'] ?? $user['name'] ?? 'Utilisateur';
            clearFailedLogins($pdo, $email);
            authenticateUser($user, $userNom);

            respond([
                'success' => true,
                'message' => 'Connexion réussie.',
                'csrf_token' => ensureCsrfToken(),
                'user' => [
                    'id' => $user['id'],
                    'nom' => $userNom,
                    'email' => $user['email']
                ]
            ]);
        } else {
            recordFailedLogin($pdo, $email);
            respond(['success' => false, 'message' => 'Identifiants incorrects.'], 401);
        }
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        respond(['success' => true, 'message' => 'Déconnecté.']);
    }
}

if ($method === 'GET') {
    if ($action === 'me') {
        if (!empty($_SESSION['user_id'])) {
            respond([
                'success' => true,
                'csrf_token' => ensureCsrfToken(),
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'nom' => $_SESSION['user_nom'],
                    'email' => $_SESSION['user_email']
                ]
            ]);
        } else {
            respond([
                'success' => false,
                'csrf_token' => ensureCsrfToken(),
                'user' => null
            ]);
        }
    }
}

respond(['success' => false, 'message' => 'Action non supportée.'], 400);
