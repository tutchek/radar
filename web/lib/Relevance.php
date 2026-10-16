<?php
/**
 * Relevance.php – analýza relevance dokumentů (Word .docx, starý .doc, PDF) pro útvary. Port relevance.py.
 *
 *     skóre útvaru = součet přes výrazy (počet výskytů × váha výrazu pro útvar)
 *
 * Seznam výrazů je sešit Excelu (první list: Příklad | Regulární výraz | útvary… | Poznámka) ve složce
 * pravidla/ – sad může být víc, každý uživatel si vybere svou (viz Pravidla.php). Při instalaci se
 * založí základní sada ze vzoru relevance_pravidla_vzor.xlsx. Načítá se při každé analýze znovu.
 */

class RelevanceChyba extends RuntimeException {}

const REL_POCET_UKAZEK = 3;
const REL_SIRKA_KONTEXTU = 60;
const REL_SLOVNI_VAHY = ['ano' => 1, 'x' => 1, 'vysoká' => 3, 'vysoka' => 3, 'střední' => 2, 'stredni' => 2,
    'nízká' => 1, 'nizka' => 1, 'ne' => 0];
const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const NS_S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const NS_PREL = 'http://schemas.openxmlformats.org/package/2006/relationships';
const NS_MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';
const OLE_PODPIS = "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1";

/** Čtení dokumentů: text a náhled jako HTML. */
class Dokumenty
{
    // ---------------- pomocné ----------------

    /** Obsah souboru z archivu ZIP v paměti (ZipArchive potřebuje soubor). */
    public static function zip(string $data): array
    {
        $tmp = tempnam(docasny_adresar(), 'dok');
        file_put_contents($tmp, $data);
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) {
            @unlink($tmp);
            throw new RelevanceChyba('Soubor není ve formátu .docx (starý .doc nebo PDF analyzovat neumím).');
        }
        $obsah = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (substr($n, -4) === '.xml' || substr($n, -5) === '.rels') {
                $obsah[$n] = $z->getFromIndex($i);
            } else {
                $obsah[$n] = null;
            }
        }
        $z->close();
        @unlink($tmp);
        return $obsah;
    }

    public static function xml(string $text): DOMElement
    {
        $d = new DOMDocument();
        $stare = libxml_use_internal_errors(true);
        $ok = $d->loadXML($text, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_use_internal_errors($stare);
        if (!$ok || !$d->documentElement) {
            throw new RelevanceChyba('Soubor se nepodařilo přečíst (poškozené XML).');
        }
        return $d->documentElement;
    }

    private static function je($el, string $ns, string $jmeno): bool
    {
        return $el instanceof DOMElement && $el->namespaceURI === $ns && $el->localName === $jmeno;
    }

    private static function deti(DOMElement $el): array
    {
        $v = [];
        foreach ($el->childNodes as $ch) {
            if ($ch instanceof DOMElement) {
                $v[] = $ch;
            }
        }
        return $v;
    }

    private static function dite(?DOMElement $el, string $jmeno): ?DOMElement
    {
        if ($el === null) {
            return null;
        }
        foreach ($el->childNodes as $ch) {
            if (self::je($ch, NS_W, $jmeno)) {
                return $ch;
            }
        }
        return null;
    }

    private static function val(?DOMElement $el): ?string
    {
        return $el !== null && $el->hasAttributeNS(NS_W, 'val') ? $el->getAttributeNS(NS_W, 'val') : null;
    }

    public static function html(string $t): string
    {
        return htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---------------- Word .docx: text ----------------

    private static function odstavec(DOMElement $p, array &$vystup): void
    {
        $kusy = [];
        $projdi = function (DOMElement $el) use (&$projdi, &$kusy, &$vystup) {
            foreach (self::deti($el) as $ch) {
                if (self::je($ch, NS_MC, 'Fallback')) {
                    continue;
                }
                if (self::je($ch, NS_W, 'p')) {
                    self::odstavec($ch, $vystup);
                } elseif (self::je($ch, NS_W, 't')) {
                    $kusy[] = $ch->textContent;
                } elseif (self::je($ch, NS_W, 'tab')) {
                    $kusy[] = "\t";
                } elseif (self::je($ch, NS_W, 'br') || self::je($ch, NS_W, 'cr')) {
                    $kusy[] = "\n";
                } else {
                    $projdi($ch);
                }
            }
        };
        $projdi($p);
        $text = implode('', $kusy);
        if (trim($text) !== '') {
            $vystup[] = $text;
        }
    }

    private static function telo(DOMElement $el, array &$vystup): void
    {
        foreach (self::deti($el) as $ch) {
            if (self::je($ch, NS_MC, 'Fallback')) {
                continue;
            }
            if (self::je($ch, NS_W, 'p')) {
                self::odstavec($ch, $vystup);
            } else {
                self::telo($ch, $vystup);
            }
        }
    }

    public static function nacti_docx(string $data): string
    {
        $soubory = self::zip($data);
        $casti = ['word/document.xml'];
        $hf = array_values(array_filter(array_keys($soubory), function ($n) { return (bool)preg_match('~^word/(header|footer)\d*\.xml$~', $n); }));
        sort($hf, SORT_STRING);
        $casti = array_merge($casti, $hf, ['word/footnotes.xml', 'word/endnotes.xml', 'word/comments.xml']);
        $odstavce = [];
        foreach ($casti as $n) {
            if (isset($soubory[$n])) {
                self::telo(self::xml($soubory[$n]), $odstavce);
            }
        }
        return implode("\n", $odstavce);
    }

    // ---------------- Word .docx: náhled ----------------

    private static function zapnuto(?DOMElement $el): bool
    {
        return $el !== null && !in_array(self::val($el) ?? '1', ['0', 'false', 'off'], true);
    }

    private static function odstavec_html(DOMElement $p, array $styly): array
    {
        $kusy = [];
        $projdi = function (DOMElement $el, bool $tucne = false, bool $kurziva = false) use (&$projdi, &$kusy) {
            foreach (self::deti($el) as $ch) {
                if (self::je($ch, NS_MC, 'Fallback') || self::je($ch, NS_W, 'del') || self::je($ch, NS_W, 'pPr')) {
                    continue;
                }
                if (self::je($ch, NS_W, 'r')) {
                    $rpr = self::dite($ch, 'rPr');
                    $b = $rpr !== null ? self::zapnuto(self::dite($rpr, 'b')) : false;
                    $i = $rpr !== null ? self::zapnuto(self::dite($rpr, 'i')) : false;
                    foreach (self::deti($ch) as $x) {
                        if (self::je($x, NS_W, 't') && $x->textContent !== '') {
                            $kusy[] = [$x->textContent, $b || $tucne, $i || $kurziva];
                        } elseif (self::je($x, NS_W, 'tab')) {
                            $kusy[] = ["\t", false, false];
                        } elseif (self::je($x, NS_W, 'br') || self::je($x, NS_W, 'cr')) {
                            $kusy[] = ["\n", false, false];
                        }
                    }
                } else {
                    $projdi($ch, $tucne, $kurziva);
                }
            }
        };
        $projdi($p);
        $vystup = [];
        $posledni = null;
        foreach ($kusy as [$text, $b, $i]) {
            $t = str_replace("\n", '<br>', self::html($text));
            $znacka = ($b ? 'b' : '') . ($i ? 'i' : '');
            if ($vystup && $znacka === $posledni) {
                $vystup[count($vystup) - 1][1][] = $t;
            } else {
                $vystup[] = [$znacka, [$t]];
            }
            $posledni = $znacka;
        }
        $obsah = '';
        foreach ($vystup as [$znacka, $texty]) {
            $t = implode('', $texty);
            if (strpos($znacka, 'i') !== false) {
                $t = "<i>$t</i>";
            }
            if (strpos($znacka, 'b') !== false) {
                $t = "<b>$t</b>";
            }
            $obsah .= $t;
        }
        $ppr = self::dite($p, 'pPr');
        $styl = '';
        $seznam = false;
        if ($ppr !== null) {
            $ps = self::dite($ppr, 'pStyle');
            if ($ps !== null) {
                $v = self::val($ps);
                $styl = $styly[$v] ?? ($v ?? '');
            }
            $seznam = self::dite($ppr, 'numPr') !== null || (bool)preg_match('/^(list|seznam|odrážk|odrazk)/iu', $styl);
        }
        $nadpis = (bool)preg_match('/^(heading|nadpis|title|název)/iu', $styl);
        return [$obsah, $nadpis, $seznam];
    }

    private static function blok_html(DOMElement $el, array $styly, array &$vystup): void
    {
        $seznam = [];
        $uzavri = function () use (&$seznam, &$vystup) {
            if ($seznam) {
                $vystup[] = '<ul>' . implode('', array_map(function ($x) { return "<li>$x</li>"; }, $seznam)) . '</ul>';
                $seznam = [];
            }
        };
        foreach (self::deti($el) as $ch) {
            if (self::je($ch, NS_W, 'p')) {
                [$obsah, $nadpis, $je_seznam] = self::odstavec_html($ch, $styly);
                if (trim($obsah) === '') {
                    $uzavri();
                    continue;
                }
                if ($je_seznam) {
                    $seznam[] = $obsah;
                    continue;
                }
                $uzavri();
                $vystup[] = $nadpis ? "<h4>$obsah</h4>" : "<p>$obsah</p>";
            } elseif (self::je($ch, NS_W, 'tbl')) {
                $uzavri();
                $radky = [];
                foreach (self::deti($ch) as $tr) {
                    if (!self::je($tr, NS_W, 'tr')) {
                        continue;
                    }
                    $bunky = [];
                    foreach (self::deti($tr) as $tc) {
                        if (!self::je($tc, NS_W, 'tc')) {
                            continue;
                        }
                        $tcpr = self::dite($tc, 'tcPr');
                        $span = 1;
                        if ($tcpr !== null) {
                            $gs = self::dite($tcpr, 'gridSpan');
                            if ($gs !== null && ctype_digit((string)self::val($gs))) {
                                $span = (int)self::val($gs);
                            }
                            $vm = self::dite($tcpr, 'vMerge');
                            if ($vm !== null && self::val($vm) !== 'restart') {
                                $bunky[] = "<td class='slouceno'" . ($span > 1 ? " colspan='$span'" : '') . '></td>';
                                continue;
                            }
                        }
                        $obsah = [];
                        self::blok_html($tc, $styly, $obsah);
                        $bunky[] = '<td' . ($span > 1 ? " colspan='$span'" : '') . '>' . implode('', $obsah) . '</td>';
                    }
                    $radky[] = '<tr>' . implode('', $bunky) . '</tr>';
                }
                $vystup[] = "<div class='posun'><table class='dokument'>" . implode('', $radky) . '</table></div>';
            } elseif (self::je($ch, NS_MC, 'Fallback')) {
                continue;
            } elseif (self::je($ch, NS_W, 'sdt') || self::je($ch, NS_W, 'sdtContent') || self::je($ch, NS_W, 'customXml') || self::je($ch, NS_W, 'body')) {
                $uzavri();
                self::blok_html($ch, $styly, $vystup);
            }
        }
        $uzavri();
    }

    public static function docx_na_html(string $data): string
    {
        try {
            $soubory = self::zip($data);
            $styly = [];
            if (isset($soubory['word/styles.xml'])) {
                foreach (self::xml($soubory['word/styles.xml'])->getElementsByTagNameNS(NS_W, 'style') as $st) {
                    $n = self::dite($st, 'name');
                    if ($n !== null) {
                        $styly[$st->getAttributeNS(NS_W, 'styleId')] = self::val($n) ?? '';
                    }
                }
            }
            if (!isset($soubory['word/document.xml'])) {
                throw new RelevanceChyba('');
            }
            $dok = self::xml($soubory['word/document.xml']);
        } catch (RelevanceChyba $e) {
            throw new RelevanceChyba('Soubor se nepodařilo přečíst jako dokument Word (.docx).');
        }
        $vystup = [];
        $telo = self::dite($dok, 'body');
        if ($telo !== null) {
            self::blok_html($telo, $styly, $vystup);
        }
        return implode('', $vystup);
    }

    public static function text_na_html(string $text): string
    {
        $v = '';
        foreach (preg_split('/\n\s*\n|\n/u', $text) as $o) {
            $o = trim($o);
            if ($o !== '') {
                $v .= '<p>' . self::html(preg_replace('/[ \t]{2,}|\t/', '   ', $o)) . '</p>';
            }
        }
        return $v;
    }

    /** Náhled dokumentu jako HTML – .docx se strukturou, .doc a PDF jako text. */
    public static function na_html(string $data): string
    {
        if (substr($data, 0, 2) === 'PK') {
            return self::docx_na_html($data);
        }
        return self::text_na_html(self::nacti($data));
    }

    // ---------------- starý Word .doc (OLE2) ----------------

    private static function u16(string $d, int $o): int
    {
        return unpack('v', substr($d, $o, 2) . "\0\0")[1];
    }

    private static function u32(string $d, int $o): int
    {
        return unpack('V', substr($d, $o, 4) . "\0\0\0\0")[1];
    }

    private static function u32s(string $d): array
    {
        $n = intdiv(strlen($d), 4);
        return $n ? array_values(unpack("V$n", $d)) : [];
    }

    public static function utf16le(string $b): string
    {
        $v = '';
        $n = strlen($b) - (strlen($b) % 2);
        for ($i = 0; $i < $n; $i += 2) {
            $u = ord($b[$i]) | (ord($b[$i + 1]) << 8);
            if ($u >= 0xD800 && $u <= 0xDBFF && $i + 3 < $n) {
                $u2 = ord($b[$i + 2]) | (ord($b[$i + 3]) << 8);
                if ($u2 >= 0xDC00 && $u2 <= 0xDFFF) {
                    $v .= mb_chr(0x10000 + (($u - 0xD800) << 10) + ($u2 - 0xDC00), 'UTF-8');
                    $i += 2;
                    continue;
                }
            }
            $v .= ($u >= 0xD800 && $u <= 0xDFFF) ? "\u{FFFD}" : mb_chr($u, 'UTF-8');
        }
        if (strlen($b) % 2) {
            $v .= "\u{FFFD}";
        }
        return $v;
    }

    public static function cp1252(string $b): string
    {
        static $tab = null;
        if ($tab === null) {
            $tab = (require __DIR__ . '/kodovani.php')['cp1252'];
        }
        if (!preg_match('/[\x80-\xff]/', $b)) {
            return $b;
        }
        $v = '';
        $n = strlen($b);
        for ($i = 0; $i < $n; $i++) {
            $o = ord($b[$i]);
            $v .= $o < 128 ? $b[$i] : $tab[$o];
        }
        return $v;
    }

    public static function nacti_doc(string $data): string
    {
        if (substr($data, 0, 8) !== OLE_PODPIS) {
            throw new RelevanceChyba('Soubor není ve formátu Word 97–2003 (.doc).');
        }
        $konec = 0xFFFFFFFE;
        $sektor = 1 << self::u16($data, 0x1E);
        $mini_sektor = 1 << self::u16($data, 0x20);
        $pocet_fat = self::u32($data, 0x2C);
        $prvni_dir = self::u32($data, 0x30);
        $mini_mez = self::u32($data, 0x38);
        $prvni_minifat = self::u32($data, 0x3C);
        $pocet_minifat = self::u32($data, 0x40);
        $prvni_difat = self::u32($data, 0x44);
        $pocet_difat = self::u32($data, 0x48);
        $sek = function ($i) use ($data, $sektor) {
            return (string)substr($data, ($i + 1) * $sektor, $sektor);
        };
        $fat_sektory = array_values(array_filter(self::u32s(substr($data, 0x4C, 109 * 4)), function ($x) use ($konec) { return $x < $konec; }));
        $dalsi = $prvni_difat;
        $na_sektor = intdiv($sektor, 4) - 1;
        for ($k = 0; $k < $pocet_difat; $k++) {
            if ($dalsi >= $konec) {
                break;
            }
            $h = self::u32s(str_pad($sek($dalsi), ($na_sektor + 1) * 4, "\0"));
            foreach (array_slice($h, 0, $na_sektor) as $x) {
                if ($x < $konec) {
                    $fat_sektory[] = $x;
                }
            }
            $dalsi = $h[$na_sektor];
        }
        $fat = '';
        foreach (array_slice($fat_sektory, 0, $pocet_fat) as $x) {
            $fat .= $sek($x);
        }
        $fat = self::u32s($fat);
        $mini_proud = '';
        $retez = function ($start, $tabulka, $mini = false) use (&$mini_proud, $sek, $mini_sektor, $konec) {
            $kusy = [];
            $i = $start;
            $videno = [];
            while ($i < $konec && $i < count($tabulka) && !isset($videno[$i])) {
                $videno[$i] = true;
                $kusy[] = $mini ? (string)substr($mini_proud, $i * $mini_sektor, $mini_sektor) : $sek($i);
                $i = $tabulka[$i];
            }
            return implode('', $kusy);
        };
        $minifat = $pocet_minifat ? self::u32s($retez($prvni_minifat, $fat)) : [];
        $adresar = $retez($prvni_dir, $fat);
        $polozky = [];
        for ($i = 0; $i < intdiv(strlen($adresar), 128); $i++) {
            $z = substr($adresar, $i * 128, 128);
            $delka = self::u16($z, 0x40);
            $jmeno = self::utf16le(substr($z, 0, max(0, $delka - 2)));
            $typ = ord($z[0x42]);
            $start = self::u32($z, 0x74);
            $velikost = self::u32($z, 0x78);
            if ($typ === 5) {
                $mini_proud = substr($retez($start, $fat), 0, $velikost);
            } elseif ($typ === 2) {
                $polozky[$jmeno] = [$start, $velikost];
            }
        }
        $proud = function ($jmeno) use (&$polozky, $retez, $fat, &$minifat, $mini_mez) {
            if (!isset($polozky[$jmeno])) {
                throw new RelevanceChyba("V souboru .doc chybí část „{$jmeno}“.");
            }
            [$start, $velikost] = $polozky[$jmeno];
            if ($velikost < $mini_mez) {
                return substr($retez($start, $minifat, true), 0, $velikost);
            }
            return substr($retez($start, $fat), 0, $velikost);
        };
        $wd = $proud('WordDocument');
        if (strlen($wd) < 0x1AA || self::u16($wd, 0) !== 0xA5EC) {
            throw new RelevanceChyba('Soubor .doc má neznámý formát (podporován je Word 97 a novější).');
        }
        $priznaky = self::u16($wd, 0x0A);
        if ($priznaky & 0x0100) {
            throw new RelevanceChyba('Dokument .doc je zaheslovaný – nelze ho prohledat.');
        }
        $tabulka = $proud(($priznaky & 0x0200) ? '1Table' : '0Table');
        $fc_clx = self::u32($wd, 0x01A2);
        $lcb_clx = self::u32($wd, 0x01A6);
        $clx = (string)substr($tabulka, $fc_clx, $lcb_clx);
        $i = 0;
        while ($i < strlen($clx) && ord($clx[$i]) === 0x01) {
            $i += 3 + self::u16($clx, $i + 1);
        }
        if ($i >= strlen($clx) || ord($clx[$i]) !== 0x02) {
            throw new RelevanceChyba('V souboru .doc se nepodařilo najít tabulku textu.');
        }
        $lcb = self::u32($clx, $i + 1);
        $plc = (string)substr($clx, $i + 5, $lcb);
        $n = intdiv($lcb - 4, 12);
        $cp = self::u32s(substr($plc, 0, 4 * ($n + 1)));
        $kusy = '';
        for ($k = 0; $k < $n; $k++) {
            $fc = self::u32($plc, 4 * ($n + 1) + 8 * $k + 2);
            $znaku = $cp[$k + 1] - $cp[$k];
            if ($fc & 0x40000000) {
                $od = intdiv($fc & 0x3FFFFFFF, 2);
                $kusy .= self::cp1252((string)substr($wd, $od, $znaku));
            } else {
                $kusy .= self::utf16le((string)substr($wd, $fc, 2 * $znaku));
            }
        }
        return self::vycisti_doc($kusy);
    }

    private static function vycisti_doc(string $t): string
    {
        $v = '';
        $kod = [];
        foreach (mb_str_split($t) as $ch) {
            $o = mb_ord($ch);
            if ($o === 0x13) {
                $kod[] = true;
            } elseif ($o === 0x14) {
                if ($kod) {
                    $kod[count($kod) - 1] = false;
                }
            } elseif ($o === 0x15) {
                if ($kod) {
                    array_pop($kod);
                }
            } elseif (in_array(true, $kod, true)) {
                continue;
            } elseif ($ch === "\r" || $ch === "\x0b" || $ch === "\x0c") {
                $v .= "\n";
            } elseif ($o === 0x07) {
                $v .= "\t";
            } elseif ($o === 0x1e) {
                $v .= '-';
            } elseif ($o === 0x1f) {
                continue;
            } elseif ($o < 0x20 && $ch !== "\t" && $ch !== "\n") {
                continue;
            } else {
                $v .= $ch;
            }
        }
        return $v;
    }

    /** Text dokumentu – .docx/.docm, .doc nebo PDF (pozná se podle obsahu). */
    public static function nacti(string $data): string
    {
        if (substr($data, 0, 2) === 'PK') {
            return self::nacti_docx($data);
        }
        if (substr($data, 0, 8) === OLE_PODPIS) {
            return self::nacti_doc($data);
        }
        if (strpos(substr($data, 0, 1024), '%PDF') !== false) {
            try {
                return PdfText::nacti($data);
            } catch (PdfChyba $e) {
                throw new RelevanceChyba($e->getMessage());
            }
        }
        if (substr($data, 0, 5) === '{\\rtf') {
            throw new RelevanceChyba('Soubor je RTF – analyzovat umím jen Word (.docx, .doc) a PDF.');
        }
        throw new RelevanceChyba('Neznámý formát souboru – analyzovat umím jen Word (.docx, .doc) a PDF.');
    }

    // ---------------- Excel ----------------

    private static function index_sloupce(string $ref): int
    {
        preg_match('/[A-Z]+/', $ref, $m);
        $i = 0;
        foreach (str_split($m[0] ?? 'A') as $ch) {
            $i = $i * 26 + (ord($ch) - 64);
        }
        return $i - 1;
    }

    /** Řádky prvního listu sešitu .xlsx jako seznamy textů. */
    public static function nacti_xlsx(string $data): array
    {
        $tmp = tempnam(docasny_adresar(), 'xls');
        file_put_contents($tmp, $data);
        $z = new ZipArchive();
        if ($z->open($tmp) !== true) {
            @unlink($tmp);
            throw new RelevanceChyba('Soubor není sešit Excelu (.xlsx).');
        }
        try {
            $sdilene = [];
            if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach (self::xml($x)->getElementsByTagNameNS(NS_S, 'si') as $si) {
                    $t = '';
                    foreach ($si->getElementsByTagNameNS(NS_S, 't') as $tt) {
                        $t .= $tt->textContent;
                    }
                    $sdilene[] = $t;
                }
            }
            $wb = $z->getFromName('xl/workbook.xml');
            if ($wb === false) {
                throw new RelevanceChyba('Sešit neobsahuje workbook.xml.');
            }
            $listy = [];
            foreach (self::xml($wb)->getElementsByTagNameNS(NS_S, 'sheet') as $s) {
                $listy[] = [$s->getAttribute('name'), $s->getAttributeNS(NS_REL, 'id')];
            }
            if (!$listy) {
                throw new RelevanceChyba('Sešit neobsahuje žádný list.');
            }
            $rid = $listy[0][1];
            $cil = null;
            foreach (self::xml((string)$z->getFromName('xl/_rels/workbook.xml.rels'))->getElementsByTagNameNS(NS_PREL, 'Relationship') as $r) {
                if ($r->getAttribute('Id') === $rid) {
                    $cil = $r->getAttribute('Target');
                }
            }
            if ($cil === null) {
                throw new RelevanceChyba('V sešitu chybí první list.');
            }
            $cesta = $cil[0] === '/' ? ltrim($cil, '/') : 'xl/' . $cil;
            $radky = [];
            foreach (self::xml((string)$z->getFromName($cesta))->getElementsByTagNameNS(NS_S, 'row') as $row) {
                $hodnoty = [];
                $posledni = -1;
                foreach (self::deti($row) as $c) {
                    if (!self::je($c, NS_S, 'c')) {
                        continue;
                    }
                    $ref = $c->getAttribute('r');
                    $sl = $ref !== '' ? self::index_sloupce($ref) : $posledni + 1;
                    $posledni = $sl;
                    $typ = $c->getAttribute('t');
                    $v = null;
                    foreach (self::deti($c) as $x) {
                        if (self::je($x, NS_S, 'v')) {
                            $v = $x;
                        }
                    }
                    if ($typ === 's') {
                        $hod = $v !== null ? ($sdilene[(int)$v->textContent] ?? '') : '';
                    } elseif ($typ === 'inlineStr') {
                        $hod = '';
                        foreach ($c->getElementsByTagNameNS(NS_S, 't') as $t) {
                            $hod .= $t->textContent;
                        }
                    } else {
                        $hod = $v !== null ? $v->textContent : '';
                    }
                    $hodnoty[$sl] = $hod;
                }
                if ($hodnoty) {
                    $r = [];
                    for ($i = 0; $i <= max(array_keys($hodnoty)); $i++) {
                        $r[] = $hodnoty[$i] ?? '';
                    }
                    $radky[] = $r;
                } else {
                    $radky[] = [];
                }
            }
            return $radky;
        } finally {
            $z->close();
            @unlink($tmp);
        }
    }
}

/** Sdílený seznam výrazů a vyhodnocení dokumentu. */
class Relevance
{
    public $soubor;                  // sada ve složce pravidla/
    public $utvary = [], $pravidla = [], $chyby = [];
    public $zmeneno = null;          // čas změny souboru (timestamp)

    public function __construct(?string $soubor = null)
    {
        $this->soubor = SadyPravidel::existuje($soubor) ? $soubor : SadyPravidel::vychozi();
    }

    /** Načte sadu ze souboru (při každé analýze znovu – správce ji mohl mezitím nahrát znovu). */
    public function nacti(): self
    {
        $cesta = SadyPravidel::cesta($this->soubor);
        $obsah = @file_get_contents($cesta);
        if ($obsah === false) {
            throw new RelevanceChyba("Sadu výrazů {$this->soubor} nelze přečíst.");
        }
        $this->zmeneno = filemtime($cesta);
        [$this->utvary, $this->pravidla, $this->chyby] = self::zpracuj(Dokumenty::nacti_xlsx($obsah));
        return $this;
    }

    private static function vaha($h): float
    {
        $h = trim((string)$h);
        if ($h === '') {
            return 0.0;
        }
        $l = mb_strtolower($h);
        if (array_key_exists($l, REL_SLOVNI_VAHY)) {
            return (float)REL_SLOVNI_VAHY[$l];
        }
        $c = str_replace([',', ' '], ['.', ''], $h);
        if (!is_numeric($c) && !preg_match('/^[+-]?(inf|infinity|nan)$/i', $c)) {
            throw new InvalidArgumentException($h);
        }
        return (float)$c;
    }

    public static function cislo(float $x): string
    {
        return floor($x) == $x ? (string)(int)$x : str_replace('.', ',', sprintf('%.2f', $x));
    }

    public static function zpracuj(array $radky): array
    {
        if (!$radky) {
            throw new RelevanceChyba('Seznam výrazů je prázdný.');
        }
        $hlavicka = array_map(function ($h) { return trim((string)$h); }, $radky[0]);
        $klice = array_map('bez_diakritiky', $hlavicka);
        $i_priklad = $i_vyraz = $i_pozn = null;
        foreach ($klice as $i => $k) {
            if ($i_priklad === null && strpos($k, 'priklad') === 0) {
                $i_priklad = $i;
            }
        }
        foreach ($klice as $i => $k) {
            if ($i_vyraz === null && (strpos($k, 'vyraz') !== false || in_array($k, ['regex', 'vzor', 'regularni vyraz'], true))) {
                $i_vyraz = $i;
            }
        }
        foreach ($klice as $i => $k) {
            if ($i_pozn === null && preg_match('/^(poznamk|komentar|popis)/', $k)) {
                $i_pozn = $i;
            }
        }
        if ($i_vyraz === null) {
            throw new RelevanceChyba('V hlavičce seznamu chybí sloupec „Regulární výraz“.');
        }
        $utvary = [];
        foreach ($hlavicka as $i => $h) {
            if ($h !== '' && !in_array($i, [$i_priklad, $i_vyraz, $i_pozn], true)) {
                $utvary[] = [$i, $h];
            }
        }
        if (!$utvary) {
            throw new RelevanceChyba('V hlavičce seznamu nejsou žádné útvary.');
        }
        $pravidla = [];
        $chyby = [];
        foreach (array_slice($radky, 1) as $j => $radek) {
            $cislo_radku = $j + 2;
            $bunka = function ($i) use ($radek) {
                return ($i !== null && $i < count($radek)) ? trim((string)$radek[$i]) : '';
            };
            $vzor = $bunka($i_vyraz);
            if ($vzor === '' || $vzor[0] === '#') {
                continue;
            }
            $vahy = [];
            foreach ($utvary as [$i, $utvar]) {
                try {
                    $v = self::vaha($bunka($i));
                } catch (InvalidArgumentException $e) {
                    $chyby[] = sprintf('řádek %d: neplatná hodnota „%s“ u útvaru %s', $cislo_radku, $bunka($i), $utvar);
                    continue;
                }
                if ($v) {
                    $vahy[$utvar] = $v;
                }
            }
            $regex = py_regex($vzor, 'iu');
            if (@preg_match($regex, '') === false) {
                $chyby[] = sprintf('řádek %d: neplatný regulární výraz „%s“ (%s)', $cislo_radku, $vzor, preg_last_error_msg());
                continue;
            }
            $pravidla[] = ['radek' => $cislo_radku, 'vzor' => $vzor, 'regex' => $regex, 'vahy' => $vahy,
                'priklad' => $bunka($i_priklad), 'poznamka' => $bunka($i_pozn)];
        }
        return [array_column($utvary, 1), $pravidla, $chyby];
    }

    public function analyzuj_text(string $text): array
    {
        $this->nacti();
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $nalezy = [];
        $ws = function ($s) { return preg_replace('/\s+/u', ' ', $s); };
        foreach ($this->pravidla as $p) {
            $pocet = 0;
            $ukazky = [];
            if (preg_match_all($p['regex'], $text, $shody, PREG_OFFSET_CAPTURE)) {
                foreach ($shody[0] as [$s, $off]) {
                    if ($s === '') {
                        continue;
                    }
                    $pocet++;
                    if (count($ukazky) < REL_POCET_UKAZEK) {
                        $pred = substr($text, 0, $off);
                        $za = substr($text, $off + strlen($s));
                        $dp = mb_strlen($pred);
                        $dz = mb_strlen($za);
                        $ukazky[] = [($dp > REL_SIRKA_KONTEXTU ? '…' : '') . $ws(mb_substr($pred, -min($dp, REL_SIRKA_KONTEXTU))),
                            $ws($s), $ws(mb_substr($za, 0, REL_SIRKA_KONTEXTU)) . ($dz > REL_SIRKA_KONTEXTU ? '…' : '')];
                    }
                }
            }
            if ($pocet) {
                $n = $p;
                unset($n['regex']);
                $n['pocet'] = $pocet;
                $n['ukazky'] = $ukazky;
                $nalezy[] = $n;
            }
        }
        $utvary = [];
        foreach ($this->utvary as $u) {
            $duvody = array_values(array_filter($nalezy, function ($n) use ($u) { return !empty($n['vahy'][$u]); }));
            $skore = 0.0;
            $vyskytu = 0;
            foreach ($duvody as $n) {
                $skore += $n['pocet'] * $n['vahy'][$u];
                $vyskytu += $n['pocet'];
            }
            usort($duvody, function ($a, $b) use ($u) { return ($b['pocet'] * $b['vahy'][$u]) <=> ($a['pocet'] * $a['vahy'][$u]); });
            $utvary[] = ['utvar' => $u, 'skore' => $skore, 'skore_text' => self::cislo($skore), 'vyskytu' => $vyskytu,
                'vyrazu' => count($duvody), 'duvody' => $duvody];
        }
        usort($utvary, function ($a, $b) { return ($b['skore'] <=> $a['skore']) ?: strcmp((string)$a['utvar'], (string)$b['utvar']); });
        return ['utvary' => $utvary, 'nalezy' => $nalezy, 'znaku' => mb_strlen($text), 'chyby' => $this->chyby];
    }

    public function analyzuj_dokument(string $data): array
    {
        return $this->analyzuj_text(Dokumenty::nacti($data));
    }
}
