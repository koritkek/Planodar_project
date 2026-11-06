<?php
// =====================================================================
// export.php - stazeni vsech vlastnich dat (ucet, udalosti, podukoly)
// jako soubor JSON. Kazdy si muze stahnout jen svoje data.
// =====================================================================

// Ucty, session a databaze
require_once 'auth.php';

// Jen pro prihlasene (jinak presmeruje na prihlaseni)
$uzivatel = vyzadujPrihlaseni($spojeni);
// Id prihlaseneho uzivatele
$user_id = (int) $uzivatel['id'];

// Vsechny udalosti uzivatele serazene podle data a casu
$udalosti = nactiRadky($spojeni,
    "SELECT id, nazev, datum, TIME_FORMAT(cas, '%H:%i') AS cas, popis, vytvoreno
     FROM events WHERE user_id = ? ORDER BY datum, cas",
    'i', [$user_id]);

// Vsechny podukoly tech udalosti (JOIN pres events zajisti, ze jsou jen jeho)
$podukoly = nactiRadky($spojeni,
    'SELECT s.id, s.event_id, s.nazev, s.cas, s.odhad_minut, s.dulezite, s.splneno
     FROM subtasks s JOIN events e ON e.id = s.event_id
     WHERE e.user_id = ? ORDER BY s.id',
    'i', [$user_id]);

// Podukoly roztridime k udalostem: klic pole = id udalosti
$podukolyPodleUdalosti = [];
// Projdeme vsechny podukoly
foreach ($podukoly as $podukol) {
    // Pridame podukol do seznamu jeho udalosti; splneno prevedeme na true/false
    $podukolyPodleUdalosti[$podukol['event_id']][] = [
        'nazev'   => $podukol['nazev'],             // Nazev podukolu
        'cas'     => $podukol['cas'],               // Termin (nebo null)
        'odhad_minut' => $podukol['odhad_minut'] === null ? null : (int) $podukol['odhad_minut'],  // Odhad casu
        'dulezite' => (bool) $podukol['dulezite'],  // Dulezity ano/ne
        'splneno' => (bool) $podukol['splneno'],    // Splneno ano/ne
    ];
}

// Ke kazde udalosti pripojime jeji podukoly (& = menime primo prvky pole)
foreach ($udalosti as &$udalost) {
    // Podukoly teto udalosti, nebo prazdne pole
    $udalost['podukoly'] = $podukolyPodleUdalosti[$udalost['id']] ?? [];
    // Interni id do exportu nepatri
    unset($udalost['id']);
}
// Zrusime odkaz z cyklu (dobra praxe po foreach s &)
unset($udalost);

// Cela data exportu
$data = [
    'exportovano' => date('c'),                 // Datum a cas exportu (ISO 8601)
    'ucet' => [
        'jmeno'     => $uzivatel['jmeno'],      // Jmeno
        'email'     => $uzivatel['email'],      // E-mail
        'vytvoreno' => $uzivatel['vytvoreno'],  // Datum registrace
    ],
    'udalosti' => $udalosti,                    // Udalosti s podukoly
];

// Odpoved je JSON v UTF-8
header('Content-Type: application/json; charset=utf-8');
// attachment = prohlizec soubor stahne misto zobrazeni; filename = nazev souboru
header('Content-Disposition: attachment; filename="planodar-export-' . date('Y-m-d') . '.json"');
// Osobni data se nesmi ukladat do mezipameti
header('Cache-Control: no-store');
// Prehledne odsazeny JSON s citelnymi ceskymi znaky
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
