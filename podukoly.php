<?php
// =====================================================================
// podukoly.php - REST API pripravnych podukolu (jen pro prihlasene)
//
//   GET    podukoly.php?event_id=5             -> podukoly udalosti
//   POST   { "event_id": 5, "nahradit": false } -> AI vygeneruje podukoly
//          (nahradit = true: stavajici podukoly nahradi novymi)
//   POST   { "event_id": 5, "nazev": "..." }    -> rucne prida jeden podukol
//          (nepovinne i "termin", "odhad_minut", "dulezite", "splneno" -
//           pouziva se i pro tlacitko "Zpet" po smazani)
//   PUT    { "id": 12, "nazev": "...", "splneno": true, "dulezite": true }
//          -> uprava podukolu (staci poslat jen to, co se meni)
//   DELETE { "id": 12 }                        -> smaze jeden podukol
//   DELETE { "event_id": 5, "vse": true }      -> smaze vsechny podukoly udalosti
//
// Vlastnika podukolu urcuje jeho udalost (events.user_id), proto kazdy
// dotaz overuje pres JOIN, ze udalost patri prihlasenemu uzivateli.
// Kazda odpoved obsahuje "pocty" (celkem / splneno) pro listek v kalendari.
// =====================================================================

// Spolecny zaklad API (prihlaseni, CSRF, JSON, $user_id ...)
require_once 'api_zaklad.php';
// Komunikace s AI
require_once 'ai.php';

// Maximalni delka nazvu podukolu (sloupec VARCHAR(255))
const MAX_DELKA_PODUKOLU = 255;
// Maximalni pocet podukolu jedne udalosti (ochrana pred zahlcenim databaze)
const MAX_PODUKOLU_UDALOSTI = 50;

// ---------------------------------------------------------------------
// Pomocne funkce
// ---------------------------------------------------------------------

// Nacte udalost, pokud patri prihlasenemu uzivateli; jinak ukonci skript chybou 404
function vlastniUdalost($spojeni, $event_id, $user_id)
{
    // Udalost hledame vzdy i podle vlastnika
    $udalost = nactiRadek($spojeni,
        'SELECT id, nazev, datum, cas, popis FROM events WHERE id = ? AND user_id = ?',
        'ii', [$event_id, $user_id]);
    // Cizi nebo neexistujici udalost
    if ($udalost === null) {
        // Chyba 404 = nenalezeno
        odpovez(['chyba' => 'Událost nebyla nalezena.'], 404);
    }
    // Vratime udalost
    return $udalost;
}

// Vsechny podukoly udalosti (termin jako RRRR-MM-DD, ano/ne hodnoty jako true/false)
function seznamPodukolu($spojeni, $event_id)
{
    // Serazeno podle terminu (bez terminu na konec), pak v poradi vytvoreni
    $radky = nactiRadky($spojeni,
        "SELECT id, nazev, DATE_FORMAT(cas, '%Y-%m-%d') AS termin, odhad_minut, dulezite, splneno
         FROM subtasks WHERE event_id = ?
         ORDER BY cas IS NULL, cas, id",
        'i', [$event_id]);
    // Prevod typu pro JavaScript
    foreach ($radky as &$radek) {
        // Id jako cislo
        $radek['id'] = (int) $radek['id'];
        // Odhad jako cislo, nebo null
        $radek['odhad_minut'] = $radek['odhad_minut'] === null ? null : (int) $radek['odhad_minut'];
        // 0/1 z databaze -> false/true
        $radek['dulezite'] = (bool) $radek['dulezite'];
        $radek['splneno'] = (bool) $radek['splneno'];
    }
    // Zrusime odkaz z cyklu
    unset($radek);
    // Vratime seznam
    return $radky;
}

// Pocet podukolu udalosti: celkem a splnenych
function poctyPodukolu($spojeni, $event_id)
{
    // COUNT spocita vsechny, SUM splnene (splneno je 0 nebo 1)
    $radek = nactiRadek($spojeni,
        'SELECT COUNT(*) AS celkem, COALESCE(SUM(splneno), 0) AS splneno FROM subtasks WHERE event_id = ?',
        'i', [$event_id]);
    // Vratime cisla
    return ['celkem' => (int) $radek['celkem'], 'splneno' => (int) $radek['splneno']];
}

// Odpoved se seznamem a pocty podukolu udalosti
function odpovezSeznamem($spojeni, $event_id, $kod = 200)
{
    // Seznam, pocty a informace, zda je AI nastavena
    odpovez([
        'podukoly' => seznamPodukolu($spojeni, $event_id),
        'pocty'    => poctyPodukolu($spojeni, $event_id),
        'ai'       => jeAiNastavena(),
    ], $kod);
}

// Upravi nazev podukolu (mezery) a zkontroluje ho; pri chybe ukonci skript
function zkontrolujNazevPodukolu($nazev)
{
    // Vicenasobne mezery a odradkovani -> jedna mezera
    $nazev = trim(preg_replace('/\s+/u', ' ', $nazev));
    // Nazev je povinny
    if ($nazev === '') {
        // Chyba 400
        odpovez(['chyba' => 'Název podúkolu nesmí být prázdný.'], 400);
    }
    // Maximalni delka
    if (mb_strlen($nazev) > MAX_DELKA_PODUKOLU) {
        // Chyba 400
        odpovez(['chyba' => 'Název podúkolu je příliš dlouhý (max. ' . MAX_DELKA_PODUKOLU . ' znaků).'], 400);
    }
    // Vratime upraveny nazev
    return $nazev;
}

// Precte nepovinnou hodnotu ano/ne; chybi -> $vychozi, jiny typ nez true/false -> chyba 400
function anoNeZVstupu($vstup, $klic, $vychozi)
{
    // Klic chybi -> vychozi hodnota
    if (!array_key_exists($klic, $vstup)) {
        // Vychozi
        return $vychozi;
    }
    // Musi to byt opravdu true nebo false (ne "1", ne 1)
    if (!is_bool($vstup[$klic])) {
        // Chyba 400
        odpovez(['chyba' => 'Neplatná hodnota „' . $klic . '“.'], 400);
    }
    // Hodnota
    return $vstup[$klic];
}

// =====================================================================
// GET - podukoly jedne udalosti
// =====================================================================
if ($metoda === 'GET') {
    // Id udalosti z adresy; (int) z neplatne hodnoty udela 0
    $event_id = (int) ($_GET['event_id'] ?? 0);
    // Udalost musi patrit prihlasenemu uzivateli
    vlastniUdalost($spojeni, $event_id, $user_id);
    // Odpoved se seznamem
    odpovezSeznamem($spojeni, $event_id);
}

// =====================================================================
// POST - rucni pridani podukolu, nebo vygenerovani pomoci AI
// =====================================================================
if ($metoda === 'POST') {
    // Prijata data
    $vstup = nactiJsonTelo();
    // Id udalosti
    $event_id = idZVstupu($vstup, 'event_id');
    // Udalost musi patrit prihlasenemu uzivateli
    $udalost = vlastniUdalost($spojeni, $event_id, $user_id);

    // ----- Rucni pridani (prisel nazev) -----
    if (array_key_exists('nazev', $vstup)) {
        // Kontrola nazvu
        $nazev = zkontrolujNazevPodukolu(textZVstupu($vstup, 'nazev'));
        // Limit poctu podukolu jedne udalosti
        if (poctyPodukolu($spojeni, $event_id)['celkem'] >= MAX_PODUKOLU_UDALOSTI) {
            // Chyba 409
            odpovez(['chyba' => 'Událost už má maximum ' . MAX_PODUKOLU_UDALOSTI . ' podúkolů.'], 409);
        }
        // Nepovinny termin RRRR-MM-DD (null = bez terminu)
        $termin = null;
        // Termin prisel jako text
        if (isset($vstup['termin']) && is_string($vstup['termin'])) {
            // Kontrola formatu a existence data
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $vstup['termin'], $c) || !checkdate((int) $c[2], (int) $c[3], (int) $c[1])) {
                // Chyba 400
                odpovez(['chyba' => 'Neplatný termín podúkolu.'], 400);
            }
            // Termin v 00:00
            $termin = $vstup['termin'] . ' 00:00:00';
        }
        // Nepovinny odhad minut (1 az 1440), jinak null
        $odhad = null;
        // Odhad prisel jako cele cislo
        if (isset($vstup['odhad_minut']) && is_int($vstup['odhad_minut'])) {
            // Omezeni rozsahu
            $odhad = max(1, min(AI_MAX_MINUT, $vstup['odhad_minut']));
        }
        // Dulezite a splneno (vychozi ne)
        $dulezite = anoNeZVstupu($vstup, 'dulezite', false) ? 1 : 0;
        $splneno = anoNeZVstupu($vstup, 'splneno', false) ? 1 : 0;
        // Ulozeni
        zmenData($spojeni,
            'INSERT INTO subtasks (event_id, nazev, cas, odhad_minut, dulezite, splneno) VALUES (?, ?, ?, ?, ?, ?)',
            'issiii', [$event_id, $nazev, $termin, $odhad, $dulezite, $splneno]);
        // Vratime novy seznam (201 = vytvoreno)
        odpovezSeznamem($spojeni, $event_id, 201);
    }

    // ----- Generovani pomoci AI -----
    // Volani AI muze trvat desitky sekund - prodlouzime casovy limit skriptu
    set_time_limit(AI_TIMEOUT_S * AI_POKUSU + 30);
    // Nahradit stavajici podukoly? (jen presne true)
    $nahradit = ($vstup['nahradit'] ?? false) === true;

    // Bez nahrazeni smi generovat jen udalost bez podukolu (jinak by vznikly duplicity)
    if (!$nahradit && poctyPodukolu($spojeni, $event_id)['celkem'] > 0) {
        // Chyba 409 = konflikt se stavem dat
        odpovez(['chyba' => 'Událost už podúkoly má.'], 409);
    }

    // Bez API klice AI nefunguje
    if (!jeAiNastavena()) {
        // Chyba 503 = sluzba neni dostupna
        odpovez(['chyba' => 'AI není nastavená – chybí API klíč (' . aiNazevKlice() . ' nebo config.php).'], 503);
    }

    // Limity generovani (ochrana kreditu pred zneuzitim):
    // pocet generovani tohoto uzivatele za hodinu a vsech uzivatelu za 24 hodin
    $pocty = nactiRadek($spojeni,
        'SELECT
            SUM(user_id = ? AND cas > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS uzivatel,
            COUNT(*) AS vsichni
         FROM ai_generovani WHERE cas > DATE_SUB(NOW(), INTERVAL 1 DAY)',
        'i', [$user_id]);
    // Limity z nastaveni
    $limitHodina = konfigurace()['ai_limit_za_hodinu'];
    $limitDen = konfigurace()['ai_limit_za_den'];
    // Limit uzivatele vycerpan
    if ((int) $pocty['uzivatel'] >= $limitHodina) {
        // Chyba 429 = prilis mnoho pozadavku
        odpovez(['chyba' => 'Dosáhl/a jsi limitu ' . $limitHodina . ' generování za hodinu. Zkus to prosím později.'], 429);
    }
    // Denni limit cele aplikace vycerpan
    if ((int) $pocty['vsichni'] >= $limitDen) {
        // Chyba 429
        odpovez(['chyba' => 'AI má pro dnešek vyčerpaný limit generování. Zkus to prosím zítra.'], 429);
    }
    // Zapiseme pokus (pocita se i neuspesny - opakovane klikani take stoji kredit)
    zmenData($spojeni, 'INSERT INTO ai_generovani (user_id) VALUES (?)', 'i', [$user_id]);
    // Uklid: zaznamy starsi nez den uz nejsou potreba
    zmenData($spojeni, 'DELETE FROM ai_generovani WHERE cas < DATE_SUB(NOW(), INTERVAL 1 DAY)');

    // Zavolame AI - PRED jakoukoli zmenou v databazi (kdyz selze, stare podukoly zustanou)
    $vysledek = vygenerujPodukolyAi($udalost);
    // AI selhala -> srozumitelna chyba
    if (!$vysledek['ok']) {
        // HTTP kod a zprava pripravene v ai.php
        odpovez(['chyba' => $vysledek['chyba']], $vysledek['kod']);
    }

    // Dnesni datum a datum udalosti (pro vypocet terminu)
    $dnes = date('Y-m-d');
    $datumUdalosti = $udalost['datum'];

    // Ulozeni v transakci: bud se ulozi vse, nebo nic
    mysqli_begin_transaction($spojeni);
    // Zamkneme radek udalosti (FOR UPDATE) - soubezne generovani teze udalosti
    // musi pockat, takze nevzniknou duplicity; zaroven overime, ze udalost stale existuje
    if (nactiRadek($spojeni, 'SELECT id FROM events WHERE id = ? AND user_id = ? FOR UPDATE',
            'ii', [$event_id, $user_id]) === null) {
        // Udalost byla mezitim smazana
        mysqli_rollback($spojeni);
        // Chyba 404
        odpovez(['chyba' => 'Událost mezitím zmizela.'], 404);
    }
    // Mezitim vznikly podukoly jinym pozadavkem (napr. ve druhe zalozce)
    if (!$nahradit && poctyPodukolu($spojeni, $event_id)['celkem'] > 0) {
        // Nic neukladame
        mysqli_rollback($spojeni);
        // Chyba 409
        odpovez(['chyba' => 'Událost už mezitím podúkoly dostala.'], 409);
    }
    // Nahrazeni: smazeme stavajici podukoly
    if ($nahradit) {
        // Smazani vsech podukolu udalosti
        zmenData($spojeni, 'DELETE FROM subtasks WHERE event_id = ?', 'i', [$event_id]);
    }
    // Vlozime nove podukoly
    foreach ($vysledek['podukoly'] as $podukol) {
        // Termin = datum udalosti minus pocet dni predem
        $termin = date('Y-m-d', strtotime($datumUdalosti . ' -' . $podukol['dni_predem'] . ' days'));
        // U budouci udalosti nedava smysl termin v minulosti -> nejdrive dnes
        if ($datumUdalosti >= $dnes && $termin < $dnes) {
            // Termin dnes
            $termin = $dnes;
        }
        // Ulozeni podukolu (cas = termin v 00:00)
        zmenData($spojeni,
            'INSERT INTO subtasks (event_id, nazev, cas, odhad_minut, dulezite, splneno) VALUES (?, ?, ?, ?, ?, 0)',
            'issii', [$event_id, $podukol['nazev'], $termin . ' 00:00:00', $podukol['minuty'], $podukol['dulezite'] ? 1 : 0]);
    }
    // Potvrzeni transakce
    mysqli_commit($spojeni);

    // Vratime nove podukoly (201 = vytvoreno)
    odpovezSeznamem($spojeni, $event_id, 201);
}

// =====================================================================
// PUT - uprava nazvu, splneni nebo dulezitosti podukolu
// =====================================================================
if ($metoda === 'PUT') {
    // Prijata data
    $vstup = nactiJsonTelo();
    // Id podukolu
    $id = idZVstupu($vstup);

    // Podukol musi patrit k udalosti prihlaseneho uzivatele (JOIN pres events)
    $podukol = nactiRadek($spojeni,
        'SELECT s.id, s.event_id FROM subtasks s JOIN events e ON e.id = s.event_id
         WHERE s.id = ? AND e.user_id = ?',
        'ii', [$id, $user_id]);
    // Cizi nebo neexistujici podukol
    if ($podukol === null) {
        // Chyba 404
        odpovez(['chyba' => 'Podúkol nebyl nalezen (možná byl smazán).'], 404);
    }

    // Prislo neco ke zmene?
    $zmenaNazvu = array_key_exists('nazev', $vstup);
    $zmenaSplneni = array_key_exists('splneno', $vstup);
    $zmenaDulezitosti = array_key_exists('dulezite', $vstup);
    // Nic ke zmene -> chyba
    if (!$zmenaNazvu && !$zmenaSplneni && !$zmenaDulezitosti) {
        // Chyba 400
        odpovez(['chyba' => 'Není co změnit.'], 400);
    }

    // Nejdriv vsechny hodnoty zkontrolujeme, pak teprve ukladame (chyba nic nezmeni napul)
    $nazev = $zmenaNazvu ? zkontrolujNazevPodukolu(textZVstupu($vstup, 'nazev')) : null;
    $splneno = anoNeZVstupu($vstup, 'splneno', null);
    $dulezite = anoNeZVstupu($vstup, 'dulezite', null);

    // Zmena nazvu
    if ($zmenaNazvu) {
        // Ulozeni
        zmenData($spojeni, 'UPDATE subtasks SET nazev = ? WHERE id = ?', 'si', [$nazev, $id]);
    }
    // Zmena splneni
    if ($zmenaSplneni) {
        // Ulozeni (true -> 1, false -> 0)
        zmenData($spojeni, 'UPDATE subtasks SET splneno = ? WHERE id = ?', 'ii', [$splneno ? 1 : 0, $id]);
    }
    // Zmena dulezitosti (hvezdicka)
    if ($zmenaDulezitosti) {
        // Ulozeni
        zmenData($spojeni, 'UPDATE subtasks SET dulezite = ? WHERE id = ?', 'ii', [$dulezite ? 1 : 0, $id]);
    }

    // Vratime aktualni seznam a pocty
    odpovezSeznamem($spojeni, (int) $podukol['event_id']);
}

// =====================================================================
// DELETE - smazani jednoho podukolu, nebo vsech podukolu udalosti
// =====================================================================
// Sem se dostane jen metoda DELETE
// Prijata data
$vstup = nactiJsonTelo();

// Hromadne smazani vsech podukolu udalosti
if (($vstup['vse'] ?? false) === true) {
    // Id udalosti
    $event_id = idZVstupu($vstup, 'event_id');
    // Udalost musi patrit prihlasenemu uzivateli
    vlastniUdalost($spojeni, $event_id, $user_id);
    // Smazani vsech podukolu udalosti
    zmenData($spojeni, 'DELETE FROM subtasks WHERE event_id = ?', 'i', [$event_id]);
    // Vratime (prazdny) seznam a pocty
    odpovezSeznamem($spojeni, $event_id);
}

// Smazani jednoho podukolu
$id = idZVstupu($vstup);
// Nejdriv zjistime jeho udalost (a zaroven vlastnika)
$podukol = nactiRadek($spojeni,
    'SELECT s.event_id FROM subtasks s JOIN events e ON e.id = s.event_id WHERE s.id = ? AND e.user_id = ?',
    'ii', [$id, $user_id]);
// Cizi nebo neexistujici podukol
if ($podukol === null) {
    // Chyba 404
    odpovez(['chyba' => 'Podúkol nebyl nalezen (možná už byl smazán).'], 404);
}
// Smazani
zmenData($spojeni, 'DELETE FROM subtasks WHERE id = ?', 'i', [$id]);
// Vratime aktualni seznam a pocty
odpovezSeznamem($spojeni, (int) $podukol['event_id']);
