<?php
// =====================================================================
// prihlaseni.php - prihlasovaci stranka (e-mail + heslo)
// GET  = zobrazi formular
// POST = overi udaje a prihlasi uzivatele
// =====================================================================

// Spolecna sablona (vlozi i auth.php a db.php)
require_once 'sablona.php';

// Uz prihlaseny uzivatel nema na prihlasovaci strance co delat
if (prihlasenyUzivatel($spojeni) !== null) {
    // Rovnou do kalendare
    presmeruj('index.php');
}

// Text chyby formulare (prazdny = zadna chyba)
$chyba = '';
// Zadany e-mail - po chybe ho vratime do formulare, at ho uzivatel nemusi psat znovu
$email = '';

// Formular byl odeslan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // E-mail upraveny na mala pismena bez mezer
    $email = upravEmail($_POST['email'] ?? '');
    // Heslo bereme presne tak, jak bylo zadano (mezery mohou byt soucasti hesla)
    $heslo = (string) ($_POST['heslo'] ?? '');
    // Zaskrtnute "Zapamatovat si me"?
    $pamatovat = isset($_POST['pamatovat']);
    // IP adresa pro omezeni pokusu
    $ip = ipAdresa();

    // Kontrola CSRF tokenu (formular musi pochazet z nasi stranky)
    if (!overCsrf($_POST['csrf'] ?? '')) {
        // Typicky po dlouhe necinnosti - session vyprsela
        $chyba = 'Formulář vypršel. Zkuste to prosím znovu.';
    } elseif ($email === '' || $heslo === '') {
        // Prazdne pole
        $chyba = 'Vyplňte e-mail i heslo.';
    } elseif (jePrihlaseniZablokovane($spojeni, $email, $ip)) {
        // Prilis mnoho neuspesnych pokusu
        $chyba = 'Příliš mnoho neúspěšných pokusů. Zkuste to znovu za ' . BLOKACE_MINUT . ' minut.';
    } else {
        // Najdeme ucet podle e-mailu
        $uzivatel = nactiRadek($spojeni,
            'SELECT id, jmeno, email, heslo_hash, verze_prihlaseni FROM users WHERE email = ?',
            's', [$email]);
        // Hash k overeni: skutecny, nebo falesny (aby kontrola trvala stejne dlouho)
        $hash = ($uzivatel !== null) ? $uzivatel['heslo_hash'] : FALESNY_HASH;
        // password_verify porovna heslo s ulozenym hashem
        $hesloSedi = password_verify($heslo, $hash);

        // Ucet neexistuje nebo heslo nesedi
        if ($uzivatel === null || !$hesloSedi) {
            // Zapiseme neuspesny pokus
            zapisNeuspesnyPokus($spojeni, $email, $ip);
            // Stejna zprava v obou pripadech - utocnik se nedozvi, ktere e-maily existuji
            $chyba = 'Nesprávný e-mail nebo heslo.';
        } else {
            // Uspech: smazeme zaznamy o neuspesnych pokusech
            smazPokusy($spojeni, $email);
            // Hash je starsim algoritmem (napr. bcrypt z drivejsi verze aplikace)? -> prepocitame
            // ho na aktualni (Argon2id); jde to jen ted, kdy zname heslo v citelne podobe
            if (password_needs_rehash($uzivatel['heslo_hash'], ALGORITMUS_HESLA, MOZNOSTI_HESLA)) {
                // Novy hash ulozime
                zmenData($spojeni, 'UPDATE users SET heslo_hash = ? WHERE id = ?', 'si',
                    [hashHesla($heslo), $uzivatel['id']]);
            }
            // Prihlaseni (session + pripadne "Zapamatovat si me")
            prihlasUzivatele($spojeni, $uzivatel, $pamatovat);
            // Hotovo - do kalendare
            presmeruj('index.php');
        }
    }
}

// Jednorazova zprava z jine stranky (napr. "Byli jste odhlaseni.")
$zprava = vezmiZpravu();

// ---------------------------------------------------------------------
// Vypis stranky
// ---------------------------------------------------------------------
zacatekStranky('Přihlášení', 'stranka-formular');
?>
    <!-- Uzky obal uprostred stranky -->
    <main class="max-w-md mx-auto px-4 py-10">
        <?php logoAplikace(); ?>

        <!-- List papiru s formularem -->
        <section class="list-papiru">
            <!-- Nadpis -->
            <h2 class="titulek-okna">Přihlášení</h2>

            <?php
            // Zprava z jine stranky (napr. po odhlaseni nebo smazani uctu)
            vypisZpravu($zprava);
            // Chyba tohoto formulare
            vypisChybu(['formular' => $chyba], 'formular');
            ?>

            <!-- Formular se odesila metodou POST na tuto stranku (heslo tak neni videt v adrese) -->
            <form method="post" action="prihlaseni.php" class="space-y-4">
                <!-- Skryty CSRF token -->
                <?php echo csrfPole(); ?>

                <!-- E-mail -->
                <div>
                    <!-- Popisek pole -->
                    <label for="email" class="popisek">E-mail</label>
                    <!-- autocomplete = prohlizec nabidne ulozeny e-mail; autofocus = kurzor rovnou sem -->
                    <input id="email" name="email" type="email" required autofocus autocomplete="email"
                           maxlength="255" value="<?php echo e($email); ?>" class="pole">
                </div>

                <!-- Heslo -->
                <div>
                    <!-- Popisek pole -->
                    <label for="heslo" class="popisek">Heslo</label>
                    <!-- current-password = spravce hesel nabidne ulozene heslo -->
                    <input id="heslo" name="heslo" type="password" required autocomplete="current-password" class="pole">
                </div>

                <!-- Zapamatovat si me -->
                <label class="zaskrtavatko">
                    <!-- Zaskrtavaci policko -->
                    <input type="checkbox" name="pamatovat" value="1">
                    <!-- Text u policka -->
                    <span>Zůstat přihlášen/a i po zavření prohlížeče (<?php echo PAMATUJ_DNI; ?> dní)</span>
                </label>

                <!-- Odeslani -->
                <div class="flex justify-end pt-2">
                    <!-- Hlavni tlacitko s gradientem -->
                    <button type="submit" class="btn btn-hlavni">Přihlásit se</button>
                </div>
            </form>
        </section>

        <?php if (konfigurace()['registrace_povolena']): ?>
        <!-- Odkaz na registraci jako rucne psana poznamka (jen kdyz je registrace povolena) -->
        <p class="poznamka text-center mt-6">
            Ještě nemáš účet? <a href="registrace.php" class="odkaz">Zaregistruj se</a>
        </p>
        <?php endif; ?>

        <!-- Co aplikace umi - tri lepici listky -->
        <ul class="vyhody" aria-label="Co Plánodář umí">
            <li class="vyhoda barva-0">Zapiš událost do kalendáře</li>
            <li class="vyhoda barva-1">AI navrhne, co si připravit</li>
            <li class="vyhoda barva-2">Odškrtávej a nic nezapomeň</li>
        </ul>
    </main>
<?php
konecStranky('formulare.js');
