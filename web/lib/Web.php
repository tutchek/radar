<?php
/**
 * Web.php – stránky Legislativního radaru. Port třídy OdokServer z odok_web.py
 * (místo vlastního HTTP serveru obsluhuje požadavky index.php; data čte z databáze).
 *
 * Stránky (index.php?p=…):
 *   (prázdné)       přehled předkladatelů          ministerstvo&z=MZD   materiály předkladatele
 *   hledej&q=…      PID / čj → detail, jinak fulltext   material&id=…    detail materiálu
 *   cast&typ=…      části načítané dodatečně (verze, připomínky, psp, esel, relevance, nahled, plpv)
 *   tisky, tisk&id=…, schuze[&c=…], plpv, oblibene, nastav, obnov
 *   prihlaseni, odhlaseni, profil, admin (Admin.php)
 */

require_once __DIR__ . '/WebPsp.php';
require_once __DIR__ . '/WebNastenka.php';

const ZNAMA_OBDOBI = [10 => [2025, null], 9 => [2021, 2025], 8 => [2017, 2021], 7 => [2013, 2017]];
const ZACATKY_OBDOBI = [10 => '2025-10-04', 9 => '2021-10-09', 8 => '2017-10-21', 7 => '2013-10-26', 6 => '2010-05-29'];
const URL_SBIRKA = 'https://www.e-sbirka.cz/sb/{rok}/{cislo}';
const MAX_VYSLEDKU = 1000;
const HVEZDA = '⭐', HVEZDA_PRAZDNA = '☆';
const POPISKY = [
    'pid' => 'PID', 'cislo_jednaci' => 'Číslo jednací', 'nazev' => 'Název',
    'predkladatel' => 'Předkladatel', 'predkladatel_zkratka' => 'Zkratka předkladatele',
    'typ' => 'Typ', 'stav' => 'Stav', 'datum' => 'Datum', 'url_web' => 'Stránka v ODOK',
    'cj_ova' => 'Čj ÚV (OVA)', 'tisk_psp' => 'Sněmovní tisk (dle ODOK)',
];

class Presmeruj extends Exception
{
    public $kam, $cookies;

    public function __construct(string $kam, array $cookies = [])
    {
        parent::__construct($kam);
        $this->kam = $kam;
        $this->cookies = $cookies;
    }
}

function prvni_rok_odok(): int
{
    return (int)Konfig::get('odok_prvni_rok', 2016);
}

/** Ročníky ODOK, které pokrývají volební období (rok od–do, do=null → dosud). */
function roky_obdobi(int $od, ?int $do): array
{
    $dnes = (int)date('Y');
    $a = max($od, prvni_rok_odok());
    $b = min($do ?? $dnes, $dnes);
    return $a <= $b ? range($a, $b) : [$dnes];
}

function fmt_cas_odok($h): string
{
    $d = is_string($h) ? parsuj_cas_odok($h) : null;
    return $d ? fmt_datum(den($d)) : (py_bool($h) ? py_str($h) : '');
}

/** Obecné zobrazení libovolného JSONu jako HTML. */
function json_na_html($data, int $hloubka = 0): string
{
    if ($hloubka > 8) {
        return '<code>…</code>';
    }
    if (is_array($data) && $data !== [] && !array_is_list($data)) {
        if ($hloubka === 0 && count($data) === 1) {
            $k = array_key_first($data);
            if (is_array($data[$k])) {
                return "<p class='tlumene'>" . e($k) . '</p>' . json_na_html($data[$k], 0);
            }
        }
        $r = '';
        foreach ($data as $k => $v) {
            $r .= '<tr><th>' . e($k) . '</th><td>' . json_na_html($v, $hloubka + 1) . '</td></tr>';
        }
        return '<table class="kv">' . $r . '</table>';
    }
    if (is_array($data)) {
        if (!$data) {
            return '<span class="tlumene">[]</span>';
        }
        $vse_dict = true;
        foreach ($data as $x) {
            if (!(is_array($x) && ($x === [] || !array_is_list($x)))) {
                $vse_dict = false;
                break;
            }
        }
        if ($vse_dict) {
            $sl = [];
            foreach ($data as $x) {
                foreach ($x as $k => $_) {
                    $sl[(string)$k] = true;
                }
            }
            $sl = array_keys($sl);
            $hl = implode('', array_map(function ($k) { return '<th>' . e($k) . '</th>'; }, $sl));
            $telo = '';
            foreach ($data as $x) {
                $telo .= '<tr>';
                foreach ($sl as $k) {
                    $telo .= '<td>' . (array_key_exists($k, $x) ? json_na_html($x[$k], $hloubka + 1) : '') . '</td>';
                }
                $telo .= '</tr>';
            }
            $tab = '<div class="posun"><table class="data"><thead><tr>' . $hl . '</tr></thead><tbody>' . $telo . '</tbody></table></div>';
            if ($hloubka > 0) {
                return sprintf('<details><summary>%d položek</summary>%s</details>', count($data), $tab);
            }
            return $tab;
        }
        return '<ul>' . implode('', array_map(function ($x) use ($hloubka) { return '<li>' . json_na_html($x, $hloubka + 1) . '</li>'; }, $data)) . '</ul>';
    }
    if (is_string($data) && preg_match('~^https?://~', $data)) {
        return '<a href="' . e($data) . '" target="_blank" rel="noopener">' . e($data) . '</a>';
    }
    if (is_bool($data)) {
        return $data ? 'ano' : 'ne';
    }
    if ($data === null) {
        return '<span class="tlumene">–</span>';
    }
    return e(py_str($data));
}

function odkazy_esel($n, $text = null, bool $kratce = false): string
{
    if ($n === null || $n->id === null) {
        return $text !== null ? e($text) : '';
    }
    $url = $n->url_elegislativa ?: $n->url;
    $h = sprintf("<a href='%s' target='_blank' rel='noopener' title='Otevřít v e-Legislativě'>%s ↗</a>", e($url), e($text !== null ? $text : 'e-Legislativa'));
    if ($n->url_elegislativa && $n->url) {
        $h .= sprintf(" <a class='tlumene' href='%s' target='_blank' rel='noopener' title='Veřejný portál e-Legislativy'>%s</a>",
            e($n->url), $kratce ? '(veř.)' : '(veřejný portál)');
    }
    return $h;
}

/** '526/1990 Sb.' → odkaz do e-Sbírky; jiný text jen escapovaný. */
function odkaz_sbirka($text): string
{
    if (!py_bool($text)) {
        return '';
    }
    $t = (string)$text;
    if (!preg_match('/(\d+)\s*\/\s*(\d{4})\s*Sb\.?/', $t, $m, PREG_OFFSET_CAPTURE)) {
        return e($t);
    }
    $url = vzor(URL_SBIRKA, ['rok' => $m[2][0], 'cislo' => $m[1][0]]);
    return sprintf("%s<a href='%s' target='_blank' rel='noopener' title='e-Sbírka'>%s ↗</a>%s",
        e(substr($t, 0, $m[0][1])), e($url), e($m[0][0]), e(substr($t, $m[0][1] + strlen($m[0][0]))));
}

class Web
{
    use WebPsp;
    use WebNastenka;

    /** @var Oblibene */
    public $oblibene;
    private $odok_instance = [];
    private $psp_data = null;
    private $psp_pohledy = [];
    private $esel = null;
    private $kontext = ['rok' => null, 'obdobi' => null, 'cesta' => 'index.php'];
    private $cesta_stranky = '';
    protected $poznamky_cache = null;
    protected $novely_cache = null;

    public function __construct()
    {
        $this->oblibene = new Oblibene(Auth::uid());
    }

    // ---------------- obsluha požadavku ----------------

    public function obsluz(): void
    {
        $p = trim((string)($_GET['p'] ?? ''), '/');
        $this->cesta_stranky = $p;
        $this->kontext['rok'] = $_COOKIE['odok_rok'] ?? null;
        $this->kontext['obdobi'] = $_COOKIE['ps_obdobi'] ?? null;
        // období v adrese (odkaz s hledáním poslaný někomu jinému) má přednost před uloženou volbou, nic neukládá
        if (preg_match('/^(?:(\d{1,2})(?::(\d{4}))?|(vse))$/', (string)($_GET['vo'] ?? ''), $vo)) {
            $this->kontext['obdobi'] = ($vo[3] ?? '') !== '' ? 'vse' : $vo[1];
            $this->kontext['rok'] = ($vo[2] ?? '') !== '' ? $vo[2] : null;
        }
        $q = $_SERVER['QUERY_STRING'] ?? '';
        $this->kontext['cesta'] = in_array($p, ['nastav', 'cast'], true) ? 'index.php' : 'index.php' . ($q !== '' ? '?' . $q : '');

        $verejne = ['prihlaseni', 'registrace'];
        if (!Auth::uzivatel() && !in_array($p, $verejne, true) && !Konfig::get('anonymni_cteni')) {
            if ($p === 'cast' || $_SERVER['REQUEST_METHOD'] === 'POST') {
                http_response_code(401);
                echo "<div class='chyba'>Přihlášení vypršelo – obnovte stránku.</div>";
                return;
            }
            $this->presmerujNa(odkaz('/prihlaseni', ['zpet' => $this->kontext['cesta']]));
            return;
        }

        Auth::uzivatel();
        if ($p === 'cast') {
            Auth::uvolni();      // části (verze, PLPV, relevance…) se načítají dlouho a souběžně
        }

        $post = [
            'oblibene/prepni' => 'akce_oblibene_prepni', 'oblibene/poznamka' => 'akce_oblibene_poznamka',
            'oblibene/videno' => 'akce_oblibene_videno', 'pravidla/vyber' => 'akce_pravidla_vyber',
        ];
        if (isset($post[$p])) {
            $this->obsluzPost($post[$p]);
            return;
        }
        $cesty = [
            '' => 'stranka_uvod', 'ministerstvo' => 'stranka_ministerstvo', 'hledej' => 'stranka_hledej',
            'material' => 'stranka_material', 'cast' => 'cast_detailu', 'obnov' => 'stranka_obnov',
            'tisky' => 'stranka_tisky', 'tisk' => 'stranka_tisk', 'novelizace' => 'stranka_novelizace', 'schuze' => 'stranka_schuze', 'plpv' => 'stranka_plpv', 'nastenka' => 'stranka_nastenka',
            'nastav' => 'stranka_nastav', 'oblibene' => 'stranka_oblibene',
            'prihlaseni' => 'stranka_prihlaseni', 'registrace' => 'stranka_registrace', 'odhlaseni' => 'stranka_odhlaseni', 'profil' => 'stranka_profil',
            'admin' => 'stranka_admin',
        ];
        $parametry = array_map(function ($v) { return is_array($v) ? (string)reset($v) : (string)$v; }, $_GET);
        unset($parametry['p']);
        try {
            if (!isset($cesty[$p])) {
                http_response_code(404);
                echo $this->stranka('Nenalezeno', '<h1>Stránka nenalezena</h1>');
                return;
            }
            $html = $this->{$cesty[$p]}($parametry);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo $html;
        } catch (Presmeruj $r) {
            foreach ($r->cookies as $c) {
                header('Set-Cookie: ' . $c, false);
            }
            $this->presmerujNa($r->kam);
        } catch (OdokChyba | RelevanceChyba $ch) {
            header('Content-Type: text/html; charset=utf-8');
            if ($p === 'cast') {
                echo "<div class='chyba'>" . e($ch->getMessage()) . '</div>';
            } else {
                http_response_code(404);
                echo $this->stranka('Chyba', "<h1>Chyba</h1><div class='chyba'>" . e($ch->getMessage()) . '</div>');
            }
        } catch (Throwable $ch) {
            log_zprava(sprintf('Chyba stránky %s: %s v %s:%d', $p, $ch->getMessage(), $ch->getFile(), $ch->getLine()), 'chyba');
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            $podrobnosti = Auth::jeAdmin() ? $ch->getMessage() . "\n" . $ch->getFile() . ':' . $ch->getLine() . "\n" . $ch->getTraceAsString()
                : $ch->getMessage();
            if ($p === 'cast') {
                echo "<div class='chyba'>" . e($podrobnosti) . '</div>';
            } else {
                echo $this->stranka('Chyba', "<h1>Chyba serveru</h1><div class='chyba'>" . e($podrobnosti) . '</div>');
            }
        }
    }

    private function obsluzPost(string $metoda): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Auth::overCsrf($_SERVER['HTTP_X_RADAR'] ?? null)) {
            http_response_code(403);
            echo '{"chyba": "nepovoleno"}';
            return;
        }
        Auth::uvolni();
        try {
            $parametry = array_map('strval', $_POST);
            echo json_encode($this->$metoda($parametry), JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException $ch) {
            http_response_code(400);
            echo json_encode(['chyba' => $ch->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $ch) {
            log_zprava('Chyba akce ' . $metoda . ': ' . $ch->getMessage(), 'chyba');
            http_response_code(500);
            echo json_encode(['chyba' => $ch->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    public function presmerujNa(string $kam): void
    {
        header('Location: ' . $kam, true, 303);
    }

    protected function presmeruj(string $kam, array $cookies = []): void
    {
        throw new Presmeruj($kam, $cookies);
    }

    // ---------------- kostra stránky ----------------

    public function hvezda(string $typ, $ident, $nazev = '', ?array $klice = null): string
    {
        if (!py_bool($ident) || !$this->oblibene->aktivni()) {
            return '';
        }
        $zap = $klice !== null ? isset($klice[Oblibene::klic($typ, $ident)]) : $this->oblibene->je($typ, $ident);
        return sprintf("<button type='button' class='hvezda%s' data-typ='%s' data-id='%s' data-nazev='%s' title='%s'>%s</button>",
            $zap ? ' zapnuto' : '', e($typ), e($ident), e(mb_substr((string)($nazev ?? ''), 0, 300)),
            $zap ? 'Odebrat z oblíbených' : 'Přidat do oblíbených', $zap ? HVEZDA : HVEZDA_PRAZDNA);
    }

    public function bunka_hvezdy(string $typ, $ident, $nazev, array $klice): string
    {
        $zap = isset($klice[Oblibene::klic($typ, $ident)]);
        return sprintf("<td class='sl-hvezda' data-razeni='%d'>%s</td>", $zap ? 1 : 0, $this->hvezda($typ, $ident, $nazev, $klice));
    }

    // ---------- poznámky k oblíbeným („přezdívky“ návrhů) ----------

    /** ['tisk' => [id => text], 'material' => [PID => text], 'predpis' => [ident => text], 'tisk_mat' => [id_tisk => text]] */
    protected function poznamky_obl(): array
    {
        if ($this->poznamky_cache !== null) {
            return $this->poznamky_cache;
        }
        $v = ['tisk' => [], 'material' => [], 'predpis' => [], 'tisk_mat' => []];
        foreach ($this->oblibene->aktivni() ? $this->oblibene->seznam() : [] as $p) {
            $t = trim(preg_replace('/\s+/u', ' ', (string)($p['poznamka'] ?? '')));
            if ($t !== '' && isset($v[$p['typ']])) {
                $v[$p['typ']][$p['typ'] === 'material' ? mb_strtoupper((string)$p['id']) : (string)$p['id']] = $t;
            }
        }
        if ($v['material'] && $this->psp_ok()) {      // poznámka k materiálu platí i pro jeho sněmovní tisk
            foreach ($this->snemovna()->pid_tisk(array_keys($v['material'])) as $pid => $id) {
                $v['tisk_mat'][(string)$id] = $v['material'][strtoupper($pid)] ?? null;
            }
            $v['tisk_mat'] = array_filter($v['tisk_mat']);
        }
        return $this->poznamky_cache = $v;
    }

    protected function poznamka_tisku($id_tisk): ?string
    {
        $p = $this->poznamky_obl();
        return $p['tisk'][(string)$id_tisk] ?? $p['tisk_mat'][(string)$id_tisk] ?? null;
    }

    protected function poznamka_materialu($pid, $id_tisk = null): ?string
    {
        $p = $this->poznamky_obl();
        return $p['material'][mb_strtoupper(trim((string)$pid))] ?? ($id_tisk !== null ? ($p['tisk'][(string)$id_tisk] ?? null) : null);
    }

    protected function poznamka_predpisu($ident): ?string
    {
        return $this->poznamky_obl()['predpis'][(string)$ident] ?? null;
    }

    /** Štítek s poznámkou (krátká celá, dlouhá zkrácená; celá v title), nebo ''. */
    public static function stitek_poznamky(?string $text, int $delka = 40): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        return sprintf(" <span class='prezdivka' title='Moje poznámka: %s'>%s</span>", e($text), e(mb_strimwidth($text, 0, $delka, '…')));
    }

    public function tlacitko_jen_oblibene(string $id): string
    {
        if (!$this->oblibene->aktivni()) {
            return '';
        }
        return sprintf("<button type='button' class='jen-obl' data-tabulka='%s' title='Zobrazit jen oblíbené řádky'>⭐ jen oblíbené</button>", e($id));
    }

    public function stranka(string $titulek, string $obsah, string $sekce = 'odok'): string
    {
        $nav = '';
        foreach ([['odok', '/', 'Materiály ODOK'], ['tisky', '/tisky', 'Sněmovní tisky'], ['novelizace', '/novelizace', 'Novelizace'],
                     ['schuze', '/schuze', 'Schůze PS'], ['nastenka', '/nastenka', 'Nástěnka'],
                     ['plpv', '/plpv', 'Podklady PLPV']] as [$klic, $url, $text]) {
            $nav .= sprintf("<a href='%s'%s>%s</a>", e(odkaz($url)), $klic === $sekce ? " class='aktivni'" : '', $text);
        }
        $u = Auth::uzivatel();
        if ($u) {
            $pocet = $this->oblibene->pocet();
            $nav .= sprintf("<a href='%s' title='Moje oblíbené návrhy'%s>⭐<span class='pocet-obl' id='pocet-obl'>%s</span></a>",
                e(odkaz('/oblibene')), $sekce === 'oblibene' ? " class='aktivni'" : '', $pocet ?: '');
        }
        if (Auth::jeAdmin()) {
            $nav .= sprintf("<a href='%s'%s>Správa</a>", e(odkaz('/admin')), $sekce === 'admin' ? " class='aktivni'" : '');
        }
        $uzivatel = $u
            ? sprintf("<span class='uzivatel'><a href='%s' title='Můj účet'>%s</a><form method='post' action='%s'><input type='hidden' name='csrf' value='%s'><button title='Odhlásit'>Odhlásit</button></form></span>",
                e(odkaz('/profil')), e($u['jmeno'] ?: $u['login']), e(odkaz('/odhlaseni')), e(Auth::csrf()))
            : sprintf("<span class='uzivatel'><a class='tlacitko' href='%s'>Přihlásit</a>%s</span>", e(odkaz('/prihlaseni', ['zpet' => $this->kontext['cesta']])),
                Auth::rezimRegistrace() !== 'vypnuta' ? sprintf(" <a href='%s'>Registrace</a>", e(odkaz('/registrace', ['zpet' => $this->kontext['cesta']]))) : '');
        $obnovit = $u ? sprintf("<a class='tlacitko' href='%s' title='Požádat o stažení čerstvých dat při nejbližším běhu importu'>Obnovit data</a>", e(odkaz('/obnov'))) : '';
        $zpravy = '';
        foreach (Auth::zpravy() as [$typ, $text]) {
            $zpravy .= sprintf("<div class='zprava %s'>%s</div>", e($typ), e($text));
        }
        $sablona = (string)@file_get_contents(RADAR_KOREN . '/assets/stranka.html');
        $verze = dechex((int)max(@filemtime(RADAR_KOREN . '/assets/styl.css'), @filemtime(RADAR_KOREN . '/assets/skript.js')));
        return strtr($sablona, [
            '$titulek' => e($titulek), '$nav' => $nav, '$prepinac' => in_array($sekce, ['admin', 'ucet'], true) ? '' : $this->prepinac(),
            '$obsah' => $obsah, '$verze' => $verze, '$uzivatel' => $uzivatel, '$csrf' => e(Auth::csrf()),
            '$obnovit' => $obnovit, '$zpravy' => $zpravy,
        ]);
    }

    // ---------------- volba roku (ODOK) a volebního období (Sněmovna) ----------------

    protected function vybrany_rok(): ?int
    {
        return cele_cislo($this->kontext['rok']);
    }

    protected function vybrane_obdobi()
    {
        $v = $this->kontext['obdobi'];
        if ($v !== null && cele_cislo($v) !== null) {
            return (int)$v;
        }
        return py_bool($v) ? $v : null;
    }

    /** Data Sněmovny (výchozí pohled). Vyhodí OdokChyba, když ještě nejsou naimportovaná. */
    protected function snemovna(): Psp
    {
        if ($this->psp_data === null) {
            if (!Psp::maData()) {
                throw new OdokChyba('Data Sněmovny zatím nejsou v databázi – počkejte na první běh importu (cron.php), nebo ho spusťte v administraci.');
            }
            $this->psp_data = new Psp();
        }
        return $this->psp_data;
    }

    protected function psp_ok(): bool
    {
        try {
            $this->snemovna();
            return true;
        } catch (OdokChyba $e) {
            return false;
        }
    }

    protected function legislativa(): Esel
    {
        if ($this->esel === null) {
            if (!Esel::maData()) {
                throw new OdokChyba('Data e-Legislativy zatím nejsou v databázi – počkejte na první běh importu (cron.php).');
            }
            $this->esel = new Esel();
        }
        return $this->esel;
    }

    protected function esel_ok(): bool
    {
        try {
            $this->legislativa();
            return true;
        } catch (OdokChyba $e) {
            return false;
        }
    }

    /** [[číslo, rok od, rok do|null], …] od nejnovějšího – z dat Sněmovny, jinak ze ZNAMA_OBDOBI. */
    protected function seznam_obdobi(): array
    {
        if ($this->psp_ok()) {
            $s = [];
            foreach ($this->snemovna()->obdobi_seznam() as $o) {
                if ($o->cislo && $o->od && ($o->rok_do() ?? 9999) >= prvni_rok_odok()) {
                    $s[] = [$o->cislo, $o->rok_od(), $o->rok_do()];
                }
            }
            if ($s) {
                return $s;
            }
        }
        $s = [];
        foreach (ZNAMA_OBDOBI as $c => [$od, $do]) {
            $s[] = [$c, $od, $do];
        }
        return $s;
    }

    protected function cislo_obdobi()
    {
        $v = $this->vybrane_obdobi();
        if ($v === 'vse') {
            return 'vse';
        }
        $s = $this->seznam_obdobi();
        foreach ($s as [$c]) {
            if (is_int($v) && $c === $v) {
                return $v;
            }
        }
        return $s[0][0];
    }

    protected function vybrane_roky(): array
    {
        $rok = $this->vybrany_rok();
        if ($rok) {
            return [$rok];
        }
        $cislo = $this->cislo_obdobi();
        if ($cislo === 'vse') {
            return range(prvni_rok_odok(), (int)date('Y'));
        }
        foreach ($this->seznam_obdobi() as [$c, $od, $do]) {
            if ($c === $cislo) {
                return roky_obdobi($od, $do);
            }
        }
        return [(int)date('Y')];
    }

    /** [od, do, popis] – dny, do kterých musí spadat založení materiálu; null = bez omezení. */
    protected function rozsah_vyberu(): ?array
    {
        $rok = $this->vybrany_rok();
        $cislo = $this->cislo_obdobi();
        if ($cislo === 'vse') {
            return $rok ? ["$rok-01-01", "$rok-12-31", "rok $rok"] : null;
        }
        $od = $do = null;
        if ($this->psp_ok()) {
            foreach ($this->snemovna()->obdobi_seznam() as $o) {
                if ($o->cislo === $cislo && $o->od) {
                    $od = den($o->od);
                    $do = $o->do ? den($o->do) : null;
                    break;
                }
            }
        }
        if ($od === null) {
            $od = ZACATKY_OBDOBI[$cislo] ?? null;
            $dalsi = is_int($cislo) ? (ZACATKY_OBDOBI[$cislo + 1] ?? null) : null;
            $do = $dalsi ? pridej_dny($dalsi, -1) : null;
        }
        if ($od === null) {
            return null;
        }
        $popis = sprintf('%s. VO (%s – %s)', $cislo, fmt_datum($od), $do ? fmt_datum($do) : 'dosud');
        if ($rok) {
            $od = max($od, "$rok-01-01");
            $do = min($do ?? "$rok-12-31", "$rok-12-31");
            $popis = sprintf('rok %d v %s. VO (%s – %s)', $rok, $cislo, fmt_datum($od), fmt_datum($do));
        }
        return [$od, $do, $popis];
    }

    protected function filtr_vyberu(array $materialy): array
    {
        $rozsah = $this->rozsah_vyberu();
        if ($rozsah === null) {
            return [$materialy, 0, null];
        }
        [$od, $do] = $rozsah;
        $v = [];
        foreach ($materialy as $m) {
            $d = den(py_or($m->datum_vytvoreni, $m->datum_autorizace));
            if ($d === null || ($d >= $od && ($do === null || $d <= $do))) {
                $v[] = $m;
            }
        }
        return [$v, count($materialy) - count($v), $rozsah];
    }

    protected function poznamka_filtru(int $skryto, ?array $rozsah): string
    {
        if (!$rozsah) {
            return '';
        }
        return sprintf("<p class='tlumene'>Zobrazeny materiály založené v období %s%s.</p>", e($rozsah[2]),
            $skryto ? sprintf('; skryto %d založených mimo toto období (ročníky ODOK jsou po kalendářních letech, volební období začínají v říjnu)', $skryto) : '');
    }

    protected function popis_vyberu(): string
    {
        $rok = $this->vybrany_rok();
        $cislo = $this->cislo_obdobi();
        if ($cislo === 'vse') {
            return $rok ? "rok $rok" : 'všechna období';
        }
        return $rok ? sprintf('%s (%s. VO)', $rok, $cislo) : sprintf('celé %s. období', $cislo);
    }

    protected function odok_pro($roky = null): Odok
    {
        if ($roky === null) {
            $roky = $this->vybrane_roky();
        } elseif (!is_array($roky)) {
            $roky = [(int)$roky];
        }
        $roky = array_map('intval', $roky);
        sort($roky);
        $k = implode(',', $roky);
        return $this->odok_instance[$k] ?? ($this->odok_instance[$k] = new Odok($roky));
    }

    protected function psp_pro($obdobi = 'zvolene'): Psp
    {
        $p = $this->snemovna();
        $param = $obdobi === 'zvolene' ? $this->vybrane_obdobi() : $obdobi;
        if ($param === null) {
            return $p;
        }
        $k = (string)$param;
        if (!isset($this->psp_pohledy[$k])) {
            $this->psp_pohledy[$k] = $p->v_obdobi($param);
        }
        try {
            $this->psp_pohledy[$k]->obdobi();
        } catch (OdokChyba $e) {
            return $p;
        }
        return $this->psp_pohledy[$k];
    }

    protected function prepinac(): string
    {
        $rok = $this->vybrany_rok();
        $cislo = $this->cislo_obdobi();
        $dnes = (int)date('Y');
        $skupiny = [];
        foreach ($this->seznam_obdobi() as [$c, $od, $do]) {
            $volby = [sprintf("<option value='%d'%s>celé období (%d. VO)</option>", $c, ($cislo === $c && !$rok) ? ' selected' : '', $c)];
            foreach (array_reverse(roky_obdobi($od, $do)) as $r) {
                $volby[] = sprintf("<option value='%d:%d'%s>%d (%d. VO)</option>", $c, $r, ($cislo === $c && $rok === $r) ? ' selected' : '', $r, $c);
            }
            $skupiny[] = sprintf("<optgroup label='%d. období (%d–%s)'>%s</optgroup>", $c, $od, $do ?? '', implode('', $volby));
        }
        $skupiny[] = sprintf("<optgroup label='Bez omezení'><option value='vse'%s>všechna období (ODOK %d–%d)</option></optgroup>",
            $cislo === 'vse' ? ' selected' : '', prvni_rok_odok(), $dnes);
        return sprintf('<form class="prepinac" action="index.php">
<input type="hidden" name="p" value="nastav"><input type="hidden" name="zpet" value="%s">
<label title="Volební období Poslanecké sněmovny, případně jeden rok v něm – určuje ročníky ODOK i sněmovní tisky">Období
<select name="vyber" onchange="this.form.submit()">%s</select></label>
<noscript><button>OK</button></noscript></form>', e($this->kontext['cesta'] ?: 'index.php'), implode('', $skupiny));
    }

    private static function cookie(string $nazev, string $hodnota, int $max_age): string
    {
        return sprintf('%s=%s; Path=%s; Max-Age=%d; SameSite=Lax', $nazev, $hodnota, Auth::cesta(), $max_age);
    }

    public function stranka_nastav(array $parametry): string
    {
        $cookies = [];
        $rok = $parametry['rok'] ?? '';
        $obdobi = $parametry['obdobi'] ?? '';
        $vyber = $parametry['vyber'] ?? '';
        if ($vyber !== '') {
            $casti = explode(':', $vyber, 2);
            $obdobi = $casti[0];
            $rok = $casti[1] ?? '';
            if ($rok === '') {
                $cookies[] = self::cookie('odok_rok', '', 0);
            }
        }
        if (ctype_digit($rok)) {
            $cookies[] = self::cookie('odok_rok', (string)(int)$rok, 31536000);
        }
        if (ctype_digit($obdobi) || $obdobi === 'vse') {
            $cookies[] = self::cookie('ps_obdobi', $obdobi, 31536000);
        }
        $zpet = $parametry['zpet'] ?? '';
        if (!preg_match('~^index\.php(\?[^\s]*)?$~', $zpet)) {
            $zpet = 'index.php';
        }
        if ($obdobi !== '') {   // období zadané přímo v adrese (podklady PLPV) nahradí nová volba
            $dotaz = [];
            parse_str((string)parse_url($zpet, PHP_URL_QUERY), $dotaz);
            unset($dotaz['o'], $dotaz['rok'], $dotaz['vo']);
            $zpet = 'index.php' . ($dotaz ? '?' . http_build_query($dotaz) : '');
        }
        $this->presmeruj($zpet, $cookies);
        return '';
    }

    // ---------------- ODOK ----------------

    /** Odkaz na tisk podle id_tisk: '35' (u jiného období '35 (9. obd.)'). */
    protected function odkaz_tisk_kratky($id_tisk): string
    {
        $p = $this->psp_pro();
        $r = $p->jeden('tisky', 'id_tisk', $id_tisk);
        if (!$r) {
            return '';
        }
        $t = new Tisk($r, $p);
        $aktualni = $p->obdobi() ? $p->obdobi()->cislo : null;
        $text = $t->obdobi() === $aktualni ? $t->cislo() : sprintf('%s (%s. obd.)', $t->cislo(), py_str($t->obdobi()));
        return sprintf("<a href='%s' title='%s'>%s</a>", e(odkaz('/tisk', ['id' => 'id:' . $id_tisk])), e($t->nazev()), e($text));
    }

    /** Řádek pod stavem ODOK: kde je materiál ve Sněmovně (stav tisku, číslo ve Sbírce). */
    protected function stav_ve_snemovne($id_tisk): string
    {
        $p = $this->psp_pro();
        $r = $p->jeden('tisky', 'id_tisk', $id_tisk);
        if (!$r) {
            return '';
        }
        $t = new Tisk($r, $p);
        $stav = $t->stav();
        if ($stav === null) {
            return '';
        }
        $sb = $t->sbirka();
        $hotovo = in_array($stav, [Tisk::STAV_VYHLASENO, 'SCHVÁLENO', 'PODEPSÁNO', 'VZATO NA VĚDOMÍ', 'ODESLÁNO K VYHLÁŠENÍ'], true);
        $kdy = $t->datum_stavu();
        return sprintf("<div class='dal%s' title='Stav sněmovního tisku %s%s'>PS: %s%s</div>",
            $hotovo ? ' hotovo' : ($t->projednano() ? ' skonceno' : ''), e($t->cislo()),
            $kdy ? ' od ' . e(fmt_datum(den($kdy) ?? $kdy)) : '', e($stav), $sb ? ' · ' . e($sb) : '');
    }

    /**
     * Řádek pod stavem ODOK z e-Legislativy (vyhlášky, nařízení a vše, co nemá sněmovní tisk).
     * V přehledu jen rychlé párování (čj OVA / čj předkladatele, nebo už spočítané na detailu
     * materiálu) – porovnávání názvů by stránku s mnoha materiály zdrželo.
     */
    protected function stav_v_elegislative(Esel $esel, $m): string
    {
        $v = $esel->k_materialu($m, true);
        if (!$v) {
            return '';
        }
        $n = $v[0];
        if ($n->citace || $n->datum_vyhlaseni) {
            return sprintf("<div class='dal hotovo' title='Podle e-Legislativy (návrh %s)'>Sb.: %s</div>",
                e($n->identifikator), e(py_or($n->citace, 'vyhlášeno ' . fmt_datum(den($n->datum_vyhlaseni) ?? $n->datum_vyhlaseni))));
        }
        $stav = $n->aktualni_stav;
        return $stav ? sprintf("<div class='dal' title='Aktuální stav v e-Legislativě (návrh %s)'>e-Leg.: %s</div>",
            e($n->identifikator), e($stav)) : '';
    }

    protected function tabulka_materialu(array $materialy, string $id = 'materialy'): string
    {
        $klice = $this->oblibene->klice();
        $tisky_hotove = $this->psp_ok();
        $pid_tisk = [];
        if ($tisky_hotove) {
            $pid_tisk = $this->snemovna()->pid_tisk(array_map(function ($m) { return $m->pid; }, $materialy));
            $this->psp_pro()->prednacti('tisky', 'id_tisk', array_values($pid_tisk));
        }
        if ($tisky_hotove) {
            $this->psp_pro()->prednacti_pro_tisky(array_values($pid_tisk));
        }
        $esel = $this->esel_ok() ? $this->legislativa() : null;
        $radky = [];
        foreach ($materialy as $m) {
            $tisk = '';
            $dal = '';
            if ($tisky_hotove && isset($pid_tisk[strtoupper((string)$m->pid)])) {
                $tisk = $this->odkaz_tisk_kratky($pid_tisk[strtoupper((string)$m->pid)]);
                $dal = $this->stav_ve_snemovne($pid_tisk[strtoupper((string)$m->pid)]);
            }
            if ($dal === '' && $esel !== null && !in_array($m->stav_id, ['Y', 'DZV'], true)) {
                $dal = $this->stav_v_elegislative($esel, $m);
            }
            $radky[] = '<tr>' . $this->bunka_hvezdy('material', $m->pid, $m->nazev, $klice) . sprintf(
                "<td><a href='%s'>%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td><td data-razeni='%s'>%s</td><td>%s</td></tr>",
                e(odkaz('/material', ['id' => py_or($m->pid, $m->cislo_jednaci, '')])), e($m->pid), e($m->cislo_jednaci),
                e($m->nazev) . self::stitek_poznamky($this->poznamka_materialu($m->pid, $pid_tisk[strtoupper((string)$m->pid)] ?? null))
                    . (isset($pid_tisk[strtoupper((string)$m->pid)]) ? $this->stitky_novel($pid_tisk[strtoupper((string)$m->pid)])
                        : ($this->sledovane_predpisy() ? $this->stitky_novel_materialu($m, ($esel && ($vn = $esel->k_materialu($m, true))) ? $vn[0] : null) : '')),
                e($m->predkladatel_zkratka), e($m->typ), e($m->stav), $dal, e($m->datum ?? ''), e(fmt_cas_odok($m->datum)), $tisk);
        }
        return sprintf('
<div class="lista"><input type="text" data-filtr="%1$s" placeholder="Filtrovat v tabulce…">
%2$s<span class="tlumene"><span id="%1$s-pocet">%3$d</span> materiálů</span></div>
<div class="posun"><table class="data raditelna" id="%1$s"><thead><tr>
<th title="Oblíbené">⭐</th><th>PID</th><th>Čj</th><th>Název</th><th>Předkl.</th><th>Typ</th><th>Stav</th><th>Datum</th>
<th title="Sněmovní tisk">Tisk PS</th>
</tr></thead><tbody>%4$s</tbody></table></div>%5$s', e($id), $this->tlacitko_jen_oblibene($id), count($materialy), implode('', $radky),
            $tisky_hotove ? '' : "<p class='tlumene'>Data Sněmovny zatím nejsou v databázi – sloupec „Tisk PS“ se doplní po jejich importu.</p>");
    }

    public function stranka_uvod(array $parametry): string
    {
        $odok = $this->odok_pro();
        $materialy = array_map(function ($r) use ($odok) { return new Material($r, $odok); },
            $odok->zaznamy('id, pid, predkladatel, predkladatel_zkratka, datum_vytvoreni, datum_autorizace'));
        [$materialy, $skryto, $rozsah] = $this->filtr_vyberu($materialy);
        $podle = [];
        foreach ($materialy as $m) {
            $z = py_bool($m->predkladatel_zkratka) ? $m->predkladatel_zkratka : '?';
            $n = $m->predkladatel ?? '(neuvedeno)';
            $podle[$z][$n] = ($podle[$z][$n] ?? 0) + 1;
        }
        $predkladatele = [];
        foreach ($podle as $z => $nazvy) {
            $serazene = $nazvy;
            $poradi = array_flip(array_keys($nazvy));
            uksort($serazene, function ($a, $b) use ($nazvy, $poradi) { return ($nazvy[$b] <=> $nazvy[$a]) ?: ($poradi[$a] <=> $poradi[$b]); });
            $uvedene = array_values(array_filter(array_keys($serazene), function ($n) { return $n !== '(neuvedeno)'; }));
            $predkladatele[] = [(string)$z, $uvedene[0] ?? '(neuvedeno)', array_sum($nazvy), array_slice($uvedene, 1)];
        }
        usort($predkladatele, function ($a, $b) { return ($b[2] <=> $a[2]) ?: strcmp($a[0], $b[0]); });
        $dlazdice = '';
        foreach ($predkladatele as [$z, $nazev, $pocet, $dalsi]) {
            $dlazdice .= sprintf("<a href='%s'%s><span class='zkratka'>%s</span><span>%s</span><span class='pocet'>%d</span></a>",
                e(odkaz('/ministerstvo', ['z' => $z])), $dalsi ? " title='Dříve / také: " . e(implode('; ', $dalsi)) . "'" : '',
                e($z), e($nazev), $pocet);
        }
        $prazdne = '';
        if (!$materialy && !(int)Db::hodnota('SELECT COUNT(*) FROM {p}odok_material')) {
            $prazdne = "<div class='karta'>Databáze je zatím prázdná – data se naplní při prvním běhu importu (cron.php). "
                . (Auth::jeAdmin() ? "Stav a ruční spuštění najdete ve <a href='" . e(odkaz('/admin', ['t' => 'data'])) . "'>správě</a>." : '') . '</div>';
        }
        $obsah = sprintf('<h1>Předkladatelé</h1>
<form class="lista" method="get" action="index.php"><input type="hidden" name="p" value="hledej">
<input type="search" name="q" placeholder="Hledat v materiálech všech předkladatelů – název, PID, čj…" style="max-width:520px" required>
<button>Hledat</button></form>
<p class="tlumene">%d materiálů (%s, ročníky ODOK %s). Klikněte na předkladatele, nebo hledejte napříč všemi
(„zákon, který …“ najde i „návrh zákona, kterým …“).</p>%s%s
<div class="mrizka">%s</div>', count($materialy), e($rozsah ? $rozsah[2] : 'všechna období'), e(implode(', ', $odok->roky)),
            $skryto ? $this->poznamka_filtru($skryto, $rozsah) : '', $prazdne, $dlazdice);
        return $this->stranka('Předkladatelé', $obsah);
    }

    public function stranka_ministerstvo(array $parametry): string
    {
        $z = $parametry['z'] ?? '';
        [$materialy, $skryto, $rozsah] = $this->filtr_vyberu($this->odok_pro()->materialy_ministerstva($z));
        $nazev = '';
        foreach ($materialy as $m) {
            if ($m->predkladatel) {
                $nazev = $m->predkladatel;
                break;
            }
        }
        $obsah = sprintf("<h1>%s <span class='tlumene'>%s</span></h1>%s%s", e($z), e($nazev), $this->poznamka_filtru($skryto, $rozsah),
            $materialy ? $this->tabulka_materialu($materialy) : '<p>Žádné materiály pro „' . e($z) . '“.</p>');
        return $this->stranka($z, $obsah);
    }

    public function stranka_hledej(array $parametry): string
    {
        $q = trim($parametry['q'] ?? '');
        if ($q === '') {
            $this->presmeruj(odkaz('/'));
        }
        $q_puvodni = $q;
        // v uvozovkách = jen text v materiálech (bez výkladu jako číslo předpisu, čj OVA, číslo tisku …)
        $jen_text = (bool)preg_match('/^["„“”\'](.+)["“”\']$/u', $q, $m_uv);
        if ($jen_text) {
            $q = trim($m_uv[1]);
        }
        // číslo předpisu (280/2009, 280/2009 Sb., zákon č. 280/2009): karta předpisu (odkaz na novelizace)
        // a pod ní běžné výsledky z ODOK – názvy materiálů číslo předpisu často obsahují
        $karta_predpisu = '';
        $predpis = $jen_text ? null : self::parsuj_predpis($q);
        if ($predpis !== null && !preg_match('/^\s*OVA/i', $q)) {
            $karta_predpisu = $this->karta_predpisu_hledani($predpis[0], $predpis[1], $q_puvodni);
            if ($karta_predpisu !== '') {
                $q = $predpis[0] . '/' . $predpis[1];   // ve fulltextu hledat holé číslo (bez „Sb.“, „zákon č.“)
            }
        }
        $heuristiky = !$jen_text && $karta_predpisu === '';
        $poznamka_tisk = '';
        $nalezy = $heuristiky ? $this->hledej_cj_pripominek($q, true) : [];
        if (count(array_unique(array_column($nalezy, 'pid'))) === 1) {
            $this->presmeruj(odkaz('/material', ['id' => $nalezy[0]['pid']]));
        }
        if ($heuristiky && preg_match('/^(OVA\s*)?\d+\s*(-\s*E)?(\s*\/\s*\d+)?$/i', $q)) {
            if (strpos($q, '/') !== false) {
                $cj = preg_replace('/^OVA\s*/i', '', $q);
                foreach ([$this->odok_pro(), $this->odok_pro(range(prvni_rok_odok(), (int)date('Y')))] as $o) {
                    try {
                        $m = $o->material($cj);
                    } catch (OdokChyba $e) {
                        continue;
                    }
                    if ($m->nazev) {
                        $this->presmeruj(odkaz('/material', ['id' => py_or($m->pid, $cj)]));
                    }
                }
            }
            try {
                $t = $this->psp_pro()->tisk($q);
                $this->presmeruj(odkaz('/tisk', ['id' => 'id:' . $t->id_tisk()]));
            } catch (OdokChyba $ch) {
                $poznamka_tisk = sprintf("<p class='tlumene'>Sněmovní tisk nenalezen: %s Hledám v materiálech ODOK.</p>", e($ch->getMessage()));
            }
        }
        $vse = ($parametry['vse'] ?? '') === '1';
        $odok = $this->odok_pro($vse ? range(prvni_rok_odok(), (int)date('Y')) : null);
        if ($heuristiky) {
            try {
                $m = $odok->material($q);
                $this->presmeruj(odkaz('/material', ['id' => py_or($m->pid, $q), 'rok' => '']));
            } catch (OdokChyba $e) {
            }
        }
        $vysledky = $odok->hledej($q);
        if ($karta_predpisu !== '') {        // číslo předpisu jako celé číslo (ne „1280/2009“ pro 280/2009)
            $vzor = sprintf('~(?<![0-9])%d\s*/\s*%d(?![0-9])~u', $predpis[0], $predpis[1]);
            $vysledky = array_values(array_filter($vysledky, function ($m) use ($vzor) {
                return (bool)preg_match($vzor, $m->nazev . ' ' . $m->cislo_jednaci . ' ' . $m->cj_ova);
            }));
        }
        $skryto = 0;
        $rozsah = null;
        if (!$vse) {
            [$vysledky, $skryto, $rozsah] = $this->filtr_vyberu($vysledky);
        }
        $roky_text = count($odok->roky) > 1 ? sprintf('%d–%d', min($odok->roky), max($odok->roky)) : (string)$odok->roky[0];
        $orez = count($vysledky) > MAX_VYSLEDKU;
        $rozsah_html = $vse
            ? sprintf("<p class='tlumene'>Hledáno ve všech ročnících ODOK (%s).</p>", $roky_text)
            : sprintf("<div class='lista'><span class='tlumene'>Hledáno: %s (ODOK %s).</span><a class='tlacitko' href='%s'>Hledat ve všech letech %d–%d</a></div>",
                e($this->popis_vyberu()), e($roky_text), e(odkaz('/hledej', ['q' => $q_puvodni, 'vse' => '1'])), prvni_rok_odok(), (int)date('Y'));
        $obsah = sprintf('<h1>Hledání: „%s“</h1>%s%s%s%s%s%s%s', e($q), $karta_predpisu, $poznamka_tisk,
            $heuristiky ? $this->html_nalezy_pripominek($q) : '', $karta_predpisu !== '' ? '<h2>Materiály ODOK s „' . e($q) . '“</h2>' . $rozsah_html : $rozsah_html,
            $skryto ? $this->poznamka_filtru($skryto, $rozsah) : '',
            $orez ? sprintf("<p class='tlumene'>Zobrazeno prvních %d z %d.</p>", MAX_VYSLEDKU, count($vysledky)) : '',
            $vysledky ? $this->tabulka_materialu(array_slice($vysledky, 0, MAX_VYSLEDKU))
                : '<p>Nic nenalezeno.' . ($vse ? '' : ' Materiál může být z jiného roku – zkuste hledání ve všech letech.') . '</p>');
        return $this->stranka('Hledání', $obsah);
    }

    /** Materiál ze zvoleného (nebo zadaného) ročníku, jinak z ostatních; u PID aspoň holý materiál. */
    protected function najdi_material($ident, $rok = null): Material
    {
        $instance = [$this->odok_pro($rok), $this->odok_pro(range(prvni_rok_odok(), (int)date('Y')))];
        $prvni_chyba = null;
        $holy = null;
        foreach ($instance as $o) {
            try {
                $m = $o->material($ident);
            } catch (OdokChyba $ch) {
                $prvni_chyba = $prvni_chyba ?? $ch;
                continue;
            }
            if ($m->nazev) {
                return $m;
            }
            $holy = $holy ?? $m;
        }
        if ($holy !== null) {
            return $holy;
        }
        throw $prvni_chyba ?? new OdokChyba("Materiál '$ident' nenalezen.");
    }

    public function stranka_material(array $parametry): string
    {
        $ident = $parametry['id'] ?? '';
        $rok = $parametry['rok'] ?? '';
        $m = $this->najdi_material($ident, ctype_digit($rok) ? (int)$rok : null);
        $info = $m->info();
        $dokumenty = $m->dokumenty();
        $url_dok = array_map([$m, 'url_dokumentu'], $dokumenty);
        $radky_info = '';
        foreach ($info as $k => $v) {
            if ($v !== null) {
                $radky_info .= sprintf('<tr><th>%s</th><td>%s</td></tr>', e(POPISKY[$k] ?? $k), json_na_html($v));
            }
        }
        $pid = $m->pid ?? '';
        if ($dokumenty) {
            $radky_dok = '';
            foreach ($dokumenty as $i => $d) {
                $u = $url_dok[$i];
                $typ = is_array($d['type'] ?? null) ? ($d['type']['name'] ?? null) : ($d['type'] ?? null);
                $jm = py_or($d['old_name'] ?? null, $d['name'] ?? null);
                $radky_dok .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    e(isset($d['order']) ? py_str($d['order']) : null), e($typ),
                    $u ? sprintf("<a href='%s' target='_blank' rel='noopener'>%s</a>", e($u), e($jm)) : e($jm),
                    e($d['name'] ?? null), e(py_or($d['size_str'] ?? null, $d['size'] ?? null)), e($d['date_modified'] ?? null),
                    implode(' ', array_filter([$this->tlacitko_nahled($pid, $d, $u), $this->tlacitko_relevance($pid, $d, $u)])));
            }
            $tab_dok = '<div class="posun"><table class="data raditelna"><thead><tr>
<th>#</th><th>Typ</th><th>Původní název</th><th>Soubor</th><th>Velikost</th><th>Změněno</th>
<th>Obsah</th></tr></thead><tbody>' . $radky_dok . '</tbody></table></div>';
        } else {
            $tab_dok = "<p class='tlumene'>V datasetu nejsou u materiálu žádné dokumenty.</p>";
        }
        $snemovna = $pid === '' ? '' : sprintf("<h2>Sněmovní tisk</h2><details open data-nacti='%s'><summary>Vazba na Poslaneckou sněmovnu</summary><div class='obsah'><p class='nacitam'>Načítám data Sněmovny…</p></div></details>",
            e(odkaz('/cast', ['typ' => 'psp', 'pid' => $pid])));
        $esel = ($pid === '' || !in_array($m->typ_id, ['NV', 'NNV'], true)) ? '' :
            sprintf("<h2>e-Legislativa</h2><details open data-nacti='%s'><summary>Návrh v e-Legislativě (fáze, stavy, Sbírka, účinnost)</summary><div class='obsah'><p class='nacitam'>Načítám data e-Legislativy…</p></div></details>",
                e(odkaz('/cast', ['typ' => 'esel', 'pid' => $pid])));
        $obsah = sprintf('<h1>%s%s</h1>
<p class="tlumene">%s · %s · <a href="%s" target="_blank" rel="noopener">otevřít v ODOK ↗</a></p>
<div class="karta"><table class="kv">%s</table></div>
%s%s
<h2>Dokumenty (%d)</h2>%s
<h2>Historické verze</h2>
<details data-nacti="%s"><summary>Zobrazit verze</summary>
<div class="obsah"><p class="nacitam">Načítám…</p></div></details>
<h2>Připomínky</h2>
<details data-nacti="%s"><summary>Zobrazit připomínky</summary>
<div class="obsah"><p class="nacitam">Načítám…</p></div></details>
<h2>Všechna data materiálu</h2>
<details><summary>Zobrazit surová data z datasetu</summary>%s</details>
', $this->hvezda('material', $pid, $m->nazev), e(py_or($m->nazev, $pid)), e($pid), e(py_or($m->cislo_jednaci, 'čj neuvedeno')),
            e(py_or($m->url_web, '#')), $radky_info, $snemovna, $esel, count($dokumenty), $tab_dok,
            e(odkaz('/cast', ['typ' => 'verze', 'pid' => $pid])), e(odkaz('/cast', ['typ' => 'pripominky', 'pid' => $pid])),
            json_na_html($m->data()));
        return $this->stranka(py_or($m->nazev, $pid) ?: 'Materiál', $obsah);
    }

    public function cast_detailu(array $parametry): string
    {
        $typ = $parametry['typ'] ?? '';
        $pid = $parametry['pid'] ?? '';
        switch ($typ) {
            case 'psp': return $this->cast_tisk_k_materialu($pid);
            case 'esel': return $this->cast_esel_k_materialu($pid);
            case 'relevance': return $this->cast_relevance($pid, $parametry['d'] ?? '');
            case 'nahled': return $this->cast_nahled($pid, $parametry['d'] ?? '');
            case 'plpv': return $this->cast_plpv($parametry);
            case 'verze': return $this->html_verze($pid, Odok::verze($pid));
            case 'pripominky':
                $data = Odok::pripominky($pid);
                $this->pridej_do_indexu(strtoupper($pid), $data);
                return $this->html_pripominky($data, $pid);
        }
        throw new OdokChyba('Neznámý typ: ' . $typ);
    }

    protected function tlacitko_relevance($pid_materialu, $d, $url): string
    {
        $nazvy = mb_strtolower(implode(' ', [py_str($d['name'] ?? ''), py_str($d['old_name'] ?? '')]));
        if (!py_bool($url) || !py_bool($d['pid'] ?? null) || !py_bool($pid_materialu) || !preg_match('/\.(doc[xm]?|pdf)\b/u', $nazvy)) {
            return '';
        }
        return sprintf("<button type='button' class='tlacitko-mini' data-vlozit='%s' data-skryt='Skrýt analýzu' data-nacitam='Stahuji dokument a hledám výrazy…' title='Stáhne dokument a vyhledá v něm výrazy ze seznamu útvarů'>Provést analýzu relevance</button>",
            e(odkaz('/cast', ['typ' => 'relevance', 'pid' => $pid_materialu, 'd' => $d['pid']])));
    }

    protected function tlacitko_nahled($pid_materialu, $d, $url): string
    {
        $nazvy = mb_strtolower(implode(' ', [py_str($d['name'] ?? ''), py_str($d['old_name'] ?? '')]));
        if (!py_bool($url) || !py_bool($d['pid'] ?? null) || !py_bool($pid_materialu) || !preg_match('/\.(doc[xm]?|pdf)\b/u', $nazvy)) {
            return '';
        }
        return sprintf("<button type='button' class='tlacitko-mini' data-vlozit='%s' data-skryt='Skrýt text' data-nacitam='Stahuji a načítám dokument…' title='Zobrazí obsah dokumentu přímo na stránce'>Zobrazit text</button>",
            e(odkaz('/cast', ['typ' => 'nahled', 'pid' => $pid_materialu, 'd' => $d['pid']])));
    }

    const TYPY_VERZI = ['MPR' => 'Verze do připomínkového řízení', 'PK' => 'Verze pro jednání pracovních komisí',
        'JV' => 'Verze pro jednání vlády', 'PV' => 'Projednaná verze'];

    protected function html_verze(string $pid, $data): string
    {
        if (!je_dict($data) || !je_list($data['versions_list'] ?? null)) {
            return json_na_html($data);
        }
        $verze = $data['versions_list'];
        usort($verze, function ($a, $b) { return strcmp(py_str($a['date_authorized'] ?? ''), py_str($b['date_authorized'] ?? '')); });
        if (!$verze) {
            return "<p class='tlumene'>Materiál zatím nemá žádné zveřejněné verze.</p>";
        }
        $vid = function ($v) { return py_str(($v['version_type'] ?? [])['id'] ?? ''); };
        $prehled = implode(' → ', array_map(function ($v) use ($vid) {
            return sprintf("<span class='verze-typ %s'>%s</span>", e(strtolower($vid($v))), e($vid($v) !== '' ? $vid($v) : '?'));
        }, $verze));
        $casti = [sprintf("<div class='verze'><p class='tlumene'>%d %s v pořadí vzniku: %s</p>", count($verze), count($verze) < 5 ? 'verze' : 'verzí', $prehled)];
        foreach (array_reverse($verze) as $i => $v) {
            $vt = $v['version_type'] ?? [];
            $stav = py_str(($v['status'] ?? [])['name'] ?? '');
            $ident = $v['identifier'] ?? [];
            $titul = trim(py_str($v['title'] ?? ''));
            $udaje = [['Stav', e($stav)],
                ['Čj předkladatele', e($ident['id_no_ext'] ?? '')],
                ['Čj ÚV (OVA)', e($ident['id_no'] ?? '')],
                ['Vytvořeno', e(fmt_cas_odok(($v['metadata'] ?? [])['date_created'] ?? null))],
                ['Název', $titul !== trim(py_str($data['title'] ?? '')) ? e($titul) : ''],
                ['Důvod předložení', e($v['reason'] ?? '')],
                ['Poznámka', e($v['notes'] ?? '')],
                ['Právní oblast', e(implode(', ', array_map('py_str', (array)($v['law_areas'] ?? []))))],
                ['Klíčová slova', e(implode(', ', array_map('py_str', (array)($v['keywords'] ?? []))))]];
            $rizeni = $v['review_process'] ?? [];
            if (py_bool($rizeni['date_finish'] ?? null) || py_bool($rizeni['reviewers'] ?? null)) {
                $udaje[] = ['Připomínkové řízení', sprintf('do %s · %d připomínkových míst%s', e(fmt_cas_odok($rizeni['date_finish'] ?? null)),
                    count((array)($rizeni['reviewers'] ?? [])),
                    py_bool($rizeni['email_for_reviews'] ?? null) ? " · e-mail <span class='vyber'>" . e($rizeni['email_for_reviews']) . '</span>' : '')];
            }
            $prilohy = (array)($v['attachments'] ?? []);
            usort($prilohy, function ($a, $b) { return (py_or($a['order'] ?? null, 0)) <=> (py_or($b['order'] ?? null, 0)); });
            if ($prilohy) {
                $radky = '';
                foreach ($prilohy as $a) {
                    $u = py_or($a['uri'] ?? null, vzor(URL_DOKUMENT, ['pid' => $a['pid'] ?? '']));
                    $radky .= sprintf("<tr><td>%s</td><td>%s</td><td><a href='%s' target='_blank' rel='noopener'>%s</a></td><td class='tlumene'>%s</td><td>%s</td><td>%s</td></tr>",
                        e(py_or($a['order'] ?? null, '')), e(py_or(($a['type'] ?? [])['name'] ?? null, '')), e($u),
                        e(py_or($a['old_name'] ?? null, $a['name'] ?? null, 'soubor')), e(py_or($a['name'] ?? null, '')), e(py_or($a['size_str'] ?? null, '')),
                        implode(' ', array_filter([$this->tlacitko_nahled($pid, $a, py_or($a['uri'] ?? null, $a['pid'] ?? null)),
                            $this->tlacitko_relevance($pid, $a, py_or($a['uri'] ?? null, $a['pid'] ?? null))])));
                }
                $tab = "<div class='posun'><table class='data raditelna'><thead><tr><th>#</th><th>Typ</th><th>Původní název</th><th>Soubor</th><th>Velikost</th><th>Obsah</th></tr></thead><tbody>$radky</tbody></table></div>";
            } else {
                $tab = "<p class='tlumene'>Bez dokumentů.</p>";
            }
            $n = count($prilohy);
            $tid = py_str($vt['id'] ?? '');
            $casti[] = sprintf('<details class="verze-karta"%s><summary><span class="verze-typ %s">%s</span>
<b>%s</b> <span class="tlumene">%s · %d %s</span>%s</summary>
<div class="karta"><table class="kv">%s</table>%s</div></details>', $i === 0 ? ' open' : '', e(strtolower($tid)),
                e(py_or($vt['name'] ?? null, self::TYPY_VERZI[$tid] ?? null, $tid !== '' ? $tid : null, 'verze')),
                e(fmt_cas_odok($v['date_authorized'] ?? null)), e($stav), $n,
                $n === 1 ? 'dokument' : ($n > 1 && $n < 5 ? 'dokumenty' : 'dokumentů'),
                $i === 0 ? " <span class='stitek'>nejnovější</span>" : '',
                implode('', array_map(function ($x) { return $x[1] !== '' ? sprintf('<tr><th>%s</th><td>%s</td></tr>', $x[0], $x[1]) : ''; }, $udaje)),
                $tab);
        }
        $casti[] = '<details><summary>Surová data verzí</summary>' . json_na_html($data) . '</details></div>';
        return implode('', $casti);
    }

    const TYPY_PRIPOMINEK = ['Z' => [0, 'zasadni', 'Zásadní'], 'D' => [1, 'doporucujici', 'Doporučující'], 'BP' => [2, 'bez', 'Bez připomínek']];

    protected function html_pripominky($data, ?string $pid = null): string
    {
        if (!je_dict($data)) {
            return json_na_html($data);
        }
        $rizeni = (array)($data['review_process'] ?? []);
        $mista = (array)($rizeni['reviewers'] ?? []);
        $pripominky = (array)($data['reviews_list'] ?? []);
        $typ = function ($r) {
            $t = (array)($r['review_type'] ?? []);
            return [py_str($t['id'] ?? ''), py_str($t['name'] ?? '')];
        };
        $pocty = [];
        $odpovedeli = [];
        foreach ($pripominky as $r) {
            [$tid, $tn] = $typ($r);
            $k = $tid . "\x00" . $tn;
            $pocty[$k] = ($pocty[$k] ?? 0) + 1;
            $odpovedeli[] = ((($r['author'] ?? [])['organization'] ?? [])['id'] ?? null);
        }
        $nevyjadrili = array_values(array_filter($mista, function ($m) use ($odpovedeli) { return !in_array($m['id'] ?? null, $odpovedeli, true); }));
        $poradi_typu = function ($tid) { return self::TYPY_PRIPOMINEK[$tid][0] ?? 9; };
        $klice = array_keys($pocty);
        $pozice = array_flip($klice);
        usort($klice, function ($a, $b) use ($poradi_typu, $pozice) {
            return ($poradi_typu(explode("\x00", $a)[0]) <=> $poradi_typu(explode("\x00", $b)[0])) ?: ($pozice[$a] <=> $pozice[$b]);
        });
        $stitky = '';
        foreach ($klice as $k) {
            [$tid, $nazev] = explode("\x00", $k);
            $stitky .= sprintf("<span class='pripominka %s'>%s: <b>%d</b></span>", self::TYPY_PRIPOMINEK[$tid][1] ?? 'jina', e($nazev !== '' ? $nazev : $tid), $pocty[$k]);
        }
        $souhrn = [];
        if (py_bool($rizeni['date_finish'] ?? null)) {
            $souhrn[] = 'Připomínky do <b>' . e(fmt_cas_odok($rizeni['date_finish'])) . '</b>';
        }
        if ($mista) {
            $souhrn[] = sprintf('%d připomínkových míst, vyjádřilo se %d', count($mista), count($mista) - count($nevyjadrili));
        }
        if (py_bool($rizeni['email_for_reviews'] ?? null)) {
            $souhrn[] = "e-mail pro připomínky: <span class='vyber'>" . e($rizeni['email_for_reviews']) . '</span>';
        }
        $casti = ["<div class='pripominky'>"];
        if ($souhrn) {
            $casti[] = '<p>' . implode(' · ', $souhrn) . '</p>';
        }
        if ($stitky) {
            $casti[] = "<div class='lista'>$stitky</div>";
        }
        if ($pripominky) {
            $serazene = $pripominky;
            usort($serazene, function ($a, $b) use ($typ, $poradi_typu) {
                return [$poradi_typu($typ($a)[0]), py_str($a['date_authorized'] ?? '')] <=> [$poradi_typu($typ($b)[0]), py_str($b['date_authorized'] ?? '')];
            });
            $radky = [];
            foreach ($serazene as $r) {
                [$tid, $tn] = $typ($r);
                $org = (array)((($r['author'] ?? [])['organization']) ?? []);
                $prilohy = (array)($r['attachments'] ?? []);
                usort($prilohy, function ($a, $b) { return (py_or($a['order'] ?? null, 0)) <=> (py_or($b['order'] ?? null, 0)); });
                $soubory = '';
                foreach ($prilohy as $a) {
                    $soubory .= sprintf("<div class='soubor'><a href='%s' target='_blank' rel='noopener' title='%s'>%s</a> <span class='tlumene'>(%s%s)</span> %s</div>",
                        e(py_or($a['uri'] ?? null, vzor(URL_DOKUMENT, ['pid' => $a['pid'] ?? '']))), e(py_or($a['name'] ?? null, '')),
                        e(py_or($a['old_name'] ?? null, $a['name'] ?? null, 'soubor')), e(py_or(($a['type'] ?? [])['name'] ?? null, '')),
                        py_bool($a['size_str'] ?? null) ? ', ' . e($a['size_str']) : '',
                        $this->tlacitko_nahled($pid, $a, py_or($a['uri'] ?? null, $a['pid'] ?? null)));
                }
                $text = implode(' ', array_filter([$r['notes'] ?? null, $r['reason'] ?? null], function ($x) { return is_string($x) && trim($x) !== ''; }));
                $radky[] = sprintf("<tr><td><b>%s</b> <span class='tlumene'>%s</span></td><td data-razeni='%d'><span class='pripominka %s'>%s</span></td><td data-razeni='%s'>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>",
                    e(py_or($org['id'] ?? null, '')), e(py_or($org['name'] ?? null, '')), $poradi_typu($tid),
                    self::TYPY_PRIPOMINEK[$tid][1] ?? 'jina', e($tn !== '' ? $tn : $tid),
                    e(py_or($r['date_authorized'] ?? null, '')), e(fmt_cas_odok($r['date_authorized'] ?? null)),
                    e(py_or(($r['identifier'] ?? [])['id_no_ext'] ?? null, '')), $soubory,
                    $text !== '' ? "<div class='text-pripominky'>" . e($text) . '</div>' : '');
            }
            $casti[] = sprintf('<div class="lista"><input type="text" data-filtr="pripominky-tab" placeholder="Filtrovat (místo, typ, čj…)">
<span class="tlumene"><span id="pripominky-tab-pocet">%d</span> vyjádření</span></div>
<div class="posun"><table class="data raditelna" id="pripominky-tab"><thead><tr><th>Připomínkové místo</th>
<th>Typ</th><th>Vloženo</th><th>Čj místa</th><th>Soubory</th><th>Text</th></tr></thead><tbody>%s</tbody></table></div>', count($pripominky), implode('', $radky));
        } else {
            $casti[] = "<p class='tlumene'>Zatím nejsou vložené žádné připomínky.</p>";
        }
        if ($nevyjadrili) {
            usort($nevyjadrili, function ($a, $b) { return strcmp(py_str(py_or($a['id'] ?? null, '')), py_str(py_or($b['id'] ?? null, ''))); });
            $casti[] = sprintf('<details><summary>Nevyjádřili se (%d)</summary><p>%s</p></details>', count($nevyjadrili), implode(', ', array_map(function ($m) {
                return sprintf("<span title='%s'>%s</span>", e(py_or($m['name'] ?? null, '')), e(py_or($m['id'] ?? null, $m['name'] ?? null, '')));
            }, $nevyjadrili)));
        }
        $casti[] = '<details><summary>Surová data připomínek</summary>' . json_na_html($data) . '</details></div>';
        return implode('', $casti);
    }

    public function stranka_obnov(array $parametry): string
    {
        Db::q('UPDATE {p}zdroj SET vynutit = 1 WHERE klic <> ?', ['cron']);
        foreach (array_keys(Import::zdroje()) as $k) {
            Db::q('INSERT IGNORE INTO {p}zdroj (klic, popis, vynutit) VALUES (?, ?, 1)', [$k, Import::zdroje()[$k]['popis']]);
        }
        Db::q("DELETE FROM {p}http_cache WHERE url NOT LIKE '%/download/attachment/%'");
        Auth::zprava('Čerstvá data se stáhnou při nejbližším běhu importu (cron). Verze a připomínky materiálů se stáhnou znovu při zobrazení.');
        $this->presmeruj(Auth::jeAdmin() ? odkaz('/admin', ['t' => 'data']) : odkaz('/'));
        return '';
    }

    // ---------------- dokumenty: náhled a relevance ----------------

    /** [popis dokumentu, bytes] – dokument materiálu, některé jeho verze nebo připomínky. */
    protected function dokument(string $pid, string $pid_dok): array
    {
        $m = $this->najdi_material($pid);
        $d = null;
        $url = null;
        foreach ($m->dokumenty() as $x) {
            if (($x['pid'] ?? null) === $pid_dok) {
                $d = $x;
                $url = $m->url_dokumentu($d);
                break;
            }
        }
        foreach ([['verze', 'versions_list'], ['pripominky', 'reviews_list']] as [$metoda, $klic]) {
            if ($d !== null) {
                break;
            }
            try {
                $data = Odok::$metoda($pid);
                $seznam = je_dict($data) ? (array)($data[$klic] ?? []) : [];
            } catch (OdokChyba $e) {
                $seznam = [];
            }
            foreach ($seznam as $v) {
                foreach ((array)($v['attachments'] ?? []) as $a) {
                    if (($a['pid'] ?? null) === $pid_dok) {
                        $d = $a;
                        $url = py_or($a['uri'] ?? null, vzor(URL_DOKUMENT, ['pid' => $pid_dok]));
                        break 2;
                    }
                }
            }
        }
        if ($d === null) {
            throw new OdokChyba("Dokument $pid_dok u materiálu $pid nenalezen.");
        }
        // dokument se v ODOK nemění → stáhne se jen jednou (cache v databázi bez omezení stáří)
        return [$d, Http::sCache((string)$url, null)];
    }

    public function cast_nahled(string $pid, string $pid_dok): string
    {
        [$d, $obsah] = $this->dokument($pid, $pid_dok);
        $telo = Dokumenty::na_html($obsah);
        $url = py_or($d['uri'] ?? null, vzor(URL_DOKUMENT, ['pid' => $pid_dok]));
        return sprintf("<div class='nahled'><div class='lista'><b>%s</b><span class='tlumene'>%s</span><a href='%s' target='_blank' rel='noopener'>stáhnout ↗</a></div><div class='nahled-obsah'>%s</div></div>",
            e(py_or($d['old_name'] ?? null, $d['name'] ?? null, 'dokument')), is_array($d['type'] ?? null) ? e(py_or($d['type']['name'] ?? null, '')) : '',
            e($url), $telo !== '' ? $telo : "<p class='tlumene'>Dokument neobsahuje žádný text.</p>");
    }

    public function cast_relevance(string $pid, string $pid_dok): string
    {
        [$d, $obsah] = $this->dokument($pid, $pid_dok);
        $rel = new Relevance(SadyPravidel::pro_uzivatele(Auth::uzivatel()));
        $v = $rel->analyzuj_dokument($obsah);
        $relevantni = array_values(array_filter($v['utvary'], function ($u) { return $u['skore'] > 0; }));
        $nazev = py_or($d['old_name'] ?? null, $d['name'] ?? null, '');
        $casti = ["<div class='relevance'>"];
        if ($relevantni) {
            $nejvic = $relevantni[0]['skore'] ?: 1;
            $radky = [];
            foreach ($relevantni as $u) {
                $duvody = '';
                foreach ($u['duvody'] as $n) {
                    $vaha = $n['vahy'][$u['utvar']];
                    $duvody .= sprintf('<li><b>%s</b> – %d× %s%s%s</li>', e(py_or($n['priklad'], $n['vzor'])), $n['pocet'],
                        $vaha == 1 ? '' : '(váha ' . e(py_str($vaha)) . ') ',
                        $n['poznamka'] !== '' ? "<span class='tlumene'>" . e($n['poznamka']) . '</span>' : '',
                        "<div class='ukazky'>" . implode('', array_map(function ($x) {
                            return sprintf('<div>%s<mark>%s</mark>%s</div>', e($x[0]), e($x[1]), e($x[2]));
                        }, $n['ukazky'])) . '</div>');
                }
                $radky[] = sprintf("<tr><td><b>%s</b></td><td data-razeni='%09.2f'><span class='sloupek' style='width:%dpx'></span> %s</td><td>%d</td><td><details open><summary>%d %s</summary><ul>%s</ul></details></td></tr>",
                    e($u['utvar']), $u['skore'], max(4, (int)(80 * $u['skore'] / $nejvic)), e($u['skore_text']), $u['vyskytu'], $u['vyrazu'],
                    $u['vyrazu'] === 1 ? 'výraz' : ($u['vyrazu'] < 5 ? 'výrazy' : 'výrazů'), $duvody);
            }
            $casti[] = sprintf('<p>Dokument <b>%s</b> může být relevantní pro: %s</p>', e($nazev),
                implode(', ', array_map(function ($u) { return '<b>' . e($u['utvar']) . '</b>'; }, $relevantni)));
            $casti[] = "<div class='posun'><table class='data raditelna'><thead><tr><th>Útvar</th><th>Skóre</th><th>Výskytů</th><th>Proč</th></tr></thead><tbody>"
                . implode('', $radky) . '</tbody></table></div>';
        } else {
            $casti[] = sprintf('<p>V dokumentu <b>%s</b> se nenašel žádný výraz ze seznamu – podle něj není relevantní pro žádný útvar.</p>', e($nazev));
        }
        $bez = array_column(array_filter($v['utvary'], function ($u) { return $u['skore'] <= 0; }), 'utvar');
        if ($relevantni && $bez) {
            $casti[] = "<p class='tlumene'>Bez nálezu: " . e(implode(', ', $bez)) . '</p>';
        }
        $bez_utvaru = array_filter($v['nalezy'], function ($n) { return !$n['vahy']; });
        if ($bez_utvaru) {
            $casti[] = "<p class='tlumene'>Nalezeno, ale bez přiřazeného útvaru: " . e(implode(', ', array_map(function ($n) {
                return sprintf('%s (%d×)', py_or($n['priklad'], $n['vzor']), $n['pocet']);
            }, $bez_utvaru))) . '</p>';
        }
        if ($v['chyby']) {
            $casti[] = "<p class='tlumene'>Chyby v seznamu výrazů (řádky přeskočeny): " . e(implode('; ', $v['chyby'])) . '</p>';
        }
        $sady = SadyPravidel::seznam();
        $u = Auth::uzivatel();
        $prepinac = ($u && count($sady) > 1)
            ? '<label>Sada výrazů ' . SadyPravidel::vyber('pravidla', $u['pravidla'], ' data-pravidla-vyber') . '</label>'
            : sprintf('sada <b>%s</b>%s', e(SadyPravidel::popis($rel->soubor)), $u ? '' : ' (základní; po přihlášení si lze vybrat jinou)');
        $casti[] = sprintf("<div class='lista'><button type='button' class='tlacitko-mini' data-relevance-znovu>↻ Přegenerovat relevanci</button>%s<span class='tlumene'>Prohledáno %d znaků textu podle %d výrazů ze sady <b>%s</b> (změněna %s). Sady výrazů nahrává správce; po jejich úpravě stačí přegenerovat.</span></div>",
            $prepinac, $v['znaku'], count($rel->pravidla), e(SadyPravidel::popis($rel->soubor)), e($rel->zmeneno ? fmt_datum(date('Y-m-d H:i:s', $rel->zmeneno)) : '?'));
        $casti[] = '</div>';
        return implode('', $casti);
    }

    // ---------------- index čísel jednacích připomínek ----------------
    // Čj připomínkových míst nejsou v datové sadě ODOK, jen v JSONu připomínek každého materiálu.
    // Index se doplňuje při každém zobrazení připomínek (nic se navíc nestahuje).

    public static function norm_cj_pripominky($text): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(bez_diakritiky($text ?? '')));
    }

    protected function pridej_do_indexu(string $pid, $data): void
    {
        $radky = [];
        foreach (je_dict($data) ? (array)($data['reviews_list'] ?? []) : [] as $i => $r) {
            $cj = trim(py_str((($r['identifier'] ?? [])['id_no_ext']) ?? ''));
            if ($cj === '') {
                continue;
            }
            $org = (array)((($r['author'] ?? [])['organization']) ?? []);
            $k = self::norm_cj_pripominky($cj);
            if ($k !== '') {
                $radky[] = [mb_substr($k, 0, 190), $pid, $i, mb_substr($cj, 0, 255), mb_substr(py_str(py_or($org['id'] ?? null, '')), 0, 64),
                    mb_substr(py_str(py_or($org['name'] ?? null, '')), 0, 255), mb_substr(py_str(py_or((($r['review_type'] ?? [])['name']) ?? null, '')), 0, 100)];
            }
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        Db::q('DELETE FROM {p}pripominky_cj WHERE pid = ?', [$pid]);
        Db::vlozVice('pripominky_cj', ['norm', 'pid', 'poradi', 'cj', 'org', 'org_nazev', 'typ'], $radky);
        $pdo->commit();
    }

    protected function hledej_cj_pripominek(string $dotaz, bool $jen_presne = false): array
    {
        $q = self::norm_cj_pripominky($dotaz);
        if (strlen($q) < 5 || !preg_match('/\d/', $q)) {
            return [];
        }
        $n = Db::vse('SELECT pid, cj, org, org_nazev, typ FROM {p}pripominky_cj WHERE norm = ? ORDER BY pid, poradi', [$q]);
        if (!$n && !$jen_presne) {
            $n = Db::vse('SELECT pid, cj, org, org_nazev, typ FROM {p}pripominky_cj WHERE norm LIKE ? ORDER BY pid, poradi LIMIT 51',
                ['%' . Odok::like($q) . '%']);
        }
        return $n;
    }

    protected function html_nalezy_pripominek(string $q): string
    {
        $nalezy = $this->hledej_cj_pripominek($q);
        if (!$nalezy) {
            return '';
        }
        $pocet = (int)Db::hodnota('SELECT COUNT(DISTINCT pid) FROM {p}pripominky_cj');
        $radky = '';
        foreach ($nalezy as $n) {
            try {
                $nazev = py_or($this->najdi_material($n['pid'])->nazev, $n['pid']);
            } catch (OdokChyba $e) {
                $nazev = $n['pid'];
            }
            $radky .= sprintf("<tr><td><a href='%s'>%s</a></td><td><b>%s</b> <span class='tlumene'>%s</span></td><td>%s</td><td>%s</td></tr>",
                e(odkaz('/material', ['id' => $n['pid']])), e($nazev), e($n['org']), e($n['org_nazev']), e($n['cj']), e($n['typ']));
        }
        return "<h2>Nalezeno v číslech jednacích připomínek</h2><div class='posun'><table class='data raditelna'><thead><tr><th>Materiál</th><th>Připomínkové místo</th><th>Čj připomínky</th><th>Typ</th></tr></thead><tbody>$radky</tbody></table></div>"
            . sprintf("<p class='tlumene'>Čísla jednací připomínek se prohledávají jen u materiálů, jejichž připomínky už byly v aplikaci zobrazeny (%d materiálů).</p>", $pocet)
            . '<h2>Materiály</h2>';
    }

    // ---------------- účet, přihlášení, správa ----------------

    private static function bezpecny_zpet($zpet): string
    {
        $zpet = (string)$zpet;
        if (!preg_match('~^index\.php(\?[^\s]*)?$~', $zpet) || preg_match('~p=(prihlaseni|registrace|odhlaseni)~', $zpet)) {
            return 'index.php';
        }
        return $zpet;
    }

    public function stranka_prihlaseni(array $parametry): string
    {
        $zpet = self::bezpecny_zpet($parametry['zpet'] ?? ($_POST['zpet'] ?? 'index.php'));
        $chyba = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Auth::overCsrf($_POST['csrf'] ?? null)) {
                $chyba = 'Formulář vypršel, zkuste to znovu.';
            } else {
                $chyba = Auth::prihlas((string)($_POST['login'] ?? ''), (string)($_POST['heslo'] ?? ''), !empty($_POST['trvale'])) ?? '';
                if ($chyba === '') {
                    $this->presmeruj($zpet);
                }
            }
        }
        $registrace = Auth::rezimRegistrace() !== 'vypnuta'
            ? sprintf("<p class='tlumene'>Nemáte účet? <a href='%s'>Zaregistrujte se</a>.</p>", e(odkaz('/registrace', ['zpet' => $zpet]))) : '';
        $obsah = sprintf("<div class='karta prihlaseni'><h1>Přihlášení</h1>%s<form method='post' action='%s'>
<input type='hidden' name='csrf' value='%s'><input type='hidden' name='zpet' value='%s'>
<label>Přihlašovací jméno <input name='login' autocomplete='username' required autofocus value='%s'></label>
<label>Heslo <input type='password' name='heslo' autocomplete='current-password' required></label>
%s<button>Přihlásit</button></form>%s</div>", $chyba ? "<div class='zprava chyba'>" . e($chyba) . '</div>' : '', e(odkaz('/prihlaseni')),
            e(Auth::csrf()), e($zpet), e($_POST['login'] ?? ''),
            Auth::dniTrvalehoPrihlaseni() > 0 ? sprintf("<label class='zaskrt'><input type='checkbox' name='trvale' value='1'%s> Zůstat přihlášen na tomto zařízení (%d dní)</label>",
                ($_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['trvale'])) ? ' checked' : '', Auth::dniTrvalehoPrihlaseni()) : '',
            $registrace);
        return $this->stranka('Přihlášení', $obsah, 'ucet');
    }

    public function stranka_registrace(array $parametry): string
    {
        $rezim = Auth::rezimRegistrace();
        if ($rezim === 'vypnuta') {
            throw new OdokChyba('Registrace je vypnutá – o účet požádejte správce.');
        }
        if (Auth::uzivatel()) {
            $this->presmeruj('index.php');
        }
        $zpet = self::bezpecny_zpet($parametry['zpet'] ?? ($_POST['zpet'] ?? 'index.php'));
        $chyba = '';
        $v = ['login' => '', 'jmeno' => '', 'email' => ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $v = array_map(function ($x) { return trim((string)$x); }, array_intersect_key($_POST, $v) + $v);
            if (!Auth::overCsrf($_POST['csrf'] ?? null)) {
                $chyba = 'Formulář vypršel, zkuste to znovu.';
            } else {
                $chyba = Auth::registruj($v['login'], $v['jmeno'], $v['email'], (string)($_POST['heslo'] ?? ''),
                    (string)($_POST['heslo2'] ?? ''), (string)($_POST['web'] ?? ''), (int)($_POST['t'] ?? 0)) ?? '';
                if ($chyba === '') {
                    if ($rezim === 'otevrena') {
                        Auth::prihlas($v['login'], (string)$_POST['heslo']);
                        Auth::zprava('Vítejte! Účet je založený a jste přihlášen(a).');
                        $this->presmeruj($zpet);
                    }
                    $obsah = "<div class='karta prihlaseni'><h1>Registrace odeslána</h1><p>Účet <b>" . e($v['login'])
                        . "</b> je založený a čeká na schválení správcem. Přihlásit se půjde, jakmile ho schválí.</p>"
                        . "<p><a class='tlacitko' href='index.php'>Zpět na úvod</a></p></div>";
                    return $this->stranka('Registrace', $obsah, 'ucet');
                }
            }
        }
        $obsah = sprintf("<div class='karta prihlaseni'><h1>Registrace</h1>%s%s<form method='post' action='%s'>
<input type='hidden' name='csrf' value='%s'><input type='hidden' name='zpet' value='%s'><input type='hidden' name='t' value='%d'>
<label>Přihlašovací jméno <input name='login' autocomplete='username' required autofocus pattern='[A-Za-z0-9._@\-]{2,64}' title='písmena bez diakritiky, číslice a . _ @ -' value='%s'></label>
<label>Jméno <input name='jmeno' autocomplete='name' value='%s'></label>
<label>E-mail <input type='email' name='email' autocomplete='email' value='%s'></label>
<label>Heslo (aspoň 8 znaků) <input type='password' name='heslo' autocomplete='new-password' required minlength='8'></label>
<label>Heslo znovu <input type='password' name='heslo2' autocomplete='new-password' required minlength='8'></label>
<label style='position:absolute;left:-9999px' aria-hidden='true'>Nevyplňujte <input name='web' tabindex='-1' autocomplete='off'></label>
<button>Zaregistrovat</button></form><p class='tlumene'>Máte účet? <a href='%s'>Přihlaste se</a>.</p></div>",
            $rezim === 'schvaleni' ? "<p class='tlumene'>Nový účet musí schválit správce.</p>" : '',
            $chyba ? "<div class='zprava chyba'>" . e($chyba) . '</div>' : '', e(odkaz('/registrace')), e(Auth::csrf()), e($zpet), time(),
            e($v['login']), e($v['jmeno']), e($v['email']), e(odkaz('/prihlaseni', ['zpet' => $zpet])));
        return $this->stranka('Registrace', $obsah, 'ucet');
    }

    public function stranka_odhlaseni(array $parametry): string
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::overCsrf($_POST['csrf'] ?? null)) {
            Auth::odhlas();
        }
        $this->presmeruj(Konfig::get('anonymni_cteni') ? 'index.php' : odkaz('/prihlaseni'));
        return '';
    }

    public function stranka_profil(array $parametry): string
    {
        $u = Auth::uzivatel();
        if (!$u) {
            $this->presmeruj(odkaz('/prihlaseni'));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && Auth::overCsrf($_POST['csrf'] ?? null)) {
            $akce = (string)($_POST['akce'] ?? 'heslo');
            if ($akce === 'pravidla') {
                $sada = (string)($_POST['pravidla'] ?? '');
                Db::q('UPDATE {p}uzivatel SET pravidla = ? WHERE id = ?', [SadyPravidel::existuje($sada) ? $sada : null, $u['id']]);
                Auth::zprava('Sada výrazů pro analýzu relevance byla uložena.');
            } elseif ($akce === 'udaje') {
                Db::q('UPDATE {p}uzivatel SET jmeno = ?, email = ? WHERE id = ?',
                    [mb_substr(trim((string)($_POST['jmeno'] ?? '')), 0, 190), mb_substr(trim((string)($_POST['email'] ?? '')), 0, 190), $u['id']]);
                Auth::zprava('Údaje byly uloženy.');
            } else {
                $stare = (string)($_POST['stare'] ?? '');
                $nove = (string)($_POST['nove'] ?? '');
                if (!password_verify($stare, $u['heslo'])) {
                    Auth::zprava('Současné heslo nesouhlasí.', 'chyba');
                } elseif ($ch = Auth::kontrolaHesla($nove)) {
                    Auth::zprava($ch, 'chyba');
                } elseif ($nove !== (string)($_POST['nove2'] ?? '')) {
                    Auth::zprava('Nová hesla se neshodují.', 'chyba');
                } else {
                    Db::q('UPDATE {p}uzivatel SET heslo = ? WHERE id = ?', [password_hash($nove, PASSWORD_DEFAULT), $u['id']]);
                    Auth::obnovOtisk();
                    Auth::zprava('Heslo bylo změněno.');
                }
            }
            $this->presmeruj(odkaz('/profil'));
        }
        $csrf = "<input type='hidden' name='csrf' value='" . e(Auth::csrf()) . "'>";
        try {
            $vyber = SadyPravidel::vyber('pravidla', $u['pravidla']);
            $pravidla = sprintf("<h2>Analýza relevance</h2><form method='post' class='karta formular' action='%s'>%s<input type='hidden' name='akce' value='pravidla'>
<label>Sada výrazů, podle které se dokumenty vyhodnocují %s</label>
<p class='tlumene'>Sady nahrává správce. Sadu lze přepnout i přímo u výsledku analýzy.</p><button>Uložit</button></form>",
                e(odkaz('/profil')), $csrf, $vyber);
        } catch (RelevanceChyba $ch) {
            $pravidla = "<h2>Analýza relevance</h2><div class='chyba'>" . e($ch->getMessage()) . '</div>';
        }
        $obsah = sprintf("<h1>Můj účet</h1>
<form method='post' class='karta formular' action='%s'>%s<input type='hidden' name='akce' value='udaje'>
<table class='kv'><tr><th>Přihlašovací jméno</th><td>%s</td></tr><tr><th>Role</th><td>%s</td></tr></table>
<label>Jméno <input type='text' name='jmeno' value='%s'></label><label>E-mail <input type='email' name='email' value='%s'></label>
<button>Uložit údaje</button></form>
%s
<h2>Změna hesla</h2><form method='post' class='karta formular' action='%s'>%s<input type='hidden' name='akce' value='heslo'>
<label>Současné heslo <input type='password' name='stare' autocomplete='current-password' required></label>
<label>Nové heslo (aspoň 8 znaků) <input type='password' name='nove' autocomplete='new-password' required minlength='8'></label>
<label>Nové heslo znovu <input type='password' name='nove2' autocomplete='new-password' required minlength='8'></label>
<button>Změnit heslo</button></form>", e(odkaz('/profil')), $csrf, e($u['login']), $u['role'] === 'admin' ? 'správce' : 'uživatel',
            e($u['jmeno']), e($u['email']), $pravidla, e(odkaz('/profil')), $csrf);
        return $this->stranka('Můj účet', $obsah, 'ucet');
    }

    /** Volba sady výrazů přímo u výsledku analýzy (JSON). */
    public function akce_pravidla_vyber(array $par): array
    {
        $u = Auth::uzivatel();
        if (!$u) {
            throw new InvalidArgumentException('Sadu výrazů si může vybrat jen přihlášený uživatel.');
        }
        $sada = (string)($par['pravidla'] ?? '');
        Db::q('UPDATE {p}uzivatel SET pravidla = ? WHERE id = ?', [SadyPravidel::existuje($sada) ? $sada : null, $u['id']]);
        return ['ok' => true];
    }

    public function stranka_admin(array $parametry): string
    {
        if (!Auth::jeAdmin()) {
            throw new OdokChyba('Do správy má přístup jen správce.');
        }
        return (new Admin($this))->obsluz($parametry);
    }
}
