<?php
// =====================================================================
// api.php - REST API udalosti (jen pro prihlasene uzivatele)
//
//   GET    api.php?rok=2026&mesic=10  -> udalosti uzivatele v danem mesici
//                                        (vcetne poctu podukolu a splnenych)
//   GET    api.php?hledat=pohreb      -> vyhledani udalosti podle nazvu a popisu
//   POST   api.php  (telo = JSON)     -> ulozi novou udalost
//          { "nazev": "...", "datum": "RRRR-MM-DD", "cas": "HH:MM", "popis": "..." }
//   PUT    api.php  (telo = JSON)     -> upravi udalost; posila se jen to, co se meni
//          { "id": 5, "nazev": "...", "popis": "...", "datum": "...", "cas": "..." }
//          (zmena data = presun udalosti; terminy podukolu se posunou s ni)
//   DELETE api.php  (telo = JSON)     -> smaze udalost (i s jejimi podukoly)
//          { "id": 5 }
//
// Prihlaseni, CSRF a JSON odpovedi resi api_zaklad.php. Kazdy SQL dotaz
// obsahuje "user_id = ?", takze nikdo nevidi, neupravi ani nesmaze cizi udalost.
// =====================================================================

// Spolecny zaklad API (vytvori $metoda, $uzivatel, $user_id a funkce odpovez() apod.)
require_once 'api_zaklad.php';

// Maximalni povolena delka nazvu udalosti (odpovida VARCHAR(255) v databazi)
const MAX_DELKA_NAZVU = 255;
// Maximalni povolena delka popisu (rozumny limit, at nikdo neposle megabajty textu)
const MAX_DELKA_POPISU = 2000;
// Povoleny rozsah let - stejny pro cteni (GET) i ukladani (POST)
const ROK_MIN = 1900;
const ROK_MAX = 2200;

// Maximalni pocet vysledku vyhledavani
const MAX_VYSLEDKU = 12;

// Zkontroluje nazev (spolecne pro POST i PUT); pri chybe ukonci skript
function zkontrolujNazev($nazev)
{
    // Nazev nesmi byt prazdny
    if ($nazev === '') {
        // Vratime chybu 400
        odpovez(['chyba' => 'Název události je povinný.'], 400);
    }
    // Delka nazvu (mb_strlen pocita ceske znaky spravne jako jeden znak)
    if (mb_strlen($nazev) > MAX_DELKA_NAZVU) {
        // Vratime chybu 400
        odpovez(['chyba' => 'Název je příliš dlouhý (max. ' . MAX_DELKA_NAZVU . ' znaků).'], 400);
    }
}

// Zkontroluje popis; pri chybe ukonci skript
function zkontrolujPopis($popis)
{
    // Delka popisu
    if (mb_strlen($popis) > MAX_DELKA_POPISU) {
        // Vratime chybu 400
        odpovez(['chyba' => 'Popis je příliš dlouhý (max. ' . MAX_DELKA_POPISU . ' znaků).'], 400);
    }
}

// Zkontroluje datum RRRR-MM-DD (existuje a je v povolenem rozsahu let); pri chybe ukonci skript
function zkontrolujDatum($datum)
{
    // Kontrola formatu data pomoci regularniho vyrazu; casti si ulozime do $casti
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $datum, $casti)
        // checkdate(mesic, den, rok) overi, ze datum skutecne existuje (napr. ne 31. 2.)
        || !checkdate((int) $casti[2], (int) $casti[3], (int) $casti[1])) {
        // Vratime chybu 400
        odpovez(['chyba' => 'Neplatné datum.'], 400);
    }
    // Rok musi byt v povolenem rozsahu (jinak by se udalost nedala v kalendari zobrazit)
    if ((int) $casti[1] < ROK_MIN || (int) $casti[1] > ROK_MAX) {
        // Vratime chybu 400
        odpovez(['chyba' => 'Rok musí být mezi ' . ROK_MIN . ' a ' . ROK_MAX . '.'], 400);
    }
}

// Zkontroluje cas HH:MM (hodiny 00-23, minuty 00-59); pri chybe ukonci skript
function zkontrolujCas($cas)
{
    // Regularni vyraz pro cas
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $cas)) {
        // Vratime chybu 400
        odpovez(['chyba' => 'Neplatný čas (formát HH:MM).'], 400);
    }
}

// Nacte jednu udalost uzivatele i s pocty podukolu (nebo null)
function nactiUdalost($spojeni, $id, $user_id)
{
    // Stejne sloupce jako vypis mesice
    $udalost = nactiRadek($spojeni,
        "SELECT e.id, e.nazev, e.datum, TIME_FORMAT(e.cas, '%H:%i') AS cas, e.popis,
                (SELECT COUNT(*) FROM subtasks s WHERE s.event_id = e.id) AS podukoly_celkem,
                (SELECT COUNT(*) FROM subtasks s WHERE s.event_id = e.id AND s.splneno = 1) AS podukoly_splneno
         FROM events e WHERE e.id = ? AND e.user_id = ?",
        'ii', [$id, $user_id]);
    // Cisla prevedeme na cisla
    if ($udalost !== null) {
        // Id a pocty
        $udalost['id'] = (int) $udalost['id'];
        $udalost['podukoly_celkem'] = (int) $udalost['podukoly_celkem'];
        $udalost['podukoly_splneno'] = (int) $udalost['podukoly_splneno'];
    }
    // Vratime udalost
    return $udalost;
}

// =====================================================================
// GET ?hledat=... - vyhledani udalosti podle nazvu a popisu
// =====================================================================
if ($metoda === 'GET' && isset($_GET['hledat'])) {
    // Hledany text bez okrajovych mezer
    $hledat = trim((string) $_GET['hledat']);
    // Prilis kratky nebo dlouhy text nehledame
    if (mb_strlen($hledat) < 2 || mb_strlen($hledat) > 100) {
        // Prazdny seznam
        odpovez([]);
    }
    // Znaky % a _ maji v LIKE zvlastni vyznam (zastupne znaky) -> "escapujeme" je zpetnym lomitkem
    $vzor = '%' . addcslashes($hledat, '%_\\') . '%';
    // Udalosti uzivatele, jejichz nazev nebo popis obsahuje text (porovnani bez ohledu
    // na velikost pismen diky razeni utf8mb4_czech_ci); nejdriv ty nejblize dnesku
    $vysledky = nactiRadky($spojeni,
        "SELECT id, nazev, datum, TIME_FORMAT(cas, '%H:%i') AS cas
         FROM events
         WHERE user_id = ? AND (nazev LIKE ? OR popis LIKE ?)
         ORDER BY ABS(DATEDIFF(datum, CURDATE())), datum
         LIMIT " . MAX_VYSLEDKU,
        'iss', [$user_id, $vzor, $vzor]);
    // Id jako cislo
    foreach ($vysledky as &$v) {
        // Prevod
        $v['id'] = (int) $v['id'];
    }
    // Zrusime odkaz z cyklu
    unset($v);
    // Odpoved
    odpovez($vysledky);
}

// =====================================================================
// GET - nacteni udalosti pro jeden mesic
// =====================================================================
if ($metoda === 'GET') {
    // Precteme rok z URL; kdyz chybi, pouzijeme aktualni rok
    $rok = isset($_GET['rok']) ? (int) $_GET['rok'] : (int) date('Y');
    // Precteme mesic z URL; kdyz chybi, pouzijeme aktualni mesic
    $mesic = isset($_GET['mesic']) ? (int) $_GET['mesic'] : (int) date('n');

    // Kontrola rozsahu: mesic 1-12, rok v povolenem intervalu
    if ($mesic < 1 || $mesic > 12 || $rok < ROK_MIN || $rok > ROK_MAX) {
        // Neplatne vstupy -> chyba 400 (Bad Request)
        odpovez(['chyba' => 'Neplatný rok nebo měsíc.'], 400);
    }

    // Prvni den mesice ve formatu RRRR-MM-DD (sprintf doplni nuly, napr. 2026-03-01)
    $od = sprintf('%04d-%02d-01', $rok, $mesic);
    // Posledni den mesice - "t" ve funkci date() vrati pocet dni v mesici
    $do = date('Y-m-t', strtotime($od));

    // Jen udalosti prihlaseneho uzivatele v danem mesici;
    // dva poddotazy spocitaji podukoly udalosti (vsechny a splnene)
    // TIME_FORMAT zkrati cas z "14:00:00" na "14:00"
    $udalosti = nactiRadky($spojeni,
        "SELECT e.id, e.nazev, e.datum, TIME_FORMAT(e.cas, '%H:%i') AS cas, e.popis,
                (SELECT COUNT(*) FROM subtasks s WHERE s.event_id = e.id) AS podukoly_celkem,
                (SELECT COUNT(*) FROM subtasks s WHERE s.event_id = e.id AND s.splneno = 1) AS podukoly_splneno
         FROM events e
         WHERE e.user_id = ? AND e.datum BETWEEN ? AND ?
         ORDER BY e.datum, e.cas",
        'iss', [$user_id, $od, $do]);

    // Cisla prevedeme na cisla (z databaze mohou prijit jako text)
    foreach ($udalosti as &$udalost) {
        // Id udalosti (JavaScript s nim pocita barvu listku)
        $udalost['id'] = (int) $udalost['id'];
        // Pocty podukolu
        $udalost['podukoly_celkem'] = (int) $udalost['podukoly_celkem'];
        $udalost['podukoly_splneno'] = (int) $udalost['podukoly_splneno'];
    }
    // Zrusime odkaz z cyklu (dobra praxe po foreach s &)
    unset($udalost);

    // Odesleme seznam udalosti klientovi
    odpovez($udalosti);
}

// =====================================================================
// POST - ulozeni nove udalosti
// =====================================================================
if ($metoda === 'POST') {
    // Prijata data
    $vstup = nactiJsonTelo();
    // Nazev udalosti (povinny)
    $nazev = textZVstupu($vstup, 'nazev');
    // Datum ve formatu RRRR-MM-DD (povinne)
    $datum = textZVstupu($vstup, 'datum');
    // Cas ve formatu HH:MM (povinny)
    $cas = textZVstupu($vstup, 'cas');
    // Popis je volitelny
    $popis = textZVstupu($vstup, 'popis');

    // Kontrola vsech udaju (pri chybe skript skonci s chybou 400)
    zkontrolujNazev($nazev);
    zkontrolujPopis($popis);
    zkontrolujDatum($datum);
    zkontrolujCas($cas);

    // Prazdny popis ulozime do databaze jako NULL (= hodnota neni vyplnena)
    $popis = ($popis === '') ? null : $popis;

    // Vlozime novou udalost; vlastnikem je vzdy prihlaseny uzivatel
    zmenData($spojeni,
        'INSERT INTO events (user_id, nazev, datum, cas, popis) VALUES (?, ?, ?, ?, ?)',
        'issss', [$user_id, $nazev, $datum, $cas, $popis]);

    // Vratime ulozenou udalost s kodem 201 (Created = vytvoreno)
    odpovez([
        'id'    => mysqli_insert_id($spojeni),  // Nove id z databaze
        'nazev' => $nazev,                      // Nazev udalosti
        'datum' => $datum,                      // Datum udalosti
        'cas'   => $cas,                        // Cas udalosti
        'popis' => $popis,                      // Popis (nebo null)
        'podukoly_celkem'  => 0,                // Nova udalost zatim nema podukoly
        'podukoly_splneno' => 0,
    ], 201);
}

// =====================================================================
// PUT - uprava existujici udalosti (nazev, popis, datum, cas)
// =====================================================================
if ($metoda === 'PUT') {
    // Prijata data
    $vstup = nactiJsonTelo();
    // Id upravovane udalosti
    $id = idZVstupu($vstup);

    // Udalost musi existovat A patrit prihlasenemu uzivateli
    // (cizi udalost se tvari jako neexistujici - nikdo se nedozvi, ze existuje)
    $puvodni = nactiRadek($spojeni, 'SELECT id, nazev, datum, cas, popis FROM events WHERE id = ? AND user_id = ?',
        'ii', [$id, $user_id]);
    // Nenalezeno
    if ($puvodni === null) {
        // Chyba 404 = nenalezeno
        odpovez(['chyba' => 'Událost nebyla nalezena.'], 404);
    }

    // Nove hodnoty: co neprislo, zustane puvodni
    $nazev = array_key_exists('nazev', $vstup) ? textZVstupu($vstup, 'nazev') : $puvodni['nazev'];
    $popis = array_key_exists('popis', $vstup) ? textZVstupu($vstup, 'popis') : (string) $puvodni['popis'];
    $datum = array_key_exists('datum', $vstup) ? textZVstupu($vstup, 'datum') : $puvodni['datum'];
    $cas   = array_key_exists('cas', $vstup) ? textZVstupu($vstup, 'cas') : substr($puvodni['cas'], 0, 5);

    // Kontrola vsech udaju
    zkontrolujNazev($nazev);
    zkontrolujPopis($popis);
    zkontrolujDatum($datum);
    zkontrolujCas($cas);

    // Prazdny popis ulozime jako NULL
    $popis = ($popis === '') ? null : $popis;
    // O kolik dni se udalost posouva (0 = datum se nemeni)
    $posunDni = (int) round((strtotime($datum) - strtotime($puvodni['datum'])) / 86400);

    // Uprava udalosti a posun podukolu musi probehnout spolecne -> transakce
    mysqli_begin_transaction($spojeni);
    // Zmena udalosti (user_id v podmince = dvojita pojistka)
    zmenData($spojeni,
        'UPDATE events SET nazev = ?, popis = ?, datum = ?, cas = ? WHERE id = ? AND user_id = ?',
        'ssssii', [$nazev, $popis, $datum, $cas, $id, $user_id]);
    // Udalost se presunula -> terminy jejich podukolu posuneme o stejny pocet dni
    // (pripravy "2 dny predem" tak zustanou 2 dny pred udalosti)
    if ($posunDni !== 0) {
        // DATE_ADD s poctem dni (muze byt i zaporny)
        zmenData($spojeni,
            'UPDATE subtasks SET cas = DATE_ADD(cas, INTERVAL ? DAY) WHERE event_id = ? AND cas IS NOT NULL',
            'ii', [$posunDni, $id]);
    }
    // Potvrzeni transakce
    mysqli_commit($spojeni);

    // Vratime ulozenou udalost i s pocty podukolu (200 = OK)
    odpovez(nactiUdalost($spojeni, $id, $user_id));
}

// =====================================================================
// DELETE - smazani udalosti (podukoly smaze databaze diky ON DELETE CASCADE)
// =====================================================================
// Sem se dostane jen metoda DELETE - ostatni skoncily vyse
// Prijata data
$vstup = nactiJsonTelo();
// Id mazane udalosti
$id = idZVstupu($vstup);

// Smazeme jen udalost prihlaseneho uzivatele; vrati pocet smazanych radku
$smazano = zmenData($spojeni, 'DELETE FROM events WHERE id = ? AND user_id = ?', 'ii', [$id, $user_id]);

// Nic se nesmazalo -> udalost neexistuje nebo patri nekomu jinemu
if ($smazano === 0) {
    // Chyba 404 = nenalezeno
    odpovez(['chyba' => 'Událost nebyla nalezena.'], 404);
}

// Uspech
odpovez(['id' => $id, 'smazano' => true]);
