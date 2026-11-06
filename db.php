<?php
// =====================================================================
// db.php - pripojeni k databazi MySQL (proceduralni mysqli)
// a pomocne funkce pro bezpecne SQL dotazy (prepared statements).
// Tento soubor se vklada do dalsich skriptu pomoci require_once.
//
// Chyby databaze se resi na jednom miste - ve funkci selhaniDatabaze():
// api.php (ktere si predem nadefinuje konstantu REZIM_API) dostane
// chybu jako JSON, bezne stranky jako HTML.
// =====================================================================

// Nastaveni aplikace (vychozi hodnoty XAMPP, config.php, promenne prostredi Dockeru/Renderu)
require_once __DIR__ . '/konfigurace.php';
// Automaticka instalace a aktualizace tabulek (funkce zajistiSchema)
require_once __DIR__ . '/schema.php';

// Prihlasovaci udaje k databazi z nastaveni
$db_host  = konfigurace()['db_host'];    // Adresa databazoveho serveru
$db_port  = konfigurace()['db_port'];    // Port (MySQL standardne 3306, cloud casto jiny)
$db_user  = konfigurace()['db_user'];    // Uzivatelske jmeno (v XAMPP "root")
$db_heslo = konfigurace()['db_heslo'];   // Heslo (v XAMPP prazdne)
$db_nazev = konfigurace()['db_nazev'];   // Nazev databaze

// ---------------------------------------------------------------------
// Ukonci skript s chybovou odpovedi (JSON pro API, HTML pro stranky)
//   $detail      - presny duvod chyby (zapise se do logu serveru)
//   $ukazDetail  - true = duvod ukazat i uzivateli (napr. "Unknown database")
// ---------------------------------------------------------------------
function selhaniDatabaze($detail, $ukazDetail = false)
{
    // Presny duvod zapiseme do logu (Apache: logs/error.log, Docker: docker compose logs)
    error_log('Planodar - chyba databaze: ' . $detail);
    // Uzivateli ukazeme detail jen kdyz je to bezpecne; jinak obecnou zpravu
    // (texty chyb SQL by prozradily strukturu databaze)
    $zprava = $ukazDetail ? $detail : 'Chyba databáze. Zkuste to prosím znovu.';
    // HTTP kod 500 = chyba serveru
    http_response_code(500);

    // API: odpoved ve formatu JSON
    if (defined('REZIM_API')) {
        // Hlavicka JSON
        header('Content-Type: application/json; charset=utf-8');
        // Vadne znaky (napr. hlaska Windows v cp1250) nerozbiji JSON diky SUBSTITUTE
        echo json_encode(['chyba' => $zprava], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        // Konec skriptu
        exit;
    }

    // Bezna stranka: jednoducha HTML stranka ve stylu aplikace
    header('Content-Type: text/html; charset=utf-8');
    // htmlspecialchars zabrani vlozeni HTML z textu chyby
    $bezpecnaZprava = htmlspecialchars($zprava, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Vypiseme stranku (style.css dodava barvy a papir)
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>Plánodář – chyba</title><link rel="stylesheet" href="style.css"></head>'
        . '<body class="papir" style="padding:3rem 1rem"><div class="hlaska-chyba" style="max-width:32rem;margin:auto">'
        . '<p>' . $bezpecnaZprava . '</p>'
        . '<p style="font-weight:400;margin-top:.5rem">Běží MySQL a sedí přihlašovací údaje k databázi '
        . '(config.php nebo proměnné prostředí)? Tabulky si aplikace vytvoří sama. '
        . 'Máš-li databázi ze starší verze aplikace (bez účtů), spusť migrace_ucty.sql.</p>'
        // Odkaz zpet - uzivatel nesmi uviznout na chybove strance
        . '<p style="margin-top:.75rem"><a href="index.php" class="odkaz">Zkusit znovu</a></p>'
        . '</div></body></html>';
    // Konec skriptu
    exit;
}

// Ulozi certifikat autority databaze (text PEM z nastaveni) do docasneho souboru
// - funkce mysqli_ssl_set potrebuje cestu k souboru, ne text. Vrati cestu.
function souborCertifikatu($pem)
{
    // Nazev podle otisku obsahu - jiny certifikat = jiny soubor
    $soubor = sys_get_temp_dir() . '/planodar-db-ca-' . md5($pem) . '.pem';
    // Soubor jeste neexistuje -> zapiseme ho (certifikat autority neni tajny)
    if (!is_file($soubor)) {
        // Zapis; @ = chybu (napr. plny disk) resi az pripojeni
        @file_put_contents($soubor, $pem . "\n");
    }
    // Vratime cestu
    return $soubor;
}

// Vypne automaticke vyhazovani vyjimek z mysqli (PHP 8.1+ je jinak vyhazuje)
// - chyby si osetrujeme sami pomoci podminek
mysqli_report(MYSQLI_REPORT_OFF);

// Priprava spojeni (mysqli_init umozni nastavit sifrovani a casovy limit pred pripojenim)
$spojeni = mysqli_init();
// Na pripojeni cekame max. 10 sekund (nedostupny server nesmi stranku "zaseknout")
mysqli_options($spojeni, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
// Priznaky pripojeni (0 = bezne nesifrovane spojeni, napr. XAMPP na vlastnim pocitaci)
$priznaky = 0;
// Cloudova databaze: spojeni pres internet musi byt sifrovane (TLS)
if (konfigurace()['db_ssl']) {
    // Certifikat autority z nastaveni -> server databaze se overi (nikdo se za nej nevyda)
    $ca = konfigurace()['db_ssl_ca'];
    // Pouzijeme ho, pokud je vyplneny
    if ($ca !== '') {
        // Klient se overi certifikatem autority
        mysqli_ssl_set($spojeni, null, null, souborCertifikatu($ca), null, null);
        // Sifrovane spojeni s overenim serveru
        $priznaky = MYSQLI_CLIENT_SSL;
    } else {
        // Bez certifikatu: spojeni je sifrovane, ale server se neoveri (horsi varianta)
        $priznaky = MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
}

// Pokus o pripojeni k databazi; pri neuspechu vrati false
// Zavinac @ potlaci PHP varovani - chybu zobrazime sami srozumitelne
$pripojeno = @mysqli_real_connect($spojeni, $db_host, $db_user, $db_heslo, $db_nazev, $db_port, null, $priznaky);

// Bez databaze aplikace nefunguje -> ukoncime skript s vysvetlenim
if (!$pripojeno) {
    // Duvod ukazeme jen pri vyvoji (display_errors zapnute v XAMPP); na serveru
    // by prozradil adresu databaze - tam je jen v logu
    selhaniDatabaze('Nepodařilo se připojit k databázi: ' . mysqli_connect_error(), (bool) ini_get('display_errors'));
}

// Nastavime kodovani komunikace s databazi na utf8mb4 (spravne ceske znaky)
mysqli_set_charset($spojeni, 'utf8mb4');
// Casove pasmo databaze = ceske (date('P') = posun od UTC, napr. "+01:00", v lete "+02:00");
// NOW() v SQL pak vraci stejny cas jako PHP, i kdyz cloudova databaze bezi v UTC
mysqli_query($spojeni, "SET time_zone = '" . date('P') . "'");
// Tabulky existuji a maji vsechny sloupce (pri prvnim spusteni je vytvori)
zajistiSchema($spojeni);

// ---------------------------------------------------------------------
// Pripravi a spusti SQL dotaz s parametry (ochrana proti SQL injection).
//   $sql        - dotaz s otazniky misto hodnot, napr. "SELECT ... WHERE id = ?"
//   $typy       - typy parametru: s = text, i = cele cislo (napr. "si")
//   $parametry  - pole hodnot ve stejnem poradi jako otazniky
//   $povolenaChyba - cislo chyby MySQL, ktera neni fatalni (napr. 1062 =
//                 duplicitni hodnota); pri ni funkce vrati false
// Vrati spusteny dotaz (mysqli_stmt). Pri jine chybe ukonci skript.
// ---------------------------------------------------------------------
function spustDotaz($spojeni, $sql, $typy = '', $parametry = [], $povolenaChyba = 0)
{
    // Pripravime dotaz na serveru databaze
    $dotaz = mysqli_prepare($spojeni, $sql);
    // Dotaz nejde pripravit (preklep v SQL, chybejici tabulka...)
    if ($dotaz === false) {
        // Ukoncime skript s chybou
        selhaniDatabaze(mysqli_error($spojeni));
    }
    // Pokud ma dotaz parametry, navazeme je na otazniky
    if ($typy !== '') {
        // ... rozbali pole na jednotlive argumenty funkce
        mysqli_stmt_bind_param($dotaz, $typy, ...$parametry);
    }
    // Spustime dotaz
    if (!mysqli_stmt_execute($dotaz)) {
        // Povolena chyba (napr. duplicitni e-mail) -> vratime false, volajici ji osetri
        if ($povolenaChyba !== 0 && mysqli_stmt_errno($dotaz) === $povolenaChyba) {
            // Uvolnime dotaz z pameti
            mysqli_stmt_close($dotaz);
            // Signal pro volajiciho
            return false;
        }
        // Jina chyba -> ukoncime skript
        selhaniDatabaze(mysqli_stmt_error($dotaz));
    }
    // Vratime spusteny dotaz
    return $dotaz;
}

// Spusti SELECT a vrati vsechny radky jako pole asociativnich poli
function nactiRadky($spojeni, $sql, $typy = '', $parametry = [])
{
    // Spustime dotaz (pri chybe skript skonci uvnitr funkce)
    $dotaz = spustDotaz($spojeni, $sql, $typy, $parametry);
    // Ziskame vysledek dotazu
    $vysledek = mysqli_stmt_get_result($dotaz);
    // Vsechny radky najednou; MYSQLI_ASSOC = klice podle nazvu sloupcu
    $radky = mysqli_fetch_all($vysledek, MYSQLI_ASSOC);
    // Uvolnime dotaz z pameti
    mysqli_stmt_close($dotaz);
    // Vratime radky (prazdne pole, kdyz nic neodpovida)
    return $radky;
}

// Spusti SELECT a vrati prvni radek, nebo null, kdyz zadny neexistuje
function nactiRadek($spojeni, $sql, $typy = '', $parametry = [])
{
    // Nacteme vsechny radky
    $radky = nactiRadky($spojeni, $sql, $typy, $parametry);
    // ?? vrati null, kdyz radek s indexem 0 neexistuje
    return $radky[0] ?? null;
}

// Spusti INSERT / UPDATE / DELETE a vrati pocet zmenenych radku
// (nebo false pri povolene chybe, viz spustDotaz)
function zmenData($spojeni, $sql, $typy = '', $parametry = [], $povolenaChyba = 0)
{
    // Spustime dotaz
    $dotaz = spustDotaz($spojeni, $sql, $typy, $parametry, $povolenaChyba);
    // Povolena chyba -> predame false dal
    if ($dotaz === false) {
        // Signal pro volajiciho
        return false;
    }
    // Pocet radku, kterych se dotaz tykal
    $pocet = mysqli_stmt_affected_rows($dotaz);
    // Uvolnime dotaz z pameti
    mysqli_stmt_close($dotaz);
    // Vratime pocet
    return $pocet;
}
