<?php
/**
 * Admin.php – jednoduchá správa: uživatelé, stav a obnova dat, seznam výrazů pro analýzu relevance, log.
 */

class Admin
{
    private $web;

    public function __construct(Web $web)
    {
        $this->web = $web;
    }

    private static function url(string $t, array $dalsi = []): string
    {
        return odkaz('/admin', array_merge(['t' => $t], $dalsi));
    }

    private static function cas($utc): string
    {
        return $utc ? date('j. n. Y H:i', strtotime($utc . ' UTC')) : '–';
    }

    private static function stari($utc): string
    {
        if (!$utc) {
            return '';
        }
        $m = (int)((time() - strtotime($utc . ' UTC')) / 60);
        if ($m < 1) {
            return 'právě teď';
        }
        if ($m < 60) {
            return "před $m min";
        }
        $h = intdiv($m, 60);
        return $h < 48 ? sprintf('před %d h %d min', $h, $m % 60) : sprintf('před %d dny', intdiv($h, 24));
    }

    private function formular(string $akce, string $obsah, string $t, string $trida = 'formular', string $potvrzeni = ''): string
    {
        return sprintf("<form method='post' class='%s' action='%s'%s><input type='hidden' name='csrf' value='%s'><input type='hidden' name='akce' value='%s'>%s</form>",
            $trida, e(self::url($t)), $potvrzeni !== '' ? " onsubmit=\"return confirm('" . e($potvrzeni) . "')\"" : '', e(Auth::csrf()), e($akce), $obsah);
    }

    public function obsluz(array $parametry): string
    {
        $t = $parametry['t'] ?? 'uzivatele';
        $vystup_importu = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Auth::overCsrf($_POST['csrf'] ?? null)) {
                Auth::zprava('Formulář vypršel, zkuste to znovu.', 'chyba');
            } else {
                try {
                    $vystup_importu = $this->akce((string)($_POST['akce'] ?? ''));
                } catch (Presmeruj $r) {
                    throw $r;
                } catch (Throwable $e) {
                    Auth::zprava($e->getMessage(), 'chyba');
                }
            }
            if ($vystup_importu === '') {
                throw new Presmeruj(self::url($t, isset($_POST['id']) && ($_POST['akce'] ?? '') === 'upravit' && !empty($_SESSION['zpravy'])
                    && end($_SESSION['zpravy'])[0] === 'chyba' ? ['upravit' => $_POST['id']] : []));
            }
        }
        if ($t === 'pravidla' && ($parametry['stahnout'] ?? '') !== '') {
            $soubor = $parametry['stahnout'];
            $cesta = $soubor === 'vzor' ? RADAR_KOREN . '/relevance_pravidla_vzor.xlsx' : SadyPravidel::cesta($soubor);
            $jmeno = $soubor === 'vzor' ? 'relevance_pravidla_vzor.xlsx' : $soubor;
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $jmeno . '"');
            header('Content-Length: ' . filesize($cesta));
            readfile($cesta);
            exit;
        }
        $menu = '';
        foreach (['uzivatele' => 'Uživatelé', 'data' => 'Data a import', 'pravidla' => 'Sady výrazů', 'log' => 'Log'] as $k => $n) {
            $menu .= sprintf("<a class='tlacitko%s' href='%s'>%s</a>", $k === $t ? ' aktivni' : '', e(self::url($k)), $n);
        }
        switch ($t) {
            case 'data': $obsah = $this->data($vystup_importu); break;
            case 'pravidla': $obsah = $this->pravidla(); break;
            case 'log': $obsah = $this->log(); break;
            default: $t = 'uzivatele'; $obsah = $this->uzivatele($parametry);
        }
        return $this->web->stranka('Správa', "<h1>Správa</h1><div class='lista admin-menu'>$menu</div>$obsah", 'admin');
    }

    private function akce(string $akce): string
    {
        $ja = Auth::uzivatel();
        switch ($akce) {
            case 'pridat':
                $login = trim((string)$_POST['login']);
                if (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $login)) {
                    throw new RuntimeException('Přihlašovací jméno smí obsahovat jen písmena bez diakritiky, číslice a znaky . _ @ - (2–64 znaků).');
                }
                if (Db::hodnota('SELECT id FROM {p}uzivatel WHERE login = ?', [$login])) {
                    throw new RuntimeException("Uživatel $login už existuje.");
                }
                if ($ch = Auth::kontrolaHesla((string)$_POST['heslo'])) {
                    throw new RuntimeException($ch);
                }
                Db::q('INSERT INTO {p}uzivatel (login, jmeno, email, heslo, role, aktivni, vytvoreno) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())',
                    [$login, trim((string)$_POST['jmeno']), trim((string)$_POST['email']), password_hash((string)$_POST['heslo'], PASSWORD_DEFAULT),
                        ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'uzivatel']);
                Auth::zprava("Uživatel $login byl přidán.");
                return '';
            case 'upravit':
                $id = (int)$_POST['id'];
                $u = Db::radek('SELECT * FROM {p}uzivatel WHERE id = ?', [$id]);
                if (!$u) {
                    throw new RuntimeException('Uživatel nenalezen.');
                }
                $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'uzivatel';
                $aktivni = !empty($_POST['aktivni']) ? 1 : 0;
                if ($id === (int)$ja['id'] && ($role !== 'admin' || !$aktivni)) {
                    throw new RuntimeException('Sami sobě nemůžete odebrat roli správce ani zablokovat účet.');
                }
                if ($u['role'] === 'admin' && ($role !== 'admin' || !$aktivni) && $this->pocetAdminu() <= 1) {
                    throw new RuntimeException('Musí zůstat aspoň jeden aktivní správce.');
                }
                $sada = (string)($_POST['pravidla'] ?? '');
                Db::q('UPDATE {p}uzivatel SET jmeno = ?, email = ?, role = ?, aktivni = ?, pravidla = ? WHERE id = ?',
                    [trim((string)$_POST['jmeno']), trim((string)$_POST['email']), $role, $aktivni, SadyPravidel::existuje($sada) ? $sada : null, $id]);
                if ((string)($_POST['heslo'] ?? '') !== '') {
                    if ($ch = Auth::kontrolaHesla((string)$_POST['heslo'])) {
                        throw new RuntimeException($ch);
                    }
                    Db::q('UPDATE {p}uzivatel SET heslo = ? WHERE id = ?', [password_hash((string)$_POST['heslo'], PASSWORD_DEFAULT), $id]);
                    if ($id === (int)$ja['id']) {
                        Auth::obnovOtisk();
                    }
                }
                Auth::zprava("Uživatel {$u['login']} byl uložen.");
                return '';
            case 'smazat':
                $id = (int)$_POST['id'];
                if ($id === (int)$ja['id']) {
                    throw new RuntimeException('Sami sebe smazat nemůžete.');
                }
                $u = Db::radek('SELECT * FROM {p}uzivatel WHERE id = ?', [$id]);
                if ($u && $u['role'] === 'admin' && $this->pocetAdminu() <= 1) {
                    throw new RuntimeException('Musí zůstat aspoň jeden aktivní správce.');
                }
                Db::q('DELETE FROM {p}oblibene WHERE uzivatel_id = ?', [$id]);
                Db::q('DELETE FROM {p}uzivatel WHERE id = ?', [$id]);
                Auth::zrusTrvaleUzivatele($id);
                Auth::zprava('Uživatel ' . ($u['login'] ?? '') . ' byl smazán.');
                return '';
            case 'vynutit':
                $klic = (string)($_POST['zdroj'] ?? '');
                $zdroje = Import::zdroje();
                foreach ($klic === '' ? array_keys($zdroje) : [$klic] as $k) {
                    if (isset($zdroje[$k])) {
                        Db::q('INSERT INTO {p}zdroj (klic, popis, vynutit) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE vynutit = 1', [$k, $zdroje[$k]['popis']]);
                    }
                }
                Auth::zprava($klic === '' ? 'Všechny zdroje se stáhnou znovu při nejbližším běhu importu.' : "Zdroj $klic se stáhne znovu při nejbližším běhu importu.");
                return '';
            case 'spustit':
                Auth::uvolni();      // import trvá – ať neblokuje ostatní stránky
                @set_time_limit(600);
                ignore_user_abort(true);
                $radky = [];
                $rozpocet = max(5, min(600, (int)($_POST['rozpocet'] ?? 25)));
                $imp = new Import($rozpocet, function ($t) use (&$radky) { $radky[] = date('H:i:s ') . $t; });
                $jen = ($_POST['zdroj'] ?? '') !== '' ? [(string)$_POST['zdroj']] : null;
                $imp->spust($jen !== null, $jen);
                if (!$imp->vysledky && !$radky) {
                    $radky[] = 'Nic k obnově – všechna data jsou čerstvá.';
                }
                return implode("\n", $radky) ?: 'Nic k obnově – všechna data jsou čerstvá.';
            case 'smazat_cache':
                $n = Db::q("DELETE FROM {p}http_cache")->rowCount();
                Db::q('DELETE FROM {p}kv_cache');
                Auth::zprava("Cache stažených souborů vymazána ($n položek).");
                return '';
            case 'schvalit':
                $u = Db::radek('SELECT * FROM {p}uzivatel WHERE id = ?', [(int)$_POST['id']]);
                if ($u) {
                    Db::q('UPDATE {p}uzivatel SET aktivni = 1 WHERE id = ?', [$u['id']]);
                    Auth::zprava("Účet {$u['login']} byl schválen – uživatel se může přihlásit.");
                }
                return '';
            case 'registrace':
                $r = (string)($_POST['rezim'] ?? '');
                if (in_array($r, ['otevrena', 'schvaleni', 'vypnuta'], true)) {
                    nastav('registrace', $r);
                    Auth::zprava('Nastavení registrace bylo uloženo.');
                }
                return '';
            case 'nahrat_pravidla':
                if (empty($_FILES['soubor']['tmp_name']) || !is_uploaded_file($_FILES['soubor']['tmp_name'])) {
                    throw new RuntimeException('Vyberte soubor .xlsx.');
                }
                $nazev = trim((string)($_POST['nazev'] ?? '')) ?: (string)$_FILES['soubor']['name'];
                $soubor = SadyPravidel::uloz($nazev, (string)file_get_contents($_FILES['soubor']['tmp_name']), !empty($_POST['prepsat']));
                Auth::zprava('Sada „' . SadyPravidel::popis($soubor) . '“ byla nahrána.');
                return '';
            case 'vychozi_pravidla':
                $soubor = SadyPravidel::cesta((string)$_POST['soubor']) ? (string)$_POST['soubor'] : '';
                nastav('pravidla_vychozi', $soubor);
                Auth::zprava('Sada „' . SadyPravidel::popis($soubor) . '“ je teď základní.');
                return '';
            case 'smazat_pravidla':
                SadyPravidel::smaz((string)$_POST['soubor']);
                Auth::zprava('Sada „' . SadyPravidel::popis((string)$_POST['soubor']) . '“ byla smazána; uživatelé, kteří ji používali, mají základní.');
                return '';
            case 'smazat_log':
                Db::q('DELETE FROM {p}log');
                Auth::zprava('Log byl vymazán.');
                return '';
        }
        throw new RuntimeException('Neznámá akce.');
    }

    private function pocetAdminu(): int
    {
        return (int)Db::hodnota("SELECT COUNT(*) FROM {p}uzivatel WHERE role = 'admin' AND aktivni = 1");
    }

    private function uzivatele(array $parametry): string
    {
        $radky = '';
        $ja = Auth::uid();
        $cekaji = 0;
        foreach (Db::vse('SELECT * FROM {p}uzivatel ORDER BY aktivni, login') as $u) {
            $id = (int)$u['id'];
            $ceka = !(int)$u['aktivni'] && $u['posledni_prihlaseni'] === null;
            $cekaji += $ceka ? 1 : 0;
            $akce = $ceka ? $this->formular('schvalit', "<input type='hidden' name='id' value='$id'><button class='tlacitko-mini'>Schválit</button>", 'uzivatele', '') . ' ' : '';
            $akce .= sprintf("<a class='tlacitko-mini tlacitko' href='%s'>Upravit</a>", e(self::url('uzivatele', ['upravit' => $id])));
            if ($id !== $ja) {
                $akce .= ' ' . $this->formular('smazat', "<input type='hidden' name='id' value='$id'><button class='tlacitko-mini'>Smazat</button>",
                    'uzivatele', '', 'Opravdu smazat uživatele ' . $u['login'] . '?');
            }
            $stav = (int)$u['aktivni'] ? 'aktivní' : ($ceka ? "<span class='stav-stary'>čeká na schválení</span>" : "<span class='stav-chyba'>zablokovaný</span>");
            $radky .= sprintf("<tr><td><b>%s</b></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td data-razeni='%s'>%s</td><td data-razeni='%s'>%s</td><td style='white-space:nowrap'>%s</td></tr>",
                e($u['login']), e($u['jmeno']), e($u['email']), $u['role'] === 'admin' ? 'správce' : 'uživatel', $stav,
                SadyPravidel::existuje($u['pravidla']) ? e(SadyPravidel::popis($u['pravidla'])) : "<span class='tlumene'>základní</span>",
                e($u['vytvoreno']), e(self::cas($u['vytvoreno'])), e($u['posledni_prihlaseni']), e(self::cas($u['posledni_prihlaseni'])), $akce);
        }
        $rezim = Auth::rezimRegistrace();
        $volby = '';
        foreach (['otevrena' => 'otevřená – kdokoli si založí účet a hned ho může používat',
                     'schvaleni' => 'se schválením – nový účet musí schválit správce',
                     'vypnuta' => 'vypnutá – účty zakládá jen správce'] as $k => $popis) {
            $volby .= sprintf("<option value='%s'%s>%s</option>", $k, $k === $rezim ? ' selected' : '', e($popis));
        }
        $html = '<h2>Registrace</h2>' . $this->formular('registrace', "<label>Registrace nových uživatelů <select name='rezim'>$volby</select></label> <button>Uložit</button>", 'uzivatele', 'lista');
        $html .= sprintf("<h2>Uživatelé%s</h2><div class='posun'><table class='data raditelna'><thead><tr><th>Přihlašovací jméno</th><th>Jméno</th><th>E-mail</th><th>Role</th><th>Stav</th><th>Sada výrazů</th><th>Registrován</th><th>Naposledy přihlášen</th><th></th></tr></thead><tbody>%s</tbody></table></div>",
            $cekaji ? " <span class='stitek'>$cekaji čeká na schválení</span>" : '', $radky);
        $upravit = isset($parametry['upravit']) ? Db::radek('SELECT * FROM {p}uzivatel WHERE id = ?', [(int)$parametry['upravit']]) : null;
        $role = function ($vybrana) {
            return sprintf("<select name='role'><option value='uzivatel'%s>uživatel</option><option value='admin'%s>správce</option></select>",
                $vybrana === 'admin' ? '' : ' selected', $vybrana === 'admin' ? ' selected' : '');
        };
        if ($upravit) {
            try {
                $sady = '<label>Sada výrazů pro analýzu relevance ' . SadyPravidel::vyber('pravidla', $upravit['pravidla']) . '</label>';
            } catch (Throwable $e) {
                $sady = '';
            }
            $html .= '<h2>Upravit uživatele ' . e($upravit['login']) . '</h2>' . $this->formular('upravit', sprintf(
                "<input type='hidden' name='id' value='%d'><label>Jméno <input type='text' name='jmeno' value='%s'></label>
<label>E-mail <input type='email' name='email' value='%s'></label><label>Role %s</label>%s
<label class='zaskrt'><input type='checkbox' name='aktivni' value='1'%s> účet je aktivní (schválený, nezablokovaný)</label>
<label>Nové heslo (prázdné = beze změny) <input type='password' name='heslo' autocomplete='new-password'></label>
<div class='radek'><button>Uložit</button> <a class='tlacitko' href='%s'>Zrušit</a></div>",
                (int)$upravit['id'], e($upravit['jmeno']), e($upravit['email']), $role($upravit['role']), $sady, (int)$upravit['aktivni'] ? ' checked' : '',
                e(self::url('uzivatele'))), 'uzivatele', 'formular karta');
        }
        $html .= '<h2>Přidat uživatele</h2>' . $this->formular('pridat', "<label>Přihlašovací jméno <input type='text' name='login' required pattern='[A-Za-z0-9._@\\-]{2,64}'></label>
<label>Jméno <input type='text' name='jmeno'></label><label>E-mail <input type='email' name='email'></label>
<label>Role " . $role('uzivatel') . "</label>
<label>Heslo (aspoň 8 znaků) <input type='password' name='heslo' required minlength='8' autocomplete='new-password'></label>
<button>Přidat</button>", 'uzivatele', 'formular karta');
        return $html;
    }

    private function data(string $vystup): string
    {
        $stav = Import::stavZdroju();
        $radky = '';
        foreach (Import::zdroje() as $k => $info) {
            $s = $stav[$k] ?? null;
            $prosly = Import::jeProsly($s, $info['platnost'], $info['uzavreni'] ?? null);
            $trida = !$s ? '' : ($s['stav'] === 'chyba' ? 'stav-chyba' : ($prosly ? 'stav-stary' : 'stav-ok'));
            $tlacitka = $this->formular('vynutit', "<input type='hidden' name='zdroj' value='" . e($k) . "'><button class='tlacitko-mini' title='Stáhnout znovu při nejbližším běhu importu'>Obnovit</button>", 'data', '')
                . ' ' . $this->formular('spustit', "<input type='hidden' name='zdroj' value='" . e($k) . "'><button class='tlacitko-mini' title='Naimportovat hned (je-li zdroj na řadě nebo označený k obnově)'>Teď</button>", 'data', '');
            $radky .= sprintf("<tr><td><b>%s</b><div class='tlumene'>%s</div></td><td class='%s'>%s%s</td><td>%s<div class='tlumene'>%s</div></td><td>%s</td><td>%s</td><td>%s</td><td style='white-space:nowrap'>%s</td></tr>",
                e($info['popis']), e($k), $trida, e($s['stav'] ?? 'zatím nestaženo'), ($s && (int)$s['vynutit']) ? ' · <i>čeká na obnovu</i>' : '',
                e(self::cas($s['stazeno'] ?? null)), e(self::stari($s['stazeno'] ?? null)), e(Import::popisPlatnosti($info['platnost'], !empty($info['jednou_denne']))),
                e($s && $s['radku'] !== null ? (string)$s['radku'] : ''), e($s['zprava'] ?? ''), $tlacitka);
        }
        $cron = $stav['cron']['stazeno'] ?? null;
        $php = (PHP_BINARY && stripos(PHP_BINARY, 'fpm') === false && stripos(PHP_BINARY, 'cgi') === false) ? PHP_BINARY : 'php';
        $skript = RADAR_KOREN . '/cron.php';
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = sprintf('%s://%s%scron.php?klic=%s', $https, $_SERVER['HTTP_HOST'] ?? 'vas-web.cz', Auth::cesta(), rawurlencode((string)Konfig::get('cron_klic')));
        $pocty = Db::vse('SELECT rok, COUNT(*) AS n FROM {p}odok_material GROUP BY rok ORDER BY rok DESC');
        $psp = Psp::maData() ? (int)Db::hodnota('SELECT COUNT(*) FROM ' . Psp::tab('tisky')) : 0;
        $esel = Esel::maData() ? Esel::pocet() : 0;
        $cache = Db::radek('SELECT COUNT(*) AS n, COALESCE(SUM(velikost), 0) AS b FROM {p}http_cache');
        $html = sprintf("<h2>Zdroje dat</h2>
<p class='tlumene'>Data plní skript <code>cron.php</code>. Každý zdroj se stáhne znovu po uplynutí platnosti; nezměněný soubor se znovu neimportuje.
Poslední běh importu: <b>%s</b> %s.</p>
<div class='posun'><table class='data'><thead><tr><th>Zdroj</th><th>Stav</th><th>Staženo</th><th>Platnost</th><th>Řádků</th><th>Zpráva</th><th></th></tr></thead><tbody>%s</tbody></table></div>
<div class='lista'>%s %s %s</div>%s",
            e(self::cas($cron)), $cron ? '(' . e(self::stari($cron)) . ')' : "– <span class='stav-chyba'>cron zatím neběžel</span>", $radky,
            $this->formular('vynutit', "<input type='hidden' name='zdroj' value=''><button>Obnovit vše při příštím běhu</button>", 'data', ''),
            $this->formular('spustit', "<label>Spustit import teď, nejvýš <select name='rozpocet'><option>25</option><option>60</option><option>120</option><option>300</option></select> s</label> <button>Spustit</button>", 'data', 'lista'),
            $this->formular('smazat_cache', '<button>Vymazat cache stažených souborů</button>', 'data', '', 'Vymazat cache verzí, připomínek a dokumentů?'),
            $vystup !== '' ? "<h3>Výstup importu</h3><pre class='kod'>" . e($vystup) . '</pre>' : '');
        $html .= sprintf("<h2>Nastavení cronu</h2>
<p>Doporučeno spouštět každých 15 minut (zdroje se stahují jen po uplynutí platnosti). Z příkazové řádky na serveru:</p>
<pre class='kod'>*/15 * * * * %s %s</pre>
<p>Pokud hosting umí cron jen jako volání adresy (běží s časovým rozpočtem 25 s, zbytek se doplní při dalších voláních):</p>
<pre class='kod'>%s</pre>
<p class='tlumene'>První naplnění (všechny ročníky ODOK, Sněmovna, e-Legislativa) může trvat několik minut – při volání přes adresu se rozloží do několika běhů.
Ruční spuštění z příkazové řádky: <code>php cron.php --vse</code>, stav: <code>php cron.php --stav</code>.</p>", e($php), e($skript), e($url));
        $html .= sprintf("<h2>Obsah databáze</h2><table class='kv'><tr><th>Materiály ODOK</th><td>%s</td></tr><tr><th>Sněmovní tisky</th><td>%d</td></tr><tr><th>Návrhy v e-Legislativě</th><td>%d</td></tr><tr><th>Cache stažených souborů</th><td>%d souborů, %.1f MB</td></tr><tr><th>Uživatelé / oblíbené</th><td>%d / %d</td></tr><tr><th>Verze aplikace</th><td>%s (PHP %s)</td></tr></table>",
            $pocty ? e(implode(', ', array_map(function ($r) { return $r['rok'] . ': ' . $r['n']; }, $pocty))) : 'žádné', $psp, $esel,
            (int)$cache['n'], (int)$cache['b'] / 1048576, (int)Db::hodnota('SELECT COUNT(*) FROM {p}uzivatel'), (int)Db::hodnota('SELECT COUNT(*) FROM {p}oblibene'),
            RADAR_VERZE, PHP_VERSION);
        return $html;
    }

    private function pravidla(): string
    {
        $chyba_slozky = '';
        try {
            $sady = SadyPravidel::seznam();
            $vychozi = $sady ? SadyPravidel::vychozi() : null;
        } catch (Throwable $e) {
            $sady = [];
            $vychozi = null;
            $chyba_slozky = "<div class='chyba'>" . e($e->getMessage()) . '</div>';
        }
        $pocty = SadyPravidel::pocty_uzivatelu();
        $radky = '';
        foreach ($sady as $s => $info) {
            try {
                $r = (new Relevance($s))->nacti();
                $obsah = sprintf('%d výrazů · útvary: %s%s', count($r->pravidla), e(implode(', ', $r->utvary)),
                    $r->chyby ? sprintf(" <span class='stav-chyba' title='%s'>· %d chyb</span>", e(implode("\n", $r->chyby)), count($r->chyby)) : '');
            } catch (Throwable $e) {
                $obsah = "<span class='stav-chyba'>" . e($e->getMessage()) . '</span>';
            }
            $akce = sprintf("<a class='tlacitko tlacitko-mini' href='%s'>Stáhnout</a>", e(self::url('pravidla', ['stahnout' => $s])));
            if ($s !== $vychozi) {
                $akce .= ' ' . $this->formular('vychozi_pravidla', "<input type='hidden' name='soubor' value='" . e($s) . "'><button class='tlacitko-mini'>Nastavit jako základní</button>", 'pravidla', '')
                    . ' ' . $this->formular('smazat_pravidla', "<input type='hidden' name='soubor' value='" . e($s) . "'><button class='tlacitko-mini'>Smazat</button>",
                        'pravidla', '', 'Smazat sadu ' . $info['nazev'] . '?');
            }
            $radky .= sprintf("<tr><td><b>%s</b>%s<div class='tlumene'>%s</div></td><td>%s</td><td data-razeni='%d'>%s</td><td>%d</td><td style='white-space:nowrap'>%s</td></tr>",
                e($info['nazev']), $s === $vychozi ? " <span class='stitek'>základní</span>" : '', e($s), $obsah, $info['zmeneno'],
                e(date('j. n. Y H:i', $info['zmeneno'])), $pocty[$s] ?? 0, $akce);
        }
        return sprintf("<h2>Sady výrazů pro analýzu relevance</h2>
<p>U dokumentu (Word .docx/.doc i PDF) se hledají regulární výrazy z prvního listu sešitu a pro každý útvar se spočítá skóre
(počet výskytů × váha). Sad může být víc: každý přihlášený uživatel si v profilu (nebo přímo u výsledku analýzy) vybere svou,
nepřihlášení a uživatelé bez volby mají <b>základní</b>. Sady jsou soubory .xlsx ve složce <code>pravidla/</code> –
lze je tam nahrát i přímo přes FTP.</p>
%s<div class='posun'><table class='data'><thead><tr><th>Sada</th><th>Obsah</th><th>Změněno</th><th>Uživatelů</th><th></th></tr></thead><tbody>%s</tbody></table></div>
<p class='tlumene'>Uživatelů = kolik uživatelů si sadu výslovně vybralo (ostatní používají základní).</p>
<h3>Nahrát sadu</h3>
<form method='post' enctype='multipart/form-data' class='formular karta' action='%s'><input type='hidden' name='csrf' value='%s'><input type='hidden' name='akce' value='nahrat_pravidla'>
<label>Soubor .xlsx <input type='file' name='soubor' accept='.xlsx' required></label>
<label>Název sady (prázdné = podle názvu souboru; stejný název = nahrazení) <input type='text' name='nazev' placeholder='např. Odbor 31'></label>
<label class='zaskrt'><input type='checkbox' name='prepsat' value='1'> přepsat existující sadu se stejným názvem</label>
<button>Nahrát</button></form>
<p class='tlumene'>Formát: sloupce <b>Příklad</b> (jen pro lidi), <b>Regulární výraz</b> (bez ohledu na velikost písmen),
<b>sloupce útvarů</b> (0 / 1, vyšší váha, nebo ano / x / vysoká / střední / nízká), <b>Poznámka</b>; řádek s výrazem začínajícím <code>#</code>
se přeskočí. <a href='%s'>Stáhnout vzor</a>.</p>", $chyba_slozky, $radky ?: "<tr><td colspan='5' class='tlumene'>Žádné sady.</td></tr>",
            e(self::url('pravidla')), e(Auth::csrf()), e(self::url('pravidla', ['stahnout' => 'vzor'])));
    }

    private function log(): string
    {
        $radky = '';
        foreach (Db::vse('SELECT * FROM {p}log ORDER BY id DESC LIMIT 300') as $r) {
            $radky .= sprintf("<tr><td data-razeni='%s'>%s</td><td class='%s'>%s</td><td><div class='text-pripominky'>%s</div></td></tr>",
                e($r['cas']), e(self::cas($r['cas'])), $r['uroven'] === 'chyba' ? 'stav-chyba' : '', e($r['uroven']), e($r['zprava']));
        }
        return "<h2>Log</h2><p class='tlumene'>Chyby importu a aplikace (posledních 300 záznamů, starší než 90 dní se mažou).</p>"
            . ($radky ? "<div class='posun'><table class='data raditelna'><thead><tr><th>Čas</th><th>Úroveň</th><th>Zpráva</th></tr></thead><tbody>$radky</tbody></table></div>"
                : "<p class='tlumene'>Log je prázdný.</p>")
            . $this->formular('smazat_log', '<button>Vymazat log</button>', 'log', 'lista', 'Vymazat log?');
    }
}
