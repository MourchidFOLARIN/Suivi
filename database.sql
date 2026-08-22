-- Base de données : suivi_prospects
-- A importer dans phpMyAdmin ou via : mysql -u root -p < database.sql

CREATE DATABASE IF NOT EXISTS suivi_prospects CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE suivi_prospects;

CREATE TABLE IF NOT EXISTS prospects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    prenom VARCHAR(100) NOT NULL,
    telephone VARCHAR(30) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    source VARCHAR(100) DEFAULT NULL,          -- comment le contact a été trouvé (ami, réseaux sociaux, marché, etc.)
    statut ENUM('nouveau', 'invite', 'presente', 'interesse', 'inscrit', 'perdu') NOT NULL DEFAULT 'nouveau',
    invitation_faite TINYINT(1) NOT NULL DEFAULT 0,
    date_invitation DATE DEFAULT NULL,
    presentation_faite TINYINT(1) NOT NULL DEFAULT 0,
    date_presentation DATE DEFAULT NULL,
    date_inscription DATE DEFAULT NULL,
    prochaine_relance DATE DEFAULT NULL,       -- date du prochain suivi à faire
    notes TEXT DEFAULT NULL,
    date_ajout DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Quelques prospects d'exemple (à supprimer si non désirés)
INSERT INTO prospects (nom, prenom, telephone, email, source, statut, invitation_faite, date_invitation, presentation_faite, date_presentation, notes)
VALUES
('AGOSSOU', 'Chimène', '0197001122', 'chimene.a@example.com', 'Ami(e)', 'presente', 1, '2026-08-10', 1, '2026-08-15', 'Très motivée, attend de voir les résultats de son cousin.'),
('DOSSOU', 'Ferdinand', '0197002233', NULL, 'Marché', 'invite', 1, '2026-08-18', 0, NULL, 'A dit qu il rappellerait après le week-end.'),
('HOUNTONDJI', 'Sandra', '0197003344', 'sandra.h@example.com', 'Réseaux sociaux', 'nouveau', 0, NULL, 0, NULL, 'Contact pris via Facebook, pas encore relancée.');
