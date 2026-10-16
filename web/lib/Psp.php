<?php
/**
 * Psp.php – otevřená data Poslanecké sněmovny (sněmovní tisky, legislativní proces, schůze, Senát).
 * Port psp.py. Tabulky z archivů psp.cz importuje cron do tabulek psp_<tabulka> (sloupec _r = pořadí
 * řádku v souboru). Malé číselníky se načítají celé, velké tabulky po klíčích s předběžným načtením.
 */

const URL_OPENDATA = 'https://www.psp.cz/eknih/cdrom/opendata/{archiv}.zip';
const URL_TISK = 'https://www.psp.cz/sqw/historie.sqw?o={o}&t={ct}';
const URL_TISK_TEXT = 'https://www.psp.cz/sqw/text/tiskt.sqw?O={o}&CT={ct}&CT1={ct1}';
const URL_SCHUZE = 'https://www.psp.cz/sqw/ischuze.sqw?o={o}&s={schuze}';
const URL_HLASOVANI = 'https://www.psp.cz/sqw/hlasy.sqw?g={id}';
const URL_ODOK = 'https://www.odok.gov.cz/portal/veklep/material/{pid}/';
const URL_SENAT_TISK = 'https://www.senat.cz/xqw/xervlet/pssenat/historie?action=detail&value={id}';

function vzor(string $vzor, array $h): string
{
    return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($h) {
        return array_key_exists($m[1], $h) ? py_str($h[$m[1]]) : $m[0];
    }, $vzor);
}

/** Datum ve formátu isoformat() z Pythonu: date → 2026-03-05, datetime → 2026-03-05T10:00:00. */
function iso($d): string
{
    if (!is_string($d) || !je_datum($d)) {
        return '';
    }
    return strlen($d) <= 10 ? substr($d, 0, 10) : substr($d, 0, 10) . 'T' . substr($d, 11, 8);
}

class Obdobi
{
    public $id_organ, $cislo, $od, $do, $nazev;

    public function __construct($id_organ, $cislo, $od, $do, $nazev)
    {
        [$this->id_organ, $this->cislo, $this->od, $this->do, $this->nazev] = [$id_organ, $cislo, $od, $do, $nazev];
    }

    public function rok_od(): ?int
    {
        return $this->od ? (int)substr($this->od, 0, 4) : null;
    }

    public function rok_do(): ?int
    {
        return $this->do ? (int)substr($this->do, 0, 4) : null;
    }

    public function __toString(): string
    {
        return sprintf('%s. volební období (%s–%s)', py_str($this->cislo), $this->rok_od() ?? '?', $this->rok_do() ?? '');
    }
}

class Psp
{
    const I = 'i', S = 's', D = 'd', T = 't';

    /** tabulka → [archiv, [[sloupec, typ], …]] podle dokumentace psp.cz */
    const SCHEMA = [
        'druh_tisku' => ['tisky', [['id_druh', 'i'], ['druh_t', 's'], ['nazev_druh', 's']]],
        'typ_zakon' => ['tisky', [['id_navrh', 'i'], ['druh_navrhovatele', 's']]],
        'typ_stavu' => ['tisky', [['id_typ', 'i'], ['popis_stavu', 's']]],
        'stavy' => ['tisky', [['id_stav', 'i'], ['id_typ', 'i'], ['id_druh', 'i'], ['popis', 's'], ['lhuta', 'i'], ['lhuta_where', 'i']]],
        'typ_akce' => ['tisky', [['id_akce', 'i'], ['popis_akce', 's']]],
        'prechody' => ['tisky', [['id_prechod', 'i'], ['odkud', 'i'], ['kam', 'i'], ['id_akce', 'i'], ['typ_prechodu', 'i']]],
        'tisky' => ['tisky', [['id_tisk', 'i'], ['id_druh', 'i'], ['id_stav', 'i'], ['ct', 'i'], ['cislo_za', 'i'], ['id_navrh', 'i'],
            ['id_org', 'i'], ['id_org_obd', 'i'], ['id_osoba', 'i'], ['navrhovatel', 's'], ['nazev_tisku', 's'],
            ['predlozeno', 'd'], ['rozeslano', 'd'], ['dal', 't'], ['tech_nos_dat', 'd'], ['uplny_nazev_tisku', 's'],
            ['zm_lhuty', 'i'], ['lhuta', 'i'], ['rj', 'i'], ['t_url', 's'], ['is_eu', 'i'], ['roz', 't'], ['is_sdv', 'i'], ['status', 'i']]],
        'hist' => ['tisky', [['id_hist', 'i'], ['id_tisk', 'i'], ['datum', 't'], ['id_hlas', 'i'], ['id_prechod', 'i'], ['id_bod', 'i'],
            ['schuze', 'i'], ['usnes_ps', 'i'], ['orgv_id_posl', 'i'], ['ps_id_posl', 'i'], ['orgv_p_usn', 'i'],
            ['zaver_publik', 'd'], ['zaver_sb_castka', 'i'], ['zaver_sb_cislo', 'i'], ['poznamka', 's']]],
        'hist_vybory' => ['tisky', [['id_tisku', 'i'], ['id_organ', 'i'], ['typ', 'i'], ['id_hist', 'i'], ['id_posl', 'i'], ['poradi', 'i'], ['garancni', 'i']]],
        'vysledek' => ['tisky', [['id_vysledek', 'i'], ['druh_vysledek', 's']]],
        'se_tisk' => ['se_tisk', [['id_tisk', 'i'], ['id_druh', 'i'], ['id_stav', 'i'], ['cislo_tisku', 'i'], ['id_obdobi', 'i'], ['anotace', 's'], ['nazev_tisku', 's']]],
        'se_druh_tisku' => ['se_tisk', [['id_druh', 'i'], ['nazev_druhu', 's']]],
        'psp2senat' => ['se_tisk', [['id_psp', 'i'], ['id_senat', 'i'], ['typ', 'i']]],
        'tisky_za' => ['tisky', [['id_tisk', 'i'], ['cislo_za', 'i'], ['id_hist', 'i'], ['id_druh', 'i'], ['nazev_za', 's'], ['uplny_nazev_za', 's'],
            ['rozeslano', 't'], ['id_org', 'i'], ['usn_vybor', 'i'], ['id_posl', 'i'], ['t_url', 's'], ['id_vysledek', 'i'],
            ['cislo_za_post', 'i'], ['sort_it', 'i'], ['roz', 't'], ['status', 'i']]],
        'predkladatel' => ['tisky', [['id_tisk', 'i'], ['id_osoba', 'i'], ['poradi', 'i'], ['typ', 'i']]],
        'tisk_eklep' => ['tisky', [['pid', 's'], ['ova_c', 'i'], ['ova_r', 'i'], ['verejne', 'i'], ['id_tisk', 'i'], ['cislo_za', 'i']]],
        'tz_eklep' => ['tisky', [['pid', 's'], ['ova_c', 'i'], ['ova_r', 'i'], ['verejne', 'i'], ['id_tisk', 'i'], ['cislo_za', 'i']]],
        'schuze' => ['schuze', [['id_schuze', 'i'], ['id_org', 'i'], ['schuze', 'i'], ['od_schuze', 't'], ['do_schuze', 't'], ['aktualizace', 't'], ['pozvanka', 'i']]],
        'schuze_stav' => ['schuze', [['id_schuze', 'i'], ['stav', 'i'], ['typ', 'i'], ['text_dt', 's'], ['text_st', 's'], ['tm_line', 's']]],
        'bod_schuze' => ['schuze', [['id_bod', 'i'], ['id_schuze', 'i'], ['id_tisk', 'i'], ['id_typ', 'i'], ['bod', 'i'], ['uplny_naz', 's'],
            ['uplny_kon', 's'], ['poznamka', 's'], ['id_bod_stav', 'i'], ['pozvanka', 'i'], ['rj', 'i'], ['pozn2', 's'],
            ['druh_bodu', 'i'], ['id_sd', 'i'], ['zkratka', 's']]],
        'bod_stav' => ['schuze', [['id_bod_stav', 'i'], ['popis', 's']]],
        'typ_organu' => ['poslanci', [['id_typ_org', 'i'], ['typ_id_typ_org', 'i'], ['nazev_typ_org_cz', 's'], ['nazev_typ_org_en', 's'],
            ['typ_org_obecny', 'i'], ['priorita', 'i']]],
        'organy' => ['poslanci', [['id_organ', 'i'], ['organ_id_organ', 'i'], ['id_typ_organu', 'i'], ['zkratka', 's'], ['nazev_organu_cz', 's'],
            ['nazev_organu_en', 's'], ['od_organ', 'd'], ['do_organ', 'd'], ['priorita', 'i'], ['cl_organ_base', 'i']]],
        'osoby' => ['poslanci', [['id_osoba', 'i'], ['pred', 's'], ['prijmeni', 's'], ['jmeno', 's'], ['za', 's'], ['narozeni', 'd'],
            ['pohlavi', 's'], ['zmena', 'd'], ['umrti', 'd']]],
        // Sbírka zákonů (sbirka.zip): předpisy, vazba na tisk, ze kterého vzešly, a které předpisy tisky mění / ruší
        'druh_predpisu' => ['sbirka', [['id_dp', 'i'], ['nazev_druhu', 's'], ['priorita', 'i']]],
        'sbirka' => ['sbirka', [['id_sbirka', 'i'], ['cislo', 'i'], ['rok', 'i'], ['id_dp', 'i'], ['id_tisk', 'i'], ['datum', 'd'], ['castka', 'i']]],
        'sb_pre' => ['sbirka', [['id_tisk', 'i'], ['cz', 'i'], ['id_sbirka', 'i'], ['typ', 'i'], ['zdroj', 'i'], ['xzdroj', 'i']]],
        'poslanec' => ['poslanci', [['id_poslanec', 'i'], ['id_osoba', 'i'], ['id_kraj', 'i'], ['id_kandidatka', 'i'], ['id_obdobi', 'i'],
            ['web', 's'], ['ulice', 's'], ['obec', 's'], ['psc', 's'], ['email', 's'], ['telefon', 's'], ['fax', 's'],
            ['psp_telefon', 's'], ['facebook', 's'], ['foto', 'i']]],
    ];

    /** indexy velkých tabulek (sloupce, podle kterých se hledá) */
    const INDEXY = [
        'tisky' => ['id_tisk', 'ct', 'id_org_obd'], 'hist' => ['id_tisk', 'id_hist'], 'hist_vybory' => ['id_tisku'],
        'tisky_za' => ['id_tisk'], 'predkladatel' => ['id_tisk'], 'tisk_eklep' => ['id_tisk', 'pid'], 'tz_eklep' => ['id_tisk', 'pid'],
        'bod_schuze' => ['id_bod', 'id_tisk', 'id_schuze'], 'se_tisk' => ['id_tisk'], 'psp2senat' => ['id_psp'],
        'osoby' => ['id_osoba'], 'poslanec' => ['id_poslanec'], 'organy' => ['id_organ'], 'schuze' => ['id_schuze'],
        'sbirka' => ['id_sbirka', 'id_tisk', 'cislo'], 'sb_pre' => ['id_tisk', 'id_sbirka'],
    ];
    const MALE = ['druh_tisku', 'typ_zakon', 'typ_stavu', 'stavy', 'typ_akce', 'prechody', 'vysledek', 'bod_stav',
        'se_druh_tisku', 'typ_organu', 'organy', 'schuze', 'schuze_stav', 'druh_predpisu'];
    const VOLITELNE_ARCHIVY = ['poslanci', 'se_tisk', 'sbirka'];

    /** sb_pre.typ */
    const ZMENA_MENI = 31, ZMENA_RUSI = 32;

    const TYPY_VAZBY_SENAT = [5 => 'vrácený senátní návrh k dopracování', 10 => 'senátní návrh zákona projednávaný ve Sněmovně',
        20 => 'postoupeno Senátu', 21 => 'opakovaně postoupeno Senátu', 30 => 'souběžné projednávání (např. mezinárodní smlouva)'];
    const STAVY_SENAT = [1 => 'doručen', 2 => 'projednává se', 5 => 'uzavřen'];
    const TYP_BODU = [1 => '1. čtení', 7 => '1. čtení', 15 => '1. čtení', 17 => '1. čtení', 18 => '1. čtení',
        40 => '1. čtení', 41 => '1. čtení', 2 => '2. čtení', 3 => '2. čtení', 4 => '2. čtení',
        5 => '3. čtení', 6 => 'odpovědi na písemné interpelace', 10 => 'zkrácené jednání',
        13 => 'vráceno prezidentem', 14 => 'vráceno / zamítnuto Senátem'];
    const DRUH_BODU_PORAD = 7;

    /**
     * Čitelný stav místo „KONEC“ podle akce, kterou projednávání skončilo („KONEC“ zní jako neúspěch).
     * Nerozpoznaná akce → null (zůstane „KONEC“).
     */
    public static function stav_ukonceni(?string $akce, bool $ve_sbirce = false): ?string
    {
        $a = bez_diakritiky((string)$akce);
        if ($ve_sbirce || strpos($a, 'vyhlas') !== false) {
            return 'VYHLÁŠENO';
        }
        if (strpos($a, 'odeslan') !== false || strpos($a, 'k vyhlaseni') !== false) {
            return 'ODESLÁNO K VYHLÁŠENÍ';      // zákon odeslán do Sbírky, číslo ještě nemá
        }
        if (preg_match('~(vzat|vzal|vzala|stazen|odvolan)\w*.*zpet|zpet.*(vzat|vzal|stazen)|stazen~', $a)) {
            return 'VZATO ZPĚT';
        }
        if (strpos($a, 'zamit') !== false) {
            return 'ZAMÍTNUTO';
        }
        if (preg_match('~neschval|neprijat|nebyl\w* schval|nebyl\w* prijat~', $a)) {
            return 'NESCHVÁLENO';
        }
        if (strpos($a, 'zastav') !== false) {
            return 'ZASTAVENO';
        }
        if (strpos($a, 'vracen') !== false && strpos($a, 'dopracov') !== false) {
            return 'VRÁCENO K DOPRACOVÁNÍ';
        }
        if (strpos($a, 'na vedomi') !== false) {
            return 'VZATO NA VĚDOMÍ';
        }
        if (strpos($a, 'podeps') !== false) {
            return 'PODEPSÁNO';
        }
        if (strpos($a, 'schval') !== false || strpos($a, 'prijat') !== false) {
            return 'SCHVÁLENO';
        }
        return null;
    }
    const ETAPY = [
        ['1. čtení', [1, 17, 18, 40, 41, 44, 67, 71]],
        ['garanční výbor (po 1. čtení)', [3, 62, 65]],
        ['2. čtení', [2, 4, 72]],
        ['garanční výbor (po 2. čtení)', [21, 54, 55]],
        ['3. čtení', [5, 73, 43, 66, 77]],
        ['Senát', [12, 64]],
    ];
    const NAZVY_ETAP = ['1. čtení', 'garanční výbor (po 1. čtení)', '2. čtení', 'garanční výbor (po 2. čtení)', '3. čtení', 'Senát'];
    const DRUHY_USNESENI_VYBORU = [8, 44, 50];
    const TYPY_3_CTENI = [5, 73, 43, 66, 77];
    const TYPY_SNEMOVNA_PO_VRACENI = [13, 14, 63];
    const TYP_SENAT = 12, TYP_PREZIDENT = 8;
    const TYP_VYBORU = [1 => 'navrženo', 2 => 'přikázáno', 3 => 'iniciativně'];
    const TYP_STAVU_PROJEDNANO = 6;
    const TYP_STAVU_SENAT = 12;
    const TYP_STAVU_ODESILANO_SENATU = 64;
    const LHUTA_SENATU_DNI = 30;
    const DRUHY_ZAKONU = [1 => 'vládní návrh zákona', 2 => 'návrh zákona (nevládní)', 3 => 'státní rozpočet'];
    const DRUHY_VLADNI = [1, 3];
    const DRUHY_EU = [41, 45, 46, 47];

    public static function etapa_podle_typu($typ): ?string
    {
        foreach (self::ETAPY as [$nazev, $typy]) {
            if (in_array($typ, $typy, true)) {
                return $nazev;
            }
        }
        return null;
    }

    // ---------------- data (sdílená mezi pohledy na různá období) ----------------

    private $c;               // sdílená cache: tabulky, indexy, memo dotazů
    private $obdobi_param;
    private $obdobi_vypocet = null;

    public function __construct($obdobi = null)
    {
        $this->c = new stdClass();
        $this->c->tabulky = [];
        $this->c->indexy = [];
        $this->c->memo = [];
        $this->c->posledni = false;
        $this->c->seznam_obdobi = null;
        $this->obdobi_param = $obdobi;
    }

    public function v_obdobi($obdobi): Psp
    {
        $k = clone $this;
        $k->obdobi_param = $obdobi;
        $k->obdobi_vypocet = null;
        return $k;
    }

    public static function tab(string $nazev): string
    {
        return Db::t('psp_' . $nazev);
    }

    /** Má databáze data Sněmovny? */
    public static function maData(): bool
    {
        try {
            return (int)Db::hodnota('SELECT COUNT(*) FROM ' . self::tab('tisky')) > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Celá tabulka (jen malé číselníky). */
    public function tabulka(string $nazev): array
    {
        if (!isset($this->c->tabulky[$nazev])) {
            if (!isset(self::SCHEMA[$nazev])) {
                throw new OdokChyba("Neznámá tabulka: $nazev");
            }
            try {
                $this->c->tabulky[$nazev] = Db::vse('SELECT * FROM ' . self::tab($nazev) . ' ORDER BY _r');
            } catch (PDOException $e) {
                throw new OdokChyba("Tabulka $nazev není v databázi – spusťte import (cron.php).");
            }
        }
        return $this->c->tabulky[$nazev];
    }

    /** Řádky tabulky se zadanou hodnotou sloupce (v pořadí souboru). */
    public function radky(string $tabulka, string $sloupec, $hodnota): array
    {
        if (in_array($tabulka, self::MALE, true)) {
            $k = "$tabulka.$sloupec";
            if (!isset($this->c->indexy[$k])) {
                $idx = [];
                foreach ($this->tabulka($tabulka) as $r) {
                    $idx[self::klic($r[$sloupec] ?? null)][] = $r;
                }
                $this->c->indexy[$k] = $idx;
            }
            return $this->c->indexy[$k][self::klic($hodnota)] ?? [];
        }
        if ($hodnota === null) {
            return [];
        }
        $k = "$tabulka.$sloupec";
        $kk = self::klic($hodnota);
        if (!isset($this->c->memo[$k]) || !array_key_exists($kk, $this->c->memo[$k])) {
            $this->prednacti($tabulka, $sloupec, [$hodnota]);
        }
        return $this->c->memo[$k][$kk] ?? [];
    }

    private static function klic($v): string
    {
        return $v === null ? "\x00" : (string)$v;
    }

    /** Hromadně načte řádky pro více hodnot (aby se nedotazovalo po jednom). */
    public function prednacti(string $tabulka, string $sloupec, array $hodnoty): void
    {
        if (in_array($tabulka, self::MALE, true)) {
            return;
        }
        $k = "$tabulka.$sloupec";
        $chybi = [];
        foreach ($hodnoty as $h) {
            if ($h !== null && (!isset($this->c->memo[$k]) || !array_key_exists(self::klic($h), $this->c->memo[$k]))) {
                $chybi[self::klic($h)] = $h;
            }
        }
        if (!$chybi) {
            return;
        }
        foreach ($chybi as $kk => $_) {
            $this->c->memo[$k][$kk] = [];
        }
        foreach (array_chunk(array_values($chybi), 1000) as $kus) {
            try {
                $st = Db::q('SELECT * FROM ' . self::tab($tabulka) . " WHERE `$sloupec` IN (" . Db::in($kus) . ') ORDER BY _r', $kus);
            } catch (PDOException $e) {
                if (in_array(self::SCHEMA[$tabulka][0], self::VOLITELNE_ARCHIVY, true)) {
                    return;
                }
                throw new OdokChyba("Tabulka $tabulka není v databázi – spusťte import (cron.php).");
            }
            while ($r = $st->fetch()) {
                $this->c->memo[$k][self::klic($r[$sloupec])][] = $r;
            }
        }
    }

    public function jeden(string $tabulka, string $sloupec, $hodnota): ?array
    {
        $r = $this->radky($tabulka, $sloupec, $hodnota);
        return $r ? $r[0] : null;
    }

    /** Zavolá fce(); když chybí volitelná data (poslanci, Senát), vrátí výchozí. */
    public function bezpecne(callable $fce, $vychozi = null)
    {
        try {
            return $fce();
        } catch (OdokChyba $e) {
            return $vychozi;
        }
    }

    // ---------------- číselníky ----------------

    public function osoba($id_osoba): ?string
    {
        $o = $this->bezpecne(function () use ($id_osoba) { return $this->jeden('osoby', 'id_osoba', $id_osoba); });
        if (!$o) {
            return null;
        }
        $jmeno = implode(' ', array_filter([$o['pred'], $o['jmeno'], $o['prijmeni']], 'py_bool'));
        return $jmeno . (py_bool($o['za']) ? ', ' . $o['za'] : '');
    }

    public function poslanec($id_poslanec): ?string
    {
        $p = $this->bezpecne(function () use ($id_poslanec) { return $this->jeden('poslanec', 'id_poslanec', $id_poslanec); });
        return $p ? $this->osoba($p['id_osoba']) : null;
    }

    public function organ($id_organ): ?string
    {
        $o = $this->bezpecne(function () use ($id_organ) { return $this->jeden('organy', 'id_organ', $id_organ); });
        return $o ? $o['nazev_organu_cz'] : null;
    }

    public function stav($id_stav): ?array
    {
        return $this->jeden('stavy', 'id_stav', $id_stav);
    }

    public function popis_stavu($id_stav): ?string
    {
        $s = $this->stav($id_stav);
        if (!$s) {
            return null;
        }
        $t = $this->jeden('typ_stavu', 'id_typ', $s['id_typ']);
        if (!$t) {
            return null;
        }
        $p = trim((string)($t['popis_stavu'] ?? ''));
        return $p === '' ? null : $p;
    }

    public function lhuta_stavu($id_stav): ?string
    {
        $s = $this->stav($id_stav);
        if (!$s) {
            return null;
        }
        $p = trim((string)($s['popis'] ?? ''));
        return $p === '' ? null : $p;
    }

    public function druh($id_druh): ?string
    {
        $d = $this->jeden('druh_tisku', 'id_druh', $id_druh);
        return $d ? $d['nazev_druh'] : null;
    }

    // ---------------- volební období ----------------

    public function posledni_obdobi(): ?int
    {
        if ($this->c->posledni === false) {
            $s = $this->obdobi_seznam();
            $this->c->posledni = $s ? $s[0]->cislo : null;
        }
        return $this->c->posledni;
    }

    /** @return Obdobi[] od nejnovějšího */
    public function obdobi_seznam(): array
    {
        if ($this->c->seznam_obdobi === null) {
            $s = [];
            foreach ($this->bezpecne(function () { return $this->tabulka('organy'); }, []) as $o) {
                if (preg_match('/^PSP(\d+)$/', trim((string)($o['zkratka'] ?? '')), $m)) {
                    $s[] = new Obdobi($o['id_organ'], (int)$m[1], jako_cas($o['od_organ']), jako_cas($o['do_organ']), $o['nazev_organu_cz']);
                }
            }
            usort($s, function ($a, $b) { return $b->cislo <=> $a->cislo; });
            $this->c->seznam_obdobi = $s;
        }
        return $this->c->seznam_obdobi;
    }

    /** Zvolené období, nebo null = všechna. */
    public function obdobi(): ?Obdobi
    {
        if ($this->obdobi_vypocet === null) {
            $this->obdobi_vypocet = $this->urci_obdobi($this->obdobi_param) ?: false;
        }
        return $this->obdobi_vypocet ?: null;
    }

    private function urci_obdobi($param): ?Obdobi
    {
        if (is_string($param) && in_array(mb_strtolower($param), ['vse', 'vše', '*', 'all'], true)) {
            return null;
        }
        $seznam = $this->obdobi_seznam();
        if (!$seznam) {
            $ids = Db::sloupec('SELECT DISTINCT id_org_obd FROM ' . self::tab('tisky') . ' WHERE id_org_obd IS NOT NULL AND id_org_obd <> 0 ORDER BY id_org_obd');
            if ($param === null && $ids) {
                $p = end($ids);
                return new Obdobi($p, null, null, null, "období id $p");
            }
            if (is_int($param) && in_array($param, $ids)) {
                return new Obdobi($param, null, null, null, "období id $param");
            }
            throw new OdokChyba('Volební období nelze určit (chybí poslanci.zip).');
        }
        if ($param === null) {
            return $seznam[0];
        }
        $param = (int)$param;
        foreach ($seznam as $o) {
            if ($param === $o->cislo || $param === $o->id_organ || ($param > 1900 && $o->rok_od() === $param)) {
                return $o;
            }
        }
        foreach ($seznam as $o) {
            if ($param > 1900 && $o->od && $o->rok_od() <= $param && ($o->do === null || $o->rok_do() >= $param)) {
                return $o;
            }
        }
        throw new OdokChyba(sprintf('Volební období %s nenalezeno. Dostupná: %s', $param,
            implode(', ', array_map(function ($o) { return sprintf('%d (%s)', $o->cislo, py_str($o->rok_od())); }, $seznam))));
    }

    // ---------------- Sbírka zákonů: novelizace ----------------

    /** Jsou v databázi data Sbírky (sbirka.zip)? */
    public function ma_sbirku(): bool
    {
        try {
            return (bool)Db::hodnota('SELECT 1 FROM ' . self::tab('sb_pre') . ' LIMIT 1');
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Předpisy s číslem a rokem (obvykle jeden). */
    public function predpisy(int $cislo, int $rok): array
    {
        return array_values(array_filter($this->bezpecne(function () use ($cislo) {
            return $this->radky('sbirka', 'cislo', $cislo);
        }, []), function ($r) use ($rok) { return (int)$r['rok'] === $rok; }));
    }

    public function predpis($id_sbirka): ?array
    {
        return $this->bezpecne(function () use ($id_sbirka) { return $this->jeden('sbirka', 'id_sbirka', $id_sbirka); });
    }

    /** '280/2009 Sb.' */
    public static function oznaceni_predpisu(array $r): string
    {
        return sprintf('%s/%s Sb.', py_str($r['cislo']), py_str($r['rok']));
    }

    /** '280/2009' – identifikátor předpisu (oblíbené, adresy). */
    public static function ident_predpisu(array $r): string
    {
        return sprintf('%s/%s', py_str($r['cislo']), py_str($r['rok']));
    }

    public function druh_predpisu($id_dp): ?string
    {
        $r = $this->bezpecne(function () use ($id_dp) { return $this->jeden('druh_predpisu', 'id_dp', $id_dp); });
        return $r ? $r['nazev_druhu'] : null;
    }

    /** Název předpisu – z e-Sbírky (datová sada metadat), jinak z tisku, ze kterého vzešel; jinak null. */
    public function nazev_predpisu(array $r): ?string
    {
        $e = self::esb_predpis((int)$r['cislo'], (int)$r['rok']);
        if ($e) {
            return self::nazev_esb($e);
        }
        if (!py_bool($r['id_tisk'])) {
            return null;
        }
        $t = $this->jeden('tisky', 'id_tisk', $r['id_tisk']);
        return $t ? py_or($t['nazev_tisku'], $t['uplny_nazev_tisku']) : null;
    }

    /** Předpis Sbírky zákonů z e-Sbírky (tabulka esb_predpis) nebo null. */
    public static function esb_predpis(int $cislo, int $rok): ?array
    {
        static $memo = [];
        $k = "$cislo/$rok";
        if (!array_key_exists($k, $memo)) {
            try {
                $memo[$k] = Db::radek("SELECT * FROM {p}esb_predpis WHERE rok = ? AND cislo = ? AND sbirka = 'sb' LIMIT 1", [$rok, $cislo]);
            } catch (PDOException $e) {
                $memo[$k] = null;
            }
        }
        return $memo[$k];
    }

    /**
     * Čitelný název: vlastní jméno předpisu z citačního názvu („Daňový řád“, „Občanský zákoník“),
     * jinak plný název („Zákon o dani z přidané hodnoty“).
     */
    public static function nazev_esb(array $e): ?string
    {
        $citace = trim((string)$e['nazev_citace'], " .\t");
        $nazev = trim((string)$e['nazev'], " .\t");
        if ($citace !== '' && !preg_match('~^(o|k|ke|na|ze|z|,|kterým|kterou|kterými|jímž|jíž|jimiž|jímžto|ve|v|za|pro|při|u|do)(?=[\s,]|$)~u', $citace)) {
            return mb_strtoupper(mb_substr($citace, 0, 1)) . mb_substr($citace, 1);
        }
        return $nazev !== '' ? $nazev : null;
    }

    /** Zkratky předpisu z e-Sbírky ('DŘ', 'DPH', …). */
    public static function zkratky_esb(array $e): array
    {
        $v = [];
        foreach (array_merge([(string)$e['nazev_zkraceny']], explode('|', (string)$e['dalsi_nazvy'])) as $z) {
            $z = trim($z);
            if ($z !== '' && !in_array($z, $v, true) && mb_strtolower($z) !== mb_strtolower((string)self::nazev_esb($e))) {
                $v[] = $z;
            }
        }
        return $v;
    }

    /** Je v databázi seznam předpisů z e-Sbírky? */
    public static function ma_esbirku(): bool
    {
        try {
            return (bool)Db::hodnota("SELECT 1 FROM {p}esb_predpis LIMIT 1");
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Hledání předpisu podle názvu nebo zkratky (e-Sbírka, jen Sbírka zákonů): všechna slova musí sedět.
     * Shoda se zkratkou nebo citačním názvem vpředu, pak zákony, pak novější.
     */
    public static function hledej_predpisy(string $q, int $limit = 50): array
    {
        $slova = array_values(array_filter(preg_split('/\s+/', bez_diakritiky(trim($q))), function ($s) { return mb_strlen($s) >= 2; }));
        if (!$slova) {
            return [];
        }
        $podminky = [];
        $par = [];
        foreach ($slova as $s) {
            $podminky[] = 'hledani LIKE ?';
            $par[] = '%' . addcslashes($s, '%_\\') . '%';
        }
        try {
            $radky = Db::vse("SELECT * FROM {p}esb_predpis WHERE sbirka = 'sb' AND " . implode(' AND ', $podminky) . ' LIMIT 2000', $par);
        } catch (PDOException $e) {
            return [];
        }
        $cele = bez_diakritiky(trim($q));
        usort($radky, function ($a, $b) use ($cele) {
            $skore = function ($r) use ($cele) {
                $s = 0;
                foreach (array_merge([(string)$r['nazev_zkraceny'], (string)$r['nazev_citace']], explode('|', (string)$r['dalsi_nazvy'])) as $z) {
                    if ($z !== '' && trim(bez_diakritiky($z), ' .') === $cele) {
                        $s += 100;
                    }
                }
                $s += $r['druh'] === 'ZAKON' ? 10 : 0;
                $s += $r['zruseno'] ? -20 : 0;
                return $s;
            };
            return [$skore($b), (int)$b['rok'], (int)$b['cislo']] <=> [$skore($a), (int)$a['rok'], (int)$a['cislo']];
        });
        return array_slice($radky, 0, $limit);
    }

    /**
     * Sněmovní tisky, které předpis(y) mění nebo ruší: [['tisk' => Tisk, 'typ' => 31|32, 'cz' => int], …]
     * od nejnovějšího. Senátní tisky (zdroj 2) se vynechávají.
     */
    public function zmeny_predpisu(array $id_sbirka): array
    {
        $vazby = [];
        foreach ($id_sbirka as $id) {
            foreach ($this->bezpecne(function () use ($id) { return $this->radky('sb_pre', 'id_sbirka', $id); }, []) as $r) {
                if ((int)($r['zdroj'] ?? 1) === 1 && in_array((int)$r['typ'], [self::ZMENA_MENI, self::ZMENA_RUSI], true)) {
                    $k = (int)$r['id_tisk'];
                    if (!isset($vazby[$k]) || (int)$r['typ'] === self::ZMENA_RUSI) {
                        $vazby[$k] = $r;
                    }
                }
            }
        }
        $this->prednacti('tisky', 'id_tisk', array_keys($vazby));
        $this->prednacti_pro_tisky(array_keys($vazby));
        $v = [];
        foreach ($vazby as $id_tisk => $r) {
            $t = $this->jeden('tisky', 'id_tisk', $id_tisk);
            if ($t) {
                $v[] = ['tisk' => new Tisk($t, $this), 'typ' => (int)$r['typ'], 'cz' => (int)($r['cz'] ?? 0)];
            }
        }
        usort($v, function ($a, $b) {
            return [iso($b['tisk']->predlozeno()) ?? '', $b['tisk']->id_tisk()] <=> [iso($a['tisk']->predlozeno()) ?? '', $a['tisk']->id_tisk()];
        });
        return $v;
    }

    /** Předpisy, které tisk mění / ruší: [['predpis' => řádek sbirka, 'typ' => 31|32, 'cz' => int], …]. */
    public function predpisy_tisku($id_tisk): array
    {
        $v = [];
        foreach ($this->bezpecne(function () use ($id_tisk) { return $this->radky('sb_pre', 'id_tisk', $id_tisk); }, []) as $r) {
            if ((int)($r['zdroj'] ?? 1) !== 1 || !in_array((int)$r['typ'], [self::ZMENA_MENI, self::ZMENA_RUSI], true)) {
                continue;
            }
            $p = $this->predpis($r['id_sbirka']);
            if ($p) {
                $v[] = ['predpis' => $p, 'typ' => (int)$r['typ'], 'cz' => (int)($r['cz'] ?? 0)];
            }
        }
        usort($v, function ($a, $b) {
            return [(int)$a['predpis']['rok'], (int)$a['predpis']['cislo']] <=> [(int)$b['predpis']['rok'], (int)$b['predpis']['cislo']];
        });
        return $v;
    }

    public function v_obdobi_tisk(array $r): bool
    {
        $o = $this->obdobi();
        return $o === null || ($r['id_org_obd'] ?? null) === $o->id_organ;
    }

    public function cislo_obdobi($id_org_obd): ?int
    {
        foreach ($this->obdobi_seznam() as $o) {
            if ($o->id_organ === $id_org_obd) {
                return $o->cislo;
            }
        }
        return null;
    }

    // ---------------- tisky ----------------

    private function tisky_radky(?array $druhy = null): array
    {
        $o = $this->obdobi();
        $kde = [];
        $param = [];
        if ($o !== null) {
            $kde[] = 'id_org_obd = ?';
            $param[] = $o->id_organ;
        }
        if ($druhy !== null) {
            $kde[] = 'id_druh IN (' . Db::in($druhy) . ')';
            $param = array_merge($param, $druhy);
        }
        $radky = Db::vse('SELECT * FROM ' . self::tab('tisky') . ($kde ? ' WHERE ' . implode(' AND ', $kde) : '') . ' ORDER BY _r', $param);
        foreach ($radky as $r) {
            $this->c->memo['tisky.id_tisk'][self::klic($r['id_tisk'])] = $this->c->memo['tisky.id_tisk'][self::klic($r['id_tisk'])] ?? [$r];
        }
        return $radky;
    }

    /** @return Tisk[] */
    public function tisky(): array
    {
        return array_map(function ($r) { return new Tisk($r, $this); }, $this->tisky_radky());
    }

    /** @return Tisk[] návrhy zákonů ve zvoleném období */
    public function zakony(bool $jen_vladni = false, bool $jen_neprojednane = false, bool $jen_ukoncene = false): array
    {
        $radky = $this->tisky_radky(array_keys(self::DRUHY_ZAKONU));
        $this->prednacti_pro_tisky(array_column($radky, 'id_tisk'));
        $v = [];
        foreach ($radky as $r) {
            $t = new Tisk($r, $this);
            $druh = $r['id_druh'];
            if ($jen_vladni && !in_array($druh, self::DRUHY_VLADNI, true)) {
                continue;
            }
            if ($jen_neprojednane && ($t->projednano() || $t->revokovano())) {
                continue;
            }
            if ($jen_ukoncene && !($t->projednano() || $t->revokovano())) {
                continue;
            }
            $v[] = $t;
        }
        usort($v, function ($a, $b) { return ($a->ct() ?? 0) <=> ($b->ct() ?? 0); });
        return $v;
    }

    /** Předběžné načtení souvisejících řádků pro více tisků najednou. */
    public function prednacti_pro_tisky(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, function ($x) { return $x !== null; })));
        if (!$ids) {
            return;
        }
        $this->prednacti('hist', 'id_tisk', $ids);
        $this->prednacti('bod_schuze', 'id_tisk', $ids);
        $this->prednacti('tisky_za', 'id_tisk', $ids);
        $this->prednacti('tisk_eklep', 'id_tisk', $ids);
        $this->prednacti('tz_eklep', 'id_tisk', $ids);
        $this->bezpecne(function () use ($ids) { $this->prednacti('hist_vybory', 'id_tisku', $ids); });
        $this->bezpecne(function () use ($ids) { $this->prednacti('predkladatel', 'id_tisk', $ids); });
        $this->bezpecne(function () use ($ids) { $this->prednacti('psp2senat', 'id_psp', $ids); });
        $bodu = [];
        $poslanci = [];
        $senat = [];
        foreach ($ids as $id) {
            foreach ($this->c->memo['hist.id_tisk'][self::klic($id)] ?? [] as $h) {
                if ($h['id_bod']) {
                    $bodu[] = $h['id_bod'];
                }
                foreach (['orgv_id_posl', 'ps_id_posl'] as $k) {
                    if ($h[$k]) {
                        $poslanci[] = $h[$k];
                    }
                }
            }
            foreach ($this->c->memo['psp2senat.id_psp'][self::klic($id)] ?? [] as $v) {
                $senat[] = $v['id_senat'];
            }
        }
        $this->prednacti('bod_schuze', 'id_bod', $bodu);
        $this->bezpecne(function () use ($senat) { $this->prednacti('se_tisk', 'id_tisk', $senat); });
        $this->bezpecne(function () use ($poslanci) {
            $this->prednacti('poslanec', 'id_poslanec', $poslanci);
            $osoby = [];
            foreach ($poslanci as $p) {
                foreach ($this->c->memo['poslanec.id_poslanec'][self::klic($p)] ?? [] as $r) {
                    $osoby[] = $r['id_osoba'];
                }
            }
            $this->prednacti('osoby', 'id_osoba', $osoby);
        });
    }

    /** Sněmovní tisk podle čísla (123, 123/0, 123-E), PID eKLEPu, čj OVA nebo id:<id_tisk>. */
    public function tisk($identifikator): Tisk
    {
        $s = trim((string)$identifikator);
        if (strtolower(substr($s, 0, 3)) === 'id:') {
            $r = $this->jeden('tisky', 'id_tisk', (int)substr($s, 3));
            if ($r) {
                return new Tisk($r, $this);
            }
            throw new OdokChyba(sprintf('Tisk s id_tisk %s nenalezen.', substr($s, 3)));
        }
        if (preg_match('/^[A-Za-z0-9]{12}$/', $s) && !ctype_digit($s)) {
            $t = $this->tisk_k_materialu($s);
            if ($t) {
                return $t;
            }
            throw new OdokChyba(sprintf('K PID %s není v datech Sněmovny žádný tisk.', strtoupper($s)));
        }
        if (preg_match('/^(OVA\s*)?(\d+)\s*\/\s*(\d{2}|\d{4})$/i', $s, $m) && ($m[1] !== '' || strlen($m[3]) === 4)) {
            $c = (int)$m[2];
            $r = (int)$m[3] % 100;
            foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
                $radky = $this->bezpecne(function () use ($tab, $c) {
                    try {
                        return Db::vse('SELECT * FROM ' . self::tab($tab) . ' WHERE ova_c = ? ORDER BY _r', [$c]);
                    } catch (PDOException $e) {
                        throw new OdokChyba($e->getMessage());
                    }
                }, []);
                foreach ($radky as $e) {
                    if (is_int($e['ova_r']) && $e['ova_r'] % 100 === $r) {
                        $t = $this->jeden('tisky', 'id_tisk', $e['id_tisk']);
                        if ($t) {
                            return new Tisk($t, $this);
                        }
                    }
                }
            }
            throw new OdokChyba(sprintf('K čj OVA %d/%02d není v datech Sněmovny žádný tisk.', $c, $r));
        }
        if (preg_match('/^(\d+)(?:\s*-\s*E)?(?:\s*\/\s*(\d+))?$/i', $s, $m)) {
            $ct = (int)$m[1];
            $kandidati = array_values(array_filter($this->radky('tisky', 'ct', $ct), [$this, 'v_obdobi_tisk']));
            if ($kandidati) {
                usort($kandidati, function ($a, $b) { return ($b['id_org_obd'] ?? 0) <=> ($a['id_org_obd'] ?? 0); });
                return new Tisk($kandidati[0], $this);
            }
            throw new OdokChyba(sprintf('Tisk %s ve volebním období %s nenalezen.', $s,
                $this->obdobi() ? py_str($this->obdobi()->cislo) : '(všechna)'));
        }
        throw new OdokChyba(sprintf('Nerozumím identifikátoru tisku: %s', "'" . $identifikator . "'"));
    }

    public function tisk_v_obdobi($ct, $cislo_obdobi): ?Tisk
    {
        $ids = [];
        foreach ($this->obdobi_seznam() as $o) {
            if ($o->cislo === (int)$cislo_obdobi) {
                $ids[] = $o->id_organ;
            }
        }
        foreach ($this->radky('tisky', 'ct', (int)$ct) as $r) {
            if (in_array($r['id_org_obd'], $ids, true) && !py_bool($r['cislo_za'])) {
                return new Tisk($r, $this);
            }
        }
        return null;
    }

    /** Tisk navázaný na materiál eKLEPu (PID), nebo null. Hledá ve všech obdobích. */
    public function tisk_k_materialu($pid): ?Tisk
    {
        $pid = strtoupper(trim((string)$pid));
        foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
            try {
                $radky = Db::vse('SELECT * FROM ' . self::tab($tab) . ' WHERE UPPER(TRIM(pid)) = ? ORDER BY _r', [$pid]);
            } catch (PDOException $e) {
                continue;
            }
            foreach ($radky as $e) {
                $t = $this->jeden('tisky', 'id_tisk', $e['id_tisk']);
                if ($t) {
                    return new Tisk($t, $this);
                }
            }
        }
        foreach (self::ova_materialu([$pid]) as $ova) {      // záložně podle čj OVA (viz tisky_podle_ova)
            foreach ($this->tisky_podle_ova($ova) as $id) {
                $t = $this->jeden('tisky', 'id_tisk', $id);
                if ($t) {
                    return new Tisk($t, $this);
                }
            }
        }
        return null;
    }

    /**
     * Sněmovní tisky se stejným čj OVA (Úřad vlády) jako materiál – záložní párování, když tisk odkazuje na jiné PID.
     * Typicky státní rozpočet: vládě jde jeden materiál, do Sněmovny dva tisky a jeden z nich dostane nový materiál
     * v eKLEP (nové PID, čj OVA s příponou, např. 671/26-01). Vrací id_tisk podle pořadí v datech.
     */
    public function tisky_podle_ova($cj_ova): array
    {
        if (!preg_match('/^\s*(\d+)\s*\/\s*(\d{2,4})/u', (string)($cj_ova ?? ''), $m)) {
            return [];
        }
        $c = (int)$m[1];
        $r = (int)$m[2] % 100;
        $v = [];
        foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
            try {
                $radky = Db::vse('SELECT id_tisk FROM ' . self::tab($tab) . ' WHERE ova_c = ? AND ova_r IN (?, ?) AND id_tisk IS NOT NULL ORDER BY _r',
                    [$c, $r, 2000 + $r]);
            } catch (PDOException $e) {
                continue;
            }
            foreach ($radky as $x) {
                $v[(int)$x['id_tisk']] = true;
            }
        }
        return array_keys($v);
    }

    /** Materiály ODOK se stejným čj OVA (i s příponou -01 …): [PID => ['cj_ova' => …, 'nazev' => …]]. */
    public static function materialy_podle_ova($cj_ova): array
    {
        if (!preg_match('/^\s*(\d+)\s*\/\s*(\d{2,4})/u', (string)($cj_ova ?? ''), $m)) {
            return [];
        }
        $vzor = sprintf('^[[:space:]]*%d[[:space:]]*/[[:space:]]*(20)?%02d([^0-9]|$)', (int)$m[1], (int)$m[2] % 100);
        $v = [];
        try {
            foreach (Db::vse('SELECT pid, cj_ova, nazev FROM {p}odok_material WHERE cj_ova REGEXP ? ORDER BY rok DESC', [$vzor]) as $r) {
                $k = strtoupper(trim((string)$r['pid']));
                if ($k !== '' && !isset($v[$k])) {
                    $v[$k] = ['cj_ova' => $r['cj_ova'], 'nazev' => $r['nazev']];
                }
            }
        } catch (PDOException $e) {
        }
        return $v;
    }

    /** čj OVA materiálů ODOK podle PID: [PID => čj OVA]. */
    public static function ova_materialu(array $pidy): array
    {
        $v = [];
        foreach (array_chunk($pidy, 1000) as $kus) {
            try {
                foreach (Db::vse('SELECT UPPER(pid) AS p, cj_ova FROM {p}odok_material WHERE UPPER(pid) IN (' . Db::in($kus) . ') AND cj_ova IS NOT NULL', $kus) as $r) {
                    if (trim((string)$r['cj_ova']) !== '' && !isset($v[$r['p']])) {
                        $v[$r['p']] = $r['cj_ova'];
                    }
                }
            } catch (PDOException $e) {
                return $v;
            }
        }
        return $v;
    }

    /** id_tisk sněmovního tisku navázaného na PID (pro sloupec „Tisk PS“), hromadně. */
    public function pid_tisk(array $pidy): array
    {
        $pidy = array_values(array_unique(array_filter(array_map(function ($p) { return strtoupper((string)$p); }, $pidy))));
        $v = [];
        if (!$pidy) {
            return $v;
        }
        foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
            foreach (array_chunk($pidy, 1000) as $kus) {
                try {
                    $radky = Db::vse('SELECT UPPER(TRIM(pid)) AS p, id_tisk FROM ' . self::tab($tab) . ' WHERE UPPER(TRIM(pid)) IN (' . Db::in($kus) . ') AND id_tisk IS NOT NULL ORDER BY _r', $kus);
                } catch (PDOException $e) {
                    continue;
                }
                foreach ($radky as $r) {
                    if ($r['p'] !== '' && !isset($v[$r['p']])) {
                        $v[$r['p']] = $r['id_tisk'];
                    }
                }
            }
        }
        // záložně podle čj OVA materiálu (tisk odkazuje na jiné PID – rozdělený materiál, státní rozpočet)
        $chybi = array_values(array_diff($pidy, array_keys($v)));
        if ($chybi) {
            $podle_ova = [];        // "číslo/rr" => [PID, …]
            foreach (self::ova_materialu($chybi) as $pid => $ova) {
                if (preg_match('/^\s*(\d+)\s*\/\s*(\d{2,4})/u', (string)$ova, $m)) {
                    $podle_ova[sprintf('%d/%02d', (int)$m[1], (int)$m[2] % 100)][] = $pid;
                }
            }
            $cisla = array_values(array_unique(array_map(function ($k) { return (int)$k; }, array_keys($podle_ova))));
            foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
                foreach (array_chunk($cisla, 1000) as $kus) {
                    if (!$kus) {
                        continue;
                    }
                    try {
                        $radky = Db::vse('SELECT ova_c, ova_r, id_tisk FROM ' . self::tab($tab) . ' WHERE ova_c IN (' . Db::in($kus) . ') AND id_tisk IS NOT NULL ORDER BY _r', $kus);
                    } catch (PDOException $e) {
                        continue;
                    }
                    foreach ($radky as $r) {
                        foreach ($podle_ova[sprintf('%d/%02d', (int)$r['ova_c'], (int)$r['ova_r'] % 100)] ?? [] as $pid) {
                            $v[$pid] = $v[$pid] ?? $r['id_tisk'];
                        }
                    }
                }
            }
        }
        return $v;
    }

    // ---------------- schůze ----------------

    private function schuze_radky(): array
    {
        $vse = $this->tabulka('schuze');
        $o = $this->obdobi();
        if ($o === null) {
            return $vse;
        }
        $podle_org = array_values(array_filter($vse, function ($s) use ($o) { return $s['id_org'] === $o->id_organ; }));
        if ($podle_org) {
            return $podle_org;
        }
        return array_values(array_filter($vse, function ($s) use ($o) {
            $od = jako_cas($s['od_schuze']);
            return $o->od && $od && $od >= $o->od && ($o->do === null || $od <= $o->do);
        }));
    }

    public function schuze(): array
    {
        $podle_id = [];
        foreach ($this->schuze_radky() as $s) {
            $st = $podle_id[$s['id_schuze'] ?? ''] ?? null;
            if ($st === null || (py_bool($st['pozvanka']) && !py_bool($s['pozvanka']))) {
                $podle_id[$s['id_schuze'] ?? ''] = $s;
            }
        }
        $seznam = [];
        foreach ($podle_id as $s) {
            $seznam[] = ['id_schuze' => $s['id_schuze'], 'cislo' => $s['schuze'], 'od' => $s['od_schuze'], 'do' => $s['do_schuze'],
                'navrzeny_porad' => py_bool($s['pozvanka']), 'url' => $this->url_schuze($s['schuze'])];
        }
        usort($seznam, function ($a, $b) { return ($b['cislo'] ?? 0) <=> ($a['cislo'] ?? 0); });
        return $seznam;
    }

    public function url_schuze($cislo): ?string
    {
        $o = $this->obdobi();
        if ($cislo === null || $o === null || $o->cislo === null) {
            return null;
        }
        return vzor(URL_SCHUZE, ['o' => $o->cislo, 'schuze' => $cislo]);
    }

    /** Řádky bod_schuze → BodSchuze; u stejného bodu má přednost schválený pořad. */
    public function body(array $radky): array
    {
        $schuze = [];
        foreach ($this->tabulka('schuze') as $s) {
            $st = $schuze[$s['id_schuze'] ?? ''] ?? null;
            if ($st === null || (py_bool($st['pozvanka']) && !py_bool($s['pozvanka']))) {
                $schuze[$s['id_schuze'] ?? ''] = $s;
            }
        }
        $vybrane = [];
        foreach ($radky as $b) {
            $st = $vybrane[self::klic($b['id_bod'])] ?? null;
            if ($st === null || (py_bool($st['pozvanka']) && !py_bool($b['pozvanka']))) {
                $vybrane[self::klic($b['id_bod'])] = $b;
            }
        }
        $body = [];
        foreach ($vybrane as $b) {
            $body[] = new BodSchuze($b, $schuze[$b['id_schuze'] ?? ''] ?? null, $this);
        }
        usort($body, function ($a, $b) {
            return [$a->od ?? '0000', $a->bod ?? 0] <=> [$b->od ?? '0000', $b->bod ?? 0];
        });
        return $body;
    }

    public function body_schuze(int $cislo_schuze): array
    {
        $ids = [];
        foreach ($this->schuze_radky() as $s) {
            if ($s['schuze'] === $cislo_schuze) {
                $ids[$s['id_schuze']] = $s['id_schuze'];
            }
        }
        if (!$ids) {
            throw new OdokChyba("Schůze $cislo_schuze ve zvoleném období nenalezena.");
        }
        $ids = array_values($ids);
        $radky = Db::vse('SELECT * FROM ' . self::tab('bod_schuze') . ' WHERE id_schuze IN (' . Db::in($ids) . ') AND (druh_bodu IS NULL OR druh_bodu <> ?) ORDER BY _r',
            array_merge($ids, [self::DRUH_BODU_PORAD]));
        $tisky = array_filter(array_column($radky, 'id_tisk'));
        $this->prednacti('tisky', 'id_tisk', $tisky);
        $body = $this->body($radky);
        usort($body, function ($a, $b) { return ($a->bod ?? 0) <=> ($b->bod ?? 0); });
        return $body;
    }
}

// ---------------------------------------------------------------- svátky a lhůty

function velikonocni_nedele(int $rok): string
{
    $a = $rok % 19; $b = intdiv($rok, 100); $c = $rok % 100;
    $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mesic = intdiv($h + $l - 7 * $m + 114, 31);
    $den = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $rok, $mesic, $den);
}

function svatky(int $rok): array
{
    $v = [];
    foreach ([[1, 1], [5, 1], [5, 8], [7, 5], [7, 6], [9, 28], [10, 28], [11, 17], [12, 24], [12, 25], [12, 26]] as [$m, $d]) {
        $v[sprintf('%04d-%02d-%02d', $rok, $m, $d)] = true;
    }
    $n = velikonocni_nedele($rok);
    $v[pridej_dny($n, 1)] = true;
    if ($rok >= 2016) {
        $v[pridej_dny($n, -2)] = true;
    }
    return $v;
}

function je_pracovni_den(string $d): bool
{
    return (int)date('N', strtotime($d . ' 12:00:00')) < 6 && !isset(svatky((int)substr($d, 0, 4))[$d]);
}

function konec_lhuty(?string $zacatek, int $dni): ?string
{
    if ($zacatek === null) {
        return null;
    }
    $konec = pridej_dny(substr($zacatek, 0, 10), $dni);
    while (!je_pracovni_den($konec)) {
        $konec = pridej_dny($konec, 1);
    }
    return $konec;
}

// ---------------------------------------------------------------- Krok, BodSchuze, Tisk

class Krok
{
    public $data, $id_hist, $datum, $akce, $stav, $stav_predtim, $typ_kam, $typ_odkud, $etapa, $etapa_predtim,
        $schuze, $usneseni, $id_hlasovani, $poznamka, $bod, $zpravodaj;

    public function __construct(array $r, Psp $psp)
    {
        $this->data = $r;
        $prechod = $psp->jeden('prechody', 'id_prechod', $r['id_prechod']) ?? [];
        $akce = $psp->jeden('typ_akce', 'id_akce', $prechod['id_akce'] ?? null) ?? [];
        $this->id_hist = $r['id_hist'];
        $this->datum = $r['datum'];
        $this->akce = $akce['popis_akce'] ?? null;
        $this->stav = $psp->popis_stavu($prechod['kam'] ?? null);
        $this->stav_predtim = $psp->popis_stavu($prechod['odkud'] ?? null);
        $this->typ_kam = ($psp->stav($prechod['kam'] ?? null) ?? [])['id_typ'] ?? null;
        $this->typ_odkud = ($psp->stav($prechod['odkud'] ?? null) ?? [])['id_typ'] ?? null;
        $this->etapa = Psp::etapa_podle_typu($this->typ_kam);
        $this->etapa_predtim = Psp::etapa_podle_typu($this->typ_odkud);
        $this->schuze = $r['schuze'];
        $this->usneseni = $r['usnes_ps'];
        $this->id_hlasovani = $r['id_hlas'];
        $this->poznamka = $r['poznamka'];
        $bod = py_bool($r['id_bod']) ? $psp->jeden('bod_schuze', 'id_bod', $r['id_bod']) : null;
        $this->bod = $bod ? $bod['bod'] : null;
        $zp = py_or($r['orgv_id_posl'], $r['ps_id_posl']);
        $this->zpravodaj = py_bool($zp) ? $psp->poslanec($zp) : null;
        if ($this->typ_kam === Psp::TYP_STAVU_PROJEDNANO) {
            $this->stav = Psp::stav_ukonceni($this->akce, (bool)$this->sbirka()) ?? $this->stav;
        }
    }

    /**
     * Zaznamenává krok vyhlášení? Jen krok, kterým projednávání končí (přechod do KONEC), nebo akce „vyhlášen“.
     * Pole zaver_sb_cislo / zaver_publik mají v datech Sněmovny vyplněné i jiné kroky (např. „změna lhůty
     * pozmň. návrhu“ s hodnotou 7), které s vyhlášením nesouvisejí.
     */
    public function je_vyhlaseni(): bool
    {
        return $this->typ_kam === Psp::TYP_STAVU_PROJEDNANO || strpos(bez_diakritiky((string)$this->akce), 'vyhl') !== false;
    }

    public function sbirka(): ?string
    {
        $c = $this->data['zaver_sb_cislo'];
        if (!py_bool($c) || !$this->je_vyhlaseni()) {
            return null;
        }
        $rok = je_datum($this->datum) ? substr($this->datum, 0, 4) : '';
        return sprintf('%s/%s Sb.', $c, $rok);
    }

    public function url_hlasovani(): ?string
    {
        return py_bool($this->id_hlasovani) ? vzor(URL_HLASOVANI, ['id' => $this->id_hlasovani]) : null;
    }
}

class BodSchuze
{
    public $data, $id_bod, $id_tisk, $bod, $schuze, $od, $do, $nazev, $typ, $stav, $navrzeny_porad, $url;

    public function __construct(array $r, ?array $s, Psp $psp)
    {
        $s = $s ?? [];
        $this->data = $r;
        $this->id_bod = $r['id_bod'];
        $this->id_tisk = $r['id_tisk'];
        $this->bod = $r['bod'];
        $this->schuze = $s['schuze'] ?? null;
        $this->od = jako_cas($s['od_schuze'] ?? null);
        $this->do = jako_cas($s['do_schuze'] ?? null);
        $this->nazev = implode(' ', array_filter([$r['uplny_naz'], $r['uplny_kon']], 'py_bool'));
        $this->typ = Psp::TYP_BODU[$r['id_typ'] ?? ''] ?? (py_bool($r['id_typ']) ? $r['id_typ'] : null);
        $st = $psp->jeden('bod_stav', 'id_bod_stav', $r['id_bod_stav']);
        $this->stav = $st ? $st['popis'] : null;
        $this->navrzeny_porad = py_bool($r['pozvanka']);
        $this->url = $psp->url_schuze($this->schuze);
    }

    public function __toString(): string
    {
        return sprintf('%s. schůze (%s–%s), bod %s: %s%s%s', py_str($this->schuze), fmt_datum($this->od), fmt_datum($this->do),
            py_str($this->bod), $this->typ ?? '', $this->stav ? ' – ' . $this->stav : '', $this->navrzeny_porad ? ' [navržený pořad]' : '');
    }
}

class Tisk
{
    public $data;
    private $psp;
    private $hist = null;

    public function __construct(array $r, Psp $psp)
    {
        $this->data = $r;
        $this->psp = $psp;
    }

    public function id_tisk() { return $this->data['id_tisk']; }
    public function ct() { return $this->data['ct']; }

    public function cislo(): string
    {
        $c = py_str($this->ct());
        if (in_array($this->data['id_druh'], Psp::DRUHY_EU, true)) {
            $c .= '-E';
        }
        if (py_bool($this->data['cislo_za'])) {
            $c .= '/' . $this->data['cislo_za'];
        }
        return $c;
    }

    public function nazev() { return $this->data['nazev_tisku']; }
    public function uplny_nazev() { return py_or($this->data['uplny_nazev_tisku'], $this->nazev()); }
    public function druh() { return $this->psp->druh($this->data['id_druh']); }
    public function obdobi() { return $this->psp->cislo_obdobi($this->data['id_org_obd']); }

    public function navrhovatel()
    {
        $d = $this->data;
        if (py_bool($d['navrhovatel'])) {
            return $d['navrhovatel'];
        }
        if (py_bool($d['id_osoba'])) {
            return $this->psp->osoba($d['id_osoba']);
        }
        if (py_bool($d['id_org'])) {
            return $this->psp->organ($d['id_org']);
        }
        return null;
    }

    public function typ_navrhovatele()
    {
        $t = $this->psp->jeden('typ_zakon', 'id_navrh', $this->data['id_navrh']);
        return $t ? $t['druh_navrhovatele'] : null;
    }

    public function predkladatele(): array
    {
        $radky = $this->psp->bezpecne(function () { return $this->psp->radky('predkladatel', 'id_tisk', $this->id_tisk()); }, []);
        usort($radky, function ($a, $b) { return ($a['poradi'] ?? 0) <=> ($b['poradi'] ?? 0); });
        return array_map(function ($r) { return $this->psp->osoba($r['id_osoba']); }, $radky);
    }

    public function predlozeno() { return $this->data['predlozeno']; }
    public function rozeslano() { return py_or($this->data['roz'], $this->data['rozeslano']); }
    public function dalsi_projednavani() { return $this->data['dal']; }

    public function url(): ?string
    {
        $o = $this->obdobi();
        return py_bool($o) ? vzor(URL_TISK, ['o' => $o, 'ct' => $this->ct()]) : null;
    }

    public function url_text() { return $this->data['t_url']; }

    /** Zobrazovaný stav místo „KONEC“ u tisků vyhlášených ve Sbírce. */
    const STAV_VYHLASENO = 'VYHLÁŠENO';

    public function stav(): ?string
    {
        if ($this->zanikl_koncem_obdobi()) {
            return 'ukončeno koncem volebního období';
        }
        return $this->stav_ukonceni() ?? $this->stav_v_datech();
    }

    /** Krok, kterým projednávání skončilo (přechod do stavu KONEC), je-li tisk ve stavu KONEC. */
    public function krok_ukonceni(): ?Krok
    {
        if ((($this->psp->stav($this->data['id_stav']) ?? [])['id_typ'] ?? null) !== Psp::TYP_STAVU_PROJEDNANO) {
            return null;
        }
        foreach (array_reverse($this->historie()) as $k) {
            if ($k->typ_kam === Psp::TYP_STAVU_PROJEDNANO) {
                return $k;
            }
        }
        return null;
    }

    /** VYHLÁŠENO / ZAMÍTNUTO / VZATO ZPĚT … místo „KONEC“ (null = nerozpoznáno nebo neskončilo). */
    public function stav_ukonceni(): ?string
    {
        $k = $this->krok_ukonceni();
        if ($k === null) {
            return (((($this->psp->stav($this->data['id_stav']) ?? [])['id_typ'] ?? null) === Psp::TYP_STAVU_PROJEDNANO) && $this->sbirka())
                ? self::STAV_VYHLASENO : null;
        }
        return Psp::stav_ukonceni($k->akce, (bool)$this->sbirka());
    }

    public function stav_v_datech(): ?string
    {
        return $this->psp->popis_stavu($this->data['id_stav']);
    }

    public function zanikl_koncem_obdobi(): bool
    {
        $s = $this->psp->stav($this->data['id_stav']) ?? [];
        if (($s['id_typ'] ?? null) === Psp::TYP_STAVU_PROJEDNANO || $this->revokovano()) {
            return false;
        }
        $posledni = $this->psp->posledni_obdobi();
        $o = $this->obdobi();
        return (bool)($o && $posledni && $o < $posledni);
    }

    public function lhuta() { return $this->psp->lhuta_stavu($this->data['id_stav']); }

    public function projednano(): bool
    {
        $s = $this->psp->stav($this->data['id_stav']) ?? [];
        return ($s['id_typ'] ?? null) === Psp::TYP_STAVU_PROJEDNANO || $this->zanikl_koncem_obdobi();
    }

    public function revokovano(): bool
    {
        return in_array($this->data['status'], [1, 2], true);
    }

    /** @return Krok[] od nejstaršího */
    public function historie(): array
    {
        if ($this->hist === null) {
            $radky = $this->psp->radky('hist', 'id_tisk', $this->id_tisk());
            usort($radky, function ($a, $b) {
                return [jako_cas($a['datum']) ?? '0000', $a['id_hist'] ?? 0] <=> [jako_cas($b['datum']) ?? '0000', $b['id_hist'] ?? 0];
            });
            $this->hist = array_map(function ($r) { return new Krok($r, $this->psp); }, $radky);
        }
        return $this->hist;
    }

    public function posledni_krok(): ?Krok
    {
        $h = $this->historie();
        return $h ? end($h) : null;
    }

    public function datum_stavu()
    {
        $k = $this->posledni_krok();
        return $k ? $k->datum : null;
    }

    /** Etapy projednávání: [název => ['od','do','akce','probiha', ('lhuta')]] v pořadí NAZVY_ETAP. */
    public function etapy(): array
    {
        $v = [];
        foreach ($this->historie() as $k) {
            if ($k->etapa && $k->etapa !== $k->etapa_predtim) {
                if (!isset($v[$k->etapa])) {
                    $v[$k->etapa] = ['od' => $k->datum, 'do' => null, 'akce' => null, 'probiha' => false];
                }
                $v[$k->etapa]['do'] = null;
                $v[$k->etapa]['akce'] = null;
            }
            if ($k->etapa_predtim && $k->etapa_predtim !== $k->etapa) {
                if (!isset($v[$k->etapa_predtim])) {
                    $v[$k->etapa_predtim] = ['od' => null, 'do' => null, 'akce' => null, 'probiha' => false];
                }
                $v[$k->etapa_predtim]['do'] = $k->datum;
                $v[$k->etapa_predtim]['akce'] = $k->akce;
            }
        }
        $pk = $this->posledni_krok();
        $aktualni = $pk ? $pk->etapa : null;
        if ($aktualni !== null && isset($v[$aktualni]) && $v[$aktualni]['do'] === null && !$this->zanikl_koncem_obdobi()) {
            $v[$aktualni]['probiha'] = true;
        }
        $this->zpresni_garancni_vybor($v);
        $senat = $this->lhuta_senatu();
        if ($senat && isset($v['Senát'])) {
            if (py_bool($senat['doruceno'])) {
                $v['Senát']['od'] = $senat['doruceno'];
            }
            $v['Senát']['lhuta'] = $senat;
        }
        $vysledek = [];
        foreach (Psp::NAZVY_ETAP as $n) {
            if (isset($v[$n])) {
                $vysledek[$n] = $v[$n];
            }
        }
        return $vysledek;
    }

    public function lhuta_senatu(?string $dnes = null): ?array
    {
        $kroky = $this->historie();
        $o = $d = null;
        $io = $id = -1;
        foreach ($kroky as $i => $k) {
            if ($k->typ_kam === Psp::TYP_STAVU_ODESILANO_SENATU && $k->typ_odkud !== Psp::TYP_STAVU_ODESILANO_SENATU) {
                $o = $k;
                $io = $i;
            }
            if ($k->typ_kam === Psp::TYP_STAVU_SENAT && $k->typ_odkud !== Psp::TYP_STAVU_SENAT) {
                $d = $k;
                $id = $i;
            }
        }
        if ($o === null && $d === null) {
            return null;
        }
        if ($o !== null && $d !== null && $io > $id) {
            $d = null;
        }
        if ($d !== null && jako_cas($d->datum) === null) {
            $d = null;
        }
        $v = ['odeslano' => $o ? $o->datum : null, 'doruceno' => $d ? $d->datum : null, 'lhuta_do' => null,
            'rozhodnuto' => null, 'akce' => null, 'zbyva_dni' => null, 'uplynula' => false];
        if ($d === null) {
            return $v;
        }
        $v['lhuta_do'] = konec_lhuty(jako_cas($d->datum), Psp::LHUTA_SENATU_DNI);
        if ($v['lhuta_do'] === null) {
            return $v;
        }
        $konec = null;
        for ($i = $id + 1; $i < count($kroky); $i++) {
            $k = $kroky[$i];
            if ($k->typ_odkud === Psp::TYP_STAVU_SENAT && $k->typ_kam !== Psp::TYP_STAVU_SENAT) {
                $konec = $k;
                break;
            }
        }
        if ($konec !== null) {
            $v['rozhodnuto'] = $konec->datum;
            $v['akce'] = $konec->akce;
        } elseif ($this->zanikl_koncem_obdobi()) {
            $v['zaniklo'] = true;
        } else {
            $dnes = $dnes ?: dnes();
            $v['zbyva_dni'] = rozdil_dni($dnes, $v['lhuta_do']);
            $v['uplynula'] = $v['zbyva_dni'] < 0;
        }
        return $v;
    }

    /** Usnesení garančního výboru jako následné tisky: [[datum, číslo], …] podle data. */
    public function usneseni_garancniho_vyboru(): array
    {
        $p = $this->psp;
        $garancni = [];
        foreach ($p->bezpecne(function () use ($p) { return $p->radky('hist_vybory', 'id_tisku', $this->id_tisk()); }, []) as $r) {
            if (py_bool($r['garancni'])) {
                $garancni[] = $r['id_organ'];
            }
        }
        $v = [];
        foreach ($p->radky('tisky_za', 'id_tisk', $this->id_tisk()) as $r) {
            if (!in_array($r['id_druh'], Psp::DRUHY_USNESENI_VYBORU, true)) {
                continue;
            }
            if ($garancni && !in_array($r['id_org'], $garancni, true)) {
                continue;
            }
            $datum = jako_cas(py_or($r['roz'], $r['rozeslano']));
            if ($datum) {
                $v[] = [$datum, sprintf('%s/%s', py_str($this->ct()), py_str($r['cislo_za']))];
            }
        }
        usort($v, function ($a, $b) { return $a <=> $b; });
        return $v;
    }

    private function zpresni_garancni_vybor(array &$etapy): void
    {
        $usneseni = $this->usneseni_garancniho_vyboru();
        if (!$usneseni) {
            return;
        }
        [$e1, $gv1, $e2, $gv2] = Psp::NAZVY_ETAP;
        $konec_1ct = jako_cas($etapy[$e1]['do'] ?? null);
        $konec_2ct = jako_cas($etapy[$e2]['do'] ?? null);
        foreach ([[$gv1, $konec_1ct, $konec_2ct], [$gv2, $konec_2ct, null]] as [$nazev, $od, $do]) {
            if (!isset($etapy[$nazev]) || $od === null) {
                continue;
            }
            $kandidati = array_values(array_filter($usneseni, function ($u) use ($od, $do) {
                return $u[0] >= $od && ($do === null || $u[0] <= $do);
            }));
            if ($kandidati) {
                [$datum, $cislo] = $nazev === $gv1 ? $kandidati[0] : end($kandidati);
                $etapy[$nazev]['do'] = $datum;
                $etapy[$nazev]['akce'] = "usnesení výboru (tisk $cislo)";
                $etapy[$nazev]['probiha'] = false;
            }
        }
    }

    /** Údaje pro přehled legislativních prací (sloupce J–O excelu PLPV). */
    public function pro_plpv(): array
    {
        $schvaleno = $senat = $prezident = [];
        $vyhlaseno = null;
        $fmt = function ($d) {
            $d = den($d);
            return $d ? sprintf('%d.%d.%d', (int)substr($d, 8, 2), (int)substr($d, 5, 2), (int)substr($d, 0, 4)) : '';
        };
        foreach ($this->historie() as $k) {
            $akce = $k->akce ?? '';
            $ba = bez_diakritiky($akce);
            $schvaleni = strpos($ba, 'schval') !== false || strpos($ba, 'setrv') !== false;
            if (in_array($k->typ_odkud, Psp::TYPY_3_CTENI, true) && !in_array($k->typ_kam, Psp::TYPY_3_CTENI, true)) {
                $schvaleno[] = $fmt($k->datum) . ($schvaleni ? '' : ' ' . $akce);
            } elseif (in_array($k->typ_odkud, Psp::TYPY_SNEMOVNA_PO_VRACENI, true) && !in_array($k->typ_kam, Psp::TYPY_SNEMOVNA_PO_VRACENI, true)) {
                $schvaleno[] = sprintf('%s (opakované hlasování%s)', $fmt($k->datum), $schvaleni ? '' : ': ' . $akce);
            } elseif ($k->typ_odkud === Psp::TYP_SENAT && $k->typ_kam !== Psp::TYP_SENAT) {
                $senat[] = $fmt($k->datum) . ($schvaleni ? '' : ' ' . $akce);
            } elseif ($k->typ_odkud === Psp::TYP_PREZIDENT && $k->typ_kam !== Psp::TYP_PREZIDENT) {
                $prezident[] = $fmt($k->datum) . (trim($ba) === 'podepsal' ? '' : ' ' . $akce);
            }
            if (py_bool($k->data['zaver_publik']) && $k->je_vyhlaseni()) {
                $vyhlaseno = $k->data['zaver_publik'];
            }
        }
        return [
            'postoupeno' => $this->predlozeno(), 'tisk' => $this->cislo(), 'obdobi' => $this->obdobi(),
            'schvaleno_ps' => $schvaleno ? implode(', ', $schvaleno) : null,
            'senat' => $senat ? implode(', ', $senat) : null,
            'prezident' => $prezident ? implode(', ', $prezident) : null,
            'sbirka' => $this->sbirka(), 'vyhlaseno' => $vyhlaseno, 'stav' => $this->stav(), 'projednano' => $this->projednano(),
        ];
    }

    public function aktualni_etapa(): ?string
    {
        $k = $this->posledni_krok();
        return $k ? $k->etapa : null;
    }

    public function sbirka(): ?string
    {
        foreach (array_reverse($this->historie()) as $k) {
            if ($k->sbirka()) {
                return $k->sbirka();
            }
        }
        return null;
    }

    /** @return BodSchuze[] */
    public function body_schuze(): array
    {
        return $this->psp->body($this->psp->radky('bod_schuze', 'id_tisk', $this->id_tisk()));
    }

    public function aktualni_bod(): ?BodSchuze
    {
        $body = $this->body_schuze();
        if (!$body) {
            return null;
        }
        $ted = ted();
        $budouci = array_values(array_filter($body, function ($b) use ($ted) {
            return (py_or($b->do, $b->od) ?? '0000') >= $ted;
        }));
        return $budouci ? $budouci[0] : end($body);
    }

    public function vybory(): array
    {
        $radky = $this->psp->bezpecne(function () { return $this->psp->radky('hist_vybory', 'id_tisku', $this->id_tisk()); }, []);
        usort($radky, function ($a, $b) { return ($a['poradi'] ?? 0) <=> ($b['poradi'] ?? 0); });
        $v = [];
        foreach ($radky as $r) {
            $v[] = [
                'vybor' => $this->psp->organ($r['id_organ']) ?? $r['id_organ'],
                'typ' => Psp::TYP_VYBORU[$r['typ'] ?? ''] ?? $r['typ'],
                'garancni' => in_array($r['garancni'], [1, 2], true),
                'zpravodaj' => py_bool($r['id_posl']) ? $this->psp->poslanec($r['id_posl']) : null,
            ];
        }
        return $v;
    }

    public function senatni_tisky(): array
    {
        $p = $this->psp;
        $vazby = $p->bezpecne(function () use ($p) { return $p->radky('psp2senat', 'id_psp', $this->id_tisk()); }, []);
        $v = [];
        foreach ($vazby as $vz) {
            $st = $p->bezpecne(function () use ($p, $vz) { return $p->jeden('se_tisk', 'id_tisk', $vz['id_senat']); });
            if (!$st) {
                continue;
            }
            $druh = $p->bezpecne(function () use ($p, $st) { return $p->jeden('se_druh_tisku', 'id_druh', $st['id_druh']); }) ?? [];
            $cislo = $st['cislo_tisku'];
            $obdobi = $st['id_obdobi'];
            if ($obdobi === 1 && is_int($cislo) && $cislo >= 1000) {
                $cislo_text = sprintf('%d/%d', intdiv($cislo, 1000), $cislo % 1000);
            } else {
                $cislo_text = $cislo !== null ? py_str($cislo) : '';
            }
            $v[] = [
                'id' => $st['id_tisk'], 'cislo' => $cislo_text, 'obdobi' => $obdobi, 'stav' => $st['id_stav'],
                'stav_nazev' => Psp::STAVY_SENAT[$st['id_stav'] ?? ''] ?? ('stav ' . py_str($st['id_stav'])),
                'druh' => $druh['nazev_druhu'] ?? null, 'anotace' => $st['anotace'], 'nazev' => $st['nazev_tisku'],
                'typ' => $vz['typ'], 'typ_popis' => Psp::TYPY_VAZBY_SENAT[$vz['typ'] ?? ''] ?? ('vazba ' . py_str($vz['typ'])),
                'url' => vzor(URL_SENAT_TISK, ['id' => $st['id_tisk']]),
            ];
        }
        usort($v, function ($a, $b) { return [$a['obdobi'] ?? 0, $a['id'] ?? 0] <=> [$b['obdobi'] ?? 0, $b['id'] ?? 0]; });
        return $v;
    }

    public function nasledne_tisky(): array
    {
        $radky = $this->psp->radky('tisky_za', 'id_tisk', $this->id_tisk());
        $o = $this->obdobi();
        $v = [[
            'cislo' => py_str($this->ct()) . '/0', 'druh' => $this->druh(), 'nazev' => py_or($this->uplny_nazev(), $this->nazev()),
            'rozeslano' => $this->rozeslano(), 'organ' => null, 'vysledek' => 'výchozí tisk',
            'url' => py_or($this->url_text(), py_bool($o) ? vzor(URL_TISK_TEXT, ['o' => $o, 'ct' => $this->ct(), 'ct1' => 0]) : null),
        ]];
        usort($radky, function ($a, $b) {
            return [$a['sort_it'] ?? 0, $a['cislo_za'] ?? 0] <=> [$b['sort_it'] ?? 0, $b['cislo_za'] ?? 0];
        });
        foreach ($radky as $r) {
            $vysl = $this->psp->jeden('vysledek', 'id_vysledek', $r['id_vysledek']) ?? [];
            $post = (is_int($r['cislo_za_post']) && $r['cislo_za_post'] > 0 && $r['cislo_za_post'] <= 10)
                ? 'abcdefghij'[$r['cislo_za_post'] - 1] : '';
            $cz = $r['cislo_za'];
            $cz_text = [-1 => 'Z', -2 => 'Z1', -3 => 'Z2'][$cz ?? ''] ?? $cz;
            $v[] = [
                'cislo' => sprintf('%s/%s%s', $this->cislo(), py_str($cz_text), $post),
                'druh' => $this->psp->druh($r['id_druh']),
                'nazev' => py_or($r['uplny_nazev_za'], $r['nazev_za']),
                'rozeslano' => py_or($r['roz'], $r['rozeslano']),
                'organ' => py_bool($r['id_org']) ? $this->psp->organ($r['id_org']) : null,
                'vysledek' => $vysl['druh_vysledek'] ?? null,
                'url' => py_or($r['t_url'], (is_int($cz) && $cz > 0 && py_bool($o)) ? vzor(URL_TISK_TEXT, ['o' => $o, 'ct' => $this->ct(), 'ct1' => $cz]) : null),
            ];
        }
        return $v;
    }

    public function eklep(): array
    {
        $v = [];
        foreach (['tisk_eklep', 'tz_eklep'] as $tab) {
            $radky = $this->psp->bezpecne(function () use ($tab) { return $this->psp->radky($tab, 'id_tisk', $this->id_tisk()); }, []);
            foreach ($radky as $r) {
                $pid = trim((string)($r['pid'] ?? ''));
                $cz = $r['cislo_za'];
                $v[] = [
                    'pid' => $pid,
                    'cj_ova' => (is_int($r['ova_c']) && is_int($r['ova_r'])) ? sprintf('%s/%02d', $r['ova_c'], $r['ova_r'] % 100) : null,
                    'verejne' => $r['verejne'] === 1,
                    'tisk' => py_bool($cz) ? sprintf('%s/%s', py_str($this->ct()), $cz) : $this->cislo(),
                    'nasledny' => $tab === 'tz_eklep',
                    'url' => vzor(URL_ODOK, ['pid' => $pid]),
                ];
            }
        }
        return $v;
    }

    public function pid_eklep(): ?string
    {
        foreach ($this->eklep() as $e) {
            if (!$e['nasledny']) {
                return $e['pid'];
            }
        }
        return null;
    }
}
