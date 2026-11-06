// =====================================================================
// script.js - logika kalendare Planodar
//  - vykresleni mrizky dni pro zvoleny mesic (s animaci prechodu)
//  - prepinani mesicu (tlacitka, sipky na klavesnici, zkratka T = dnes)
//  - modalni okna (pridani udalosti, detail udalosti)
//  - uprava nazvu, popisu, data a casu udalosti primo v detailu
//  - presun udalosti pretazenim listku na jiny den (mys)
//  - agenda vybraneho dne (na mobilu, kde jsou bunky male)
//  - vyhledavani udalosti (zkratka /)
//  - pripravne podukoly: generovani pomoci AI, vlastni podukoly,
//    odskrtavani, hvezdicka "dulezite", odhad casu, uprava, mazani se "Zpet"
//  - AJAX komunikace s api.php a podukoly.php pomoci fetch()
// =====================================================================

// "use strict" zapne prisnejsi kontrolu chyb v JavaScriptu
'use strict';

// ---------------------------------------------------------------------
// Konstanty
// ---------------------------------------------------------------------

// Adresa API udalosti
const API_URL = 'api.php';
// Adresa API podukolu (vcetne generovani pomoci AI)
const PODUKOLY_URL = 'podukoly.php';
// CSRF token z <meta name="csrf-token"> (vlozi ho PHP); posila se s kazdym pozadavkem,
// aby server poznal, ze pozadavek pochazi z nasi stranky a ne z ciziho webu
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

// Ceske nazvy mesicu (index 0 = leden, 11 = prosinec)
const NAZVY_MESICU = [
    'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen',
    'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'
];

// Pocet barev listku udalosti (tridy barva-0 az barva-3 v style.css)
const POCET_BAREV = 4;
// Kolik listku se v bunce ukaze najednou; zbytek schova tlacitko "+N dalsi"
// (jinak by jeden plny den roztahl cely tyden do vysky)
const MAX_LISTKU_V_BUNCE = 3;
// Povoleny rozsah let - stejny jako v api.php (mimo nej by API vratilo chybu)
const ROK_MIN = 1900;
const ROK_MAX = 2200;
// Uzka obrazovka (mobil) - stejna hranice jako v style.css
const MOBIL = window.matchMedia('(max-width: 640px)');
// Zarizeni s presnym ukazatelem (mys) - jen tam ma smysl pretahovani listku
const MYS = window.matchMedia('(pointer: fine)');
// Uzivatel si v systemu vypnul animace
const BEZ_ANIMACI = window.matchMedia('(prefers-reduced-motion: reduce)');

// ---------------------------------------------------------------------
// Stav aplikace (promenne, ktere se behem pouzivani meni)
// ---------------------------------------------------------------------

// Prave zobrazeny rok a mesic 1-12 (nastavi se pri spusteni z data-rok, data-mesic)
let zobrazenyRok;
let zobrazenyMesic;
// Dnesni datum jako text RRRR-MM-DD (pro zvyrazneni dnesni bunky)
let dnesniDatum;
// Vybrany den (agenda na mobilu, vychozi datum nove udalosti)
let vybranyDen = '';
// Udalosti nactene z API pro zobrazeny mesic
let udalosti = [];
// Poradove cislo posledniho pozadavku na API - pri rychlem prepinani mesicu
// tak pozname a zahodime starsi odpoved, ktera dorazila az po novejsi
let cisloPozadavku = 0;
// Smer animace pri dalsim vykresleni (-1 = predchozi mesic, 1 = dalsi, 0 = bez animace)
let smerAnimace = 0;
// Prvek, ktery mel fokus pred otevrenim modalniho okna (po zavreni ho vratime)
let fokusPredModalem = null;
// Udalost, ktera je prave zobrazena v okne s detailem
let aktualniUdalost = null;
// Prvek (nazev nebo popis), ktery se prave upravuje; null = zadna uprava neprobiha
let upravovanyPrvek = null;
// Je na serveru nastaveny API klic pro AI? (nastavi se pri spusteni z data-ai)
let aiNastavena = false;
// Podukoly prave zobrazene udalosti
let podukoly = [];
// Poradove cislo nacitani podukolu (starsi odpoved po prepnuti udalosti zahodime)
let cisloNacitaniPodukolu = 0;
// Id udalosti, pro ktere prave AI generuje (generovani muze bezet i po zavreni okna)
const generovaneUdalosti = new Set();
// Hromadna akce cekajici na potvrzeni: 'smazat' nebo 'pregenerovat' (null = zadna)
let cekajiciAkcePodukolu = null;
// Pri dalsim vykresleni seznamu podukoly "pritancuji" (jen po vygenerovani AI)
let animovatNove = false;
// Casovac a akce kratkeho oznameni dole na obrazovce
let casovacOznameni = null;
let akceOznameni = null;
// Vyhledavani: casovac (cekani na dopsani), cislo pozadavku, vysledky, oznaceny vysledek
let casovacHledani = null;
let cisloHledani = 0;
let vysledkyHledani = [];
let aktivniVysledek = -1;
// Id udalosti, jejiz listek se prave pretahuje mysi (null = nic)
let tazenaUdalost = null;

// ---------------------------------------------------------------------
// Odkazy na HTML prvky (ziskame je jednou, at je nehledame stale znovu)
// ---------------------------------------------------------------------

// Pomocna zkratka pro document.getElementById
const $ = function (id) { return document.getElementById(id); };

// Kalendar a jeho casti
const elKalendar = $('kalendar');
const elMrizka = $('mrizka-dni');
const elNadpis = $('nadpis-mesice');
const elChyba = $('chyba-hlaska');
// Agenda vybraneho dne
const elAgenda = $('agenda');
const elAgendaNadpis = $('agenda-nadpis');
const elAgendaSeznam = $('agenda-seznam');
// Vyhledavani
const elPoleHledat = $('pole-hledat');
const elVysledky = $('vysledky-hledani');
// Kratke oznameni
const elOznameni = $('oznameni');
const elOznameniText = $('oznameni-text');
const elOznameniAkce = $('oznameni-akce');

// Modalni okno pro pridani udalosti a jeho formular
const elModalPridat = $('modal-pridat');
const elForm = $('form-pridat');
const elPoleNazev = $('pole-nazev');
const elPoleDatum = $('pole-datum');
const elPoleCas = $('pole-cas');
const elPolePopis = $('pole-popis');
const elChybaFormulare = $('chyba-formulare');
const elBtnUlozit = $('btn-ulozit');

// Modalni okno s detailem udalosti a jeho casti
const elModalDetail = $('modal-detail');
const elDetailNazev = $('detail-nazev');
const elDetailKdy = $('detail-kdy');
const elDetailPopis = $('detail-popis');
const elChybaDetailu = $('chyba-detailu');
// Presun udalosti (jine datum a cas)
const elFormPresun = $('form-presun');
const elPresunDatum = $('presun-datum');
const elPresunCas = $('presun-cas');
// Podukoly
const elPodukoly = $('podukoly-kontejner');
const elPodukolyPrubeh = $('podukoly-prubeh');
const elUkazatel = $('ukazatel-prubehu');
const elUkazatelVypln = $('ukazatel-vypln');
const elFormNovyPodukol = $('form-novy-podukol');
const elPoleNovyPodukol = $('pole-novy-podukol');
const elChybaPodukolu = $('chyba-podukolu');
// Tlacitko "Vygenerovat podukoly (AI)" a jeho casti
const elBtnAi = $('btn-ai-podukoly');
const elBtnAiText = elBtnAi.querySelector('.btn-ai-text');
const elBtnAiNacitani = elBtnAi.querySelector('.nacitani');
const elBtnAiIkona = elBtnAi.querySelector('.btn-ai-ikona');
const elPoznamkaAi = $('poznamka-ai');
// Radek akci "vygenerovat znovu · smazat vsechny"
const elAkcePodukolu = $('akce-podukolu');
const elBtnPregenerovat = $('btn-pregenerovat');
// Potvrzeni hromadne akce s podukoly
const elPotvrzeniPodukolu = $('potvrzeni-podukolu');
const elTextPotvrzeniPodukolu = $('text-potvrzeni-podukolu');
const elBtnPodukolyAno = $('btn-podukoly-ano');
const elBtnPodukolyNe = $('btn-podukoly-ne');
// Tlacitka detailu a potvrzeni smazani udalosti
const elTlacitkaDetailu = $('tlacitka-detailu');
const elPotvrzeniSmazani = $('potvrzeni-smazani');
const elTextSmazani = $('text-smazani');
const elBtnSmazatAno = $('btn-smazat-ano');
const elBtnSmazatNe = $('btn-smazat-ne');

// ---------------------------------------------------------------------
// Pomocne funkce
// ---------------------------------------------------------------------

// Doplni cislo na dve cifry: 5 -> "05", 12 -> "12"
function dveCifry(cislo) {
    // padStart doplni zleva nuly do delky 2
    return String(cislo).padStart(2, '0');
}

// Slozi datum do textu RRRR-MM-DD (stejny format pouziva MySQL i <input type="date">)
function slozDatum(rok, mesic, den) {
    // Spojime casti pomoci pomlcek
    return rok + '-' + dveCifry(mesic) + '-' + dveCifry(den);
}

// Prevede text RRRR-MM-DD na objekt Date (v mistnim case, bez posunu pasma)
function naDatum(datumText) {
    // Rozdelime text podle pomlcek a prevedeme casti na cisla
    const [rok, mesic, den] = datumText.split('-').map(Number);
    // Mesice jsou v JS cislovane od 0, proto -1
    return new Date(rok, mesic - 1, den);
}

// Citelne ceske datum, napr. "pátek 9. října 2026"
function hezkeDatum(datumText) {
    // Formatovani podle ceskych zvyklosti
    return naDatum(datumText).toLocaleDateString('cs-CZ', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    });
}

// Kratke datum, napr. "pá 9. 10. 2026"
function kratkeDatum(datumText) {
    // Formatovani podle ceskych zvyklosti
    return naDatum(datumText).toLocaleDateString('cs-CZ', {
        weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric'
    });
}

// Prvni pismeno velke ("pátek" -> "Pátek")
function velkePismeno(text) {
    // Prvni znak velky + zbytek
    return text.charAt(0).toUpperCase() + text.slice(1);
}

// Pocet minut jako text: 45 -> "45 min", 90 -> "1 h 30 min", 120 -> "2 h"
function formatMinut(minuty) {
    // Cele hodiny a zbytek minut
    const hodiny = Math.floor(minuty / 60);
    const zbytek = minuty % 60;
    // Mene nez hodina
    if (hodiny === 0) {
        // Jen minuty
        return minuty + ' min';
    }
    // Hodiny a pripadne minuty
    return hodiny + ' h' + (zbytek > 0 ? ' ' + zbytek + ' min' : '');
}

// Ceske sklonovani podle poctu: 1 podúkol, 2-4 podúkoly, 0 a 5+ podúkolů
function sklonuj(pocet, jeden, dva, pet) {
    // Vyber tvaru podle poctu
    const tvar = pocet === 1 ? jeden : (pocet >= 2 && pocet <= 4 ? dva : pet);
    // Cislo a slovo
    return pocet + ' ' + tvar;
}

// Zkrati dlouhy text pro oznameni (napr. nazev podukolu)
function zkrat(text, delka) {
    // Kratky text nechame byt, dlouhy zkratime a pridame trojtecku
    return text.length <= delka ? text : text.slice(0, delka - 1) + '…';
}

// Je zobrazeni mobilni (uzka obrazovka)?
function jeMobil() {
    // Vysledek media query z CSS
    return MOBIL.matches;
}

// Pise prave uzivatel do pole? (pak klavesove zkratky nesmi reagovat)
function piseUzivatel(e) {
    // Cil udalosti klavesnice
    const cil = e.target;
    // Textova pole, vyber a upravovany text
    return cil.tagName === 'INPUT' || cil.tagName === 'TEXTAREA' || cil.tagName === 'SELECT' || cil.isContentEditable;
}

// Zobrazi chybovou hlasku nad kalendarem
function zobrazChybu(text) {
    // Nastavime text hlasky a zobrazime ji
    elChyba.textContent = text;
    elChyba.classList.remove('hidden');
}

// Skryje chybovou hlasku nad kalendarem
function skryjChybu() {
    // Pridame tridu hidden -> hlaska zmizi
    elChyba.classList.add('hidden');
}

// ---------------------------------------------------------------------
// Kratke oznameni dole na obrazovce (napr. "Podúkol smazán · Zpět")
// ---------------------------------------------------------------------

// Ukaze oznameni; popisAkce + akce = volitelne tlacitko (napr. "Zpět")
function ukazOznameni(text, popisAkce, akce) {
    // Text oznameni
    elOznameniText.textContent = text;
    // Akce tlacitka (nebo zadna)
    akceOznameni = akce || null;
    // Text tlacitka a jeho viditelnost
    elOznameniAkce.textContent = popisAkce || '';
    elOznameniAkce.classList.toggle('hidden', !akce);
    // Zobrazime oznameni a znovu spustime animaci vyjeti
    elOznameni.classList.remove('hidden', 'je-videt');
    // Precteni rozmeru vynuti prekresleni - animace se spusti znovu
    void elOznameni.offsetWidth;
    elOznameni.classList.add('je-videt');
    // Stary casovac zrusime a nastavime novy (s akci dele, at ji uzivatel stihne)
    clearTimeout(casovacOznameni);
    casovacOznameni = setTimeout(skryjOznameni, akce ? 7000 : 3500);
}

// Skryje oznameni
function skryjOznameni() {
    // Skryti a zapomenuti akce
    elOznameni.classList.add('hidden');
    akceOznameni = null;
}

// ---------------------------------------------------------------------
// Komunikace s API (AJAX)
// ---------------------------------------------------------------------

// Precte odpoved serveru jako JSON; pri chybe vyhodi srozumitelnou zpravu
async function prectiOdpoved(odpoved) {
    // Nejdriv precteme telo jako obycejny text
    const text = await odpoved.text();
    // Promenna pro data prevedena z JSON
    let data;
    // Pokus o prevod textu na JSON
    try {
        // JSON.parse vyhodi chybu, pokud text neni platny JSON
        data = JSON.parse(text);
    } catch (e) {
        // Server poslal neco jineho (napr. HTML s chybou PHP)
        throw new Error('Server neodpověděl ve formátu JSON (HTTP ' + odpoved.status + ').');
    }
    // 401 = uzivatel uz neni prihlaseny (vyprsela session, odhlaseni na jinem zarizeni...)
    if (odpoved.status === 401) {
        // Presmerujeme na prihlasovaci stranku
        window.location.href = 'prihlaseni.php';
        // A volajici funkci dame vedet, ze pozadavek neprosel
        throw new Error('Byli jste odhlášeni.');
    }
    // Pokud server vratil chybovy kod (4xx, 5xx), vyhodime chybu s jeho zpravou
    if (!odpoved.ok) {
        // Chyba se zpravou ze serveru
        const chyba = new Error(data.chyba || 'Neznámá chyba serveru.');
        // Pridame i HTTP kod (napr. 409 = konflikt), aby na nej slo reagovat
        chyba.status = odpoved.status;
        // Chybu zachyti volajici funkce v bloku catch
        throw chyba;
    }
    // Vse v poradku - vratime data
    return data;
}

// Odesle pozadavek na server a vrati prectena data; pri jakekoli chybe vyhodi ceskou zpravu
async function posliPozadavek(url, nastaveni = {}) {
    // K hlavickam pozadavku pridame CSRF token (Object.assign spoji objekty do noveho)
    const hlavicky = Object.assign({}, nastaveni.headers, { 'X-CSRF-Token': CSRF_TOKEN });
    // Promenna pro odpoved serveru
    let odpoved;
    // try/catch zachyti chybu site (server nebezi, vypadek pripojeni...)
    try {
        // Odesleme pozadavek s doplnenymi hlavickami a pockame na odpoved
        odpoved = await fetch(url, Object.assign({}, nastaveni, { headers: hlavicky }));
    } catch (e) {
        // fetch() vraci anglickou hlasku "Failed to fetch" - nahradime ji srozumitelnou
        throw new Error('Nepodařilo se spojit se serverem. Jsi připojený k internetu?');
    }
    // Precteme odpoved (pri chybe serveru se vyhodi vyjimka s jeho zpravou)
    return prectiOdpoved(odpoved);
}

// Odesle data jako JSON danou metodou (POST, PUT, DELETE)
function posliJson(url, metoda, data) {
    // Pozadavek s telem ve formatu JSON
    return posliPozadavek(url, {
        method: metoda,                                      // HTTP metoda
        headers: { 'Content-Type': 'application/json' },     // typ odesilanych dat
        body: JSON.stringify(data)                           // objekt prevedeny na JSON text
    });
}

// Nacte udalosti pro zobrazeny mesic a znovu vykresli kalendar
async function nactiUdalosti() {
    // Zvysime citac a zapamatujeme si cislo TOHOTO pozadavku
    const mojeCislo = ++cisloPozadavku;
    // Behem nacitani kalendar jemne zesedne (CSS podle aria-busy)
    elKalendar.setAttribute('aria-busy', 'true');
    // Promenna pro nactena data
    let data;
    // try/catch zachyti chyby site i chyby serveru
    try {
        // GET api.php?rok=2026&mesic=10
        data = await posliPozadavek(API_URL + '?rok=' + zobrazenyRok + '&mesic=' + zobrazenyMesic);
    } catch (chyba) {
        // Mezitim uz uzivatel prepnul jinam -> tato chyba je nezajimava
        if (mojeCislo !== cisloPozadavku) {
            // Nic nedelame
            return;
        }
        // Pri chybe nechame kalendar bez udalosti a ukazeme, co se stalo
        udalosti = [];
        elKalendar.setAttribute('aria-busy', 'false');
        vykresliKalendar();
        zobrazChybu('Události se nepodařilo načíst: ' + chyba.message);
        // Konec funkce
        return;
    }
    // Mezitim byl odeslan novejsi pozadavek -> tato odpoved je zastarala, zahodime ji
    if (mojeCislo !== cisloPozadavku) {
        // Nic nevykreslujeme
        return;
    }
    // Ulozime nactene udalosti do stavu aplikace
    udalosti = data;
    // Otevreny detail prevezme cerstvy objekt sve udalosti (stejne id)
    if (aktualniUdalost !== null) {
        // Hledani podle id
        const cerstva = udalosti.find(function (u) { return u.id === aktualniUdalost.id; });
        // Nalezena -> pouzijeme ji
        if (cerstva) {
            // Nahrada objektu
            aktualniUdalost = cerstva;
        }
    }
    // Vse probehlo v poradku, pripadnou starou chybu skryjeme
    skryjChybu();
    elKalendar.setAttribute('aria-busy', 'false');
    // Vykreslime kalendar i s udalostmi
    vykresliKalendar();
}

// ---------------------------------------------------------------------
// Vykresleni kalendare
// ---------------------------------------------------------------------

// Vytvori mrizku dni pro zobrazeny mesic a vlozi do ni udalosti
function vykresliKalendar() {
    // Do nadpisu napiseme nazev mesice a rok, napr. "Říjen 2026"
    elNadpis.textContent = NAZVY_MESICU[zobrazenyMesic - 1] + ' ' + zobrazenyRok;
    // Zacatek data zobrazeneho mesice, napr. "2026-10-"
    const predpona = zobrazenyRok + '-' + dveCifry(zobrazenyMesic) + '-';
    // Vybrany den musi lezet v zobrazenem mesici (jinak dnesek, nebo 1. den mesice)
    if (!vybranyDen.startsWith(predpona)) {
        // Novy vybrany den
        vybranyDen = dnesniDatum.startsWith(predpona) ? dnesniDatum : predpona + '01';
    }

    // Smazeme stary obsah mrizky
    elMrizka.innerHTML = '';
    // Den v tydnu prvniho dne mesice: getDay() vraci 0 = nedele ... 6 = sobota
    const prvniDen = new Date(zobrazenyRok, zobrazenyMesic - 1, 1).getDay();
    // Prepocet, aby tyden zacinal pondelim: pondeli = 0 ... nedele = 6
    const posun = (prvniDen + 6) % 7;
    // Pocet dni v mesici: den 0 dalsiho mesice = posledni den tohoto mesice
    const pocetDni = new Date(zobrazenyRok, zobrazenyMesic, 0).getDate();
    // Celkovy pocet bunek zaokrouhleny nahoru na cele tydny (nasobek 7)
    const pocetBunek = Math.ceil((posun + pocetDni) / 7) * 7;

    // Projdeme vsechny bunky mrizky
    for (let i = 0; i < pocetBunek; i++) {
        // Cislo dne v mesici pro tuto bunku (muze byt mimo rozsah 1..pocetDni)
        const den = i - posun + 1;
        // Bunka mimo aktualni mesic -> prazdne srafovane policko
        if (den < 1 || den > pocetDni) {
            // Prazdny div
            const prazdna = document.createElement('div');
            prazdna.className = 'bunka-dne bunka-prazdna';
            // Vlozime ho do mrizky a pokracujeme dalsi bunkou
            elMrizka.appendChild(prazdna);
            continue;
        }
        // Sloupec 5 a 6 (pocitano od 0) = sobota a nedele
        const jeVikend = (i % 7) >= 5;
        // Vytvorime a vlozime bunku dne
        elMrizka.appendChild(vytvorBunkuDne(den, slozDatum(zobrazenyRok, zobrazenyMesic, den), jeVikend));
    }

    // Animace pri zmene mesice: mrizka "vyjede" ze strany, kam se prepina
    if (smerAnimace !== 0) {
        // Odebereme starou animaci
        elMrizka.classList.remove('prechod-vpred', 'prechod-vzad');
        // Precteni rozmeru vynuti prekresleni - stejna animace se spusti znovu
        void elMrizka.offsetWidth;
        // Nova animace podle smeru
        elMrizka.classList.add(smerAnimace > 0 ? 'prechod-vpred' : 'prechod-vzad');
        // Animace jen jednou
        smerAnimace = 0;
    }
    // Agenda vybraneho dne (mobil)
    vykresliAgendu();
}

// Vytvori HTML prvek jedne bunky (dne) vcetne jejich udalosti
function vytvorBunkuDne(den, datum, jeVikend) {
    // Hlavni div bunky
    const bunka = document.createElement('div');
    // Zakladni trida bunky (styly v style.css)
    bunka.className = 'bunka-dne';
    // Ulozime si datum primo do prvku (pouziva ho vyber dne)
    bunka.dataset.datum = datum;
    // tabindex 0 = na bunku se da prejit klavesou Tab
    bunka.tabIndex = 0;
    // Ctecka obrazovky bunku oznami jako tlacitko
    bunka.setAttribute('role', 'button');
    // Vikendove dny dostanou jine pozadi
    if (jeVikend) {
        // Trida bunka-vikend je definovana v style.css
        bunka.classList.add('bunka-vikend');
    }
    // Pokud je to dnesni den, pridame zvyraznujici tridu
    if (datum === dnesniDatum) {
        // Zvyrazneni a informace pro ctecku obrazovky
        bunka.classList.add('bunka-dnes');
        bunka.setAttribute('aria-current', 'date');
    }
    // Vybrany den (agenda na mobilu)
    if (datum === vybranyDen) {
        // Zvyrazneni vybraneho dne
        bunka.classList.add('bunka-vybrana');
    }

    // Cislo dne v levem hornim rohu
    const cislo = document.createElement('div');
    cislo.className = 'cislo-dne';
    cislo.textContent = den;
    bunka.appendChild(cislo);

    // Vybereme jen udalosti, ktere patri k tomuto datu
    const udalostiDne = udalosti.filter(function (u) {
        // Porovnavame text data z databaze s datem bunky
        return u.datum === datum;
    });
    // Popis bunky pro ctecku obrazovky, napr. "pátek 9. října 2026, 2 události"
    bunka.setAttribute('aria-label', hezkeDatum(datum) + (udalostiDne.length > 0 ? ', událostí: ' + udalostiDne.length : ''));
    // Napoveda po najeti mysi (na mobilu se bunka jen vybere)
    bunka.title = 'Přidat událost na ' + hezkeDatum(datum);

    // Pro kazdou udalost vytvorime lepici listek
    udalostiDne.forEach(function (udalost, poradi) {
        // Listek (tlacitko) vlozime do bunky
        const stitek = vytvorListek(udalost);
        // Listky nad limit zatim schovame
        if (poradi >= MAX_LISTKU_V_BUNCE) {
            // Listek bude neviditelny, dokud uzivatel neklikne na "+N dalsi"
            stitek.classList.add('hidden');
        }
        // Vlozime listek do bunky
        bunka.appendChild(stitek);
    });

    // Pocet schovanych listku
    const pocetSkrytych = udalostiDne.length - MAX_LISTKU_V_BUNCE;
    // Pokud nejake listky chybi, pridame tlacitko, ktere je ukaze
    if (pocetSkrytych > 0) {
        // Tlacitko "+N dalsi"
        const dalsi = document.createElement('button');
        dalsi.type = 'button';
        dalsi.className = 'btn-dalsi-listky';
        dalsi.textContent = '+' + pocetSkrytych + ' další';
        // Kliknuti rozbali vsechny listky v teto bunce
        dalsi.addEventListener('click', function (e) {
            // Kliknuti nesmi probublat do bunky (otevrelo by se pridani udalosti)
            e.stopPropagation();
            // Zobrazime schovane listky a tlacitko odstranime
            bunka.querySelectorAll('.stitek-udalosti.hidden').forEach(function (listek) {
                listek.classList.remove('hidden');
            });
            dalsi.remove();
        });
        // Vlozime tlacitko na konec bunky
        bunka.appendChild(dalsi);
    }

    // Kliknuti na volne misto v bunce: na mobilu vyber dne, na pocitaci nova udalost
    bunka.addEventListener('click', function () {
        // Akce podle typu zobrazeni
        aktivujDen(datum);
    });
    // Ovladani klavesnici: Enter nebo mezernik na vybrane bunce
    bunka.addEventListener('keydown', function (e) {
        // Reagujeme jen na klavesu stisknutou primo na bunce (ne na listku uvnitr)
        if (e.target === bunka && (e.key === 'Enter' || e.key === ' ')) {
            // Zabranime rolovani stranky mezernikem
            e.preventDefault();
            // Stejna akce jako kliknuti
            aktivujDen(datum);
        }
    });

    // Pretahovani listku (jen mysi): bunka je "cil", kam jde listek pustit
    bunka.addEventListener('dragover', function (e) {
        // Pretahuje se nas listek?
        if (tazenaUdalost === null) {
            // Cizi obsah (napr. soubor) ignorujeme
            return;
        }
        // preventDefault = sem jde pustit
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        // Zvyrazneni cile
        bunka.classList.add('cil-pusteni');
    });
    // Listek opustil bunku -> zrusime zvyrazneni
    bunka.addEventListener('dragleave', function (e) {
        // relatedTarget = kam mys presla; uvnitr bunky (napr. na listek) zvyrazneni nechame
        if (!bunka.contains(e.relatedTarget)) {
            // Zruseni zvyrazneni
            bunka.classList.remove('cil-pusteni');
        }
    });
    // Listek byl pusten na tuto bunku -> presun udalosti
    bunka.addEventListener('drop', function (e) {
        // Neni co pustit
        if (tazenaUdalost === null) {
            // Konec
            return;
        }
        // Zabranime vychozi akci prohlizece (napr. otevreni odkazu)
        e.preventDefault();
        bunka.classList.remove('cil-pusteni');
        // Najdeme pretahovanou udalost
        const udalost = udalosti.find(function (u) { return u.id === tazenaUdalost; });
        // Pustena na jiny den -> presun (cas zustava)
        if (udalost && udalost.datum !== datum) {
            // Presun s moznosti "Zpet"
            presunUdalost(udalost, datum, udalost.cas, true);
        }
    });

    // Vratime hotovou bunku
    return bunka;
}

// Vytvori lepici listek jedne udalosti (tlacitko, ktere otevre detail)
function vytvorListek(udalost) {
    // Listek je tlacitko (lze na nej kliknout i klavesnici)
    const stitek = document.createElement('button');
    stitek.type = 'button';
    // Styl listku + jedna ze 4 barev podle id (stejna udalost ma vzdy stejnou barvu)
    stitek.className = 'stitek-udalosti barva-' + (udalost.id % POCET_BAREV);
    // Text listku: tucny cas a nazev (textContent chrani pred vlozenim HTML = XSS)
    const text = document.createElement('span');
    text.className = 'stitek-text';
    const cas = document.createElement('b');
    cas.className = 'stitek-cas';
    cas.textContent = udalost.cas;
    text.appendChild(cas);
    text.appendChild(document.createTextNode(' ' + udalost.nazev));
    stitek.appendChild(text);
    // Po najeti mysi se ukaze cely text
    stitek.title = udalost.cas + ' – ' + udalost.nazev;

    // Ma udalost podukoly? -> male pocitadlo "2/5" (vse hotovo = fajfka)
    if (udalost.podukoly_celkem > 0) {
        // Prvek pocitadla
        const pocet = document.createElement('span');
        // Vse splneno?
        const hotovo = udalost.podukoly_splneno === udalost.podukoly_celkem;
        pocet.className = 'stitek-pocet' + (hotovo ? ' je-hotovo' : '');
        pocet.textContent = hotovo ? '✓' : udalost.podukoly_splneno + '/' + udalost.podukoly_celkem;
        stitek.appendChild(pocet);
        // Doplnime napovedu
        stitek.title += ' (příprava: ' + udalost.podukoly_splneno + ' z ' + udalost.podukoly_celkem + ' hotovo)';
    }

    // Kliknuti na listek otevre detail udalosti
    stitek.addEventListener('click', function (e) {
        // Zastavime "probublani" kliknuti do bunky (jinak by se otevrelo i pridani)
        e.stopPropagation();
        // Otevreme detail
        otevriDetail(udalost);
    });

    // Pretahovani mysi na jiny den (na dotykovych displejich ne - tam slouzi tuzka u data)
    if (MYS.matches) {
        // Prvek jde chytit a tahnout
        stitek.draggable = true;
        stitek.addEventListener('dragstart', function (e) {
            // Zapamatujeme si, ktera udalost se tahne
            tazenaUdalost = udalost.id;
            // Data pretahovani (nektere prohlizece bez nich tahani nezacnou)
            e.dataTransfer.setData('text/plain', String(udalost.id));
            e.dataTransfer.effectAllowed = 'move';
            // Vzhled behem tahani (listek zprusvitni, bunky ukazou, ze jsou cil)
            stitek.classList.add('taha-se');
            document.body.classList.add('tahani');
        });
        stitek.addEventListener('dragend', function () {
            // Konec tahani - uklid
            tazenaUdalost = null;
            stitek.classList.remove('taha-se');
            document.body.classList.remove('tahani');
            // Zvyrazneni cilu odstranime
            document.querySelectorAll('.cil-pusteni').forEach(function (b) { b.classList.remove('cil-pusteni'); });
        });
    }
    // Vratime hotovy listek
    return stitek;
}

// Kliknuti (nebo Enter) na den: mobil = vyber dne a agenda, pocitac = nova udalost
function aktivujDen(datum) {
    // Podle sirky obrazovky
    if (jeMobil()) {
        // Vyber dne
        vyberDen(datum);
    } else {
        // Zapamatujeme si den a otevreme pridani
        vybranyDen = datum;
        otevriPridani(datum);
    }
}

// Vybere den (zvyrazni bunku a ukaze jeho udalosti v agende)
function vyberDen(datum) {
    // Novy vybrany den
    vybranyDen = datum;
    // Prepneme zvyrazneni bunek (bez prekresleni cele mrizky)
    elMrizka.querySelectorAll('.bunka-dne[data-datum]').forEach(function (b) {
        // Zvyraznena jen bunka s timto datem
        b.classList.toggle('bunka-vybrana', b.dataset.datum === datum);
    });
    // Agenda vybraneho dne
    vykresliAgendu();
    // Agendu posuneme do zorneho pole (je pod kalendarem)
    elAgenda.scrollIntoView({ behavior: BEZ_ANIMACI.matches ? 'auto' : 'smooth', block: 'nearest' });
}

// Vykresli agendu vybraneho dne (seznam udalosti; viditelna jen na mobilu)
function vykresliAgendu() {
    // Nadpis s datem, napr. "Pátek 9. října 2026"
    elAgendaNadpis.textContent = velkePismeno(hezkeDatum(vybranyDen));
    // Vyprazdnime seznam
    elAgendaSeznam.innerHTML = '';
    // Udalosti vybraneho dne
    const udalostiDne = udalosti.filter(function (u) { return u.datum === vybranyDen; });
    // Zadne udalosti -> kratka poznamka
    if (udalostiDne.length === 0) {
        // Odstavec s poznamkou
        const prazdno = document.createElement('p');
        prazdno.className = 'agenda-prazdno';
        prazdno.textContent = 'Na tento den nemáš nic naplánováno.';
        elAgendaSeznam.appendChild(prazdno);
    }
    // Kazda udalost jako lepici listek pres celou sirku
    udalostiDne.forEach(function (udalost) {
        // Polozka je tlacitko - otevre detail
        const polozka = document.createElement('button');
        polozka.type = 'button';
        polozka.className = 'agenda-polozka barva-' + (udalost.id % POCET_BAREV);
        // Cas
        const cas = document.createElement('span');
        cas.className = 'agenda-cas';
        cas.textContent = udalost.cas;
        // Nazev
        const nazev = document.createElement('span');
        nazev.className = 'agenda-nazev';
        nazev.textContent = udalost.nazev;
        polozka.append(cas, nazev);
        // Prubeh pripravy, napr. "2/5" nebo fajfka
        if (udalost.podukoly_celkem > 0) {
            // Pocitadlo
            const pocet = document.createElement('span');
            const hotovo = udalost.podukoly_splneno === udalost.podukoly_celkem;
            pocet.className = 'stitek-pocet' + (hotovo ? ' je-hotovo' : '');
            pocet.textContent = hotovo ? '✓ připraveno' : udalost.podukoly_splneno + '/' + udalost.podukoly_celkem;
            polozka.appendChild(pocet);
        }
        // Kliknuti otevre detail
        polozka.addEventListener('click', function () { otevriDetail(udalost); });
        elAgendaSeznam.appendChild(polozka);
    });
    // Tlacitko pro pridani udalosti na vybrany den
    const pridat = document.createElement('button');
    pridat.type = 'button';
    pridat.className = 'btn agenda-pridat';
    pridat.textContent = '+ Přidat událost na tento den';
    pridat.addEventListener('click', function () { otevriPridani(vybranyDen); });
    elAgendaSeznam.appendChild(pridat);
}

// ---------------------------------------------------------------------
// Prepinani mesicu
// ---------------------------------------------------------------------

// Posune zobrazeny mesic o "krok" (-1 = predchozi, +1 = dalsi)
function zmenMesic(krok) {
    // Na hranici povoleneho rozsahu (leden 1900 / prosinec 2200) dal nepokracujeme
    if ((krok < 0 && zobrazenyRok === ROK_MIN && zobrazenyMesic === 1)
        || (krok > 0 && zobrazenyRok === ROK_MAX && zobrazenyMesic === 12)) {
        // Mesic nemenime
        return;
    }
    // Pricteme krok k mesici a osetrime prechod pres rok
    zobrazenyMesic += krok;
    if (zobrazenyMesic < 1) {
        // Prosinec predchoziho roku
        zobrazenyMesic = 12;
        zobrazenyRok--;
    }
    if (zobrazenyMesic > 12) {
        // Leden dalsiho roku
        zobrazenyMesic = 1;
        zobrazenyRok++;
    }
    // Animace ve smeru prepnuti
    smerAnimace = krok;
    // Nacteme udalosti noveho mesice (a ten se pak vykresli)
    nactiUdalosti();
}

// Prepne kalendar na mesic daneho data (s animaci ve spravnem smeru)
function prejdiNaMesic(datum) {
    // Rok a mesic z data
    const [rok, mesic] = datum.split('-').map(Number);
    // Porovnani s aktualne zobrazenym mesicem (rok * 12 + mesic = poradove cislo mesice)
    const rozdil = (rok * 12 + mesic) - (zobrazenyRok * 12 + zobrazenyMesic);
    // Smer animace (0 = stejny mesic)
    smerAnimace = Math.sign(rozdil);
    // Nastaveni mesice
    zobrazenyRok = rok;
    zobrazenyMesic = mesic;
    // Nacteni (vrati slib - da se na nej pockat)
    return nactiUdalosti();
}

// Vrati kalendar na aktualni (dnesni) mesic
function prejdiNaDnes() {
    // Vybereme dnesek a prepneme na jeho mesic
    vybranyDen = dnesniDatum;
    prejdiNaMesic(dnesniDatum);
}

// Vychozi datum nove udalosti: vybrany den v zobrazenem mesici
function vychoziDatumPridani() {
    // vybranyDen vzdy lezi v zobrazenem mesici (zajistuje vykresliKalendar)
    return vybranyDen || dnesniDatum;
}

// ---------------------------------------------------------------------
// Modalni okna
// ---------------------------------------------------------------------

// Zobrazi zadane modalni okno
function otevriModal(modal) {
    // Zapamatujeme si, kde byl fokus (napr. vybrana bunka), at ho po zavreni vratime
    if (!jeOtevrenModal()) {
        // Jen pri prvnim otevreni
        fokusPredModalem = document.activeElement;
    }
    // Odebereme tridu hidden -> okno se zobrazi
    modal.classList.remove('hidden');
    // Stranka pod oknem se nesmi rolovat (hlavne na mobilu)
    document.body.classList.add('modal-otevreny');
}

// Skryje zadane modalni okno
function zavriModal(modal) {
    // Okno uz je zavrene -> neni co delat
    if (modal.classList.contains('hidden')) {
        // Konec funkce
        return;
    }
    // Rozpracovana uprava v detailu se zrusi (blur by ji jinak ulozil az po zavreni)
    if (modal === elModalDetail) {
        // Zruseni upravy textu a schovani presunu
        zrusUpravu();
        skryjPresun();
    }
    // Pridame tridu hidden -> okno zmizi
    modal.classList.add('hidden');
    // Zadne okno uz neni otevrene -> stranka se zase muze rolovat
    if (!jeOtevrenModal()) {
        // Odemceni rolovani
        document.body.classList.remove('modal-otevreny');
    }
    // Vratime fokus na prvek, ze ktereho se okno otevrelo (pokud na strance porad je)
    if (fokusPredModalem && document.body.contains(fokusPredModalem)) {
        // Uzivatel klavesnice tak pokracuje tam, kde skoncil
        fokusPredModalem.focus();
    }
}

// Zjisti, zda je prave otevrene nejake modalni okno
function jeOtevrenModal() {
    // Hledame prvek s tridou modal-pozadi, ktery nema tridu hidden
    return document.querySelector('.modal-pozadi:not(.hidden)') !== null;
}

// Klavesa Tab v otevrenem okne: fokus "obiha" jen uvnitr okna (ne do kalendare za nim)
function udrzFokusVOkne(e) {
    // Otevrene okno
    const okno = document.querySelector('.modal-pozadi:not(.hidden) .modal-okno');
    // Zadne okno -> nic
    if (!okno) {
        // Konec
        return;
    }
    // Vsechny viditelne prvky, na ktere jde prejit Tabem
    const prvky = Array.from(okno.querySelectorAll('button, input, textarea, select, a[href], [tabindex="0"], [contenteditable="true"], [contenteditable="plaintext-only"]'))
        .filter(function (p) { return !p.disabled && p.offsetParent !== null; });
    // Nic k fokusu
    if (prvky.length === 0) {
        // Konec
        return;
    }
    // Prvni a posledni prvek
    const prvni = prvky[0];
    const posledni = prvky[prvky.length - 1];
    // Shift+Tab na prvnim (nebo na samotnem okne) -> skok na posledni
    if (e.shiftKey && (document.activeElement === prvni || document.activeElement === okno)) {
        // Presun fokusu
        e.preventDefault();
        posledni.focus();
    } else if (!e.shiftKey && document.activeElement === posledni) {
        // Tab na poslednim -> skok na prvni
        e.preventDefault();
        prvni.focus();
    }
}

// Otevre okno pro pridani udalosti s predvyplnenym datem
function otevriPridani(datum) {
    // Vymazeme hodnoty z minuleho pouziti formulare
    elForm.reset();
    // Predvyplnime datum
    elPoleDatum.value = datum;
    // Skryjeme pripadnou starou chybu formulare
    elChybaFormulare.classList.add('hidden');
    // Zobrazime okno a kurzor dame do pole nazev
    otevriModal(elModalPridat);
    elPoleNazev.focus();
}

// Vypise nazev, popis a termin udalosti do okna s detailem
function vyplnDetail(udalost) {
    // Datum a cas, napr. "pátek 9. října 2026 · 14:00"
    elDetailKdy.textContent = hezkeDatum(udalost.datum) + ' · ' + udalost.cas;
    // Nazev udalosti (textContent = bezpecne, zadne HTML)
    elDetailNazev.textContent = udalost.nazev;
    // Popis, nebo informace, ze popis chybi
    elDetailPopis.textContent = udalost.popis ? udalost.popis : 'Bez popisu.';
    // Prazdny popis dostane tlumeny styl (trida je-prazdny v style.css)
    elDetailPopis.classList.toggle('je-prazdny', !udalost.popis);
}

// Otevre okno s detailem vybrane udalosti
function otevriDetail(udalost) {
    // Pokud se jeste upravoval text predchozi udalosti, upravu zrusime
    zrusUpravu();
    // Zapamatujeme si, kterou udalost okno zobrazuje (kvuli upravam)
    aktualniUdalost = udalost;
    // Vypiseme udaje
    vyplnDetail(udalost);
    // Skryjeme chybu z minula, presun a potvrzeni smazani (okno zacina v beznem stavu)
    elChybaDetailu.classList.add('hidden');
    skryjPresun();
    skryjPotvrzeniSmazani();
    // Zobrazime okno s detailem a fokus presuneme do nej (Tab pak prochazi okno)
    otevriModal(elModalDetail);
    elModalDetail.querySelector('.modal-okno').focus();
    // Nacteme podukoly udalosti
    nactiPodukoly(udalost);
}

// Je okno detailu otevrene a ukazuje prave tuto udalost? (porovnani podle id -
// objekt udalosti se po novem nacteni mesice vymeni za cerstvy)
function ukazujeDetail(udalost) {
    // Shoda id a zaroven otevrene okno
    return aktualniUdalost !== null && aktualniUdalost.id === udalost.id
        && !elModalDetail.classList.contains('hidden');
}

// ---------------------------------------------------------------------
// Presun udalosti (zmena data a casu)
// ---------------------------------------------------------------------

// Ukaze formular pro zmenu data a casu
function ukazPresun() {
    // Predvyplnime soucasne hodnoty
    elPresunDatum.value = aktualniUdalost.datum;
    elPresunCas.value = aktualniUdalost.cas;
    // Zobrazime formular a kurzor dame do data
    elFormPresun.classList.remove('hidden');
    elPresunDatum.focus();
}

// Schova formular pro zmenu data a casu
function skryjPresun() {
    // Pridame tridu hidden
    elFormPresun.classList.add('hidden');
}

// Presune udalost na jine datum/cas; nabidnoutZpet = ukazat v oznameni tlacitko "Zpet"
async function presunUdalost(udalost, datum, cas, nabidnoutZpet) {
    // Puvodni hodnoty (pro "Zpet")
    const puvodniDatum = udalost.datum;
    const puvodniCas = udalost.cas;
    // Nic se nemeni -> konec
    if (datum === puvodniDatum && cas === puvodniCas) {
        // Uspech bez pozadavku
        return true;
    }
    // try/catch zachyti chyby ze serveru i ze site
    try {
        // PUT api.php { id, datum, cas } - server posune i terminy podukolu
        const ulozena = await posliJson(API_URL, 'PUT', { id: udalost.id, datum: datum, cas: cas });
        // Nove hodnoty do objektu udalosti
        udalost.datum = ulozena.datum;
        udalost.cas = ulozena.cas;
        // Detail teto udalosti je otevreny -> aktualizace textu a podukolu (posunute terminy)
        if (ukazujeDetail(udalost)) {
            // Prepis udaju a nove nacteni podukolu
            vyplnDetail(udalost);
            nactiPodukoly(udalost);
        }
        // Kalendar nacteme znovu (udalost mohla odejit do jineho mesice)
        await nactiUdalosti();
        // Oznameni (s moznosti vratit presun)
        ukazOznameni('Přesunuto na ' + kratkeDatum(udalost.datum) + ' ' + udalost.cas + '.',
            nabidnoutZpet ? 'Zpět' : '',
            nabidnoutZpet ? function () { presunUdalost(udalost, puvodniDatum, puvodniCas, false); } : null);
        // Uspech
        return true;
    } catch (chyba) {
        // Chyba: v otevrenem detailu primo u udalosti, jinak v oznameni
        if (ukazujeDetail(udalost)) {
            // Chyba v detailu
            elChybaDetailu.textContent = 'Přesun se nepovedl: ' + chyba.message;
            elChybaDetailu.classList.remove('hidden');
        } else {
            // Chyba v oznameni
            ukazOznameni('Přesun se nepovedl: ' + chyba.message);
        }
        // Neuspech
        return false;
    }
}

// Odeslani formulare pro presun v detailu
async function priOdeslaniPresunu(e) {
    // Zabranime znovunacteni stranky
    e.preventDefault();
    // Udalost v detailu
    const udalost = aktualniUdalost;
    // Prazdne hodnoty prohlizec nepusti (required), pro jistotu kontrola
    if (!elPresunDatum.value || !elPresunCas.value) {
        // Konec
        return;
    }
    // Skryjeme starou chybu
    elChybaDetailu.classList.add('hidden');
    // Presun (bez "Zpet" - uzivatel to zadal rucne)
    if (await presunUdalost(udalost, elPresunDatum.value, elPresunCas.value, false)) {
        // Uspech -> schovame formular
        skryjPresun();
    }
}

// ---------------------------------------------------------------------
// Pripravne podukoly (generuje AI na serveru, viz podukoly.php a ai.php)
// ---------------------------------------------------------------------

// Zobrazi chybu v sekci podukolu
function ukazChybuPodukolu(text) {
    // Text chyby a zobrazeni
    elChybaPodukolu.textContent = text;
    elChybaPodukolu.classList.remove('hidden');
}

// Nacte podukoly udalosti ze serveru a vykresli je
async function nactiPodukoly(udalost) {
    // Cislo tohoto nacitani (pri rychlem prepnuti udalosti zahodime starsi odpoved)
    const mojeCislo = ++cisloNacitaniPodukolu;
    // Vychozi stav sekce: zadne podukoly, zadna chyba, zadne potvrzeni
    podukoly = [];
    elChybaPodukolu.classList.add('hidden');
    skryjPotvrzeniPodukolu();
    // Behem nacitani schovame ovladani a ukazeme kratkou zpravu
    elBtnAi.classList.add('hidden');
    elPoznamkaAi.classList.add('hidden');
    elAkcePodukolu.classList.add('hidden');
    elFormNovyPodukol.classList.add('hidden');
    elUkazatel.classList.add('hidden');
    elPodukolyPrubeh.textContent = '';
    elPodukoly.innerHTML = '<p class="napoveda nacitani-text">Načítám přípravu…</p>';
    // try/catch zachyti chyby ze serveru i ze site
    try {
        // GET podukoly.php?event_id=...
        const data = await posliPozadavek(PODUKOLY_URL + '?event_id=' + udalost.id);
        // Mezitim se nacitalo neco jineho -> odpoved zahodime
        if (mojeCislo !== cisloNacitaniPodukolu) {
            // Konec funkce
            return;
        }
        // Prevzeti seznamu a vykresleni
        prevezmiPodukoly(udalost, data, true);
    } catch (chyba) {
        // Starsi nacitani -> chybu ignorujeme
        if (mojeCislo !== cisloNacitaniPodukolu) {
            // Konec funkce
            return;
        }
        // Vyprazdnime seznam a ukazeme chybu
        elPodukoly.innerHTML = '';
        ukazChybuPodukolu('Přípravu se nepodařilo načíst: ' + chyba.message);
    }
}

// Prevezme odpoved serveru (seznam + pocty) a aktualizuje okno i listek v kalendari
//   prekreslitSeznam = false: seznam se neprekresluje (napr. po zaskrtnuti - zustane fokus)
function prevezmiPodukoly(udalost, data, prekreslitSeznam) {
    // Pocty ulozime k udalosti i k jejimu objektu v kalendari (po novem nacteni mesice
    // to muze byt jiny objekt se stejnym id) - pouzije je listek v kalendari
    [udalost, udalosti.find(function (u) { return u.id === udalost.id; })].forEach(function (u) {
        // Objekt existuje
        if (u) {
            // Prepis poctu
            u.podukoly_celkem = data.pocty.celkem;
            u.podukoly_splneno = data.pocty.splneno;
        }
    });
    // Server rika, jestli je AI nastavena (klic mohl pribyt i bez obnoveni stranky)
    aiNastavena = data.ai;
    // Prekreslime kalendar (pocitadlo na listku)
    vykresliKalendar();
    // Okno uz ukazuje jinou udalost (nebo je zavrene) -> seznam nemenime
    if (!ukazujeDetail(udalost)) {
        // Konec funkce
        return;
    }
    // Ulozime seznam
    podukoly = data.podukoly;
    // Vykreslime seznam, nebo jen ovladani a prubeh
    if (prekreslitSeznam) {
        // Cely seznam znovu
        vykresliPodukoly();
    } else {
        // Jen tlacitka a prubeh
        vykresliOvladaniPodukolu();
    }
}

// Pocet dni mezi dvema daty RRRR-MM-DD (b - a)
function rozdilDni(a, b) {
    // Date.UTC pracuje bez letniho casu, takze rozdil je vzdy cely pocet dni
    const [ra, ma, da] = a.split('-').map(Number);
    const [rb, mb, db] = b.split('-').map(Number);
    // 86 400 000 ms = jeden den
    return Math.round((Date.UTC(rb, mb - 1, db) - Date.UTC(ra, ma - 1, da)) / 86400000);
}

// Popis terminu podukolu, napr. "2 dny předem · 7. 10."
function popisTerminu(termin, datumUdalosti) {
    // Podukol bez terminu (napr. pridany rucne)
    if (!termin) {
        // Zadny popis
        return '';
    }
    // Kolik dni pred udalosti
    const dni = rozdilDni(termin, datumUdalosti);
    // Den a mesic terminu, napr. "7. 10."
    const [, mesic, den] = termin.split('-').map(Number);
    const kdy = den + '. ' + mesic + '.';
    // Ceske sklonovani podle poctu dni
    if (dni <= 0) {
        // V den udalosti
        return 'v den události';
    }
    if (dni === 1) {
        // Jeden den
        return 'den předem · ' + kdy;
    }
    if (dni <= 4) {
        // 2 az 4 dny
        return dni + ' dny předem · ' + kdy;
    }
    // 5 a vice dni
    return dni + ' dní předem · ' + kdy;
}

// Vykresli cely seznam podukolu a ovladani
function vykresliPodukoly() {
    // Vyprazdnime kontejner
    elPodukoly.innerHTML = '';
    // Udalost podukoly ma -> seznam
    if (podukoly.length > 0) {
        // Seznam jako <ul>
        const seznam = document.createElement('ul');
        // Styl seznamu; po vygenerovani AI polozky postupne "pritancuji"
        seznam.className = 'seznam-podukolu' + (animovatNove ? ' je-novy' : '');
        // Polozka za kazdy podukol
        podukoly.forEach(function (podukol, poradi) {
            // Vytvoreni polozky; poradi urcuje zpozdeni animace
            const polozka = vytvorPolozkuPodukolu(podukol);
            polozka.style.setProperty('--poradi', poradi);
            seznam.appendChild(polozka);
        });
        // Vlozime seznam do kontejneru
        elPodukoly.appendChild(seznam);
    }
    // Animace jen jednou
    animovatNove = false;
    // Tlacitka, poznamka a prubeh
    vykresliOvladaniPodukolu();
}

// Nastavi tlacitka, poznamku a prubeh podle stavu (ma podukoly? generuje se?)
function vykresliOvladaniPodukolu() {
    // Ma udalost podukoly?
    const ma = podukoly.length > 0;
    // Generuje AI prave pro tuto udalost?
    const generujeSe = aktualniUdalost !== null && generovaneUdalosti.has(aktualniUdalost.id);
    // Splnene podukoly
    const hotovo = podukoly.filter(function (p) { return p.splneno; }).length;
    // Odhad zbyvajiciho casu: soucet odhadu nesplnenych podukolu
    const zbyva = podukoly.reduce(function (soucet, p) {
        // Pricteme jen nesplnene s odhadem
        return soucet + (!p.splneno && p.odhad_minut ? p.odhad_minut : 0);
    }, 0);
    // Podil splnenych v procentech
    const procenta = ma ? Math.round(hotovo / podukoly.length * 100) : 0;

    // Prubeh, napr. "2 z 5 hotovo · zbývá ~40 min"
    let prubeh = '';
    if (ma) {
        // Zaklad
        prubeh = hotovo + ' z ' + podukoly.length + ' hotovo';
        // Vse hotovo / zbyvajici cas
        if (hotovo === podukoly.length) {
            // Pochvala
            prubeh += ' · připraveno!';
        } else if (zbyva > 0) {
            // Zbyvajici cas
            prubeh += ' · zbývá ~' + formatMinut(zbyva);
        }
    }
    elPodukolyPrubeh.textContent = prubeh;
    // Ukazatel prubehu (proužek)
    elUkazatel.classList.toggle('hidden', !ma);
    elUkazatel.classList.toggle('je-hotovo', ma && hotovo === podukoly.length);
    elUkazatel.setAttribute('aria-valuenow', String(procenta));
    elUkazatelVypln.style.width = procenta + '%';

    // Tlacitko AI: kdyz podukoly chybi, nebo prave probiha generovani
    elBtnAi.classList.toggle('hidden', ma && !generujeSe);
    // Bez klice nebo behem generovani nejde kliknout
    elBtnAi.disabled = !aiNastavena || generujeSe;
    // Ctecka obrazovky pozna, ze se neco deje
    elBtnAi.setAttribute('aria-busy', generujeSe ? 'true' : 'false');
    // Tocici se kolecko misto jiskry jen behem generovani
    elBtnAiNacitani.classList.toggle('hidden', !generujeSe);
    elBtnAiIkona.classList.toggle('hidden', generujeSe);
    // Text tlacitka
    elBtnAiText.textContent = generujeSe ? 'AI přemýšlí…' : 'Vygenerovat podúkoly (AI)';

    // Poznamka pod tlacitkem: vysvetleni, prubeh, nebo duvod, proc AI nejde
    elPoznamkaAi.classList.toggle('hidden', ma && !generujeSe);
    if (generujeSe) {
        // Behem generovani
        elPoznamkaAi.textContent = 'Obvykle to trvá jen pár vteřin.';
    } else if (aiNastavena) {
        // AI je pripravena
        elPoznamkaAi.textContent = 'AI navrhne, co je dobré připravit předem – nebo si napiš vlastní.';
    } else {
        // Chybi API klic
        elPoznamkaAi.textContent = 'AI není nastavená (chybí API klíč) – podúkoly si ale můžeš psát sám/sama.';
    }

    // Pridani vlastniho podukolu: kdyz se negeneruje
    elFormNovyPodukol.classList.toggle('hidden', generujeSe);
    // Akce se seznamem: jen kdyz podukoly jsou, nic se negeneruje a neceka potvrzeni
    elAkcePodukolu.classList.toggle('hidden', !ma || generujeSe || cekajiciAkcePodukolu !== null);
    // "vygenerovat znovu" bez klice nejde
    elBtnPregenerovat.disabled = !aiNastavena;
}

// Vytvori polozku seznamu pro jeden podukol
function vytvorPolozkuPodukolu(podukol) {
    // Polozka seznamu (+ preskrtnuti, kdyz je splneny; + zvyrazneni, kdyz je dulezity)
    const polozka = document.createElement('li');
    polozka.className = 'podukol' + (podukol.splneno ? ' je-splneny' : '') + (podukol.dulezite ? ' je-dulezity' : '');

    // Zaskrtavaci policko "splneno" (vlastni vzhled v style.css)
    const zaskrt = document.createElement('input');
    zaskrt.type = 'checkbox';
    zaskrt.className = 'podukol-zaskrt';
    // Jedinecne id - propoji policko s textem (kliknuti na text policko zaskrtne)
    zaskrt.id = 'podukol-' + podukol.id;
    zaskrt.checked = podukol.splneno;
    // Zmena stavu -> ulozeni
    zaskrt.addEventListener('change', function () {
        // Ulozime novy stav na server
        prepniPodukol(podukol, zaskrt, polozka);
    });
    polozka.appendChild(zaskrt);

    // Obal textu (nazev + termin pod sebou)
    const obal = document.createElement('div');
    obal.className = 'podukol-obal';
    // Nazev jako <label> - kliknuti na nej prepne policko
    const nazev = document.createElement('label');
    nazev.htmlFor = zaskrt.id;
    nazev.className = 'podukol-nazev';
    // Text ve vlastnim prvku - preskrtnuti se pak animuje presne po textu
    const text = document.createElement('span');
    text.className = 'podukol-text';
    text.textContent = podukol.nazev;
    nazev.appendChild(text);
    obal.appendChild(nazev);

    // Radek s terminem a odhadem casu, napr. "den předem · 8. 10. · ~20 min"
    const casti = [];
    // Termin
    const popis = popisTerminu(podukol.termin, aktualniUdalost.datum);
    // Po terminu a nesplneno -> cervene zvyrazneni
    const poTerminu = !podukol.splneno && podukol.termin && podukol.termin < dnesniDatum;
    if (popis !== '') {
        // Text terminu
        casti.push(popis + (poTerminu ? ' · po termínu' : ''));
    }
    // Odhad casu
    if (podukol.odhad_minut) {
        // Napr. "~20 min"
        casti.push('~' + formatMinut(podukol.odhad_minut));
    }
    // Radek jen kdyz je co ukazat
    if (casti.length > 0) {
        // Prvek radku
        const meta = document.createElement('span');
        meta.className = 'podukol-termin' + (poTerminu ? ' je-po-terminu' : '');
        meta.textContent = casti.join(' · ');
        obal.appendChild(meta);
    }
    polozka.appendChild(obal);

    // Tlacitko "dulezite" (hvezdicka); aria-pressed = prepinac pro ctecku obrazovky
    const hvezda = document.createElement('button');
    hvezda.type = 'button';
    hvezda.className = 'podukol-akce podukol-hvezda';
    hvezda.textContent = '★';
    hvezda.setAttribute('aria-pressed', podukol.dulezite ? 'true' : 'false');
    hvezda.title = podukol.dulezite ? 'Zrušit důležitost' : 'Označit jako důležité';
    hvezda.setAttribute('aria-label', 'Důležité: „' + podukol.nazev + '“');
    hvezda.addEventListener('click', function () {
        // Prepnuti dulezitosti
        prepniDulezite(podukol, hvezda, polozka);
    });
    polozka.appendChild(hvezda);

    // Tlacitko "upravit" (tuzka jako znak, ne barevne emoji)
    const upravit = document.createElement('button');
    upravit.type = 'button';
    upravit.className = 'podukol-akce';
    upravit.textContent = '✎︎';
    upravit.title = 'Upravit podúkol';
    upravit.setAttribute('aria-label', 'Upravit podúkol „' + podukol.nazev + '“');
    upravit.addEventListener('click', function () {
        // Nazev se zmeni na textove pole
        zacniUpravuPodukolu(podukol, obal, nazev);
    });
    polozka.appendChild(upravit);

    // Tlacitko "smazat" (krizek)
    const smazat = document.createElement('button');
    smazat.type = 'button';
    smazat.className = 'podukol-akce podukol-smazat';
    smazat.textContent = '×';
    smazat.title = 'Smazat podúkol';
    smazat.setAttribute('aria-label', 'Smazat podúkol „' + podukol.nazev + '“');
    smazat.addEventListener('click', function () {
        // Smazani (jde vratit tlacitkem "Zpet" v oznameni)
        smazPodukol(podukol, smazat, polozka);
    });
    polozka.appendChild(smazat);

    // Vratime hotovou polozku
    return polozka;
}

// Ulozi zaskrtnuti / odskrtnuti podukolu
async function prepniPodukol(podukol, zaskrt, polozka) {
    // Udalost, ke ktere podukol patri
    const udalost = aktualniUdalost;
    // Novy stav z policka
    const novyStav = zaskrt.checked;
    // Zmenu ukazeme hned (uzivatel neceka na server) - "optimisticka" aktualizace
    podukol.splneno = novyStav;
    polozka.classList.toggle('je-splneny', novyStav);
    vykresliOvladaniPodukolu();
    // Skryjeme starou chybu
    elChybaPodukolu.classList.add('hidden');
    // try/catch zachyti chyby ze serveru i ze site
    try {
        // PUT podukoly.php { id, splneno }
        const data = await posliJson(PODUKOLY_URL, 'PUT', { id: podukol.id, splneno: novyStav });
        // Pocty pro listek; seznam neprekreslujeme (fokus zustane na policku)
        prevezmiPodukoly(udalost, data, false);
        // Posledni podukol odskrtnut -> mala oslava
        if (novyStav && data.pocty.celkem > 0 && data.pocty.splneno === data.pocty.celkem) {
            // Oznameni a "zablesknuti" ukazatele
            ukazOznameni('Všechno připraveno! ✓');
            elUkazatel.classList.remove('oslava');
            void elUkazatel.offsetWidth;
            elUkazatel.classList.add('oslava');
        }
    } catch (chyba) {
        // Neulozilo se -> vratime puvodni stav
        podukol.splneno = !novyStav;
        zaskrt.checked = !novyStav;
        polozka.classList.toggle('je-splneny', !novyStav);
        vykresliOvladaniPodukolu();
        // Chyba pro uzivatele
        ukazChybuPodukolu('Změnu se nepodařilo uložit: ' + chyba.message);
    }
}

// Prepne priznak "dulezite" (hvezdicka)
async function prepniDulezite(podukol, tlacitko, polozka) {
    // Udalost, ke ktere podukol patri
    const udalost = aktualniUdalost;
    // Novy stav
    const novyStav = !podukol.dulezite;
    // Hned zobrazime (optimisticky)
    podukol.dulezite = novyStav;
    polozka.classList.toggle('je-dulezity', novyStav);
    tlacitko.setAttribute('aria-pressed', novyStav ? 'true' : 'false');
    tlacitko.title = novyStav ? 'Zrušit důležitost' : 'Označit jako důležité';
    // try/catch zachyti chyby
    try {
        // PUT podukoly.php { id, dulezite }
        const data = await posliJson(PODUKOLY_URL, 'PUT', { id: podukol.id, dulezite: novyStav });
        // Pocty (seznam se neprekresluje)
        prevezmiPodukoly(udalost, data, false);
    } catch (chyba) {
        // Vratime puvodni stav
        podukol.dulezite = !novyStav;
        polozka.classList.toggle('je-dulezity', !novyStav);
        tlacitko.setAttribute('aria-pressed', !novyStav ? 'true' : 'false');
        ukazChybuPodukolu('Změnu se nepodařilo uložit: ' + chyba.message);
    }
}

// Smaze jeden podukol (v oznameni nabidne "Zpet")
async function smazPodukol(podukol, tlacitko, polozka) {
    // Udalost, ke ktere podukol patri
    const udalost = aktualniUdalost;
    // Zablokujeme tlacitko (zadne dvojite kliknuti) a polozku nechame "odjet"
    tlacitko.disabled = true;
    polozka.classList.add('odchazi');
    // Skryjeme starou chybu
    elChybaPodukolu.classList.add('hidden');
    // try/catch zachyti chyby ze serveru i ze site
    try {
        // DELETE podukoly.php { id }
        const data = await posliJson(PODUKOLY_URL, 'DELETE', { id: podukol.id });
        // Novy seznam a pocty
        prevezmiPodukoly(udalost, data, true);
        // Oznameni s moznosti vratit
        ukazOznameni('Podúkol „' + zkrat(podukol.nazev, 30) + '“ smazán.', 'Zpět', function () {
            // Vraceni podukolu
            vratPodukol(udalost, podukol);
        });
    } catch (chyba) {
        // Tlacitko a polozku vratime
        tlacitko.disabled = false;
        polozka.classList.remove('odchazi');
        ukazChybuPodukolu('Podúkol se nepodařilo smazat: ' + chyba.message);
    }
}

// Vrati smazany podukol (vytvori ho znovu se stejnymi udaji)
async function vratPodukol(udalost, podukol) {
    // try/catch zachyti chyby
    try {
        // POST podukoly.php s puvodnimi udaji
        const data = await posliJson(PODUKOLY_URL, 'POST', {
            event_id: udalost.id,
            nazev: podukol.nazev,
            termin: podukol.termin,
            odhad_minut: podukol.odhad_minut,
            dulezite: podukol.dulezite,
            splneno: podukol.splneno
        });
        // Novy seznam
        prevezmiPodukoly(udalost, data, true);
        ukazOznameni('Podúkol je zpátky.');
    } catch (chyba) {
        // Chyba v oznameni
        ukazOznameni('Vrátit se nepodařilo: ' + chyba.message);
    }
}

// Prida vlastni podukol z formulare pod seznamem
async function pridejPodukol(e) {
    // Zabranime znovunacteni stranky
    e.preventDefault();
    // Nazev bez prebytecnych mezer
    const nazev = elPoleNovyPodukol.value.replace(/\s+/g, ' ').trim();
    // Prazdny nazev neposilame
    if (nazev === '') {
        // Kurzor zpet do pole
        elPoleNovyPodukol.focus();
        return;
    }
    // Udalost v detailu
    const udalost = aktualniUdalost;
    // Pole zablokujeme behem ukladani
    elPoleNovyPodukol.disabled = true;
    elChybaPodukolu.classList.add('hidden');
    // try/catch zachyti chyby
    try {
        // POST podukoly.php { event_id, nazev }
        const data = await posliJson(PODUKOLY_URL, 'POST', { event_id: udalost.id, nazev: nazev });
        // Vyprazdnime pole a prevezmeme novy seznam
        elPoleNovyPodukol.value = '';
        prevezmiPodukoly(udalost, data, true);
    } catch (chyba) {
        // Chyba pro uzivatele
        ukazChybuPodukolu('Podúkol se nepodařilo přidat: ' + chyba.message);
    } finally {
        // Pole znovu povolime a vratime do nej kurzor (lze hned psat dalsi)
        elPoleNovyPodukol.disabled = false;
        if (ukazujeDetail(udalost)) {
            // Fokus zpet
            elPoleNovyPodukol.focus();
        }
    }
}

// Zmeni nazev podukolu na textove pole pro upravu
function zacniUpravuPodukolu(podukol, obal, nazev) {
    // Udalost, ke ktere podukol patri
    const udalost = aktualniUdalost;
    // Textove pole s puvodnim nazvem
    const pole = document.createElement('input');
    pole.type = 'text';
    pole.maxLength = 255;
    pole.className = 'pole pole-podukol';
    pole.value = podukol.nazev;
    pole.setAttribute('aria-label', 'Nový název podúkolu');
    // Nazev nahradime polem, kurzor do pole a text oznacime
    obal.replaceChild(pole, nazev);
    pole.focus();
    pole.select();

    // Priznak, aby se uprava neulozila dvakrat (Enter + nasledne opusteni pole)
    let hotovo = false;

    // Ukonci upravu: ulozit = true -> poslat na server
    async function ukonci(ulozit) {
        // Uz ukonceno -> nic
        if (hotovo) {
            // Konec funkce
            return;
        }
        hotovo = true;
        // Novy nazev bez prebytecnych mezer
        const novyNazev = pole.value.replace(/\s+/g, ' ').trim();
        // Bez ulozeni, nebo bez zmeny -> jen vratime puvodni zobrazeni
        if (!ulozit || novyNazev === podukol.nazev) {
            // Puvodni seznam
            vykresliPodukoly();
            return;
        }
        // Prazdny nazev neukladame
        if (novyNazev === '') {
            // Puvodni seznam a chyba
            vykresliPodukoly();
            ukazChybuPodukolu('Název podúkolu nesmí být prázdný.');
            return;
        }
        // Pole zablokujeme behem ukladani
        pole.disabled = true;
        // try/catch zachyti chyby ze serveru i ze site
        try {
            // PUT podukoly.php { id, nazev }
            const data = await posliJson(PODUKOLY_URL, 'PUT', { id: podukol.id, nazev: novyNazev });
            // Novy seznam
            prevezmiPodukoly(udalost, data, true);
        } catch (chyba) {
            // Puvodni seznam a chyba
            if (ukazujeDetail(udalost)) {
                // Prekresleni a chyba pro uzivatele
                vykresliPodukoly();
                ukazChybuPodukolu('Název se nepodařilo uložit: ' + chyba.message);
            }
        }
    }

    // Klavesy v poli: Enter = ulozit, Escape = zrusit
    pole.addEventListener('keydown', function (e) {
        // Enter
        if (e.key === 'Enter') {
            // Zabranime odeslani formulare apod.
            e.preventDefault();
            ukonci(true);
        }
        // Escape
        if (e.key === 'Escape') {
            // Escape nesmi zavrit cele okno
            e.stopPropagation();
            ukonci(false);
        }
    });
    // Kliknuti jinam = ulozit
    pole.addEventListener('blur', function () {
        // Ulozeni
        ukonci(true);
    });
}

// Necha AI vygenerovat podukoly; nahradit = true -> stavajici nahradi
async function generujPodukoly(nahradit) {
    // Udalost, pro kterou generujeme
    const udalost = aktualniUdalost;
    // Uz se pro ni generuje -> nic (ochrana pred dvojitym kliknutim)
    if (generovaneUdalosti.has(udalost.id)) {
        // Konec funkce
        return;
    }
    // Oznacime probihajici generovani a ukazeme nacitani
    generovaneUdalosti.add(udalost.id);
    elChybaPodukolu.classList.add('hidden');
    vykresliOvladaniPodukolu();
    // try/catch zachyti chyby ze serveru, ze site i z AI
    try {
        // POST podukoly.php { event_id, nahradit }
        const data = await posliJson(PODUKOLY_URL, 'POST', { event_id: udalost.id, nahradit: nahradit });
        // Generovani skoncilo (pred prekreslenim, at se tlacitko vrati do klidu)
        generovaneUdalosti.delete(udalost.id);
        // Nove podukoly pri vykresleni "pritancuji"
        animovatNove = ukazujeDetail(udalost);
        // Novy seznam a pocty
        prevezmiPodukoly(udalost, data, true);
        // Oznameni
        ukazOznameni('AI navrhla ' + sklonuj(data.podukoly.length, 'podúkol', 'podúkoly', 'podúkolů')
            + ' k „' + zkrat(udalost.nazev, 24) + '“.');
    } catch (chyba) {
        // Generovani skoncilo
        generovaneUdalosti.delete(udalost.id);
        // Okno uz ukazuje neco jineho -> chybu ukazeme aspon v oznameni
        if (!ukazujeDetail(udalost)) {
            // Oznameni
            ukazOznameni('AI: ' + chyba.message);
            return;
        }
        // Chyba pro uzivatele (text pripravil server, napr. "AI neodpověděla včas")
        ukazChybuPodukolu(chyba.message);
        // 409 = podukoly mezitim vznikly jinde -> nacteme aktualni stav
        if (chyba.status === 409) {
            // Nove nacteni
            nactiPodukoly(udalost);
            return;
        }
        // Tlacitka zpet do klidu
        vykresliOvladaniPodukolu();
    }
}

// Ukaze potvrzeni hromadne akce: 'smazat' (vse) nebo 'pregenerovat'
function ukazPotvrzeniPodukolu(akce) {
    // Zapamatujeme si, co se ma po potvrzeni udelat
    cekajiciAkcePodukolu = akce;
    // Pocet podukolu do textu otazky
    const pocet = podukoly.length;
    // Text otazky a tlacitka podle akce
    if (akce === 'smazat') {
        // Smazani vsech
        elTextPotvrzeniPodukolu.textContent = 'Opravdu smazat všechny podúkoly (' + pocet + ')? Vrátit to nepůjde.';
        elBtnPodukolyAno.textContent = 'Ano, smazat';
    } else {
        // Nahrazeni novymi
        elTextPotvrzeniPodukolu.textContent = 'Smazat současné podúkoly (' + pocet + ') a nechat AI navrhnout nové? '
            + 'Tvoje úpravy a odškrtnutí se ztratí.';
        elBtnPodukolyAno.textContent = 'Ano, vygenerovat';
    }
    // Ukazeme potvrzeni a schovame akce
    elPotvrzeniPodukolu.classList.remove('hidden');
    vykresliOvladaniPodukolu();
    // Fokus na bezpecnou volbu
    elBtnPodukolyNe.focus();
}

// Schova potvrzeni hromadne akce
function skryjPotvrzeniPodukolu() {
    // Zadna akce neceka
    cekajiciAkcePodukolu = null;
    // Schovame potvrzeni a tlacitko znovu povolime
    elPotvrzeniPodukolu.classList.add('hidden');
    elBtnPodukolyAno.disabled = false;
    // Akce se seznamem zpet (jen pokud je detail otevreny s udalosti)
    if (aktualniUdalost !== null) {
        // Prekresleni tlacitek
        vykresliOvladaniPodukolu();
    }
}

// Uzivatel potvrdil hromadnou akci
async function potvrdAkciPodukolu() {
    // Co se ma udelat a pro kterou udalost
    const akce = cekajiciAkcePodukolu;
    const udalost = aktualniUdalost;
    // Zablokujeme tlacitko (zadne dvojite potvrzeni)
    elBtnPodukolyAno.disabled = true;
    // Nahrazeni novymi = generovani s nahrazenim (stare se smazou az po uspechu AI)
    if (akce === 'pregenerovat') {
        // Schovame potvrzeni a generujeme
        skryjPotvrzeniPodukolu();
        generujPodukoly(true);
        return;
    }
    // Smazani vsech podukolu udalosti
    try {
        // DELETE podukoly.php { event_id, vse: true }
        const data = await posliJson(PODUKOLY_URL, 'DELETE', { event_id: udalost.id, vse: true });
        // Schovame potvrzeni; prazdny seznam -> znovu se ukaze tlacitko AI
        skryjPotvrzeniPodukolu();
        prevezmiPodukoly(udalost, data, true);
    } catch (chyba) {
        // Schovame potvrzeni a ukazeme chybu
        skryjPotvrzeniPodukolu();
        ukazChybuPodukolu('Podúkoly se nepodařilo smazat: ' + chyba.message);
    }
}

// ---------------------------------------------------------------------
// Uprava nazvu a popisu primo v detailu (po kliknuti na tuzku)
// ---------------------------------------------------------------------

// Zacne upravu zadaneho prvku (nadpisu nebo popisu)
function zacniUpravu(prvek) {
    // Tento prvek se uz upravuje -> neni co delat
    if (upravovanyPrvek === prvek) {
        // Konec funkce
        return;
    }
    // Zapamatujeme si, ktery prvek se upravuje
    upravovanyPrvek = prvek;
    // U prazdneho popisu smazeme text "Bez popisu.", at ho uzivatel nemusi mazat sam
    if (prvek === elDetailPopis && !aktualniUdalost.popis) {
        // Prazdny text bez tlumeneho stylu
        prvek.textContent = '';
        prvek.classList.remove('je-prazdny');
    }
    // Zapneme moznost psat primo do prvku
    try {
        // "plaintext-only" = jde psat jen obycejny text (bez tucneho pisma, obrazku...)
        prvek.contentEditable = 'plaintext-only';
    } catch (e) {
        // Starsi prohlizece tuto hodnotu neznaji - pouzijeme obycejne "true"
        prvek.contentEditable = 'true';
    }
    // Zvyraznujici trida a kurzor do prvku
    prvek.classList.add('upravuje-se');
    prvek.focus();
    // Kurzor posuneme na konec textu (jinak by byl na zacatku)
    const rozsah = document.createRange();
    rozsah.selectNodeContents(prvek);
    rozsah.collapse(false);
    const vyber = window.getSelection();
    vyber.removeAllRanges();
    vyber.addRange(rozsah);
}

// Vypne upravy prvku (vrati ho do bezneho stavu)
function ukonciUpravu(prvek) {
    // Vypneme moznost psat a zvyrazneni
    prvek.contentEditable = 'false';
    prvek.classList.remove('upravuje-se');
}

// Zrusi rozpracovanou upravu bez ulozeni a vrati puvodni text
function zrusUpravu() {
    // Zadna uprava neprobiha -> konec
    if (!upravovanyPrvek) {
        // Konec funkce
        return;
    }
    // Ulozime si prvek a stav vynulujeme HNED, aby navazna udalost blur nic neulozila
    const prvek = upravovanyPrvek;
    upravovanyPrvek = null;
    // Vypneme upravy a vratime puvodni texty
    ukonciUpravu(prvek);
    vyplnDetail(aktualniUdalost);
}

// Ulozi upraveny nazev nebo popis na server
async function ulozUpravu() {
    // Zadna uprava neprobiha (napr. uz byla ulozena nebo zrusena) -> konec
    if (!upravovanyPrvek) {
        // Konec funkce
        return;
    }
    // Prvek, ktery se upravoval; stav vynulujeme hned (zadne dvojite ulozeni)
    const prvek = upravovanyPrvek;
    upravovanyPrvek = null;
    // Udalost, ke ktere uprava patri
    const udalost = aktualniUdalost;
    // Upravoval se nazev, nebo popis?
    const jeNazev = (prvek === elDetailNazev);
    // Novy text bez okrajovych mezer (innerText zachova odradkovani)
    let novyText = prvek.innerText.trim();
    // Nazev ma byt na jeden radek - odradkovani a vicenasobne mezery nahradime mezerou
    if (jeNazev) {
        // \s+ = jeden nebo vice "bilych" znaku
        novyText = novyText.replace(/\s+/g, ' ');
    }
    // Vypneme upravy
    ukonciUpravu(prvek);

    // Nazev nesmi byt prazdny
    if (jeNazev && novyText === '') {
        // Vratime puvodni texty a zobrazime chybu
        vyplnDetail(udalost);
        elChybaDetailu.textContent = 'Název nesmí být prázdný.';
        elChybaDetailu.classList.remove('hidden');
        return;
    }
    // Nic se nezmenilo -> neni potreba nic posilat na server
    if ((jeNazev && novyText === udalost.nazev) || (!jeNazev && novyText === (udalost.popis || ''))) {
        // Jen obnovime zobrazeni (napr. vratime text "Bez popisu.")
        vyplnDetail(udalost);
        return;
    }

    // try/catch zachyti chyby ze serveru i ze site
    try {
        // Posilame jen zmeneny udaj (server ostatni nemeni)
        const zmena = jeNazev ? { id: udalost.id, nazev: novyText } : { id: udalost.id, popis: novyText };
        const ulozena = await posliJson(API_URL, 'PUT', zmena);
        // Nove hodnoty do objektu udalosti i do objektu v kalendari
        [udalost, udalosti.find(function (u) { return u.id === udalost.id; })].forEach(function (u) {
            // Objekt existuje
            if (u) {
                // Prepis
                u.nazev = ulozena.nazev;
                u.popis = ulozena.popis;
            }
        });
        // Skryjeme starou chybu a prekreslime kalendar (novy nazev na listku)
        elChybaDetailu.classList.add('hidden');
        vykresliKalendar();
    } catch (chyba) {
        // Zobrazime chybu v okne detailu
        elChybaDetailu.textContent = chyba.message;
        elChybaDetailu.classList.remove('hidden');
    }
    // Pokud okno porad zobrazuje tutez udalost, vypiseme aktualni texty
    if (aktualniUdalost !== null && aktualniUdalost.id === udalost.id) {
        // Po uspechu nove texty, po chybe puvodni
        vyplnDetail(aktualniUdalost);
    }
}

// Obsluha klaves pri upravach (spolecna pro nazev i popis)
function priKlavesePriUprave(e) {
    // Klavesy resime jen behem upravy
    if (upravovanyPrvek !== e.currentTarget) {
        // Konec funkce
        return;
    }
    // Escape = zrusit upravu
    if (e.key === 'Escape') {
        // Zastavime probublani - jinak by Escape zavrel cele okno
        e.stopPropagation();
        zrusUpravu();
        return;
    }
    // Enter u nazvu, nebo Ctrl+Enter u popisu = ulozit
    if (e.key === 'Enter' && (e.currentTarget === elDetailNazev || e.ctrlKey)) {
        // Zabranime vlozeni noveho radku; opusteni prvku spusti ulozeni (blur)
        e.preventDefault();
        e.currentTarget.blur();
    }
}

// ---------------------------------------------------------------------
// Mazani udalosti (s potvrzenim primo v okne detailu)
// ---------------------------------------------------------------------

// Zobrazi otazku "Opravdu smazat?" misto tlacitek Smazat / Zavrit
function ukazPotvrzeniSmazani() {
    // Text otazky s nazvem udalosti (textContent = bezpecne, zadne HTML)
    elTextSmazani.textContent = 'Opravdu smazat „' + aktualniUdalost.nazev
        + '“? Smažou se i její podúkoly a vrátit to nepůjde.';
    // Schovame bezna tlacitka a ukazeme potvrzeni
    elTlacitkaDetailu.classList.add('hidden');
    elPotvrzeniSmazani.classList.remove('hidden');
    // Fokus na bezpecnou volbu - omylem zmacknuty Enter nic nesmaze
    elBtnSmazatNe.focus();
}

// Schova potvrzeni a vrati bezna tlacitka
function skryjPotvrzeniSmazani() {
    // Schovame potvrzeni a ukazeme bezna tlacitka
    elPotvrzeniSmazani.classList.add('hidden');
    elTlacitkaDetailu.classList.remove('hidden');
    // Tlacitka potvrzeni znovu povolime (mohla zustat zablokovana po chybe)
    elBtnSmazatAno.disabled = false;
    elBtnSmazatNe.disabled = false;
}

// Uzivatel potvrdil smazani -> smazeme udalost na serveru
async function potvrdSmazani() {
    // Udalost, ktera se maze
    const udalost = aktualniUdalost;
    // Zablokujeme tlacitka, aby se pozadavek neodeslal dvakrat
    elBtnSmazatAno.disabled = true;
    elBtnSmazatNe.disabled = true;
    elChybaDetailu.classList.add('hidden');
    // try/catch zachyti chyby ze serveru i ze site
    try {
        // DELETE api.php { id }
        await posliJson(API_URL, 'DELETE', { id: udalost.id });
        // Zavreme okno detailu
        zavriModal(elModalDetail);
        // Udalost odebereme z nactenych dat a prekreslime kalendar
        udalosti = udalosti.filter(function (u) { return u.id !== udalost.id; });
        vykresliKalendar();
        // Oznameni
        ukazOznameni('Událost „' + zkrat(udalost.nazev, 30) + '“ smazána.');
    } catch (chyba) {
        // Zobrazime chybu v okne detailu a vratime bezna tlacitka
        elChybaDetailu.textContent = chyba.message;
        elChybaDetailu.classList.remove('hidden');
        skryjPotvrzeniSmazani();
    }
}

// ---------------------------------------------------------------------
// Pridani nove udalosti
// ---------------------------------------------------------------------

// Obsluha odeslani formulare (ulozeni nove udalosti)
async function priOdeslaniFormulare(e) {
    // Zabranime klasickemu odeslani formulare (znovunacteni stranky)
    e.preventDefault();
    // Posbirame hodnoty z poli formulare do objektu
    const novaUdalost = {
        nazev: elPoleNazev.value.trim(), // nazev bez okrajovych mezer
        datum: elPoleDatum.value,        // datum RRRR-MM-DD
        cas: elPoleCas.value,            // cas HH:MM
        popis: elPolePopis.value.trim()  // popis bez okrajovych mezer
    };
    // Atribut required pusti i nazev slozeny jen z mezer - ten zachytime uz tady
    if (novaUdalost.nazev === '') {
        // Chyba a kurzor zpet do pole nazev
        elChybaFormulare.textContent = 'Vyplňte název události.';
        elChybaFormulare.classList.remove('hidden');
        elPoleNazev.focus();
        return;
    }
    // Zablokujeme tlacitko, aby uzivatel neulozil udalost dvakrat
    elBtnUlozit.disabled = true;
    elChybaFormulare.classList.add('hidden');
    // try/catch zachyti chyby ze serveru i ze site
    try {
        // POST api.php
        const ulozena = await posliJson(API_URL, 'POST', novaUdalost);
        // Zavreme okno
        zavriModal(elModalPridat);
        // Vybereme den nove udalosti a prepneme na jeji mesic (uzivatel mohl datum zmenit)
        vybranyDen = ulozena.datum;
        await prejdiNaMesic(ulozena.datum);
        // Oznameni s rychlym odkazem na detail (a tim i na AI)
        ukazOznameni('Zapsáno do kalendáře.', 'Otevřít', function () {
            // Najdeme ulozenou udalost v nactenem mesici
            const u = udalosti.find(function (x) { return x.id === ulozena.id; });
            // A otevreme jeji detail
            if (u) {
                // Detail
                otevriDetail(u);
            }
        });
    } catch (chyba) {
        // Zobrazime chybu primo ve formulari
        elChybaFormulare.textContent = chyba.message;
        elChybaFormulare.classList.remove('hidden');
    } finally {
        // Tlacitko v kazdem pripade znovu odblokujeme
        elBtnUlozit.disabled = false;
    }
}

// ---------------------------------------------------------------------
// Vyhledavani udalosti
// ---------------------------------------------------------------------

// Uzivatel pise do vyhledavani -> chvili pockame a pak hledame (ne po kazdem pismenu)
function priPsaniHledani() {
    // Stary casovac zrusime
    clearTimeout(casovacHledani);
    // Hledany text
    const text = elPoleHledat.value.trim();
    // Kratky text nehledame
    if (text.length < 2) {
        // Zvyseni cisla zahodi pripadnou odpoved, ktera jeste bezi
        cisloHledani++;
        skryjVysledky();
        return;
    }
    // Hledani za 250 ms (kdyz uzivatel mezitim nepise dal)
    casovacHledani = setTimeout(hledej, 250);
}

// Odesle hledany text na server a vykresli vysledky
async function hledej() {
    // Hledany text a cislo tohoto pozadavku
    const text = elPoleHledat.value.trim();
    const mojeCislo = ++cisloHledani;
    // try/catch zachyti chyby
    try {
        // GET api.php?hledat=... (encodeURIComponent osetri mezery a ceske znaky v adrese)
        const data = await posliPozadavek(API_URL + '?hledat=' + encodeURIComponent(text));
        // Mezitim uzivatel napsal neco jineho -> odpoved zahodime
        if (mojeCislo !== cisloHledani) {
            // Konec
            return;
        }
        // Ulozeni a vykresleni vysledku
        vysledkyHledani = data;
        vykresliVysledky('');
    } catch (chyba) {
        // Starsi pozadavek -> ignorujeme
        if (mojeCislo !== cisloHledani) {
            // Konec
            return;
        }
        // Chyba misto vysledku
        vysledkyHledani = [];
        vykresliVysledky(chyba.message);
    }
}

// Vykresli seznam vysledku (nebo chybu / informaci, ze nic nenaslo)
function vykresliVysledky(chyba) {
    // Vyprazdnime seznam, nic neni oznacene
    elVysledky.innerHTML = '';
    aktivniVysledek = -1;
    elPoleHledat.removeAttribute('aria-activedescendant');
    // Informace misto vysledku (chyba nebo prazdny vysledek)
    if (chyba !== '' || vysledkyHledani.length === 0) {
        // Polozka s textem
        const info = document.createElement('li');
        info.className = 'vysledek-info';
        info.textContent = chyba !== '' ? chyba : 'Nic jsem nenašel.';
        elVysledky.appendChild(info);
    }
    // Kazdy vysledek jako polozka seznamu
    vysledkyHledani.forEach(function (v, i) {
        // Polozka (role option = moznost v seznamu pro ctecku obrazovky)
        const li = document.createElement('li');
        li.id = 'vysledek-' + i;
        li.className = 'vysledek barva-' + (v.id % POCET_BAREV);
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        // Datum a cas
        const kdy = document.createElement('span');
        kdy.className = 'vysledek-kdy';
        kdy.textContent = kratkeDatum(v.datum) + ' · ' + v.cas;
        // Nazev
        const nazev = document.createElement('span');
        nazev.className = 'vysledek-nazev';
        nazev.textContent = v.nazev;
        li.append(nazev, kdy);
        // mousedown s preventDefault = pole neztrati fokus (seznam by se schoval drive nez klik)
        li.addEventListener('mousedown', function (e) { e.preventDefault(); });
        // Kliknuti = otevrit udalost
        li.addEventListener('click', function () { vyberVysledek(i); });
        elVysledky.appendChild(li);
    });
    // Zobrazime seznam
    elVysledky.classList.remove('hidden');
    elPoleHledat.setAttribute('aria-expanded', 'true');
}

// Schova seznam vysledku
function skryjVysledky() {
    // Skryti a informace pro ctecku obrazovky
    elVysledky.classList.add('hidden');
    elPoleHledat.setAttribute('aria-expanded', 'false');
    elPoleHledat.removeAttribute('aria-activedescendant');
    aktivniVysledek = -1;
}

// Oznaci vysledek (sipky nahoru / dolu)
function oznacVysledek(index) {
    // Zadne vysledky -> nic
    if (vysledkyHledani.length === 0) {
        // Konec
        return;
    }
    // Index "dokola" (z posledniho na prvni a naopak)
    aktivniVysledek = (index + vysledkyHledani.length) % vysledkyHledani.length;
    // Zvyrazneni oznacene polozky
    vysledkyHledani.forEach(function (v, i) {
        // Polozka a jeji stav
        const li = $('vysledek-' + i);
        li.setAttribute('aria-selected', i === aktivniVysledek ? 'true' : 'false');
    });
    // Ctecka obrazovky oznami oznacenou polozku
    elPoleHledat.setAttribute('aria-activedescendant', 'vysledek-' + aktivniVysledek);
}

// Otevre vybrany vysledek: prepne mesic a ukaze detail udalosti
async function vyberVysledek(index) {
    // Vybrany vysledek
    const v = vysledkyHledani[index];
    // Neexistuje -> nic
    if (!v) {
        // Konec
        return;
    }
    // Schovame vysledky, vymazeme pole
    skryjVysledky();
    elPoleHledat.value = '';
    elPoleHledat.blur();
    // Vybereme den udalosti a prepneme na jeji mesic
    vybranyDen = v.datum;
    await prejdiNaMesic(v.datum);
    // Najdeme udalost v nactenem mesici a otevreme detail
    const udalost = udalosti.find(function (u) { return u.id === v.id; });
    if (udalost) {
        // Detail
        otevriDetail(udalost);
    }
}

// Klavesy ve vyhledavani: sipky, Enter, Escape
function priKlaveseVHledani(e) {
    // Sipka dolu = dalsi vysledek
    if (e.key === 'ArrowDown') {
        // Bez rolovani stranky
        e.preventDefault();
        oznacVysledek(aktivniVysledek + 1);
    } else if (e.key === 'ArrowUp') {
        // Sipka nahoru = predchozi vysledek
        e.preventDefault();
        oznacVysledek(aktivniVysledek - 1);
    } else if (e.key === 'Enter') {
        // Enter = otevrit oznaceny (nebo prvni) vysledek
        e.preventDefault();
        if (vysledkyHledani.length > 0 && !elVysledky.classList.contains('hidden')) {
            // Otevreni
            vyberVysledek(Math.max(0, aktivniVysledek));
        }
    } else if (e.key === 'Escape') {
        // Escape = vymazat a opustit vyhledavani (okna se nezaviraji)
        e.stopPropagation();
        elPoleHledat.value = '';
        skryjVysledky();
        elPoleHledat.blur();
    }
}

// ---------------------------------------------------------------------
// Spusteni aplikace - navazani udalosti na prvky stranky
// ---------------------------------------------------------------------

// Precte vychozi hodnoty z PHP, nastavi obsluhu tlacitek a nacte data
function inicializace() {
    // Vychozi rok, mesic a dnesni datum z data-* atributu (prevod textu na cislo)
    zobrazenyRok = Number(elKalendar.dataset.rok);
    zobrazenyMesic = Number(elKalendar.dataset.mesic);
    dnesniDatum = elKalendar.dataset.dnes;
    vybranyDen = dnesniDatum;
    // Je AI nastavena? (data-ai="1" = server ma API klic)
    aiNastavena = elKalendar.dataset.ai === '1';

    // Prepinani mesicu a navrat na dnesek
    $('btn-predchozi').addEventListener('click', function () { zmenMesic(-1); });
    $('btn-dalsi').addEventListener('click', function () { zmenMesic(1); });
    $('btn-dnes').addEventListener('click', prejdiNaDnes);
    // Nova udalost (tlacitko v hlavicce a kulate tlacitko na mobilu)
    $('btn-nova').addEventListener('click', function () { otevriPridani(vychoziDatumPridani()); });
    $('fab-nova').addEventListener('click', function () { otevriPridani(vychoziDatumPridani()); });

    // Odeslani formulare pro pridani udalosti
    elForm.addEventListener('submit', priOdeslaniFormulare);

    // Tuzky v detailu: datum a cas, nazev, popis
    $('btn-presunout').addEventListener('click', ukazPresun);
    $('btn-upravit-nazev').addEventListener('click', function () { zacniUpravu(elDetailNazev); });
    $('btn-upravit-popis').addEventListener('click', function () { zacniUpravu(elDetailPopis); });
    // Formular presunu
    elFormPresun.addEventListener('submit', priOdeslaniPresunu);
    $('btn-presun-zrusit').addEventListener('click', skryjPresun);
    // Escape ve formulari presunu zavre jen formular, ne cele okno
    elFormPresun.addEventListener('keydown', function (e) {
        // Escape
        if (e.key === 'Escape') {
            // Zastavime probublani a schovame formular
            e.stopPropagation();
            skryjPresun();
            $('btn-presunout').focus();
        }
    });

    // Podukoly: AI, vlastni podukol, hromadne akce s potvrzenim
    elBtnAi.addEventListener('click', function () { generujPodukoly(false); });
    elFormNovyPodukol.addEventListener('submit', pridejPodukol);
    elBtnPregenerovat.addEventListener('click', function () { ukazPotvrzeniPodukolu('pregenerovat'); });
    $('btn-smazat-podukoly').addEventListener('click', function () { ukazPotvrzeniPodukolu('smazat'); });
    elBtnPodukolyAno.addEventListener('click', potvrdAkciPodukolu);
    elBtnPodukolyNe.addEventListener('click', skryjPotvrzeniPodukolu);

    // Mazani udalosti s potvrzenim
    $('btn-smazat').addEventListener('click', ukazPotvrzeniSmazani);
    elBtnSmazatAno.addEventListener('click', potvrdSmazani);
    elBtnSmazatNe.addEventListener('click', skryjPotvrzeniSmazani);

    // Nazev i popis: stejna obsluha klaves a ulozeni pri opusteni prvku
    [elDetailNazev, elDetailPopis].forEach(function (prvek) {
        // Enter / Ctrl+Enter = ulozit, Escape = zrusit
        prvek.addEventListener('keydown', priKlavesePriUprave);
        // Kliknuti jinam (opusteni prvku) = ulozit zmeny
        prvek.addEventListener('blur', ulozUpravu);
    });

    // Vsechna tlacitka s atributem data-zavrit zaviraji sve modalni okno
    document.querySelectorAll('[data-zavrit]').forEach(function (tlacitko) {
        // closest najde nejblizsiho predka s tridou modal-pozadi = cele okno
        tlacitko.addEventListener('click', function () { zavriModal(tlacitko.closest('.modal-pozadi')); });
    });
    // Kliknuti na tmave pozadi mimo okno take zavre modal
    document.querySelectorAll('.modal-pozadi').forEach(function (pozadi) {
        // Zavirame jen pri kliknuti primo na pozadi, ne na obsah okna
        pozadi.addEventListener('mousedown', function (e) {
            // Pozadi = cil kliknuti
            if (e.target === pozadi) {
                // Zavreni
                zavriModal(pozadi);
            }
        });
    });

    // Vyhledavani
    elPoleHledat.addEventListener('input', priPsaniHledani);
    elPoleHledat.addEventListener('keydown', priKlaveseVHledani);
    // Opusteni pole schova vysledky
    elPoleHledat.addEventListener('blur', skryjVysledky);
    // Navrat do pole s textem znovu ukaze vysledky
    elPoleHledat.addEventListener('focus', function () {
        // Text je dost dlouhy -> hledat znovu
        if (elPoleHledat.value.trim().length >= 2) {
            // Nove hledani
            hledej();
        }
    });

    // Tlacitko v oznameni (napr. "Zpět")
    elOznameniAkce.addEventListener('click', function () {
        // Akci si ulozime, oznameni schovame a akci provedeme
        const akce = akceOznameni;
        skryjOznameni();
        if (akce) {
            // Provedeni akce
            akce();
        }
    });

    // Zmena sirky okna (otoceni telefonu) -> prekreslit agendu i zpusob vyberu dne
    MOBIL.addEventListener('change', vykresliKalendar);

    // Ovladani klavesnici
    document.addEventListener('keydown', function (e) {
        // Klavesa Escape zavre vsechna otevrena okna
        if (e.key === 'Escape') {
            // Zavreni oken
            zavriModal(elModalPridat);
            zavriModal(elModalDetail);
            return;
        }
        // Tab v otevrenem okne nesmi utect do kalendare za oknem
        if (e.key === 'Tab' && jeOtevrenModal()) {
            // Udrzeni fokusu
            udrzFokusVOkne(e);
            return;
        }
        // Zkratky nefunguji v otevrenem okne, pri psani do pole a s Ctrl/Alt/Cmd
        if (jeOtevrenModal() || piseUzivatel(e) || e.ctrlKey || e.metaKey || e.altKey) {
            // Konec obsluhy
            return;
        }
        // Sipky = predchozi / dalsi mesic
        if (e.key === 'ArrowLeft') {
            // Posun zpet
            zmenMesic(-1);
        } else if (e.key === 'ArrowRight') {
            // Posun dopredu
            zmenMesic(1);
        } else if (e.key === '/') {
            // Lomitko = vyhledavani (preventDefault: lomitko se nenapise do pole)
            e.preventDefault();
            elPoleHledat.focus();
        } else if (e.key === 'n' || e.key === 'N') {
            // N = nova udalost
            e.preventDefault();
            otevriPridani(vychoziDatumPridani());
        } else if (e.key === 't' || e.key === 'T') {
            // T = dnesni mesic
            prejdiNaDnes();
        }
    });

    // Hned vykreslime prazdnou mrizku, at uzivatel nekouka na prazdnou plochu
    vykresliKalendar();
    // Pak nacteme udalosti a kalendar se prekresli i s nimi
    nactiUdalosti();
}

// Spustime inicializaci (skript je nacten na konci <body>, takze HTML uz existuje)
inicializace();
