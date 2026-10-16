<?php
/**
 * cron.php – plnění databáze z otevřených dat (ODOK, Sněmovna, e-Legislativa).
 *
 * Z příkazové řádky (doporučeno, např. každých 15 minut):
 *     php /cesta/k/radar/cron.php                 # obnoví zdroje, kterým vypršela platnost
 *     php cron.php --vse                          # stáhne a naimportuje vše hned
 *     php cron.php --jen=psp,esel                 # jen vybrané zdroje (odok = všechny ročníky, odok:2026 …)
 *     php cron.php --rozpocet=300                 # skončí po 5 minutách, zbytek příště
 *     php cron.php --stav                         # vypíše stav zdrojů
 *
 * Přes HTTP (když hosting umí cron jen jako volání adresy):
 *     https://vas-web.cz/radar/cron.php?klic=<cron_klic z config.php>
 * Přes HTTP běží s časovým rozpočtem 25 s (?rozpocet=…) – jednotlivé zdroje se doplní při dalších voláních.
 */

declare(strict_types=1);
require __DIR__ . '/lib/zaklad.php';
zachyt_fatalni_chyby(false);

$cli = PHP_SAPI === 'cli';
if (!Konfig::existuje()) {
    if (!$cli) {
        http_response_code(503);
    }
    echo "Aplikace není nainstalovaná (chybí config.php) – spusťte install.php.\n";
    exit(1);
}

if ($cli) {
    $volby = getopt('v', ['vse', 'jen:', 'rozpocet:', 'stav', 'help']);
    if (isset($volby['help'])) {
        echo preg_replace('/^\s*\* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__))[1])[0]);
        exit(0);
    }
    $rozpocet = (int)($volby['rozpocet'] ?? 0);
    $vse = isset($volby['vse']);
    $jen = isset($volby['jen']) ? array_filter(explode(',', (string)$volby['jen'])) : null;
    @set_time_limit(0);
    $vypis = function ($t) { echo date('H:i:s '), $t, "\n"; };
} else {
    header('Content-Type: text/plain; charset=utf-8');
    $klic = (string)Konfig::get('cron_klic');
    if ($klic === '' || !hash_equals($klic, (string)($_GET['klic'] ?? ''))) {
        http_response_code(403);
        echo "Neplatný klíč.\n";
        exit(1);
    }
    $rozpocet = max(5, (int)($_GET['rozpocet'] ?? 25));
    $vse = !empty($_GET['vse']);
    $jen = !empty($_GET['jen']) ? array_filter(explode(',', (string)$_GET['jen'])) : null;
    @set_time_limit($rozpocet + 300);
    ignore_user_abort(true);
    $vypis = function ($t) { echo date('H:i:s '), $t, "\n"; @flush(); };
}

try {
    Db::pripoj();
    Import::zkontrolujSchema();      // nová verze aplikace nahraná bez otevření webu – doplnit tabulky i pro cron
} catch (PDOException $e) {
    echo 'Nelze se připojit k databázi: ', $e->getMessage(), "\n";
    exit(1);
}

if ($cli && isset($volby['stav'])) {
    $stav = Import::stavZdroju();
    foreach (Import::zdroje() as $k => $info) {
        $s = $stav[$k] ?? null;
        printf("%-12s %-8s %-20s %s\n", $k, $s['stav'] ?? '-', $s && $s['stazeno'] ? date('j. n. Y H:i', strtotime($s['stazeno'] . ' UTC')) : 'nikdy',
            $s['zprava'] ?? '');
    }
    exit(0);
}

$import = new Import($rozpocet, $vypis);
$import->spust($vse, $jen);
$chyb = count(array_filter($import->vysledky, function ($v) { return $v[0] !== 'ok'; }));
if (!$import->vysledky) {
    $vypis('Nic k obnově – všechna data jsou čerstvá.');
}
exit($chyb ? 2 : 0);
