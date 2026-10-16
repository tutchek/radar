<?php
/**
 * Esel.php – kontextová data z e-Legislativy k materiálům ODOK. Port esel.py.
 * Datové sady importuje cron do tabulek esel_navrh, esel_cj, esel_faze, esel_stav.
 *
 * Párování s materiálem ODOK (od nejspolehlivějšího): čj OVA → čj předkladatele → podobnost názvu
 * (stejný druh aktu, nad prahem PRAH_NAZEV; podobnost počítá stejný algoritmus jako difflib v Pythonu).
 */

const ESEL_ZAKLAD = 'https://opendata.eselpoint.gov.cz/datove-sady-elegislativa/';
const URL_ESEL_PORTAL = 'https://e-legislativa.gov.cz/portal/navrh/{id}';
const URL_ELEGISLATIVA = 'https://portal.e-legislativa.gov.cz/portal/navrh/{id}';
/** e-Sbírka: metadata všech předpisů (názvy, zkratky) – datová sada 006 */
const URL_ESBIRKA_METADATA = 'https://opendata.eselpoint.gov.cz/datove-sady-esbirka/006PravniAktMetadata.json.gz';
const URL_ESBIRKA_VAZBY = 'https://opendata.eselpoint.gov.cz/datove-sady-esbirka/007PravniAktKonsolidacniVazba.json.gz';
const URL_ESBIRKA_DRUH_AKTU = 'https://opendata.eselpoint.gov.cz/datove-sady-esbirka/041CiselnikDruhAktu.json.gz';
const ESEL_SADY = [
    'navrhy' => '100NavrhAktuNavrh', 'faze' => '102NavrhAktuFaze', 'stavy' => '103NavrhAktuStavProcesu',
    'vrstvy' => '104NavrhAktuVrstvaNavrhu', 'metadata' => '105NavrhAktuMetadataAktu',
    'ucinnost' => '110NavrhAktuUcinnostAktu', 'globalni' => '180CiselnikGlobalni',
];
const DRUH_PODLE_TYPU_ODOK = ['NV' => 'VYHLASKA', 'NNV' => 'NARIZVLA', 'NZ' => 'ZAKON'];
const PRAH_NAZEV = 0.86;

/** '2025-09-24T13:33:48' / '2025-09-10' → 'Y-m-d H:i:s', jinak null. */
function esel_cas($h): ?string
{
    if (!is_string($h) || $h === '') {
        return null;
    }
    $s = substr(trim($h), 0, 19);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$s 00:00:00" : null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00') : null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2})$/', $s, $m)) {
        return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:00:00";
    }
    return null;
}

function norm_ova($cj): ?string
{
    if (!preg_match('/^\s*(\d+)\s*\/\s*(\d{2,4})/u', (string)($cj ?? ''), $m)) {
        return null;
    }
    return sprintf('%d/%02d', (int)$m[1], (int)$m[2] % 100);
}

function norm_cj($cj): ?string
{
    if (!py_bool($cj)) {
        return null;
    }
    $t = str_replace(' ', '', mb_strtoupper(bez_diakritiky((string)$cj)));
    if (!preg_match('/^([A-Z]+)[-\/]?(\d+)\/(\d{4})/', $t, $m)) {
        return null;
    }
    return sprintf('%s-%d/%s', $m[1], (int)$m[2], $m[3]);
}

function norm_nazev($nazev): string
{
    $t = bez_diakritiky($nazev ?? '');
    $t = preg_replace('/^\s*navrh\s+/u', '', $t);
    $t = preg_replace('/,?\s*ve zneni pozdejsich predpisu/u', '', $t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

function bez_poradi($nazev): string
{
    return trim(preg_replace('/\s*\(\d+\)\s*$/u', '', (string)($nazev ?? '')));
}

/** Port difflib.SequenceMatcher (isjunk=None, autojunk=True) – jen ratio() a horní odhady. */
class SequenceMatcher
{
    private $a, $b, $b2j, $la, $lb;

    public function __construct(string $a, string $b)
    {
        $this->a = $a === '' ? [] : mb_str_split($a);
        $this->b = $b === '' ? [] : mb_str_split($b);
        $this->la = count($this->a);
        $this->lb = count($this->b);
        $b2j = [];
        foreach ($this->b as $i => $ch) {
            $b2j[$ch][] = $i;
        }
        $n = $this->lb;
        if ($n >= 200) {
            $ntest = intdiv($n, 100) + 1;
            foreach ($b2j as $ch => $idx) {
                if (count($idx) > $ntest) {
                    unset($b2j[$ch]);
                }
            }
        }
        $this->b2j = $b2j;
    }

    public static function real_quick_ratio(int $la, int $lb): float
    {
        return ($la + $lb) ? 2.0 * min($la, $lb) / ($la + $lb) : 1.0;
    }

    public function quick_ratio(): float
    {
        $pocet = [];
        foreach ($this->b as $ch) {
            $pocet[$ch] = ($pocet[$ch] ?? 0) + 1;
        }
        $shody = 0;
        foreach ($this->a as $ch) {
            if (($pocet[$ch] ?? 0) > 0) {
                $pocet[$ch]--;
                $shody++;
            }
        }
        return ($this->la + $this->lb) ? 2.0 * $shody / ($this->la + $this->lb) : 1.0;
    }

    private function nejdelsi(int $alo, int $ahi, int $blo, int $bhi): array
    {
        $a = $this->a;
        $b = $this->b;
        [$besti, $bestj, $bestsize] = [$alo, $blo, 0];
        $j2len = [];
        for ($i = $alo; $i < $ahi; $i++) {
            $nove = [];
            foreach ($this->b2j[$a[$i]] ?? [] as $j) {
                if ($j < $blo) {
                    continue;
                }
                if ($j >= $bhi) {
                    break;
                }
                $k = $nove[$j] = ($j2len[$j - 1] ?? 0) + 1;
                if ($k > $bestsize) {
                    [$besti, $bestj, $bestsize] = [$i - $k + 1, $j - $k + 1, $k];
                }
            }
            $j2len = $nove;
        }
        while ($besti > $alo && $bestj > $blo && $a[$besti - 1] === $b[$bestj - 1]) {
            [$besti, $bestj, $bestsize] = [$besti - 1, $bestj - 1, $bestsize + 1];
        }
        while ($besti + $bestsize < $ahi && $bestj + $bestsize < $bhi && $a[$besti + $bestsize] === $b[$bestj + $bestsize]) {
            $bestsize++;
        }
        return [$besti, $bestj, $bestsize];
    }

    public function ratio(): float
    {
        $shody = 0;
        $fronta = [[0, $this->la, 0, $this->lb]];
        while ($fronta) {
            [$alo, $ahi, $blo, $bhi] = array_pop($fronta);
            [$i, $j, $k] = $this->nejdelsi($alo, $ahi, $blo, $bhi);
            if ($k) {
                $shody += $k;
                if ($alo < $i && $blo < $j) {
                    $fronta[] = [$alo, $i, $blo, $j];
                }
                if ($i + $k < $ahi && $j + $k < $bhi) {
                    $fronta[] = [$i + $k, $ahi, $j + $k, $bhi];
                }
            }
        }
        return ($this->la + $this->lb) ? 2.0 * $shody / ($this->la + $this->lb) : 1.0;
    }
}

/** Python round() (zaokrouhlení na sudou u poloviny) pro kladná čísla na n míst. */
function py_round(float $x, int $n = 0): float
{
    return round($x, $n, PHP_ROUND_HALF_EVEN);
}

class NavrhEsel
{
    public $r;
    private $stavy = null;

    public function __construct(array $r)
    {
        $this->r = $r;
    }

    public function __get($n)
    {
        $r = $this->r;
        switch ($n) {
            case 'id': return $r['id'];
            case 'url': return $r['id'] !== null ? vzor(URL_ESEL_PORTAL, ['id' => $r['id']]) : null;
            case 'url_elegislativa': return $r['id'] !== null ? vzor(URL_ELEGISLATIVA, ['id' => $r['id']]) : null;
            case 'identifikator': return $r['identifikator'];
            case 'nazev': return $r['nazev'];
            case 'nazev_aktu': return py_or($r['nazev_aktu'], $r['nazev']);
            case 'cj_ova': return $r['cj_ova'];
            case 'druh': return $r['druh'];
            case 'typ_procesu': return $r['typ_procesu'];
            case 'citace': return $r['citace'];
            case 'datum_vyhlaseni': return $r['datum_vyhlaseni'];
            case 'datum_schvaleni': return $r['datum_schvaleni'];
            case 'ucinnost': return $r['ucinnost'];
            case 'kompatibilita_eu': return $r['kompatibilita_eu'];
            case 'termin_implementace': return $r['termin_implementace'];
            case 'cj_predkladatele': return $r['cj_predkladatele'] ? (array)json_decode($r['cj_predkladatele'], true) : [];
            case 'aktualni_stav': return $this->aktualni_stav();
        }
        throw new LogicException("NavrhEsel nemá vlastnost $n");
    }

    /** Všechny stavy procesu přes všechny fáze, chronologicky: [['nazev','od','do','faze'], …]. */
    public function stavy(): array
    {
        if ($this->stavy === null) {
            $this->stavy = Db::vse('SELECT s.nazev, s.od, s.do, f.nazev AS faze FROM {p}esel_stav s
                JOIN {p}esel_faze f ON f.id = s.faze_id AND f.posledni = 1
                WHERE s.navrh_id = ? ORDER BY s.od, f.od, f.idx, s.idx', [$this->r['id']]);
        }
        return $this->stavy;
    }

    public function datum_stavu(array $nazvy, bool $posledni = false): ?string
    {
        $d = [];
        foreach ($this->stavy() as $s) {
            if (in_array(bez_poradi($s['nazev']), $nazvy, true) && $s['od']) {
                $d[] = $s['od'];
            }
        }
        if (!$d) {
            return null;
        }
        return $posledni ? end($d) : $d[0];
    }

    private function aktualni_stav(): ?string
    {
        $st = array_values(array_filter($this->stavy(), function ($s) { return $s['do'] === null; })) ?: $this->stavy();
        return $st ? bez_poradi(end($st)['nazev']) : null;
    }

    public function pro_plpv(): array
    {
        return [
            'postupovano_vlade' => $this->datum_stavu(['Postupováno vládě']),
            'jednani_vlady' => py_or($this->datum_schvaleni, $this->datum_stavu(['Zapracování závěrů vlády'])),
            'lrv_predlozeno' => $this->datum_stavu(['Příprava pro jednání OKOM a PK LRV']),
            'lrv_stanoviska' => $this->datum_stavu(['Stanoviska OKOM a PK LRV']),
            'postupovano_vydavateli' => $this->datum_stavu(['Postupováno vydavateli'], true),
            'vyhlaseno' => py_or($this->datum_vyhlaseni, $this->datum_stavu(['Právní akt vyhlášen'])),
            'citace' => $this->citace, 'ucinnost' => $this->ucinnost,
            'stav' => $this->aktualni_stav, 'eu' => $this->kompatibilita_eu,
            'termin_implementace' => $this->termin_implementace,
        ];
    }
}

class Esel
{
    private $podle_druhu = [];

    public static function maData(): bool
    {
        try {
            return (int)Db::hodnota('SELECT COUNT(*) FROM {p}esel_navrh') > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function pocet(): int
    {
        return (int)Db::hodnota('SELECT COUNT(*) FROM {p}esel_navrh');
    }

    private static function navrh(?array $r): ?NavrhEsel
    {
        return $r ? new NavrhEsel($r) : null;
    }

    public function navrh_podle_id($id): ?NavrhEsel
    {
        return self::navrh(Db::radek('SELECT * FROM {p}esel_navrh WHERE id = ?', [$id]));
    }

    /** Návrh k materiálu ODOK: [NavrhEsel, způsob, jistota] nebo null. */
    public function k_materialu($m, bool $jen_rychle = false): ?array
    {
        $vstup = [$m->typ_id, $m->cj_ova, $m->cislo_jednaci, $m->nazev];
        $verze = Db::hodnota("SELECT hash FROM {p}zdroj WHERE klic = 'esel'");
        $klic = 'esel_par:' . $verze . ':' . json_encode($vstup, JSON_UNESCAPED_UNICODE);
        $ulozeno = kv_get($klic);
        if (is_array($ulozeno)) {
            if (!$ulozeno) {
                return null;
            }
            $n = $this->navrh_podle_id($ulozeno[0]);
            if ($n) {
                return [$n, $ulozeno[1], $ulozeno[2]];
            }
        }
        $v = $this->spocitej($m, $jen_rychle);
        if ($v || !$jen_rychle) {      // „nenalezeno“ bez porovnání názvů se neukládá – detail ho dopočítá
            kv_set($klic, $v ? [$v[0]->id, $v[1], $v[2]] : [], 86400 * 7);
        }
        return $v;
    }

    private function spocitej($m, bool $jen_rychle = false): ?array
    {
        $druh = DRUH_PODLE_TYPU_ODOK[$m->typ_id ?? ''] ?? null;
        $k = norm_ova($m->cj_ova);
        if ($k) {
            $n = self::navrh(Db::radek('SELECT * FROM {p}esel_navrh WHERE norm_ova = ? ORDER BY poradi LIMIT 1', [$k]));
            if ($n && ($druh === null || $n->druh === $druh || $n->druh === null)) {
                return [$n, 'čj OVA', 1.0];
            }
        }
        $k = norm_cj($m->cislo_jednaci);
        if ($k) {
            $n = self::navrh(Db::radek('SELECT n.* FROM {p}esel_cj c JOIN {p}esel_navrh n ON n.id = c.navrh_id
                WHERE c.norm = ? ORDER BY c.poradi LIMIT 1', [$k]));
            if ($n && ($druh === null || $n->druh === $druh || $n->druh === null)) {
                return [$n, 'čj předkladatele', 0.95];
            }
        }
        if ($druh === null || $jen_rychle) {
            return null;
        }
        $cil = norm_nazev($m->nazev ?? '');
        if ($cil === '') {
            return null;
        }
        if (!isset($this->podle_druhu[$druh])) {
            $this->podle_druhu[$druh] = Db::vse('SELECT id, norm_nazev FROM {p}esel_navrh WHERE druh = ? ORDER BY poradi', [$druh]);
        }
        $la = mb_strlen($cil);
        $nejlepsi = null;
        $skore = 0.0;
        foreach ($this->podle_druhu[$druh] as $r) {
            $nazev = (string)$r['norm_nazev'];
            if ($nazev === '') {
                continue;
            }
            // horní odhady podobnosti – kandidát, který nemůže překonat dosavadní nejlepší ani práh, se přeskočí
            // (výsledek je stejný jako bez přeskakování: vrací se jen nejlepší shoda nad prahem)
            $odhad = SequenceMatcher::real_quick_ratio($la, mb_strlen($nazev));
            if ($odhad <= $skore || $odhad < PRAH_NAZEV) {
                continue;
            }
            $sm = new SequenceMatcher($cil, $nazev);
            $q = $sm->quick_ratio();
            if ($q <= $skore || $q < PRAH_NAZEV) {
                continue;
            }
            $rr = $sm->ratio();
            if ($rr > $skore) {
                $nejlepsi = $r['id'];
                $skore = $rr;
            }
        }
        if ($nejlepsi !== null && $skore >= PRAH_NAZEV) {
            return [$this->navrh_podle_id($nejlepsi), 'podobnost názvu', py_round($skore, 2)];
        }
        return null;
    }
}

/**
 * Předpisy, které podle názvu návrh mění nebo ruší: [[číslo, rok], …]. Bere čísla za slovesem „mění / ruší / zrušuje“
 * až do první vztažné věty („, kterou se …“ – ta už popisuje měněný předpis) a vynechává části „ve znění …“.
 * Záloha pro návrhy, u kterých e-Legislativa nemá úplné znění s vyznačením změn.
 */
function predpisy_z_nazvu(?string $nazev): array
{
    $t = preg_replace('/\s+/u', ' ', (string)$nazev);
    if (!preg_match('/(?<!\pL)(mění|ruší|zrušuje|zrušují|změně|změnách|zrušení)(?!\pL)/u', $t, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $t = substr($t, $m[0][1]);
    if (preg_match('/,\s*(kter|jímž|jíž|jimiž)/u', $t, $k, PREG_OFFSET_CAPTURE, 1)) {
        $t = substr($t, 0, $k[0][1]);
    }
    $t = preg_replace('/ve\s+znění[^,;]*/u', ' ', $t);
    preg_match_all('~(?<![\d/])(\d{1,4})\s*/\s*(\d{4})(?=\s*Sb)~u', $t, $mm, PREG_SET_ORDER);
    $v = [];
    foreach ($mm as $x) {
        $v[$x[1] . '/' . $x[2]] = [(int)$x[1], (int)$x[2]];
    }
    return array_values($v);
}

/** Novelizační vazby z e-Legislativy (úplné znění v návrhu, záložně název) a z e-Sbírky. */
class Novely
{
    /** Návrhy v e-Legislativě, které předpis mění: [['navrh' => NavrhEsel, 'zdroj' => 'úplné znění'|'název'], …]. */
    public static function navrhy_menici(int $c, int $r): array
    {
        $v = [];
        try {
            foreach (Db::vse('SELECT n.* FROM {p}esel_novela z JOIN {p}esel_navrh n ON n.id = z.navrh_id
                              WHERE z.predpis_cislo = ? AND z.predpis_rok = ?', [$c, $r]) as $row) {
                $v[$row['id']] = ['navrh' => new NavrhEsel($row), 'zdroj' => 'úplné znění'];
            }
        } catch (PDOException $e) {
        }
        try {
            $vzor = "%$c/$r%";
            foreach (Db::vse('SELECT * FROM {p}esel_navrh WHERE nazev_aktu LIKE ? OR nazev LIKE ?', [$vzor, $vzor]) as $row) {
                if (isset($v[$row['id']])) {
                    continue;
                }
                $n = new NavrhEsel($row);
                if (in_array([$c, $r], predpisy_z_nazvu($n->nazev_aktu), true)) {
                    $v[$row['id']] = ['navrh' => $n, 'zdroj' => 'název'];
                }
            }
        } catch (PDOException $e) {
        }
        $v = array_values($v);
        usort($v, function ($a, $b) { return strcmp((string)$b['navrh']->identifikator, (string)$a['navrh']->identifikator); });
        return $v;
    }

    /** Předpisy, které návrh mění: [[číslo, rok, zdroj], …]. */
    public static function predpisy_navrhu(?NavrhEsel $n, ?string $nazev = null): array
    {
        $v = [];
        if ($n !== null) {
            try {
                foreach (Db::vse('SELECT predpis_cislo, predpis_rok FROM {p}esel_novela WHERE navrh_id = ? ORDER BY predpis_rok, predpis_cislo', [$n->id]) as $x) {
                    $v[$x['predpis_cislo'] . '/' . $x['predpis_rok']] = [(int)$x['predpis_cislo'], (int)$x['predpis_rok'], 'úplné znění v e-Legislativě'];
                }
            } catch (PDOException $e) {
            }
        }
        if (!$v) {
            foreach (array_merge($n !== null ? predpisy_z_nazvu($n->nazev_aktu) : [], predpisy_z_nazvu($nazev)) as [$c, $r]) {
                $v["$c/$r"] = $v["$c/$r"] ?? [$c, $r, 'název návrhu'];
            }
        }
        return array_values($v);
    }

    /** Vyhlášené předpisy, které předpis mění / ruší (e-Sbírka): řádky s novela_cislo, novela_rok, rusi, nazev, druh … */
    public static function vyhlasene_novely(int $c, int $r): array
    {
        try {
            return Db::vse("SELECT z.novela_cislo, z.novela_rok, z.rusi, p.nazev, p.nazev_citace, p.druh, p.druh_nazev, p.schvaleno
                FROM {p}esb_novela z LEFT JOIN {p}esb_predpis p ON p.sbirka = 'sb' AND p.rok = z.novela_rok AND p.cislo = z.novela_cislo
                WHERE z.predpis_cislo = ? AND z.predpis_rok = ? ORDER BY z.novela_rok DESC, z.novela_cislo DESC", [$c, $r]);
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Předpisy, které vyhlášený předpis mění (e-Sbírka): [[číslo, rok, ruší], …]. */
    public static function menene_vyhlasenym(int $c, int $r): array
    {
        try {
            return array_map(function ($x) { return [(int)$x['predpis_cislo'], (int)$x['predpis_rok'], (bool)$x['rusi']]; },
                Db::vse('SELECT predpis_cislo, predpis_rok, rusi FROM {p}esb_novela WHERE novela_cislo = ? AND novela_rok = ?
                         ORDER BY predpis_rok, predpis_cislo', [$c, $r]));
        } catch (PDOException $e) {
            return [];
        }
    }
}
