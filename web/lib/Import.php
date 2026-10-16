<?php
/**
 * Import.php – plnění databáze z otevřených dat (spouští cron.php).
 *
 * Zdroje (tabulka zdroj):
 *   odok:<rok>  – dataset ODOK za ročník (ZIP, záložně JSON; domény odok.cz / odok.gov.cz)
 *   psp         – archivy Sněmovny tisky, schuze, poslanci, se_tisk (UNL, cp1250)
 *   esel        – datové sady e-Legislativy (json.gz)
 *   verze       – předběžné stažení historie verzí materiálů pro podklady PLPV (volitelné)
 *   uklid       – mazání staré cache a logu
 * Každý zdroj se stáhne znovu po uplynutí platnosti (konfigurace platnost_h); nezměněný soubor
 * (stejný otisk) se znovu neimportuje. Velké soubory se čtou proudově, aby stačila paměť webhostingu.
 */

/** Proudové čtení prvků velkého JSON pole (soubor může být .gz) bez načtení celého souboru. */
class JsonProud
{
    private $f;
    private $b = '';
    private $i = 0;
    private $eof = false;

    public function __construct(string $cesta)
    {
        $this->f = gzopen($cesta, 'rb');
        if (!$this->f) {
            throw new OdokChyba("Soubor $cesta nelze otevřít.");
        }
    }

    public function __destruct()
    {
        if ($this->f) {
            gzclose($this->f);
        }
    }

    private function dopln(): bool
    {
        if ($this->eof) {
            return false;
        }
        $d = gzread($this->f, 1 << 20);
        if ($d === false || $d === '') {
            $this->eof = true;
            return false;
        }
        $this->b .= $d;
        return true;
    }

    private function znak(): ?string
    {
        while (true) {
            $n = strlen($this->b);
            while ($this->i < $n && strpos(" \t\r\n", $this->b[$this->i]) !== false) {
                $this->i++;
            }
            if ($this->i < $n) {
                return $this->b[$this->i];
            }
            if (!$this->dopln()) {
                return null;
            }
        }
    }

    private function konecRetezce(): void
    {
        $this->i++;
        while (true) {
            $j = $this->i + strcspn($this->b, "\"\\", $this->i);
            if ($j >= strlen($this->b)) {
                $this->i = strlen($this->b);
                if (!$this->dopln()) {
                    throw new OdokChyba('Neočekávaný konec JSON (řetězec).');
                }
                continue;
            }
            if ($this->b[$j] === '\\') {
                $this->i = $j + 2;
                while ($this->i > strlen($this->b)) {
                    if (!$this->dopln()) {
                        throw new OdokChyba('Neočekávaný konec JSON.');
                    }
                }
                continue;
            }
            $this->i = $j + 1;
            return;
        }
    }

    private function konecHodnoty(): void
    {
        $c = $this->b[$this->i];
        if ($c === '"') {
            $this->konecRetezce();
            return;
        }
        if ($c === '{' || $c === '[') {
            $hloubka = 0;
            while (true) {
                $j = $this->i + strcspn($this->b, '"{}[]', $this->i);
                if ($j >= strlen($this->b)) {
                    $this->i = strlen($this->b);
                    if (!$this->dopln()) {
                        throw new OdokChyba('Neočekávaný konec JSON.');
                    }
                    continue;
                }
                $this->i = $j;
                $ch = $this->b[$j];
                if ($ch === '"') {
                    $this->konecRetezce();
                    continue;
                }
                $hloubka += ($ch === '{' || $ch === '[') ? 1 : -1;
                $this->i++;
                if ($hloubka === 0) {
                    return;
                }
            }
        }
        while (true) {
            $j = $this->i + strcspn($this->b, ",]} \t\r\n", $this->i);
            if ($j < strlen($this->b) || !$this->dopln()) {
                $this->i = min($j, strlen($this->b));
                return;
            }
        }
    }

    private function hodnota(int $od)
    {
        return json_decode(substr($this->b, $od, $this->i - $od), true, 512, JSON_BIGINT_AS_STRING);
    }

    /**
     * Prvky pole: buď celý soubor je pole, nebo pole pod klíčem $klic v kořenovém objektu
     * ($klic = null → první pole v kořenovém objektu). Kořen, který polem není, se vrátí jako jediný prvek.
     */
    public function prvky(?string $klic = null): Generator
    {
        $this->dopln();
        if (strncmp($this->b, "\xEF\xBB\xBF", 3) === 0) {
            $this->i = 3;
        }
        $c = $this->znak();
        if ($c === '[') {
            $this->i++;
            yield from $this->pole();
            return;
        }
        if ($c !== '{') {
            return;
        }
        $zacatek = $this->i;
        $this->i++;
        while (true) {
            $c = $this->znak();
            if ($c === null || $c === '}') {
                break;
            }
            if ($c === ',') {
                $this->i++;
                continue;
            }
            $s = $this->i;
            $this->konecHodnoty();
            $k = $this->hodnota($s);
            if ($this->znak() !== ':') {
                throw new OdokChyba('Neplatný JSON (chybí dvojtečka).');
            }
            $this->i++;
            $c = $this->znak();
            if ($c === '[' && ($klic === null || $k === $klic)) {
                $this->i++;
                yield from $this->pole();
                return;
            }
            $this->konecHodnoty();
        }
        // kořen bez pole – vrátit celý objekt (malé soubory)
        if ($zacatek === 0 || $zacatek === 3) {
            $v = json_decode(substr($this->b, $zacatek), true, 512, JSON_BIGINT_AS_STRING);
            if (is_array($v)) {
                yield $v;
            }
        }
    }

    private function pole(): Generator
    {
        while (true) {
            $c = $this->znak();
            if ($c === null || $c === ']') {
                $this->i++;
                return;
            }
            if ($c === ',') {
                $this->i++;
                continue;
            }
            $s = $this->i;
            $this->konecHodnoty();
            yield $this->hodnota($s);
            if ($this->i > (1 << 19)) {
                $this->b = substr($this->b, $this->i);
                $this->i = 0;
            }
        }
    }
}

class Import
{
    /** JSON větší než tohle se čte proudově (kvůli paměti). */
    const VELKY_JSON = 48 * 1024 * 1024;

    private $konec;        // čas, do kdy smí běžet (časový rozpočet)
    private $vypis;        // callable pro průběžný výpis
    public $vysledky = [];

    public function __construct(int $rozpocet_s = 0, ?callable $vypis = null)
    {
        $this->konec = $rozpocet_s > 0 ? microtime(true) + $rozpocet_s : null;
        $this->vypis = $vypis;
    }

    private function log(string $zprava): void
    {
        if ($this->vypis) {
            ($this->vypis)($zprava);
        }
    }

    private function zbyvaCas(): bool
    {
        return $this->konec === null || microtime(true) < $this->konec;
    }

    // ---------------- seznam zdrojů a plánování ----------------

    public static function zdroje(): array
    {
        $rok = (int)date('Y');
        $z = [];
        for ($r = $rok; $r >= (int)Konfig::get('odok_prvni_rok', 2016); $r--) {
            if ($r >= $rok) {
                $z["odok:$r"] = ['popis' => "ODOK – ročník $r", 'platnost' => (float)Konfig::get('platnost_h.odok', 24)];
            } else {
                // minulý ročník: jednou po skončení roku (2. ledna po hodině denni_obnova) dotáhnout poslední
                // změny z konce prosince, pak už se nestahuje (jen ručně)
                $z["odok:$r"] = ['popis' => "ODOK – ročník $r", 'platnost' => (float)Konfig::get('platnost_h.odok_stary', 0),
                    'uzavreni' => strtotime(sprintf('%d-01-02 %02d:00:00', $r + 1, self::hodinyObnovy()[0]))];
            }
        }
        $z['psp'] = ['popis' => 'Poslanecká sněmovna a Senát', 'platnost' => (float)Konfig::get('platnost_h.psp')];
        $z['esel'] = ['popis' => 'e-Legislativa', 'platnost' => (float)Konfig::get('platnost_h.esel')];
        $z['esbirka'] = ['popis' => 'e-Sbírka – názvy předpisů', 'platnost' => (float)Konfig::get('platnost_h.esbirka', 24)];
        // velké soubory (16 a 19 MB) – e-Legislativa jednou denně, konsolidační vazby e-Sbírky (přibývají pomalu) jednou týdně
        $z['esel_novely'] = ['popis' => 'e-Legislativa – předpisy měněné návrhy', 'platnost' => (float)Konfig::get('platnost_h.esel_novely', 24),
            'jednou_denne' => true];
        $z['esbirka_novely'] = ['popis' => 'e-Sbírka – novelizační vazby', 'platnost' => (float)Konfig::get('platnost_h.esbirka_novely', 168)];
        if (Konfig::get('plpv_prednacist')) {
            $z['verze'] = ['popis' => 'Historie verzí pro PLPV (' . implode(', ', (array)Konfig::get('plpv_prednacist')) . ')',
                'platnost' => (float)Konfig::get('platnost_h.detail')];
        }
        $z['uklid'] = ['popis' => 'Úklid cache a logu', 'platnost' => 24.0];
        return $z;
    }

    /**
     * Je zdroj na řadě k obnově? platnost 0 = jen jednou (po úspěšném stažení už nikdy, jen ručně);
     * platnost ≥ 24 h = jednou denně: znovu po uplynutí platnosti, nebo když bylo naposledy staženo
     * před poslední uplynulou hodinou z 'denni_obnova' (výchozí 7:00 a 19:00).
     */
    public static function jeProsly(?array $s, float $platnost, ?int $uzavreni = null, bool $jednou_denne = false): bool
    {
        if (!$s || !$s['stazeno']) {
            return true;
        }
        $stazeno = strtotime($s['stazeno'] . ' UTC');
        if ($uzavreni !== null && $stazeno < $uzavreni && time() >= $uzavreni) {
            return true;     // ročník skončil – ještě jednou stáhnout jeho konečnou podobu
        }
        if ($platnost <= 0) {
            return false;
        }
        if (time() - $stazeno >= $platnost * 3600) {
            return true;
        }
        if ($platnost >= 48) {
            return false;    // delší platnost (např. týdně) – jen podle času
        }
        if ($platnost >= 24 && $jednou_denne) {
            // velké soubory: jen jednou denně po první hodině z 'denni_obnova'
            $h = strtotime(sprintf('%s %02d:00:00', date('Y-m-d'), self::hodinyObnovy()[0]));
            $h = $h > time() ? $h - 86400 : $h;
            return $stazeno < $h;
        }
        if ($platnost >= 24) {
            // denní zdroje: znovu po každé z hodin 'denni_obnova' (výchozí 7:00 a 19:00) – když se ranní
            // aktualizace úřadu zpozdí, dotáhne ji večerní běh a nevznikne celodenní mezera
            $hranice = self::posledniHraniceObnovy();
            return $hranice !== null && $stazeno < $hranice;
        }
        return false;
    }

    /** Hodiny denní obnovy z konfigurace ('denni_obnova' => [7, 19] nebo jedno číslo). */
    public static function hodinyObnovy(): array
    {
        $h = array_values(array_unique(array_filter(array_map('intval', (array)Konfig::get('denni_obnova', [7, 19])),
            function ($x) { return $x >= 0 && $x <= 23; })));
        sort($h);
        return $h ?: [7, 19];
    }

    /** Poslední už uplynulý okamžik denní obnovy (dnes nebo včera). */
    public static function posledniHraniceObnovy(): ?int
    {
        $nejpozdejsi = null;
        foreach ([date('Y-m-d'), date('Y-m-d', strtotime('-1 day'))] as $den) {
            foreach (self::hodinyObnovy() as $h) {
                $t = strtotime(sprintf('%s %02d:00:00', $den, $h));
                if ($t <= time() && ($nejpozdejsi === null || $t > $nejpozdejsi)) {
                    $nejpozdejsi = $t;
                }
            }
        }
        return $nejpozdejsi;
    }

    /** Popis platnosti pro administraci: '2 h', 'denně po 7:00', 'jen jednou'. */
    public static function popisPlatnosti(float $platnost, bool $jednou_denne = false): string
    {
        if ($platnost <= 0) {
            return 'jen jednou';
        }
        if ($platnost >= 48) {
            return $platnost % 24 === 0 ? sprintf('jednou za %d dní', (int)($platnost / 24)) : rtrim(rtrim(sprintf('%.1f', $platnost), '0'), '.') . ' h';
        }
        if ($platnost >= 24 && $jednou_denne) {
            return sprintf('denně po %d:00', self::hodinyObnovy()[0]);
        }
        if ($platnost >= 24) {
            $hodiny = self::hodinyObnovy();
            return (count($hodiny) === 1 ? 'denně po ' : count($hodiny) . '× denně (') . implode(', ', array_map(function ($h) { return "$h:00"; }, $hodiny))
                . (count($hodiny) === 1 ? '' : ')');
        }
        return rtrim(rtrim(sprintf('%.1f', $platnost), '0'), '.') . ' h';
    }

    /** Stav zdrojů z databáze: klic → řádek tabulky zdroj (nebo null). */
    public static function stavZdroju(): array
    {
        $stav = [];
        foreach (Db::vse('SELECT * FROM {p}zdroj') as $r) {
            $stav[$r['klic']] = $r;
        }
        return $stav;
    }

    /** Které zdroje jsou na řadě (prošlé, vynucené), v pořadí důležitosti. */
    public static function naRade(bool $vse = false, ?array $jen = null): array
    {
        $stav = self::stavZdroju();
        $poradi = [];
        $rok = (int)date('Y');
        $priorita = function ($k) use ($rok) {
            if ($k === "odok:$rok") return 0;
            if ($k === 'psp') return 1;
            if ($k === 'esel') return 2;
            if ($k === 'odok:' . ($rok - 1)) return 3;
            if ($k === 'esbirka') return 4;
            if ($k === 'esel_novely') return 5;
            if ($k === 'esbirka_novely') return 6;
            if ($k === 'verze') return 50;
            if ($k === 'uklid') return 60;
            return 10 + ($rok - (int)substr($k, 5));
        };
        foreach (self::zdroje() as $k => $info) {
            if ($jen !== null && !in_array($k, $jen, true) && !in_array(explode(':', $k)[0], $jen, true)) {
                continue;
            }
            $s = $stav[$k] ?? null;
            $vynutit = $s && (int)$s['vynutit'] === 1;
            $prosly = self::jeProsly($s, $info['platnost'], $info['uzavreni'] ?? null, !empty($info['jednou_denne']));
            // po neúspěchu další pokus nejdřív za 15 minut (aby se při výpadku sítě nestahovalo pořád)
            $odstup = $s && $s['stav'] === 'chyba' && $s['pokus'] && time() - strtotime($s['pokus'] . ' UTC') < 900;
            if ($vse || $vynutit || ($prosly && !$odstup)) {
                $poradi[$k] = [$priorita($k), $info['popis'], $vynutit || $vse];
            }
        }
        uasort($poradi, function ($a, $b) { return $a[0] <=> $b[0]; });
        return $poradi;
    }

    /** Hlavní běh: postupně obnoví zdroje, které jsou na řadě, dokud nevyprší časový rozpočet. */
    public function spust(bool $vse = false, ?array $jen = null): void
    {
        if (!(int)Db::hodnota("SELECT GET_LOCK(?, 0)", [Konfig::get('db.nazev') . '_radar_cron'])) {
            $this->log('Import už běží v jiném procesu – končím.');
            return;
        }
        try {
            Db::q("REPLACE INTO {p}zdroj (klic, popis, stazeno, stav) VALUES ('cron', 'Poslední běh cronu', UTC_TIMESTAMP(), 'ok')");
            foreach (self::naRade($vse, $jen) as $klic => [$_p, $popis, $vynutit]) {
                if (!$this->zbyvaCas()) {
                    $this->log('Vypršel časový rozpočet – zbytek při dalším spuštění.');
                    break;
                }
                $this->obnovZdroj($klic, $popis, $vynutit);
            }
        } finally {
            Db::hodnota('SELECT RELEASE_LOCK(?)', [Konfig::get('db.nazev') . '_radar_cron']);
        }
    }

    public function obnovZdroj(string $klic, string $popis, bool $vynutit = false): void
    {
        $this->log("== $popis ($klic)");
        $zacatek = microtime(true);
        Db::q('INSERT INTO {p}zdroj (klic, popis, pokus, stav) VALUES (?, ?, UTC_TIMESTAMP(), ?)
               ON DUPLICATE KEY UPDATE popis = VALUES(popis), pokus = VALUES(pokus), stav = VALUES(stav)', [$klic, $popis, 'běží']);
        $stary = Db::radek('SELECT hash FROM {p}zdroj WHERE klic = ?', [$klic]);
        try {
            if (strncmp($klic, 'odok:', 5) === 0) {
                [$hash, $radku, $zprava] = $this->importOdok((int)substr($klic, 5), $vynutit ? null : ($stary['hash'] ?? null));
            } elseif ($klic === 'psp') {
                [$hash, $radku, $zprava] = $this->importPsp($vynutit ? null : ($stary['hash'] ?? null));
            } elseif ($klic === 'esel') {
                [$hash, $radku, $zprava] = $this->importEsel($vynutit ? null : ($stary['hash'] ?? null));
            } elseif ($klic === 'esbirka') {
                [$hash, $radku, $zprava] = $this->importEsbirka($vynutit ? null : ($stary['hash'] ?? null));
            } elseif ($klic === 'esel_novely') {
                [$hash, $radku, $zprava] = $this->importEselNovely($vynutit ? null : ($stary['hash'] ?? null));
            } elseif ($klic === 'esbirka_novely') {
                [$hash, $radku, $zprava] = $this->importEsbirkaNovely($vynutit ? null : ($stary['hash'] ?? null));
            } elseif ($klic === 'verze') {
                [$hash, $radku, $zprava] = $this->prednactiVerze();
            } elseif ($klic === 'uklid') {
                [$hash, $radku, $zprava] = $this->uklid();
            } else {
                throw new OdokChyba("Neznámý zdroj $klic");
            }
            $trvani = microtime(true) - $zacatek;
            Db::q('UPDATE {p}zdroj SET stazeno = UTC_TIMESTAMP(), hash = ?, stav = ?, zprava = ?, radku = COALESCE(?, radku), trvani_s = ?, vynutit = 0 WHERE klic = ?',
                [$hash, 'ok', $zprava, $radku, $trvani, $klic]);
            $this->log(sprintf('   hotovo za %.1f s: %s', $trvani, $zprava));
            $this->vysledky[$klic] = ['ok', $zprava];
            if ($klic === 'esel' && $radku !== null) {
                Db::q('DELETE FROM {p}kv_cache WHERE platnost < UTC_TIMESTAMP() + INTERVAL 1 MINUTE');
            }
        } catch (Throwable $e) {
            $zprava = $e->getMessage();
            Db::q('UPDATE {p}zdroj SET stav = ?, zprava = ?, trvani_s = ?, vynutit = 0 WHERE klic = ?',
                ['chyba', mb_substr($zprava, 0, 4000), microtime(true) - $zacatek, $klic]);
            log_zprava("Import $klic selhal: $zprava", 'chyba');
            $this->log("   CHYBA: $zprava");
            $this->vysledky[$klic] = ['chyba', $zprava];
        }
    }

    // ---------------- ODOK ----------------

    /** @return array [hash, počet materiálů|null, zpráva] */
    public function importOdok(int $rok, ?string $staryHash): array
    {
        @ini_set('memory_limit', '1024M');
        $tmp = docasny_adresar() . "/odok_$rok." . getmypid();
        $chyby = [];
        $soubor = null;
        $typ = null;
        foreach (DOMENY_DATASETU as $domena) {
            $url = vzor(URL_DATASET_ZIP, ['domena' => $domena, 'rok' => $rok]);
            try {
                Http::doSouboru($url, "$tmp.zip");
                $z = new ZipArchive();
                if ($z->open("$tmp.zip") !== true) {
                    throw new OdokChyba("Poškozený ZIP: $url");
                }
                $z->close();
                [$soubor, $typ] = ["$tmp.zip", 'zip'];
                break;
            } catch (OdokChyba $e) {
                $chyby[] = $e->getMessage();
                if ($e instanceof SitovaChyba && $e->kod === 0) {
                    continue;      // server neodpovídá – JSON ze stejné domény nezkoušet
                }
            }
            $url = vzor(URL_DATASET_JSON, ['domena' => $domena, 'rok' => $rok]);
            try {
                Http::doSouboru($url, "$tmp.json");
                [$soubor, $typ] = ["$tmp.json", 'json'];
                break;
            } catch (OdokChyba $e) {
                $chyby[] = $e->getMessage();
            }
        }
        if ($soubor === null) {
            throw new OdokChyba("Dataset za rok $rok se nepodařilo stáhnout.\n  " . implode("\n  ", $chyby));
        }
        try {
            $hash = sha1_file($soubor);
            if ($staryHash !== null && $hash === $staryHash) {
                return [$hash, null, 'beze změny'];
            }
            $materialy = [];       // pid → index (uvnitř ročníku platí poslední výskyt na místě prvního)
            $seznam = [];
            $pridej = function ($m) use (&$materialy, &$seznam) {
                $s = Material::sloupceZDat($m);
                $k = $s['pid'] !== null ? 'p:' . $s['pid'] : 'i:' . count($seznam);
                if (isset($materialy[$k])) {
                    $seznam[$materialy[$k]] = [$m, $s];
                } else {
                    $materialy[$k] = count($seznam);
                    $seznam[] = [$m, $s];
                }
            };
            $vadne = [];
            if ($typ === 'zip') {
                $z = new ZipArchive();
                $z->open($soubor);
                $jsony = [];
                for ($i = 0; $i < $z->numFiles; $i++) {
                    $n = $z->getNameIndex($i);
                    if (strtolower(substr($n, -5)) === '.json') {
                        $jsony[] = $i;
                    }
                }
                if (!$jsony) {
                    throw new OdokChyba("ZIP neobsahuje JSON (rok $rok).");
                }
                foreach ($jsony as $i) {
                    $st = $z->statIndex($i);
                    if ($st["size"] < static::VELKY_JSON) {
                        try {
                            $obsah = Odok::json((string)$z->getFromIndex($i));
                        } catch (OdokChyba $e) {
                            // jeden vadný soubor (materiál) nesmí zastavit import celého ročníku
                            $vadne[] = $z->getNameIndex($i) . ': ' . $e->getMessage();
                            continue;
                        }
                        foreach (_najdi_materialy($obsah) as $m) {
                            $pridej($m);
                        }
                    } else {
                        $cil = "$tmp.velky.json";
                        copy("zip://$soubor#" . $z->getNameIndex($i), $cil);
                        foreach ((new JsonProud($cil))->prvky() as $prvek) {
                            foreach (_najdi_materialy($prvek) as $m) {
                                $pridej($m);
                            }
                        }
                        @unlink($cil);
                    }
                }
                $z->close();
            } else {
                if (filesize($soubor) < static::VELKY_JSON) {
                    foreach (_najdi_materialy(Odok::json((string)file_get_contents($soubor))) as $m) {
                        $pridej($m);
                    }
                } else {
                    foreach ((new JsonProud($soubor))->prvky() as $prvek) {
                        foreach (_najdi_materialy($prvek) as $m) {
                            $pridej($m);
                        }
                    }
                }
            }
            if (!$seznam && $vadne) {
                throw new OdokChyba("Dataset $rok nemá žádný čitelný materiál: " . implode('; ', array_slice($vadne, 0, 3)));
            }
            $this->ulozOdok($rok, $seznam);
            if ($vadne) {
                log_zprava(sprintf('ODOK %d: %d vadných souborů v ZIP – %s', $rok, count($vadne), implode('; ', array_slice($vadne, 0, 5))), 'chyba');
            }
            return [$hash, count($seznam), sprintf('%d materiálů', count($seznam))
                . ($vadne ? sprintf('; %d vadných souborů přeskočeno (%s)', count($vadne), mb_strimwidth($vadne[0], 0, 200, '…')) : '')];
        } finally {
            @unlink("$tmp.zip");
            @unlink("$tmp.json");
        }
    }

    private function ulozOdok(int $rok, array $seznam): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::q('DELETE c FROM {p}odok_cj c JOIN {p}odok_material m ON m.id = c.material_id WHERE m.rok = ?', [$rok]);
            Db::q('DELETE FROM {p}odok_cj WHERE rok = ?', [$rok]);
            Db::q('DELETE FROM {p}odok_material WHERE rok = ?', [$rok]);
            $sloupce = ['pid', 'rok', 'poradi', 'nazev', 'cj', 'cj_ova', 'predkladatel', 'predkladatel_zkratka', 'typ', 'typ_id',
                'stav', 'stav_id', 'datum', 'datum_vytvoreni', 'datum_autorizace', 'hledani', 'idtexty', 'data'];
            $radky = [];
            $cj = [];
            foreach ($seznam as $poradi => [$m, $s]) {
                $texty = [];
                _vsechny_texty($m, $texty);
                $hledani = implode("\n", array_unique(array_map('bez_diakritiky', $texty)));
                $idtexty = "\n" . implode("\n", array_unique(array_map('norm_id', $texty))) . "\n";
                $radky[] = [$s['pid'], $rok, $poradi, $s['nazev'], self::orez($s['cj'], 255), self::orez($s['cj_ova'], 100),
                    self::orez($s['predkladatel'], 255), self::orez($s['predkladatel_zkratka'], 64), self::orez($s['typ'], 255),
                    self::orez($s['typ_id'], 16), self::orez($s['stav'], 255), self::orez($s['stav_id'], 16), self::orez($s['datum'], 64),
                    $s['datum_vytvoreni'], $s['datum_autorizace'], $hledani, $idtexty,
                    gzcompress((string)json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), 6)];
                // index čísel jednacích (čj předkladatele, případně seznam; čj ÚV)
                $c = _prvni_cesta($m, 'identifier.id_no_ext');
                if (!py_bool($c)) {
                    $c = _najdi_hluboko($m, KLICE_CJ, 2);
                }
                $klice = [];
                foreach (je_list($c) ? $c : [$c] as $x) {
                    if (py_bool($x) && !je_dict($x)) {
                        $klice[] = norm_id(py_str($x));
                    }
                }
                $ova = _prvni_cesta($m, 'identifier.id_no');
                if (py_bool($ova)) {
                    $klice[] = norm_id(py_str($ova));
                }
                $cj[$poradi] = array_unique($klice);
                if (count($radky) >= 200) {
                    Db::vlozVice('odok_material', $sloupce, $radky, 50);
                    $radky = [];
                }
            }
            Db::vlozVice('odok_material', $sloupce, $radky, 50);
            $ids = [];
            foreach (Db::vse('SELECT id, poradi FROM {p}odok_material WHERE rok = ?', [$rok]) as $r) {
                $ids[$r['poradi']] = $r['id'];
            }
            $radky = [];
            foreach ($cj as $poradi => $klice) {
                foreach ($klice as $k) {
                    if ($k !== '') {
                        $radky[] = [mb_substr($k, 0, 255), $ids[$poradi], $rok, $poradi];
                    }
                }
            }
            Db::vlozVice('odok_cj', ['norm', 'material_id', 'rok', 'poradi'], $radky, 500);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function orez($v, int $n)
    {
        return $v === null ? null : mb_substr((string)$v, 0, $n);
    }

    // ---------------- Sněmovna ----------------

    public static function ddlPsp(string $nazev, string $jmeno_tabulky): string
    {
        $sl = ['`_r` INT NOT NULL'];
        foreach (Psp::SCHEMA[$nazev][1] as [$s, $typ]) {
            $sl[] = "`$s` " . ['i' => 'BIGINT NULL', 's' => 'TEXT NULL', 'd' => 'DATE NULL', 't' => 'DATETIME NULL'][$typ];
        }
        $typy = array_column(Psp::SCHEMA[$nazev][1], 1, 0);
        foreach (Psp::INDEXY[$nazev] ?? [] as $s) {
            $sl[] = "KEY `$s` (`$s`" . ($typy[$s] === 's' ? '(40)' : '') . ', `_r`)';
        }
        return "CREATE TABLE $jmeno_tabulky (" . implode(', ', $sl) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
    }

    /** Vytvoří chybějící tabulky aplikace (při instalaci i aktualizaci; existující data zůstanou). */
    public static function vytvorTabulky(): void
    {
        foreach (explode(';', Db::sql((string)file_get_contents(RADAR_KOREN . '/sql/schema.sql'))) as $prikaz) {
            $prikaz = trim(preg_replace('/^--.*$/m', '', $prikaz));
            if ($prikaz !== '') {
                Db::pdo()->exec($prikaz);
            }
        }
        self::vytvorPrazdnePsp();
        Db::pdo()->exec(self::ddlEsbirka(Db::t('esb_predpis')));
        self::zajistiTabulkyNovel();
        // doplnění sloupců ze starších verzí (CREATE TABLE IF NOT EXISTS existující tabulku nemění)
        $sloupce = [['uzivatel', 'pravidla', 'VARCHAR(190) NULL AFTER posledni_prihlaseni']];
        foreach ($sloupce as [$tab, $sl, $typ]) {
            $je = Db::hodnota('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [Konfig::get('db.prefix') . $tab, $sl]);
            if (!$je) {
                Db::pdo()->exec('ALTER TABLE ' . Db::t($tab) . " ADD COLUMN `$sl` $typ");
            }
        }
        Db::q('REPLACE INTO {p}nastaveni (klic, hodnota) VALUES (?, ?)', ['schema', (string)self::VERZE_SCHEMATU]);
        Nastaveni::$cache = null;
    }

    /** Verze struktury databáze – po nahrání nové verze aplikace se databáze doplní automaticky. */
    const VERZE_SCHEMATU = 7;

    public static function zkontrolujSchema(): void
    {
        if ((int)nastaveni('schema', '0') < self::VERZE_SCHEMATU) {
            self::vytvorTabulky();
        }
    }

    public static function vytvorPrazdnePsp(): void
    {
        foreach (array_keys(Psp::SCHEMA) as $nazev) {
            if (!Db::existujeTabulka('psp_' . $nazev)) {
                Db::pdo()->exec(self::ddlPsp($nazev, Psp::tab($nazev)));
            }
        }
    }

    /** Řádek UNL → hodnoty (oddělovač |, escapování \). */
    public static function rozdelRadek(string $radek): array
    {
        if (strpos($radek, '\\') === false) {
            $pole = explode('|', $radek);
        } else {
            $pole = [];
            $akt = '';
            $n = strlen($radek);
            for ($i = 0; $i < $n; $i++) {
                $z = $radek[$i];
                if ($z === '\\' && $i + 1 < $n) {
                    $akt .= $radek[++$i];
                    continue;
                }
                if ($z === '|') {
                    $pole[] = $akt;
                    $akt = '';
                } else {
                    $akt .= $z;
                }
            }
            $pole[] = $akt;
        }
        if ($pole && end($pole) === '') {
            array_pop($pole);
        }
        return $pole;
    }

    /** Proudové čtení souboru UNL (cp1250) – generátor seznamů hodnot. */
    public static function unl($proud): Generator
    {
        static $cp = null;
        if ($cp === null) {
            $cp = require __DIR__ . '/cp1250.php';
        }
        $rozpracovano = null;
        while (($radek = fgets($proud)) !== false) {
            if (substr($radek, -1) === "\n") {
                $radek = substr($radek, 0, -1);
            }
            if (substr($radek, -1) === "\r") {
                $radek = substr($radek, 0, -1);
            }
            $radek = strtr($radek, $cp);
            if ($rozpracovano !== null) {
                $radek = $rozpracovano . "\n" . $radek;
                $rozpracovano = null;
            }
            $zpetnych = strlen($radek) - strlen(rtrim($radek, '\\'));
            if ($zpetnych % 2 === 1) {
                $rozpracovano = substr($radek, 0, -1);
                continue;
            }
            if ($radek !== '') {
                yield self::rozdelRadek($radek);
            }
        }
        if ($rozpracovano !== null && $rozpracovano !== '') {
            yield self::rozdelRadek($rozpracovano);
        }
    }

    public static function parsujDatum(string $h, string $typ): ?string
    {
        $h = trim($h);
        $m = null;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:(?: |T)(\d{1,2})(?::(\d{1,2})(?::(\d{1,2}))?)?)?$/', $h, $x)) {
            $m = [(int)$x[1], (int)$x[2], (int)$x[3], $x[4] ?? null, $x[5] ?? null, $x[6] ?? null];
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?: (\d{1,2})(?::(\d{1,2})(?::(\d{1,2}))?)?)?$/', $h, $x)) {
            $m = [(int)$x[3], (int)$x[2], (int)$x[1], $x[4] ?? null, $x[5] ?? null, $x[6] ?? null];
        }
        if (!$m || !checkdate($m[1], $m[2], $m[0])) {
            return null;
        }
        $d = sprintf('%04d-%02d-%02d', $m[0], $m[1], $m[2]);
        if ($typ === 'd') {
            return $d;
        }
        return sprintf('%s %02d:%02d:%02d', $d, (int)($m[3] ?? 0), (int)($m[4] ?? 0), (int)($m[5] ?? 0));
    }

    public static function prevedHodnotu(string $h, string $typ)
    {
        if ($h === '') {
            return null;
        }
        if ($typ === 'i') {
            $t = trim($h);
            return preg_match('/^[+-]?\d{1,18}$/', $t) ? (int)$t : null;
        }
        if ($typ === 'd' || $typ === 't') {
            return self::parsujDatum($h, $typ);
        }
        return $h;
    }

    public function importPsp(?string $staryHash): array
    {
        @ini_set('memory_limit', '512M');
        $tmp = docasny_adresar() . '/psp.' . getmypid();
        $archivy = [];
        foreach (Psp::SCHEMA as [$archiv]) {
            $archivy[$archiv] = true;
        }
        $soubory = [];
        $chyby = [];
        try {
            foreach (array_keys($archivy) as $archiv) {
                $cil = "$tmp.$archiv.zip";
                try {
                    Http::doSouboru(vzor(URL_OPENDATA, ['archiv' => $archiv]), $cil);
                    $soubory[$archiv] = $cil;
                } catch (OdokChyba $e) {
                    if (!in_array($archiv, Psp::VOLITELNE_ARCHIVY, true)) {
                        throw $e;
                    }
                    $chyby[] = "$archiv: " . $e->getMessage();
                }
            }
            $hash = sha1(implode(',', array_map('sha1_file', $soubory)) . ',' . implode(',', array_keys($soubory)));
            if ($staryHash !== null && $hash === $staryHash) {
                return [$hash, null, 'beze změny'];
            }
            $nove = [];
            $celkem = 0;
            $pocty = [];
            foreach (Psp::SCHEMA as $nazev => [$archiv, $sloupce]) {
                if (!isset($soubory[$archiv])) {
                    continue;      // volitelný archiv se nepodařilo stáhnout – zůstanou stará data
                }
                $tab_nova = Db::t('psp_' . $nazev . '__novy');
                Db::pdo()->exec("DROP TABLE IF EXISTS $tab_nova");
                Db::pdo()->exec(self::ddlPsp($nazev, $tab_nova));
                $nove[] = $nazev;
                $z = new ZipArchive();
                if ($z->open($soubory[$archiv]) !== true) {
                    throw new OdokChyba("Poškozený archiv $archiv.zip");
                }
                $clen = null;
                for ($i = 0; $i < $z->numFiles; $i++) {
                    $jm = strtolower(basename($z->getNameIndex($i)));
                    if ($jm === "$nazev.unl" || ($clen === null && explode('.', $jm)[0] === $nazev)) {
                        $clen = $z->getNameIndex($i);
                        if ($jm === "$nazev.unl") {
                            break;
                        }
                    }
                }
                if ($clen === null) {
                    $z->close();
                    $chyby[] = "V archivu $archiv.zip chybí tabulka $nazev";
                    continue;
                }
                $proud = $z->getStream($clen);
                $jmena = array_merge(['_r'], array_column($sloupce, 0));
                $typy = array_column($sloupce, 1);
                $davka = [];
                $r = 0;
                foreach (self::unl($proud) as $hodnoty) {
                    $radek = [$r++];
                    foreach ($typy as $i => $typ) {
                        $radek[] = isset($hodnoty[$i]) ? self::prevedHodnotu($hodnoty[$i], $typ) : null;
                    }
                    $davka[] = $radek;
                    if (count($davka) >= 1000) {
                        Db::vlozVice($tab_nova, $jmena, $davka, 250);
                        $davka = [];
                    }
                }
                Db::vlozVice($tab_nova, $jmena, $davka, 250);
                fclose($proud);
                $z->close();
                $celkem += $r;
                $pocty[] = "$nazev $r";
            }
            // výměna tabulek najednou (čtenáři vidí buď stará, nebo nová data)
            $prejmenuj = [];
            $smaz = [];
            foreach ($nove as $nazev) {
                $tab = Db::t('psp_' . $nazev);
                $stara = Db::t('psp_' . $nazev . '__stara');
                Db::pdo()->exec("DROP TABLE IF EXISTS $stara");
                if (Db::existujeTabulka('psp_' . $nazev)) {
                    $prejmenuj[] = "$tab TO $stara";
                    $smaz[] = $stara;
                }
                $prejmenuj[] = Db::t('psp_' . $nazev . '__novy') . " TO $tab";
            }
            if ($prejmenuj) {
                Db::pdo()->exec('RENAME TABLE ' . implode(', ', $prejmenuj));
            }
            foreach ($smaz as $s) {
                Db::pdo()->exec("DROP TABLE IF EXISTS $s");
            }
            foreach ($chyby as $ch) {
                log_zprava("Sněmovna: $ch", 'varovani');
            }
            return [$hash, $celkem, sprintf('%d řádků v %d tabulkách%s', $celkem, count($nove), $chyby ? '; ' . implode('; ', $chyby) : '')];
        } finally {
            foreach (array_keys($archivy) as $archiv) {
                @unlink("$tmp.$archiv.zip");
            }
        }
    }

    // ---------------- e-Legislativa ----------------

    private static function skalar($v): ?string
    {
        if ($v === null) {
            return null;
        }
        return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : py_str($v);
    }

    // ---------------- e-Sbírka: názvy předpisů ----------------

    /** Tabulka předpisů ze Sbírky (e-Sbírka, datová sada 006 Právní akt – metadata). */
    public static function ddlEsbirka(string $tabulka): string
    {
        return "CREATE TABLE IF NOT EXISTS $tabulka (
  sbirka VARCHAR(8) NOT NULL, rok SMALLINT NOT NULL, cislo INT NOT NULL,
  nazev TEXT NULL, nazev_citace TEXT NULL, nazev_zkraceny VARCHAR(255) NULL, dalsi_nazvy TEXT NULL,
  druh VARCHAR(32) NULL, druh_nazev VARCHAR(100) NULL, schvaleno DATE NULL, ucinnost DATE NULL, zruseno DATE NULL,
  hledani TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  KEY rok_cislo (rok, cislo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci";
    }

    /** Číselník e-Sbírky: [kód => název]; při chybě prázdné pole (názvy druhů nejsou nutné). */
    private function ciselnikEsbirky(string $url): array
    {
        $cil = docasny_adresar() . '/esbirka_cis.' . getmypid() . '.json.gz';
        $v = [];
        try {
            Http::doSouboru($url, $cil);
            foreach ((new JsonProud($cil))->prvky('položky') as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $kod = $nazev = null;
                foreach ($p as $k => $h) {
                    if (!is_scalar($h) || $h === '') {
                        continue;
                    }
                    if ($kod === null && preg_match('/(^|-)kód$/u', $k)) {
                        $kod = (string)$h;
                    } elseif ($nazev === null && preg_match('/(^|-)název$/u', $k)) {
                        $nazev = (string)$h;
                    }
                }
                if ($kod === null && isset($p['iri']) && preg_match('~/položka/([^/]+)$~u', (string)$p['iri'], $m)) {
                    $kod = $m[1];
                }
                if ($kod !== null && $nazev !== null) {
                    $v[$kod] = $nazev;
                }
            }
        } catch (Throwable $e) {
            log_zprava('e-Sbírka: číselník ' . basename($url) . ' se nepodařilo načíst: ' . $e->getMessage(), 'varovani');
        } finally {
            @unlink($cil);
        }
        return $v;
    }

    public function importEsbirka(?string $staryHash): array
    {
        $cil = docasny_adresar() . '/esbirka.' . getmypid() . '.json.gz';
        try {
            Http::doSouboru(URL_ESBIRKA_METADATA, $cil);
            $hash = sha1_file($cil);
            if ($staryHash !== null && $hash === $staryHash) {
                return [$hash, null, 'beze změny'];
            }
            $nova = Db::t('esb_predpis__novy');
            Db::pdo()->exec("DROP TABLE IF EXISTS $nova");
            Db::pdo()->exec(self::ddlEsbirka($nova));
            $jmena = ['sbirka', 'rok', 'cislo', 'nazev', 'nazev_citace', 'nazev_zkraceny', 'dalsi_nazvy', 'druh', 'druh_nazev', 'schvaleno', 'ucinnost', 'zruseno', 'hledani'];
            $druhy = $this->ciselnikEsbirky(URL_ESBIRKA_DRUH_AKTU);
            $datum = function ($h) {
                return is_string($h) && preg_match('/^\d{4}-\d{2}-\d{2}/', $h) ? substr($h, 0, 10) : null;
            };
            $davka = [];
            $pocty = [];
            foreach ((new JsonProud($cil))->prvky('položky') as $p) {
                if (!is_array($p)) {
                    continue;
                }
                // sbírka z ELI: /eli/cz/sb/2009/280 (sb = Sbírka zákonů, sm = Sbírka mezinárodních smluv, …)
                $eli = (string)py_or($p['metadata-eli'] ?? null, $p['akt-iri'] ?? null);
                if (!preg_match('~/eli/cz/([a-z0-9]+)/(\d{4})/(\d+)~i', $eli, $m)) {
                    continue;
                }
                $dalsi = [];
                foreach ((array)($p['metadata-další-název'] ?? []) as $d) {
                    $t = trim((string)(is_array($d) ? ($d['další-název'] ?? '') : $d));
                    if ($t !== '' && !in_array($t, $dalsi, true)) {
                        $dalsi[] = $t;
                    }
                }
                $nazev = self::skalar($p['metadata-název'] ?? null) ?? self::skalar($p['akt-název-vyhlášený'] ?? null);
                $citace = self::skalar($p['metadata-název-citace'] ?? null);
                $zkr = self::skalar($p['metadata-název-zkrácený'] ?? null);
                $sb = strtolower($m[1]);
                $pocty[$sb] = ($pocty[$sb] ?? 0) + 1;
                $davka[] = [$sb, (int)$m[2], (int)$m[3], $nazev, $citace, $zkr !== null ? mb_substr($zkr, 0, 255) : null,
                    $dalsi ? implode('|', $dalsi) : null, $druh = self::skalar($p['cis-esb-druh-aktu-položka'] ?? null),
                    $druh !== null ? ($druhy[$druh] ?? null) : null,
                    $datum($p['metadata-datum-schválení'] ?? null), $datum($p['metadata-datum-účinnosti-od'] ?? null),
                    $datum($p['metadata-datum-zrušení'] ?? null),
                    bez_diakritiky(implode(' ', array_filter([$nazev, $citace, $zkr, implode(' ', $dalsi), "{$m[3]}/{$m[2]}"])))];
                if (count($davka) >= 500) {
                    Db::vlozVice($nova, $jmena, $davka, 250);
                    $davka = [];
                }
            }
            Db::vlozVice($nova, $jmena, $davka, 250);
            $celkem = array_sum($pocty);
            if ($celkem < 1000) {
                throw new OdokChyba("Datová sada e-Sbírky obsahuje jen $celkem předpisů – nechávám původní data.");
            }
            $tab = Db::t('esb_predpis');
            $stara = Db::t('esb_predpis__stara');
            Db::pdo()->exec("DROP TABLE IF EXISTS $stara");
            if (Db::existujeTabulka('esb_predpis')) {
                Db::pdo()->exec("RENAME TABLE $tab TO $stara, $nova TO $tab");
                Db::pdo()->exec("DROP TABLE IF EXISTS $stara");
            } else {
                Db::pdo()->exec("RENAME TABLE $nova TO $tab");
            }
            arsort($pocty);
            return [$hash, $celkem, sprintf('%d předpisů (%s)', $celkem,
                implode(', ', array_map(function ($k, $v) { return "$k $v"; }, array_keys($pocty), $pocty)))];
        } finally {
            @unlink($cil);
        }
    }

    public function importEsel(?string $staryHash): array
    {
        @ini_set('memory_limit', '1024M');
        $tmp = docasny_adresar() . '/esel.' . getmypid();
        $soubory = [];
        try {
            foreach (ESEL_SADY as $klic => $sada) {
                $cil = "$tmp.$klic.json.gz";
                try {
                    Http::doSouboru(ESEL_ZAKLAD . $sada . '.json.gz', $cil);
                    $soubory[$klic] = $cil;
                } catch (OdokChyba $e) {
                    if ($klic !== 'globalni') {
                        throw $e;
                    }
                }
            }
            $hash = sha1(implode(',', array_map('sha1_file', $soubory)));
            if ($staryHash !== null && $hash === $staryHash) {
                return [$hash, null, 'beze změny'];
            }
            $prvky = function ($klic) use ($soubory) {
                return (new JsonProud($soubory[$klic]))->prvky('položky');
            };

            $globalni = [];
            if (isset($soubory['globalni'])) {
                foreach ($prvky('globalni') as $g) {
                    if (!is_array($g)) {
                        continue;
                    }
                    $n = $g['název'] ?? null;
                    $globalni[self::klicId($g['globální-id'] ?? null)] = py_or(is_array($n) ? ($n['cs'] ?? null) : $n, $g['kód'] ?? null);
                }
            }

            // návrhy
            $navrhy = [];
            $podle_ident = [];
            $podle_id = [];
            foreach ($prvky('navrhy') as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $i = count($navrhy);
                $gid = is_array($n['cis-ele-globální-typ-procesu-návrhu'] ?? null) ? ($n['cis-ele-globální-typ-procesu-návrhu']['globální-id'] ?? null) : null;
                $navrhy[] = [
                    'id' => $n['návrh-id'] ?? null, 'ident' => $n['návrh-identifikator'] ?? null, 'nazev' => self::skalar($n['návrh-název'] ?? null),
                    'cj_ova' => self::skalar($n['návrh-cj-ova'] ?? null), 'cj_pred' => $n['návrh-cj-předkladatele'] ?? null,
                    'typ' => $globalni[self::klicId($gid)] ?? null,
                ];
                $podle_ident[self::klicId($navrhy[$i]['ident'])] = $i;
                $podle_id[self::klicId($navrhy[$i]['id'])] = $i;
            }

            // vrstvy: aktuální vrstva → metadata; čj předkladatele ze všech vrstev
            $aktualni = [];
            $cj = [];
            foreach ($prvky('vrstvy') as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $ident = implode('-', array_slice(explode('-', (string)($v['vrstva-návrhu-identifikátor'] ?? '')), 0, 2));
                if (!isset($podle_ident[$ident])) {
                    continue;
                }
                if (py_bool($v['vrstva-návrhu-cjpředkladatele'] ?? null)) {
                    $cj[$ident][py_str($v['vrstva-návrhu-cjpředkladatele'])] = true;
                }
                $st = $aktualni[$ident] ?? null;
                $vid = py_or($v['vrstva-návrhu-id'] ?? null, 0);
                if (py_bool($v['vrstva-návrhu-je-aktuální'] ?? null) || $st === null
                    || (!$st['akt'] && $vid > $st['id'])) {
                    $md = $v['návrh-aktu-metadata-aktu'] ?? null;
                    $aktualni[$ident] = ['akt' => py_bool($v['vrstva-návrhu-je-aktuální'] ?? null), 'id' => $vid,
                        'meta' => is_array($md) ? self::klicId($md['metadata-aktu-id'] ?? null) : self::klicId(null)];
                }
            }
            $potrebna = [];
            foreach ($aktualni as $a) {
                $potrebna[$a['meta']] = true;
            }
            $metadata = [];
            foreach ($prvky('metadata') as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $mid = self::klicId($m['metadata-aktu-id'] ?? null);
                if (!isset($potrebna[$mid])) {
                    continue;
                }
                $u = $m['návrh-aktu-účinnost-od'] ?? null;
                $metadata[$mid] = [
                    'nazev' => self::skalar($m['metadata-aktu-název'] ?? null), 'druh' => self::skalar($m['cis-ele-druh-aktu-položka'] ?? null),
                    'citace' => self::skalar($m['metadata-aktu-citace'] ?? null),
                    'vyhlaseni' => esel_cas($m['metadata-aktu-datum-čas-vyhlášení'] ?? null),
                    'schvaleni' => esel_cas($m['metadata-aktu-datum-schválení'] ?? null),
                    'eu' => self::skalar($m['cis-ele-kompatibilita-eu-položka'] ?? null),
                    'implementace' => esel_cas($m['metadata-aktu-termín-pro-implementaci'] ?? null),
                    'ucinnost_id' => is_array($u) ? self::klicId($u['účinnost-aktu-id'] ?? null) : self::klicId(null),
                ];
            }
            $potrebne_u = [];
            foreach ($metadata as $m) {
                $potrebne_u[$m['ucinnost_id']] = true;
            }
            $ucinnost = [];
            foreach ($prvky('ucinnost') as $u) {
                if (!is_array($u)) {
                    continue;
                }
                $uid = self::klicId($u['účinnost-aktu-id'] ?? null);
                if (!isset($potrebne_u[$uid])) {
                    continue;
                }
                $d = py_or(esel_cas($u['účinnost-aktu-datum-účinnosti'] ?? null), esel_cas($u['účinnost-aktu-datum-pracovní-účinnosti'] ?? null));
                if ($d) {
                    $ucinnost[$uid] = substr($d, 0, 10);
                } else {
                    $t = is_array($u['účinnost-aktu-účinnost-textem'] ?? null) ? ($u['účinnost-aktu-účinnost-textem']['účinnost-textem'] ?? null) : null;
                    $ucinnost[$uid] = py_bool($t) ? trim(py_str($t)) : null;
                }
            }

            // nové tabulky
            $tabulky = ['esel_navrh', 'esel_cj', 'esel_faze', 'esel_stav'];
            $ddl = [];
            foreach ($tabulky as $t) {
                $ddl[$t] = Db::q('SHOW CREATE TABLE ' . Db::t($t))->fetch(PDO::FETCH_NUM)[1];
                Db::pdo()->exec('DROP TABLE IF EXISTS ' . Db::t($t . '__novy'));
                Db::pdo()->exec(preg_replace('/^CREATE TABLE `[^`]+`/', 'CREATE TABLE ' . Db::t($t . '__novy'), $ddl[$t]));
            }
            $radky = [];
            $cj_radky = [];
            $sl = ['id', 'poradi', 'identifikator', 'nazev', 'nazev_aktu', 'norm_nazev', 'cj_ova', 'norm_ova', 'druh', 'typ_procesu',
                'citace', 'datum_vyhlaseni', 'datum_schvaleni', 'ucinnost', 'kompatibilita_eu', 'termin_implementace', 'cj_predkladatele'];
            foreach ($navrhy as $i => $n) {
                if ($podle_id[self::klicId($n['id'])] !== $i || $n['id'] === null) {
                    continue;      // duplicitní návrh-id – platí poslední
                }
                $ident = self::klicId($n['ident']);
                $a = $aktualni[$ident] ?? null;
                $meta = $a ? ($metadata[$a['meta']] ?? []) : [];
                $mnoz = $cj[$ident] ?? [];
                if (py_bool($n['cj_pred'])) {
                    $mnoz[py_str($n['cj_pred'])] = true;
                }
                $seznam_cj = array_map('strval', array_keys($mnoz));
                sort($seznam_cj, SORT_STRING);
                $nazev_aktu = py_or($meta['nazev'] ?? null, $n['nazev']);
                $radky[] = [$n['id'], $i, self::orez(self::skalar($n['ident']), 64), $n['nazev'], $meta['nazev'] ?? null, norm_nazev($nazev_aktu),
                    self::orez($n['cj_ova'], 100), norm_ova($n['cj_ova']), self::orez($meta['druh'] ?? null, 64), self::orez($n['typ'], 255),
                    self::orez($meta['citace'] ?? null, 100), $meta['vyhlaseni'] ?? null, $meta['schvaleni'] ?? null,
                    self::orez(isset($meta['ucinnost_id']) ? ($ucinnost[$meta['ucinnost_id']] ?? null) : null, 255),
                    self::orez($meta['eu'] ?? null, 255), $meta['implementace'] ?? null, json_encode($seznam_cj, JSON_UNESCAPED_UNICODE)];
                foreach ($seznam_cj as $j => $c) {
                    $k = norm_cj($c);
                    if ($k) {
                        $cj_radky[] = [$k, $n['id'], $i * 1000 + $j];
                    }
                }
                if (count($radky) >= 500) {
                    Db::vlozVice(Db::t('esel_navrh__novy'), $sl, $radky, 100, 'REPLACE');
                    $radky = [];
                }
            }
            Db::vlozVice(Db::t('esel_navrh__novy'), $sl, $radky, 100, 'REPLACE');
            Db::vlozVice(Db::t('esel_cj__novy'), ['norm', 'navrh_id', 'poradi'], $cj_radky, 500);
            unset($navrhy, $aktualni, $cj, $metadata, $ucinnost, $radky, $cj_radky);

            // fáze a stavy (stav odkazuje na fázi polem „…fáze-nadřízená.návrh-id“)
            $faze_navrh = [];       // faze-id → [navrh-id, pořadí posledního výskytu]
            $duplicitni = [];
            $davka = [];
            $seq = 0;
            foreach ($prvky('faze') as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $nad = is_array($f['návrh-aktu-nadřízený-návrh'] ?? null) ? ($f['návrh-aktu-nadřízený-návrh']['návrh-id'] ?? null) : null;
                if (!isset($podle_id[self::klicId($nad)])) {
                    continue;
                }
                $fid = $f['fáze-id'] ?? null;
                $seq++;
                if (isset($faze_navrh[self::klicId($fid)])) {
                    $duplicitni[self::klicId($fid)] = $fid;
                }
                $faze_navrh[self::klicId($fid)] = [$nad, $seq];
                $davka[] = [$fid ?? 0, $nad, $seq, self::orez(self::skalar($f['fáze-název'] ?? null), 255),
                    esel_cas($f['fáze-datum-čas-zahájení'] ?? null), esel_cas($f['fáze-datum-čas-ukončení'] ?? null)];
                if (count($davka) >= 1000) {
                    Db::vlozVice(Db::t('esel_faze__novy'), ['id', 'navrh_id', 'idx', 'nazev', 'od', 'do'], $davka, 250);
                    $davka = [];
                }
            }
            Db::vlozVice(Db::t('esel_faze__novy'), ['id', 'navrh_id', 'idx', 'nazev', 'od', 'do'], $davka, 250);
            foreach ($duplicitni as $k => $fid) {
                Db::q('UPDATE ' . Db::t('esel_faze__novy') . ' SET posledni = 0 WHERE id = ? AND idx <> ?', [$fid, $faze_navrh[$k][1]]);
            }
            $davka = [];
            $pocet_stavu = 0;
            foreach ($prvky('stavy') as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $fid = is_array($s['stav-procesu-fáze-nadřízená'] ?? null) ? ($s['stav-procesu-fáze-nadřízená']['návrh-id'] ?? null) : null;
                $f = $faze_navrh[self::klicId($fid)] ?? null;
                if ($f === null) {
                    continue;
                }
                $seq++;
                $pocet_stavu++;
                $davka[] = [$fid, $f[0], $seq, self::orez(self::skalar($s['stav-procesu-název'] ?? null), 255),
                    esel_cas($s['stav-procesu-datum-čas-zahájení'] ?? null), esel_cas($s['stav-procesu-datum-čas-ukončení'] ?? null)];
                if (count($davka) >= 1000) {
                    Db::vlozVice(Db::t('esel_stav__novy'), ['faze_id', 'navrh_id', 'idx', 'nazev', 'od', 'do'], $davka, 250);
                    $davka = [];
                }
            }
            Db::vlozVice(Db::t('esel_stav__novy'), ['faze_id', 'navrh_id', 'idx', 'nazev', 'od', 'do'], $davka, 250);

            $prejmenuj = [];
            foreach ($tabulky as $t) {
                Db::pdo()->exec('DROP TABLE IF EXISTS ' . Db::t($t . '__stara'));
                $prejmenuj[] = Db::t($t) . ' TO ' . Db::t($t . '__stara');
                $prejmenuj[] = Db::t($t . '__novy') . ' TO ' . Db::t($t);
            }
            Db::pdo()->exec('RENAME TABLE ' . implode(', ', $prejmenuj));
            foreach ($tabulky as $t) {
                Db::pdo()->exec('DROP TABLE IF EXISTS ' . Db::t($t . '__stara'));
            }
            $pocet = count($podle_id);
            return [$hash, $pocet, sprintf('%d návrhů, %d stavů procesu', $pocet, $pocet_stavu)];
        } finally {
            foreach (array_keys(ESEL_SADY) as $klic) {
                @unlink("$tmp.$klic.json.gz");
            }
        }
    }

    // ---------------- novelizační vazby (e-Legislativa: strom objektů, e-Sbírka: konsolidační vazby) ----------------

    /**
     * Úseky velkého JSON souboru (.gz) mezi výskyty oddělovače (začátek každého prvku pole, např. "typ": "…").
     * Čte se po 1 MB a prvky se nerozkládají – pro hledání několika polí regulárním výrazem to stačí a je to
     * řádově rychlejší a úspornější než úplný rozklad JSONu (soubory mají 16–19 MB komprimovaně, stovky MB rozbalené).
     */
    private static function useky(string $cesta, string $oddelovac): Generator
    {
        $f = gzopen($cesta, 'rb');
        if (!$f) {
            throw new OdokChyba("Soubor $cesta nelze otevřít.");
        }
        try {
            $buf = '';
            while (!gzeof($f)) {
                $kus = gzread($f, 1 << 20);
                if ($kus === false) {
                    break;
                }
                $buf .= $kus;
                if (!preg_match_all($oddelovac, $buf, $m, PREG_OFFSET_CAPTURE) || count($m[0]) < 2) {
                    if (strlen($buf) > (64 << 20)) {
                        throw new OdokChyba('Neočekávaný formát souboru (prvek delší než 64 MB).');
                    }
                    continue;
                }
                $pozice = array_column($m[0], 1);
                for ($i = 0, $n = count($pozice) - 1; $i < $n; $i++) {
                    yield substr($buf, $pozice[$i], $pozice[$i + 1] - $pozice[$i]);
                }
                $buf = substr($buf, $pozice[$n]);
            }
            if (preg_match($oddelovac, $buf)) {
                yield $buf;
            }
        } finally {
            gzclose($f);
        }
    }

    /** Tabulky novelizačních vazeb (i bez nahraného sql/schema.sql – vytvoří je přímo kód). */
    public static function zajistiTabulkyNovel(): void
    {
        Db::pdo()->exec('CREATE TABLE IF NOT EXISTS ' . Db::t('esel_novela') . ' (
  navrh_id BIGINT NOT NULL, predpis_cislo INT NOT NULL, predpis_rok INT NOT NULL,
  PRIMARY KEY (navrh_id, predpis_rok, predpis_cislo), KEY predpis (predpis_rok, predpis_cislo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        Db::pdo()->exec('CREATE TABLE IF NOT EXISTS ' . Db::t('esb_novela') . ' (
  novela_cislo INT NOT NULL, novela_rok INT NOT NULL, predpis_cislo INT NOT NULL, predpis_rok INT NOT NULL,
  rusi TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (novela_rok, novela_cislo, predpis_rok, predpis_cislo), KEY predpis (predpis_rok, predpis_cislo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }

    /** Naplní tabulku řádky (v transakci: smazat a vložit znovu). */
    private static function nahradTabulku(string $tabulka, array $sloupce, array $radky): void
    {
        self::zajistiTabulkyNovel();
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM ' . Db::t($tabulka));
            foreach (array_chunk($radky, 2000) as $kus) {
                Db::vlozVice(Db::t($tabulka), $sloupce, $kus, 500);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * e-Legislativa: které předpisy návrhy mění. Návrh novely obsahuje dokument „úplné znění s vyznačením změn“
     * (objekt typu Dokument.TD_UPLNE_ZNENI ve 140NavrhAktuObjekt) s poli vychoziCisloPredpisu / vychoziRokPredpisu;
     * kořenový objekt stromu je objekt návrhu (100NavrhAktuNavrh, návrh-aktu-objekt).
     */
    public function importEselNovely(?string $staryHash): array
    {
        $tmp = docasny_adresar() . '/eselnov.' . getmypid();
        try {
            Http::doSouboru(ESEL_ZAKLAD . '100NavrhAktuNavrh.json.gz', "$tmp.navrhy.json.gz");
            Http::doSouboru(ESEL_ZAKLAD . '140NavrhAktuObjekt.json.gz', "$tmp.objekty.json.gz");
            $hash = sha1(sha1_file("$tmp.navrhy.json.gz") . sha1_file("$tmp.objekty.json.gz"));
            if ($staryHash !== null && $hash === $staryHash) {
                return [$hash, null, 'beze změny'];
            }
            $navrh_objektu = [];        // id objektu návrhu → návrh-id
            foreach ((new JsonProud("$tmp.navrhy.json.gz"))->prvky('položky') as $n) {
                if (!is_array($n) || !isset($n['návrh-id'])) {
                    continue;
                }
                $o = $n['návrh-aktu-objekt'] ?? null;
                $oid = is_array($o) ? ($o['object-id'] ?? $o['objekt-id'] ?? null) : null;
                if ($oid === null && is_array($o) && preg_match('~/(\d+)$~', (string)($o['iri'] ?? ''), $mm)) {
                    $oid = $mm[1];
                }
                if ($oid !== null) {
                    $navrh_objektu[(string)$oid] = $n['návrh-id'];
                }
            }
            $vazby = [];
            $objektu = $bez_navrhu = 0;
            foreach (self::useky("$tmp.objekty.json.gz", '/"typ"\s*:\s*"návrh-aktu-objekt"/') as $u) {
                $objektu++;
                if (strpos($u, 'vychoziCisloPredpisu') === false) {
                    continue;
                }
                if (!preg_match('/vychoziCisloPredpisu\\\\?"\s*:\s*\\\\?"?(\d+)/', $u, $c)
                    || !preg_match('/vychoziRokPredpisu\\\\?"\s*:\s*\\\\?"?(\d{4})/', $u, $r)) {
                    continue;
                }
                if (preg_match('/vychoziNazevSbirky\\\\?"\s*:\s*\\\\?"([^"\\\\]*)/', $u, $sb) && stripos(bez_diakritiky($sb[1]), 'mezinar') !== false) {
                    continue;       // Sbírka mezinárodních smluv
                }
                if (!preg_match('/"návrh-aktu-objekt-kořenový"\s*:\s*\{[^}]*?"(?:object|objekt)-id"\s*:\s*(\d+)/', $u, $k)
                    && !preg_match('/"návrh-aktu-objekt-kořenový"\s*:\s*\{[^}]*?návrh-aktu-objekt\/(\d+)/', $u, $k)) {
                    $bez_navrhu++;
                    continue;
                }
                $navrh = $navrh_objektu[$k[1]] ?? null;
                if ($navrh === null) {
                    $bez_navrhu++;
                    continue;
                }
                $vazby[$navrh . ':' . (int)$r[1] . ':' . (int)$c[1]] = [$navrh, (int)$c[1], (int)$r[1]];
            }
            self::nahradTabulku('esel_novela', ['navrh_id', 'predpis_cislo', 'predpis_rok'], array_values($vazby));
            $navrhu = count(array_unique(array_column($vazby, 0)));
            return [$hash, count($vazby), sprintf('%d vazeb návrh → měněný předpis u %d návrhů (prošlo %d objektů%s)',
                count($vazby), $navrhu, $objektu, $bez_navrhu ? ", $bez_navrhu úplných znění bez návrhu" : '')];
        } finally {
            @unlink("$tmp.navrhy.json.gz");
            @unlink("$tmp.objekty.json.gz");
        }
    }

    /**
     * e-Sbírka: vyhlášený předpis → předpis, který mění / ruší (007PravniAktKonsolidacniVazba, po fragmentech ELI
     * – sloučí se na dvojice předpisů). Vazby přibývají až s konsolidací (úplným zněním), u čerstvých novel chybí.
     */
    public function importEsbirkaNovely(?string $staryHash): array
    {
        $cil = docasny_adresar() . '/esbnov.' . getmypid() . '.json.gz';
        try {
            Http::doSouboru(URL_ESBIRKA_VAZBY, $cil);
            $hash = sha1_file($cil);
            if ($staryHash !== null && $hash === $staryHash) {
                return [$hash, null, 'beze změny'];
            }
            $eli = function (string $u, string $pole): ?array {
                return preg_match('/"' . $pole . '"\s*:\s*\{\s*"iri"\s*:\s*"[^"]*?eli\/cz\/sb\/(\d{4})\/(\d+)/', $u, $m)
                    ? [(int)$m[2], (int)$m[1]] : null;
            };
            $dvojice = [];
            $zaznamu = 0;
            foreach (self::useky($cil, '/"typ"\s*:\s*"právní-akt-vazba-konsolidační"/') as $u) {
                $zaznamu++;
                $nov = $eli($u, 'znění-fragment-novela');
                $pred = $eli($u, 'znění-fragment-zdroj') ?? $eli($u, 'znění-fragment-cíl');
                if ($nov === null || $pred === null || $nov === $pred) {
                    continue;
                }
                $k = "$nov[1]:$nov[0]:$pred[1]:$pred[0]";
                $rusi = strpos($u, 'ZRUSPRED') !== false ? 1 : 0;
                $dvojice[$k] = [$nov[0], $nov[1], $pred[0], $pred[1], max($rusi, $dvojice[$k][4] ?? 0)];
            }
            self::nahradTabulku('esb_novela', ['novela_cislo', 'novela_rok', 'predpis_cislo', 'predpis_rok', 'rusi'], array_values($dvojice));
            return [$hash, count($dvojice), sprintf('%d dvojic novela → měněný předpis (z %d konsolidačních vazeb)', count($dvojice), $zaznamu)];
        } finally {
            @unlink($cil);
        }
    }

    private static function klicId($v): string
    {
        return $v === null ? "\x00" : (is_array($v) ? json_encode($v) : (string)$v);
    }

    // ---------------- verze pro PLPV, úklid ----------------

    public function prednactiVerze(): array
    {
        $resorty = array_map('strtoupper', (array)Konfig::get('plpv_prednacist'));
        $rok = (int)date('Y');
        $odok = new Odok(range($rok - 1, $rok));
        $urls = [];
        foreach ($resorty as $r) {
            foreach ($odok->materialy_ministerstva($r) as $m) {
                if (in_array($m->typ_id, ['NZ', 'NV', 'NNV'], true) && $m->pid) {
                    $urls[] = str_replace('{pid}', $m->pid, URL_VERZE);
                }
            }
        }
        $chyby = Http::predNacti($urls, (float)Konfig::get('platnost_h.detail', 2) * 0.9);
        return [null, count($urls), sprintf('%d materiálů, chyb %d', count($urls), count($chyby))];
    }

    public function uklid(): array
    {
        $n = Db::q("DELETE FROM {p}http_cache WHERE stazeno < UTC_TIMESTAMP() - INTERVAL 30 DAY AND url NOT LIKE '%/download/attachment/%'")->rowCount();
        $n += Db::q("DELETE FROM {p}http_cache WHERE stazeno < UTC_TIMESTAMP() - INTERVAL 180 DAY")->rowCount();
        Db::q('DELETE FROM {p}kv_cache WHERE platnost < UTC_TIMESTAMP()');
        Db::q('DELETE FROM {p}log WHERE cas < UTC_TIMESTAMP() - INTERVAL 90 DAY');
        Db::q('DELETE FROM {p}prihlaseni_pokus WHERE cas < UTC_TIMESTAMP() - INTERVAL 1 DAY');
        try {
            Db::q('DELETE FROM {p}trvale_prihlaseni WHERE platnost < UTC_TIMESTAMP()');
        } catch (PDOException $e) {
        }
        foreach (glob(docasny_adresar() . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400 && basename($f) !== '.htaccess') {
                @unlink($f);
            }
        }
        return [null, null, "smazáno $n starých souborů z cache"];
    }
}
