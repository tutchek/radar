<?php
/**
 * diagnostika.php – kontrola, proč aplikace nefunguje (pro správce).
 *
 *     https://vas-web.cz/radar/diagnostika.php?klic=<cron_klic z config.php>
 *     php diagnostika.php
 *
 * Ukáže nastavení PHP, připojení k databázi a obsah tabulek, relace, spojení na zdroje dat,
 * stav importu, poslední chyby a zkusí vykreslit hlavní stránky (s měřením času).
 */

declare(strict_types=1);
$zacatek_skriptu = microtime(true);
require __DIR__ . '/lib/zaklad.php';
require_once __DIR__ . '/lib/Import.php';
zachyt_fatalni_chyby(false);

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    if (!Konfig::existuje()) {
        echo "Chybí config.php – aplikace není nainstalovaná (otevřete install.php).\n";
        exit;
    }
    $klic = (string)Konfig::get('cron_klic');
    if ($klic === '' || !hash_equals($klic, (string)($_GET['klic'] ?? ''))) {
        http_response_code(403);
        echo "Zadejte klíč: diagnostika.php?klic=<cron_klic z config.php>\n";
        exit;
    }
}
@set_time_limit(120);
while (ob_get_level()) {
    ob_end_flush();
}

// rozbor párování materiálu ODOK s návrhem v e-Legislativě:
//   diagnostika.php?klic=…&parovani=KORNDX5MJKKB[&navrh=2026-0183]
//   php diagnostika.php --parovani=KORNDX5MJKKB --navrh=2026-0183
$volby = $cli ? getopt('', ['parovani:', 'navrh:', 'tisk:']) : $_GET;
// rozbor párování materiálu ODOK se sněmovním tiskem (PID, záložně čj OVA):
//   diagnostika.php?klic=…&tisk=KORNDXGKDRP9      php diagnostika.php --tisk=KORNDXGKDRP9
if (!empty($volby['tisk'])) {
    Db::pripoj();
    diagnostika_tisk(strtoupper(trim((string)$volby['tisk'])));
    exit;
}
if (!empty($volby['parovani'])) {
    Db::pripoj();
    diagnostika_parovani(trim((string)$volby['parovani']), trim((string)($volby['navrh'] ?? '')));
    exit;
}

function diagnostika_tisk(string $pid): void
{
    echo "Legislativní radar – párování materiálu ODOK se sněmovním tiskem (" . date('j. n. Y H:i:s') . ")\n";
    nadpis('Verze kódu');
    foreach (['lib/Psp.php', 'lib/WebPsp.php', 'lib/WebNastenka.php'] as $f) {
        radek($f, date('j. n. Y H:i:s', (int)@filemtime(__DIR__ . '/' . $f)) . ', ' . (int)@filesize(__DIR__ . '/' . $f) . ' B');
    }
    radek('záložní párování podle čj OVA v kódu', method_exists('Psp', 'tisky_podle_ova') ? 'ano' : 'NE – nahrajte nový lib/Psp.php', method_exists('Psp', 'tisky_podle_ova'));
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        radek('OPcache', $st ? sprintf('zapnutá (revalidate_freq %s s, validate_timestamps %s)', ini_get('opcache.revalidate_freq'), ini_get('opcache.validate_timestamps')) : 'vypnutá');
    }
    nadpis("Materiál $pid v tabulce ODOK");
    $radky = Db::vse('SELECT rok, pid, cj, cj_ova, stav_id, stav, typ_id, LEFT(nazev, 90) AS nazev FROM {p}odok_material WHERE UPPER(TRIM(pid)) = ?', [$pid]);
    if (!$radky) {
        radek('materiál', 'v tabulce materiálů není', false);
    }
    $ova = null;
    foreach ($radky as $r) {
        radek('ročník ' . $r['rok'], sprintf('čj OVA %s · čj %s · stav %s %s · typ %s · %s', var_export($r['cj_ova'], true), $r['cj'],
            $r['stav_id'], $r['stav'], $r['typ_id'], $r['nazev']));
        $ova = $ova ?? (trim((string)$r['cj_ova']) !== '' ? $r['cj_ova'] : null);
    }
    nadpis('Vazby Sněmovny na eKLEP podle PID');
    foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
        $x = Db::vse('SELECT * FROM ' . Psp::tab($tab) . ' WHERE UPPER(TRIM(pid)) = ?', [$pid]);
        radek($tab, $x ? json_encode($x, JSON_UNESCAPED_UNICODE) : 'nic');
    }
    nadpis('Vazby Sněmovny na eKLEP podle čj OVA ' . var_export($ova, true));
    if ($ova !== null && preg_match('/^\s*(\d+)\s*\/\s*(\d{2,4})/u', (string)$ova, $m)) {
        foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
            $x = Db::vse('SELECT * FROM ' . Psp::tab($tab) . ' WHERE ova_c = ?', [(int)$m[1]]);
            radek($tab . ' (ova_c = ' . (int)$m[1] . ')', $x ? json_encode($x, JSON_UNESCAPED_UNICODE) : 'nic');
        }
    } else {
        radek('čj OVA', 'materiál ho nemá nebo nejde rozpoznat', false);
    }
    if (!method_exists('Psp', 'tisky_podle_ova')) {
        return;
    }
    nadpis('Výsledek v aplikaci');
    try {
        $psp = new Psp();
        radek('pid_tisk', json_encode($psp->pid_tisk([$pid])));
        $t = $psp->tisk_k_materialu($pid);
        radek('tisk_k_materialu', $t ? sprintf('tisk %s (id %s)', $t->cislo(), $t->id_tisk()) : 'nic', (bool)$t);
        radek('tisky_podle_ova', json_encode($psp->tisky_podle_ova($ova)));
        radek('materialy_podle_ova', json_encode(array_keys(Psp::materialy_podle_ova($ova))));
    } catch (Throwable $e) {
        radek('chyba', get_class($e) . ': ' . $e->getMessage(), false);
    }
}

function diagnostika_parovani(string $pid, string $ident): void
{
    echo "Legislativní radar – párování materiálu ODOK s e-Legislativou (" . date('j. n. Y H:i:s') . ")\n";
    nadpis("Materiál $pid");
    $odok = new Odok(range((int)Konfig::get('odok_prvni_rok', 2016), (int)date('Y')));
    try {
        $m = $odok->material($pid);
    } catch (Throwable $e) {
        radek('materiál', 'nenalezen: ' . $e->getMessage(), false);
        return;
    }
    $druh = DRUH_PODLE_TYPU_ODOK[$m->typ_id ?? ''] ?? null;
    radek('název', (string)$m->nazev);
    radek('typ (typ_id)', sprintf('%s (%s) → druh v e-Legislativě: %s', $m->typ, $m->typ_id, $druh ?? 'žádný – název se neporovnává'), $druh !== null);
    radek('čj ÚV (OVA)', sprintf('%s → %s', $m->cj_ova ?? '—', norm_ova($m->cj_ova) ?? 'nelze použít'), norm_ova($m->cj_ova) !== null);
    radek('čj předkladatele', sprintf('%s → %s', $m->cislo_jednaci ?? '—', norm_cj($m->cislo_jednaci) ?? 'nelze použít'), norm_cj($m->cislo_jednaci) !== null);
    $cil = norm_nazev($m->nazev ?? '');
    radek('normalizovaný název', $cil);

    nadpis('Data e-Legislativy v databázi');
    $z = Db::radek("SELECT stazeno, stav, zprava FROM {p}zdroj WHERE klic = 'esel'");
    radek('import', $z ? sprintf('%s UTC · %s · %s', $z['stazeno'], $z['stav'], $z['zprava']) : 'zatím neproběhl', $z && $z['stav'] === 'ok');
    radek('návrhů celkem', (string)Db::hodnota('SELECT COUNT(*) FROM {p}esel_navrh'));
    $druhy = [];
    foreach (Db::vse('SELECT COALESCE(druh, "(bez druhu)") d, COUNT(*) n FROM {p}esel_navrh GROUP BY druh ORDER BY n DESC') as $r) {
        $druhy[] = "{$r['d']} {$r['n']}";
    }
    radek('podle druhu', implode(', ', $druhy));
    radek('nejvyšší identifikátor', (string)Db::hodnota('SELECT MAX(identifikator) FROM {p}esel_navrh'));

    nadpis('Výsledek párování');
    $vstup = [$m->typ_id, $m->cj_ova, $m->cislo_jednaci, $m->nazev];
    $klic = 'esel_par:' . ($z ? Db::hodnota("SELECT hash FROM {p}zdroj WHERE klic = 'esel'") : '') . ':' . json_encode($vstup, JSON_UNESCAPED_UNICODE);
    $ulozeno = kv_get($klic);
    radek('uloženo v cache', $ulozeno === null ? 'ne' : ($ulozeno ? 'návrh id ' . $ulozeno[0] . ' (' . $ulozeno[1] . ')' : 'nenalezeno (platí do dalšího importu e-Legislativy)'));
    $sp = new ReflectionMethod('Esel', 'spocitej');
    $sp->setAccessible(true);
    $v = $sp->invoke(new Esel(), $m, false);
    radek('nově spočítáno', $v ? sprintf('%s (%s, jistota %s)', $v[0]->identifikator, $v[1], $v[2]) : 'nenalezeno', (bool)$v);

    $ova = norm_ova($m->cj_ova);
    if ($ova) {
        $r = Db::vse('SELECT identifikator, druh, cj_ova FROM {p}esel_navrh WHERE norm_ova = ? ORDER BY poradi', [$ova]);
        radek('návrhy se stejným čj OVA', $r ? implode('; ', array_map(function ($x) { return "{$x['identifikator']} ({$x['druh']}, {$x['cj_ova']})"; }, $r)) : 'žádné');
    }
    $cj = norm_cj($m->cislo_jednaci);
    if ($cj) {
        $r = Db::vse('SELECT n.identifikator, n.druh FROM {p}esel_cj c JOIN {p}esel_navrh n ON n.id = c.navrh_id WHERE c.norm = ? ORDER BY c.poradi', [$cj]);
        radek('návrhy se stejným čj předkl.', $r ? implode('; ', array_map(function ($x) { return "{$x['identifikator']} ({$x['druh']})"; }, $r)) : 'žádné');
        $pref = explode('-', $cj)[0] . '-' . explode('/', explode('-', $cj, 2)[1])[0];
        $r = Db::vse('SELECT DISTINCT c.norm, n.identifikator FROM {p}esel_cj c JOIN {p}esel_navrh n ON n.id = c.navrh_id WHERE c.norm LIKE ? LIMIT 10', [$pref . '%']);
        if ($r) {
            radek('podobná čj (stejné číslo)', implode('; ', array_map(function ($x) { return "{$x['norm']} → {$x['identifikator']}"; }, $r)));
        }
    }

    if ($ident !== '') {
        nadpis("Návrh $ident v databázi radaru");
        $n = Db::radek('SELECT * FROM {p}esel_navrh WHERE identifikator = ? OR identifikator LIKE ? ORDER BY poradi LIMIT 1', [$ident, "%$ident%"]);
        if (!$n) {
            radek('návrh', 'V DATECH RADARU NENÍ – buď chybí v otevřených datech e-Legislativy, nebo ho import vynechal', false);
            radek('tip', 'ověřte surová data skriptem esel_navrh.py ' . $ident);
        } else {
            radek('id / identifikátor', $n['id'] . ' / ' . $n['identifikator']);
            radek('název', (string)$n['nazev']);
            radek('název aktu', (string)$n['nazev_aktu']);
            radek('druh', ($n['druh'] ?? '(prázdný)') . ($druh !== null ? ($n['druh'] === $druh ? ' = shoda' : " ≠ očekávaný $druh") : ''), $druh === null || $n['druh'] === $druh);
            radek('typ procesu', (string)$n['typ_procesu']);
            radek('čj OVA', sprintf('%s → %s', $n['cj_ova'] ?? '—', $n['norm_ova'] ?? '—'), $ova !== null && $n['norm_ova'] === $ova ? true : null);
            $cjn = Db::sloupec('SELECT norm FROM {p}esel_cj WHERE navrh_id = ? ORDER BY poradi', [$n['id']]);
            radek('čj předkladatele', ($n['cj_predkladatele'] ?: '—') . ' → ' . ($cjn ? implode(', ', $cjn) : 'žádné'), $cj !== null && in_array($cj, $cjn, true) ? true : null);
            radek('normalizovaný název', (string)$n['norm_nazev']);
            $sm = new SequenceMatcher($cil, (string)$n['norm_nazev']);
            $ratio = $sm->ratio();
            radek('podobnost názvu', sprintf('%.3f (práh %.2f)', $ratio, PRAH_NAZEV), $ratio >= PRAH_NAZEV);
        }
    }

    nadpis('Nejpodobnější názvy (všechny druhy)');
    $kandidati = [];
    $la = mb_strlen($cil);
    foreach (Db::vse('SELECT id, identifikator, druh, norm_nazev FROM {p}esel_navrh') as $r) {
        $b = (string)$r['norm_nazev'];
        if ($b === '' || SequenceMatcher::real_quick_ratio($la, mb_strlen($b)) < 0.6) {
            continue;
        }
        $sm = new SequenceMatcher($cil, $b);
        if ($sm->quick_ratio() < 0.6) {
            continue;
        }
        $kandidati[] = [$sm->ratio(), $r];
    }
    usort($kandidati, function ($a, $b) { return $b[0] <=> $a[0]; });
    foreach (array_slice($kandidati, 0, 8) as [$q, $r]) {
        radek(sprintf('%.3f  %s', $q, $r['identifikator']), sprintf('[%s] %s', $r['druh'] ?? 'bez druhu', mb_strimwidth((string)$r['norm_nazev'], 0, 110, '…')), $q >= PRAH_NAZEV);
    }
    if (!$kandidati) {
        radek('—', 'žádný návrh s podobností nad 0,6');
    }
}

function radek(string $co, $hodnota, ?bool $ok = null): void
{
    $znak = $ok === null ? '   ' : ($ok ? '[OK]' : '[!!]');
    $co = $co . str_repeat(' ', max(0, 34 - mb_strlen($co)));
    printf("%-5s %s %s\n", $znak, $co, is_bool($hodnota) ? ($hodnota ? 'ano' : 'ne') : (string)$hodnota);
    @flush();
}

function nadpis(string $t): void
{
    echo "\n== $t ==\n";
    @flush();
}

echo "Legislativní radar – diagnostika (" . date('j. n. Y H:i:s') . ", verze " . RADAR_VERZE . ")\n";

nadpis('PHP');
radek('verze', PHP_VERSION . ' (' . PHP_SAPI . ')', PHP_VERSION_ID >= 80000);
foreach (['pdo_mysql', 'zip', 'zlib', 'mbstring', 'dom', 'curl', 'json', 'session'] as $ext) {
    radek("rozšíření $ext", extension_loaded($ext), extension_loaded($ext) || $ext === 'curl');
}
radek('memory_limit', ini_get('memory_limit'));
radek('max_execution_time', ini_get('max_execution_time') . ' s');
radek('set_time_limit povoleno', function_exists('set_time_limit') && strpos((string)ini_get('disable_functions'), 'set_time_limit') === false);
radek('allow_url_fopen', (bool)ini_get('allow_url_fopen'));
radek('open_basedir', ini_get('open_basedir') ?: '(žádný)');
radek('disable_functions', ini_get('disable_functions') ?: '(žádné)');
radek('složka aplikace', RADAR_KOREN);
radek('zápis do data/', is_writable(RADAR_KOREN . '/data'), is_writable(RADAR_KOREN . '/data'));
radek('dočasný adresář', docasny_adresar() . (is_writable(docasny_adresar()) ? '' : ' (NEZAPISOVATELNÝ)'), is_writable(docasny_adresar()));

radek('zápis do pravidla/', is_writable(RADAR_KOREN . '/pravidla'), is_writable(RADAR_KOREN . '/pravidla'));
try {
    $sady = SadyPravidel::seznam();
    radek('sady výrazů', count($sady) . ' (' . implode(', ', array_column($sady, 'nazev')) . '), základní: ' . SadyPravidel::popis(SadyPravidel::vychozi()), (bool)$sady);
} catch (Throwable $e) {
    radek('sady výrazů', $e->getMessage(), false);
}
radek('registrace', Auth::rezimRegistrace());

nadpis('Relace (přihlášení)');
$cesta_relaci = session_save_path() ?: sys_get_temp_dir();
radek('session.save_path', $cesta_relaci);
radek('zapisovatelné', @is_writable($cesta_relaci), @is_writable($cesta_relaci) || is_dir(RADAR_KOREN . '/data/relace'));
if (is_dir(RADAR_KOREN . '/data/relace')) {
    radek('náhradní úložiště', RADAR_KOREN . '/data/relace (používá se)');
}

nadpis('Databáze');
try {
    $t = microtime(true);
    $pdo = Db::pripoj();
    radek('připojení', sprintf('%s, %.0f ms', $pdo->query('SELECT VERSION()')->fetchColumn(), (microtime(true) - $t) * 1000), true);
    foreach (['uzivatel', 'odok_material', 'esel_navrh', 'psp_tisky', 'psp_hist', 'http_cache', 'log'] as $tab) {
        try {
            radek("tabulka $tab", Db::hodnota('SELECT COUNT(*) FROM ' . Db::t($tab)) . ' řádků', true);
        } catch (PDOException $e) {
            radek("tabulka $tab", 'CHYBÍ – spusťte install.php?klic=… (aktualizace)', false);
        }
    }
    $procesy = Db::vse("SELECT ID, TIME, STATE, LEFT(INFO, 90) AS DOTAZ FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' AND ID <> CONNECTION_ID() AND USER = CURRENT_USER() ORDER BY TIME DESC LIMIT 10");
    radek('běžící dotazy', $procesy ? count($procesy) : 'žádné');
    foreach ($procesy as $p) {
        radek('', sprintf('%d s  %s  %s', $p['TIME'], $p['STATE'], $p['DOTAZ']), $p['TIME'] < 30);
    }
    $zamek = Db::hodnota('SELECT IS_USED_LOCK(?)', [Konfig::get('db.nazev') . '_radar_cron']);
    radek('import právě běží', $zamek ? "ano (spojení $zamek)" : 'ne');
} catch (Throwable $e) {
    radek('připojení', 'CHYBA: ' . $e->getMessage(), false);
    exit;
}

nadpis('Stav zdrojů dat');
try {
    $stav = Import::stavZdroju();
    foreach (array_merge(['cron' => ['popis' => 'poslední běh cronu']], Import::zdroje()) as $k => $info) {
        $s = $stav[$k] ?? null;
        radek($k, $s ? sprintf('%s | %s | %s', $s['stav'] ?: '-', $s['stazeno'] ? date('j. n. H:i', strtotime($s['stazeno'] . ' UTC')) : 'nestaženo',
            mb_substr((string)$s['zprava'], 0, 120)) : 'zatím nestaženo', $s ? $s['stav'] !== 'chyba' : null);
    }
} catch (Throwable $e) {
    radek('stav', 'CHYBA: ' . $e->getMessage(), false);
}

nadpis('Spojení na zdroje dat');
foreach (['https://www.odok.cz/', 'https://www.odok.gov.cz/', 'https://www.psp.cz/', 'https://opendata.eselpoint.gov.cz/datove-sady-elegislativa/'] as $url) {
    $t = microtime(true);
    try {
        Http::stahni($url, 15);
        radek(parse_url($url, PHP_URL_HOST), sprintf('OK, %.1f s', microtime(true) - $t), true);
    } catch (Throwable $e) {
        radek(parse_url($url, PHP_URL_HOST), sprintf('%s (%.1f s)', $e->getMessage(), microtime(true) - $t), $e instanceof SitovaChyba && $e->kod > 0);
    }
}

nadpis('Vykreslení stránek');
$_SESSION = [];
foreach (['stranka_uvod' => [], 'stranka_tisky' => [], 'stranka_schuze' => []] as $metoda => $par) {
    $t = microtime(true);
    $pamet = memory_get_usage();
    try {
        $html = (new Web())->$metoda($par);
        radek($metoda, sprintf('OK, %.2f s, %d kB, paměť +%.0f MB', microtime(true) - $t, strlen($html) / 1024,
            (memory_get_peak_usage() - $pamet) / 1048576), microtime(true) - $t < 10);
    } catch (OdokChyba $e) {
        radek($metoda, sprintf('%s (%.2f s)', $e->getMessage(), microtime(true) - $t), null);
    } catch (Throwable $e) {
        radek($metoda, sprintf('CHYBA: %s v %s:%d', $e->getMessage(), basename($e->getFile()), $e->getLine()), false);
    }
}

nadpis('Poslední chyby v logu aplikace');
try {
    $log = Db::vse("SELECT cas, uroven, zprava FROM {p}log ORDER BY id DESC LIMIT 15");
    if (!$log) {
        echo "   (žádné)\n";
    }
    foreach ($log as $r) {
        printf("   %s  %-8s %s\n", date('j. n. H:i', strtotime($r['cas'] . ' UTC')), $r['uroven'], mb_substr(str_replace("\n", ' ', $r['zprava']), 0, 300));
    }
} catch (Throwable $e) {
    echo '   ', $e->getMessage(), "\n";
}
printf("\nHotovo za %.1f s.\n", microtime(true) - $zacatek_skriptu);
