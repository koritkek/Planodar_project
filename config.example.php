<?php
// =====================================================================
// config.example.php - VZOR lokalniho nastaveni (tento soubor je v gitu)
//
// Pro XAMPP: zkopiruj ho jako config.php a vypln API klic. Soubor
// config.php je v .gitignore, takze se klic nikdy nedostane na GitHub.
// Na hostingu (Render) se config.php nepouziva - stejne hodnoty se tam
// zadavaji jako promenne prostredi (seznam je v .env.example).
// =====================================================================

return [
    // API klic pro Groq - ZDARMA, ziskas ho na https://console.groq.com/keys (zacina "gsk_")
    'groq_api_key' => '',

    // Nepovinne: placeny Claude (https://platform.claude.com -> API Keys, zacina "sk-ant-")
    'anthropic_api_key' => '',

    // ----- Vse nize je nepovinne (vychozi hodnoty funguji v XAMPP) -----

    // Poskytovatel AI: auto (prednost ma Groq, kdyz je jeho klic vyplneny) / groq / claude
    'ai_poskytovatel' => 'auto',
    // Model: prazdne = vychozi (Groq: openai/gpt-oss-120b, Claude: claude-haiku-5-5)
    'ai_model'  => '',
    // Jak dukladne model premysli: low = nejmene tokenu
    'ai_effort' => 'low',

    // Ochrana kreditu: generovani za hodinu na uzivatele / za den pro vsechny dohromady
    'ai_limit_za_hodinu' => 20,
    'ai_limit_za_den'    => 200,

    // Pripojeni k databazi (XAMPP: localhost, root, bez hesla)
    'db_host'  => 'localhost',
    'db_port'  => 3306,
    'db_user'  => 'root',
    'db_heslo' => '',
    'db_nazev' => 'planodar',

    // false = registrace novych uctu je vypnuta
    'registrace_povolena' => true,
];
