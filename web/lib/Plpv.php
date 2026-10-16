<?php
/**
 * Plpv.php – podklady pro excel přehledu legislativních prací (PLPV). Port plpv.py.
 * Řídící jsou materiály ODOK; zákony doplňuje Sněmovna, vyhlášky a nařízení e-Legislativa.
 */

const PLPV_ODOK = 'odok', PLPV_PSP = 'psp', PLPV_ESEL = 'esel', PLPV_PSP_ESEL = 'psp_esel', PLPV_RUCNE = 'rucne';

class ListPLPV
{
    const POPIS_ZDROJE = [PLPV_ODOK => 'ODOK', PLPV_PSP => 'Poslanecká sněmovna', PLPV_ESEL => 'e-Legislativa',
        PLPV_PSP_ESEL => 'Sněmovna (zákony) / e-Legislativa (vyhlášky, nařízení)', PLPV_RUCNE => 'doplňuje se ručně'];

    public $klic, $nazev, $sloupce, $doplnky;
    public $radky = [];

    public function __construct($klic, $nazev, $sloupce, $doplnky)
    {
        [$this->klic, $this->nazev, $this->sloupce, $this->doplnky] = [$klic, $nazev, $sloupce, $doplnky];
    }

    public static function listy(): array
    {
        $C = ['Právní předpis', 'nazev', PLPV_ODOK, 'C'];
        $G = ['MPŘ (den rozeslání)', 'mpr', PLPV_ODOK, 'G'];
        $H = ['Předložení vládě', 'predlozeni_vlade', PLPV_ODOK, 'H'];
        $zakony = [$C, $G, $H, ['Vláda schválení', 'vlada', PLPV_ODOK, 'I'], ['Postoupeno PSP', 'postoupeno', PLPV_PSP, 'J'],
            ['Tisk', 'tisk', PLPV_PSP, 'K'], ['Schváleno PSP', 'schvaleno_ps', PLPV_PSP, 'L'], ['Schváleno Senátem', 'senat', PLPV_PSP, 'M'],
            ['Podpis presidenta', 'prezident', PLPV_PSP, 'N'], ['Priority', 'priority', PLPV_RUCNE, 'O']];
        $vyhlasky = [$C, $G, ['Předložení vládě', 'predlozeni_lrv', PLPV_ODOK, 'H'], ['LRV', 'lrv', PLPV_ODOK, 'I'],
            ['Podpis ministryně', 'podpis', PLPV_ESEL, 'J'], ['Sbírka zákonů', 'sbirka', PLPV_ESEL, 'K'], ['Účinnost', 'ucinnost', PLPV_ESEL, 'L']];
        $narizeni = [$C, $G, $H, ['LRV', 'lrv', PLPV_ODOK, 'I'], ['Vláda schválení', 'vlada', PLPV_ODOK, 'J'],
            ['Sbírka zákonů', 'sbirka', PLPV_ESEL, 'K'], ['Účinnost', 'ucinnost', PLPV_ESEL, 'L']];
        $publikovane = [$C, $G, $H, ['Vláda schválení', 'vlada', PLPV_ODOK, 'I'], ['Postoupeno PSP', 'postoupeno', PLPV_PSP, 'J'],
            ['Tisk', 'tisk', PLPV_PSP, 'K'], ['Schváleno PSP', 'schvaleno_ps', PLPV_PSP, 'L'], ['Schváleno Senátem', 'senat', PLPV_PSP, 'M'],
            ['Podpis presidenta/ministryně', 'prezident', PLPV_PSP_ESEL, 'N'], ['Sbírka zákonů', 'sbirka', PLPV_PSP_ESEL, 'O'],
            ['Účinnost', 'ucinnost', PLPV_ESEL, 'P']];
        $spolecne = [['Stav v ODOK', 'stav_odok', PLPV_ODOK], ['PID', 'pid', PLPV_ODOK], ['Čj', 'cj', PLPV_ODOK],
            ['Čj ÚV', 'cj_ova', PLPV_ODOK], ['Konec MPŘ', 'konec_mpr', PLPV_ODOK]];
        $psp = [['Aktuální stav PS', 'stav_ps', PLPV_PSP]];
        foreach (['1. čtení', 'garanční výbor', '2. čtení', 'garanční výbor (2)', '3. čtení', 'Senát (konec)'] as $i => $n) {
            $psp[] = [$n, "etapa_$i", PLPV_PSP];
        }
        $esel = [['e-Legislativa (párování)', 'esel', PLPV_ESEL], ['Stav v e-Legislativě', 'stav_esel', PLPV_ESEL],
            ['Vyhlášeno', 'vyhlaseno', PLPV_ESEL], ['Kompatibilita s EU', 'eu', PLPV_ESEL],
            ['Termín implementace', 'termin_implementace', PLPV_ESEL]];
        return [
            ['zakony', 'Zákony', $zakony, array_merge($psp, $spolecne)],
            ['vyhlasky', 'Vyhlášky', $vyhlasky, array_merge($esel, $spolecne)],
            ['narizeni', 'Nařízení', $narizeni, array_merge($esel, $spolecne)],
            ['publikovane', 'Publikované ve Sbírce zákonů', $publikovane, array_merge($esel, $psp, $spolecne)],
        ];
    }

    /** Hodnota do buňky: datum jako d.m.rrrr, jinak text. */
    public static function fmt($h): string
    {
        if ($h === null) {
            return '';
        }
        if (is_string($h) && preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: \d{2}:\d{2}:\d{2})?$/', $h, $m)) {
            return sprintf('%d.%d.%d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
        return py_str($h);
    }

    public function jako_tsv(bool $s_doplnky = false, bool $se_zahlavim = true): string
    {
        $sloupce = $s_doplnky ? array_merge($this->sloupce, $this->doplnky) : $this->sloupce;
        $radky = [];
        if ($se_zahlavim) {
            $radky[] = implode("\t", array_map(function ($s) {
                return str_replace("\n", ' ', count($s) > 3 ? sprintf('%s · %s', $s[3], $s[0]) : $s[0]);
            }, $sloupce));
        }
        foreach ($this->radky as $r) {
            $radky[] = implode("\t", array_map(function ($s) use ($r) {
                return str_replace(["\t", "\n"], ' ', self::fmt($r[$s[1]] ?? null));
            }, $sloupce));
        }
        return implode("\n", $radky);
    }
}

class Podklady
{
    const TYP_ZAKON = 'NZ', TYP_VYHLASKA = 'NV', TYP_NARIZENI = 'NNV';
    const KONECNE_STAVY = [STAV_PUBLIKOVANO, 'Y', 'DZV'];

    public $resort, $od, $do, $odok, $psp, $esel, $roky_odok;
    public $chyby = [];

    /** $od, $do = 'Y-m-d' ($do = null → dosud). */
    public function __construct(string $resort, string $od, ?string $do, ?Psp $psp = null, ?Esel $esel = null)
    {
        $this->resort = $resort;
        $this->od = $od;
        $this->do = $do;
        $konec = (int)substr($do ?? dnes(), 0, 4);
        $this->roky_odok = range((int)substr($od, 0, 4) - 1, $konec);
        $this->odok = new Odok($this->roky_odok);
        $this->psp = $psp;
        $this->esel = $esel;
    }

    private function kandidati(): array
    {
        return array_values(array_filter($this->odok->materialy_ministerstva($this->resort), function ($m) {
            return in_array($m->typ_id, [self::TYP_ZAKON, self::TYP_VYHLASKA, self::TYP_NARIZENI], true);
        }));
    }

    /** Historie verzí (souběžné stažení, cache v databázi). */
    private function nacti_verze(array $materialy): array
    {
        $urls = [];
        foreach ($materialy as $m) {
            if ($m->pid) {
                $urls[] = str_replace('{pid}', $m->pid, URL_VERZE);
            }
        }
        Http::predNacti($urls, (float)Konfig::get('platnost_h.detail', 2));
        $v = [];
        foreach ($materialy as $m) {
            try {
                $v[$m->pid] = $m->verze_seznam();
            } catch (Throwable $e) {
                $this->chyby[] = sprintf('%s: %s', py_str($m->pid), $e->getMessage());
                $v[$m->pid] = [];
            }
        }
        return $v;
    }

    private function tisk($m): ?Tisk
    {
        if ($this->psp === null) {
            return null;
        }
        $t = null;
        $tp = $m->tisk_psp;
        if ($tp) {
            $t = $this->psp->tisk_v_obdobi($tp[1], $tp[0]);
        }
        return $t ?: $this->psp->tisk_k_materialu($m->pid);
    }

    public function radek($m, array $verze, ?Tisk $tisk): array
    {
        $prvni = function (...$typy) use ($verze) {
            $d = [];
            foreach ($verze as $v) {
                if (in_array($v['typ'], $typy, true) && $v['datum']) {
                    $d[] = $v['datum'];
                }
            }
            return $d ? min($d) : null;
        };
        [$mpr, $pk, $jv, $pv] = [$prvni('MPR'), $prvni('PK'), $prvni('JV'), $prvni('PV')];
        $vlada = $m->datum_jednani_vlady;
        if ($vlada && $jv && $vlada < $jv) {
            $vlada = null;
        }
        $vyhlaska = $m->typ_id === self::TYP_VYHLASKA;
        $r = [
            'nazev' => trim((string)($m->nazev ?? '')), 'mpr' => $mpr,
            'predlozeni_vlade' => $vyhlaska ? $pk : $jv,
            'vlada' => $vyhlaska ? null : py_or($vlada, $pv),
            'predlozeni_lrv' => $pk, 'lrv' => $m->typ_id === self::TYP_VYHLASKA ? $pv : $pk,
            'sbirka' => $m->stav_id === STAV_PUBLIKOVANO ? 'publikováno (číslo doplnit)' : null,
            'stav_odok' => $m->stav, 'pid' => $m->pid, 'cj' => $m->cislo_jednaci, 'cj_ova' => $m->cj_ova,
            'konec_mpr' => $m->konec_mpr, 'typ_id' => $m->typ_id, '_material' => $m, '_tisk' => $tisk,
        ];
        if ($tisk !== null) {
            $p = $tisk->pro_plpv();
            $etapy = $tisk->etapy();
            $r['postoupeno'] = $p['postoupeno'];
            $r['tisk'] = $p['tisk'];
            $r['schvaleno_ps'] = $p['schvaleno_ps'];
            $r['senat'] = $p['senat'];
            $r['prezident'] = $p['prezident'];
            $r['stav_ps'] = $p['stav'];
            if ($p['sbirka']) {
                $r['sbirka'] = $p['sbirka'];
            }
            $r['_vyhlaseno'] = $p['vyhlaseno'];
            foreach (Psp::NAZVY_ETAP as $i => $nazev) {
                $e = $etapy[$nazev] ?? null;
                $r["etapa_$i"] = $e && $e['probiha'] ? 'probíhá' : ($e ? $e['do'] : null);
            }
            $r['_etapy'] = array_keys($etapy);
        } elseif ($m->tisk_psp) {
            $r['tisk'] = $m->tisk_psp[1];
        }
        $this->doplnit_z_eselu($m, $r);
        return $r;
    }

    private function doplnit_z_eselu($m, array &$r): void
    {
        if ($this->esel === null || !in_array($m->typ_id, [self::TYP_VYHLASKA, self::TYP_NARIZENI], true)) {
            return;
        }
        try {
            $nalez = $this->esel->k_materialu($m);
        } catch (OdokChyba $e) {
            $this->chyby[] = 'e-Legislativa: ' . $e->getMessage();
            return;
        }
        if (!$nalez) {
            return;
        }
        [$n, $zpusob, $jistota] = $nalez;
        $e = $n->pro_plpv();
        if ($m->typ_id === self::TYP_VYHLASKA) {
            $r['predlozeni_lrv'] = py_or($r['predlozeni_lrv'], $e['postupovano_vlade'], $e['lrv_predlozeno']);
            $r['predlozeni_vlade'] = $r['predlozeni_lrv'];
            $r['lrv'] = py_or($r['lrv'], $e['lrv_stanoviska']);
        } else {
            $r['predlozeni_vlade'] = py_or($r['predlozeni_vlade'], $e['postupovano_vlade']);
            $r['lrv'] = py_or($r['lrv'], $e['lrv_predlozeno']);
            $r['vlada'] = py_or($r['vlada'], $e['jednani_vlady']);
        }
        $r['podpis'] = $e['postupovano_vydavateli'];
        if ($e['citace']) {
            $r['sbirka'] = $e['citace'];
            $r['prezident'] = py_or($r['prezident'] ?? null, $e['postupovano_vydavateli']);
        }
        $r['ucinnost'] = $e['ucinnost'];
        $r['vyhlaseno'] = $e['citace'] ? $e['vyhlaseno'] : null;
        $r['_vyhlaseno'] = py_or($r['_vyhlaseno'] ?? null, $r['vyhlaseno']);
        $r['stav_esel'] = $e['stav'];
        $r['eu'] = $e['eu'];
        $r['termin_implementace'] = $e['termin_implementace'];
        $r['esel'] = sprintf('%s (%s%s)', py_str($n->identifikator), $zpusob, $jistota >= 1 ? '' : sprintf(', shoda %d %%', (int)py_round($jistota * 100)));
        $r['_esel'] = $n;
        $r['_esel_data'] = [$e['postupovano_vlade'], $e['jednani_vlady'], $e['lrv_predlozeno'], $e['lrv_stanoviska'],
            $e['postupovano_vydavateli'], $e['vyhlaseno']];
    }

    private function v_rozmezi($d): bool
    {
        $d = den($d);
        if (!$d) {
            return false;
        }
        return $this->od <= $d && ($this->do === null || $d <= $this->do);
    }

    private function aktivni_v_roce(array $r): bool
    {
        $m = $r['_material'];
        $data = [$m->datum_vytvoreni, $m->datum_autorizace, $m->konec_mpr, $m->datum_jednani_vlady,
            $r['mpr'] ?? null, $r['predlozeni_vlade'] ?? null, $r['vlada'] ?? null, $r['predlozeni_lrv'] ?? null];
        $t = $r['_tisk'];
        if ($t !== null) {
            $data[] = $t->predlozeno();
            foreach ($t->historie() as $k) {
                $data[] = $k->datum;
            }
        }
        foreach ($r['_esel_data'] ?? [] as $x) {
            $data[] = $x;
        }
        foreach ($data as $d) {
            if ($this->v_rozmezi($d)) {
                return true;
            }
        }
        if ($this->do !== null && $this->do < dnes()) {
            return false;
        }
        $rozpracovany = !in_array($m->stav_id, self::KONECNE_STAVY, true) && !($t !== null && $t->projednano());
        $rok = $m->datum_vytvoreni ? (int)substr($m->datum_vytvoreni, 0, 4) : 0;
        return $rozpracovany && $rok >= (int)substr($this->od, 0, 4) - 1;
    }

    /** @return ListPLPV[] v pořadí jako excel */
    public function listy(): array
    {
        $kandidati = $this->kandidati();
        $verze = $this->nacti_verze($kandidati);
        $listy = [];
        foreach (ListPLPV::listy() as [$k, $n, $s, $d]) {
            $listy[$k] = new ListPLPV($k, $n, $s, $d);
        }
        if ($this->psp !== null) {
            $tisky = [];
            foreach ($kandidati as $m) {
                $t = $this->tisk($m);
                if ($t) {
                    $tisky[] = $t->id_tisk();
                }
            }
            $this->psp->prednacti_pro_tisky($tisky);
        }
        foreach ($kandidati as $m) {
            $r = $this->radek($m, $verze[$m->pid] ?? [], $this->tisk($m));
            if (!$this->aktivni_v_roce($r)) {
                continue;
            }
            $publikovano = $m->stav_id === STAV_PUBLIKOVANO || ($r['_tisk'] !== null && $r['_tisk']->sbirka())
                || (isset($r['_esel']) && $r['_esel']->citace);
            $vyhlaseno = $r['_vyhlaseno'] ?? null;
            if ($publikovano && $vyhlaseno && $this->do !== null && den($vyhlaseno) > $this->do) {
                $publikovano = false;
                $r['sbirka'] = $r['ucinnost'] = $r['vyhlaseno'] = null;
            }
            if ($publikovano) {
                $listy['publikovane']->radky[] = $r;
            } elseif ($m->typ_id === self::TYP_ZAKON) {
                $listy['zakony']->radky[] = $r;
            } elseif ($m->typ_id === self::TYP_VYHLASKA) {
                $listy['vyhlasky']->radky[] = $r;
            } else {
                $listy['narizeni']->radky[] = $r;
            }
        }
        foreach ($listy as $l) {
            usort($l->radky, function ($a, $b) {
                $x = py_or($a['mpr'], $a['_material']->datum_vytvoreni) ?: '9999';
                $y = py_or($b['mpr'], $b['_material']->datum_vytvoreni) ?: '9999';
                return strcmp($x, $y);
            });
        }
        return array_values($listy);
    }
}
