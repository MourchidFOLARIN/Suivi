-- Base de données : suivi_prospects
-- A importer dans phpMyAdmin ou via : mysql -u root -p < database.sql

CREATE DATABASE IF NOT EXISTS suivi_prospects CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE suivi_prospects;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    mot_de_passe VARCHAR(255) NOT NULL,
    date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS prospects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
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
    date_maj DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY prospects_user_date_ajout (user_id, date_ajout),
    KEY prospects_user_statut (user_id, statut),
    CONSTRAINT prospects_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_attempts (
    attempt_key VARCHAR(64) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    blocked_until DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
