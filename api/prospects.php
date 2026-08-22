<?php
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$validStatuts = ['nouveau', 'invite', 'presente', 'interesse', 'inscrit', 'perdu'];

switch ($method) {

    // ---------- LECTURE ----------
    case 'GET':
        if ($action === 'stats') {
            $total = $pdo->query("SELECT COUNT(*) FROM prospects")->fetchColumn();
            $inscrits = $pdo->query("SELECT COUNT(*) FROM prospects WHERE statut = 'inscrit'")->fetchColumn();
            $perdus = $pdo->query("SELECT COUNT(*) FROM prospects WHERE statut = 'perdu'")->fetchColumn();
            $enCours = $total - $inscrits - $perdus;
            $tauxConversion = $total > 0 ? round(($inscrits / $total) * 100, 1) : 0;

            respond([
                'success' => true,
                'data' => [
                    'total' => (int)$total,
                    'inscrits' => (int)$inscrits,
                    'perdus' => (int)$perdus,
                    'en_cours' => (int)$enCours,
                    'taux_conversion' => $tauxConversion,
                ],
            ]);
        }

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM prospects WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $prospect = $stmt->fetch();
            if (!$prospect) {
                respond(['success' => false, 'message' => 'Prospect introuvable.'], 404);
            }
            respond(['success' => true, 'data' => $prospect]);
        }

        // Liste complète, avec filtre optionnel par statut ou recherche texte
        $sql = "SELECT * FROM prospects WHERE 1=1";
        $params = [];

        if (!empty($_GET['statut'])) {
            if (!in_array($_GET['statut'], $validStatuts, true)) {
                respond(['success' => false, 'message' => 'Statut de filtrage invalide.'], 422);
            }
            $sql .= " AND statut = ?";
            $params[] = $_GET['statut'];
        }
        if (!empty($_GET['recherche'])) {
            $sql .= " AND (nom LIKE ? OR prenom LIKE ? OR telephone LIKE ?)";
            $like = '%' . $_GET['recherche'] . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }
        $sql .= " ORDER BY date_ajout DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        respond(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // ---------- CREATION ----------
    case 'POST':
        $data = jsonInput();

        if (empty($data['nom']) || empty($data['prenom']) || empty($data['telephone'])) {
            respond(['success' => false, 'message' => 'Nom, prénom et téléphone sont obligatoires.'], 422);
        }

        $statut = $data['statut'] ?? 'nouveau';
        if (!in_array($statut, $validStatuts, true)) {
            respond(['success' => false, 'message' => 'Statut invalide.'], 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO prospects
                (nom, prenom, telephone, email, source, statut, invitation_faite, date_invitation,
                 presentation_faite, date_presentation, date_inscription, prochaine_relance, notes)
            VALUES
                (:nom, :prenom, :telephone, :email, :source, :statut, :invitation_faite, :date_invitation,
                 :presentation_faite, :date_presentation, :date_inscription, :prochaine_relance, :notes)
        ");

        $stmt->execute([
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

        $lastId = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql')
            ? $pdo->lastInsertId('prospects_id_seq')
            : $pdo->lastInsertId();

        respond(['success' => true, 'message' => 'Prospect ajouté.', 'id' => $lastId], 201);
        break;

    // ---------- MODIFICATION ----------
    case 'PUT':
        $data = jsonInput();

        if (empty($data['id'])) {
            respond(['success' => false, 'message' => 'Identifiant manquant.'], 422);
        }

        // Mise à jour rapide du statut uniquement (utilisée par les listes/tableaux)
        if (isset($data['statut']) && !isset($data['nom'])) {
            if (!in_array($data['statut'], $validStatuts, true)) {
                respond(['success' => false, 'message' => 'Statut invalide.'], 422);
            }
            $stmt = $pdo->prepare("UPDATE prospects SET statut = ? WHERE id = ?");
            $stmt->execute([$data['statut'], $data['id']]);
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
                notes = :notes
            WHERE id = :id
        ");

        $stmt->execute([
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
            'id' => $data['id'],
        ]);

        respond(['success' => true, 'message' => 'Prospect mis à jour.']);
        break;

    // ---------- SUPPRESSION ----------
    case 'DELETE':
        parse_str(file_get_contents('php://input'), $deleteParams);
        $id = $_GET['id'] ?? ($deleteParams['id'] ?? null);

        if (empty($id)) {
            respond(['success' => false, 'message' => 'Identifiant manquant.'], 422);
        }

        $stmt = $pdo->prepare("DELETE FROM prospects WHERE id = ?");
        $stmt->execute([$id]);
        respond(['success' => true, 'message' => 'Prospect supprimé.']);
        break;

    default:
        respond(['success' => false, 'message' => 'Méthode non supportée.'], 405);
}
