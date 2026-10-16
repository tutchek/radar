<?php
/**
 * WebPsp.php – stránky Sněmovny, e-Legislativy, oblíbených a podkladů PLPV (část třídy Web).
 */

trait WebPsp
{
    // ---------------- e-Legislativa ----------------

    public function cast_esel_k_materialu(string $pid): string
    {
        $m = $this->najdi_material($pid);
        $nalez = $this->legislativa()->k_materialu($m);
        if ($nalez === null) {
            return sprintf("<p class='tlumene'>K materiálu se v otevřených datech e-Legislativy nepodařilo najít návrh (hledá se podle čj OVA,
čj předkladatele a podobnosti názvu). Otevřená data neobsahují všechny návrhy, které jsou v e-Legislativě vidět – aktuálně v nich je
%d návrhů; chybějící návrh se spáruje sám, jakmile ho e-Legislativa zveřejní. Podzákonné předpisy vytvořené mimo e-Legislativu v ní nejsou vůbec.</p>%s",
                Esel::pocet(), ($h = $this->html_predpisy_navrhu(null, $m->nazev)) !== '' ? "<div class='karta'><table class='kv'><tr><th>Mění / ruší předpisy</th><td>$h</td></tr></table></div>" : '');
        }
        [$n, $zpusob, $jistota] = $nalez;
        $vazba = $jistota >= 1 ? $zpusob : sprintf('%s (shoda %d %%)', $zpusob, (int)py_round($jistota * 100));
        $druhy = ['VYHLASKA' => 'vyhláška', 'NARIZVLA' => 'nařízení vlády', 'ZAKON' => 'zákon'];
        $den = function ($x) {
            if ($x === null || $x === '') {
                return '';
            }
            return je_datum($x) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $x) ? e(fmt_datum(den($x))) : e($x);
        };
        $cas = function ($x) {
            return $x ? sprintf('%s %d:%02d', fmt_datum(den($x)), (int)substr($x, 11, 2), (int)substr($x, 14, 2)) : '';
        };
        $cjp = $n->cj_predkladatele;
        sort($cjp, SORT_STRING);
        $radky = [
            ['Návrh', sprintf('<b>%s</b> · %s', e(py_or($n->identifikator, '')), odkazy_esel($n, 'otevřít v e-Legislativě'))],
            ['Vazba nalezena podle', e($vazba)],
            ['Název v e-Legislativě', ($n->nazev_aktu && $n->nazev_aktu !== $m->nazev) ? e($n->nazev_aktu) : ''],
            ['Druh', e($druhy[$n->druh ?? ''] ?? ($n->druh ?? ''))],
            ['Typ procesu', e($n->typ_procesu ?? '')],
            ['Čj OVA', e($n->cj_ova ?? '')],
            ['Čj předkladatele', e(implode(', ', $cjp))],
            ['Aktuální stav', $n->aktualni_stav ? "<span class='stitek'>" . e($n->aktualni_stav) . '</span>' : ''],
            ['Sbírka', $n->citace ? '<b>' . odkaz_sbirka($n->citace) . '</b>' : ''],
            ['Mění / ruší předpisy', $this->html_predpisy_navrhu($n, $m->nazev)],
            ['Vyhlášeno', $den($n->datum_vyhlaseni)],
            ['Schváleno', $den($n->datum_schvaleni)],
            ['Účinnost', $den($n->ucinnost)],
            ['Kompatibilita s EU', e($n->kompatibilita_eu ?? '')],
            ['Termín pro implementaci', $den($n->termin_implementace)],
        ];
        $karta = "<div class='karta'><table class='kv'>" . implode('', array_map(function ($r) {
            return $r[1] !== '' ? sprintf('<tr><th>%s</th><td>%s</td></tr>', $r[0], $r[1]) : '';
        }, $radky)) . '</table></div>';
        $stavy = $n->stavy();
        if (!$stavy) {
            return $karta;
        }
        $telo = '';
        foreach ($stavy as $s) {
            $telo .= sprintf("<tr><td>%s</td><td>%s</td><td data-razeni='%s'>%s</td><td data-razeni='%s'>%s</td></tr>",
                e($s['faze'] ?? ''), e(preg_replace('/\s*\((\d+)\)$/u', ' ($1. kolo)', $s['nazev'] ?? '')),
                e($s['od'] ? iso($s['od']) : ''), e($cas($s['od'])), e($s['do'] ? iso($s['do']) : '9999'),
                $s['do'] ? e($cas($s['do'])) : "<span class='stitek'>probíhá</span>");
        }
        return $karta . "<h3>Fáze a stavy procesu</h3><div class='posun'><table class='data raditelna'><thead><tr><th>Fáze</th><th>Stav</th><th>Od</th><th>Do</th></tr></thead><tbody>$telo</tbody></table></div>";
    }

    // ---------------- Sněmovna ----------------

    protected static function url_psp($url): ?string
    {
        if (!py_bool($url)) {
            return null;
        }
        return $url[0] === '/' ? 'https://www.psp.cz' . $url : $url;
    }

    protected static function bunka_etapy(?array $etapa, string $doplnek = ''): string
    {
        if ($doplnek !== '') {
            $doplnek = "<div class='mensi'>$doplnek</div>";
        }
        if (!$etapa) {
            return "<td>$doplnek</td>";
        }
        if ($etapa['probiha']) {
            return "<td data-razeni='9999'><span class='stitek'>probíhá</span>$doplnek</td>";
        }
        $d = $etapa['do'];
        return sprintf("<td data-razeni='%s' title='%s'>%s%s</td>", e(iso($d)), e($etapa['akce']), e(fmt_datum(den($d) ?? $d)), $doplnek);
    }

    protected static function text_lhuty_senatu(?array $l, bool $kratce = false): string
    {
        if (!$l) {
            return '';
        }
        if (!py_bool($l['doruceno'])) {
            return py_bool($l['odeslano']) ? sprintf('odesláno %s, zatím nedoručeno', fmt_datum(den($l['odeslano']))) : '';
        }
        if (py_bool($l['rozhodnuto']) || py_bool($l['akce'])) {
            if ($kratce) {
                return '';
            }
            return sprintf('doručeno %s, lhůta do %s; Senát rozhodl%s (%s)', fmt_datum(den($l['doruceno'])), fmt_datum($l['lhuta_do']),
                py_bool($l['rozhodnuto']) ? ' ' . fmt_datum(den($l['rozhodnuto'])) : '', $l['akce'] ?? '');
        }
        if (!empty($l['zaniklo'])) {
            return $kratce ? '' : sprintf('doručeno %s, lhůta do %s; projednávání skončilo koncem volebního období',
                fmt_datum(den($l['doruceno'])), fmt_datum($l['lhuta_do']));
        }
        if (!$l['lhuta_do'] || $l['zbyva_dni'] === null) {
            return 'doručeno ' . fmt_datum(den($l['doruceno']));
        }
        $n = $l['zbyva_dni'];
        $zbyva = $n < 0 ? 'lhůta uplynula' : ($n === 0 ? 'poslední den lhůty' : sprintf('zbývá %d %s', $n, $n === 1 ? 'den' : ($n < 5 ? 'dny' : 'dní')));
        if ($kratce) {
            return sprintf('lhůta do %s (%s)', fmt_datum($l['lhuta_do']), $zbyva);
        }
        return sprintf('doručeno %s → lhůta do %s (%s)', fmt_datum(den($l['doruceno'])), fmt_datum($l['lhuta_do']), $zbyva);
    }

    protected static function senat_kratky(array $senatni, bool $jen_postoupene = false): string
    {
        $v = [];
        foreach ($senatni as $x) {
            if ($jen_postoupene && !in_array($x['typ'], [20, 21, 30], true)) {
                continue;
            }
            $v[] = sprintf("<a href='%s' target='_blank' rel='noopener' title='%s – %s'>ST %s ↗</a>", e($x['url']), e($x['anotace'] ?? ''),
                e($x['stav_nazev']), e($x['cislo']));
        }
        return implode(', ', $v);
    }

    protected static function stitek_stavu(Tisk $t): string
    {
        $titulek = $t->zanikl_koncem_obdobi() ? 'V datech Sněmovny zůstal stav: ' . $t->stav_v_datech() : null;
        $k = $t->krok_ukonceni();
        if ($k && $k->akce) {
            $titulek = sprintf('Projednávání skončilo: %s%s (v datech Sněmovny stav %s)',
                $k->akce, $k->datum ? ', ' . fmt_datum(den($k->datum) ?? $k->datum) : '', $t->stav_v_datech());
        }
        $trida = in_array($t->stav(), [Tisk::STAV_VYHLASENO, 'SCHVÁLENO', 'PODEPSÁNO', 'VZATO NA VĚDOMÍ', 'ODESLÁNO K VYHLÁŠENÍ'], true) ? ' vyhlaseno'
            : ($t->projednano() ? ' konec' : '');
        return sprintf("<span class='stitek%s'%s>%s</span>", $trida,
            $titulek ? " title='" . e($titulek) . "'" : '', e($t->stav() ?? '?'));
    }

    protected static function bod_kratky(?BodSchuze $b): string
    {
        if (!$b) {
            return '';
        }
        return sprintf("<a href='%s'>%s. schůze</a> · %s%s", e(odkaz('/schuze', ['c' => $b->schuze])), e($b->schuze),
            e($b->typ ?? ('bod ' . py_str($b->bod))), $b->navrzeny_porad ? " <span class='tlumene'>(navrženo)</span>" : '');
    }

    public function stranka_tisky(array $parametry): string
    {
        $filtr = $parametry['f'] ?? 'vse';
        if ($filtr === 'neprojednane') {
            $filtr = 'projednavane';
        }
        $druh = $parametry['d'] ?? 'vse';
        $p = $this->psp_pro();
        $zakony = $p->zakony($druh === 'vladni', $filtr === 'projednavane', $filtr === 'ukoncene');
        $klice = $this->oblibene->klice();
        $radky = [];
        foreach ($zakony as $t) {
            $pid = $t->pid_eklep();
            $etapy = $t->etapy();
            $senat = self::senat_kratky($t->senatni_tisky(), true);
            $lhuta = self::text_lhuty_senatu($etapy['Senát']['lhuta'] ?? null, true);
            $senat = implode('<br>', array_filter([$lhuta !== '' ? "<span class='lhuta'>" . e($lhuta) . '</span>' : '', $senat]));
            $bunky = '';
            foreach (Psp::NAZVY_ETAP as $n) {
                $bunky .= self::bunka_etapy($etapy[$n] ?? null, $n === 'Senát' ? $senat : '');
            }
            $ds = $t->datum_stavu();
            $radky[] = (isset($this->novely_sledovanych()[(int)$t->id_tisk()]) ? "<tr class='novela'>" : '<tr>')
                . $this->bunka_hvezdy('tisk', $t->id_tisk(), sprintf('Tisk %s – %s', $t->cislo(), py_str($t->nazev())), $klice)
                . sprintf("<td><a href='%s'>%s</a></td><td title='%s'>%s</td><td>%s</td><td>%s</td><td>%s</td><td data-razeni='%s'>%s</td>",
                    e(odkaz('/tisk', ['id' => 'id:' . $t->id_tisk()])), e($t->cislo()), e($t->uplny_nazev()),
                    e($t->nazev()) . self::stitek_poznamky($this->poznamka_tisku($t->id_tisk())) . $this->stitky_novel($t->id_tisk()),
                    e(Psp::DRUHY_ZAKONU[$t->data['id_druh'] ?? ''] ?? $t->druh()), e($t->navrhovatel()), self::stitek_stavu($t),
                    e(iso($ds)), e(fmt_datum($ds)))
                . $bunky . sprintf('<td>%s</td><td>%s</td></tr>', self::bod_kratky($t->aktualni_bod()),
                    $pid ? sprintf("<a href='%s'>%s</a>", e(odkaz('/material', ['id' => $pid])), e($pid)) : '');
        }
        $obdobi = $p->obdobi() ? (string)$p->obdobi() : 'všechna období';
        $volba = function ($klic, $hodnota, $text, $aktualni) use ($filtr, $druh) {
            $par = ['f' => $filtr, 'd' => $druh];
            $par[$klic] = $hodnota;
            return sprintf("<a class='tlacitko%s' href='%s'>%s</a>", $aktualni === $hodnota ? ' aktivni' : '', e(odkaz('/tisky', $par)), $text);
        };
        $obsah = sprintf('<h1>Návrhy zákonů <span class="tlumene">%s</span></h1>
<div class="lista filtry">%s%s &nbsp; %s%s</div>
<div class="lista"><input type="text" data-filtr="tisky" placeholder="Filtrovat v tabulce…">
%s<span class="tlumene"><span id="tisky-pocet">%d</span> tisků</span></div>
<div class="posun ukotvit" data-sloupcu="3"><table class="data raditelna" id="tisky"><thead><tr>
<th title="Oblíbené">⭐</th><th>Tisk</th><th>Název</th><th>Druh</th><th>Navrhovatel</th><th>Aktuální stav</th><th>Od</th>
<th title="Konec 1. čtení">1. čtení</th><th title="Usnesení garančního výboru po 1. čtení">GV</th>
<th title="Konec 2. čtení">2. čtení</th><th title="Usnesení garančního výboru po 2. čtení">GV</th>
<th title="Konec 3. čtení">3. čtení</th><th title="Senát">Senát</th>
<th>Schůze</th><th>eKLEP</th></tr></thead><tbody>%s</tbody></table></div>
<p class="tlumene">Sloupce etap ukazují datum ukončení (u garančního výboru rozeslání jeho usnesení);
po najetí myší je vidět, jakým krokem etapa skončila.</p>', e($obdobi),
            $volba('f', 'vse', 'Všechny', $filtr), $volba('f', 'projednavane', 'Projednávané', $filtr) . $volba('f', 'ukoncene', 'Ukončené (vč. vyhlášených)', $filtr),
            $volba('d', 'vse', 'Všechny návrhy', $druh), $volba('d', 'vladni', 'Jen vládní', $druh),
            $this->tlacitko_jen_oblibene('tisky'), count($radky), implode('', $radky));
        return $this->stranka('Sněmovní tisky', $obsah, 'tisky');
    }

    /**
     * Kroky pro výpis: kroky bez data se nedávají na začátek, ale za krok, který jim v pořadí záznamů
     * (id_hist) předchází – převezmou jeho datum pro řazení.
     */
    protected static function historie_pro_vypis(array $historie): array
    {
        $podle_id = $historie;
        usort($podle_id, function ($a, $b) { return ($a->id_hist ?? 0) <=> ($b->id_hist ?? 0); });
        $posledni = '0000';
        $klice = [];
        foreach ($podle_id as $i => $k) {
            if ($k->datum) {
                $posledni = (string)(jako_cas($k->datum) ?? $posledni);
            }
            $klice[spl_object_id($k)] = [$k->datum ? (string)(jako_cas($k->datum) ?? $posledni) : $posledni, $i];
        }
        usort($podle_id, function ($a, $b) use ($klice) { return $klice[spl_object_id($a)] <=> $klice[spl_object_id($b)]; });
        return $podle_id;
    }

    public function stranka_tisk(array $parametry): string
    {
        $p = $this->psp_pro();
        $t = $p->tisk($parametry['id'] ?? '');
        $p->prednacti_pro_tisky([$t->id_tisk()]);
        $senatni = $t->senatni_tisky();
        $info = [['Druh', e($t->druh())], ['Navrhovatel', e($t->navrhovatel())], ['Typ navrhovatele', e($t->typ_navrhovatele())],
            ['Předkladatelé', e(implode(', ', array_filter($t->predkladatele(), 'py_bool')))],
            ['Předloženo', e(fmt_datum($t->predlozeno()))], ['Rozesláno', e(fmt_datum($t->rozeslano()))],
            ['Stav', sprintf('%s · od %s%s', self::stitek_stavu($t), e(fmt_datum($t->datum_stavu())), self::popis_posledniho_kroku($t))], ['Lhůta', e($t->lhuta())],
            ['Aktuální schůze', self::bod_kratky($t->aktualni_bod())], ['Další projednávání', e(fmt_datum($t->dalsi_projednavani()))],
            ['Senát', self::senat_kratky($senatni)], ['Lhůta Senátu', e(self::text_lhuty_senatu($t->lhuta_senatu()))],
            ['Sbírka zákonů', odkaz_sbirka($t->sbirka())], ['Mění / ruší předpisy', $this->html_predpisy_tisku($t)],
            ['Úplný název', e($t->uplny_nazev())]];
        $historie = $t->historie();
        $etapy = $t->etapy();
        $body = $t->body_schuze();
        $vybory = $t->vybory();
        $nasledne = $t->nasledne_tisky();
        $eklep = $t->eklep();
        $nadpis = 'Sněmovní tisk ' . $t->cislo();
        $podnadpis = $t->obdobi() ? $t->obdobi() . '. volební období' : '';
        $url_psp = $t->url();
        $url_text = self::url_psp($t->url_text());
        $radky_info = implode('', array_map(function ($r) { return $r[1] !== '' ? sprintf('<tr><th>%s</th><td>%s</td></tr>', $r[0], $r[1]) : ''; }, $info));

        if ($eklep) {
            $tab_eklep = "<div class='posun'><table class='data'><thead><tr><th>Tisk</th><th>PID</th><th>Čj OVA</th><th>Veřejné</th><th></th></tr></thead><tbody>"
                . implode('', array_map(function ($x) {
                    return sprintf("<tr><td>%s%s</td><td><a href='%s'>%s</a></td><td>%s</td><td>%s</td><td><a href='%s' target='_blank' rel='noopener'>ODOK ↗</a></td></tr>",
                        e($x['tisk']), $x['nasledny'] ? " <span class='tlumene'>(následný)</span>" : '', e(odkaz('/material', ['id' => $x['pid']])),
                        e($x['pid']), e($x['cj_ova']), $x['verejne'] ? 'ano' : 'ne', e($x['url']));
                }, $eklep));
            // materiály se stejným čj OVA, na které tisk přímo neodkazuje (rozdělený materiál, státní rozpočet)
            $zname = array_map(function ($x) { return strtoupper($x['pid']); }, $eklep);
            $dalsi = [];
            foreach (array_unique(array_filter(array_column($eklep, 'cj_ova'))) as $ova) {
                foreach (Psp::materialy_podle_ova($ova) as $pid => $mat) {
                    if (!in_array($pid, $zname, true) && !isset($dalsi[$pid])) {
                        $dalsi[$pid] = $mat;
                    }
                }
            }
            foreach ($dalsi as $pid => $mat) {
                $tab_eklep .= sprintf("<tr><td class='tlumene'>stejné čj OVA</td><td><a href='%s'>%s</a><div class='mensi tlumene'>%s</div></td><td>%s</td><td></td><td><a href='%s' target='_blank' rel='noopener'>ODOK ↗</a></td></tr>",
                    e(odkaz('/material', ['id' => $pid])), e($pid), e(mb_strimwidth((string)$mat['nazev'], 0, 120, '…')), e($mat['cj_ova']),
                    e(vzor(URL_ODOK, ['pid' => $pid])));
            }
            $tab_eklep .= '</tbody></table></div>';
        } else {
            $tab_eklep = "<p class='tlumene'>Tisk nemá v datech Sněmovny vazbu na eKLEP.</p>";
        }
        if ($etapy) {
            $r = '';
            foreach ($etapy as $nazev => $x) {
                $r .= sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', e($nazev),
                    e(fmt_datum($x['od'])) . (!empty($x['lhuta']['doruceno']) ? " <span class='tlumene'>(doručení)</span>" : ''),
                    $x['probiha'] ? "<span class='stitek'>probíhá</span>" : e(fmt_datum($x['do'])),
                    e($x['akce']) . ((!empty($x['lhuta']) && $x['probiha']) ? "<div class='lhuta'>" . e(self::text_lhuty_senatu($x['lhuta'], true)) . '</div>' : ''));
            }
            $tab_etapy = "<div class='posun'><table class='data'><thead><tr><th>Etapa</th><th>Začátek</th><th>Konec</th><th>Jak skončila</th></tr></thead><tbody>$r</tbody></table></div>";
        } else {
            $tab_etapy = "<p class='tlumene'>Tisk zatím neprošel žádnou etapou projednávání.</p>";
        }
        if ($historie) {
            $r = '';
            $predchozi_stav = null;
            foreach (self::historie_pro_vypis($historie) as $k) {
                $kde = $k->stav_predtim;
                if ($k->typ_odkud === Psp::TYP_STAVU_PROJEDNANO && $predchozi_stav !== null) {
                    $kde = $predchozi_stav;      // místo surového „KONEC“ navázat na předchozí krok (PODEPSÁNO …)
                }
                $posun = $k->stav !== null && $k->stav !== $kde;
                $predchozi_stav = $k->stav ?? $predchozi_stav;
                $zacatek = (bool)preg_match('/zah[áa]jen/u', (string)$k->akce);
                $r .= sprintf("<tr class='%s'><td data-razeni='%s'%s>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>",
                    $zacatek ? 'krok-zacatek' : ($posun ? 'krok-posun' : 'krok-uvnitr'),
                    e(iso($k->datum)), $k->datum ? '' : " title='Datum v datech Sněmovny chybí – řazeno podle pořadí záznamů'",
                    $k->datum ? e(fmt_datum($k->datum)) : "<span class='tlumene'>—</span>",
                    $kde !== null ? "<span class='faze'>" . e($kde) . '</span>' : "<span class='tlumene'>podání</span>",
                    ($zacatek ? '▶ ' : '') . e($k->akce),
                    $posun ? "<span class='posun-faze' title='Tímto krokem tisk přešel do další fáze; projednávání v ní začíná až krokem „zahájeno“ nebo bodem schůze'>→ " . e($k->stav) . '</span>'
                        : "<span class='tlumene' title='Krok uvnitř fáze – fáze se nemění'>zůstává</span>",
                    py_bool($k->schuze) ? sprintf("<a href='%s'>%s. schůze</a>%s", e(odkaz('/schuze', ['c' => $k->schuze])), e($k->schuze),
                        py_bool($k->bod) ? ', bod ' . e($k->bod) : '') : '',
                    e($k->usneseni), py_bool($k->id_hlasovani) ? sprintf("<a href='%s' target='_blank' rel='noopener'>%s ↗</a>", e($k->url_hlasovani()), e($k->id_hlasovani)) : '',
                    odkaz_sbirka($k->sbirka()), e($k->poznamka));
            }
            $tab_hist = "<p class='tlumene'>Každý řádek je jeden krok v databázi Sněmovny: <b>kde</b> (ve které fázi) se odehrál, <b>co se stalo</b>
a zda tisk tím <b>postoupil do další fáze</b> (→). Postup do fáze ještě neznamená, že v ní projednávání začalo – například
„Organizační výbor · doporučen · → 1. čtení“ znamená, že 1. čtení teprve bude; jeho skutečný začátek ukazuje krok ▶ zahájeno
nebo bod schůze.</p><div class='posun'><table class='data historie'><thead><tr><th>Datum</th><th>Kde (fáze)</th><th>Co se stalo</th><th>Posun</th>"
                . "<th>Schůze</th><th>Usnesení</th><th>Hlasování</th><th>Sbírka</th><th>Poznámka</th></tr></thead><tbody>$r</tbody></table></div>";
        } else {
            $tab_hist = "<p class='tlumene'>Bez záznamů.</p>";
        }
        if ($body) {
            $r = '';
            foreach ($body as $b) {
                $r .= sprintf("<tr><td><a href='%s'>%s. schůze</a></td><td>%s – %s</td><td>%s</td><td>%s%s</td><td>%s</td></tr>",
                    e(odkaz('/schuze', ['c' => $b->schuze])), e($b->schuze), e(fmt_datum($b->od)), e(fmt_datum($b->do)), e($b->bod), e($b->typ),
                    $b->navrzeny_porad ? " <span class='tlumene'>(navržený pořad)</span>" : '', e($b->stav));
            }
            $tab_body = "<div class='posun'><table class='data'><thead><tr><th>Schůze</th><th>Termín</th><th>Bod</th><th>Projednávání</th><th>Stav bodu</th></tr></thead><tbody>$r</tbody></table></div>";
        } else {
            $tab_body = "<p class='tlumene'>Tisk zatím nebyl na pořadu schůze.</p>";
        }
        $tab_vybory = $vybory ? "<div class='posun'><table class='data'><thead><tr><th>Výbor</th><th>Typ</th><th>Garanční</th><th>Zpravodaj</th></tr></thead><tbody>"
            . implode('', array_map(function ($v) {
                return sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', e($v['vybor']), e($v['typ']), $v['garancni'] ? 'ano' : '', e($v['zpravodaj']));
            }, $vybory)) . '</tbody></table></div>' : "<p class='tlumene'>Bez přikázání výborům.</p>";
        $tab_nasl = $nasledne ? "<div class='posun'><table class='data'><thead><tr><th>Tisk</th><th>Druh</th><th>Název</th><th>Rozesláno</th><th>Výsledek</th></tr></thead><tbody>"
            . implode('', array_map(function ($n) {
                return sprintf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    py_bool($n['url']) ? sprintf("<a href='%s' target='_blank' rel='noopener'>%s ↗</a>", e(self::url_psp($n['url'])), e($n['cislo'])) : e($n['cislo']),
                    e($n['druh']), e($n['nazev']), e(fmt_datum($n['rozeslano'])), e($n['vysledek']));
            }, $nasledne)) . '</tbody></table></div>' : "<p class='tlumene'>Žádné následné tisky.</p>";
        $tab_senat = $senatni ? "<div class='posun'><table class='data'><thead><tr><th>Senátní tisk</th><th>Období</th><th>Druh</th><th>Stav</th><th>Vazba</th><th>Název</th></tr></thead><tbody>"
            . implode('', array_map(function ($x) {
                return sprintf("<tr><td><a href='%s' target='_blank' rel='noopener'>%s ↗</a></td><td>%s. funkční období</td><td>%s</td><td><span class='stitek'>%s</span></td><td>%s</td><td><b>%s</b><div class='tlumene'>%s</div></td></tr>",
                    e($x['url']), e($x['cislo']), e($x['obdobi']), e($x['druh'] ?? ''), e($x['stav_nazev']), e($x['typ_popis']), e($x['anotace'] ?? ''), e($x['nazev'] ?? ''));
            }, $senatni)) . '</tbody></table></div>' : "<p class='tlumene'>Tisk zatím nebyl postoupen Senátu.</p>";
        $odkazy = implode(' · ', array_filter([
            $url_psp ? sprintf("<a href='%s' target='_blank' rel='noopener'>historie na psp.cz ↗</a>", e($url_psp)) : '',
            $url_text ? sprintf("<a href='%s' target='_blank' rel='noopener'>text tisku ↗</a>", e($url_text)) : '']));
        $obsah = sprintf('<h1>%s%s <span class="tlumene">%s</span></h1>
<p>%s</p><p class="tlumene">%s</p>
<div class="karta"><table class="kv">%s</table></div>
<h2>Vazba na eKLEP / ODOK</h2>%s
<h2>Etapy projednávání</h2>%s
<h2>Legislativní proces</h2>%s
<h2>Projednávání na schůzích</h2>%s
<h2>Výbory</h2>%s
<h2>Následné tisky</h2>%s
<h2>Senát</h2>%s', $this->hvezda('tisk', $t->id_tisk(), sprintf('%s – %s', $nadpis, py_str($t->nazev()))), e($nadpis), e($podnadpis),
            e($t->nazev()), $odkazy, $radky_info, $tab_eklep, $tab_etapy, $tab_hist, $tab_body, $tab_vybory, $tab_nasl, $tab_senat);
        return $this->stranka($nadpis, $obsah, 'tisky');
    }

    public function stranka_schuze(array $parametry): string
    {
        $cislo = $parametry['c'] ?? '';
        $p = $this->psp_pro();
        $obdobi = $p->obdobi() ? (string)$p->obdobi() : '';
        $oblibene = $this->oblibene_tisky();
        if ($cislo === '') {
            // kolik oblíbených tisků je v pořadu které schůze
            $na_schuzi = [];
            if ($oblibene) {
                $p->prednacti_pro_tisky(array_keys($oblibene));
                foreach (array_keys($oblibene) as $id) {
                    $r = $p->jeden('tisky', 'id_tisk', $id);
                    if (!$r || !$p->v_obdobi_tisk($r)) {     // čísla schůzí se v každém období opakují
                        continue;
                    }
                    $t = new Tisk($r, $p);
                    foreach ($t->body_schuze() as $b) {
                        if ($b->schuze !== null) {
                            $na_schuzi[(int)$b->schuze][$id] = $t->cislo();
                        }
                    }
                }
            }
            // body, ve kterých se mění sledovaný předpis
            $novely = $this->novely_sledovanych();
            $novely_na_schuzi = [];
            if ($novely) {
                $p->prednacti_pro_tisky(array_keys($novely));
                foreach ($novely as $id => $predpisy) {
                    $r = $p->jeden('tisky', 'id_tisk', $id);
                    if (!$r || !$p->v_obdobi_tisk($r)) {     // čísla schůzí se v každém období opakují
                        continue;
                    }
                    $t = new Tisk($r, $p);
                    foreach ($t->body_schuze() as $b) {
                        if ($b->schuze !== null) {
                            $novely_na_schuzi[(int)$b->schuze][$id] = sprintf('%s (%s)', $t->cislo(), implode(', ', array_keys($predpisy)));
                        }
                    }
                }
            }
            $radky = '';
            foreach ($p->schuze() as $s) {
                $moje = $na_schuzi[(int)$s['cislo']] ?? [];
                $nov = $novely_na_schuzi[(int)$s['cislo']] ?? [];
                $titulky = array_filter([$moje ? 'Oblíbené tisky v pořadu: ' . implode(', ', $moje) : '',
                    $nov ? 'Novely sledovaných předpisů: ' . implode(', ', $nov) : '']);
                $radky .= sprintf("<tr%s><td><a href='%s'>%s. schůze</a></td><td>%s</td><td>%s</td><td>%s</td><td data-razeni='%d'%s>%s</td><td>%s</td></tr>",
                    $moje ? " class='oblibeny'" : ($nov ? " class='novela'" : ''), e(odkaz('/schuze', ['c' => $s['cislo']])), e($s['cislo']), e(fmt_datum($s['od'])), e(fmt_datum($s['do'])),
                    $s['navrzeny_porad'] ? 'navržený' : 'schválený', count($moje) * 1000 + count($nov),
                    $titulky ? " title='" . e(implode(' · ', $titulky)) . "'" : '',
                    trim(($moje ? HVEZDA . ' ' . count($moje) : '') . ($nov ? " <span class='znak-novely'>§ " . count($nov) . '</span>' : '')),
                    $s['url'] ? sprintf("<a href='%s' target='_blank' rel='noopener'>psp.cz ↗</a>", e($s['url'])) : '');
            }
            $obsah = sprintf('<h1>Schůze Poslanecké sněmovny <span class="tlumene">%s</span></h1>
<div class="posun"><table class="data"><thead><tr><th>Schůze</th><th>Od</th><th>Do</th><th>Pořad</th><th title="Oblíbené tisky v pořadu schůze; § = novely sledovaných předpisů">Moje</th><th></th>
</tr></thead><tbody>%s</tbody></table></div>%s', e($obdobi), $radky,
                ($oblibene || $novely) ? "<p class='tlumene'>Žlutě podbarvené schůze mají v pořadu tisk z vašich oblíbených (i přes oblíbený materiál ODOK)"
                    . ($novely ? ", modře (§) novelu některého ze sledovaných předpisů" : '') . '.</p>' : '');
            return $this->stranka('Schůze PS', $obsah, 'schuze');
        }
        $cislo = (int)$cislo;
        $body = $p->body_schuze($cislo);
        $info = [];
        foreach ($p->schuze() as $s) {
            if ($s['cislo'] === $cislo) {
                $info = $s;
                break;
            }
        }
        $radky = '';
        $klice = $this->oblibene->klice();
        $moje = 0;
        $novel = 0;
        foreach ($body as $b) {
            $proc = py_bool($b->id_tisk) ? ($oblibene[(int)$b->id_tisk] ?? null) : null;
            $moje += $proc ? 1 : 0;
            $novela = py_bool($b->id_tisk) ? $this->stitky_novel($b->id_tisk) : '';
            $novel += $novela !== '' ? 1 : 0;
            $hvezda = py_bool($b->id_tisk) ? $this->bunka_hvezdy('tisk', $b->id_tisk, sprintf('Tisk – %s', py_str($b->nazev)), $klice)
                : "<td class='sl-hvezda'></td>";
            $radky .= sprintf('<tr%s>%s<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s%s%s%s</td></tr>',
                $proc ? " class='oblibeny' title='" . e($proc) . "'" : ($novela !== '' ? " class='novela'" : ''), $this->oblibene->aktivni() ? $hvezda : '', e($b->bod),
                py_bool($b->id_tisk) ? $this->odkaz_tisk_kratky($b->id_tisk) : '', e($b->typ), e($b->stav), e($b->nazev),
                py_bool($b->id_tisk) ? self::stitek_poznamky($this->poznamka_tisku($b->id_tisk)) : '',
                $proc && strpos($proc, 'materiál') !== false ? " <span class='mensi tlumene'>(" . e($proc) . ')</span>' : '', $novela);
        }
        $obsah = sprintf('<h1>%d. schůze <span class="tlumene">%s</span></h1>
<p class="tlumene">%s – %s · %s pořad %s</p>
<div class="lista"><input type="text" data-filtr="porad" placeholder="Filtrovat v pořadu…">
%s<span class="tlumene"><span id="porad-pocet">%d</span> bodů%s</span></div>
<div class="posun"><table class="data raditelna" id="porad"><thead><tr>%s<th>Bod</th><th>Tisk</th>
<th>Projednávání</th><th>Stav</th><th>Název</th></tr></thead><tbody>%s</tbody></table></div>', $cislo, e($obdobi),
            e(fmt_datum($info['od'] ?? null)), e(fmt_datum($info['do'] ?? null)), !empty($info['navrzeny_porad']) ? 'navržený' : 'schválený',
            !empty($info['url']) ? sprintf("· <a href='%s' target='_blank' rel='noopener'>psp.cz ↗</a>", e($info['url'])) : '',
            $moje ? $this->tlacitko_jen_oblibene('porad') : '', count($body),
            ($moje ? sprintf(', z toho <b>%d %s</b> z oblíbených', $moje, $moje === 1 ? 'tisk' : ($moje < 5 ? 'tisky' : 'tisků')) : '')
                . ($novel ? sprintf(', <b>%d</b> %s sledovaný předpis (§)', $novel, $novel === 1 ? 'mění' : 'mění') : ''),
            $this->oblibene->aktivni() ? '<th title="Oblíbené">⭐</th>' : '', $radky);
        return $this->stranka("$cislo. schůze", $obsah, 'schuze');
    }

    /**
     * Oblíbené tisky uživatele: [id_tisk => důvod] – tisk označený hvězdičkou, nebo tisk k oblíbenému
     * materiálu ODOK (přes vazbu eKLEP).
     */
    protected function oblibene_tisky(): array
    {
        if (!$this->oblibene->aktivni() || !$this->psp_ok()) {
            return [];
        }
        $v = [];
        foreach ($this->oblibene->seznam('tisk') as $p) {
            if (ctype_digit((string)$p['id'])) {
                $v[(int)$p['id']] = 'oblíbený tisk';
            }
        }
        $pidy = array_column($this->oblibene->seznam('material'), 'id');
        if ($pidy) {
            foreach ($this->snemovna()->pid_tisk($pidy) as $pid => $id) {
                $v[(int)$id] = $v[(int)$id] ?? "oblíbený materiál $pid";
            }
        }
        return $v;
    }

    /**
     * Tisky, které mění nebo ruší některý ze sledovaných předpisů (⭐ u předpisu):
     * [id_tisk => [ident => ['nazev' => …, 'poznamka' => …, 'rusi' => bool]]].
     */
    protected function novely_sledovanych(): array
    {
        if ($this->novely_cache !== null) {
            return $this->novely_cache;
        }
        $v = [];
        $sledovane = $this->oblibene->aktivni() ? $this->oblibene->seznam('predpis') : [];
        if ($sledovane && $this->psp_ok() && $this->snemovna()->ma_sbirku()) {
            $psp = $this->snemovna();
            foreach ($sledovane as $s) {
                $cislo = self::parsuj_predpis((string)$s['id']);
                $predpisy = $cislo ? $psp->predpisy($cislo[0], $cislo[1]) : [];
                if (!$predpisy) {
                    continue;
                }
                $nazev = $psp->nazev_predpisu($predpisy[0]);
                foreach ($psp->zmeny_predpisu(array_column($predpisy, 'id_sbirka')) as $z) {
                    $v[(int)$z['tisk']->id_tisk()][(string)$s['id']] = ['nazev' => $nazev, 'poznamka' => $this->poznamka_predpisu($s['id']),
                        'rusi' => $z['typ'] === Psp::ZMENA_RUSI];
                }
            }
        }
        return $this->novely_cache = $v;
    }

    /** Štítky „§ 280/2009“ (nebo „§ přezdívka“) u tisku, který mění sledovaný předpis, nebo ''. */
    protected function stitky_novel($id_tisk): string
    {
        $v = '';
        foreach ($this->novely_sledovanych()[(int)$id_tisk] ?? [] as $ident => $x) {
            $popis = sprintf('%s sledovaný předpis %s Sb.%s%s', $x['rusi'] ? 'Ruší' : 'Mění', $ident,
                $x['nazev'] ? ' – ' . $x['nazev'] : '', $x['poznamka'] ? ' (' . $x['poznamka'] . ')' : '');
            $v .= sprintf(" <a class='stitek-novely' href='%s' title='%s'>§ %s</a>", e(odkaz('/novelizace', ['q' => $ident])), e($popis),
                e($x['poznamka'] ? mb_strimwidth($x['poznamka'], 0, 28, '…') : $ident));
        }
        return $v;
    }

    /** Sledované předpisy uživatele: [ident => ['nazev' => …, 'poznamka' => …]]. */
    protected function sledovane_predpisy(): array
    {
        static $v = null;
        if ($v === null) {
            $v = [];
            foreach ($this->oblibene->aktivni() ? $this->oblibene->seznam('predpis') : [] as $s) {
                $v[(string)$s['id']] = ['nazev' => $s['nazev'], 'poznamka' => $this->poznamka_predpisu($s['id'])];
            }
        }
        return $v;
    }

    /** Štítky § u materiálu ODOK (vyhláška, nařízení, zákon bez tisku), který mění sledovaný předpis. */
    protected function stitky_novel_materialu($m, ?NavrhEsel $n = null): string
    {
        $sled = $this->sledovane_predpisy();
        if (!$sled) {
            return '';
        }
        $v = '';
        foreach (Novely::predpisy_navrhu($n, $m->nazev) as [$c, $r]) {
            $ident = "$c/$r";
            if (!isset($sled[$ident])) {
                continue;
            }
            $x = $sled[$ident];
            $v .= sprintf(" <a class='stitek-novely' href='%s' title='%s'>§ %s</a>", e(odkaz('/novelizace', ['q' => $ident])),
                e(sprintf('Mění sledovaný předpis %s Sb.%s%s', $ident, $x['nazev'] ? ' – ' . $x['nazev'] : '', $x['poznamka'] ? ' (' . $x['poznamka'] . ')' : '')),
                e($x['poznamka'] ? mb_strimwidth($x['poznamka'], 0, 28, '…') : $ident));
        }
        return $v;
    }

    public function cast_tisk_k_materialu(string $pid): string
    {
        $p = $this->psp_pro();
        $t = $p->tisk_k_materialu($pid);
        if ($t === null) {
            return "<p class='tlumene'>K materiálu není v datech Sněmovny žádný tisk (zatím nebyl předložen, nebo nemá vazbu na eKLEP).</p>";
        }
        $p->prednacti_pro_tisky([$t->id_tisk()]);
        $vazba = null;
        foreach ($t->eklep() as $x) {
            if (strtoupper($x['pid']) === strtoupper($pid)) {
                $vazba = $x;
                break;
            }
        }
        $pk = $t->posledni_krok();
        $radky = [
            ['Sněmovní tisk', sprintf("<a href='%s'>%s</a> (%s. volební období)", e(odkaz('/tisk', ['id' => 'id:' . $t->id_tisk()])), e($t->cislo()), e(py_str($t->obdobi())))],
            ['Název', e($t->nazev())],
            ['Stav', sprintf('%s · od %s', self::stitek_stavu($t), e(fmt_datum($t->datum_stavu())))],
            ['Poslední krok', $pk ? e(trim(($pk->stav_predtim ? $pk->stav_predtim . ': ' : '') . $pk->akce))
                . ($pk->stav && $pk->stav !== $pk->stav_predtim ? " <span class='posun-faze'>→ " . e($pk->stav) . '</span>' : '') : ''],
            ['Schůze', self::bod_kratky($t->aktualni_bod())],
            ['Senát', self::senat_kratky($t->senatni_tisky())],
            ['Lhůta Senátu', e(self::text_lhuty_senatu($t->lhuta_senatu()))],
            ['Sbírka zákonů', odkaz_sbirka($t->sbirka())],
            ['Mění / ruší předpisy', $this->html_predpisy_tisku($t)],
            ['Čj OVA', $vazba ? e($vazba['cj_ova']) : ''],
        ];
        if ($vazba && $vazba['nasledny']) {
            array_splice($radky, 1, 0, [['Vazba', sprintf('materiál je následným tiskem %s (např. stanovisko vlády)', e($vazba['tisk']))]]);
        }
        if ($vazba === null) {      // nalezeno záložně podle čj OVA
            $ova = Psp::ova_materialu([strtoupper(trim($pid))])[strtoupper(trim($pid))] ?? '';
            $dalsi = [];
            foreach ($p->tisky_podle_ova($ova) as $id) {
                if ((int)$id !== (int)$t->id_tisk()) {
                    $dalsi[] = $this->odkaz_tisk_kratky($id);
                }
            }
            $pid_tisku = array_filter(array_map(function ($x) { return $x['pid']; }, $t->eklep()));
            array_splice($radky, 1, 0, [['Vazba', sprintf('podle čj OVA %s – tisk je v datech Sněmovny navázán na jiný materiál eKLEP%s '
                . '(materiál byl po vládě rozdělen, např. státní rozpočet)%s', e($ova),
                $pid_tisku ? ' (' . implode(', ', array_map(function ($x) { return sprintf("<a href='%s'>%s</a>", e(odkaz('/material', ['id' => $x])), e($x)); }, $pid_tisku)) . ')' : '',
                $dalsi ? '; další tisk se stejným čj OVA: ' . implode(', ', array_filter($dalsi)) : '')]]);
        }
        return "<div class='karta'><table class='kv'>" . implode('', array_map(function ($r) {
            return $r[1] !== '' ? sprintf('<tr><th>%s</th><td>%s</td></tr>', $r[0], $r[1]) : '';
        }, $radky)) . '</table></div>';
    }

    // ---------------- Novelizace (Sbírka zákonů) ----------------

    /** '280/2009', '280/2009 Sb.', 'zákon č. 280/2009' → [280, 2009]; jinak null. */
    public static function parsuj_predpis(string $q): ?array
    {
        if (preg_match('~^\s*(?:(?:zákon|zakon|z\.|vyhláška|vyhlaska|nařízení|narizeni)?\s*(?:č\.|c\.)?\s*)(\d{1,4})\s*/\s*(\d{4})\s*(?:sb\.?)?\s*$~iu', $q, $m)) {
            return [(int)$m[1], (int)$m[2]];
        }
        return null;
    }

    /** „(doporučen – Organizační výbor)“: poslední krok, aby bylo jasné, že fáze třeba teprve bude. */
    protected static function popis_posledniho_kroku(Tisk $t): string
    {
        $k = $t->posledni_krok();
        if (!$k || !$k->akce || $t->projednano()) {
            return '';
        }
        return sprintf(" <span class='tlumene'>(poslední krok: %s%s)</span>", e($k->akce), $k->stav_predtim ? ' – ' . e($k->stav_predtim) : '');
    }

    /** Barevný štítek druhu změny předpisu: „mění“ / „ruší“. */
    protected static function stitek_zmeny(int $typ): string
    {
        return $typ === Psp::ZMENA_RUSI
            ? "<span class='stitek zmena-rusi' title='Návrh předpis ruší'>ruší</span>"
            : "<span class='stitek zmena-meni' title='Návrh předpis mění (novelizuje)'>mění</span>";
    }

    /** Odkaz na stránku novelizací předpisu. */
    protected static function odkaz_predpis(array $p, ?string $nazev = null): string
    {
        return sprintf("<a href='%s'%s>%s</a>", e(odkaz('/novelizace', ['q' => Psp::ident_predpisu($p)])),
            $nazev ? " title='" . e($nazev) . "'" : '', e(Psp::oznaceni_predpisu($p)));
    }

    /** „mění 280/2009 Sb. (Daňový řád); ruší …“ pro detail tisku a kartu tisku u materiálu. */
    protected function html_predpisy_tisku(Tisk $t): string
    {
        $p = $this->snemovna();
        $polozky = [];
        foreach ($p->predpisy_tisku($t->id_tisk()) as $x) {
            $nazev = $p->nazev_predpisu($x['predpis']);
            $polozky[] = sprintf('%s %s%s%s', self::stitek_zmeny($x['typ']),
                self::odkaz_predpis($x['predpis'], $nazev),
                $nazev ? " <span class='tlumene'>" . e(mb_strimwidth($nazev, 0, 90, '…')) . '</span>' : '',
                $x['cz'] > 0 ? sprintf(" <span class='tlumene' title='Změna přibyla až v tisku %s/%d'>(od %s/%d)</span>", e($t->cislo()), $x['cz'], e($t->cislo()), $x['cz']) : '');
        }
        if (!$polozky) {
            return '';
        }
        $prvni = array_slice($polozky, 0, 6);
        $dalsi = array_slice($polozky, 6);
        return "<ul class='predpisy'><li>" . implode('</li><li>', $prvni) . '</li></ul>'
            . ($dalsi ? sprintf("<details><summary>a dalších %d předpisů</summary><ul class='predpisy'><li>%s</li></ul></details>", count($dalsi), implode('</li><li>', $dalsi)) : '');
    }

    /** Předpisy, které mění návrh v e-Legislativě (úplné znění v návrhu, záložně název; u vyhlášeného i e-Sbírka). */
    protected function html_predpisy_navrhu(?NavrhEsel $n, ?string $nazev_materialu = null): string
    {
        $polozky = [];
        $zdroje = [];
        $predpisy = Novely::predpisy_navrhu($n, $nazev_materialu);
        if ($n !== null && $n->citace && preg_match('~(\d+)\s*/\s*(\d{4})~', (string)$n->citace, $cm)) {
            foreach (Novely::menene_vyhlasenym((int)$cm[1], (int)$cm[2]) as [$c, $r, $rusi]) {
                $predpisy[] = [$c, $r, 'e-Sbírka', $rusi];
            }
        }
        foreach ($predpisy as $x) {
            [$c, $r, $zdroj] = $x;
            $k = "$c/$r";
            $zdroje[$zdroj] = true;
            if (isset($polozky[$k])) {
                continue;
            }
            $esb = Psp::esb_predpis($c, $r);
            $nazev = $esb ? Psp::nazev_esb($esb) : null;
            $polozky[$k] = sprintf("%s <a href='%s'>%s Sb.</a>%s%s", self::stitek_zmeny(!empty($x[3]) ? Psp::ZMENA_RUSI : Psp::ZMENA_MENI),
                e(odkaz('/novelizace', ['q' => $k])), e($k), $nazev ? " <span class='tlumene'>" . e(mb_strimwidth($nazev, 0, 90, '…')) . '</span>' : '',
                self::stitek_poznamky($this->poznamka_predpisu($k)));
        }
        if (!$polozky) {
            return '';
        }
        return "<ul class='predpisy'><li>" . implode('</li><li>', $polozky) . "</li></ul><span class='mensi tlumene'>podle: "
            . e(implode(', ', array_keys($zdroje))) . '</span>';
    }

    /** Patří tisk do zvoleného volebního období / roku (volba v liště)? Rok = rok předložení. */
    protected function tisk_ve_vyberu(Tisk $t): bool
    {
        $cislo = $this->cislo_obdobi();
        if ($cislo !== 'vse' && (int)$t->obdobi() !== (int)$cislo) {
            return false;
        }
        $rok = $this->vybrany_rok();
        if ($rok) {
            $d = den($t->predlozeno());
            return $d !== null && (int)substr($d, 0, 4) === $rok;
        }
        return true;
    }

    /** Poznámka nad výpisem novel: co je ve výběru a odkaz na všechna období. */
    protected function poznamka_vyberu_novel(int $skryto, string $zpet): string
    {
        $vse = $this->cislo_obdobi() === 'vse' && !$this->vybrany_rok();
        if ($vse) {
            return "<p class='tlumene'>Zobrazeny návrhy ze všech volebních období.</p>";
        }
        $cislo = $this->cislo_obdobi();
        $rok = $this->vybrany_rok();
        $popis = $rok ? sprintf('předložené v roce %d', $rok) . ($cislo !== 'vse' ? sprintf(' (%s. volební období)', $cislo) : '')
            : sprintf('z %s. volebního období', $cislo);
        return sprintf("<p class='tlumene'>Zobrazeny návrhy %s%s · <a href='%s'>zobrazit všechna období</a></p>",
            e($popis), $skryto ? sprintf(' – %d %s mimo tento výběr', $skryto, $skryto === 1 ? 'další je' : ($skryto < 5 ? 'další jsou' : 'dalších je')) : '',
            e(odkaz('/nastav', ['vyber' => 'vse', 'zpet' => $zpet])));
    }

    /** Výsledky hledání předpisu podle názvu / zkratky (e-Sbírka). Jediný výsledek → rovnou novelizace. */
    protected function vysledky_hledani_predpisu(string $q): string
    {
        if (!Psp::ma_esbirku()) {
            return "<p>Zadejte předpis jako číslo/rok, např. <b>280/2009</b>. Hledání podle názvu bude fungovat po prvním importu názvů z e-Sbírky (zdroj „e-Sbírka – názvy předpisů“ v Správa → Data a import).</p>";
        }
        $nalezene = Psp::hledej_predpisy($q);
        if (count($nalezene) === 1) {
            $this->presmeruj(odkaz('/novelizace', ['q' => $nalezene[0]['cislo'] . '/' . $nalezene[0]['rok']]));
        }
        if (!$nalezene) {
            return sprintf("<p>Předpis „%s“ jsem nenašel. Zkuste jiné slovo z názvu, zkratku (např. DPH, NOZ, DŘ) nebo číslo/rok.</p>", e($q));
        }
        $klice = $this->oblibene->klice();
        $druhy = ['ZAKON' => 'zákon', 'ZAKONUST' => 'ústavní zákon', 'NARIZVLA' => 'nařízení vlády', 'VYHLASKA' => 'vyhláška',
            'SDELENI' => 'sdělení', 'NALUS' => 'nález ÚS', 'ZAKOPATR' => 'zákonné opatření', 'USNESENI' => 'usnesení', 'ROZHODNUTI' => 'rozhodnutí'];
        $radky = '';
        foreach ($nalezene as $e) {
            $ident = $e['cislo'] . '/' . $e['rok'];
            $nazev = (string)Psp::nazev_esb($e);
            $radky .= sprintf("<tr>%s<td data-razeni='%04d-%05d'><a href='%s'>%s Sb.</a></td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td></tr>",
                $this->bunka_hvezdy('predpis', $ident, "$ident Sb. – $nazev", $klice), (int)$e['rok'], (int)$e['cislo'],
                e(odkaz('/novelizace', ['q' => $ident])), e($ident), e($nazev) . self::stitek_poznamky($this->poznamka_predpisu($ident)),
                $nazev !== trim((string)$e['nazev'], ' .') ? "<div class='mensi tlumene'>" . e(trim((string)$e['nazev'], ' .')) . '</div>' : '',
                e(implode(', ', Psp::zkratky_esb($e))), e(py_or($e['druh_nazev'] ?? null, $druhy[$e['druh']] ?? mb_strtolower((string)$e['druh']))),
                $e['zruseno'] ? 'zrušen ' . e(fmt_datum($e['zruseno'])) : '<span class="stitek vyhlaseno">platný</span>');
        }
        return sprintf('<h2>Předpisy odpovídající „%s“ (%d%s)</h2>
<div class="posun"><table class="data raditelna" id="predpisy-hledani"><thead><tr><th title="Sledovat">⭐</th><th>Předpis</th><th>Název</th>
<th>Zkratky</th><th>Druh</th><th>Stav</th></tr></thead><tbody>%s</tbody></table></div>
<p class="tlumene">Podle názvů a zkratek z e-Sbírky (jen Sbírka zákonů). Kliknutím na předpis zobrazíte jeho novelizace.</p>',
            e($q), count($nalezene), count($nalezene) >= 50 ? ', zobrazeno prvních 50' : '', $radky);
    }

    /** Karta předpisu na stránce hledání (číslo předpisu zadané do hlavního vyhledávání); '' = předpis neznámý. */
    protected function karta_predpisu_hledani(int $c, int $r, string $q_puvodni): string
    {
        $esb = Psp::esb_predpis($c, $r);
        $predpisy = ($this->psp_ok() && $this->snemovna()->ma_sbirku()) ? $this->snemovna()->predpisy($c, $r) : [];
        if (!$esb && !$predpisy) {
            return '';
        }
        $nazev = $esb ? Psp::nazev_esb($esb) : ($predpisy ? $this->snemovna()->nazev_predpisu($predpisy[0]) : null);
        $novely = '';
        if ($predpisy) {
            $zmeny = $this->snemovna()->zmeny_predpisu(array_column($predpisy, 'id_sbirka'));
            $bezi = count(array_filter($zmeny, function ($z) { return !$z['tisk']->projednano(); }));
            $novely = $zmeny ? sprintf(' · ve Sněmovně %d %s, z toho %d %s', count($zmeny),
                count($zmeny) === 1 ? 'návrh ho mění' : 'návrhů ho mění', $bezi, $bezi === 1 ? 'projednávaný' : 'projednávaných') : '';
        }
        $ident = "$c/$r";
        return sprintf("<div class='karta karta-predpis'>%s <b>Předpis %s Sb.</b>%s%s%s<div class='lista' style='margin:8px 0 0'>
<a class='tlacitko' href='%s'>Zobrazit novelizace a návrhy, které ho zmiňují</a> %s</div>
<p class='tlumene mensi'>Níže jsou materiály ODOK, které mají „%s“ v textu. Jen text bez karty předpisu: zadejte <a href='%s'>\"%s\"</a> v uvozovkách.
<a href='%s'>Hledáte čj OVA?</a></p></div>",
            $this->hvezda('predpis', $ident, "$ident Sb." . ($nazev ? " – $nazev" : '')), e($ident),
            $nazev ? ' – ' . e($nazev) : '', $esb && $esb['zruseno'] ? " <span class='stitek konec'>zrušen " . e(fmt_datum($esb['zruseno'])) . '</span>' : '',
            $novely, e(odkaz('/novelizace', ['q' => $ident])), odkaz_sbirka("text v e-Sbírce $ident Sb."),
            e($ident), e(odkaz('/hledej', ['q' => '"' . $ident . '"'])), e($ident), e(odkaz('/hledej', ['q' => "OVA $c/$r"])));
    }

    /**
     * Materiály ODOK, které mají číslo předpisu v názvu – i vyhlášky a nařízení mimo Sněmovnu. Hledá se v ročnících
     * ODOK zvoleného období / roku (jako v ostatních seznamech; starší ročníky často nemají datum založení, takže
     * samotný filtr podle data by je propustil).
     */
    protected function materialy_s_predpisem(int $c, int $r): array
    {
        $odok = $this->odok_pro();
        $vzor = sprintf('~(?<![0-9])%d\s*/\s*%d(?![0-9])~u', $c, $r);
        $v = array_values(array_filter($odok->hledej("$c/$r"), function ($m) use ($vzor) {
            return (bool)preg_match($vzor, (string)$m->nazev);
        }));
        usort($v, function ($a, $b) {
            return strcmp((string)py_or($b->datum_vytvoreni, $b->datum_autorizace), (string)py_or($a->datum_vytvoreni, $a->datum_autorizace));
        });
        return $v;
    }

    /** Materiál ODOK k návrhu v e-Legislativě (podle čj OVA), nebo null. */
    protected static function material_k_navrhu(NavrhEsel $n): ?array
    {
        if (!preg_match('~^\s*(\d+)\s*/\s*(\d{2,4})~', (string)$n->cj_ova, $m)) {
            return null;
        }
        $vzor = sprintf('^[[:space:]]*%d[[:space:]]*/[[:space:]]*(20)?%02d([^0-9]|$)', (int)$m[1], (int)$m[2] % 100);
        try {
            return Db::radek('SELECT pid, nazev, stav FROM {p}odok_material WHERE cj_ova REGEXP ? ORDER BY rok DESC LIMIT 1', [$vzor]) ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Sekce „Vyhlášky, nařízení a další podzákonné předpisy, které předpis mění“: návrhy v e-Legislativě (vazba přes úplné
     * znění s vyznačením změn, záložně název) a vyhlášené novely podle e-Sbírky (zákony jsou v tabulce sněmovních tisků).
     */
    protected function sekce_podzakonne_novely(int $c, int $r): string
    {
        $klice = $this->oblibene->klice();
        $radky = [];
        $citace_navrhu = [];
        $druhy = ['VYHLASKA' => 'vyhláška', 'NARIZVLA' => 'nařízení vlády', 'ZAKON' => 'zákon', 'ZAKONUST' => 'ústavní zákon'];
        // materiály ODOK k návrhům: kandidáti = materiály (všechny ročníky) s číslem předpisu v názvu, spárované
        // s e-Legislativou stejně jako na detailu materiálu (čj OVA, čj předkladatele, název; výsledek je v cache)
        $mat_navrh = $mat_citace = $kandidati = [];
        if ($this->esel_ok()) {
            $esel = $this->legislativa();
            $vzor = sprintf('~(?<![0-9])%d\s*/\s*%d(?![0-9])~u', $c, $r);
            foreach ($this->odok_pro(range(prvni_rok_odok(), (int)date('Y')))->hledej("$c/$r") as $m) {
                if (!preg_match($vzor, (string)$m->nazev)) {
                    continue;
                }
                $kandidati[] = $m;
                if (!($v = $esel->k_materialu($m))) {
                    continue;
                }
                $mat_navrh[$v[0]->id] = $mat_navrh[$v[0]->id] ?? ['pid' => $m->pid, 'nazev' => $m->nazev, 'stav' => $m->stav];
                if ($v[0]->citace && preg_match('~(\d+)\s*/\s*(\d{4})~', (string)$v[0]->citace, $cm)) {
                    $mat_citace["$cm[1]/$cm[2]"] = $mat_navrh[$v[0]->id];
                }
            }
        }
        // omezení na zvolené období / rok: vyhlášené podle data vyhlášení (schválení), rozpracované podle roku
        // v identifikátoru návrhu (2026-0115 → 2026)
        $rozsah = $this->rozsah_vyberu();
        $skryto = 0;
        $ve_vyberu = function (?string $datum, ?int $rok = null) use ($rozsah): bool {
            if ($rozsah === null) {
                return true;
            }
            $d = den($datum);
            if ($d !== null) {
                return $d >= $rozsah[0] && ($rozsah[1] === null || $d <= $rozsah[1]);
            }
            return $rok === null || ($rok >= (int)substr($rozsah[0], 0, 4) && ($rozsah[1] === null || $rok <= (int)substr($rozsah[1], 0, 4)));
        };
        $bunka_odok = function (?array $mat): string {
            return $mat ? sprintf("<a href='%s'>%s</a><div class='mensi tlumene'>%s</div>", e(odkaz('/material', ['id' => $mat['pid']])),
                e($mat['pid']), e((string)($mat['stav'] ?? ''))) : '';
        };
        foreach (Novely::navrhy_menici($c, $r) as ['navrh' => $n, 'zdroj' => $zdroj]) {
            if ($n->druh === 'ZAKON' || $n->druh === 'ZAKONUST') {
                continue;       // zákony – sněmovní tisky výše
            }
            if ($n->citace && preg_match('~(\d+)\s*/\s*(\d{4})~', (string)$n->citace, $cm0)) {
                $citace_navrhu["$cm0[1]/$cm0[2]"] = true;     // i mimo výběr – nezobrazovat znovu z e-Sbírky
            }
            if (!$ve_vyberu($n->citace ? ($n->datum_vyhlaseni ?? null) : null, (int)substr((string)$n->identifikator, 0, 4) ?: null)) {
                $skryto++;
                continue;
            }
            $mat = $mat_navrh[$n->id] ?? self::material_k_navrhu($n);
            $stav = $n->citace ? "<span class='stitek vyhlaseno'>vyhlášeno</span> " . odkaz_sbirka($n->citace . (strpos((string)$n->citace, 'Sb') === false ? ' Sb.' : ''))
                : ($n->aktualni_stav ? "<span class='stitek'>" . e($n->aktualni_stav) . '</span>' : '');
            if ($n->citace && preg_match('~(\d+)\s*/\s*(\d{4})~', (string)$n->citace, $cm)) {
                $citace_navrhu["$cm[1]/$cm[2]"] = true;
            }
            $radky[] = sprintf("<tr>%s<td data-razeni='%s'>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class='tlumene'>%s</td></tr>",
                $mat ? $this->bunka_hvezdy('material', $mat['pid'], $mat['nazev'], $klice) : "<td class='sl-hvezda'></td>",
                e((string)$n->identifikator), odkazy_esel($n, (string)$n->identifikator, true),
                e((string)$n->nazev_aktu) . ($mat ? self::stitek_poznamky($this->poznamka_materialu($mat['pid'])) : ''),
                e($druhy[$n->druh ?? ''] ?? mb_strtolower((string)$n->druh)), $stav, $bunka_odok($mat),
                $zdroj === 'název' ? 'podle názvu' : 'úplné znění');
        }
        foreach (Novely::vyhlasene_novely($c, $r) as $x) {
            if (in_array($x['druh'], ['ZAKON', 'ZAKONUST'], true) || isset($citace_navrhu[$x['novela_cislo'] . '/' . $x['novela_rok']])) {
                continue;
            }
            if (!$ve_vyberu($x['schvaleno'] ?? null, (int)$x['novela_rok'])) {
                $skryto++;
                continue;
            }
            $ozn = sprintf('%d/%d Sb.', $x['novela_cislo'], $x['novela_rok']);
            $nazev = Psp::nazev_esb(['nazev' => $x['nazev'], 'nazev_citace' => $x['nazev_citace']]) ?? '';
            $mat = $mat_citace[$x['novela_cislo'] . '/' . $x['novela_rok']] ?? null;
            if ($mat === null) {
                // záložně: jediný publikovaný materiál ODOK s číslem předpisu v názvu z roku vyhlášení novely
                $shoda = array_values(array_filter($kandidati, function ($m) use ($x, $mat_citace) {
                    return $m->stav_id === STAV_PUBLIKOVANO && (int)substr((string)py_or($m->datum, $m->datum_autorizace, $m->datum_vytvoreni), 0, 4) === (int)$x['novela_rok']
                        && !in_array($m->pid, array_column($mat_citace, 'pid'), true);
                }));
                if (count($shoda) === 1) {
                    $mat = ['pid' => $shoda[0]->pid, 'nazev' => $shoda[0]->nazev, 'stav' => $shoda[0]->stav];
                }
            }
            $radky[] = sprintf("<tr>%s<td data-razeni='%04d-%05d'>%s</td><td>%s</td><td>%s</td><td><span class='stitek vyhlaseno'>vyhlášeno</span>%s%s</td><td>%s</td><td class='tlumene'>e-Sbírka</td></tr>",
                $mat ? $this->bunka_hvezdy('material', $mat['pid'], $mat['nazev'], $klice) : "<td class='sl-hvezda'></td>",
                $x['novela_rok'], $x['novela_cislo'], odkaz_sbirka($ozn), e($nazev) . ($mat ? self::stitek_poznamky($this->poznamka_materialu($mat['pid'])) : ''),
                e(py_or($x['druh_nazev'], $druhy[$x['druh'] ?? ''] ?? null, mb_strtolower((string)$x['druh']))),
                $x['schvaleno'] ? ' ' . e(fmt_datum($x['schvaleno'])) : '', $x['rusi'] ? " <span class='stitek zmena-rusi'>ruší</span>" : '', $bunka_odok($mat));
        }
        $vse = $this->cislo_obdobi() === 'vse' && !$this->vybrany_rok();
        $uvod = "<h2>Podzákonné předpisy a další akty, které předpis mění (" . count($radky) . ")</h2>
<p class='tlumene'>Návrhy z e-Legislativy (podle úplného znění s vyznačením změn, které je součástí návrhu; u návrhů bez něj podle
názvu) a vyhlášené novely podle konsolidačních vazeb e-Sbírky (ty se doplňují se zpožděním, až vznikne úplné znění).
Zobrazeno: " . e($this->popis_vyberu()) . ' (vyhlášené podle data vyhlášení, rozpracované podle roku návrhu)'
            . ($skryto ? sprintf('; mimo výběr %d', $skryto) : '')
            . ($vse ? '' : sprintf(" · <a href='%s'>zobrazit všechna období</a>", e(odkaz('/nastav', ['vyber' => 'vse', 'zpet' => odkaz('/novelizace', ['q' => "$c/$r"])]))))
            . '.</p>';
        if (!$radky) {
            return $uvod . ($skryto ? '<p>Ve zvoleném období žádné.</p>' : '<p>Žádné.</p>');
        }
        return $uvod . "<div class='posun'><table class='data raditelna' id='novely-podzak'><thead><tr><th title='Oblíbené'>⭐</th><th>Návrh / předpis</th>
<th>Název</th><th>Druh</th><th>Stav</th><th>ODOK</th><th>Vazba</th></tr></thead><tbody>" . implode('', $radky) . '</tbody></table></div>';
    }

    /** Sekce „Návrhy v ODOK, které předpis zmiňují v názvu“ (respektuje volbu období / roku). */
    protected function sekce_odok_predpis(int $c, int $r): string
    {
        $vsechny = $this->materialy_s_predpisem($c, $r);
        [$materialy, $skryto, $rozsah] = $this->filtr_vyberu($vsechny);
        $roky = $this->vybrane_roky();
        $vse = $this->cislo_obdobi() === 'vse' && !$this->vybrany_rok();
        $uvod = sprintf("<h2>Návrhy v ODOK, které předpis zmiňují v názvu (%d)</h2>
<p class='tlumene'>Materiály ODOK se „%d/%d“ v názvu – i vyhlášky a nařízení, které Sněmovnou neprocházejí, a návrhy,
které do Sněmovny ještě nedošly. Hledáno: %s (ODOK %s)%s.</p>", count($materialy), $c, $r, e($this->popis_vyberu()),
            e(count($roky) > 1 ? min($roky) . '–' . max($roky) : (string)$roky[0]),
            $vse ? '' : sprintf(" · <a href='%s'>zobrazit všechna období</a>",
                e(odkaz('/nastav', ['vyber' => 'vse', 'zpet' => odkaz('/novelizace', ['q' => "$c/$r"])]))));
        if (!$vsechny) {
            return $uvod . ($vse ? '<p>Žádný materiál ODOK nemá toto číslo předpisu v názvu.</p>' : '<p>Ve zvoleném období žádný materiál ODOK nemá toto číslo předpisu v názvu.</p>');
        }
        return $uvod . ($skryto ? $this->poznamka_filtru($skryto, $rozsah) : '')
            . ($materialy ? $this->tabulka_materialu($materialy, 'novely-odok') : '<p>Ve zvoleném období žádné.</p>');
    }

    public function stranka_novelizace(array $parametry): string
    {
        $q = trim($parametry['q'] ?? '');
        $filtr = $parametry['f'] ?? 'vse';
        $formular = sprintf('<form class="lista" method="get" action="index.php"><input type="hidden" name="p" value="novelizace">
<input type="text" name="q" value="%s" placeholder="číslo/rok nebo název či zkratka předpisu, např. 280/2009, DPH" style="max-width:440px" autofocus>
<button>Najít návrhy</button></form>', e($q));
        if (!$this->psp_ok() || !$this->snemovna()->ma_sbirku()) {
            return $this->stranka('Novelizace', '<h1>Novelizace předpisů</h1>' . $formular
                . "<p class='nacitam'>Data Sbírky zákonů ze Sněmovny (sbirka.zip) zatím nejsou v databázi – doplní se při příštím importu dat Sněmovny (cron), nebo ho spusťte v administraci.</p>", 'novelizace');
        }
        $psp = $this->snemovna();
        if ($q === '') {
            return $this->stranka('Novelizace', '<h1>Novelizace předpisů</h1>' . $formular . $this->prehled_sledovanych_predpisu(), 'novelizace');
        }
        $cislo = self::parsuj_predpis($q);
        if ($cislo === null) {
            return $this->stranka('Novelizace', '<h1>Novelizace předpisů</h1>' . $formular . $this->vysledky_hledani_predpisu($q), 'novelizace');
        }
        [$c, $r] = $cislo;
        $predpisy = $psp->predpisy($c, $r);
        $ova = sprintf("<p class='tlumene'>Hledali jste číslo jednací OVA %d/%d? <a href='%s'>Hledat jako čj</a></p>",
            $c, $r, e(odkaz('/hledej', ['q' => "OVA $c/$r"])));
        if (!$predpisy) {
            $esb = Psp::esb_predpis($c, $r);
            $text = $esb
                ? sprintf('<p><b>%s</b>%s</p><p>V datech Sněmovny (od roku 1993) k tomuto předpisu nejsou žádné sněmovní tisky, které by ho měnily. %s
Hvězdičkou ☆ u nadpisu si předpis přidáte ke sledovaným – zvýrazní se pak i jeho novely (vyhlášky a nařízení podle e-Legislativy a e-Sbírky).</p>',
                    e((string)Psp::nazev_esb($esb)), $esb['zruseno'] ? ' · zrušen ' . e(fmt_datum($esb['zruseno'])) : '',
                    odkaz_sbirka(sprintf('Text v e-Sbírce: %d/%d Sb.', $c, $r)))
                : sprintf('<p>Předpis %d/%d Sb. v datech Sbírky není.</p>', $c, $r);
            return $this->stranka('Novelizace', sprintf('<h1>%sNovelizace předpisu %d/%d Sb.</h1>%s%s%s%s',
                $esb ? $this->hvezda('predpis', "$c/$r", sprintf('%d/%d Sb. – %s', $c, $r, (string)Psp::nazev_esb($esb))) . ' ' : '', $c, $r, $formular, $text,
                $this->sekce_podzakonne_novely($c, $r) . $this->sekce_odok_predpis($c, $r), $ova), 'novelizace');
        }
        $hlavni = $predpisy[0];
        $nazev = $psp->nazev_predpisu($hlavni);
        $druh = $psp->druh_predpisu($hlavni['id_dp']);
        $ident = Psp::ident_predpisu($hlavni);
        $vsechny = $psp->zmeny_predpisu(array_column($predpisy, 'id_sbirka'));
        $zmeny = array_values(array_filter($vsechny, function ($z) { return $this->tisk_ve_vyberu($z['tisk']); }));
        $skryto = count($vsechny) - count($zmeny);
        $klice = $this->oblibene->klice();
        $projednavane = 0;
        $radky = [];
        foreach ($zmeny as $z) {
            /** @var Tisk $t */
            $t = $z['tisk'];
            $proj = !$t->projednano();
            $projednavane += $proj ? 1 : 0;
            if (($filtr === 'projednavane' && !$proj) || ($filtr === 'ukoncene' && $proj)) {
                continue;
            }
            $pid = $t->pid_eklep();
            $ds = $t->datum_stavu();
            $radky[] = '<tr>' . $this->bunka_hvezdy('tisk', $t->id_tisk(), sprintf('Tisk %s – %s', $t->cislo(), py_str($t->nazev())), $klice)
                . sprintf("<td data-razeni='%s'><a href='%s'>%s</a> <span class='tlumene'>(%s. obd.)</span></td><td title='%s'>%s</td><td>%s</td><td>%s</td>"
                    . "<td data-razeni='%s'>%s</td><td>%s<div class='mensi tlumene'>%s</div></td><td>%s</td><td>%s</td><td>%s</td></tr>",
                    e(sprintf('%02d-%06d', (int)$t->obdobi(), (int)$t->data['ct'])), e(odkaz('/tisk', ['id' => 'id:' . $t->id_tisk()])), e($t->cislo()),
                    e(py_str($t->obdobi())), e($t->uplny_nazev()), e($t->nazev()) . self::stitek_poznamky($this->poznamka_tisku($t->id_tisk())),
                    e(Psp::DRUHY_ZAKONU[$t->data['id_druh'] ?? ''] ?? $t->druh()), e($t->navrhovatel()),
                    e(iso($t->predlozeno())), e(fmt_datum($t->predlozeno())),
                    self::stitek_stavu($t), $ds ? 'od ' . e(fmt_datum($ds)) : '',
                    self::stitek_zmeny($z['typ']) . ($z['cz'] > 0 ? sprintf(" <span class='tlumene' title='Změna přibyla až v tisku %s/%d (pozměňovací návrh, Senát…)'>(od %s/%d)</span>", e($t->cislo()), $z['cz'], e($t->cislo()), $z['cz']) : ''),
                    odkaz_sbirka($t->sbirka()),
                    $pid ? sprintf("<a href='%s'>%s</a>", e(odkaz('/material', ['id' => $pid])), e($pid)) : '');
        }
        $volba = function ($hodnota, $text) use ($filtr, $ident) {
            return sprintf("<a class='tlacitko%s' href='%s'>%s</a>", $filtr === $hodnota ? ' aktivni' : '',
                e(odkaz('/novelizace', ['q' => $ident, 'f' => $hodnota])), $text);
        };
        $puvod = '';
        if (py_bool($hlavni['id_tisk'])) {
            $tr = $psp->jeden('tisky', 'id_tisk', $hlavni['id_tisk']);
            if ($tr) {
                $tp = new Tisk($tr, $psp);
                $puvod = sprintf(' · vzešel z tisku <a href="%s">%s</a> (%s. obd.)', e(odkaz('/tisk', ['id' => 'id:' . $tp->id_tisk()])), e($tp->cislo()), e(py_str($tp->obdobi())));
            }
        }
        $obsah = sprintf('<h1>%s Novelizace předpisu %s <span class="tlumene">%s</span></h1>
<p class="tlumene">%s%s · vyhlášen %s · %s%s</p>%s
%s<div class="lista filtry">%s%s%s</div>
<div class="lista"><input type="text" data-filtr="novely" placeholder="Filtrovat v tabulce…">
<span class="tlumene"><span id="novely-pocet">%d</span> návrhů</span></div>%s
<p class="tlumene">Podle dat Sbírky zákonů ze Sněmovny (vazba tisk → měněné a rušené předpisy). „Od X/N“ znamená,
že změna předpisu přibyla až v průběhu projednávání (pozměňovací návrhy, Senát). Senátní návrhy zákonů nejsou zahrnuty.</p>%s',
            $this->hvezda('predpis', $ident, sprintf('%s%s', Psp::oznaceni_predpisu($hlavni), $nazev ? ' – ' . $nazev : '')),
            e(Psp::oznaceni_predpisu($hlavni)), e($nazev ?? '') . (($esb_h = Psp::esb_predpis($c, $r)) && Psp::zkratky_esb($esb_h)
                ? ' <span class="mensi">(' . e(implode(', ', Psp::zkratky_esb($esb_h))) . ')</span>' : '')
                . ($esb_h && $esb_h['zruseno'] ? ' <span class="stitek konec">zrušen ' . e(fmt_datum($esb_h['zruseno'])) . '</span>' : ''),
            e($druh ? mb_strtoupper(mb_substr($druh, 0, 1)) . mb_substr($druh, 1) : 'Předpis'), $puvod,
            e(fmt_datum($hlavni['datum'])), odkaz_sbirka('text v e-Sbírce ' . Psp::oznaceni_predpisu($hlavni)),
            count($predpisy) > 1 ? sprintf(' · pod tímto číslem je ve Sbírce %d položek, výpis zahrnuje všechny', count($predpisy)) : '',
            $formular, $this->poznamka_vyberu_novel($skryto, odkaz('/novelizace', ['q' => $ident, 'f' => $filtr])),
            $volba('vse', sprintf('Všechny (%d)', count($zmeny))), $volba('projednavane', sprintf('Projednávané (%d)', $projednavane)),
            $volba('ukoncene', sprintf('Ukončené (%d)', count($zmeny) - $projednavane)), count($radky),
            $radky ? '<div class="posun"><table class="data raditelna" id="novely"><thead><tr><th title="Oblíbené">⭐</th><th>Tisk</th><th>Název</th><th>Druh</th>
<th>Navrhovatel</th><th>Předloženo</th><th>Stav</th><th>Změna</th><th>Sbírka</th><th>eKLEP</th></tr></thead><tbody>' . implode('', $radky) . '</tbody></table></div>'
                : ($skryto ? '<p>Ve zvoleném období žádné návrhy.</p>' : '<p>Žádné návrhy.</p>'),
            $this->sekce_podzakonne_novely($c, $r) . $this->sekce_odok_predpis($c, $r) . (($parametry['z'] ?? '') === 'hledani' ? $ova : ''));
        return $this->stranka('Novelizace ' . Psp::oznaceni_predpisu($hlavni), $obsah, 'novelizace');
    }

    /** Úvod stránky Novelizace: sledované předpisy (⭐) a jejich právě projednávané novely. */
    protected function prehled_sledovanych_predpisu(): string
    {
        $vysvetleni = "<p class='tlumene'>Zadejte číslo předpisu (např. <b>280/2009</b>), jeho název nebo zkratku (<b>daňový řád</b>, <b>DPH</b>, <b>NOZ</b>)
– ukážou se všechny sněmovní tisky, které ho mění nebo ruší, s aktuálním stavem. Číslo předpisu funguje i ve vyhledávání nahoře.</p>";
        if (!$this->oblibene->aktivni()) {
            return $vysvetleni . "<p class='tlumene'>Po přihlášení si předpisy můžete označit hvězdičkou ☆ a tady pak uvidíte přehled jejich projednávaných novel.</p>";
        }
        $sledovane = $this->oblibene->seznam('predpis');
        if (!$sledovane) {
            return $vysvetleni . "<p class='tlumene'>Hvězdičkou ☆ u předpisu si ho přidáte ke sledovaným – tady pak uvidíte, které jeho novely se právě projednávají.</p>";
        }
        $psp = $this->snemovna();
        $klice = $this->oblibene->klice();
        $radky = [];
        foreach ($sledovane as $s) {
            $cislo = self::parsuj_predpis((string)$s['id']);
            $predpisy = $cislo ? $psp->predpisy($cislo[0], $cislo[1]) : [];
            $esb = $cislo ? Psp::esb_predpis($cislo[0], $cislo[1]) : null;
            if (!$predpisy && !$esb) {
                $radky[] = sprintf('<tr>%s<td>%s</td><td colspan="3" class="tlumene">v datech Sbírky nenalezen</td></tr>',
                    $this->bunka_hvezdy('predpis', $s['id'], $s['nazev'], $klice), e($s['id']));
                continue;
            }
            $zmeny = $predpisy ? $psp->zmeny_predpisu(array_column($predpisy, 'id_sbirka')) : [];
            $bezi = array_values(array_filter($zmeny, function ($z) { return !$z['tisk']->projednano() && $this->tisk_ve_vyberu($z['tisk']); }));
            $seznam = implode('', array_map(function ($z) {
                $t = $z['tisk'];
                return sprintf("<li><a href='%s'>%s</a> %s %s</li>", e(odkaz('/tisk', ['id' => 'id:' . $t->id_tisk()])), e($t->cislo()),
                    self::stitek_stavu($t), e(mb_strimwidth((string)$t->nazev(), 0, 110, '…')));
            }, $bezi));
            // rozpracované podzákonné předpisy (e-Legislativa, ještě nevyhlášené)
            foreach (Novely::navrhy_menici($cislo[0], $cislo[1]) as ['navrh' => $n]) {
                if ($n->citace || in_array($n->druh, ['ZAKON', 'ZAKONUST'], true)) {
                    continue;
                }
                $bezi[] = $n;
                $seznam .= sprintf("<li>%s %s %s</li>", odkazy_esel($n, (string)$n->identifikator, true),
                    $n->aktualni_stav ? "<span class='stitek'>" . e($n->aktualni_stav) . '</span>' : '',
                    e(mb_strimwidth((string)$n->nazev_aktu, 0, 110, '…')));
            }
            $posledni = null;
            foreach ($zmeny as $z) {
                $sb = $z['tisk']->sbirka();
                if ($sb && ($posledni === null || strcmp(iso($z['tisk']->datum_stavu()), iso($posledni->datum_stavu())) > 0)) {
                    $posledni = $z['tisk'];
                }
            }
            $nazev = $predpisy ? $psp->nazev_predpisu($predpisy[0]) : Psp::nazev_esb($esb);
            if (!$posledni && ($vn = Novely::vyhlasene_novely($cislo[0], $cislo[1]))) {
                $posledni_esb = sprintf('%d/%d Sb.', $vn[0]['novela_cislo'], $vn[0]['novela_rok']);
            }
            $radky[] = sprintf("<tr>%s<td data-razeni='%04d-%05d'>%s</td><td>%s</td><td data-razeni='%d'>%s</td><td>%s</td></tr>",
                $this->bunka_hvezdy('predpis', $s['id'], $s['nazev'], $klice), $cislo[1], $cislo[0],
                $predpisy ? self::odkaz_predpis($predpisy[0], $nazev) : sprintf("<a href='%s'>%s Sb.</a>", e(odkaz('/novelizace', ['q' => $s['id']])), e($s['id'])), e($nazev ?? '') . self::stitek_poznamky($this->poznamka_predpisu($s['id'])), count($bezi),
                $bezi ? "<ul class='predpisy'>$seznam</ul>" : "<span class='tlumene'>žádná</span>",
                $posledni ? odkaz_sbirka($posledni->sbirka()) . sprintf(" <span class='tlumene'>(tisk %s)</span>", e($posledni->cislo()))
                    : (isset($posledni_esb) ? odkaz_sbirka($posledni_esb) . " <span class='tlumene'>(e-Sbírka)</span>" : ''));
            unset($posledni_esb);
        }
        return sprintf('<h2>Sledované předpisy (%d)</h2>%s
<div class="posun"><table class="data raditelna" id="predpisy"><thead><tr><th title="Sledované">⭐</th><th>Předpis</th><th>Název</th>
<th>Projednávané novely</th><th>Poslední vyhlášená novela</th></tr></thead><tbody>%s</tbody></table></div>%s',
            count($sledovane), $this->cislo_obdobi() === 'vse' && !$this->vybrany_rok() ? ''
                : sprintf("<p class='tlumene'>Projednávané novely %s; poslední vyhlášená novela bez ohledu na období.</p>",
                    e($this->vybrany_rok() ? sprintf('předložené v roce %d', $this->vybrany_rok()) : sprintf('z %s. volebního období', $this->cislo_obdobi()))),
            implode('', $radky), $vysvetleni);
    }

    // ---------------- Oblíbené ⭐ ----------------

    public function akce_oblibene_prepni(array $par): array
    {
        $typ = $par['typ'] ?? '';
        $obl = $this->oblibene->prepni($typ, $par['id'] ?? '', $par['nazev'] ?? '', $typ === 'material' ? $this->vybrane_roky() : null);
        return ['oblibeny' => $obl, 'pocet' => $this->oblibene->pocet()];
    }

    public function akce_oblibene_poznamka(array $par): array
    {
        $this->oblibene->nastav($par['klic'] ?? '', ['poznamka' => mb_substr($par['text'] ?? '', 0, 2000)]);
        return ['ok' => true];
    }

    public function akce_oblibene_videno(array $par): array
    {
        $klic = ($par['klic'] ?? '') !== '' ? $par['klic'] : null;
        foreach ($this->oblibene->seznam() as $p) {
            if (!in_array($p['typ'], ['material', 'tisk'], true)) {
                continue;
            }
            if ($klic === null || $p['klic'] === $klic) {
                $r = $p['typ'] === 'material' ? $this->oblibeny_material($p) : $this->oblibeny_tisk($p);
                if ($r['otisk'] !== null) {
                    $this->oblibene->nastav($p['klic'], ['videno' => $r['otisk']]);
                }
            }
        }
        return ['ok' => true];
    }

    protected function oblibeny_material(array $polozka): array
    {
        $pid = $polozka['id'];
        $r = ['nazev' => py_or($polozka['nazev'], $pid), 'resort' => '', 'typ' => '', 'stav_odok' => '', 'tisk' => '', 'stav_psp' => '',
            'esel' => '', 'sbirka' => '', 'otisk' => null, 'm' => null, 'esel_navrh' => null];
        try {
            $m = $this->najdi_material($pid, $polozka['rok']);
        } catch (OdokChyba $e) {
            $m = null;
        }
        $casti = [];
        if ($m !== null && $m->nazev) {
            $r['m'] = $m;
            $r['nazev'] = $m->nazev;
            $r['resort'] = $m->predkladatel_zkratka ?? '';
            $r['typ'] = $m->typ ?? '';
            $r['stav_odok'] = $m->stav ?? '';
            $casti[] = 'ODOK: ' . ($m->stav ?: '?');
        }
        $psp_ok = $this->psp_ok();
        if ($psp_ok) {
            $id = $this->snemovna()->pid_tisk([$pid])[strtoupper($pid)] ?? null;
            if ($id !== null) {
                $psp = $this->snemovna();
                $t = new Tisk($psp->jeden('tisky', 'id_tisk', $id), $psp);
                $r['tisk'] = $this->odkaz_tisk_kratky($t->id_tisk());
                $r['stav_psp'] = self::stitek_stavu($t);
                $r['sbirka'] = $t->sbirka() ?? '';
                $casti[] = 'PS: ' . py_str($t->stav());
            }
        }
        if ($this->esel_ok() && $m !== null && $m->nazev && in_array($m->typ_id, ['NV', 'NNV'], true)) {
            $nalez = $this->legislativa()->k_materialu($m);
            if ($nalez) {
                $n = $nalez[0];
                $r['esel'] = $n->aktualni_stav ?? '';
                $r['esel_navrh'] = $n;
                $r['sbirka'] = py_or($r['sbirka'], $n->citace, '');
                $casti[] = 'eSeL: ' . ($n->aktualni_stav ?: '?');
            }
        }
        if ($r['sbirka']) {
            $casti[] = 'Sb.: ' . $r['sbirka'];
        }
        // otisk jen když je materiál nalezen (stejně jako v Pythonu, kde se čekalo na dokončení načítání zdrojů)
        $vse = $m !== null && $m->nazev;
        $r['otisk'] = $vse ? implode(' · ', $casti) : null;
        return $r;
    }

    protected function oblibeny_tisk(array $polozka): array
    {
        $r = ['nazev' => py_or($polozka['nazev'], 'tisk'), 'odkaz' => '', 'stav' => '', 'od' => null, 'etapa' => '', 'sbirka' => '', 'pid' => '', 'otisk' => null];
        if (!$this->psp_ok()) {
            return $r;
        }
        $psp = $this->snemovna();
        $ident = $polozka['id'];
        $radek = $psp->jeden('tisky', 'id_tisk', ctype_digit($ident) ? (int)$ident : $ident);
        if (!$radek) {
            return $r;
        }
        $t = new Tisk($radek, $psp);
        $etapy = $t->etapy();
        $probiha = '';
        foreach (Psp::NAZVY_ETAP as $n) {
            if (!empty($etapy[$n]['probiha'])) {
                $probiha = $n;
                break;
            }
        }
        if ($probiha === 'Senát') {
            $probiha .= ' – ' . self::text_lhuty_senatu($etapy['Senát']['lhuta'] ?? null, true);
        }
        $akt = $psp->obdobi() ? $psp->obdobi()->cislo : null;
        $r = array_merge($r, ['nazev' => $t->nazev(), 'stav' => self::stitek_stavu($t), 'od' => $t->datum_stavu(), 'etapa' => $probiha,
            'sbirka' => $t->sbirka() ?? '', 'pid' => $t->pid_eklep() ?? '',
            'odkaz' => sprintf("<a href='%s'>%s</a>%s", e(odkaz('/tisk', ['id' => 'id:' . $t->id_tisk()])), e($t->cislo()),
                $t->obdobi() === $akt ? '' : " <span class='tlumene'>(" . e(py_str($t->obdobi())) . '. obd.)</span>')]);
        $r['otisk'] = implode(' · ', array_filter(['PS: ' . py_str($t->stav()), $t->sbirka() ? 'Sb.: ' . $t->sbirka() : '']));
        return $r;
    }

    protected function bunka_zmeny(array $polozka, ?string $otisk): string
    {
        if ($otisk === null) {
            return "<td class='tlumene' data-razeni='0'>…</td>";
        }
        if ($polozka['videno'] === null) {
            $this->oblibene->nastav($polozka['klic'], ['videno' => $otisk]);
            return "<td data-razeni='0'></td>";
        }
        if ($polozka['videno'] === $otisk) {
            return "<td data-razeni='0'></td>";
        }
        return sprintf("<td data-razeni='1'><span class='zmena' title='Dříve: %s&#10;Nyní: %s'>🔔 změna</span> <button type='button' class='tlacitko-mini' data-videno='%s' title='Beru na vědomí'>✔</button></td>",
            e($polozka['videno']), e($otisk), e($polozka['klic']));
    }

    public function stranka_oblibene(array $parametry): string
    {
        if (!$this->oblibene->aktivni()) {
            $this->presmeruj(odkaz('/prihlaseni', ['zpet' => odkaz('/oblibene')]));
        }
        $materialy = $this->oblibene->seznam('material');
        $tisky = $this->oblibene->seznam('tisk');
        $predpisy_obl = $this->oblibene->seznam('predpis');
        $klice = $this->oblibene->klice();
        $zmen = 0;
        $poznamka = function ($p) {
            return sprintf("<td><input class='poznamka' data-klic='%s' value='%s' placeholder='poznámka…'></td>", e($p['klic']), e($p['poznamka'] ?? ''));
        };
        $pridano = function ($p) {
            return sprintf("<td data-razeni='%s'>%s</td>", e(str_replace(' ', 'T', (string)$p['pridano'])), e($p['pridano'] ? fmt_datum(substr($p['pridano'], 0, 10)) : ''));
        };
        if ($this->psp_ok()) {
            $this->snemovna()->prednacti('tisky', 'id_tisk', array_map(function ($p) { return ctype_digit($p['id']) ? (int)$p['id'] : $p['id']; }, $tisky));
        }
        $radky_m = [];
        foreach ($materialy as $p) {
            $r = $this->oblibeny_material($p);
            $bz = $this->bunka_zmeny($p, $r['otisk']);
            $zmen += strpos($bz, '🔔') !== false ? 1 : 0;
            $radky_m[] = sprintf("<tr>%s<td><a href='%s'>%s</a><div class='tlumene'>%s</div></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>%s%s%s</tr>",
                $this->bunka_hvezdy('material', $p['id'], $r['nazev'], $klice),
                e(odkaz('/material', ['id' => $p['id'], 'rok' => is_array($p['rok']) ? '' : ($p['rok'] ?? '')])), e($r['nazev']), e($p['id']),
                e($r['resort']), e($r['typ']), e($r['stav_odok']), $r['tisk'], $r['stav_psp'],
                odkazy_esel($r['esel_navrh'], $r['esel'], true), odkaz_sbirka($r['sbirka']), $bz, $poznamka($p), $pridano($p));
        }
        $radky_t = [];
        foreach ($tisky as $p) {
            $r = $this->oblibeny_tisk($p);
            $bz = $this->bunka_zmeny($p, $r['otisk']);
            $zmen += strpos($bz, '🔔') !== false ? 1 : 0;
            $radky_t[] = sprintf("<tr>%s<td>%s</td><td>%s</td><td>%s</td><td data-razeni='%s'>%s</td><td>%s</td><td>%s</td><td>%s</td>%s%s%s</tr>",
                $this->bunka_hvezdy('tisk', $p['id'], $r['nazev'], $klice), $r['odkaz'] ?: e($p['id']), e($r['nazev']), $r['stav'],
                e(iso($r['od'])), e(fmt_datum($r['od'])), e($r['etapa']), odkaz_sbirka($r['sbirka']),
                $r['pid'] ? sprintf("<a href='%s'>%s</a>", e(odkaz('/material', ['id' => $r['pid']])), e($r['pid'])) : '', $bz, $poznamka($p), $pridano($p));
        }
        $cast_p = $predpisy_obl ? sprintf("<h2>Sledované předpisy (%d)</h2><p>%s – přehled jejich projednávaných novel je na stránce <a href='%s'>Novelizace</a>.</p>",
            count($predpisy_obl), implode(', ', array_map(function ($p) {
                return sprintf("<a href='%s' title='%s'>%s Sb.</a>", e(odkaz('/novelizace', ['q' => $p['id']])), e($p['nazev'] ?? ''), e($p['id']));
            }, $predpisy_obl)), e(odkaz('/novelizace'))) : '';
        if (!$materialy && !$tisky && !$predpisy_obl) {
            $obsah = '<h1>⭐ Moje oblíbené</h1>
<div class="karta"><p>Zatím tu nic není. Hvězdičkou ☆ u materiálu (v seznamech, v detailu nebo
v podkladech PLPV) nebo u sněmovního tisku si návrh přidáte do oblíbených – a tady pak uvidíte,
kde se právě nachází: stav v ODOK, ve Sněmovně i v e-Legislativě, a jestli se od minula něco změnilo 🔔.</p></div>';
            return $this->stranka('Oblíbené', $obsah, 'oblibene');
        }
        $cast_m = $materialy ? sprintf('<h2>Materiály ODOK (%d)</h2>
<div class="lista"><input type="text" data-filtr="obl-materialy" placeholder="Filtrovat…">
<span class="tlumene"><span id="obl-materialy-pocet">%d</span> materiálů</span></div>
<div class="posun ukotvit" data-sloupcu="2"><table class="data raditelna" id="obl-materialy"><thead><tr>
<th>⭐</th><th>Název</th><th>Předkl.</th><th>Typ</th><th>Stav ODOK</th><th>Tisk PS</th><th>Stav PS</th>
<th>e-Legislativa</th><th>Sbírka</th><th>Změna</th><th>Poznámka</th><th>Přidáno</th>
</tr></thead><tbody>%s</tbody></table></div>', count($materialy), count($materialy), implode('', $radky_m)) : '';
        $cast_t = $tisky ? sprintf('<h2>Sněmovní tisky (%d)</h2>
<div class="posun"><table class="data raditelna" id="obl-tisky"><thead><tr>
<th>⭐</th><th>Tisk</th><th>Název</th><th>Stav</th><th>Od</th><th>Probíhá</th><th>Sbírka</th><th>eKLEP</th>
<th>Změna</th><th>Poznámka</th><th>Přidáno</th></tr></thead><tbody>%s</tbody></table></div>', count($tisky), implode('', $radky_t)) : '';
        $chybi = [];
        if (!$this->psp_ok()) {
            $chybi[] = 'Sněmovny';
        }
        if (!$this->esel_ok()) {
            $chybi[] = 'e-Legislativy';
        }
        $obsah = sprintf('<h1>⭐ Moje oblíbené</h1>
<p class="tlumene">Aktuální stav sledovaných návrhů napříč ODOK, Sněmovnou a e-Legislativou.
🔔 označuje změnu stavu od posledního potvrzení (po najetí myší je vidět, co se změnilo).
Oblíbené jsou uložené u vašeho účtu.</p>
%s%s%s%s', $chybi ? "<p class='nacitam'>Data " . implode(' a ', $chybi) . ' zatím nejsou v databázi – některé sloupce se doplní po jejich importu.</p>' : '',
            $zmen ? sprintf("<div class='lista'><button type='button' data-videno=''>✔ Vzít na vědomí všechny změny (%d)</button></div>", $zmen) : '',
            $cast_m, $cast_t . $cast_p);
        return $this->stranka('Oblíbené', $obsah, 'oblibene');
    }

    // ---------------- Podklady PLPV ----------------

    public function stranka_plpv(array $parametry): string
    {
        [$resort, $obdobi, $rok, , , $popis] = $this->plpv_parametry($parametry);
        $vybrany = py_or($parametry['l'] ?? '', 'zakony');
        $resorty = [];
        $aktualni = $this->seznam_obdobi()[0];
        foreach (array_keys($this->odok_pro(roky_obdobi($aktualni[1], $aktualni[2]))->predkladatele()) as $k) {
            $z = explode("\x00", $k)[0];
            if ($z !== '' && $z !== '?') {
                $resorty[$z] = true;
            }
        }
        $resorty = array_keys($resorty);
        if (!in_array($resort, $resorty, true)) {
            $resorty[] = $resort;
        }
        sort($resorty, SORT_STRING);
        [$volby_obdobi, $volby_roku] = $this->plpv_volby($obdobi, $rok);
        $moznosti = implode('', array_map(function ($z) use ($resort) {
            return sprintf('<option%s>%s</option>', $z === $resort ? ' selected' : '', e($z));
        }, $resorty));
        $zalozky = '';
        foreach (ListPLPV::listy() as [$klic, $nazev]) {
            $zalozky .= sprintf("<a class='tlacitko%s' href='%s'>%s</a>", $klic === $vybrany ? ' aktivni' : '',
                e(odkaz('/plpv', ['r' => $resort, 'o' => $obdobi ?? '', 'rok' => $rok ?? '', 'l' => $klic])), e($nazev));
        }
        $legenda = implode(' ', array_map(function ($k, $v) { return sprintf("<span class='zdroj-%s'>%s</span>", $k, e($v)); },
            array_keys(ListPLPV::POPIS_ZDROJE), ListPLPV::POPIS_ZDROJE));
        $par_cast = ['typ' => 'plpv', 'r' => $resort, 'o' => $obdobi ?? '', 'rok' => $rok ?? '', 'l' => $vybrany];
        if (!empty($parametry['obnovit'])) {
            $par_cast['obnovit'] = '1';
        }
        $obsah = sprintf('<h1>Podklady PLPV <span class="tlumene">%s · %s</span></h1>
<form class="lista" action="index.php"><input type="hidden" name="p" value="plpv">
<label>Resort <select name="r">%s</select></label>
%s
<label>Rok <select name="rok">%s</select></label>
<input type="hidden" name="l" value="%s"><button>Zobrazit</button>
<a class="tlacitko" href="%s" title="Znovu sestavit (ignoruje uložené podklady)">Přepočítat</a>
</form>
<div class="lista filtry">%s</div>
<p class="tlumene legenda">Zdroj sloupce: %s</p>
<details open data-nacti="%s"><summary>Tabulka</summary><div class="obsah">
<p class="nacitam">Připravuji podklady… První sestavení může trvat i minutu – stahují se
historie verzí materiálů z ODOK (pak se 2 hodiny berou z cache).</p></div></details>', e($resort), e($popis), $moznosti, $volby_obdobi,
            $volby_roku, e($vybrany), e(odkaz('/plpv', ['r' => $resort, 'o' => $obdobi ?? '', 'rok' => $rok ?? '', 'l' => $vybrany, 'obnovit' => '1'])),
            $zalozky, $legenda, e(odkaz('/cast', $par_cast)));
        return $this->stranka('Podklady PLPV', $obsah, 'plpv');
    }

    /** [resort, číslo období|null, rok|null, od, do|null, popis] */
    protected function plpv_parametry(array $parametry): array
    {
        $resort = strtoupper(trim(py_or($parametry['r'] ?? '', 'MF')));
        $o = $parametry['o'] ?? '';
        $rok = $parametry['rok'] ?? '';
        if (!array_key_exists('o', $parametry) && !array_key_exists('rok', $parametry) && $this->vybrany_rok()) {
            $rok = (string)$this->vybrany_rok();
        }
        $rok = ctype_digit($rok) ? (int)$rok : null;
        $obdobi = null;
        if ($this->psp_ok()) {
            try {
                $p = ctype_digit($o) ? $this->psp_pro((int)$o) : $this->psp_pro();
                $obdobi = ($p->obdobi() && $p->obdobi()->od) ? $p->obdobi() : null;
            } catch (OdokChyba $e) {
                $obdobi = null;
            }
        }
        if ($rok) {
            [$od, $do] = ["$rok-01-01", "$rok-12-31"];
            $popis = $obdobi ? sprintf('%s · rok %d', $obdobi, $rok) : "rok $rok";
        } elseif ($obdobi !== null) {
            $od = den($obdobi->od);
            $do = $obdobi->do ? den($obdobi->do) : null;
            $popis = (string)$obdobi;
        } else {
            $rok = (int)date('Y');
            [$od, $do, $popis] = ["$rok-01-01", "$rok-12-31", "rok $rok"];
        }
        return [$resort, $obdobi ? $obdobi->cislo : null, $rok, $od, $do, $popis];
    }

    protected function plpv_volby($obdobi, $rok): array
    {
        $volby_obdobi = '';
        $roky = [(int)date('Y')];
        if ($this->psp_ok()) {
            $seznam = array_filter($this->snemovna()->obdobi_seznam(), function ($o) { return $o->cislo && $o->cislo >= 5 && $o->od; });
            $volby_obdobi = "<label>Volební období <select name='o'>" . implode('', array_map(function ($o) use ($obdobi) {
                return sprintf("<option value='%s'%s>%s</option>", $o->cislo, $o->cislo === $obdobi ? ' selected' : '', e((string)$o));
            }, $seznam)) . '</select></label>';
            foreach ($seznam as $o) {
                if ($o->cislo === $obdobi) {
                    $roky = range($o->rok_od(), $o->do ? $o->rok_do() : (int)date('Y'));
                }
            }
        }
        $volby_roku = "<option value=''>celé období</option>" . implode('', array_map(function ($r) use ($rok) {
            return sprintf('<option%s>%d</option>', $r === $rok ? ' selected' : '', $r);
        }, $roky));
        return [$volby_obdobi, $volby_roku];
    }

    public function cast_plpv(array $parametry): string
    {
        @set_time_limit(300);
        $obnovit = !empty($parametry['obnovit']);
        [$resort, $obdobi, , $od, $do] = $this->plpv_parametry($parametry);
        $klic_listu = py_or($parametry['l'] ?? '', 'zakony');
        $snemovna = null;
        if ($this->psp_ok()) {
            try {
                $snemovna = $obdobi ? $this->psp_pro($obdobi) : $this->psp_pro();
            } catch (OdokChyba $e) {
                $snemovna = null;
            }
        }
        $legislativa = $this->esel_ok() ? $this->legislativa() : null;
        if ($obnovit) {
            $urls = [];
            foreach ((new Odok(range((int)substr($od, 0, 4) - 1, (int)substr($do ?? dnes(), 0, 4))))->materialy_ministerstva($resort) as $m) {
                if ($m->pid) {
                    $urls[] = sha1(str_replace('{pid}', $m->pid, URL_VERZE));
                }
            }
            // jen označit jako zastaralé (ne smazat) – když ODOK zrovna neodpovídá, zůstane starší kopie
            foreach (array_chunk($urls, 500) as $kus) {
                Db::q("UPDATE {p}http_cache SET stazeno = '2000-01-01 00:00:00' WHERE url_hash IN (" . Db::in($kus) . ')', $kus);
            }
        }
        $podklady = new Podklady($resort, $od, $do, $snemovna, $legislativa);
        $listy = $podklady->listy();
        $chyby = $podklady->chyby;
        $lst = $listy[0];
        foreach ($listy as $x) {
            if ($x->klic === $klic_listu) {
                $lst = $x;
            }
        }
        $pocty = implode(' · ', array_map(function ($x) { return sprintf('%s: %d', $x->nazev, count($x->radky)); }, $listy));
        $klice = $this->oblibene->klice();
        $hlavicka = function ($s, $doplnek) {
            $pismeno = count($s) > 3 ? "<span class='pismeno'>" . e($s[3]) . '</span>' : '';
            return sprintf("<th class='zdroj-%s%s' title='%s'>%s%s</th>", $s[2], $doplnek ? ' doplnek' : '', e(ListPLPV::POPIS_ZDROJE[$s[2]]),
                $pismeno, str_replace("\n", '<br>', e($s[0])));
        };
        $bunka = function ($r, $s) use ($klice) {
            [, $klic, $zdroj] = $s;
            $h = $r[$klic] ?? null;
            $text = e(ListPLPV::fmt($h));
            if ($klic === 'nazev' && py_bool($r['pid'] ?? null)) {
                $text = $this->hvezda('material', $r['pid'], $h, $klice) . sprintf("<a href='%s'>%s</a>", e(odkaz('/material', ['id' => $r['pid']])), $text);
            } elseif ($klic === 'tisk' && ($r['_tisk'] ?? null) !== null) {
                $text = sprintf("<a href='%s'>%s</a>", e(odkaz('/tisk', ['id' => 'id:' . $r['_tisk']->id_tisk()])), $text);
            } elseif ($klic === 'pid' && py_bool($h)) {
                $text = sprintf("<a href='%s' target='_blank' rel='noopener'>%s ↗</a>", e(vzor(URL_DETAIL_WEB, ['pid' => $h])), $text);
            } elseif ($klic === 'sbirka' && py_bool($h)) {
                $text = odkaz_sbirka($h);
            } elseif ($klic === 'esel' && py_bool($h) && ($r['_esel'] ?? null) !== null) {
                $text = odkazy_esel($r['_esel'], ListPLPV::fmt($h), true);
            } elseif ($h === 'probíhá') {
                $text = "<span class='stitek'>probíhá</span>";
            }
            $razeni = is_string($h) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $h) ? iso($h) : '';
            return sprintf("<td class='zdroj-%s'%s>%s</td>", $zdroj, $razeni !== '' ? " data-razeni='" . e($razeni) . "'" : '', $text);
        };
        $sloupce = array_merge($lst->sloupce, $lst->doplnky);
        $telo = '';
        foreach ($lst->radky as $r) {
            $telo .= '<tr>' . implode('', array_map(function ($s) use ($r, $bunka) { return $bunka($r, $s); }, $sloupce)) . '</tr>';
        }
        $tabulka = $lst->radky ? sprintf('<div class="posun ukotvit" data-sloupcu="1"><table class="data raditelna plpv" id="plpv"><thead>
<tr><th colspan="%d" class="skupina">Sloupce excelu</th><th colspan="%d" class="skupina doplnek">Doplňkové údaje (pro kontrolu)</th></tr>
<tr>%s</tr></thead><tbody>%s</tbody></table></div>', count($lst->sloupce), count($lst->doplnky),
            implode('', array_map(function ($s) use ($hlavicka) { return $hlavicka($s, false); }, $lst->sloupce))
            . implode('', array_map(function ($s) use ($hlavicka) { return $hlavicka($s, true); }, $lst->doplnky)), $telo)
            : "<p class='tlumene'>Žádné materiály pro tento list.</p>";
        $upozorneni = [];
        if ($snemovna === null) {
            $upozorneni[] = 'Data Sněmovny nejsou k dispozici – sloupce ze Sněmovny jsou prázdné.';
        }
        if ($legislativa === null) {
            $upozorneni[] = 'Data e-Legislativy nejsou k dispozici – u vyhlášek a nařízení chybí podpis, číslo ve Sbírce a účinnost.';
        }
        $chyby_html = '';
        if ($chyby) {
            $chyby_html = $this->vypis_chyb_verzi($chyby);
        }
        $pismena = implode(', ', array_column($lst->sloupce, 3));
        return sprintf('<p class="tlumene">%s</p>%s
<div class="lista">
<button type="button" data-kopiruj="tsv-excel">Kopírovat řádky (sloupce %s)</button>
<button type="button" data-kopiruj="tsv-vse">Kopírovat vč. záhlaví a doplňkových sloupců</button>
<span class="tlumene" data-kopiruj-stav></span></div>
<textarea id="tsv-excel" hidden>%s</textarea><textarea id="tsv-vse" hidden>%s</textarea>
%s
<p class="tlumene">Písmeno nad sloupcem je sloupec v excelu. Zobrazují se jen sloupce, pro které jsou
data (sloupce A, B a D–F – položka PLPV, sekce, implementace EU, plán a VPŘ – v datech nejsou).
Zkopírované řádky obsahují sloupce %s v tomto pořadí, bez záhlaví; data jsou ve tvaru d.m.rrrr.
Přiřazení k řádkům excelu je potřeba zkontrolovat – názvy v ODOK se mohou mírně lišit.</p>', e($pocty),
            implode('', array_map(function ($u) { return "<div class='chyba'>" . e($u) . '</div>'; }, $upozorneni)) . $chyby_html, $pismena,
            e($lst->jako_tsv(false, false)), e($lst->jako_tsv(true)), $tabulka, $pismena);
    }

    /** Krátké upozornění na nestažené historie verzí; podrobnosti sbalené. */
    private function vypis_chyb_verzi(array $chyby): string
    {
        $polozky = [];
        $ostatni = [];
        $duvody = [];
        foreach ($chyby as $c) {
            if (!preg_match('~^(\S+): Nelze stáhnout \S+: (.*)$~s', (string)$c, $m)) {
                $ostatni[] = (string)$c;
                continue;
            }
            $duvod = $m[2];
            if (stripos($duvod, 'reset') !== false || stripos($duvod, 'Send failure') !== false || stripos($duvod, 'Recv failure') !== false) {
                $duvod = 'server ODOK ukončil spojení';
            } elseif (stripos($duvod, 'timed out') !== false || stripos($duvod, 'timeout') !== false) {
                $duvod = 'server ODOK neodpověděl včas';
            } elseif (preg_match('~^HTTP (429|5\d\d)~', $duvod)) {
                $duvod = 'server ODOK je přetížený (' . $duvod . ')';
            } elseif (preg_match('~^HTTP 404~', $duvod)) {
                $duvod = 'historie verzí není zveřejněná (HTTP 404)';
            }
            $duvody[$duvod] = ($duvody[$duvod] ?? 0) + 1;
            $polozky[] = sprintf("<li><a href='%s'>%s</a> – %s</li>", e(odkaz('material', ['id' => $m[1]])), e($m[1]), e($duvod));
        }
        $h = '';
        if ($polozky) {
            arsort($duvody);
            $prechodne = array_filter(array_keys($duvody), function ($d) { return strpos($d, 'server ODOK') === 0 || strpos($d, 'nestihlo') === 0; });
            $h .= sprintf("<div class='chyba upozorneni'><details><summary>U %d materiálů se nepodařilo stáhnout historii verzí – %s. "
                . "Sloupce MPŘ / předložení / LRV u nich mohou chybět.%s</summary><ul>%s</ul></details></div>",
                count($polozky), e(implode(', ', array_keys($duvody))),
                $prechodne ? ' Zkuste stránku za chvíli načíst znovu – co se jednou stáhne, zůstává uložené.' : '',
                implode('', $polozky));
        }
        foreach ($ostatni as $o) {
            $h .= "<div class='chyba'>" . e($o) . '</div>';
        }
        return $h;
    }
}
