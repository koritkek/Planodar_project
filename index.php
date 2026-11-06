<?php
// =====================================================================
// index.php - hlavni stranka aplikace Planodar (kalendar)
// Pristup maji jen prihlaseni uzivatele. PHP zde urci vychozi mesic
// (podle casu serveru) a vykresli kostru kalendare. Samotne bunky dni,
// udalosti, vyhledavani a podukoly obsluhuje script.js.
// =====================================================================

// Spolecna sablona (vlozi i auth.php a db.php)
require_once 'sablona.php';

// Neprihlaseneho uzivatele presmeruje na prihlaseni; jinak vrati jeho ucet
$uzivatel = vyzadujPrihlaseni($spojeni);

// Aktualni rok jako cislo (napr. 2026)
$aktualni_rok = (int) date('Y');
// Aktualni mesic jako cislo 1-12 (bez uvodni nuly)
$aktualni_mesic = (int) date('n');
// Dnesni datum ve formatu RRRR-MM-DD (pro zvyrazneni dnesniho dne)
$dnes = date('Y-m-d');

// Nazvy dnu v tydnu - kalendar zacina pondelim (ceska zvyklost)
$dny_v_tydnu = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];

// Jednorazova zprava (napr. uvitani po registraci)
$zprava = vezmiZpravu();

// Zacatek HTML (head s pismy, styly a CSRF tokenem pro JavaScript)
zacatekStranky('Kalendář', 'stranka-kalendar');
?>

    <!-- Hlavni obal: max. sirka, vycentrovani, vnitrni okraje -->
    <main class="obal-aplikace max-w-6xl mx-auto px-4 pt-5 pb-28 sm:pb-10">

        <!-- ===== Horni lista: vyhledavani a ucet ===== -->
        <div class="horni-lista">
            <!-- Vyhledavani udalosti (vysledky vykresli script.js) -->
            <div class="hledani" role="search">
                <!-- Ikona lupy (jen ozdoba) -->
                <svg class="hledani-ikona" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <!-- Pole pro hledany text; aria-* propojuje pole se seznamem vysledku -->
                <input id="pole-hledat" type="search" class="pole-hledat" maxlength="100" autocomplete="off"
                       placeholder="Hledat událost…" aria-label="Hledat událost"
                       role="combobox" aria-expanded="false" aria-controls="vysledky-hledani" aria-autocomplete="list">
                <!-- Napoveda klavesove zkratky (jen na pocitaci) -->
                <kbd class="klavesa hledani-klavesa" aria-hidden="true">/</kbd>
                <!-- Seznam vysledku; vychozi stav skryty -->
                <ul id="vysledky-hledani" class="vysledky-hledani hidden" role="listbox" aria-label="Nalezené události"></ul>
            </div>

            <!-- Panel prihlaseneho uzivatele -->
            <div class="uzivatel-panel">
                <!-- Pozdrav se jmenem (e() = ochrana proti vlozeni HTML) -->
                <span class="pozdrav">ahoj, <strong><?php echo e($uzivatel['jmeno']); ?></strong></span>
                <!-- Oddelovac -->
                <span aria-hidden="true" class="pozdrav">·</span>
                <!-- Odkaz na spravu uctu -->
                <a href="ucet.php" class="odkaz">můj účet</a>
                <!-- Oddelovac -->
                <span aria-hidden="true">·</span>
                <!-- Odhlaseni je formular (POST + CSRF), ne odkaz -->
                <form method="post" action="odhlaseni.php" class="inline">
                    <?php echo csrfPole(); ?>
                    <button type="submit" class="odkaz">odhlásit</button>
                </form>
            </div>
        </div>

        <!-- ===== Hlavicka s nazvem aplikace a ovladanim mesicu ===== -->
        <header class="hlavicka-aplikace">
            <!-- Levy blok: nazev aplikace a podtitulek -->
            <div class="hlavicka-nazev">
                <!-- Nazev aplikace - rucne psane pismo s gradientem -->
                <h1 class="nadpis-app">Plánodář</h1>
                <!-- Podtitulek jako poznamka tuzkou -->
                <p class="podtitulek">kalendář, který myslí dopředu</p>
            </div>

            <!-- Pravy blok: ovladani mesicu -->
            <nav class="ovladani-mesicu" aria-label="Přepínání měsíců">
                <!-- Tlacitko: predchozi mesic (obsluhu doplni script.js) -->
                <button id="btn-predchozi" type="button" class="btn btn-sipka"
                        title="Předchozí měsíc (šipka vlevo)" aria-label="Předchozí měsíc">
                    <!-- SVG sipka doleva (stroke = currentColor, tedy barva textu tlacitka) -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
                </button>
                <!-- Nadpis s nazvem mesice a rokem (text vyplni script.js); aria-live = ctecka oznami zmenu -->
                <!-- Vnitrni span nese zvyraznovac, aby tah kopiroval jen text, ne celou sirku nadpisu -->
                <h2 class="nadpis-mesice" aria-live="polite"><span id="nadpis-mesice" class="zvyraznovac"></span></h2>
                <!-- Tlacitko: dalsi mesic -->
                <button id="btn-dalsi" type="button" class="btn btn-sipka"
                        title="Další měsíc (šipka vpravo)" aria-label="Další měsíc">
                    <!-- SVG sipka doprava -->
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </button>
                <!-- Tlacitko: navrat na aktualni mesic -->
                <button id="btn-dnes" type="button" class="btn" title="Dnešní měsíc (klávesa T)">Dnes</button>
                <!-- Tlacitko: nova udalost (na mobilu ho nahradi kulate tlacitko vpravo dole) -->
                <button id="btn-nova" type="button" class="btn btn-hlavni btn-nova" title="Nová událost (klávesa N)">
                    <!-- Plus -->
                    <span aria-hidden="true" class="text-lg leading-none">+</span> Nová událost
                </button>
            </nav>
        </header>

        <?php
        // Zprava z jine stranky (napr. "Vitej, ...!" po registraci)
        vypisZpravu($zprava);
        ?>

        <!-- Misto pro chybove hlasky (napr. nedostupna databaze); vychozi stav skryty -->
        <div id="chyba-hlaska" class="hlaska-chyba hidden mb-6" role="alert"></div>

        <!-- ===== Kalendar ===== -->
        <!-- data-* atributy predavaji hodnoty z PHP do JavaScriptu (data-ai = je vyplneny API klic) -->
        <section id="kalendar" class="karta-kalendare" aria-label="Kalendář"
                 data-rok="<?php echo $aktualni_rok; ?>"
                 data-mesic="<?php echo $aktualni_mesic; ?>"
                 data-dnes="<?php echo $dnes; ?>"
                 data-ai="<?php echo jeAiNastavena() ? '1' : '0'; ?>">

            <!-- Radek s nazvy dnu v tydnu (7 sloupcu) s barevnym gradientem -->
            <div class="hlavicka-dnu grid grid-cols-7" aria-hidden="true">
                <?php foreach ($dny_v_tydnu as $den): ?>
                    <!-- Jedna bunka s nazvem dne -->
                    <div class="py-2 text-center"><?php echo $den; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- Mrizka dni (7 sloupcu); bunky vygeneruje script.js podle zvoleneho mesice -->
            <div id="mrizka-dni" class="mrizka-dni grid grid-cols-7"></div>
        </section>

        <!-- ===== Agenda vybraneho dne (jen na mobilu - bunky jsou tam na text male) ===== -->
        <section id="agenda" class="agenda" aria-labelledby="agenda-nadpis">
            <!-- Nadpis s datem (vyplni script.js) -->
            <h3 id="agenda-nadpis" class="agenda-nadpis"></h3>
            <!-- Seznam udalosti dne -->
            <div id="agenda-seznam" class="agenda-seznam"></div>
        </section>

        <!-- Rucne psana napoveda pod kalendarem (na mobilu kratsi verze) -->
        <p class="poznamka napoveda-pocitac mt-6">
            psst… klikni na den a přidej událost, klikni na lístek a uvidíš detail i přípravu.
            Lístek jde přetáhnout na jiný den. Zkratky: <kbd class="klavesa">N</kbd> nová událost,
            <kbd class="klavesa">/</kbd> hledat, <kbd class="klavesa">T</kbd> dnes, <kbd class="klavesa">←</kbd> <kbd class="klavesa">→</kbd> měsíce.
        </p>
        <p class="poznamka napoveda-mobil mt-4">psst… ťukni na den a uvidíš jeho události, plusem vpravo dole přidáš novou.</p>
    </main>

    <!-- Kulate tlacitko "+" vpravo dole (jen na mobilu) -->
    <button id="fab-nova" type="button" class="fab" aria-label="Nová událost">
        <!-- Plus jako SVG (ostre na kazdem displeji) -->
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
    </button>

    <!-- Kratke oznameni dole na obrazovce (napr. "Podúkol smazán · Zpět"); role="status" = ctecka ho precte -->
    <div id="oznameni" class="oznameni hidden" role="status" aria-live="polite">
        <!-- Text oznameni -->
        <span id="oznameni-text"></span>
        <!-- Volitelna akce (napr. vratit smazani) -->
        <button id="oznameni-akce" type="button" class="oznameni-akce hidden">Zpět</button>
    </div>

    <!-- ================================================================
         MODALNI OKNO 1: Pridani nove udalosti
         ================================================================ -->
    <!-- Poloprusvitne pozadi pres celou obrazovku; vychozi stav skryty -->
    <div id="modal-pridat" class="modal-pozadi hidden fixed inset-0 flex p-4 z-50">
        <!-- Okno jako list papiru; role a aria-* rikaji ctecce obrazovky, ze jde o dialog -->
        <div class="modal-okno w-full max-w-md" role="dialog" aria-modal="true" aria-labelledby="titulek-pridat">
            <!-- Krizek pro zavreni (hlavne pro mobil) -->
            <button type="button" data-zavrit class="zavrit-x" aria-label="Zavřít">×</button>
            <!-- Nadpis okna -->
            <h3 id="titulek-pridat" class="titulek-okna">Nová událost</h3>

            <!-- Formular; odeslani zachyti script.js a posle data pres AJAX -->
            <form id="form-pridat" class="space-y-4">
                <!-- Pole: nazev udalosti -->
                <div>
                    <!-- Popisek pole (atribut for propojuje popisek s inputem) -->
                    <label for="pole-nazev" class="popisek">Co se děje? *</label>
                    <!-- Textove pole; required = prohlizec nedovoli odeslat prazdne -->
                    <input id="pole-nazev" type="text" maxlength="255" required autocomplete="off"
                           placeholder="např. Pohřeb, Maturitní ples…" class="pole">
                </div>

                <!-- Pole: datum a cas vedle sebe -->
                <div class="grid grid-cols-2 gap-3">
                    <!-- Datum -->
                    <div>
                        <!-- Popisek pole datum -->
                        <label for="pole-datum" class="popisek">Kdy? *</label>
                        <!-- Vyber data (predvyplni se podle dne, na ktery uzivatel klikl);
                             min/max odpovidaji povolenemu rozsahu let v api.php -->
                        <input id="pole-datum" type="date" required min="1900-01-01" max="2200-12-31" class="pole">
                    </div>
                    <!-- Cas -->
                    <div>
                        <!-- Popisek pole cas -->
                        <label for="pole-cas" class="popisek">V kolik? *</label>
                        <!-- Vyber casu ve formatu HH:MM -->
                        <input id="pole-cas" type="time" required value="12:00" class="pole">
                    </div>
                </div>

                <!-- Pole: volitelny popis -->
                <div>
                    <!-- Popisek pole popis -->
                    <label for="pole-popis" class="popisek">Poznámka <span class="nepovinne">(nepovinná – AI z ní pozná víc)</span></label>
                    <!-- Viceradkove textove pole; maxlength odpovida limitu v api.php -->
                    <textarea id="pole-popis" rows="3" maxlength="2000" class="pole"></textarea>
                </div>

                <!-- Misto pro chybu pri ukladani; vychozi stav skryty -->
                <p id="chyba-formulare" class="hlaska-chyba hidden" role="alert"></p>

                <!-- Tlacitka formulare zarovnana doprava -->
                <div class="tlacitka-formulare">
                    <!-- Zavreni okna bez ulozeni (type="button" = neodesila formular) -->
                    <button type="button" data-zavrit class="btn">Zrušit</button>
                    <!-- Odeslani formulare = ulozeni udalosti -->
                    <button type="submit" id="btn-ulozit" class="btn btn-hlavni">Zapsat do kalendáře</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================================
         MODALNI OKNO 2: Detail udalosti
         ================================================================ -->
    <!-- Poloprusvitne pozadi; vychozi stav skryty -->
    <div id="modal-detail" class="modal-pozadi hidden fixed inset-0 flex p-4 z-50">
        <!-- Okno jako list papiru; tabindex="-1" = script.js do nej muze presunout fokus -->
        <div class="modal-okno w-full max-w-lg" role="dialog" aria-modal="true" aria-labelledby="detail-nazev" tabindex="-1">
            <!-- Krizek pro zavreni -->
            <button type="button" data-zavrit class="zavrit-x" aria-label="Zavřít">×</button>

            <!-- Datum a cas udalosti s tuzkou pro presun -->
            <div class="relative">
                <!-- Tuzka pro zmenu data a casu (&#xFE0E; = zobrazit jako znak, ne jako barevne emoji) -->
                <button id="btn-presunout" type="button" class="btn-tuzka btn-tuzka-kdy"
                        title="Změnit datum a čas" aria-label="Změnit datum a čas">&#9998;&#xFE0E;</button>
                <!-- Datum a cas (vyplni script.js) -->
                <p id="detail-kdy" class="detail-kdy"></p>
            </div>
            <!-- Formular pro presun udalosti (jine datum a cas); vychozi stav skryty -->
            <form id="form-presun" class="form-presun hidden" aria-label="Přesun události">
                <!-- Nove datum -->
                <input id="presun-datum" type="date" required min="1900-01-01" max="2200-12-31" class="pole" aria-label="Nové datum">
                <!-- Novy cas -->
                <input id="presun-cas" type="time" required class="pole" aria-label="Nový čas">
                <!-- Ulozit / zrusit -->
                <button type="submit" class="btn btn-hlavni btn-maly">Uložit</button>
                <button type="button" id="btn-presun-zrusit" class="btn btn-maly">Zrušit</button>
            </form>

            <!-- Obal nazvu: relative = tuzka se umisti vlevo od textu a text se neposune -->
            <div class="relative">
                <!-- Tuzka pro upravu nazvu -->
                <button id="btn-upravit-nazev" type="button" class="btn-tuzka"
                        title="Upravit název" aria-label="Upravit název">&#9998;&#xFE0E;</button>
                <!-- Nazev udalosti (vyplni script.js); po kliknuti na tuzku jde primo prepsat -->
                <h3 id="detail-nazev" class="titulek-okna break-words"></h3>
            </div>
            <!-- Obal popisu, stejny princip jako u nazvu -->
            <div class="relative">
                <!-- Tuzka pro upravu popisu -->
                <button id="btn-upravit-popis" type="button" class="btn-tuzka btn-tuzka-popis"
                        title="Upravit popis" aria-label="Upravit popis">&#9998;&#xFE0E;</button>
                <!-- Popis udalosti; whitespace-pre-wrap zachova odradkovani i pri upravach -->
                <p id="detail-popis" class="detail-popis whitespace-pre-wrap break-words"></p>
            </div>
            <!-- Chyba pri ukladani uprav nebo mazani; vychozi stav skryty -->
            <p id="chyba-detailu" class="hlaska-chyba hidden mb-4" role="alert"></p>

            <!-- ===== Pripravne podukoly (AI i vlastni, obsluhuje script.js) ===== -->
            <div class="sekce-ai">
                <!-- Nadpis sekce a prubeh (napr. "2 z 5 hotovo · zbývá ~40 min") -->
                <div class="flex items-baseline justify-between gap-3 mb-1">
                    <h4 class="nadpis-sekce">Příprava</h4>
                    <span id="podukoly-prubeh" class="napoveda text-right"></span>
                </div>
                <!-- Ukazatel prubehu (proužek); vidi se, kdyz udalost podukoly ma -->
                <div id="ukazatel-prubehu" class="ukazatel hidden" role="progressbar"
                     aria-label="Splněno podúkolů" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <div id="ukazatel-vypln" class="ukazatel-vypln"></div>
                </div>
                <!-- Sem script.js vykresli seznam podukolu; aria-live = ctecka oznami zmeny -->
                <div id="podukoly-kontejner" aria-live="polite"></div>
                <!-- Pridani vlastniho podukolu (funguje i bez AI) -->
                <form id="form-novy-podukol" class="novy-podukol hidden">
                    <!-- Nazev noveho podukolu -->
                    <input id="pole-novy-podukol" type="text" maxlength="255" class="pole" autocomplete="off"
                           placeholder="Přidat vlastní podúkol…" aria-label="Název nového podúkolu">
                    <!-- Odeslani -->
                    <button type="submit" class="btn btn-maly btn-plus" aria-label="Přidat podúkol">+</button>
                </form>
                <!-- Chyba pri praci s podukoly (napr. AI neodpovedela); vychozi stav skryty -->
                <p id="chyba-podukolu" class="hlaska-chyba hidden mt-3" role="alert"></p>
                <!-- Tlacitko AI (pri generovani ukazuje nacitani); vidi se, kdyz udalost nema podukoly -->
                <button id="btn-ai-podukoly" type="button" class="btn-ai hidden">
                    <!-- Jiskra (ikona AI) -->
                    <svg class="btn-ai-ikona" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9zM19 15l.9 2.6 2.6.9-2.6.9L19 22l-.9-2.6-2.6-.9 2.6-.9z"/></svg>
                    <!-- Tocici se kolecko - jen behem generovani -->
                    <span class="nacitani hidden" aria-hidden="true"></span>
                    <!-- Text tlacitka (behem generovani se meni) -->
                    <span class="btn-ai-text">Vygenerovat podúkoly (AI)</span>
                </button>
                <!-- Poznamka pod tlacitkem (vysvetleni nebo duvod, proc AI nejde) -->
                <p id="poznamka-ai" class="poznamka-ai hidden"></p>
                <!-- Akce se seznamem; vidi se, kdyz udalost podukoly ma -->
                <div id="akce-podukolu" class="akce-podukolu hidden">
                    <!-- Nahradit podukoly novymi (s potvrzenim) -->
                    <button id="btn-pregenerovat" type="button" class="odkaz">vygenerovat znovu (AI)</button>
                    <!-- Oddelovac -->
                    <span aria-hidden="true">·</span>
                    <!-- Smazat vsechny podukoly (s potvrzenim) -->
                    <button id="btn-smazat-podukoly" type="button" class="odkaz">smazat všechny</button>
                </div>
                <!-- Potvrzeni hromadne akce (smazat vse / vygenerovat znovu); vychozi stav skryty -->
                <div id="potvrzeni-podukolu" class="potvrzeni-smazani hidden mt-3" role="alertdialog" aria-labelledby="text-potvrzeni-podukolu">
                    <!-- Otazka (text doplni script.js) -->
                    <p id="text-potvrzeni-podukolu" class="mb-3"></p>
                    <!-- Tlacitka potvrzeni -->
                    <div class="tlacitka-formulare">
                        <!-- Bezpecna volba -->
                        <button id="btn-podukoly-ne" type="button" class="btn">Nechat být</button>
                        <!-- Provedeni akce -->
                        <button id="btn-podukoly-ano" type="button" class="btn btn-nebezpeci">Ano</button>
                    </div>
                </div>
            </div>

            <!-- Spodni tlacitka: vlevo smazat, vpravo zavrit -->
            <div id="tlacitka-detailu" class="flex justify-between gap-3 mt-6">
                <!-- Smazani udalosti - nejdriv se zobrazi potvrzeni -->
                <button id="btn-smazat" type="button" class="btn btn-smazat">Smazat</button>
                <!-- data-zavrit oznacuje tlacitko, ktere zavira modalni okno -->
                <button type="button" data-zavrit class="btn">Zavřít</button>
            </div>

            <!-- Potvrzeni smazani; vychozi stav skryty, ukaze ho tlacitko Smazat -->
            <div id="potvrzeni-smazani" class="potvrzeni-smazani hidden mt-6" role="alertdialog" aria-labelledby="text-smazani">
                <!-- Otazka (nazev udalosti doplni script.js) -->
                <p id="text-smazani" class="mb-3"></p>
                <!-- Tlacitka potvrzeni -->
                <div class="tlacitka-formulare">
                    <!-- Bezpecna volba - zrusi mazani -->
                    <button id="btn-smazat-ne" type="button" class="btn">Nechat být</button>
                    <!-- Opravdove smazani -->
                    <button id="btn-smazat-ano" type="button" class="btn btn-nebezpeci">Ano, smazat</button>
                </div>
            </div>
        </div>
    </div>

<?php
// Konec stranky s nactenim JavaScriptu
konecStranky('script.js');
