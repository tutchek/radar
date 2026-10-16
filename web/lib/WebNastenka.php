<?php
/**
 * WebNastenka.php – nástěnka legislativního procesu jednoho resortu: sloupce podle fází (vnější připomínkové
 * řízení … Sbírka, smetiště dějin) a v nich „lepíky“ s návrhy v aktuálním stavu. Návrh, který už je ve Sněmovně,
 * visí jako sněmovní tisk, jinak jako materiál ODOK (vyhlášky a nařízení doplňuje e-Legislativa).
 */
trait WebNastenka
{
    /** Fáze nástěnky v pořadí sloupců: klíč => [název, popis]. */
    protected static function faze_nastenky(): array
    {
        return [
            'vnejsi' => ['Vnější připomínky', 'Rozpracované a materiály v meziresortním připomínkovém řízení'],
            'vyporadani' => ['Vypořádání vnějšího', 'Po skončení MPŘ, před předložením vládě'],
            'vlada' => ['Vláda / LRV', 'Předloženo vládě, Legislativní radě vlády; vyhlášky a nařízení do vyhlášení'],
            'cteni1' => ['1. čtení', 'Sněmovní tisk před 1. čtením a v něm'],
            'cteni2' => ['2. čtení', 'Výbory po 1. čtení a 2. čtení'],
            'cteni3' => ['3. čtení', 'Lhůta pro pozměňovací návrhy, 3. čtení, opakované hlasování'],
            'senat' => ['Senát', 'Odesláno Senátu a projednávání v Senátu'],
            'prezident' => ['Prezident', 'Čeká na podpis prezidenta'],
            'sbirka' => ['Sbírka', 'Vyhlášeno nebo odesláno k vyhlášení'],
            'smetiste' => ['Smetiště dějin', 'Zamítnuto, vzato zpět, zaniklo koncem období, skartováno …'],
        ];
    }

    const TYPY_NASTENKY = ['NZ' => 'zákon', 'NV' => 'vyhláška', 'NNV' => 'nařízení'];

    /** [fáze, štítek, datum od] pro sněmovní tisk. */
    protected function faze_tisku(Tisk $t, Psp $p): array
    {
        $od = $t->datum_stavu();
        if ($t->zanikl_koncem_obdobi()) {
            return ['smetiste', 'zaniklo koncem období', $od];
        }
        $typ = ($p->stav($t->data['id_stav']) ?? [])['id_typ'] ?? null;
        $popis = $t->stav_v_datech();
        if ($typ === Psp::TYP_STAVU_PROJEDNANO || $t->revokovano()) {
            $s = $t->stav_ukonceni();
            $sb = $t->sbirka();
            if ($sb || $s === Tisk::STAV_VYHLASENO) {
                return ['sbirka', $sb ?: 'vyhlášeno', $od];
            }
            if (in_array($s, ['ODESLÁNO K VYHLÁŠENÍ', 'PODEPSÁNO', 'SCHVÁLENO'], true)) {
                return ['sbirka', 'odesláno k vyhlášení', $od];
            }
            return ['smetiste', mb_strtolower($s ?? ($t->revokovano() ? 'vzato zpět' : 'ukončeno')), $od];
        }
        if ($typ === Psp::TYP_PREZIDENT) {
            return ['prezident', 'u prezidenta', $od];
        }
        if (in_array($typ, Psp::TYPY_SNEMOVNA_PO_VRACENI, true)) {
            return ['cteni3', 'opakované hlasování', $od];
        }
        switch (Psp::etapa_podle_typu($typ)) {
            case '1. čtení':
                return ['cteni1', $popis && bez_diakritiky($popis) !== '1. cteni' ? $popis : '', $od];
            case 'garanční výbor (po 1. čtení)':
                return ['cteni2', 'výbory', $od];
            case '2. čtení':
                return ['cteni2', '', $od];
            case 'garanční výbor (po 2. čtení)':
                return ['cteni3', 'lhůta pro PN / výbor', $od];
            case '3. čtení':
                return ['cteni3', '', $od];
            case 'Senát':
                $l = $t->lhuta_senatu();
                $lhuta = ($l && py_bool($l['lhuta_do'] ?? null) && !py_bool($l['rozhodnuto'] ?? null))
                    ? 'lhůta do ' . fmt_datum(den($l['lhuta_do']) ?? $l['lhuta_do']) : '';
                return ['senat', $typ === Psp::TYP_STAVU_ODESILANO_SENATU ? 'odesláno Senátu' : $lhuta, $od];
        }
        return ['cteni1', $popis ?? '', $od];
    }

    /** [fáze, štítek, datum od] pro materiál ODOK (bez sněmovního tisku). */
    protected function faze_materialu($m, ?array $esel): array
    {
        $s = bez_diakritiky((string)$m->stav);
        $id = trim((string)$m->stav_id);
        $datum = py_or($m->datum, $m->datum_autorizace, $m->datum_vytvoreni);
        if ($id === STAV_PUBLIKOVANO || strpos($s, 'publikov') !== false) {
            return ['sbirka', 'publikováno', $datum];
        }
        if (in_array($id, ['Y', 'DZV'], true) || preg_match('/skart|zpet|vyrazen|zastav/', $s)) {
            return ['smetiste', mb_strtolower((string)$m->stav), $datum];
        }
        if ($esel && ($esel['citace'] || $esel['vyhlaseno'])) {
            return ['sbirka', py_or($esel['citace'], 'vyhlášeno'), py_or($esel['vyhlaseno'], $datum)];
        }
        if (strpos($s, 'psp') !== false) {
            return $m->typ_id === 'NZ' ? ['cteni1', 'v PSP, tisk nespárován', $datum] : ['vlada', (string)$m->stav, null];
        }
        // číselné stavy ODOK od 6 („6PK – přijato ÚV ke zpracování“, „7 – Zařazeno do evidence“ …) = předloženo vládě a dál
        // (i s příponou, např. „8PK – zařazeno na jednání PK“; číslo se bere z id stavu, jinak ze začátku textu)
        $cislo_stavu = preg_match('/^(\d+)/', $id, $mm) ? (int)$mm[1] : (preg_match('/^\s*(\d+)/', $s, $mm) ? (int)$mm[1] : null);
        if (($cislo_stavu !== null && $cislo_stavu >= 6) || preg_match('/evidenc|komis|jednani pk|\bpk\b|prijato uv/', $s)) {
            return ['vlada', ($esel && $esel['stav']) ? 'e-Leg.: ' . $esel['stav'] : (string)$m->stav, null];
        }
        $konec = den($m->konec_mpr);
        $po_mpr = $konec !== null && $konec < dnes();
        if (strpos($s, 'mpr') !== false || strpos($s, 'mezirezort') !== false) {
            return $po_mpr ? ['vyporadani', 'MPŘ skončilo', $konec]
                : ['vnejsi', $konec ? 'MPŘ do ' . fmt_datum($konec) : 'v MPŘ', $konec ? null : $datum];
        }
        if (strpos($s, 'vypor') !== false) {
            return ['vyporadani', (string)$m->stav, $konec];
        }
        if (preg_match('/signov|vlad|lrv|predloz|projedn|legislativ/', $s)) {
            $stitek = (string)$m->stav;
            if ($esel && $esel['stav']) {
                $stitek = 'e-Leg.: ' . $esel['stav'];
            }
            return ['vlada', $stitek, null];
        }
        if (preg_match('/rozprac|priprav|navrh/', $s)) {
            return ['vnejsi', (string)$m->stav, null];
        }
        return $po_mpr ? ['vyporadani', (string)$m->stav, $konec] : ['vnejsi', (string)$m->stav, null];
    }

    public function stranka_nastenka(array $parametry): string
    {
        // ročníky ODOK celého zvoleného volebního období (i při výběru jednoho roku – rozpracované návrhy jsou
        // stav k dnešku); rozpracované materiály ODOK jen založené v tomto období – staré návrhy, které v ODOK
        // zůstaly „viset“ v nedokončeném stavu (hlavně vyhlášky), by nástěnku zahltily
        $cislo = $this->cislo_obdobi();
        $zacatek = null;
        $roky = range(prvni_rok_odok(), (int)date('Y'));
        if ($cislo !== 'vse') {
            foreach ($this->seznam_obdobi() as [$c, $rok_od, $rok_do]) {
                if ($c === $cislo) {
                    $roky = roky_obdobi($rok_od, $rok_do);
                    $zacatek = ZACATKY_OBDOBI[$c] ?? "$rok_od-01-01";
                }
            }
            if ($this->psp_ok()) {
                foreach ($this->snemovna()->obdobi_seznam() as $o) {
                    if ($o->cislo === $cislo && $o->od) {
                        $zacatek = den($o->od);
                    }
                }
            }
        }
        $odok = $this->odok_pro($roky);
        // resorty podle počtu materiálů; zvolený resort se pamatuje
        $pocty = [];
        foreach ($odok->predkladatele() as $k => $n) {
            $z = explode("\x00", $k)[0];
            if ($z !== '' && $z !== '?') {
                $pocty[$z] = ($pocty[$z] ?? 0) + $n;
            }
        }
        arsort($pocty);
        $resort = strtoupper(trim($parametry['r'] ?? ''));
        if ($resort !== '') {
            header('Set-Cookie: nastenka_r=' . rawurlencode($resort) . '; Path=' . Auth::cesta() . '; Max-Age=31536000; SameSite=Lax', false);
        } else {
            $resort = strtoupper((string)($_COOKIE['nastenka_r'] ?? ''));
        }
        $vse_resorty = $resort === '*';
        if (!$vse_resorty && ($resort === '' || ($pocty && !isset($pocty[$resort])))) {
            $resort = (string)(array_key_first($pocty) ?? 'MF');
        }
        $volby = sprintf("<option value='*'%s>všechny resorty</option>", $vse_resorty ? ' selected' : '');
        $serazene = array_keys($pocty);
        sort($serazene, SORT_STRING);
        foreach ($serazene as $z) {
            $volby .= sprintf('<option%s>%s</option>', $z === $resort ? ' selected' : '', e($z));
        }

        $rozsah = $this->rozsah_vyberu();
        $v_rozsahu = function ($d) use ($rozsah): bool {
            if ($rozsah === null) {
                return true;
            }
            $d = den($d);
            return $d !== null && $d >= $rozsah[0] && ($rozsah[1] === null || $d <= $rozsah[1]);
        };
        $klice = $this->oblibene->klice();
        $materialy = $vse_resorty
            ? array_map(function ($r) use ($odok) { return new Material($r, $odok); }, $odok->zaznamy())
            : $odok->materialy_ministerstva($resort);
        $materialy = array_values(array_filter($materialy, function ($m) {
            return isset(self::TYPY_NASTENKY[$m->typ_id]);
        }));
        $zkratka_pid = [];      // PID → zkratka resortu (štítek na lepíku u všech resortů)
        foreach ($materialy as $m) {
            $zkratka_pid[strtoupper((string)$m->pid)] = (string)$m->predkladatel_zkratka;
        }

        // tisky: k materiálům resortu (v kterémkoli období) + vládní tisky zvoleného období s materiálem resortu z jiných let
        $tisky = [];          // id_tisk => [Tisk, Material|null]
        $p = null;
        if ($this->psp_ok()) {
            $p = $this->psp_pro();
            $vse = $this->snemovna();
            $pid_tisk = $vse->pid_tisk(array_map(function ($m) { return $m->pid; }, $materialy));
            $material_k_pid = [];
            foreach ($materialy as $m) {
                $material_k_pid[strtoupper((string)$m->pid)] = $m;
            }
            $vse->prednacti('tisky', 'id_tisk', array_values($pid_tisk));
            foreach ($pid_tisk as $pid => $id_tisk) {
                $r = $vse->jeden('tisky', 'id_tisk', $id_tisk);
                if ($r && !isset($tisky[$id_tisk])) {
                    $tisky[$id_tisk] = [new Tisk($r, $vse), $material_k_pid[$pid] ?? null];
                }
            }
            $cizi = [];
            $vladni = $p->zakony(true);
            foreach ($vladni as $t) {
                if (!isset($tisky[$t->id_tisk()]) && ($pid = $t->pid_eklep())) {
                    $cizi[strtoupper($pid)] = $t;
                }
            }
            if ($vse_resorty) {          // všechny resorty: všechny vládní tisky období
                foreach ($vladni as $t) {       // i tisky bez vazby na eKLEP
                    $tisky[$t->id_tisk()] = $tisky[$t->id_tisk()] ?? [$t, null];
                }
                foreach (array_chunk(array_keys($cizi), 500) as $kus) {
                    foreach (Db::vse('SELECT pid, predkladatel_zkratka FROM {p}odok_material WHERE UPPER(pid) IN (' . Db::in($kus) . ')', $kus) as $r) {
                        $zkratka_pid[strtoupper((string)$r['pid'])] = $zkratka_pid[strtoupper((string)$r['pid'])] ?? (string)$r['predkladatel_zkratka'];
                    }
                }
            } elseif ($cizi) {
                $pidy = array_keys($cizi);
                foreach (array_chunk($pidy, 500) as $kus) {
                    foreach (Db::vse('SELECT pid, predkladatel_zkratka FROM {p}odok_material WHERE UPPER(pid) IN (' . Db::in($kus) . ')', $kus) as $r) {
                        $t = $cizi[strtoupper((string)$r['pid'])] ?? null;
                        if ($t && norm_id($r['predkladatel_zkratka']) === norm_id($resort) && !isset($tisky[$t->id_tisk()])) {
                            $tisky[$t->id_tisk()] = [$t, null];
                        }
                    }
                }
            }
            $vse->prednacti_pro_tisky(array_keys($tisky));
        }
        $s_tiskem = [];     // materiály, které visí jako tisk (i víc materiálů k jednomu tisku – např. podle čj OVA)
        foreach ($tisky as [$t, $m]) {
            if ($m) {
                $s_tiskem[strtoupper((string)$m->pid)] = true;
            }
        }
        foreach ($pid_tisk ?? [] as $pid => $id) {
            if (isset($tisky[$id])) {
                $s_tiskem[strtoupper((string)$pid)] = true;
            }
        }
        $esel = $this->esel_ok() ? $this->legislativa() : null;
        $start_esel = microtime(true);

        $sloupce = array_fill_keys(array_keys(self::faze_nastenky()), []);
        $konecne = ['sbirka', 'smetiste'];
        foreach ($tisky as $id_tisk => [$t, $m]) {
            [$faze, $stitek, $od] = $this->faze_tisku($t, $p ?? $this->snemovna());
            if (in_array($faze, $konecne, true) && !$v_rozsahu($od)) {
                continue;
            }
            $obl = isset($klice[Oblibene::klic('tisk', $id_tisk)]) || ($m && isset($klice[Oblibene::klic('material', $m->pid)]))
                || (($pid = $t->pid_eklep()) && isset($klice[Oblibene::klic('material', $pid)]));
            $sloupce[$faze][] = [
                'resort' => $vse_resorty ? ($zkratka_pid[strtoupper((string)($m ? $m->pid : $t->pid_eklep()))] ?? '') : '',
                'typ' => 'NZ', 'odkaz' => odkaz('/tisk', ['id' => 'id:' . $id_tisk]),
                'znacka' => 'ST ' . $t->cislo(), 'titulek' => 'Sněmovní tisk ' . $t->cislo(),
                'nazev' => (string)py_or($t->nazev(), $t->uplny_nazev()), 'plny' => (string)$t->uplny_nazev(),
                'stitek' => $stitek, 'od' => $od, 'obl' => $obl,
                'poznamka' => $this->poznamka_tisku($id_tisk) ?? ($m ? $this->poznamka_materialu($m->pid) : null),
                'novely' => $this->stitky_novel($id_tisk),
                'hvezda' => $this->hvezda('tisk', $id_tisk, sprintf('Tisk %s – %s', $t->cislo(), py_str($t->nazev())), $klice),
            ];
        }
        foreach ($materialy as $m) {
            if (isset($s_tiskem[strtoupper((string)$m->pid)])) {
                continue;               // visí jako sněmovní tisk
            }
            $e = null;
            $n = null;
            if ($esel !== null && in_array($m->typ_id, ['NV', 'NNV'], true) && !in_array($m->stav_id, ['Y', 'DZV', STAV_PUBLIKOVANO], true)) {
                // plné párování (i podle podobnosti názvu, výsledek se ukládá na týden); jen při velkém počtu
                // dosud nespárovaných materiálů po vyčerpání časového limitu rychlé (čj OVA / čj předkladatele)
                $v = $esel->k_materialu($m, microtime(true) - $start_esel > 8.0);
                if ($v) {
                    $n = $v[0];
                    $e = ['citace' => $n->citace, 'vyhlaseno' => $n->datum_vyhlaseni, 'stav' => $n->aktualni_stav];
                }
            }
            [$faze, $stitek, $od] = $this->faze_materialu($m, $e);
            if (in_array($faze, $konecne, true) ? !$v_rozsahu($od)
                : ($zacatek !== null && (den(py_or($m->datum_vytvoreni, $m->datum_autorizace, $m->datum)) ?? $zacatek) < $zacatek)) {
                continue;
            }
            $sloupce[$faze][] = [
                'resort' => $vse_resorty ? (string)$m->predkladatel_zkratka : '',
                'typ' => $m->typ_id, 'odkaz' => odkaz('/material', ['id' => py_or($m->pid, $m->cislo_jednaci, '')]),
                'znacka' => 'ODOK', 'titulek' => trim('Materiál ODOK ' . $m->pid . ' · ' . $m->stav, ' ·'),
                'nazev' => trim((string)$m->nazev), 'plny' => (string)$m->nazev,
                'stitek' => $stitek, 'od' => $od, 'obl' => isset($klice[Oblibene::klic('material', $m->pid)]),
                'poznamka' => $this->poznamka_materialu($m->pid), 'novely' => $this->stitky_novel_materialu($m, $n),
                'hvezda' => $this->hvezda('material', $m->pid, $m->nazev, $klice),
            ];
        }

        $dlazdice = '';
        $celkem = 0;
        foreach (self::faze_nastenky() as $klic => [$nazev, $popis]) {
            $lepiky = $sloupce[$klic];
            $konecna = in_array($klic, $konecne, true);
            usort($lepiky, function ($a, $b) use ($konecna) {
                $x = den($a['od']) ?? ($konecna ? '0000' : '9999');
                $y = den($b['od']) ?? ($konecna ? '0000' : '9999');
                return $konecna ? strcmp($y, $x) : strcmp($x, $y);
            });
            $celkem += count($lepiky);
            $html = array_map(function ($l) use ($konecna) { return $this->lepik($l, !$konecna); }, $lepiky);
            $obsah = implode('', array_slice($html, 0, $konecna ? 8 : PHP_INT_MAX));
            if ($konecna && count($html) > 8) {
                $obsah .= sprintf("<details class='dalsi-lepiky'><summary>další %d</summary>%s</details>", count($html) - 8,
                    implode('', array_slice($html, 8)));
            }
            $dlazdice .= sprintf("<section class='faze faze-%s'><h2 title='%s'>%s <span class='pocet'>%d</span></h2><div class='lepiky'>%s</div></section>",
                e($klic), e($popis), e($nazev), count($lepiky), $obsah !== '' ? $obsah : "<p class='tlumene prazdno'>—</p>");
        }

        $obsah = sprintf('<h1>Nástěnka <span class="tlumene">%s · %s</span></h1>
<form class="lista" method="get" action="index.php"><input type="hidden" name="p" value="nastenka">
<label>Resort <select name="r" onchange="this.form.submit()">%s</select></label><noscript><button>Zobrazit</button></noscript>
<input type="text" data-filtr-lepiku="nastenka" placeholder="Filtrovat lepíky…" style="max-width:320px">
%s<span class="tlumene">%d návrhů</span></form>
<div class="nastenka" id="nastenka">%s</div>
<p class="tlumene">Stav k dnešku. Návrh, který je už ve Sněmovně, visí jako sněmovní tisk (ST), ostatní jako materiál ODOK
(zákony, vyhlášky, nařízení; vyhlášky a nařízení doplňuje e-Legislativa). Ve sloupcích Sbírka a Smetiště dějin jsou návrhy
ukončené v období %s, ostatní sloupce rozpracované návrhy (materiály ODOK jen založené v tomto volebním období). Datum u lepíku = od kdy je návrh v daném stavu.
Zvýrazněné jsou oblíbené (i tisk k oblíbenému materiálu).</p>',
            e($vse_resorty ? 'všechny resorty' : $resort), e($this->popis_vyberu()), $volby,
            $this->oblibene->aktivni() ? "<button type='button' class='jen-obl-lepiky' data-nastenka='nastenka' title='Zobrazit jen oblíbené'>⭐ jen oblíbené</button>" : '',
            $celkem, $dlazdice, e($this->popis_vyberu()));
        return $this->stranka('Nástěnka ' . ($vse_resorty ? '– všechny resorty' : $resort), $obsah, 'nastenka');
    }

    protected function lepik(array $l, bool $s_dny = true): string
    {
        $dny = '';
        $od = den($l['od']);
        if ($od !== null) {
            $n = (int)floor((strtotime(dnes()) - strtotime($od)) / 86400);
            $dny = sprintf("<span class='od' title='%s %s'>%s%s</span>", $s_dny ? 'V tomto stavu od' : 'Ukončeno', e(fmt_datum($od)), e(fmt_datum($od)),
                ($s_dny && $n > 0) ? sprintf(' · %d %s', $n, $n === 1 ? 'den' : ($n < 5 ? 'dny' : 'dní')) : '');
        }
        $extra = trim(self::stitek_poznamky($l['poznamka'] ?? null, 34) . ($l['novely'] ?? ''));
        return sprintf("<div class='lepik typ-%s%s%s'><div class='hlava'>%s<span class='druh'>%s</span><span class='znacka' title='%s'>%s</span>%s</div>
<a class='nazev' href='%s' title='%s'>%s</a>%s<div class='pata'>%s%s</div></div>",
            e(strtolower((string)$l['typ'])), $l['obl'] ? ' oblibeny' : '', ($l['novely'] ?? '') !== '' ? ' novela' : '',
            ($l['resort'] ?? '') !== '' ? "<span class='resort'>" . e($l['resort']) . '</span>' : '', e(self::TYPY_NASTENKY[$l['typ']] ?? ''), e($l['titulek']), e($l['znacka']),
            $l['hvezda'], e($l['odkaz']), e($l['plny']), e($l['nazev']), $extra !== '' ? "<div class='extra'>$extra</div>" : '',
            $l['stitek'] !== '' ? "<span class='stitek'>" . e($l['stitek']) . '</span>' : '', $dny);
    }
}
