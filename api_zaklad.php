<?php
// =====================================================================
// api_zaklad.php - spolecny zaklad vsech API souboru (api.php, podukoly.php)
//   - odpovedi ve formatu JSON,
//   - povolene metody GET, POST, PUT, DELETE,
//   - jen pro prihlasene (jinak 401),
//   - zmeny dat jen s platnym CSRF tokenem v hlavicce X-CSRF-Token (jinak 403),
//   - pomocne funkce pro cteni vstupu.
// Po vlozeni tohoto souboru je k dispozici $metoda, $uzivatel a $user_id.
// =====================================================================

// Konstanta rika db.php, ze chyby databaze ma vracet jako JSON (ne jako HTML)
const REZIM_API = true;

// PHP varovani nevypisujeme do odpovedi - rozbila by JSON (zapisuji se do logu serveru)
ini_set('display_errors', '0');
// Vsechny odpovedi budou ve formatu JSON v kodovani UTF-8
header('Content-Type: application/json; charset=utf-8');
// Osobni data se nesmi ukladat do mezipameti prohlizece ani proxy
header('Cache-Control: no-store');

// Ucty, session a databaze (vytvori promennou $spojeni)
require_once __DIR__ . '/auth.php';

// ---------------------------------------------------------------------
// Pomocne funkce
// ---------------------------------------------------------------------

// Odesle JSON odpoved s danym HTTP kodem a ukonci skript
function odpovez($data, $kod = 200)
{
    // Nastavime HTTP stavovy kod (200 = OK, 201 = vytvoreno, 400 = spatny pozadavek...)
    http_response_code($kod);
    // JSON_UNESCAPED_UNICODE = ceske znaky zustanou citelne,
    // JSON_INVALID_UTF8_SUBSTITUTE = vadny znak nerozbije celou odpoved
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    // Ukoncime skript, aby se uz nic dalsiho nevypsalo
    exit;
}

// Precte telo pozadavku jako JSON; kdyz to nejde, ukonci skript chybou 400
function nactiJsonTelo()
{
    // Surove telo pozadavku (JavaScript posila JSON text) prevedeme na pole
    $vstup = json_decode(file_get_contents('php://input'), true);
    // Pokud JSON nesel precist, jde o chybny pozadavek
    if (!is_array($vstup)) {
        // Vratime chybu 400
        odpovez(['chyba' => 'Neplatná data (očekáván JSON).'], 400);
    }
    // Vratime prectena data
    return $vstup;
}

// Bezpecne precte textovou hodnotu; vrati orezany text, nebo '' (chybi / neni text)
function textZVstupu($vstup, $klic)
{
    // Pokud klic neexistuje nebo hodnota neni text (napr. pole), vratime prazdny text
    if (!isset($vstup[$klic]) || !is_string($vstup[$klic])) {
        // Prazdny text pozdeji neprojde kontrolou povinnych poli
        return '';
    }
    // Odstranime mezery na zacatku a na konci
    return trim($vstup[$klic]);
}

// Precte kladne cele cislo (id); pri neplatne hodnote ukonci skript chybou 400
function idZVstupu($vstup, $klic = 'id')
{
    // Id musi byt kladne cele cislo (JSON cislo, ne text)
    if (!isset($vstup[$klic]) || !is_int($vstup[$klic]) || $vstup[$klic] <= 0) {
        // Vratime chybu 400
        odpovez(['chyba' => 'Neplatné id.'], 400);
    }
    // Vratime id
    return $vstup[$klic];
}

// ---------------------------------------------------------------------
// Overeni metody, prihlaseni a CSRF tokenu
// ---------------------------------------------------------------------

// Jakou HTTP metodou klient prisel (GET, POST, ...)
$metoda = $_SERVER['REQUEST_METHOD'];

// Podporujeme jen tyto ctyri metody
if (!in_array($metoda, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    // Hlavicka Allow rika klientovi, ktere metody jsou povolene
    header('Allow: GET, POST, PUT, DELETE');
    // Chyba 405 = metoda neni povolena
    odpovez(['chyba' => 'Metoda není podporována.'], 405);
}

// Prihlaseny uzivatel (nebo null)
$uzivatel = prihlasenyUzivatel($spojeni);
// Neprihlaseny -> 401; JavaScript podle toho presmeruje na prihlaseni
if ($uzivatel === null) {
    // Chyba 401 = neprihlaseno
    odpovez(['chyba' => 'Nejste přihlášeni.', 'prihlaseni' => true], 401);
}
// Id prihlaseneho uzivatele - pouziva se ve vsech dotazech
$user_id = (int) $uzivatel['id'];

// Zmeny dat musi nest CSRF token (cizi web ho nezna, takze nemuze nic zmenit za uzivatele)
if ($metoda !== 'GET' && !overCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    // Chyba 403 = zakazano
    odpovez(['chyba' => 'Platnost stránky vypršela. Obnovte ji prosím (F5).'], 403);
}

// Session uz nebudeme menit -> uvolnime ji (soubezne pozadavky ze stejneho
// prohlizece necekaji - dulezite hlavne behem dlouheho volani AI)
session_write_close();
