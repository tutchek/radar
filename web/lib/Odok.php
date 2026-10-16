<?php
/**
 * Odok.php – data ODOK / VeKLEP (eKLEP). Port odok.py.
 *
 * Dataset (materiály po ročnících) importuje cron do tabulky odok_material (jeden řádek = materiál
 * v jednom ročníku, surová data jako komprimovaný JSON). Verze a připomínky materiálu se stahují
 * při zobrazení z portálu ODOK a ukládají se do cache v databázi.
 */

if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) {
                return false;
            }
        }
        return true;
    }
}

const DOMENY_DATASETU = ['https://www.odok.cz', 'https://www.odok.gov.cz'];
const URL_DATASET_JSON = '{domena}/opendata/dataset/odok/requests_{rok}.json';
const URL_DATASET_ZIP = '{domena}/opendata/dataset/odok/requests_zip_{rok}.zip';
const URL_VERZE = 'https://www.odok.gov.cz/portal/veklep/material/verze/{pid}/json';
const URL_PRIPOMINKY = 'https://www.odok.gov.cz/portal/veklep/material/pripominky/{pid}/json';
const URL_DETAIL_WEB = 'https://www.odok.gov.cz/portal/veklep/material/{pid}/';
const URL_DOKUMENT = 'https://www.odok.cz/portal/services/download/attachment/{pid}/';
const STAV_PUBLIKOVANO = 'K';

const KLICE_PID = ['pid', 'materialPid', 'idMaterialu', 'materialId', 'id'];
const KLICE_CJ = ['id_no_ext', 'cisloJednaci', 'cj', 'cjPredkladatele', 'cisloJednaciPredkladatele',
    'spisovaZnacka', 'evidencniCislo', 'cisloJednaciMaterialu',
    'reference_number', 'ref_number', 'file_number', 'number_of_reference'];
const KLICE_NAZEV = ['title', 'nazev', 'nazevMaterialu', 'name', 'predmet', 'subject'];
const CESTA_ORGANIZACE_ID = 'author.organization.id';
const CESTA_ORGANIZACE_NAZEV = 'author.organization.name';
const KLICE_PREDKLADATEL = ['predkladatel', 'predkladatelNazev', 'gestor', 'resort',
    'ustredniOrgan', 'zadavatel', 'autor', 'organizace'];
const KLICE_STAV = ['stav', 'stavMaterialu', 'status', 'faze', 'state', 'phase'];
const KLICE_TYP = ['typ', 'typMaterialu', 'druh', 'druhMaterialu', 'type', 'kind'];
const KLICE_DATUM = ['datumVlozeni', 'datumZalozeni', 'datum', 'vlozeno', 'zalozeno',
    'datumZahajeni', 'date_created', 'created', 'created_at', 'date',
    'date_published', 'date_modified'];

// ---------------------------------------------------------------- JSON jako v Pythonu

/** dict v Pythonu (JSON objekt). Prázdné pole se bere jako obojí. */
function je_dict($x): bool
{
    return is_array($x) && ($x === [] || !array_is_list($x));
}

function je_list($x): bool
{
    return is_array($x) && array_is_list($x);
}

/** Pravdivost hodnoty jako v Pythonu. */
function py_bool($v): bool
{
    return !($v === null || $v === false || $v === '' || $v === 0 || $v === 0.0 || $v === []);
}

/** Python „a or b or …“ – první pravdivá hodnota, jinak poslední. */
function py_or(...$hodnoty)
{
    $v = null;
    foreach ($hodnoty as $v) {
        if (py_bool($v)) {
            return $v;
        }
    }
    return $v;
}

function _norm_klic($k): string
{
    return preg_replace('/[^a-z0-9]/', '', bez_diakritiky((string)$k));
}

function _najdi_klic($d, array $kandidati)
{
    if (!je_dict($d)) {
        return null;
    }
    $mapa = [];
    foreach ($d as $k => $_) {
        $mapa[_norm_klic($k)] = $k;
    }
    foreach ($kandidati as $kand) {
        $nk = _norm_klic($kand);
        if (array_key_exists($nk, $mapa)) {
            $v = $d[$mapa[$nk]];
            if (!($v === null || $v === '' || $v === [])) {
                return $v;
            }
        }
    }
    return null;
}

function _najdi_hluboko($d, array $kandidati, int $hloubka = 2)
{
    $v = _najdi_klic($d, $kandidati);
    if ($v !== null || $hloubka <= 0 || !je_dict($d)) {
        return $v;
    }
    foreach ($d as $hodnota) {
        if (je_dict($hodnota) && $hodnota !== []) {
            $v = _najdi_hluboko($hodnota, $kandidati, $hloubka - 1);
            if ($v !== null) {
                return $v;
            }
        }
    }
    return null;
}

function _je_skalar($v): bool
{
    return is_string($v) || is_int($v) || is_float($v) || is_bool($v);
}

function _hodnota_cesty($d, string $cesta, bool $i_vnorene = true)
{
    $puvodni = $d;
    foreach (explode('.', $cesta) as $cast) {
        if (!je_dict($d)) {
            $d = null;
            break;
        }
        $d = $d[$cast] ?? null;
    }
    if (_je_skalar($d)) {
        return $d;
    }
    if ($i_vnorene && je_dict($puvodni)) {
        foreach ($puvodni as $v) {
            if (je_dict($v)) {
                $h = _hodnota_cesty($v, $cesta, false);
                if ($h !== null) {
                    return $h;
                }
            }
        }
    }
    return null;
}

function _prvni_cesta($d, string ...$cesty)
{
    foreach ($cesty as $c) {
        $v = _hodnota_cesty($d, $c, false);
        if (!($v === null || $v === '')) {
            return $v;
        }
    }
    return null;
}

/** 'yyyy-mm-ddThh:mm:ssZ' → 'Y-m-d H:i:s' v místním čase, jinak null. */
function parsuj_cas_odok($hodnota): ?string
{
    if (!is_string($hodnota) || $hodnota === '') {
        return null;
    }
    $h = trim($hodnota);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}(?::?\d{2}(?::?\d{2}(?:[.,]\d+)?)?)?)?(Z|[+-]\d{2}(?::?\d{2})?)?$/', $h, $m)) {
        return null;
    }
    try {
        $t = new DateTime(str_replace('Z', '+00:00', $h), isset($m[1]) ? null : new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return null;
    }
    $t->setTimezone(new DateTimeZone(date_default_timezone_get()));
    return $t->format('Y-m-d H:i:s');
}

function _jako_text($hodnota): string
{
    if ($hodnota === null) {
        return '';
    }
    if (je_dict($hodnota) && $hodnota !== []) {
        $v = _najdi_klic($hodnota, ['nazev', 'name', 'zkratka', 'text', 'hodnota', 'value']);
        if ($v !== null && !is_array($v)) {
            return py_str($v);
        }
        $casti = [];
        foreach ($hodnota as $x) {
            if (is_string($x) || is_int($x) || is_float($x) || is_bool($x)) {
                $casti[] = _jako_text($x);
            }
        }
        return implode(', ', $casti);
    }
    if (is_array($hodnota)) {
        return implode(', ', array_map('_jako_text', $hodnota));
    }
    return py_str($hodnota);
}

/** Všechny řetězcové (a celočíselné) hodnoty ve vnořené struktuře. */
function _vsechny_texty($hodnota, array &$vystup): void
{
    if (is_array($hodnota)) {
        foreach ($hodnota as $v) {
            _vsechny_texty($v, $vystup);
        }
    } elseif (is_string($hodnota) || is_int($hodnota) || is_bool($hodnota)) {
        $vystup[] = py_str($hodnota);
    }
}

function _vypada_jako_material($d): bool
{
    if (!je_dict($d) || $d === []) {
        return false;
    }
    if (array_key_exists('author', $d) || _najdi_klic($d, KLICE_PREDKLADATEL) !== null) {
        return true;
    }
    $n = 0;
    foreach ($d as $v) {
        if (je_dict($v) && is_array($v) && array_key_exists('author', $v)) {
            $n++;
        }
    }
    return $n === 1;
}

function _najdi_materialy($data, int $hloubka = 4): array
{
    if ($hloubka < 0) {
        return [];
    }
    if (je_list($data) && $data !== []) {
        $materialy = array_values(array_filter($data, '_vypada_jako_material'));
        if ($materialy) {
            return $materialy;
        }
        $vysledek = [];
        foreach ($data as $x) {
            foreach (_najdi_materialy($x, $hloubka - 1) as $m) {
                $vysledek[] = $m;
            }
        }
        return $vysledek;
    }
    if (je_dict($data) && $data !== []) {
        if (_vypada_jako_material($data)) {
            return [$data];
        }
        $materialy = array_values(array_filter($data, '_vypada_jako_material'));
        if ($materialy) {
            return $materialy;
        }
        foreach ($data as $v) {
            if (je_list($v) && $v !== []) {
                $materialy = array_values(array_filter($v, '_vypada_jako_material'));
                if ($materialy) {
                    return $materialy;
                }
            }
        }
        $vysledek = [];
        foreach ($data as $v) {
            if (is_array($v)) {
                foreach (_najdi_materialy($v, $hloubka - 1) as $m) {
                    $vysledek[] = $m;
                }
            }
        }
        return $vysledek;
    }
    return [];
}

function _najdi_seznam_zaznamu($data): array
{
    if (je_list($data)) {
        return $data;
    }
    if (je_dict($data)) {
        $v = _najdi_klic($data, ['materialy', 'material', 'requests', 'data', 'items', 'zaznamy', 'polozky', 'results']);
        if (je_list($v)) {
            return $v;
        }
        $nejdelsi = null;
        foreach ($data as $v) {
            if (je_list($v) && $v && je_dict($v[0])) {
                if ($nejdelsi === null || count($v) > count($nejdelsi)) {
                    $nejdelsi = $v;
                }
            }
        }
        if ($nejdelsi !== null) {
            return $nejdelsi;
        }
        foreach ($data as $v) {
            if (je_dict($v) && $v !== []) {
                $s = _najdi_seznam_zaznamu($v);
                if ($s) {
                    return $s;
                }
            }
        }
    }
    return [];
}

/** Všechny slovníky ve vnořené struktuře (do hloubky, v pořadí výskytu). */
function _dicty($data, array &$vystup): void
{
    if (je_dict($data)) {
        $vystup[] = $data;
        foreach ($data as $v) {
            if (is_array($v)) {
                _dicty($v, $vystup);
            }
        }
    } elseif (is_array($data)) {
        foreach ($data as $v) {
            if (is_array($v)) {
                _dicty($v, $vystup);
            }
        }
    }
}

function vypln_url(string $vzor, array $hodnoty): ?string
{
    $chybi = false;
    $u = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($hodnoty, &$chybi) {
        if (!array_key_exists($m[1], $hodnoty)) {
            $chybi = true;
            return '';
        }
        return py_str($hodnoty[$m[1]]);
    }, $vzor);
    return $chybi ? null : $u;
}

// ---------------------------------------------------------------- Odok

class Odok
{
    /** @var int[] */
    public $roky;
    private $poradi = null;       // id ponechaných řádků v pořadí jako v Pythonu (dict: pořadí prvního výskytu)
    private $pozice = null;       // id → pozice

    public function __construct($roky = null)
    {
        if ($roky === null) {
            $roky = [(int)date('Y')];
        } elseif (!is_array($roky)) {
            $roky = [(int)$roky];
        }
        $this->roky = array_values(array_map('intval', $roky));
    }

    const SLOUPCE = 'id, pid, rok, poradi, nazev, cj, cj_ova, predkladatel, predkladatel_zkratka, typ, typ_id, stav, stav_id, datum, datum_vytvoreni, datum_autorizace';

    private function inRoky(): string
    {
        return Db::in($this->roky ?: [0]);
    }

    private function roky(): array
    {
        return $this->roky ?: [0];
    }

    /**
     * Kostra výběru: materiál ve více ročnících se bere jednou – platí jeho poslední výskyt (nejnovější
     * ročník), pořadí je podle prvního výskytu. Načítají se jen id, takže stačí i na všechny ročníky.
     */
    private function kostra(): void
    {
        if ($this->poradi !== null) {
            return;
        }
        $st = Db::q('SELECT id, pid FROM {p}odok_material WHERE rok IN (' . $this->inRoky() . ') ORDER BY rok, poradi', $this->roky());
        $podle = [];
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $podle[$r[1] !== null && $r[1] !== '' ? 'p:' . $r[1] : 'i:' . $r[0]] = (int)$r[0];
        }
        $this->poradi = array_values($podle);
        $this->pozice = array_flip($this->poradi);
    }

    /** Řádky (bez surových dat) pro zadaná id v pořadí výběru. */
    private function radky(array $ids, string $sloupce = self::SLOUPCE): array
    {
        $this->kostra();
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), function ($i) { return isset($this->pozice[$i]); }));
        usort($ids, function ($a, $b) { return $this->pozice[$a] <=> $this->pozice[$b]; });
        $podle = [];
        foreach (array_chunk($ids, 1000) as $kus) {
            foreach (Db::vse("SELECT $sloupce FROM {p}odok_material WHERE id IN (" . Db::in($kus) . ')', $kus) as $r) {
                $podle[(int)$r['id']] = $r;
            }
        }
        $v = [];
        foreach ($ids as $i) {
            if (isset($podle[$i])) {
                $v[] = $podle[$i];
            }
        }
        return $v;
    }

    /** Všechny ponechané řádky výběru. */
    public function zaznamy(string $sloupce = self::SLOUPCE): array
    {
        $this->kostra();
        return $this->radky($this->poradi, $sloupce);
    }

    public function pocet(): int
    {
        $this->kostra();
        return count($this->poradi);
    }

    /** @return Material[] */
    public function materialy(): array
    {
        return array_map(function ($r) { return new Material($r, $this); }, $this->zaznamy());
    }

    private function prvni(array $ids): ?array
    {
        $this->kostra();
        $nej = null;
        foreach ($ids as $id) {
            $id = (int)$id;
            if (isset($this->pozice[$id]) && ($nej === null || $this->pozice[$id] < $this->pozice[$nej])) {
                $nej = $id;
            }
        }
        return $nej === null ? null : ($this->radky([$nej])[0] ?? null);
    }

    /** Materiál podle PID nebo čísla jednacího (předkladatele nebo ÚV). */
    public function material($identifikator): Material
    {
        $klic = norm_id($identifikator);
        $z = null;
        if ($klic !== '') {
            $z = $this->prvni(Db::sloupec('SELECT id FROM {p}odok_material WHERE pid = ? AND rok IN (' . $this->inRoky() . ')',
                array_merge([$klic], $this->roky())));
            if ($z === null) {
                $z = $this->prvni(Db::sloupec('SELECT material_id FROM {p}odok_cj WHERE norm = ? AND rok IN (' . $this->inRoky() . ')',
                    array_merge([$klic], $this->roky())));
            }
            if ($z === null) {
                $z = $this->prvni(Db::sloupec('SELECT id FROM {p}odok_material WHERE rok IN (' . $this->inRoky() . ') AND idtexty LIKE ?',
                    array_merge($this->roky(), ['%' . "\n" . self::like($klic) . "\n" . '%'])));
            }
        }
        if ($z !== null) {
            return new Material($z, $this);
        }
        if (preg_match('/^[A-Z0-9]{12}$/', $klic)) {
            return new Material(['pid' => $klic, 'data' => ['pid' => $klic]], $this);
        }
        throw new OdokChyba(sprintf("Materiál '%s' nenalezen (roky %s).", $identifikator, '[' . implode(', ', $this->roky) . ']'));
    }

    public static function like(string $s): string
    {
        return addcslashes($s, '\\%_');
    }

    /** Fulltext bez ohledu na diakritiku a velikost písmen (ve všech hodnotách materiálu). */
    public function hledej(string $text): array
    {
        $vzor = vzor_hledani($text);     // „zákon, který“ najde i „návrh zákona, kterým“
        $ids = Db::sloupec('SELECT id FROM {p}odok_material WHERE rok IN (' . $this->inRoky() . ') AND '
            . ($vzor === null ? 'hledani LIKE ?' : 'hledani REGEXP ?'),
            array_merge($this->roky(), [$vzor === null ? '%' . self::like(bez_diakritiky($text)) . '%' : $vzor]));
        return array_map(function ($r) { return new Material($r, $this); }, $this->radky($ids));
    }

    public function materialy_ministerstva(string $ministerstvo): array
    {
        $this->kostra();
        $dotaz = norm_id($ministerstvo);
        $ids = [];
        foreach (Db::vse('SELECT id, predkladatel_zkratka FROM {p}odok_material WHERE rok IN (' . $this->inRoky() . ')', $this->roky()) as $r) {
            if (norm_id($r['predkladatel_zkratka']) === $dotaz) {
                $ids[] = $r['id'];
            }
        }
        $v = $this->radky($ids);
        if (!$v) {
            $text = trim(bez_diakritiky($ministerstvo));
            if ($text !== '') {
                foreach (Db::vse('SELECT id, predkladatel FROM {p}odok_material WHERE rok IN (' . $this->inRoky() . ')', $this->roky()) as $r) {
                    if (strpos(bez_diakritiky($r['predkladatel']), $text) !== false) {
                        $ids[] = $r['id'];
                    }
                }
                $v = $this->radky($ids);
            }
        }
        return array_map(function ($r) { return new Material($r, $this); }, $v);
    }

    /** {"zkratka\x00název": počet} – přehled předkladatelů. */
    public function predkladatele(): array
    {
        $c = [];
        foreach ($this->zaznamy('id, predkladatel, predkladatel_zkratka') as $r) {
            $k = (py_bool($r['predkladatel_zkratka']) ? $r['predkladatel_zkratka'] : '?') . "\x00"
                . (py_bool($r['predkladatel']) ? $r['predkladatel'] : '(neuvedeno)');
            $c[$k] = ($c[$k] ?? 0) + 1;
        }
        return $c;
    }

    public static function verze(string $pid, bool $vynutit = false)
    {
        $pid = norm_id($pid);
        return self::json(Http::sCache(str_replace('{pid}', $pid, URL_VERZE), (float)Konfig::get('platnost_h.detail', 2), $vynutit));
    }

    public static function pripominky(string $pid, bool $vynutit = false)
    {
        $pid = norm_id($pid);
        return self::json(Http::sCache(str_replace('{pid}', $pid, URL_PRIPOMINKY), (float)Konfig::get('platnost_h.detail', 2), $vynutit));
    }

    public static function json(string $data)
    {
        if (strncmp($data, "\xEF\xBB\xBF", 3) === 0) {
            $data = substr($data, 3);
        }
        $v = json_decode($data, true, 512, JSON_BIGINT_AS_STRING | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($v === null && json_last_error() !== JSON_ERROR_NONE) {
            $zacatek = trim(mb_substr(preg_replace('/\s+/u', ' ', strip_tags(mb_convert_encoding(substr($data, 0, 400), 'UTF-8', 'UTF-8'))), 0, 160));
            throw new OdokChyba(sprintf('Neplatný JSON (%s, %d B)%s', json_last_error_msg(), strlen($data),
                $zacatek !== '' ? ': „' . $zacatek . '…“' : ' – prázdná odpověď'));
        }
        return $v;
    }

    /** Data materiálu z databáze (dekomprimovaný JSON). */
    public static function data(int $id)
    {
        $b = Db::hodnota('SELECT data FROM {p}odok_material WHERE id = ?', [$id]);
        if ($b === null) {
            return [];
        }
        $t = @gzuncompress((string)$b);
        try {
            return self::json($t === false ? (string)$b : $t);
        } catch (OdokChyba $e) {
            // poškozená / prázdná uložená data jednoho materiálu nesmí shodit celý přehled (PLPV, nástěnka …)
            log_zprava("Data materiálu id $id: " . $e->getMessage(), 'chyba');
            return [];
        }
    }
}

// ---------------------------------------------------------------- Material

class Material
{
    public $radek;
    private $odok;
    private $data = null;
    private $verze = null;
    private $pripominky = null;

    public function __construct(array $radek, ?Odok $odok = null)
    {
        $this->radek = $radek;
        $this->odok = $odok;
        if (array_key_exists('data', $radek) && is_array($radek['data'])) {
            $this->data = $radek['data'];
        }
    }

    /** Hodnoty sloupců tabulky spočítané ze surových dat (při importu). */
    public static function sloupceZDat(array $data): array
    {
        $m = new Material(['data' => $data]);
        return [
            'pid' => $m->vypocet_pid(), 'nazev' => $m->vypocet_nazev(), 'cj' => $m->vypocet_cj(),
            'cj_ova' => self::text(_prvni_cesta($data, 'identifier.id_no')),
            'predkladatel' => self::text(py_or(_hodnota_cesty($data, CESTA_ORGANIZACE_NAZEV), _jako_text(_najdi_hluboko($data, KLICE_PREDKLADATEL)), null)),
            'predkladatel_zkratka' => self::text(_hodnota_cesty($data, CESTA_ORGANIZACE_ID)),
            'stav' => self::text(py_or(_prvni_cesta($data, 'status.name'), _jako_text(_najdi_hluboko($data, KLICE_STAV)), null)),
            'stav_id' => self::text(_prvni_cesta($data, 'status.id')),
            'typ' => self::text(py_or(_prvni_cesta($data, 'type.name'), _jako_text(_najdi_hluboko($data, KLICE_TYP)), null)),
            'typ_id' => self::text(_prvni_cesta($data, 'type.id')),
            'datum' => self::text(py_or(_prvni_cesta($data, 'date_authorized', 'metadata.date_created'), _jako_text(_najdi_hluboko($data, KLICE_DATUM)), null)),
            'datum_vytvoreni' => parsuj_cas_odok(_prvni_cesta($data, 'metadata.date_created')),
            'datum_autorizace' => parsuj_cas_odok(_prvni_cesta($data, 'date_authorized')),
        ];
    }

    private static function text($v): ?string
    {
        return $v === null ? null : py_str($v);
    }

    private function vypocet_pid(): ?string
    {
        $v = _najdi_hluboko($this->data(), KLICE_PID, 1);
        return py_bool($v) ? norm_id(is_array($v) ? py_str($v) : py_str($v)) : null;
    }

    private function vypocet_cj()
    {
        return self::text(py_or(_prvni_cesta($this->data(), 'identifier.id_no_ext'), _jako_text(_najdi_hluboko($this->data(), KLICE_CJ)), null));
    }

    private function vypocet_nazev()
    {
        return self::text(py_or(_prvni_cesta($this->data(), 'title'), _jako_text(_najdi_hluboko($this->data(), KLICE_NAZEV, 1)), null));
    }

    public function data(): array
    {
        if ($this->data === null) {
            $this->data = isset($this->radek['id']) ? (array)Odok::data((int)$this->radek['id']) : [];
        }
        return $this->data;
    }

    private function sl(string $k)
    {
        if (array_key_exists($k, $this->radek)) {
            return $this->radek[$k];
        }
        $s = self::sloupceZDat($this->data());
        foreach ($s as $kk => $v) {
            if (!array_key_exists($kk, $this->radek)) {
                $this->radek[$kk] = $v;
            }
        }
        return $this->radek[$k] ?? null;
    }

    public function __get($n)
    {
        switch ($n) {
            case 'pid': return py_bool($this->sl('pid')) ? $this->sl('pid') : null;
            case 'cislo_jednaci': return py_bool($this->sl('cj')) ? $this->sl('cj') : null;
            case 'cj_ova': return $this->sl('cj_ova');
            case 'nazev': return py_bool($this->sl('nazev')) ? $this->sl('nazev') : null;
            case 'predkladatel': return py_bool($this->sl('predkladatel')) ? $this->sl('predkladatel') : null;
            case 'predkladatel_zkratka': return $this->sl('predkladatel_zkratka');
            case 'stav': return py_bool($this->sl('stav')) ? $this->sl('stav') : null;
            case 'stav_id': return $this->sl('stav_id');
            case 'typ': return py_bool($this->sl('typ')) ? $this->sl('typ') : null;
            case 'typ_id': return $this->sl('typ_id');
            case 'datum': return py_bool($this->sl('datum')) ? $this->sl('datum') : null;
            case 'datum_vytvoreni': return $this->sl('datum_vytvoreni');
            case 'datum_autorizace': return $this->sl('datum_autorizace');
            case 'rok': return $this->radek['rok'] ?? null;
            case 'konec_mpr': return parsuj_cas_odok(_prvni_cesta($this->data(), 'review_process.date_finish'));
            case 'datum_jednani_vlady': return parsuj_cas_odok(_prvni_cesta($this->data(), 'government_program.date'));
            case 'tisk_psp': return $this->tisk_psp();
            case 'plan': return $this->plan();
            case 'url_web': return $this->pid ? str_replace('{pid}', $this->pid, URL_DETAIL_WEB) : null;
        }
        throw new LogicException("Material nemá vlastnost $n");
    }

    /** [volební období, číslo tisku] podle ODOK, jinak null. */
    private function tisk_psp(): ?array
    {
        $cislo = _prvni_cesta($this->data(), 'psp.id');
        $obdobi = _prvni_cesta($this->data(), 'psp.period');
        if (!py_bool($cislo) || !py_bool($obdobi)) {
            return null;
        }
        $c = filter_var(is_string($cislo) ? trim($cislo) : $cislo, FILTER_VALIDATE_INT);
        $o = filter_var(is_string($obdobi) ? trim($obdobi) : $obdobi, FILTER_VALIDATE_INT);
        if (is_float($cislo) || is_float($obdobi)) {
            $c = (int)$cislo;
            $o = (int)$obdobi;
        }
        return ($c === false || $o === false) ? null : [(int)$o, (int)$c];
    }

    /** Údaje o plánu z odůvodnění: ['polozka' => …, 'termin' => …]. */
    private function plan(): array
    {
        $casti = [];
        foreach (['reason', 'description'] as $k) {
            $v = _prvni_cesta($this->data(), $k);
            $casti[] = py_bool($v) ? py_str($v) : '';
        }
        $text = implode(' ', $casti);
        $t = bez_diakritiky($text);
        $v = [];
        $m = preg_match('/plan(?:u|em)? legislativnich praci vlady na rok (\d{4})/u', $t, $mm);
        if (strpos($t, 'mimo plan') !== false) {
            $v['polozka'] = 'mimo Plán';
        } elseif ($m) {
            $v['polozka'] = 'PLPV ' . $mm[1];
        } elseif (preg_match('/plan(?:u|em)? vyhlasek na rok (\d{4})/u', $t, $m2)) {
            $v['polozka'] = 'Plán vyhlášek ' . $m2[1];
        } elseif (strpos($t, 'plan legislativnich praci') !== false || strpos($t, 'planu legislativnich praci') !== false) {
            $v['polozka'] = 'PLPV';
        }
        if (preg_match('/terminem pro predlozeni vlade\s+((?:\d{1,2}\.\s*)?(?:\d{1,2}\.\s*\d{4}|[a-z]+(?:\s+\d{4})?)|\d{1,2}\.\s*\d{1,2}\.\s*\d{4})/u',
            $t, $m3, PREG_OFFSET_CAPTURE)) {
            $od = mb_strlen(substr($t, 0, $m3[1][1]));
            $delka = mb_strlen($m3[1][0]);
            $v['termin'] = trim(mb_substr($text, $od, $delka));
        }
        return $v;
    }

    public function info(): array
    {
        $t = $this->tisk_psp;
        return [
            'pid' => $this->pid, 'cislo_jednaci' => $this->cislo_jednaci, 'cj_ova' => $this->cj_ova,
            'nazev' => $this->nazev, 'predkladatel' => $this->predkladatel,
            'predkladatel_zkratka' => $this->predkladatel_zkratka, 'typ' => $this->typ, 'stav' => $this->stav,
            'datum' => $this->datum,
            'tisk_psp' => $t ? sprintf('%s (%s. období)', $t[1], $t[0]) : null,
            'url_web' => $this->url_web,
        ];
    }

    private function vyzaduj_pid(): string
    {
        if (!$this->pid) {
            throw new OdokChyba('Materiál nemá PID, detail nelze stáhnout.');
        }
        return $this->pid;
    }

    public function verze(bool $vynutit = false)
    {
        if ($this->verze === null || $vynutit) {
            $this->verze = Odok::verze($this->vyzaduj_pid(), $vynutit);
        }
        return $this->verze;
    }

    public function pripominky(bool $vynutit = false)
    {
        if ($this->pripominky === null || $vynutit) {
            $this->pripominky = Odok::pripominky($this->vyzaduj_pid(), $vynutit);
        }
        return $this->pripominky;
    }

    /** Historické verze: [['typ','typ_nazev','datum','stav','pid'], …] seřazené podle data. */
    public function verze_seznam(): array
    {
        $data = $this->verze();
        $seznam = je_dict($data) ? ($data['versions_list'] ?? null) : null;
        if (!je_list($seznam)) {
            $seznam = _najdi_seznam_zaznamu($data);
        }
        $v = [];
        foreach ($seznam as $x) {
            if (!je_dict($x)) {
                continue;
            }
            $v[] = [
                'typ' => _prvni_cesta($x, 'version_type.id'),
                'typ_nazev' => _prvni_cesta($x, 'version_type.name'),
                'datum' => parsuj_cas_odok(_prvni_cesta($x, 'date_authorized', 'metadata.date_created')),
                'stav' => _prvni_cesta($x, 'status.name'),
                'pid' => _prvni_cesta($x, 'pid', 'identifier.pid'),
            ];
        }
        usort($v, function ($a, $b) { return strcmp($a['datum'] ?? '', $b['datum'] ?? ''); });
        return $v;
    }

    public function datum_verze(array $typy, bool $posledni = false): ?string
    {
        $d = [];
        foreach ($this->verze_seznam() as $v) {
            if (in_array($v['typ'], $typy, true) && $v['datum']) {
                $d[] = $v['datum'];
            }
        }
        if (!$d) {
            return null;
        }
        return $posledni ? end($d) : $d[0];
    }

    /** Dokumenty materiálu z datasetu (mají 'name' a size / filenet_id / old_name), podle 'order'. */
    public function dokumenty(): array
    {
        $vse = [];
        _dicty($this->data(), $vse);
        $v = [];
        foreach ($vse as $i => $d) {
            if ($i === 0 || !array_key_exists('name', $d)) {
                continue;
            }
            if (array_key_exists('size', $d) || array_key_exists('filenet_id', $d) || array_key_exists('old_name', $d)) {
                $v[] = $d;
            }
        }
        usort($v, function ($a, $b) {
            $x = isset($a['order']) && is_int($a['order']) ? $a['order'] : 1000000;
            $y = isset($b['order']) && is_int($b['order']) ? $b['order'] : 1000000;
            return $x <=> $y;
        });
        return $v;
    }

    public function url_dokumentu($dokument): ?string
    {
        if (!is_array($dokument)) {
            return null;
        }
        $h = [];
        foreach ($dokument as $k => $v) {
            if (is_string($v) || is_int($v) || is_bool($v)) {
                $h[$k] = $v;
            }
        }
        $h['material_pid'] = $this->pid ?? '';
        return vypln_url(URL_DOKUMENT, $h);
    }
}
