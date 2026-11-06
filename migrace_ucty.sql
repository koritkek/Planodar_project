-- =====================================================================
-- migrace_ucty.sql - prevod STARE databaze (bez uctu) na novou verzi
--
-- Pouzij JEN tehdy, kdyz uz mas databazi "planodar" z puvodni verze
-- aplikace (tabulka events bez sloupce user_id). Pro novou instalaci
-- staci setup.sql.
--
-- POZOR: stavajici udalosti nepatri zadnemu uctu, proto se SMAZOU
-- (vcetne jejich podukolu). Skript je bezpecne spustit i vicekrat -
-- pri dalsim spusteni uz nic nemaze (vsechny udalosti maji vlastnika).
-- Pouziva "IF NOT EXISTS" u ALTER TABLE, coz umi MariaDB (XAMPP, Docker).
-- =====================================================================

-- Pracujeme v databazi planodar
USE planodar;

-- Tabulka uctu (stejna definice jako v setup.sql)
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    jmeno VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    heslo_hash VARCHAR(255) NOT NULL,
    verze_prihlaseni INT UNSIGNED NOT NULL DEFAULT 1,
    vytvoreno TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_czech_ci;

-- Tabulka tokenu pro "Zapamatovat si me" (stejna jako v setup.sql)
CREATE TABLE IF NOT EXISTS prihlaseni_tokeny (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    selector CHAR(24) NOT NULL,
    validator_hash CHAR(64) NOT NULL,
    plati_do DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tokeny_selector (selector),
    CONSTRAINT fk_tokeny_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_czech_ci;

-- Tabulka neuspesnych pokusu o prihlaseni (stejna jako v setup.sql)
CREATE TABLE IF NOT EXISTS pokusy_prihlaseni (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    cas TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_pokusy_email (email, cas),
    INDEX idx_pokusy_ip (ip, cas)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_czech_ci;

-- 1) Pridame sloupec s vlastnikem - zatim smi byt prazdny (NULL),
--    protoze stare udalosti zadneho vlastnika nemaji
ALTER TABLE events ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;

-- 2) Smazeme jen udalosti BEZ vlastnika (podukoly se smazou kaskadou);
--    udalosti uzivatelu zustanou, i kdyby se skript spustil znovu
DELETE FROM events WHERE user_id IS NULL;

-- 3) Ted uz ma kazda udalost vlastnika -> sloupec muze byt povinny
ALTER TABLE events MODIFY user_id INT UNSIGNED NOT NULL;

-- 4) Index pro rychle hledani udalosti uzivatele podle data
ALTER TABLE events ADD INDEX IF NOT EXISTS idx_events_user_datum (user_id, datum);

-- 5) Cizi klic na users: smazani uctu smaze i jeho udalosti
--    (v MariaDB se IF NOT EXISTS pise az za FOREIGN KEY)
ALTER TABLE events ADD CONSTRAINT fk_events_user
    FOREIGN KEY IF NOT EXISTS (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE;
