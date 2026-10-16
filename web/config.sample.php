<?php
/**
 * Vzor konfigurace Legislativního radaru. Instalátor (install.php) vytvoří config.php sám;
 * tento soubor slouží jako přehled všech voleb. Neuvedené volby mají výchozí hodnoty uvedené zde.
 */
return [
    // přístup k databázi MariaDB / MySQL
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'nazev' => 'radar',
        'uzivatel' => 'radar',
        'heslo' => '',
        'prefix' => 'lr_',          // prefix tabulek (více aplikací v jedné databázi)
    ],

    // klíč pro spuštění cron.php přes HTTP: https://vas-web.cz/radar/cron.php?klic=…
    'cron_klic' => 'změňte-na-náhodný-řetězec',

    // true = prohlížení bez přihlášení (oblíbené a správa jen pro přihlášené)
    'anonymni_cteni' => false,

    // nejstarší ročník datasetu ODOK, který se importuje (2016 = jako původní aplikace)
    'odok_prvni_rok' => 2016,

    // jak dlouho zůstane uživatel přihlášený na zařízení, kde zaškrtl „Zůstat přihlášen“
    // (dní od posledního použití; po změně hesla, zablokování či smazání účtu přestane platit; 0 = vypnuto)
    'prihlaseni_dni' => 30,

    // po kolika hodinách se zdroj stáhne znovu; 24 = „denní“ zdroj – stahuje se po každé z hodin denni_obnova,
    // 0 = jen jednou – po úspěšném importu už nikdy (ručně jde obnovit v Správa → Data a import)
    'platnost_h' => [
        'odok' => 24,          // ODOK – letošní ročník
        'odok_stary' => 0,     // ODOK – minulé ročníky (už se nemění; po skončení roku se ještě
                               //        jednou stáhnou 2. ledna, aby se zachytil konec prosince)
        'psp' => 24,           // Poslanecká sněmovna a Senát (otevřená data se aktualizují jednou denně)
        'esel' => 24,          // e-Legislativa (aktualizace jednou denně)
        'detail' => 2,         // verze a připomínky materiálu (stahují se při zobrazení)
    ],
    // po kterých hodinách se „denní“ zdroje stahují znovu – data úřadů se aktualizují v noci, večerní běh
    // je pojistka pro případ, že se ranní aktualizace zpozdí (aby nevznikla celodenní mezera)
    'denni_obnova' => [7, 19],

    // resorty, jejichž historie verzí cron předem stáhne, aby se podklady PLPV sestavily rychle
    // (např. ['MF']); prázdné = verze se stahují až při zobrazení podkladů
    'plpv_prednacist' => [],

    // kolik historií verzí stahovat z ODOK najednou; když ODOK hlásí „ukončil spojení“ / „přetížený“
    // i po automatickém opakování, snižte na 2 nebo 1
    'soubezne_stahovani' => 4,

    // proxy pro odchozí spojení (obvykle není potřeba), např. 'http://proxy.firma.cz:8080'
    'proxy' => '',

    // časový limit jednoho stažení (s)
    'timeout' => 60,

    'casova_zona' => 'Europe/Prague',
];
