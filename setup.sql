-- =====================================================================
-- setup.sql - vytvoreni databaze pro projekt Planodar (cista instalace)
-- Spusteni: phpMyAdmin -> zalozka SQL -> vlozit cely obsah -> Proved
-- V Dockeru se spusti automaticky pri prvnim startu databaze.
-- Na hostingu neni potreba nic spoustet: schema.php si odsud vezme
-- prikazy CREATE TABLE a chybejici tabulky vytvori sam.
-- Mas-li uz starou databazi bez uctu, pouzij misto toho migrace_ucty.sql.
-- (Komentare pis vzdy na samostatny radek - schema.php je pred delenim odstrani.)
-- =====================================================================

-- Vytvori databazi "planodar", pokud jeste neexistuje
-- utf8mb4 = plna podpora UTF-8 (ceske znaky i emoji), utf8mb4_czech_ci = ceske razeni (ch za h atd.)
CREATE DATABASE IF NOT EXISTS planodar
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_czech_ci;

-- Prepne se do nove databaze, vsechny dalsi prikazy se tykaji ji
USE planodar;

-- ---------------------------------------------------------------------
-- Tabulka "users" - uzivatelske ucty
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    -- Jedinecny identifikator uzivatele
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Zobrazovane jmeno, napr. "Jan"
    jmeno VARCHAR(100) NOT NULL,
    -- E-mail slouzi jako prihlasovaci jmeno, proto musi byt jedinecny
    email VARCHAR(255) NOT NULL,
    -- Otisk (hash) hesla z funkce password_hash() - samotne heslo se NIKDY neuklada
    heslo_hash VARCHAR(255) NOT NULL,
    -- Cislo "verze" prihlaseni; zvyseni odhlasi uzivatele na vsech zarizenich
    verze_prihlaseni INT UNSIGNED NOT NULL DEFAULT 1,
    -- Kdy byl ucet vytvoren - databaze vyplni sama
    vytvoreno TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Primarni klic tabulky
    PRIMARY KEY (id),
    -- Unikatni index: dva ucty nemohou mit stejny e-mail
    UNIQUE KEY uq_users_email (email)
)
-- InnoDB je nutny kvuli podpore cizich klicu
ENGINE = InnoDB
-- Kodovani utf8mb4 kvuli ceskym znakum
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka "events" - jedna radka = jedna udalost v kalendari
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    -- Jedinecny identifikator udalosti, automaticky se zvysuje (1, 2, 3, ...)
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Vlastnik udalosti (hodnota users.id) - kazdy vidi jen sve udalosti
    user_id INT UNSIGNED NOT NULL,
    -- Nazev udalosti, napr. "Pohreb" (max. 255 znaku, povinny)
    nazev VARCHAR(255) NOT NULL,
    -- Datum udalosti ve formatu RRRR-MM-DD (povinne)
    datum DATE NOT NULL,
    -- Cas zacatku udalosti ve formatu HH:MM:SS (povinny)
    cas TIME NOT NULL,
    -- Volitelny delsi popis udalosti (muze byt prazdny = NULL)
    popis TEXT NULL,
    -- Kdy byl zaznam vytvoren - databaze vyplni sama
    vytvoreno TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Primarni klic tabulky je sloupec id
    PRIMARY KEY (id),
    -- Index zrychli nejcastejsi dotaz: "udalosti tohoto uzivatele v tomto mesici"
    INDEX idx_events_user_datum (user_id, datum),
    -- Cizi klic: udalost patri existujicimu uzivateli
    CONSTRAINT fk_events_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        -- Smazani uctu smaze i vsechny jeho udalosti
        ON DELETE CASCADE
        -- Zmena id uzivatele se propise i sem
        ON UPDATE CASCADE
)
-- InnoDB je nutny kvuli podpore cizich klicu
ENGINE = InnoDB
-- Stejne kodovani jako cela databaze
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka "subtasks" - pripravne podukoly k udalosti
-- (generuje je AI pres Claude API, uzivatel je muze upravovat a mazat)
-- Vlastnika podukolu urcuje jeho udalost (events.user_id).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subtasks (
    -- Jedinecny identifikator podukolu
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Odkaz na udalost, ke ktere podukol patri (hodnota events.id)
    event_id INT UNSIGNED NOT NULL,
    -- Nazev podukolu, napr. "Vycistit oblek"
    nazev VARCHAR(255) NOT NULL,
    -- Volitelny datum a cas, do kdy se ma podukol splnit
    cas DATETIME NULL,
    -- Odhad, kolik minut ukol zabere (navrhuje AI, muze chybet)
    odhad_minut SMALLINT UNSIGNED NULL,
    -- Dulezity ukol (hvezdicka): 0 = ne, 1 = ano
    dulezite TINYINT(1) NOT NULL DEFAULT 0,
    -- Priznak splneni: 0 = nesplneno, 1 = splneno
    splneno TINYINT(1) NOT NULL DEFAULT 0,
    -- Primarni klic tabulky
    PRIMARY KEY (id),
    -- Index pro rychle nacteni vsech podukolu jedne udalosti
    INDEX idx_subtasks_event (event_id),
    -- Cizi klic: event_id musi existovat v tabulce events
    CONSTRAINT fk_subtasks_event
        FOREIGN KEY (event_id) REFERENCES events (id)
        -- Pri smazani udalosti se automaticky smazou i jeji podukoly
        ON DELETE CASCADE
        -- Pri zmene id udalosti se zmena propise i sem
        ON UPDATE CASCADE
)
-- InnoDB kvuli cizim klicum
ENGINE = InnoDB
-- Kodovani utf8mb4 kvuli ceskym znakum
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka "prihlaseni_tokeny" - funkce "Zapamatovat si me"
-- Cookie v prohlizeci obsahuje "selector:validator". Selector slouzi
-- k nalezeni radku, validator se uklada jen jako hash (SHA-256) -
-- kdo ukradne databazi, nemuze se z ni prihlasit.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS prihlaseni_tokeny (
    -- Jedinecny identifikator tokenu
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Uzivatel, ktereho token prihlasuje
    user_id INT UNSIGNED NOT NULL,
    -- Verejna cast tokenu (24 hex znaku) - podle ni se token hleda
    selector CHAR(24) NOT NULL,
    -- Hash tajne casti tokenu (SHA-256 = 64 hex znaku)
    validator_hash CHAR(64) NOT NULL,
    -- Do kdy token plati (pak je nutne se prihlasit znovu)
    plati_do DATETIME NOT NULL,
    -- Primarni klic tabulky
    PRIMARY KEY (id),
    -- Selector musi byt jedinecny
    UNIQUE KEY uq_tokeny_selector (selector),
    -- Cizi klic: smazani uctu smaze i jeho tokeny
    CONSTRAINT fk_tokeny_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
)
-- InnoDB kvuli cizim klicum
ENGINE = InnoDB
-- Kodovani utf8mb4
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka "ai_generovani" - kdy kdo nechal AI generovat podukoly
-- (limit generovani za hodinu chrani kredit Anthropic pred zneuzitim;
--  ve starsi databazi ji podukoly.php vytvori samo)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ai_generovani (
    -- Jedinecny identifikator zaznamu
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Uzivatel, ktery generovani spustil
    user_id INT UNSIGNED NOT NULL,
    -- Kdy generovani probehlo
    cas TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Primarni klic tabulky
    PRIMARY KEY (id),
    -- Index pro rychle spocitani generovani uzivatele za posledni hodinu
    INDEX idx_ai_user_cas (user_id, cas),
    -- Cizi klic: smazani uctu smaze i jeho zaznamy
    CONSTRAINT fk_ai_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
)
-- InnoDB kvuli cizim klicum
ENGINE = InnoDB
-- Kodovani utf8mb4
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_czech_ci;

-- ---------------------------------------------------------------------
-- Tabulka "pokusy_prihlaseni" - ochrana proti hadani hesel
-- Kazdy neuspesny pokus o prihlaseni se zapise; po 5 neuspesnych
-- pokusech na jeden e-mail behem 15 minut se prihlaseni docasne zablokuje.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pokusy_prihlaseni (
    -- Jedinecny identifikator pokusu
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- E-mail, na ktery se nekdo pokousel prihlasit
    email VARCHAR(255) NOT NULL,
    -- IP adresa pokusu (45 znaku staci i pro IPv6)
    ip VARCHAR(45) NOT NULL,
    -- Cas pokusu
    cas TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Primarni klic tabulky
    PRIMARY KEY (id),
    -- Indexy pro rychle spocitani pokusu podle e-mailu a podle IP
    INDEX idx_pokusy_email (email, cas),
    INDEX idx_pokusy_ip (ip, cas)
)
-- InnoDB
ENGINE = InnoDB
-- Kodovani utf8mb4
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_czech_ci;
