<?php
// =====================================================================
// sablona.php - spolecne casti HTML vsech stranek (hlavicka, konec,
// zpravy). Diky tomu se <head> s pismy a styly neopakuje v kazdem souboru.
// =====================================================================

// Ucty, session a databaze (sablona je potrebuje pro CSRF token a zpravy)
require_once __DIR__ . '/auth.php';

// Adresa souboru s "verzi" podle casu posledni zmeny, napr. style.css?v=1730800000
// - prohlizec si soubor muze pamatovat, a jakmile se zmeni, stahne novou verzi
function sVerzi($soubor)
{
    // filemtime = cas posledni zmeny souboru (cislo)
    return $soubor . '?v=' . (int) @filemtime(__DIR__ . '/' . $soubor);
}

// Vypise zacatek HTML stranky az po otevreni <body>
//   $tridaTela = dalsi CSS trida pro <body> (napr. pro stranky s formularem)
function zacatekStranky($titulek, $tridaTela = '')
{
    // Uzaviraci znacka PHP prepne do rezimu vypisovani HTML; otviraci znacka vraci zpet do PHP
    // (pozor: uzaviraci znacku nelze napsat ani do komentare - PHP by ji poslechlo)
    ?>
<!DOCTYPE html>
<!-- Jazyk stranky je cestina (dulezite pro ctecky obrazovky a deleni slov) -->
<html lang="cs">
<head>
    <!-- Kodovani stranky UTF-8 kvuli ceskym znakum -->
    <meta charset="UTF-8">
    <!-- Spravne zobrazeni na mobilech; viewport-fit=cover = obsah i k vyrezu displeje (resi CSS) -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- Barva listy prohlizece na mobilu (barva papiru) -->
    <meta name="theme-color" content="#fdf5e6">
    <!-- Popis stranky (vyhledavace, nahled odkazu) -->
    <meta name="description" content="Plánodář – kalendář, který myslí dopředu. Umělá inteligence navrhne, co si připravit.">
    <!-- CSRF token pro JavaScript (posila ho s kazdym pozadavkem na api.php) -->
    <meta name="csrf-token" content="<?php echo e(csrfToken()); ?>">
    <!-- Titulek v zalozce prohlizece -->
    <title><?php echo e($titulek); ?> – Plánodář</title>
    <!-- Ikona aplikace (vektorova, ostra na kazdem displeji) -->
    <link rel="icon" href="<?php echo e(sVerzi('ikona.svg')); ?>" type="image/svg+xml">
    <!-- Predpripojeni k serverum Google Fonts (pisma se nactou rychleji) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- Pisma: Caveat = rucne psane nadpisy, Bricolage Grotesque = bezny text (obe umi cestinu) -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@400;600;800&family=Caveat:wght@500;700&display=swap">
    <!-- Tailwind CSS nacteny z CDN (pripravene utility tridy pro rozlozeni) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Vlastni styly - vzhled "sesitu" (barvy, stiny, 3D prvky, animace) -->
    <link rel="stylesheet" href="<?php echo e(sVerzi('style.css')); ?>">
</head>
<!-- Telo stranky; trida papir = ctverecovane pozadi jako v sesite -->
<body class="papir min-h-screen <?php echo e($tridaTela); ?>">
    <?php
}

// Vypise konec stranky; $skript = nazev JS souboru, ktery se ma nacist (nepovinne)
function konecStranky($skript = '')
{
    // Pokud stranka potrebuje JavaScript, nacteme ho az na konci (HTML uz existuje)
    if ($skript !== '') {
        // Tag <script> s nazvem souboru (s verzi kvuli pameti prohlizece)
        echo '    <script src="' . e(sVerzi($skript)) . '"></script>' . "\n";
    }
    // Uzavreni body a html
    echo "</body>\n</html>\n";
}

// Vypise nazev aplikace a podtitulek (pro stranky prihlaseni, registrace, uctu)
function logoAplikace()
{
    ?>
        <!-- Nazev aplikace jako odkaz na kalendar -->
        <div class="text-center mb-8">
            <!-- Nazev - rucne psane pismo s gradientem -->
            <a href="index.php" class="inline-block"><h1 class="nadpis-app">Plánodář</h1></a>
            <!-- Podtitulek jako poznamka tuzkou (ml-0 zrusi odsazeni z hlavni stranky) -->
            <p class="podtitulek ml-0">kalendář, který myslí dopředu</p>
        </div>
    <?php
}

// Vypise zpravu (zelenou nebo cervenou), pokud nejaka je
function vypisZpravu($zprava)
{
    // Zadna zprava -> nic nevypisujeme
    if ($zprava === null) {
        // Konec funkce
        return;
    }
    // Trida podle typu zpravy: hlaska-ok = zelena, hlaska-chyba = cervena
    $trida = ($zprava['typ'] === 'ok') ? 'hlaska-ok' : 'hlaska-chyba';
    // role="alert" = ctecka obrazovky zpravu hned precte
    echo '<p class="' . $trida . ' mb-4" role="alert">' . e($zprava['text']) . '</p>';
}

// Vypise chybu konkretniho formulare (text z pole $chyby pod klicem $klic)
function vypisChybu($chyby, $klic)
{
    // Pro tento formular zadna chyba neni
    if (empty($chyby[$klic])) {
        // Konec funkce
        return;
    }
    // Cervena hlaska s textem chyby
    vypisZpravu(['typ' => 'chyba', 'text' => $chyby[$klic]]);
}
