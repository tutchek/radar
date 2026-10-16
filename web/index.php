<?php
/**
 * Legislativní radar – webová aplikace (PHP verze). Všechny stránky obsluhuje tento soubor (index.php?p=…).
 * Data plní cron.php, instalaci provede install.php.
 */
declare(strict_types=1);
require __DIR__ . '/lib/zaklad.php';
zachyt_fatalni_chyby();

if (!Konfig::existuje()) {
    header('Location: install.php');
    exit;
}
try {
    Db::pripoj();
} catch (PDOException $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Nelze se připojit k databázi. Zkontrolujte nastavení v config.php.\n";
    exit;
}
Import::zkontrolujSchema();      // po nahrání nové verze doplní chybějící tabulky a sloupce
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');
Auth::start();
(new Web())->obsluz();
