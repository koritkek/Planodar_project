<?php
// =====================================================================
// ucet.php - sprava uctu prihlaseneho uzivatele
//   - prehled (jmeno, e-mail, datum registrace, pocty udalosti)
//   - zmena jmena, e-mailu a hesla
//   - odhlaseni ze vsech zarizeni
//   - stazeni vlastnich dat (export.php)
//   - smazani uctu i se vsemi udalostmi a podukoly
// Vsechny zmeny se odesilaji metodou POST s polem "akce", ktere urcuje,
// co se ma udelat. Po uspechu se stranka presmeruje sama na sebe
// (vzor Post/Redirect/Get - obnoveni stranky formular neodesle znovu).
// =====================================================================

// Spolecna sablona (vlozi i auth.php a db.php)
require_once 'sablona.php';

// Jen pro prihlasene (jinak presmeruje na prihlaseni)
$uzivatel = vyzadujPrihlaseni($spojeni);
// Id prihlaseneho uzivatele
$user_id = (int) $uzivatel['id'];

// ---------------------------------------------------------------------
// Pomocne funkce teto stranky
// ---------------------------------------------------------------------

// Overi soucasne heslo pred citlivou zmenou; vrati text chyby, nebo prazdny text
function chybaSoucasnehoHesla($spojeni, $uzivatel, $heslo)
{
    // Ochrana proti hadani hesla: stejny limit jako u prihlaseni
    if (jePrihlaseniZablokovane($spojeni, $uzivatel['email'], ipAdresa())) {
        // Text chyby
        return 'Příliš mnoho špatných pokusů. Zkuste to znovu za ' . BLOKACE_MINUT . ' minut.';
    }
    // Heslo nesedi s ulozenym hashem
    if (!password_verify($heslo, $uzivatel['heslo_hash'])) {
        // Zapiseme neuspesny pokus
        zapisNeuspesnyPokus($spojeni, $uzivatel['email'], ipAdresa());
        // Text chyby
        return 'Současné heslo není správné.';
    }
    // Heslo je spravne
    return '';
}

// Ceske sklonovani podle poctu: 1 udalost, 2 udalosti, 5 udalosti
function sklonuj($pocet, $jedna, $dve, $pet)
{
    // 1 -> prvni tvar
    if ($pocet === 1) {
        // Napr. "1 událost"
        return $pocet . ' ' . $jedna;
    }
    // 2 az 4 -> druhy tvar
    if ($pocet >= 2 && $pocet <= 4) {
        // Napr. "3 události"
        return $pocet . ' ' . $dve;
    }
    // 0 a 5+ -> treti tvar
    return $pocet . ' ' . $pet;
}

// ---------------------------------------------------------------------
// Zpracovani formularu
// ---------------------------------------------------------------------

// Chyby jednotlivych formularu: klic = nazev akce, hodnota = text chyby
$chyby = [];
// Hodnoty predvyplnene ve formularich (po chybe zustane to, co uzivatel napsal)
$noveJmeno = $uzivatel['jmeno'];
$novyEmail = $uzivatel['email'];

// Nektery formular byl odeslan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ktera akce se ma provest
    $akce = (string) ($_POST['akce'] ?? '');

    // Kontrola CSRF tokenu - bez nej nic neprovedeme
    if (!overCsrf($_POST['csrf'] ?? '')) {
        // Chybu ukazeme u formulare, ktery se odesilal (neznamy formular -> obecne nahore)
        $chyby[in_array($akce, ['jmeno', 'email', 'heslo', 'odhlasit_vse', 'smazat'], true) ? $akce : 'obecne']
            = 'Formulář vypršel. Zkuste to prosím znovu.';

    // ----- Zmena jmena -----
    } elseif ($akce === 'jmeno') {
        // Nove jmeno bez prebytecnych mezer
        $noveJmeno = upravJmeno($_POST['jmeno'] ?? '');
        // Kontrola jmena
        $chyby['jmeno'] = chybaJmena($noveJmeno);
        // Jmeno je v poradku
        if ($chyby['jmeno'] === '') {
            // Ulozime ho
            zmenData($spojeni, 'UPDATE users SET jmeno = ? WHERE id = ?', 'si', [$noveJmeno, $user_id]);
            // Zprava o uspechu
            nastavZpravu('ok', 'Jméno bylo změněno.');
            // Post/Redirect/Get
            presmeruj('ucet.php');
        }

    // ----- Zmena e-mailu -----
    } elseif ($akce === 'email') {
        // Novy e-mail malymi pismeny
        $novyEmail = upravEmail($_POST['email'] ?? '');
        // Postupne kontroly: format, shoda se starym, obsazenost, heslo
        if (($chyby['email'] = chybaEmailu($novyEmail)) !== '') {
            // Spatny format - text chyby uz je v poli
        } elseif ($novyEmail === $uzivatel['email']) {
            // Zadna zmena
            $chyby['email'] = 'Tohle už je tvůj současný e-mail.';
        } elseif (jeEmailObsazeny($spojeni, $novyEmail, $user_id)) {
            // E-mail pouziva jiny ucet
            $chyby['email'] = 'Tento e-mail už používá jiný účet.';
        } elseif (($chyby['email'] = chybaSoucasnehoHesla($spojeni, $uzivatel, (string) ($_POST['heslo'] ?? ''))) !== '') {
            // Spatne heslo - text chyby uz je v poli
        } else {
            // Ulozime novy e-mail (1062 = mezitim ho nekdo zabral)
            $zmeneno = zmenData($spojeni, 'UPDATE users SET email = ? WHERE id = ?', 'si', [$novyEmail, $user_id], 1062);
            // Nekdo byl rychlejsi
            if ($zmeneno === false) {
                // Text chyby
                $chyby['email'] = 'Tento e-mail už používá jiný účet.';
            } else {
                // Zprava o uspechu
                nastavZpravu('ok', 'E-mail byl změněn. Příště se přihlas novým e-mailem.');
                // Post/Redirect/Get
                presmeruj('ucet.php');
            }
        }

    // ----- Zmena hesla -----
    } elseif ($akce === 'heslo') {
        // Soucasne heslo, nove heslo a jeho zopakovani
        $soucasne = (string) ($_POST['soucasne'] ?? '');
        $nove = (string) ($_POST['nove'] ?? '');
        $nove2 = (string) ($_POST['nove2'] ?? '');
        // Postupne kontroly
        if (($chyby['heslo'] = chybaNovehoHesla($nove, $nove2)) !== '') {
            // Nove heslo nesplnuje pozadavky - text chyby uz je v poli
        } elseif (($chyby['heslo'] = chybaSoucasnehoHesla($spojeni, $uzivatel, $soucasne)) !== '') {
            // Spatne soucasne heslo - text chyby uz je v poli
        } elseif ($nove === $soucasne) {
            // Nove heslo je stejne jako stare
            $chyby['heslo'] = 'Nové heslo je stejné jako současné.';
        } else {
            // Ulozime hash noveho hesla
            zmenData($spojeni, 'UPDATE users SET heslo_hash = ? WHERE id = ?', 'si',
                [hashHesla($nove), $user_id]);
            // Melo toto zarizeni zapnute "Zapamatovat si me"? (zjistime pred smazanim tokenu)
            $melPamatovani = (rozdelPamatovaciCookie() !== null);
            // Odhlasime vsechna ostatni zarizeni (kdyby nekdo znal stare heslo)
            $_SESSION['verze'] = zneplatniVsechnaPrihlaseni($spojeni, $user_id);
            // Nove ID session pro toto zarizeni
            session_regenerate_id(true);
            // Vsechny tokeny byly smazany -> tomuto zarizeni vydame novy, ostatnim ne
            if ($melPamatovani) {
                // Novy token a cookie
                vytvorPamatovaciToken($spojeni, $user_id);
            } else {
                // Pro jistotu smazeme pripadnou starou cookie
                smazPamatovaciCookie();
            }
            // Zprava o uspechu
            nastavZpravu('ok', 'Heslo bylo změněno. Na ostatních zařízeních jsi byl/a odhlášen/a.');
            // Post/Redirect/Get
            presmeruj('ucet.php');
        }

    // ----- Odhlaseni ze vsech zarizeni -----
    } elseif ($akce === 'odhlasit_vse') {
        // Zneplatnime vsechna prihlaseni uctu
        zneplatniVsechnaPrihlaseni($spojeni, $user_id);
        // Odhlasime i toto zarizeni
        odhlasUzivatele($spojeni);
        // Zprava pro prihlasovaci stranku
        nastavZpravu('ok', 'Byl/a jsi odhlášen/a ze všech zařízení.');
        // Na prihlaseni
        presmeruj('prihlaseni.php');

    // ----- Smazani uctu -----
    } elseif ($akce === 'smazat') {
        // Potvrzeni zaskrtnutim a zadanim hesla
        if (!isset($_POST['potvrzeni'])) {
            // Chybi zaskrtnuti
            $chyby['smazat'] = 'Zaškrtni, že opravdu chceš účet smazat.';
        } elseif (($chyby['smazat'] = chybaSoucasnehoHesla($spojeni, $uzivatel, (string) ($_POST['heslo'] ?? ''))) !== '') {
            // Spatne heslo - text chyby uz je v poli
        } else {
            // Smazeme zaznamy o pokusech o prihlaseni na tento e-mail
            zmenData($spojeni, 'DELETE FROM pokusy_prihlaseni WHERE email = ?', 's', [$uzivatel['email']]);
            // Smazeme ucet; cizi klice s ON DELETE CASCADE smazou i udalosti,
            // jejich podukoly a tokeny "Zapamatovat si me"
            zmenData($spojeni, 'DELETE FROM users WHERE id = ?', 'i', [$user_id]);
            // Odhlasime toto zarizeni
            odhlasUzivatele($spojeni);
            // Zprava pro prihlasovaci stranku
            nastavZpravu('ok', 'Účet i všechny tvoje události byly smazány. Díky, že jsi Plánodář zkusil/a.');
            // Na prihlaseni
            presmeruj('prihlaseni.php');
        }

    // ----- Neznama akce -----
    } else {
        // Nekdo poslal podivny formular
        $chyby['obecne'] = 'Neznámá akce.';
    }
}

// ---------------------------------------------------------------------
// Data pro zobrazeni
// ---------------------------------------------------------------------

// Pocet udalosti, podukolu a splnenych podukolu uzivatele (poddotazy v jednom SELECTu)
$pocty = nactiRadek($spojeni,
    'SELECT
        (SELECT COUNT(*) FROM events WHERE user_id = ?) AS udalosti,
        (SELECT COUNT(*) FROM subtasks s JOIN events e ON e.id = s.event_id WHERE e.user_id = ?) AS podukoly,
        (SELECT COUNT(*) FROM subtasks s JOIN events e ON e.id = s.event_id WHERE e.user_id = ? AND s.splneno = 1) AS splneno',
    'iii', [$user_id, $user_id, $user_id]);
// Podil splnenych podukolu v procentech (bez podukolu 0)
$procentaSplneno = (int) $pocty['podukoly'] > 0 ? (int) round($pocty['splneno'] / $pocty['podukoly'] * 100) : 0;
// Datum registrace v ceskem formatu, napr. "7. 10. 2026"
$clenemOd = date('j. n. Y', strtotime($uzivatel['vytvoreno']));
// Jednorazova zprava (napr. "Heslo bylo zmeneno.")
$zprava = vezmiZpravu();

// ---------------------------------------------------------------------
// Vypis stranky
// ---------------------------------------------------------------------
zacatekStranky('Můj účet', 'stranka-formular');
?>
    <!-- Sirsi obal - na strance je vic karet vedle sebe -->
    <main class="max-w-4xl mx-auto px-4 py-10">
        <?php logoAplikace(); ?>

        <!-- Odkaz zpet do kalendare -->
        <p class="poznamka mb-6"><a href="index.php" class="odkaz">← zpátky do kalendáře</a></p>

        <?php
        // Zprava o vysledku posledni akce
        vypisZpravu($zprava);
        // Chyba neznameho formulare
        vypisChybu($chyby, 'obecne');
        ?>

        <!-- Mrizka karet: na mobilu pod sebou, na sirsi obrazovce ve dvou sloupcich;
             items-start = kazda karta je jen tak vysoka, jak potrebuje (jako listky na nastence) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-10 items-start">

            <!-- ===== Prehled uctu ===== -->
            <section class="list-papiru">
                <!-- Nadpis s pozdravem -->
                <h2 class="titulek-okna break-words">Ahoj, <?php echo e($uzivatel['jmeno']); ?>!</h2>
                <!-- Prehled udaju jako seznam pojmu a hodnot -->
                <dl class="prehled-uctu">
                    <!-- E-mail -->
                    <dt>E-mail</dt>
                    <dd class="break-all"><?php echo e($uzivatel['email']); ?></dd>
                    <!-- Datum registrace -->
                    <dt>Členem od</dt>
                    <dd><?php echo e($clenemOd); ?></dd>
                    <!-- Pocet udalosti a podukolu -->
                    <dt>V kalendáři</dt>
                    <dd>
                        <?php echo e(sklonuj((int) $pocty['udalosti'], 'událost', 'události', 'událostí')); ?>,
                        <?php echo e(sklonuj((int) $pocty['podukoly'], 'podúkol', 'podúkoly', 'podúkolů')); ?>
                    </dd>
                    <!-- Pripravenost: kolik podukolu je splneno -->
                    <dt>Připraveno</dt>
                    <dd>
                        <?php echo (int) $pocty['splneno']; ?> z <?php echo (int) $pocty['podukoly']; ?> podúkolů
                        <!-- Ukazatel prubehu (stejny jako v detailu udalosti); sirka v procentech -->
                        <span class="ukazatel ukazatel-ucet<?php echo ($procentaSplneno === 100) ? ' je-hotovo' : ''; ?>"
                              role="progressbar" aria-label="Splněno podúkolů" aria-valuemin="0" aria-valuemax="100"
                              aria-valuenow="<?php echo $procentaSplneno; ?>">
                            <span class="ukazatel-vypln" style="width: <?php echo $procentaSplneno; ?>%"></span>
                        </span>
                    </dd>
                </dl>

                <!-- Tlacitka: stazeni dat a odhlaseni -->
                <div class="flex flex-wrap gap-3 mt-6">
                    <!-- Export vlastnich dat (soubor JSON); vypada jako tlacitko -->
                    <a href="export.php" class="btn">Stáhnout moje data</a>
                    <!-- Odhlaseni je formular (POST + CSRF), ne odkaz -->
                    <form method="post" action="odhlaseni.php">
                        <?php echo csrfPole(); ?>
                        <button type="submit" class="btn">Odhlásit se</button>
                    </form>
                </div>
            </section>

            <!-- ===== Zmena jmena ===== -->
            <section class="list-papiru">
                <!-- Nadpis karty -->
                <h2 class="titulek-okna">Jméno</h2>
                <?php vypisChybu($chyby, 'jmeno'); ?>
                <!-- Formular zmeny jmena -->
                <form method="post" action="ucet.php" class="space-y-4">
                    <!-- CSRF token a nazev akce -->
                    <?php echo csrfPole(); ?>
                    <input type="hidden" name="akce" value="jmeno">
                    <!-- Pole se jmenem -->
                    <div>
                        <!-- Popisek pole -->
                        <label for="jmeno" class="popisek">Jak ti máme říkat?</label>
                        <!-- Predvyplnene soucasnym jmenem -->
                        <input id="jmeno" name="jmeno" type="text" required autocomplete="name"
                               maxlength="<?php echo MAX_DELKA_JMENA; ?>" value="<?php echo e($noveJmeno); ?>" class="pole">
                    </div>
                    <!-- Odeslani -->
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-hlavni">Uložit jméno</button>
                    </div>
                </form>
            </section>

            <!-- ===== Zmena e-mailu ===== -->
            <section class="list-papiru">
                <!-- Nadpis karty -->
                <h2 class="titulek-okna">E-mail</h2>
                <?php vypisChybu($chyby, 'email'); ?>
                <!-- Formular zmeny e-mailu -->
                <form method="post" action="ucet.php" class="space-y-4">
                    <!-- CSRF token a nazev akce -->
                    <?php echo csrfPole(); ?>
                    <input type="hidden" name="akce" value="email">
                    <!-- Novy e-mail -->
                    <div>
                        <!-- Popisek pole -->
                        <label for="email" class="popisek">Nový e-mail</label>
                        <!-- Predvyplnene soucasnym e-mailem -->
                        <input id="email" name="email" type="email" required autocomplete="email"
                               maxlength="<?php echo MAX_DELKA_EMAILU; ?>" value="<?php echo e($novyEmail); ?>" class="pole">
                    </div>
                    <!-- Potvrzeni heslem (kdo ukradne otevreny prohlizec, nezmeni e-mail) -->
                    <div>
                        <!-- Popisek pole -->
                        <label for="email-heslo" class="popisek">Současné heslo</label>
                        <!-- Heslo pro potvrzeni -->
                        <input id="email-heslo" name="heslo" type="password" required autocomplete="current-password" class="pole">
                    </div>
                    <!-- Odeslani -->
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-hlavni">Změnit e-mail</button>
                    </div>
                </form>
            </section>

            <!-- ===== Zmena hesla ===== -->
            <section class="list-papiru">
                <!-- Nadpis karty -->
                <h2 class="titulek-okna">Heslo</h2>
                <?php vypisChybu($chyby, 'heslo'); ?>
                <!-- Formular zmeny hesla -->
                <form method="post" action="ucet.php" class="space-y-4">
                    <!-- CSRF token a nazev akce -->
                    <?php echo csrfPole(); ?>
                    <input type="hidden" name="akce" value="heslo">
                    <!-- Soucasne heslo -->
                    <div>
                        <!-- Popisek pole -->
                        <label for="soucasne" class="popisek">Současné heslo</label>
                        <!-- Pole hesla -->
                        <input id="soucasne" name="soucasne" type="password" required autocomplete="current-password" class="pole">
                    </div>
                    <!-- Nove heslo a jeho zopakovani (pod sebou - karta je uzka) -->
                    <div class="grid grid-cols-1 gap-4">
                        <!-- Nove heslo -->
                        <div>
                            <!-- Popisek pole -->
                            <label for="nove" class="popisek">Nové heslo</label>
                            <!-- new-password = spravce hesel nabidne silne heslo -->
                            <input id="nove" name="nove" type="password" required autocomplete="new-password"
                                   minlength="<?php echo MIN_DELKA_HESLA; ?>" class="pole" data-sila>
                        </div>
                        <!-- Zopakovani -->
                        <div>
                            <!-- Popisek pole -->
                            <label for="nove2" class="popisek">Nové heslo znovu</label>
                            <!-- Kontrola preklepu -->
                            <input id="nove2" name="nove2" type="password" required autocomplete="new-password"
                                   minlength="<?php echo MIN_DELKA_HESLA; ?>" class="pole">
                        </div>
                    </div>
                    <!-- Napoveda -->
                    <p class="napoveda">Alespoň <?php echo MIN_DELKA_HESLA; ?> znaků. Po změně tě odhlásíme na ostatních zařízeních.</p>
                    <!-- Odeslani -->
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-hlavni">Změnit heslo</button>
                    </div>
                </form>
            </section>

            <!-- ===== Odhlaseni ze vsech zarizeni ===== -->
            <section class="list-papiru">
                <!-- Nadpis karty -->
                <h2 class="titulek-okna">Všechna zařízení</h2>
                <?php vypisChybu($chyby, 'odhlasit_vse'); ?>
                <!-- Vysvetleni -->
                <p class="mb-4">Zapomněl/a ses odhlásit ve škole nebo na cizím počítači? Tímhle tě odhlásíme úplně všude – i tady.</p>
                <!-- Formular s jedinym tlacitkem -->
                <form method="post" action="ucet.php" class="flex justify-end">
                    <!-- CSRF token a nazev akce -->
                    <?php echo csrfPole(); ?>
                    <input type="hidden" name="akce" value="odhlasit_vse">
                    <!-- Odeslani -->
                    <button type="submit" class="btn">Odhlásit všude</button>
                </form>
            </section>

            <!-- ===== Smazani uctu (nebezpecna zona) ===== -->
            <section class="list-papiru list-nebezpeci">
                <!-- Nadpis karty -->
                <h2 class="titulek-okna">Smazat účet</h2>
                <?php vypisChybu($chyby, 'smazat'); ?>
                <!-- Varovani -->
                <p class="mb-4">
                    Smaže se tvůj účet a s ním <strong>všechny události a podúkoly</strong>
                    (<?php echo e(sklonuj((int) $pocty['udalosti'], 'událost', 'události', 'událostí')); ?>).
                    Vrátit to nejde – pokud si chceš data nechat, nejdřív si je stáhni.
                </p>
                <!-- Formular smazani -->
                <form method="post" action="ucet.php" class="space-y-4">
                    <!-- CSRF token a nazev akce -->
                    <?php echo csrfPole(); ?>
                    <input type="hidden" name="akce" value="smazat">
                    <!-- Potvrzeni heslem -->
                    <div>
                        <!-- Popisek pole -->
                        <label for="smazat-heslo" class="popisek">Pro potvrzení zadej heslo</label>
                        <!-- Pole hesla -->
                        <input id="smazat-heslo" name="heslo" type="password" required autocomplete="current-password" class="pole">
                    </div>
                    <!-- Potvrzeni zaskrtnutim -->
                    <label class="zaskrtavatko">
                        <!-- required = bez zaskrtnuti prohlizec formular neodesle -->
                        <input type="checkbox" name="potvrzeni" value="1" required>
                        <!-- Text u policka -->
                        <span>Rozumím, že účet i všechna data budou nenávratně smazána.</span>
                    </label>
                    <!-- Odeslani -->
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-nebezpeci">Smazat účet natrvalo</button>
                    </div>
                </form>
            </section>
        </div>
    </main>
<?php
konecStranky('formulare.js');
