<?php
// =====================================================================
// auth.php - uzivatelske ucty: session, prihlaseni, odhlaseni,
// funkce "Zapamatovat si me", CSRF ochrana a omezeni pokusu o prihlaseni.
// Vklada se do vsech stranek i do api.php (require_once).
// =====================================================================

// Pripojeni k databazi a pomocne funkce pro dotazy
// (casove pasmo Europe/Prague nastavuje uz konfigurace.php, kterou db.php nacita)
require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------
// Konstanty
// ---------------------------------------------------------------------

// Nazev cookie pro "Zapamatovat si me"
const PAMATUJ_COOKIE = 'planodar_pamatuj';
// Jak dlouho plati "Zapamatovat si me" (dny)
const PAMATUJ_DNI = 30;
// Kolik sekund plati stary token po vymene - kdyz prohlizec po restartu obnovi
// nekolik zalozek naraz, vsechny poslou stejnou (starou) cookie a musi projit
const PAMATUJ_POSHOVKA_S = 60;
// Kolik neuspesnych pokusu o prihlaseni na jeden e-mail je povoleno...
const MAX_POKUSU_EMAIL = 5;
// ...a kolik z jedne IP adresy (vic, protoze za jednou IP muze byt cela skola)
const MAX_POKUSU_IP = 20;
// Za jak dlouhou dobu se pokusy pocitaji (minuty)
const BLOKACE_MINUT = 15;
// Minimalni delka hesla
const MIN_DELKA_HESLA = 8;
// Maximalni delka jmena (odpovida VARCHAR(100) v databazi)
const MAX_DELKA_JMENA = 100;
// Maximalni delka e-mailu (odpovida VARCHAR(255) v databazi)
const MAX_DELKA_EMAILU = 255;
// Po kolika sekundach necinnosti prihlaseni vyprsi (2 hodiny; "Zustat prihlasen/a" plati dal)
const NECINNOST_S = 7200;

// Algoritmus pro hashovani hesel: Argon2id (doporuceny, odolny proti lamani na grafickych
// kartach), kdyz ho PHP umi; jinak bcrypt s vyssi narocnosti (cost 12)
if (defined('PASSWORD_ARGON2ID')) {
    // Argon2id s vychozimi parametry PHP (64 MB pameti, 4 pruchody)
    define('ALGORITMUS_HESLA', PASSWORD_ARGON2ID);
    define('MOZNOSTI_HESLA', []);
    // Argon2 zpracuje i dlouha hesla; limit jen proti zahlceni serveru obrovskym textem
    define('MAX_BAJTU_HESLA', 1024);
    // "Falesny" hash pro neexistujici ucet - password_verify pak trva stejne dlouho
    // jako u existujiciho uctu, takze podle rychlosti odpovedi nejde poznat, ktere e-maily existuji
    define('FALESNY_HASH', '$argon2id$v=19$m=65536,t=4,p=1$M3NWdm1CbkwuYmUuSm94eQ$/urQp4I2lE0o0VbM/g6Odn5YlK2DMAFyWw1A/cEZk1I');
} else {
    // Zalozni varianta: bcrypt
    define('ALGORITMUS_HESLA', PASSWORD_BCRYPT);
    define('MOZNOSTI_HESLA', ['cost' => 12]);
    // bcrypt bere jen prvnich 72 bajtu - delsi heslo by se potichu oriznulo, proto ho odmitneme
    define('MAX_BAJTU_HESLA', 72);
    // Falesny hash se stejnou narocnosti (cost 12)
    define('FALESNY_HASH', '$2y$12$fUcV.hKYOwRaa8tFZQeUeO9Fab3nYH25M4RVb8DtMCIcgv4vW0hzq');
}

// Vytvori hash hesla (do databaze se uklada jen hash, nikdy heslo)
function hashHesla($heslo)
{
    // password_hash prida nahodnou "sul" - stejna hesla maji ruzne hashe
    return password_hash($heslo, ALGORITMUS_HESLA, MOZNOSTI_HESLA);
}

// ---------------------------------------------------------------------
// Bezpecnostni hlavicky - posilaji se s kazdou strankou i odpovedi API
// ---------------------------------------------------------------------
// Stranku nejde vlozit do <iframe> na cizim webu (ochrana proti "clickjackingu")
header('X-Frame-Options: DENY');
// Prohlizec nesmi hadat typ souboru (napr. spustit text jako skript)
header('X-Content-Type-Options: nosniff');
// Pri prechodu na cizi web se neposle adresa nasi stranky
header('Referrer-Policy: same-origin');
// Content Security Policy: odkud smi stranka nacitat skripty, styly, pisma...
// Skripty jen z naseho webu a z CDN Tailwindu - vlozeny cizi <script> se nespusti (ochrana proti XSS)
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' https://cdn.tailwindcss.com; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src 'self' https://fonts.gstatic.com; "
    . "img-src 'self' data:; connect-src 'self'; object-src 'none'; "
    . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
// Stranka nepotrebuje kameru, mikrofon ani polohu - zakazeme je
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
// Okno stranky se neda ovladat z ciziho okna, ktere ji otevrelo
header('Cross-Origin-Opener-Policy: same-origin');

// ---------------------------------------------------------------------
// Pomocne funkce
// ---------------------------------------------------------------------

// Bezi stranka pres HTTPS? (pak smi cookie putovat jen sifrovane)
function jeHttps()
{
    // Promennou HTTPS nastavuje webovy server; "off" pouziva IIS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        // Primo sifrovane spojeni
        return true;
    }
    // Za proxy hostingu (Render) konci HTTPS uz na proxy a ta nam posila obycejne HTTP;
    // puvodni protokol rika hlavicka X-Forwarded-Proto. Verime ji jen tehdy, kdyz je to
    // v nastaveni povolene - jinak by ji mohl podvrhnout kdokoli.
    return konfigurace()['duveryhodna_proxy']
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// Stranka bezi pres HTTPS -> prohlizec si to zapamatuje a priste uz HTTP vubec nezkusi (HSTS)
if (jeHttps()) {
    // Platnost 1 rok
    header('Strict-Transport-Security: max-age=31536000');
}

// Osetri text pro bezpecny vypis do HTML (ochrana proti XSS)
function e($text)
{
    // Prevede < > & " ' na HTML entity
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Presmeruje prohlizec na jinou stranku a ukonci skript
function presmeruj($adresa)
{
    // Hlavicka Location = "jdi sem"
    header('Location: ' . $adresa);
    // Nic dalsiho uz se nesmi vypsat
    exit;
}

// IP adresa navstevnika (pro omezeni pokusu o prihlaseni)
function ipAdresa()
{
    // Za proxy hostingu je REMOTE_ADDR adresa proxy (stejna pro vsechny navstevniky!);
    // skutecnou adresu klienta posila proxy jako prvni polozku hlavicky X-Forwarded-For
    if (konfigurace()['duveryhodna_proxy'] && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Prvni adresa v seznamu "klient, proxy1, proxy2"
        $prvni = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        // Pouzijeme ji jen kdyz je to platna IP adresa
        if (filter_var($prvni, FILTER_VALIDATE_IP) !== false) {
            // Adresa klienta
            return $prvni;
        }
    }
    // Bez proxy: REMOTE_ADDR nastavuje server a nelze ji podvrhnout hlavickou
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

// Upravi jmeno: orizne mezery a vicenasobne mezery nahradi jednou
function upravJmeno($jmeno)
{
    // \s+ = jeden nebo vice bilych znaku; u = prace s UTF-8
    return trim(preg_replace('/\s+/u', ' ', (string) $jmeno));
}

// Upravi e-mail: orizne mezery a prevede na mala pismena (Jan@X.cz = jan@x.cz)
function upravEmail($email)
{
    // mb_strtolower spravne prevede i ceske znaky
    return mb_strtolower(trim((string) $email), 'UTF-8');
}

// Zkontroluje jmeno; vrati text chyby, nebo prazdny text, kdyz je v poradku
function chybaJmena($jmeno)
{
    // Jmeno je povinne
    if ($jmeno === '') {
        // Text chyby
        return 'Vyplňte jméno.';
    }
    // Jmeno nesmi byt delsi nez sloupec v databazi
    if (mb_strlen($jmeno) > MAX_DELKA_JMENA) {
        // Text chyby
        return 'Jméno je příliš dlouhé (max. ' . MAX_DELKA_JMENA . ' znaků).';
    }
    // Vse v poradku
    return '';
}

// Zkontroluje e-mail; vrati text chyby, nebo prazdny text
function chybaEmailu($email)
{
    // E-mail je povinny
    if ($email === '') {
        // Text chyby
        return 'Vyplňte e-mail.';
    }
    // Delka a format (filter_var overi tvar jmeno@domena.cz)
    if (strlen($email) > MAX_DELKA_EMAILU || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        // Text chyby
        return 'Zadejte platný e-mail.';
    }
    // Vse v poradku
    return '';
}

// Zkontroluje nove heslo a jeho zopakovani; vrati text chyby, nebo prazdny text
function chybaNovehoHesla($heslo, $heslo2)
{
    // Minimalni delka (mb_strlen = pocet znaku, ne bajtu)
    if (mb_strlen($heslo) < MIN_DELKA_HESLA) {
        // Text chyby
        return 'Heslo musí mít alespoň ' . MIN_DELKA_HESLA . ' znaků.';
    }
    // Maximalni delka v bajtech (strlen = bajty; ceske znaky maji 2 bajty)
    if (strlen($heslo) > MAX_BAJTU_HESLA) {
        // Text chyby
        return 'Heslo je příliš dlouhé.';
    }
    // Obe zadani se musi shodovat (chrani pred preklepem)
    if ($heslo !== $heslo2) {
        // Text chyby
        return 'Hesla se neshodují.';
    }
    // Vse v poradku
    return '';
}

// Je e-mail uz pouzity jinym uctem? ($krome_id = vlastni ucet, ktery nevadi)
function jeEmailObsazeny($spojeni, $email, $krome_id = 0)
{
    // Hledame ucet se stejnym e-mailem, ale jinym id
    $radek = nactiRadek($spojeni, 'SELECT id FROM users WHERE email = ? AND id <> ?', 'si', [$email, $krome_id]);
    // Nalezeny radek = e-mail je obsazeny
    return $radek !== null;
}

// ---------------------------------------------------------------------
// Session (relace) - pamet serveru o prihlasenem uzivateli
// ---------------------------------------------------------------------

// Spusti session s bezpecnym nastavenim cookie
function spustSession()
{
    // Session uz bezi -> neni co delat
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Konec funkce
        return;
    }
    // Strict mode: server neprijme ID session, ktere sam nevytvoril (ochrana proti "session fixation")
    ini_set('session.use_strict_mode', '1');
    // ID session jen v cookie, nikdy v adrese stranky (adresa se muze dostat do logu nebo k cizim lidem)
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Bez aktivity vyprsi session az po 2 hodinach (vychozich 24 minut je na kalendar malo)
    ini_set('session.gc_maxlifetime', (string) NECINNOST_S);
    // Vlastni nazev cookie (nekoliduje s jinymi aplikacemi v htdocs)
    session_name('planodar_session');
    // Nastaveni cookie se session
    session_set_cookie_params([
        'lifetime' => 0,          // 0 = cookie zmizi po zavreni prohlizece
        'path'     => '/',        // Plati pro cely web
        'secure'   => jeHttps(),  // Pres HTTPS posilat jen sifrovane
        'httponly' => true,       // JavaScript cookie neprecte (ochrana pri XSS)
        'samesite' => 'Strict',   // Prohlizec cookie neposle s zadnym pozadavkem z ciziho webu (ochrana proti CSRF)
    ]);
    // Spustime session
    session_start();
}

// Vrati CSRF token teto session (vytvori ho, kdyz jeste neexistuje)
// CSRF = utok, kdy cizi web prinuti prohlizec odeslat formular na nas web.
// Kazdy formular i pozadavek API proto musi poslat tajny token, ktery cizi web nezna.
function csrfToken()
{
    // Token jeste neexistuje -> vytvorime nahodny (32 bajtu = 64 hex znaku)
    if (empty($_SESSION['csrf'])) {
        // random_bytes = kryptograficky bezpecna nahoda
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    // Vratime token
    return $_SESSION['csrf'];
}

// Overi CSRF token z formulare nebo z hlavicky pozadavku
function overCsrf($token)
{
    // hash_equals porovnava v konstantnim case (nejde hadat po znacich podle rychlosti)
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

// Skryte pole s CSRF tokenem do formulare
function csrfPole()
{
    // Vratime HTML skryteho pole
    return '<input type="hidden" name="csrf" value="' . e(csrfToken()) . '">';
}

// Ulozi jednorazovou zpravu (zobrazi se po presmerovani, pak zmizi)
function nastavZpravu($typ, $text)
{
    // $typ = 'ok' (zelena) nebo 'chyba' (cervena)
    $_SESSION['zprava'] = ['typ' => $typ, 'text' => $text];
}

// Vrati jednorazovou zpravu a smaze ji (nebo null, kdyz zadna neni)
function vezmiZpravu()
{
    // Precteme zpravu (?? = null, kdyz neexistuje)
    $zprava = $_SESSION['zprava'] ?? null;
    // Smazeme ji, aby se neukazala znovu
    unset($_SESSION['zprava']);
    // Vratime ji
    return $zprava;
}

// ---------------------------------------------------------------------
// Prihlaseni a odhlaseni
// ---------------------------------------------------------------------

// Nacte ucet podle id (nebo null, kdyz neexistuje)
function nactiUzivatele($spojeni, $id)
{
    // Vybereme jen potrebne sloupce
    return nactiRadek($spojeni,
        'SELECT id, jmeno, email, heslo_hash, verze_prihlaseni, vytvoreno FROM users WHERE id = ?',
        'i', [$id]);
}

// Zapise prihlaseneho uzivatele do session
function nastavSession($uzivatel)
{
    // Nove ID session po prihlaseni - stare ID (treba podstrcene utocnikem) prestane platit
    session_regenerate_id(true);
    // Id prihlaseneho uzivatele
    $_SESSION['user_id'] = (int) $uzivatel['id'];
    // Verze prihlaseni - kdyz se v databazi zvysi, tato session prestane platit
    $_SESSION['verze'] = (int) $uzivatel['verze_prihlaseni'];
    // Cas posledni aktivity (po 2 hodinach necinnosti prihlaseni vyprsi)
    $_SESSION['aktivita'] = time();
    // Novy CSRF token pro prihlasenou relaci (vytvori se pri dalsim pouziti)
    unset($_SESSION['csrf']);
}

// Prihlasi uzivatele; $pamatovat = true -> vytvori i dlouhodoby token
function prihlasUzivatele($spojeni, $uzivatel, $pamatovat)
{
    // Zapis do session
    nastavSession($uzivatel);
    // "Zapamatovat si me" je zaskrtnute
    if ($pamatovat) {
        // Vytvorime token a cookie
        vytvorPamatovaciToken($spojeni, (int) $uzivatel['id']);
    }
}

// Vytvori token "Zapamatovat si me" (radek v databazi + cookie v prohlizeci)
function vytvorPamatovaciToken($spojeni, $user_id)
{
    // Verejna cast tokenu - podle ni se v databazi hleda (12 bajtu = 24 hex znaku)
    $selector = bin2hex(random_bytes(12));
    // Tajna cast tokenu - do databaze se uklada jen jeji hash
    $validator = bin2hex(random_bytes(32));
    // Uklid: smazeme vsechny uz neplatne tokeny (aby tabulka nerostla do nekonecna)
    zmenData($spojeni, 'DELETE FROM prihlaseni_tokeny WHERE plati_do < NOW()');
    // Ulozime token; platnost spocita databaze (NOW() + 30 dni)
    zmenData($spojeni,
        'INSERT INTO prihlaseni_tokeny (user_id, selector, validator_hash, plati_do)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . PAMATUJ_DNI . ' DAY))',
        'iss', [$user_id, $selector, hash('sha256', $validator)]);
    // Cookie s obema castmi, oddelenymi dvojteckou
    setcookie(PAMATUJ_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + PAMATUJ_DNI * 24 * 60 * 60,  // Platnost v sekundach od ted
        'path'     => '/',                                 // Plati pro cely web
        'secure'   => jeHttps(),                           // Pres HTTPS jen sifrovane
        'httponly' => true,                                // JavaScript cookie neprecte
        'samesite' => 'Strict',                            // Ochrana proti CSRF
    ]);
}

// Smaze cookie "Zapamatovat si me" z prohlizece
function smazPamatovaciCookie()
{
    // Cookie se smaze nastavenim data vyprseni do minulosti
    setcookie(PAMATUJ_COOKIE, '', [
        'expires'  => time() - 3600,  // Hodina zpatky = ihned neplatna
        'path'     => '/',
        'secure'   => jeHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    // Odstranime ji i z promenne tohoto pozadavku
    unset($_COOKIE[PAMATUJ_COOKIE]);
}

// Rozdeli cookie "selector:validator" na casti; vrati pole, nebo null, kdyz ma spatny tvar
function rozdelPamatovaciCookie()
{
    // Cookie neexistuje nebo neni text
    if (!isset($_COOKIE[PAMATUJ_COOKIE]) || !is_string($_COOKIE[PAMATUJ_COOKIE])) {
        // Nic k rozdeleni
        return null;
    }
    // Rozdelime podle dvojtecky (max. 2 casti)
    $casti = explode(':', $_COOKIE[PAMATUJ_COOKIE], 2);
    // Kontrola tvaru: 24 + 64 hexadecimalnich znaku
    if (count($casti) !== 2 || !preg_match('/^[0-9a-f]{24}$/', $casti[0]) || !preg_match('/^[0-9a-f]{64}$/', $casti[1])) {
        // Podivna cookie -> ignorujeme ji
        return null;
    }
    // Vratime [selector, validator]
    return $casti;
}

// Pokusi se prihlasit uzivatele pomoci cookie "Zapamatovat si me"
function prihlasZCookie($spojeni)
{
    // Rozdelime cookie na casti
    $casti = rozdelPamatovaciCookie();
    // Cookie chybi nebo je poskozena
    if ($casti === null) {
        // Pripadnou poskozenou cookie smazeme
        if (isset($_COOKIE[PAMATUJ_COOKIE])) {
            // Smazani cookie
            smazPamatovaciCookie();
        }
        // Neprihlaseno
        return null;
    }
    // Rozbalime pole na dve promenne
    [$selector, $validator] = $casti;

    // Najdeme platny token podle verejne casti
    $token = nactiRadek($spojeni,
        'SELECT id, user_id, validator_hash FROM prihlaseni_tokeny WHERE selector = ? AND plati_do > NOW()',
        's', [$selector]);

    // Token neexistuje nebo vyprsel -> cookie jen ignorujeme.
    // Nemazeme ji: pri soubeznych pozadavcich by smazani prepsalo novou cookie,
    // kterou mezitim prohlizec dostal od jineho pozadavku.
    if ($token === null) {
        // Neprihlaseno
        return null;
    }

    // Tajna cast nesedi -> nekdo zkousi podvrzenou cookie; token radeji zrusime
    if (!hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        // Smazeme token z databaze
        zmenData($spojeni, 'DELETE FROM prihlaseni_tokeny WHERE id = ?', 'i', [$token['id']]);
        // Smazeme cookie
        smazPamatovaciCookie();
        // Neprihlaseno
        return null;
    }

    // Token se pri pouziti vymeni za novy ("rotace") - ukradena stara cookie tak
    // prestane fungovat. Stary token jeste kratce plati (poshovka pro soubezne zalozky);
    // LEAST zajisti, ze se jeho platnost jen zkrati, nikdy neprodlouzi.
    zmenData($spojeni,
        'UPDATE prihlaseni_tokeny
         SET plati_do = LEAST(plati_do, DATE_ADD(NOW(), INTERVAL ' . PAMATUJ_POSHOVKA_S . ' SECOND))
         WHERE id = ?',
        'i', [$token['id']]);
    // Nacteme ucet
    $uzivatel = nactiUzivatele($spojeni, (int) $token['user_id']);
    // Ucet mezitim zmizel
    if ($uzivatel === null) {
        // Smazeme cookie
        smazPamatovaciCookie();
        // Neprihlaseno
        return null;
    }
    // Prihlasime uzivatele a vydame novy token
    prihlasUzivatele($spojeni, $uzivatel, true);
    // Vratime ucet
    return $uzivatel;
}

// Vrati prave prihlaseneho uzivatele (pole), nebo null
function prihlasenyUzivatel($spojeni)
{
    // static = hodnota si zustane mezi volanimi (ucet se nacte jen jednou za pozadavek)
    static $uzivatel = false;
    // Uz jsme ucet v tomto pozadavku zjistovali -> vratime vysledek
    if ($uzivatel !== false) {
        // Drive zjisteny vysledek
        return $uzivatel;
    }

    // V session je ulozeny prihlaseny uzivatel
    if (isset($_SESSION['user_id'])) {
        // Prilis dlouha necinnost? (gc_maxlifetime maze stare session jen nahodne, proto
        // dobu kontrolujeme sami - zapomenuty prihlaseny pocitac ve skole se sam odhlasi)
        $necinny = time() - (int) ($_SESSION['aktivita'] ?? 0) > NECINNOST_S;
        // Nacteme ucet z databaze (mohl byt mezitim smazan); po necinnosti uz ne
        $ucet = $necinny ? null : nactiUzivatele($spojeni, (int) $_SESSION['user_id']);
        // Ucet existuje a verze prihlaseni sedi (nikdo ho mezitim neodhlasil "vsude")
        if ($ucet !== null && (int) $ucet['verze_prihlaseni'] === (int) ($_SESSION['verze'] ?? 0)) {
            // Zaznamename aktivitu
            $_SESSION['aktivita'] = time();
            // Zapamatujeme a vratime
            $uzivatel = $ucet;
            // Vratime ucet
            return $uzivatel;
        }
        // Session uz neplati -> vymazeme z ni prihlaseni
        unset($_SESSION['user_id'], $_SESSION['verze'], $_SESSION['aktivita']);
    }

    // Posledni moznost: cookie "Zapamatovat si me"
    $uzivatel = prihlasZCookie($spojeni);
    // Vratime ucet, nebo null
    return $uzivatel;
}

// Odhlasi uzivatele na tomto zarizeni
function odhlasUzivatele($spojeni)
{
    // Pokud ma prohlizec cookie "Zapamatovat si me", smazeme i jeji token v databazi
    $casti = rozdelPamatovaciCookie();
    // Cookie existuje a ma spravny tvar
    if ($casti !== null) {
        // Smazeme token podle verejne casti
        zmenData($spojeni, 'DELETE FROM prihlaseni_tokeny WHERE selector = ?', 's', [$casti[0]]);
    }
    // Smazeme cookie z prohlizece
    smazPamatovaciCookie();
    // Vyprazdnime session (prihlaseni, CSRF token, zpravy...)
    $_SESSION = [];
    // Nove ID session - stare uz k nicemu nepouzije ani pripadny zlodej
    session_regenerate_id(true);
}

// Zneplatni vsechna prihlaseni uctu (vsechna zarizeni, vcetne "Zapamatovat si me")
// Vrati novou verzi prihlaseni
function zneplatniVsechnaPrihlaseni($spojeni, $user_id)
{
    // Zvysime verzi -> vsechny session s puvodni verzi prestanou platit
    zmenData($spojeni, 'UPDATE users SET verze_prihlaseni = verze_prihlaseni + 1 WHERE id = ?', 'i', [$user_id]);
    // Smazeme vsechny tokeny "Zapamatovat si me"
    zmenData($spojeni, 'DELETE FROM prihlaseni_tokeny WHERE user_id = ?', 'i', [$user_id]);
    // Nacteme novou verzi
    $radek = nactiRadek($spojeni, 'SELECT verze_prihlaseni FROM users WHERE id = ?', 'i', [$user_id]);
    // Vratime ji jako cislo
    return (int) $radek['verze_prihlaseni'];
}

// Stranka jen pro prihlasene: vrati ucet, nebo presmeruje na prihlaseni
function vyzadujPrihlaseni($spojeni)
{
    // Zjistime prihlaseneho uzivatele
    $uzivatel = prihlasenyUzivatel($spojeni);
    // Nikdo neni prihlasen
    if ($uzivatel === null) {
        // Prisel z odkazu na cizim webu? Cookies se SameSite=Strict prohlizec v takovem
        // pozadavku neposle, takze uzivatel vypada neprihlaseny, i kdyz neni.
        // Stranku proto jednou nechame obnovit - obnoveni uz je pozadavek z naseho webu
        // a cookies se poslou. (Sec-Fetch-* hlavicky posila prohlizec sam, nejdou zmenit skriptem.)
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site'
            && ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate') {
            // Mini stranka, ktera se hned obnovi (meta refresh nepotrebuje JavaScript)
            header('Content-Type: text/html; charset=utf-8');
            // Obsah stranky
            echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8">'
                . '<meta http-equiv="refresh" content="0"><title>Plánodář</title></head>'
                . '<body>Načítám…</body></html>';
            // Konec skriptu
            exit;
        }
        // Jinak na prihlasovaci stranku
        presmeruj('prihlaseni.php');
    }
    // Vratime ucet
    return $uzivatel;
}

// ---------------------------------------------------------------------
// Ochrana proti hadani hesel
// ---------------------------------------------------------------------

// Je prihlaseni pro tento e-mail nebo IP docasne zablokovane?
function jePrihlaseniZablokovane($spojeni, $email, $ip)
{
    // Spocitame neuspesne pokusy na e-mail a z IP za poslednich 15 minut
    $radek = nactiRadek($spojeni,
        'SELECT
            SUM(email = ?) AS na_email,
            SUM(ip = ?) AS z_ip
         FROM pokusy_prihlaseni
         WHERE cas > DATE_SUB(NOW(), INTERVAL ' . BLOKACE_MINUT . ' MINUTE)',
        'ss', [$email, $ip]);
    // SUM vrati NULL, kdyz zadne pokusy nejsou -> (int) z toho udela 0
    return (int) $radek['na_email'] >= MAX_POKUSU_EMAIL || (int) $radek['z_ip'] >= MAX_POKUSU_IP;
}

// Zapise neuspesny pokus o prihlaseni
function zapisNeuspesnyPokus($spojeni, $email, $ip)
{
    // Novy zaznam o pokusu
    zmenData($spojeni, 'INSERT INTO pokusy_prihlaseni (email, ip) VALUES (?, ?)', 'ss', [$email, $ip]);
    // Uklid: zaznamy starsi nez den uz nejsou potreba
    zmenData($spojeni, 'DELETE FROM pokusy_prihlaseni WHERE cas < DATE_SUB(NOW(), INTERVAL 1 DAY)');
}

// Po uspesnem prihlaseni smaze neuspesne pokusy pro dany e-mail
function smazPokusy($spojeni, $email)
{
    // Smazani zaznamu
    zmenData($spojeni, 'DELETE FROM pokusy_prihlaseni WHERE email = ?', 's', [$email]);
}

// Session spustime hned pri vlozeni tohoto souboru - potrebuje ji kazda stranka
spustSession();
