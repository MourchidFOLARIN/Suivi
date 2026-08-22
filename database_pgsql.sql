-- =====================================================
-- SCHÉMA PostgreSQL — Suivi Prospects
-- À exécuter dans la console PostgreSQL de Render
-- (Dashboard > Database > "Connect" > psql ou Query)
-- =====================================================

CREATE TABLE IF NOT EXISTS prospects (
    id              SERIAL PRIMARY KEY,
    nom             VARCHAR(100) NOT NULL,
    prenom          VARCHAR(100) NOT NULL,
    telephone       VARCHAR(30)  NOT NULL,
    email           VARCHAR(150) DEFAULT NULL,
    source          VARCHAR(100) DEFAULT NULL,
    statut          VARCHAR(20)  NOT NULL DEFAULT 'nouveau'
                        CHECK (statut IN ('nouveau','invite','presente','interesse','inscrit','perdu')),
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

-- Trigger pour mettre à jour date_maj automatiquement
CREATE OR REPLACE FUNCTION update_date_maj()
RETURNS TRIGGER AS $$
BEGIN
    NEW.date_maj = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_date_maj ON prospects;
CREATE TRIGGER trg_date_maj
    BEFORE UPDATE ON prospects
    FOR EACH ROW EXECUTE FUNCTION update_date_maj();

-- Données d'exemple (à supprimer si non désirées)
INSERT INTO prospects (nom, prenom, telephone, email, source, statut, invitation_faite, date_invitation, presentation_faite, date_presentation, notes)
VALUES
('AGOSSOU',    'Chimène',  '0197001122', 'chimene.a@example.com', 'Ami(e)',         'presente', 1, '2026-08-10', 1, '2026-08-15', 'Très motivée, attend de voir les résultats de son cousin.'),
('DOSSOU',     'Ferdinand','0197002233', NULL,                    'Marché',         'invite',   1, '2026-08-18', 0, NULL,         'A dit qu il rappellerait après le week-end.'),
('HOUNTONDJI', 'Sandra',   '0197003344', 'sandra.h@example.com', 'Réseaux sociaux','nouveau',  0, NULL,         0, NULL,         'Contact pris via Facebook, pas encore relancée.');
