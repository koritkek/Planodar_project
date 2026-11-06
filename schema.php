<?php
// =====================================================================
// schema.php - automaticka instalace a aktualizace databaze
//
// Funkce zajistiSchema() zajisti, ze databaze obsahuje vsechny tabulky
// a sloupce, ktere aplikace potrebuje:
//   1) spusti prikazy CREATE TABLE IF NOT EXISTS ze souboru setup.sql
//      (existujici tabulky a data zustanou beze zmeny),
//   2) do starsi databaze doplni nove sloupce (ALTER TABLE).
// Diky tomu na hostingu neni potreba rucne importovat SQL.
//
// Aby se kontrola nedelala pri kazdem pozadavku, zapise se po uspechu
// do docasne slozky "znacka". Dokud znacka existuje, nic se nekontroluje.
// =====================================================================

// Verze schematu - zvyseni vynuti novou kontrolu (napr. po pridani sloupce)
const VERZE_SCHEMATU = 3;

// Sloupce pridane v novejsich verzich: tabulka => [sloupec => definice pro ALTER TABLE]
const DOPLNOVANE_SLOUPCE = [
    'subtasks' => [
        // Odhad, kolik minut ukol zabere (navrhuje AI)
        'odhad_minut' => 'SMALLINT UNSIGNED NULL AFTER cas',
        // Priznak "dulezite" (hvezdicka)
        'dulezite'    => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER odhad_minut',
    ],
];

// Precte setup.sql a vrati seznam prikazu CREATE TABLE
function prikazySetupSql()
{
    // Cely obsah souboru
    $sql = (string) @file_get_contents(__DIR__ . '/setup.sql');
    // Odstranime radkove komentare (zacinaji "--")
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    // Prikazy jsou oddelene strednikem
    $prikazy = [];
    // Projdeme jednotlive casti
    foreach (explode(';', $sql) as $prikaz) {
        // Orezeme mezery a odradkovani
        $prikaz = trim($prikaz);
        // Bereme jen vytvareni tabulek (CREATE DATABASE a USE resi hosting / nastaveni)
        if (stripos($prikaz, 'CREATE TABLE') === 0) {
            // Pridame do seznamu
            $prikazy[] = $prikaz;
        }
    }
    // Vratime seznam
    return $prikazy;
}

// Zajisti tabulky a sloupce (viz popis souboru)
function zajistiSchema($spojeni)
{
    // Nastaveni databaze (znacka plati jen pro tuto databazi)
    $k = konfigurace();
    // Znacka v docasne slozce; v nazvu je verze schematu, cas zmeny setup.sql a databaze
    $znacka = sys_get_temp_dir() . '/planodar-schema-' . md5(
        VERZE_SCHEMATU . '|' . @filemtime(__DIR__ . '/setup.sql') . '|' . $k['db_host'] . '|' . $k['db_port'] . '|' . $k['db_nazev']
    ) . '.ok';
    // Znacka existuje -> databaze uz byla zkontrolovana
    if (is_file($znacka)) {
        // Nic nedelame
        return;
    }

    // 1) Chybejici tabulky vytvorime (IF NOT EXISTS = existujici se nezmeni)
    foreach (prikazySetupSql() as $prikaz) {
        // Prikaz bez parametru -> staci mysqli_query
        if (!mysqli_query($spojeni, $prikaz)) {
            // Napr. chybi prava k vytvoreni tabulky
            selhaniDatabaze('Nepodařilo se vytvořit tabulky: ' . mysqli_error($spojeni));
        }
    }

    // 2) Chybejici sloupce doplnime
    foreach (DOPLNOVANE_SLOUPCE as $tabulka => $sloupce) {
        // Seznam sloupcu, ktere tabulka uz ma (information_schema = popis databaze)
        $existujici = array_column(nactiRadky($spojeni,
            'SELECT COLUMN_NAME AS nazev FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            's', [$tabulka]), 'nazev');
        // Projdeme sloupce, ktere ma tabulka mit
        foreach ($sloupce as $sloupec => $definice) {
            // Sloupec uz existuje -> nic
            if (in_array($sloupec, $existujici, true)) {
                // Dalsi sloupec
                continue;
            }
            // Pridame sloupec (nazvy jsou konstanty v kodu, ne vstup od uzivatele)
            if (!mysqli_query($spojeni, "ALTER TABLE `$tabulka` ADD COLUMN `$sloupec` $definice")) {
                // Chyba pri uprave tabulky
                selhaniDatabaze('Nepodařilo se upravit tabulku ' . $tabulka . ': ' . mysqli_error($spojeni));
            }
        }
    }

    // Vse je v poradku -> zapiseme znacku (kdyz se zapis nepovede, kontrola probehne priste znovu)
    @file_put_contents($znacka, date('c'));
}
