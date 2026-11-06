<?php
// =====================================================================
// registrace.php - zalozeni noveho uctu (jmeno, e-mail, heslo)
// GET  = zobrazi formular
// POST = zkontroluje udaje, vytvori ucet a rovnou prihlasi
// =====================================================================

// Spolecna sablona (vlozi i auth.php a db.php)
require_once 'sablona.php';

// Prihlaseny uzivatel uz ucet ma
if (prihlasenyUzivatel($spojeni) !== null) {
    // Rovnou do kalendare
    presmeruj('index.php');
}

// Text chyby formulare (prazdny = zadna chyba)
$chyba = '';
// Zadane hodnoty - po chybe je vratime do formulare (hesla zamerne ne)
$jmeno = '';
$email = '';

// Je registrace povolena? (v config.php ji jde po nasazeni na internet vypnout)
$registracePovolena = (bool) konfigurace()['registrace_povolena'];

// Formular byl odeslan (a registrace je povolena - jinak POST ignorujeme)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $registracePovolena) {
    // Jmeno bez prebytecnych mezer
    $jmeno = upravJmeno($_POST['jmeno'] ?? '');
    // E-mail malymi pismeny bez mezer
    $email = upravEmail($_POST['email'] ?? '');
    // Heslo a jeho zopakovani presne tak, jak byly zadany
    $heslo = (string) ($_POST['heslo'] ?? '');
    $heslo2 = (string) ($_POST['heslo2'] ?? '');

    // Postupne kontroly - prvni nalezena chyba se zobrazi
    if (!overCsrf($_POST['csrf'] ?? '')) {
        // Neplatny CSRF token
        $chyba = 'Formulář vypršel. Zkuste to prosím znovu.';
    } elseif (($chyba = chybaJmena($jmeno)) !== '') {
        // Chyba ve jmenu - text uz je v $chyba
    } elseif (($chyba = chybaEmailu($email)) !== '') {
        // Chyba v e-mailu - text uz je v $chyba
    } elseif (($chyba = chybaNovehoHesla($heslo, $heslo2)) !== '') {
        // Chyba v hesle - text uz je v $chyba
    } elseif (jeEmailObsazeny($spojeni, $email)) {
        // E-mail uz pouziva jiny ucet
        $chyba = 'Účet s tímto e-mailem už existuje. Zkuste se přihlásit.';
    } else {
        // Vse v poradku -> vytvorime ucet; heslo ulozime jen jako hash
        $vlozeno = zmenData($spojeni,
            'INSERT INTO users (jmeno, email, heslo_hash) VALUES (?, ?, ?)',
            'sss', [$jmeno, $email, hashHesla($heslo)],
            1062); // 1062 = duplicitni e-mail (dva lide se registruji ve stejnou chvili)

        // Mezitim nekdo e-mail zabral
        if ($vlozeno === false) {
            // Stejna zprava jako vyse
            $chyba = 'Účet s tímto e-mailem už existuje. Zkuste se přihlásit.';
        } else {
            // Nacteme novy ucet (id pridelila databaze)
            $uzivatel = nactiUzivatele($spojeni, mysqli_insert_id($spojeni));
            // Rovnou prihlasime (bez "Zapamatovat si me")
            prihlasUzivatele($spojeni, $uzivatel, false);
            // Uvitaci zprava na hlavni strance
            nastavZpravu('ok', 'Vítej, ' . $uzivatel['jmeno'] . '! Účet je vytvořený.');
            // Do kalendare
            presmeruj('index.php');
        }
    }
}

// ---------------------------------------------------------------------
// Vypis stranky
// ---------------------------------------------------------------------
zacatekStranky('Registrace', 'stranka-formular');
?>
    <!-- Uzky obal uprostred stranky -->
    <main class="max-w-md mx-auto px-4 py-10">
        <?php logoAplikace(); ?>

        <!-- List papiru s formularem -->
        <section class="list-papiru">
            <!-- Nadpis -->
            <h2 class="titulek-okna">Nový účet</h2>

            <?php vypisChybu(['formular' => $chyba], 'formular'); ?>

            <?php if (!$registracePovolena): ?>
                <!-- Registrace je vypnuta v config.php -> misto formulare vysvetleni -->
                <p class="mb-2">Registrace nových účtů je momentálně vypnutá. Pokud už účet máš, přihlas se.</p>
            <?php else: ?>
            <!-- Registracni formular -->
            <form method="post" action="registrace.php" class="space-y-4">
                <!-- Skryty CSRF token -->
                <?php echo csrfPole(); ?>

                <!-- Jmeno -->
                <div>
                    <!-- Popisek pole -->
                    <label for="jmeno" class="popisek">Jak ti máme říkat?</label>
                    <!-- autocomplete="name" = prohlizec nabidne jmeno -->
                    <input id="jmeno" name="jmeno" type="text" required autofocus autocomplete="name"
                           maxlength="<?php echo MAX_DELKA_JMENA; ?>" value="<?php echo e($jmeno); ?>" class="pole">
                </div>

                <!-- E-mail -->
                <div>
                    <!-- Popisek pole -->
                    <label for="email" class="popisek">E-mail</label>
                    <!-- Pole typu email - mobil ukaze klavesnici se zavinacem -->
                    <input id="email" name="email" type="email" required autocomplete="email"
                           maxlength="<?php echo MAX_DELKA_EMAILU; ?>" value="<?php echo e($email); ?>" class="pole">
                </div>

                <!-- Heslo a jeho zopakovani pod sebou (pod polem je ukazatel sily hesla) -->
                <div class="grid grid-cols-1 gap-4">
                    <!-- Heslo -->
                    <div>
                        <!-- Popisek pole -->
                        <label for="heslo" class="popisek">Heslo</label>
                        <!-- new-password = spravce hesel nabidne vygenerovat silne heslo -->
                        <input id="heslo" name="heslo" type="password" required autocomplete="new-password"
                               minlength="<?php echo MIN_DELKA_HESLA; ?>" class="pole" data-sila>
                    </div>
                    <!-- Zopakovani hesla -->
                    <div>
                        <!-- Popisek pole -->
                        <label for="heslo2" class="popisek">Heslo znovu</label>
                        <!-- Kontrola preklepu -->
                        <input id="heslo2" name="heslo2" type="password" required autocomplete="new-password"
                               minlength="<?php echo MIN_DELKA_HESLA; ?>" class="pole">
                    </div>
                </div>
                <!-- Napoveda k heslu -->
                <p class="napoveda">Alespoň <?php echo MIN_DELKA_HESLA; ?> znaků. Heslo se ukládá jen jako otisk (hash), nikdo ho nepřečte.</p>

                <!-- Odeslani -->
                <div class="flex justify-end pt-2">
                    <!-- Hlavni tlacitko s gradientem -->
                    <button type="submit" class="btn btn-hlavni">Vytvořit účet</button>
                </div>
            </form>
            <?php endif; ?>
        </section>

        <!-- Odkaz na prihlaseni -->
        <p class="poznamka text-center mt-6">
            Už účet máš? <a href="prihlaseni.php" class="odkaz">Přihlas se</a>
        </p>
    </main>
<?php
konecStranky('formulare.js');
