<?php
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$user = requireAuth();
$userId = $user['id'];

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    verifyCsrfToken();
}

$validStatuts = ['nouveau', 'invite', 'presente', 'interesse', 'inscrit', 'perdu'];

function normalizeText(mixed $value, string $field, int $maxLength): string
{
    if ($value === null) {
        return '';
    }
    if (!is_string($value)) {
        respond(['success' => false, 'message' => "Le champ {$field} doit être une chaîne de caractères."], 422);
    }

    $value = trim($value);
    if (strlen($value) > $maxLength) {
        respond(['success' => false, 'message' => "Le champ {$field} est trop long."], 422);
    }

    return $value;
}

function validateDateOrNull(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $value = normalizeText($value, 'date', 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        respond(['success' => false, 'message' => 'La date est invalide. Utilise le format YYYY-MM-DD.'], 422);
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) {
        respond(['success' => false, 'message' => 'La date est invalide. Utilise le format YYYY-MM-DD.'], 422);
    }

    return $value;
}

function normalizeBoolean(mixed $value, string $field): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
        return (int) $value;
    }
    respond(['success' => false, 'message' => "Le champ {$field} doit être booléen."], 422);
}

function validatedPositiveId(mixed $value): int
{
    if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        respond(['success' => false, 'message' => 'Identifiant invalide.'], 422);
    }
    return (int) $value;
}

function normalizeProspectPayload(array $data, bool $isUpdate = false): array
{
    $nom = normalizeText($data['nom'] ?? '', 'nom', 100);
    $prenom = normalizeText($data['prenom'] ?? '', 'prénom', 100);
    $telephone = normalizeText($data['telephone'] ?? '', 'téléphone', 30);
    $email = normalizeText($data['email'] ?? '', 'email', 150);
    $source = normalizeText($data['source'] ?? '', 'source', 100);
    $notes = normalizeText($data['notes'] ?? '', 'notes', 5000);
    $statut = normalizeText($data['statut'] ?? 'nouveau', 'statut', 20);

    if (!$isUpdate && $nom === '' && $prenom === '' && $telephone === '' && $email === '') {
        respond(['success' => false, 'message' => 'Renseigne au moins un nom, un prénom, un téléphone ou un email.'], 422);
    }

    // Le schéma historique impose ces colonnes, mais une fiche peut être créée
    // avec une information partielle puis complétée lors d'une relance.
    $nom = $nom !== '' ? $nom : ($prenom !== '' ? $prenom : 'Prospect sans nom');
    $prenom = $prenom !== '' ? $prenom : '-';
    $telephone = $telephone !== '' ? $telephone : 'Non renseigné';

    if ($statut === '' || !in_array($statut, $GLOBALS['validStatuts'], true)) {
        respond(['success' => false, 'message' => 'Statut invalide.'], 422);
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'message' => 'L’email est invalide.'], 422);
    }

    $invitationFaite = normalizeBoolean($data['invitation_faite'] ?? false, 'invitation_faite');
    $presentationFaite = normalizeBoolean($data['presentation_faite'] ?? false, 'presentation_faite');
    $dateInvitation = validateDateOrNull($data['date_invitation'] ?? null);
    $datePresentation = validateDateOrNull($data['date_presentation'] ?? null);
    $dateInscription = validateDateOrNull($data['date_inscription'] ?? null);
    $prochaineRelance = validateDateOrNull($data['prochaine_relance'] ?? null);

    if ($invitationFaite && $dateInvitation === null) {
        respond(['success' => false, 'message' => 'La date d’invitation est obligatoire si l’invitation a été faite.'], 422);
    }

    if ($presentationFaite && $datePresentation === null) {
        respond(['success' => false, 'message' => 'La date de présentation est obligatoire si la présentation a été faite.'], 422);
    }

    return [
        'nom' => $nom,
        'prenom' => $prenom,
        'telephone' => $telephone,
        'email' => $email !== '' ? $email : null,
        'source' => $source !== '' ? $source : null,
        'statut' => $statut,
        'invitation_faite' => $invitationFaite,
        'date_invitation' => $dateInvitation,
        'presentation_faite' => $presentationFaite,
        'date_presentation' => $datePresentation,
        'date_inscription' => $dateInscription,
        'prochaine_relance' => $prochaineRelance,
        'notes' => $notes !== '' ? $notes : null,
    ];
}

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
            break;
        }

        if (isset($_GET['id'])) {
            $id = validatedPositiveId($_GET['id']);
            $stmt = $pdo->prepare("SELECT * FROM prospects WHERE id = ? AND user_id = ?");
            $stmt->execute([$id, $userId]);
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
            if (!is_string($_GET['recherche']) || strlen($_GET['recherche']) > 100) {
                respond(['success' => false, 'message' => 'Recherche invalide.'], 422);
            }
            $query .= " AND (nom LIKE :recherche OR prenom LIKE :recherche OR telephone LIKE :recherche)";
            $params[':recherche'] = '%' . $_GET['recherche'] . '%';
        }

        $query .= " ORDER BY date_ajout DESC";
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) ?: 100;
        $offset = ($page - 1) * $limit;
        $query .= " LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $prospects = $stmt->fetchAll();

        respond(['success' => true, 'data' => $prospects, 'pagination' => ['page' => $page, 'limit' => $limit]]);
        break;

    // ---------- CRÉATION ----------
    case 'POST':
        $data = jsonInput();
        $payload = normalizeProspectPayload($data, false);

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
            'nom' => $payload['nom'],
            'prenom' => $payload['prenom'],
            'telephone' => $payload['telephone'],
            'email' => $payload['email'],
            'source' => $payload['source'],
            'statut' => $payload['statut'],
            'invitation_faite' => $payload['invitation_faite'],
            'date_invitation' => $payload['date_invitation'],
            'presentation_faite' => $payload['presentation_faite'],
            'date_presentation' => $payload['date_presentation'],
            'date_inscription' => $payload['date_inscription'],
            'prochaine_relance' => $payload['prochaine_relance'],
            'notes' => $payload['notes'],
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

        $id = validatedPositiveId($data['id'] ?? null);

        // Vérifier d'abord si le prospect existe et appartient à cet utilisateur
        $checkStmt = $pdo->prepare("SELECT id FROM prospects WHERE id = ? AND user_id = ?");
        $checkStmt->execute([$id, $userId]);
        if (!$checkStmt->fetch()) {
            respond(['success' => false, 'message' => 'Prospect introuvable ou non autorisé.'], 403);
        }

        // Mise à jour rapide du statut
        if (isset($data['statut']) && !isset($data['nom'])) {
            $statut = normalizeText($data['statut'], 'statut', 20);
            if (!in_array($statut, $validStatuts, true)) {
                respond(['success' => false, 'message' => 'Statut invalide.'], 422);
            }
            $stmt = $pdo->prepare("UPDATE prospects SET statut = ?, date_maj = NOW() WHERE id = ? AND user_id = ?");
            $stmt->execute([$statut, $id, $userId]);
            respond(['success' => true, 'message' => 'Statut mis à jour.']);
        }

        $payload = normalizeProspectPayload($data, true);

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
            'id' => $id,
            'user_id' => $userId,
            'nom' => $payload['nom'],
            'prenom' => $payload['prenom'],
            'telephone' => $payload['telephone'],
            'email' => $payload['email'],
            'source' => $payload['source'],
            'statut' => $payload['statut'],
            'invitation_faite' => $payload['invitation_faite'],
            'date_invitation' => $payload['date_invitation'],
            'presentation_faite' => $payload['presentation_faite'],
            'date_presentation' => $payload['date_presentation'],
            'date_inscription' => $payload['date_inscription'],
            'prochaine_relance' => $payload['prochaine_relance'],
            'notes' => $payload['notes'],
        ]);

        respond(['success' => true, 'message' => 'Prospect mis à jour.']);
        break;

    // ---------- SUPPRESSION ----------
    case 'DELETE':
        $id = validatedPositiveId($_GET['id'] ?? null);
        $stmt = $pdo->prepare("DELETE FROM prospects WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        
        if ($stmt->rowCount() > 0) {
            respond(['success' => true, 'message' => 'Prospect supprimé.']);
        } else {
            respond(['success' => false, 'message' => 'Prospect introuvable ou non autorisé.'], 403);
        }
        break;

    default:
        respond(['success' => false, 'message' => 'Méthode non autorisée.'], 405);
}
