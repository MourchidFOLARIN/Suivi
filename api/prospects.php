<?php
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$user = requireAuth();
$userId = $user['id'];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$validStatuts = ['nouveau', 'invite', 'presente', 'interesse', 'inscrit', 'perdu'];

switch ($method) {

    // ---------- LECTURE ----------
    case 'GET':
        if ($action === 'stats') {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    COALESCE(SUM(CASE WHEN statut = 'inscrit' THEN 1 ELSE 0 END), 0) as inscrits,
                    COALESCE(SUM(CASE WHEN statut = 'perdu' THEN 1 ELSE 0 END), 0) as perdus
                FROM prospects 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            $total = (int)($row['total'] ?? 0);
            $inscrits = (int)($row['inscrits'] ?? 0);
            $perdus = (int)($row['perdus'] ?? 0);
            $en_cours = $total - $inscrits - $perdus;
            $taux_conversion = $total > 0 ? round(($inscrits / $total) * 100, 1) : 0;

            respond([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'inscrits' => $inscrits,
                    'perdus' => $perdus,
                    'en_cours' => $en_cours,
                    'taux_conversion' => $taux_conversion
                ]
            ]);
        }

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM prospects WHERE id = ? AND user_id = ?");
            $stmt->execute([$_GET['id'], $userId]);
            $prospect = $stmt->fetch();
            if ($prospect) {
                respond(['success' => true, 'data' => $prospect]);
            } else {
                respond(['success' => false, 'message' => 'Prospect introuvable ou non autorisé.'], 404);
            }
        }

        // Liste globale
        $query = "SELECT * FROM prospects WHERE user_id = :user_id";
        $params = [':user_id' => $userId];

        if (!empty($_GET['statut'])) {
            if (!in_array($_GET['statut'], $validStatuts, true)) {
                respond(['success' => false, 'message' => 'Statut invalide.'], 422);
            }
            $query .= " AND statut = :statut";
            $params[':statut'] = $_GET['statut'];
        }

        if (!empty($_GET['recherche'])) {
            $query .= " AND (nom LIKE :recherche OR prenom LIKE :recherche OR telephone LIKE :recherche)";
            $params[':recherche'] = '%' . $_GET['recherche'] . '%';
        }

        $query .= " ORDER BY date_ajout DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $prospects = $stmt->fetchAll();

        respond(['success' => true, 'data' => $prospects]);
        break;

    // ---------- CRÉATION ----------
    case 'POST':
        $data = jsonInput();
        if (empty($data['nom']) || empty($data['prenom']) || empty($data['telephone'])) {
            respond(['success' => false, 'message' => 'Nom, prénom et téléphone sont obligatoires.'], 422);
        }

        $statut = $data['statut'] ?? 'nouveau';
        if (!in_array($statut, $validStatuts, true)) {
            respond(['success' => false, 'message' => 'Statut invalide.'], 422);
        }

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = "
            INSERT INTO prospects
                (user_id, nom, prenom, telephone, email, source, statut, invitation_faite, date_invitation,
                 presentation_faite, date_presentation, date_inscription, prochaine_relance, notes)
            VALUES
                (:user_id, :nom, :prenom, :telephone, :email, :source, :statut, :invitation_faite, :date_invitation,
                 :presentation_faite, :date_presentation, :date_inscription, :prochaine_relance, :notes)
        ";

        if ($driver === 'pgsql') {
            $sql .= " RETURNING id";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'nom' => $data['nom'],
            'prenom' => $data['prenom'],
            'telephone' => $data['telephone'],
            'email' => $data['email'] ?? null,
            'source' => $data['source'] ?? null,
            'statut' => $statut,
            'invitation_faite' => !empty($data['invitation_faite']) ? 1 : 0,
            'date_invitation' => $data['date_invitation'] ?? null,
            'presentation_faite' => !empty($data['presentation_faite']) ? 1 : 0,
            'date_presentation' => $data['date_presentation'] ?? null,
            'date_inscription' => $data['date_inscription'] ?? null,
            'prochaine_relance' => $data['prochaine_relance'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($driver === 'pgsql') {
            $row = $stmt->fetch();
            $lastId = (int) $row['id'];
        } else {
            $lastId = (int) $pdo->lastInsertId();
        }

        respond(['success' => true, 'message' => 'Prospect ajouté.', 'id' => $lastId], 201);
        break;

    // ---------- MODIFICATION ----------
    case 'PUT':
        $data = jsonInput();

        if (empty($data['id'])) {
            respond(['success' => false, 'message' => 'Identifiant manquant.'], 422);
        }

        // Vérifier d'abord si le prospect existe et appartient à cet utilisateur
        $checkStmt = $pdo->prepare("SELECT id FROM prospects WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$data['id'], $userId]);
        if (!$checkStmt->fetch()) {
            respond(['success' => false, 'message' => 'Prospect introuvable ou non autorisé.'], 403);
        }

        // Mise à jour rapide du statut
        if (isset($data['statut']) && !isset($data['nom'])) {
            if (!in_array($data['statut'], $validStatuts, true)) {
                respond(['success' => false, 'message' => 'Statut invalide.'], 422);
            }
            $stmt = $pdo->prepare("UPDATE prospects SET statut = ?, date_maj = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$data['statut'], $data['id'], $userId]);
            respond(['success' => true, 'message' => 'Statut mis à jour.']);
        }

        if (empty($data['nom']) || empty($data['prenom']) || empty($data['telephone'])) {
            respond(['success' => false, 'message' => 'Nom, prénom et téléphone sont obligatoires.'], 422);
        }

        $statut = $data['statut'] ?? 'nouveau';
        if (!in_array($statut, $validStatuts, true)) {
            respond(['success' => false, 'message' => 'Statut invalide.'], 422);
        }

        $stmt = $pdo->prepare("
            UPDATE prospects SET
                nom = :nom,
                prenom = :prenom,
                telephone = :telephone,
                email = :email,
                source = :source,
                statut = :statut,
                invitation_faite = :invitation_faite,
                date_invitation = :date_invitation,
                presentation_faite = :presentation_faite,
                date_presentation = :date_presentation,
                date_inscription = :date_inscription,
                prochaine_relance = :prochaine_relance,
                notes = :notes,
                date_maj = NOW()
            WHERE id = :id AND user_id = :user_id
        ");

        $stmt->execute([
            'id' => $data['id'],
            'user_id' => $userId,
            'nom' => $data['nom'],
            'prenom' => $data['prenom'],
            'telephone' => $data['telephone'],
            'email' => $data['email'] ?? null,
            'source' => $data['source'] ?? null,
            'statut' => $statut,
            'invitation_faite' => !empty($data['invitation_faite']) ? 1 : 0,
            'date_invitation' => $data['date_invitation'] ?? null,
            'presentation_faite' => !empty($data['presentation_faite']) ? 1 : 0,
            'date_presentation' => $data['date_presentation'] ?? null,
            'date_inscription' => $data['date_inscription'] ?? null,
            'prochaine_relance' => $data['prochaine_relance'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        respond(['success' => true, 'message' => 'Prospect mis à jour.']);
        break;

    // ---------- SUPPRESSION ----------
    case 'DELETE':
        if (empty($_GET['id'])) {
            respond(['success' => false, 'message' => 'Identifiant manquant.'], 422);
        }
        $stmt = $pdo->prepare("DELETE FROM prospects WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['id'], $userId]);
        
        if ($stmt->rowCount() > 0) {
            respond(['success' => true, 'message' => 'Prospect supprimé.']);
        } else {
            respond(['success' => false, 'message' => 'Prospect introuvable ou non autorisé.'], 403);
        }
        break;

    default:
        respond(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
}
