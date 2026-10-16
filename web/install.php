<?php
/**
 * install.php – instalace Legislativního radaru na webhosting.
 *
 * 1. Nahrajte obsah archivu na hosting (např. do složky /radar).
 * 2. Otevřete v prohlížeči https://vas-web.cz/radar/install.php a vyplňte přístup k databázi a účet správce.
 * 3. Instalátor ověří požadavky, vytvoří tabulky, účet správce a soubor config.php.
 * 4. Nastavte cron (příkaz se zobrazí na konci) – nebo data naplňte rovnou tlačítkem v instalátoru.
 *
 * Po instalaci se instalátor zablokuje (existuje config.php). Aktualizace databáze po nahrání nové verze
 * aplikace (vytvoří chybějící tabulky, data zůstanou): install.php?klic=<cron_klic z config.php>
 * nebo z příkazové řádky: php install.php --aktualizace
 */

declare(strict_types=1);
require __DIR__ . '/lib/zaklad.php';
require_once __DIR__ . '/lib/Import.php';
require_once __DIR__ . '/lib/Relevance.php';

$cli = PHP_SAPI === 'cli';

/** Kontrola požadavků: [[popis, ok, povinné, poznámka], …] */
function pozadavky(): array
{
    $zapis = is_writable(__DIR__);
    $data = is_dir(__DIR__ . '/data') ? is_writable(__DIR__ . '/data') : $zapis;
    return [
        ['PHP 8.0 nebo novější', PHP_VERSION_ID >= 80000, true, 'máte ' . PHP_VERSION],
        ['rozšíření pdo_mysql', extension_loaded('pdo_mysql'), true, 'připojení k MariaDB / MySQL'],
        ['rozšíření zip (ZipArchive)', class_exists('ZipArchive'), true, 'archivy ODOK a Sněmovny, dokumenty Word, Excel'],
        ['rozšíření zlib', function_exists('gzopen'), true, 'data e-Legislativy, PDF'],
        ['rozšíření mbstring', extension_loaded('mbstring'), true, 'práce s českým textem'],
        ['rozšíření dom', class_exists('DOMDocument'), true, 'dokumenty Word a Excel'],
        ['rozšíření curl', function_exists('curl_init'), false, 'stahování dat (bez něj se použije allow_url_fopen, pomaleji a bez souběžného stahování)'],
        ['stahování z internetu', function_exists('curl_init') || ini_get('allow_url_fopen'), true, 'curl nebo allow_url_fopen'],
        ['zápis do složky aplikace', $zapis, false, $zapis ? 'config.php se vytvoří automaticky' : 'config.php bude potřeba nahrát ručně (obsah se zobrazí)'],
        ['zápis do složky data/', $data, false, 'dočasné soubory importu (jinak se použije systémový dočasný adresář)'],
        ['zápis do složky pravidla/', is_dir(__DIR__ . '/pravidla') ? is_writable(__DIR__ . '/pravidla') : $zapis, false, 'sady výrazů pro analýzu relevance (nahrávání v administraci)'],
    ];
}

function zapis_konfigurace(array $k): string
{
    $export = var_export($k, true);
    return "<?php\n// Konfigurace Legislativního radaru (vytvořil install.php " . date('j. n. Y H:i') . ").\n"
        . "// Další volby a jejich výchozí hodnoty viz config.sample.php.\nreturn " . $export . ";\n";
}

function nainstaluj(array $v): array
{
    $db = ['host' => trim($v['host']), 'port' => (int)($v['port'] ?: 3306), 'nazev' => trim($v['nazev']),
        'uzivatel' => trim($v['uzivatel']), 'heslo' => (string)$v['heslo_db'], 'prefix' => trim($v['prefix'])];
    if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $db['prefix'])) {
        throw new RuntimeException('Prefix tabulek smí obsahovat jen písmena, číslice a podtržítko.');
    }
    if (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', trim($v['login']))) {
        throw new RuntimeException('Přihlašovací jméno správce: jen písmena bez diakritiky, číslice a . _ @ - (2–64 znaků).');
    }
    if ($ch = Auth::kontrolaHesla((string)$v['heslo'])) {
        throw new RuntimeException('Heslo správce: ' . $ch);
    }
    if ($v['heslo'] !== $v['heslo2']) {
        throw new RuntimeException('Hesla správce se neshodují.');
    }
    try {
        $pdo = Db::pripoj($db);
    } catch (PDOException $e) {
        throw new RuntimeException('K databázi se nepodařilo připojit: ' . $e->getMessage());
    }
    $verze = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $konfig = [
        'db' => $db,
        'cron_klic' => bin2hex(random_bytes(16)),
        'anonymni_cteni' => !empty($v['anonymni']),
        'odok_prvni_rok' => max(2010, min((int)date('Y'), (int)($v['prvni_rok'] ?: 2016))),
    ];
    Konfig::nastav($konfig);
    Import::vytvorTabulky();
    if (Db::hodnota('SELECT id FROM {p}uzivatel WHERE login = ?', [trim($v['login'])])) {
        Db::q('UPDATE {p}uzivatel SET heslo = ?, role = ?, aktivni = 1 WHERE login = ?',
            [password_hash((string)$v['heslo'], PASSWORD_DEFAULT), 'admin', trim($v['login'])]);
    } else {
        Db::q('INSERT INTO {p}uzivatel (login, jmeno, email, heslo, role, aktivni, vytvoreno) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())',
            [trim($v['login']), trim($v['jmeno']), trim($v['email']), password_hash((string)$v['heslo'], PASSWORD_DEFAULT), 'admin']);
    }
    SadyPravidel::zajisti();      // složka pravidla/ se základní sadou výrazů ze vzoru
    $obsah = zapis_konfigurace($konfig);
    $zapsano = @file_put_contents(__DIR__ . '/config.php', $obsah) !== false;
    if ($zapsano) {
        @chmod(__DIR__ . '/config.php', 0640);
    }
    @mkdir(__DIR__ . '/data/tmp', 0775, true);
    return [$konfig, $zapsano, $obsah, $verze];
}

// ---------------------------------------------------------------- příkazová řádka

if ($cli) {
    if (in_array('--aktualizace', $argv, true)) {
        if (!Konfig::existuje()) {
            fwrite(STDERR, "Chybí config.php – nejdřív instalujte přes prohlížeč.\n");
            exit(1);
        }
        Db::pripoj();
        Import::vytvorTabulky();
        echo "Databáze je aktuální.\n";
        exit(0);
    }
    echo "Instalace se provádí v prohlížeči: otevřete install.php na svém webu.\n"
        . "Aktualizace databáze po nahrání nové verze: php install.php --aktualizace\n";
    foreach (pozadavky() as [$popis, $ok, $povinne, $pozn]) {
        printf("  [%s] %s – %s\n", $ok ? 'OK' : ($povinne ? 'CHYBÍ' : 'pozor'), $popis, $pozn);
    }
    exit(0);
}

// ---------------------------------------------------------------- web

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');

function stranka(string $obsah): void
{
    echo '<!DOCTYPE html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Instalace – Legislativní radar</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/styl.css">
<style>main{max-width:860px} fieldset{border:1px solid var(--okraj);border-radius:8px;margin:0 0 16px;padding:12px 16px;background:var(--panel)}
legend{font-weight:600;padding:0 6px} .ok{color:#2e7d32} .chybi{color:#b3261e} .pozor{color:#8a5a00} #vystup{max-height:420px}</style></head>
<body><header><a class="logo" href="index.php"><span class="znak">§</span> Legislativní radar</a><span class="tlumene">instalace</span></header><main>'
        . $obsah . '</main></body></html>';
}

if (Konfig::existuje()) {
    $klic = (string)Konfig::get('cron_klic');
    if ($klic !== '' && hash_equals($klic, (string)($_GET['klic'] ?? ''))) {
        try {
            Db::pripoj();
            Import::vytvorTabulky();
            stranka("<h1>Aktualizace databáze</h1><div class='zprava ok'>Databáze je aktuální (chybějící tabulky byly vytvořeny, data zůstala).</div><p><a class='tlacitko' href='index.php'>Otevřít aplikaci</a></p>");
        } catch (Throwable $e) {
            stranka("<h1>Aktualizace databáze</h1><div class='chyba'>" . e($e->getMessage()) . '</div>');
        }
        exit;
    }
    stranka("<h1>Aplikace je nainstalovaná</h1><p>Soubor <code>config.php</code> už existuje, instalátor je proto zablokovaný.
Pro novou instalaci smažte <code>config.php</code>. Aktualizaci databáze po nahrání nové verze spustíte adresou
<code>install.php?klic=…</code> (klíč <code>cron_klic</code> z config.php) nebo příkazem <code>php install.php --aktualizace</code>.</p>
<p><a class='tlacitko' href='index.php'>Otevřít aplikaci</a></p>");
    exit;
}

session_name('radar_instalace');
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$poz = pozadavky();
$lze = !in_array(false, array_map(function ($p) { return $p[1] || !$p[2]; }, $poz), true);
$chyba = '';
$v = array_merge(['host' => 'localhost', 'port' => '3306', 'nazev' => '', 'uzivatel' => '', 'heslo_db' => '', 'prefix' => 'lr_',
    'login' => 'admin', 'jmeno' => '', 'email' => '', 'heslo' => '', 'heslo2' => '', 'prvni_rok' => '2016', 'anonymni' => ''],
    array_map('strval', $_POST));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lze) {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $chyba = 'Formulář vypršel, odešlete ho prosím znovu.';
    } else {
        try {
            [$konfig, $zapsano, $obsah_konfigurace, $verze] = nainstaluj($v);
            $cesta = Auth::cesta();
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $url_cron = sprintf('%s://%s%scron.php?klic=%s', $https, $_SERVER['HTTP_HOST'] ?? 'vas-web.cz', $cesta, $konfig['cron_klic']);
            $php = (PHP_BINARY && stripos(PHP_BINARY, 'fpm') === false && stripos(PHP_BINARY, 'cgi') === false) ? PHP_BINARY : 'php';
            $html = '<h1>Hotovo</h1><div class="zprava ok">Databáze (' . e($verze) . ') je připravená a účet správce <b>' . e(trim($v['login'])) . '</b> vytvořen.</div>';
            if (!$zapsano) {
                $html .= '<div class="zprava chyba">Soubor <code>config.php</code> se nepodařilo zapsat (složka není zapisovatelná). Vytvořte ho ručně ve složce aplikace s tímto obsahem:</div><pre class="kod">'
                    . e($obsah_konfigurace) . '</pre>';
            }
            $html .= '<h2>1. Naplnění dat</h2><p>Data (ODOK po ročnících, Sněmovna, e-Legislativa) se stahují z otevřených zdrojů. První naplnění
trvá několik minut. Můžete ho spustit hned odsud – import poběží po částech, dokud není hotovo (stránku nechte otevřenou):</p>
<div class="lista"><button type="button" id="naplnit">Naplnit data teď</button><span class="tlumene" id="stav"></span></div>
<pre class="kod" id="vystup" hidden></pre>
<h2>2. Cron</h2><p>Aby se data průběžně obnovovala, nastavte na hostingu cron, ideálně každých 15 minut:</p>
<pre class="kod">*/15 * * * * ' . e($php) . ' ' . e(__DIR__ . '/cron.php') . '</pre>
<p>Pokud hosting umí cron jen jako volání adresy, použijte:</p><pre class="kod">' . e($url_cron) . '</pre>
<p class="tlumene">Adresu s klíčem nikomu nedávejte. Klíč je v <code>config.php</code> (<code>cron_klic</code>).</p>
<h2>3. Hotovo</h2><p><a class="tlacitko" href="index.php">Otevřít aplikaci a přihlásit se</a> – uživatele přidáte v sekci <b>Správa</b>.</p>
<p class="tlumene">Instalátor se teď sám zablokoval. Soubor <code>install.php</code> můžete pro jistotu smazat.</p>
<script>
document.getElementById("naplnit").addEventListener("click", function () {
  var b = this, v = document.getElementById("vystup"), s = document.getElementById("stav"), n = 0;
  b.disabled = true; v.hidden = false;
  function krok() {
    n++; s.textContent = "běží " + n + ". část…";
    fetch(' . json_encode('cron.php?klic=' . $konfig['cron_klic'] . '&rozpocet=20') . ').then(function (r) { return r.text(); }).then(function (t) {
      v.textContent += t; v.scrollTop = v.scrollHeight;
      if (t.indexOf("Nic k obnově") >= 0 || n >= 80) { s.textContent = n >= 80 ? "Přerušeno – zbytek doplní cron." : "Hotovo – data jsou naplněná."; b.disabled = false; return; }
      krok();
    }).catch(function (e) { s.textContent = "Chyba: " + e + " – zkusím znovu"; setTimeout(krok, 3000); });
  }
  krok();
});
</script>';
            stranka($html);
            exit;
        } catch (Throwable $e) {
            $chyba = $e->getMessage();
        }
    }
}

$radky = '';
foreach ($poz as [$popis, $ok, $povinne, $pozn]) {
    $radky .= sprintf('<tr><td>%s</td><td class="%s">%s</td><td class="tlumene">%s</td></tr>', e($popis),
        $ok ? 'ok' : ($povinne ? 'chybi' : 'pozor'), $ok ? '✔ v pořádku' : ($povinne ? '✘ chybí' : '! upozornění'), e($pozn));
}
$pole = function ($nazev, $popis, $typ = 'text', $navic = '') use ($v) {
    return sprintf('<label>%s <input type="%s" name="%s" value="%s"%s></label>', $popis, $typ, $nazev,
        $typ === 'password' ? '' : e($v[$nazev]), $navic);
};
$formular = $lze ? sprintf('<form method="post" class="formular" style="max-width:none">
<input type="hidden" name="csrf" value="%s">
<fieldset><legend>Databáze MariaDB / MySQL</legend>
<p class="tlumene">Databázi a uživatele založte v administraci hostingu; tabulky vytvoří instalátor.</p>
%s%s%s%s%s%s</fieldset>
<fieldset><legend>Účet správce</legend>%s%s%s%s%s</fieldset>
<fieldset><legend>Nastavení</legend>%s
<label class="zaskrt"><input type="checkbox" name="anonymni" value="1"%s> povolit prohlížení bez přihlášení (oblíbené jen přihlášeným)</label></fieldset>
<button>Nainstalovat</button></form>', e($_SESSION['csrf']),
    $pole('host', 'Server', 'text', ' required'), $pole('port', 'Port', 'number'), $pole('nazev', 'Název databáze', 'text', ' required'),
    $pole('uzivatel', 'Uživatel databáze', 'text', ' required'), $pole('heslo_db', 'Heslo databáze', 'password'),
    $pole('prefix', 'Prefix tabulek (umožní více aplikací v jedné databázi)'),
    $pole('login', 'Přihlašovací jméno', 'text', ' required'), $pole('jmeno', 'Jméno'), $pole('email', 'E-mail', 'email'),
    $pole('heslo', 'Heslo (aspoň 8 znaků)', 'password', ' required minlength="8" autocomplete="new-password"'),
    $pole('heslo2', 'Heslo znovu', 'password', ' required minlength="8" autocomplete="new-password"'),
    $pole('prvni_rok', 'Nejstarší ročník ODOK k importu (starší ročníky = větší databáze a delší první import)', 'number', ' min="2010" max="' . date('Y') . '"'),
    $v['anonymni'] ? ' checked' : '')
    : '<div class="chyba">Server nesplňuje povinné požadavky – požádejte poskytovatele hostingu o zapnutí chybějících rozšíření PHP.</div>';

stranka(sprintf('<h1>Instalace Legislativního radaru</h1>
<p>Webová aplikace pro sledování legislativního procesu (ODOK / eKLEP, e-Legislativa, Poslanecká sněmovna a Senát).</p>
<h2>Požadavky</h2><div class="posun"><table class="data"><tbody>%s</tbody></table></div>
<h2>Nastavení</h2>%s%s', $radky, $chyba !== '' ? '<div class="zprava chyba">' . e($chyba) . '</div>' : '', $formular));
