-- =====================================================
-- SCHÉMA PostgreSQL — Suivi Prospects
-- À exécuter dans la console PostgreSQL de Render
-- (Dashboard > Database > "Connect" > psql ou Query)
-- =====================================================

CREATE TABLE IF NOT EXISTS users (
    id              SERIAL PRIMARY KEY,
    nom             VARCHAR(100) NOT NULL,
    email           VARCHAR(150) NOT NULL,
    mot_de_passe    VARCHAR(255) NOT NULL,
    date_creation   TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique_idx ON users (LOWER(email));

CREATE TABLE IF NOT EXISTS prospects (
    id              SERIAL PRIMARY KEY,
    user_id         INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
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

CREATE INDEX IF NOT EXISTS prospects_user_date_ajout_idx ON prospects (user_id, date_ajout);
CREATE INDEX IF NOT EXISTS prospects_user_statut_idx ON prospects (user_id, statut);

CREATE TABLE IF NOT EXISTS auth_attempts (
    attempt_key VARCHAR(64) PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    blocked_until TIMESTAMP DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
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
