// =====================================================================
// formulare.js - drobna vylepseni formularu (prihlaseni, registrace, ucet)
//  - tlacitko "ukázat" u kazdeho pole s heslem (hlavne pro mobil)
//  - ukazatel sily noveho hesla (pole s atributem data-sila)
//  - ochrana proti dvojitemu odeslani formulare
// Vse funguje i bez tohoto skriptu - jen pohodlneji s nim.
// =====================================================================

// Prisnejsi kontrola chyb
'use strict';

// Ohodnoti silu hesla 0-4 (jednoducha pravidla: delka a ruznorodost znaku)
function silaHesla(heslo) {
    // Prazdne heslo = 0
    if (heslo.length === 0) {
        // Bez hodnoceni
        return 0;
    }
    // Pocet splnenych pravidel
    let body = 0;
    // Aspon 8 znaku (minimum aplikace) a aspon 12 znaku
    if (heslo.length >= 8) { body++; }
    if (heslo.length >= 12) { body++; }
    // Mala i velka pismena
    // (\p{Ll} = libovolne male pismeno vcetne ceskych, \p{Lu} = velke)
    if (/\p{Ll}/u.test(heslo) && /\p{Lu}/u.test(heslo)) { body++; }
    // Cislice nebo jiny znak nez pismeno
    if (/\d/.test(heslo) || /[^\p{L}\d]/u.test(heslo)) { body++; }
    // Kratke heslo je vzdy slabe
    return heslo.length < 8 ? 1 : Math.min(4, body);
}

// Texty k jednotlivym urovnim sily
const POPIS_SILY = ['', 'slabé', 'ucházející', 'dobré', 'silné'];

// Ke kazdemu poli s heslem pridame tlacitko "ukázat"
document.querySelectorAll('input[type="password"]').forEach(function (pole) {
    // Obal pole (tlacitko lezi uvnitr pole vpravo)
    const obal = document.createElement('div');
    obal.className = 'pole-heslo';
    // Pole presuneme do obalu
    pole.parentNode.insertBefore(obal, pole);
    obal.appendChild(pole);
    // Tlacitko
    const tlacitko = document.createElement('button');
    tlacitko.type = 'button';
    tlacitko.className = 'ukazat-heslo';
    tlacitko.textContent = 'ukázat';
    tlacitko.setAttribute('aria-label', 'Ukázat heslo');
    tlacitko.setAttribute('aria-pressed', 'false');
    obal.appendChild(tlacitko);
    // Kliknuti prepne typ pole (password <-> text)
    tlacitko.addEventListener('click', function () {
        // Je heslo prave skryte?
        const skryte = pole.type === 'password';
        // Prepnuti typu a textu tlacitka
        pole.type = skryte ? 'text' : 'password';
        tlacitko.textContent = skryte ? 'skrýt' : 'ukázat';
        tlacitko.setAttribute('aria-pressed', skryte ? 'true' : 'false');
        tlacitko.setAttribute('aria-label', skryte ? 'Skrýt heslo' : 'Ukázat heslo');
        // Kurzor zpet do pole
        pole.focus();
    });

    // Ukazatel sily jen u noveho hesla (atribut data-sila)
    if (pole.hasAttribute('data-sila')) {
        // Prouzek a text pod polem
        const sila = document.createElement('div');
        sila.className = 'sila-hesla';
        sila.setAttribute('aria-live', 'polite');
        // Prouzek se ctyrmi dilky
        const prouzek = document.createElement('div');
        prouzek.className = 'sila-prouzek';
        // Text urovne
        const text = document.createElement('span');
        text.className = 'sila-text';
        sila.append(prouzek, text);
        obal.insertAdjacentElement('afterend', sila);
        // Pri psani se sila prepocita
        pole.addEventListener('input', function () {
            // Uroven 0-4 se zapise do data atributu (barvy resi style.css)
            const uroven = silaHesla(pole.value);
            sila.dataset.uroven = String(uroven);
            text.textContent = uroven > 0 ? 'Síla hesla: ' + POPIS_SILY[uroven] : '';
        });
    }
});

// Ochrana proti dvojitemu odeslani (napr. dvojklik na "Přihlásit se")
document.querySelectorAll('form[method="post"]').forEach(function (formular) {
    // Pri odeslani
    formular.addEventListener('submit', function () {
        // Odesilaci tlacitko formulare
        const tlacitko = formular.querySelector('button[type="submit"]');
        // Zablokujeme ho az po odeslani (setTimeout 0), jinak by prohlizec formular neposlal
        if (tlacitko) {
            // Zablokovani s malym zpozdenim
            setTimeout(function () { tlacitko.disabled = true; }, 0);
        }
    });
});

// Navrat tlacitkem Zpet (stranka z pameti prohlizece) -> tlacitka znovu povolit
window.addEventListener('pageshow', function () {
    // Vsechna odesilaci tlacitka
    document.querySelectorAll('button[type="submit"]').forEach(function (tlacitko) {
        // Povoleni
        tlacitko.disabled = false;
    });
});
