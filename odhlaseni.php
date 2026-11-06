<?php
// =====================================================================
// odhlaseni.php - odhlaseni na tomto zarizeni
// Prijima jen POST s CSRF tokenem: obycejny odkaz (GET) by sel zneuzit -
// cizi web by mohl uzivatele odhlasit treba obrazkem <img src="odhlaseni.php">.
// =====================================================================

// Ucty, session a databaze
require_once 'auth.php';

// Jen POST s platnym CSRF tokenem
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !overCsrf($_POST['csrf'] ?? '')) {
    // Jinak nic nedelame a vratime se do kalendare
    presmeruj('index.php');
}

// Odhlaseni (smaze session i "Zapamatovat si me" na tomto zarizeni)
odhlasUzivatele($spojeni);
// Zprava pro prihlasovaci stranku
nastavZpravu('ok', 'Byl/a jsi odhlášen/a. Měj se!');
// Zpet na prihlaseni
presmeruj('prihlaseni.php');
