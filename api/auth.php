<?php
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $data = jsonInput();

    if ($action === 'register') {
        if (empty($data['nom']) || empty($data['email']) || empty($data['mot_de_passe'])) {
            respond(['success' => false, 'message' => 'Tous les champs sont obligatoires.'], 422);
        }

        // Vérifier si l'email existe déjà
        $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            respond(['success' => false, 'message' => 'Cet email est déjà utilisé.'], 409);
        }

        // Créer l'utilisateur
        $hash = password_hash($data['mot_de_passe'], PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (nom, email, mot_de_passe) VALUES (?, ?, ?)");
        
        try {
            $stmt->execute([$data['nom'], $data['email'], $hash]);
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $userId = ($driver === 'pgsql') ? $pdo->lastInsertId('users_id_seq') : $pdo->lastInsertId();

            // Créer quelques prospects d'exemple pour ce nouvel utilisateur
            $stmtSample = $pdo->prepare("
                INSERT INTO prospects (user_id, nom, prenom, telephone, source, statut, notes)
                VALUES
                (?, 'Doe', 'John', '0102030405', 'Exemple', 'nouveau', 'Prospect de démonstration généré automatiquement.')
            ");
            $stmtSample->execute([$userId]);

            // Connecter l'utilisateur
            $_SESSION['user_id'] = $userId;
            $_SESSION['user_nom'] = $data['nom'];
            $_SESSION['user_email'] = $data['email'];

            respond([
                'success' => true,
                'message' => 'Inscription réussie.',
                'user' => [
                    'id' => $userId,
                    'nom' => $data['nom'],
                    'email' => $data['email']
                ]
            ], 201);
        } catch (Exception $e) {
            respond(['success' => false, 'message' => 'Erreur lors de l\'inscription.'], 500);
        }
    }

    if ($action === 'login') {
        if (empty($data['email']) || empty($data['mot_de_passe'])) {
            respond(['success' => false, 'message' => 'Email et mot de passe requis.'], 422);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch();

        if ($user && password_verify($data['mot_de_passe'], $user['mot_de_passe'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_email'] = $user['email'];

            respond([
                'success' => true,
                'message' => 'Connexion réussie.',
                'user' => [
                    'id' => $user['id'],
                    'nom' => $user['nom'],
                    'email' => $user['email']
                ]
            ]);
        } else {
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
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'nom' => $_SESSION['user_nom'],
                    'email' => $_SESSION['user_email']
                ]
            ]);
        } else {
            respond(['success' => false, 'user' => null]);
        }
    }
}

respond(['success' => false, 'message' => 'Action non supportée.'], 400);
