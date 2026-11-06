<?php
// =====================================================================
// konfigurace.php - nacteni nastaveni aplikace na jednom miste
//
// Poradi (posledni vyhrava):
//   1) vychozi hodnoty nize (funguji v XAMPP bez dalsiho nastaveni),
//   2) soubor config.php (lokalne sem patri API klic; soubor neni v gitu),
//   3) promenne prostredi (nastavuje je Docker nebo hosting Render,
//      seznam je v souboru .env.example).
// Tajne udaje (API klic, heslo databaze) tak nikdy nejsou v kodu na GitHubu.
// =====================================================================

// Casove pasmo Praha - "dnes", terminy podukolu i cas v databazi odpovidaji ceskemu casu
// (nastavuje se tady, protoze tento soubor se nacita jako uplne prvni)
date_default_timezone_set('Europe/Prague');

// Vrati pole se vsemi nastavenimi (nacte se jen jednou za pozadavek)
function konfigurace()
{
    // static = vysledek si pamatujeme mezi volanimi funkce
    static $nastaveni = null;
    // Uz nacteno -> vratime
    if ($nastaveni !== null) {
        // Drive nactene nastaveni
        return $nastaveni;
    }

    // 1) Vychozi hodnoty
    $vychozi = [
        'db_host'   => 'localhost',          // Server databaze (XAMPP)
        'db_port'   => 3306,                 // Port databaze (MySQL ma standardne 3306)
        'db_user'   => 'root',               // Uzivatel databaze (XAMPP)
        'db_heslo'  => '',                   // Heslo databaze (XAMPP: prazdne)
        'db_nazev'  => 'planodar',           // Nazev databaze
        'db_ssl'    => false,                // true = sifrovane spojeni s databazi (cloudova databaze)
        'db_ssl_ca' => '',                   // Certifikat autority databaze (text PEM) pro overeni serveru
        'groq_api_key'      => '',           // API klic pro Groq - ZDARMA (zacina "gsk_")
        'anthropic_api_key' => '',           // API klic pro Claude - placeny (zacina "sk-ant-")
        'ai_poskytovatel'   => 'auto',       // auto (podle vyplneneho klice, prednost ma Groq) / groq / claude
        'ai_model'  => '',                   // Prazdne = vychozi model poskytovatele (viz ai.php)
        'ai_effort' => 'low',                // Jak dukladne model premysli (low = nejmene tokenu)
        'ai_limit_za_hodinu' => 20,          // Kolikrat smi jeden uzivatel generovat za hodinu
        'ai_limit_za_den'    => 200,         // Kolikrat smi generovat VSICHNI dohromady za 24 hodin (pojistka kreditu)
        'registrace_povolena' => true,       // false = nove ucty nejdou zalozit
        'duveryhodna_proxy'   => false,      // true = aplikace bezi za proxy hostingu (Render) - viz auth.php
    ];

    // 2) Soubor config.php (pokud existuje); musi vracet pole
    $soubor = __DIR__ . '/config.php';
    // require vrati hodnotu z "return [...]" v souboru
    $vlastni = is_file($soubor) ? (require $soubor) : [];
    // Pokazeny soubor (nevraci pole) radeji ignorujeme
    if (!is_array($vlastni)) {
        // Prazdne nastaveni
        $vlastni = [];
    }
    // Prevezmeme jen zname klice (preklep v nazvu klice nic nerozbije)
    $nastaveni = array_merge($vychozi, array_intersect_key($vlastni, $vychozi));

    // 3) Promenne prostredi - prepisi hodnoty ze souboru
    $prostredi = [
        'DB_HOST' => 'db_host',
        'DB_PORT' => 'db_port',
        'DB_USER' => 'db_user',
        'DB_PASS' => 'db_heslo',
        'DB_NAME' => 'db_nazev',
        'DB_SSL'  => 'db_ssl',
        'DB_SSL_CA' => 'db_ssl_ca',
        'GROQ_API_KEY'        => 'groq_api_key',
        'ANTHROPIC_API_KEY'   => 'anthropic_api_key',
        'AI_POSKYTOVATEL'     => 'ai_poskytovatel',
        'AI_MODEL'            => 'ai_model',
        'AI_EFFORT'           => 'ai_effort',
        'AI_LIMIT_ZA_HODINU'  => 'ai_limit_za_hodinu',
        'AI_LIMIT_ZA_DEN'     => 'ai_limit_za_den',
        'REGISTRACE_POVOLENA' => 'registrace_povolena',
        'DUVERYHODNA_PROXY'   => 'duveryhodna_proxy',
    ];
    // Projdeme vsechny podporovane promenne
    foreach ($prostredi as $promenna => $klic) {
        // getenv vrati false, kdyz promenna neexistuje
        $hodnota = getenv($promenna);
        // Prazdnou hodnotu ignorujeme - jinak by prazdna promenna v Dockeru smazala klic z config.php
        // (vyjimka: heslo databaze muze byt prazdne)
        if ($hodnota !== false && ($hodnota !== '' || $promenna === 'DB_PASS')) {
            // Prepiseme nastaveni
            $nastaveni[$klic] = $hodnota;
        }
    }

    // Sjednoceni typu (z promennych prostredi vzdy prijde text)
    // Cela cisla
    foreach (['db_port', 'ai_limit_za_hodinu', 'ai_limit_za_den'] as $klic) {
        // (int) z textu "3306" udela cislo 3306
        $nastaveni[$klic] = (int) $nastaveni[$klic];
    }
    // Ano/ne hodnoty: "1", "true", "ano", "yes", "on" = true
    foreach (['db_ssl', 'registrace_povolena', 'duveryhodna_proxy'] as $klic) {
        // Hodnota z config.php uz muze byt true/false
        $hodnota = $nastaveni[$klic];
        // Text prevedeme podle seznamu
        $nastaveni[$klic] = is_bool($hodnota)
            ? $hodnota
            : in_array(strtolower(trim((string) $hodnota)), ['1', 'true', 'ano', 'yes', 'on'], true);
    }
    // Klice ocistime od mezer a odradkovani (casto se zkopiruji s nimi)
    $nastaveni['groq_api_key'] = trim((string) $nastaveni['groq_api_key']);
    $nastaveni['anthropic_api_key'] = trim((string) $nastaveni['anthropic_api_key']);
    // Text voleb sjednotime (napr. "Groq" -> "groq")
    $nastaveni['ai_poskytovatel'] = strtolower(trim((string) $nastaveni['ai_poskytovatel']));
    $nastaveni['ai_model'] = trim((string) $nastaveni['ai_model']);
    // Certifikat: nektera rozhrani ulozi odradkovani jako text "\n" -> vratime skutecna odradkovani
    $nastaveni['db_ssl_ca'] = trim(str_replace('\n', "\n", (string) $nastaveni['db_ssl_ca']));
    // Vratime nastaveni
    return $nastaveni;
}

// Ktery poskytovatel AI se pouzije: 'groq' (zdarma) nebo 'claude' (placeny)
function aiPoskytovatel()
{
    // Nastaveni
    $k = konfigurace();
    // Poskytovatel zvoleny vyslovne
    if ($k['ai_poskytovatel'] === 'groq' || $k['ai_poskytovatel'] === 'claude') {
        // Vyslovna volba
        return $k['ai_poskytovatel'];
    }
    // "auto": prednost ma bezplatny Groq, pokud je jeho klic vyplneny
    return str_starts_with($k['groq_api_key'], 'gsk_') ? 'groq' : 'claude';
}

// Je AI nastavena (vyplneny API klic zvoleneho poskytovatele)?
function jeAiNastavena()
{
    // Klic Groq zacina "gsk_", klic Anthropic "sk-ant-"; jina hodnota (napr. vzorovy text) se nepocita
    return aiPoskytovatel() === 'groq'
        ? str_starts_with(konfigurace()['groq_api_key'], 'gsk_')
        : str_starts_with(konfigurace()['anthropic_api_key'], 'sk-ant-');
}
