<?php
/**
 * PdfText.php – vytažení textu z PDF bez externích knihoven. Port pdftext.py.
 * Čte objekty i z objektových proudů, filtry Flate / ASCIIHex / ASCII85 (+ PNG prediktory),
 * text dekóduje přes ToUnicode, WinAnsi / MacRoman a Differences; prochází strom stránek
 * včetně formulářových XObjektů. Neumí naskenované a šifrované PDF.
 */

class PdfChyba extends RuntimeException {}

final class PdfNazev { public $n; public function __construct(string $n) { $this->n = $n; } }
final class PdfOp { public $o; public function __construct(string $o) { $this->o = $o; } }
final class PdfOdkaz { public $c, $g; public function __construct(int $c, int $g) { $this->c = $c; $this->g = $g; } }
final class PdfRetezec { public $b; public function __construct(string $b) { $this->b = $b; } }
final class PdfPole { public $a; public function __construct(array $a) { $this->a = $a; } }
final class PdfSlovnik { public $d; public function __construct(array $d) { $this->d = $d; }
    public function get(string $k, $v = null) { return array_key_exists($k, $this->d) ? $this->d[$k] : $v; } }
final class PdfProud { public $slovnik, $data; public function __construct(PdfSlovnik $s, string $d) { $this->slovnik = $s; $this->data = $d; } }

const PDF_BILE = " \t\r\n\x0c\x00";
const PDF_ODDELOVACE = "()<>[]{}/%";

class PdfLexer
{
    public $d, $i, $n;

    public function __construct(string $data, int $pozice = 0)
    {
        $this->d = $data;
        $this->i = $pozice;
        $this->n = strlen($data);
    }

    public function bile(): void
    {
        while ($this->i < $this->n) {
            $this->i += strspn($this->d, PDF_BILE, $this->i);
            if ($this->i < $this->n && $this->d[$this->i] === '%') {
                $this->i += strcspn($this->d, "\r\n", $this->i);
            } else {
                break;
            }
        }
    }

    /** Token: int/float, PdfNazev, PdfRetezec, PdfOp, nebo '[', ']', '<<', '>>', '{', '}'; null = konec. */
    public function token()
    {
        while (true) {
            $this->bile();
            if ($this->i >= $this->n) {
                return null;
            }
            $c = $this->d[$this->i];
            if ($c === '/') {
                $j = $this->i + 1;
                $j += strcspn($this->d, PDF_BILE . PDF_ODDELOVACE, $j);
                $surove = substr($this->d, $this->i + 1, $j - $this->i - 1);
                $this->i = $j;
                $surove = preg_replace_callback('/#([0-9A-Fa-f]{2})/', function ($m) { return chr(hexdec($m[1])); }, $surove);
                return new PdfNazev(self::latin1($surove));
            }
            if ($c === '(') {
                return $this->literal();
            }
            if ($c === '<') {
                if (substr($this->d, $this->i + 1, 1) === '<') {
                    $this->i += 2;
                    return '<<';
                }
                $j = strpos($this->d, '>', $this->i);
                $j = $j === false ? $this->n : $j;
                $hexa = preg_replace('/[^0-9A-Fa-f]/', '', substr($this->d, $this->i + 1, $j - $this->i - 1));
                $this->i = $j + 1;
                if (strlen($hexa) % 2) {
                    $hexa .= '0';
                }
                return new PdfRetezec((string)hex2bin($hexa));
            }
            if ($c === '>') {
                $this->i += substr($this->d, $this->i + 1, 1) === '>' ? 2 : 1;
                return '>>';
            }
            if (strpos('[]{}', $c) !== false) {
                $this->i++;
                return $c;
            }
            $j = $this->i + strcspn($this->d, PDF_BILE . PDF_ODDELOVACE, $this->i);
            if ($j === $this->i) {
                $this->i++;
                continue;
            }
            $slovo = substr($this->d, $this->i, $j - $this->i);
            $this->i = $j;
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)$/', $slovo)) {
                if (preg_match('/^[+-]?\d+$/', $slovo)) {
                    return (int)$slovo;
                }
                return (float)$slovo;
            }
            return new PdfOp(self::latin1($slovo));
        }
    }

    public static function latin1(string $s): string
    {
        if (!preg_match('/[\x80-\xff]/', $s)) {
            return $s;
        }
        $v = '';
        $n = strlen($s);
        for ($k = 0; $k < $n; $k++) {
            $o = ord($s[$k]);
            $v .= $o < 0x80 ? $s[$k] : chr(0xC0 | ($o >> 6)) . chr(0x80 | ($o & 0x3F));
        }
        return $v;
    }

    private function literal(): PdfRetezec
    {
        $d = $this->d;
        $n = $this->n;
        $this->i++;
        $v = '';
        $hloubka = 1;
        while ($this->i < $n) {
            $k = strcspn($d, "\\()", $this->i);
            if ($k) {
                $v .= substr($d, $this->i, $k);
                $this->i += $k;
                if ($this->i >= $n) {
                    break;
                }
            }
            $c = $d[$this->i++];
            if ($c === '\\') {
                if ($this->i >= $n) {
                    break;
                }
                $e = $d[$this->i++];
                $mapa = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c"];
                if (isset($mapa[$e])) {
                    $v .= $mapa[$e];
                } elseif ($e >= '0' && $e <= '7') {
                    $okt = $e;
                    while (strlen($okt) < 3 && $this->i < $n && $d[$this->i] >= '0' && $d[$this->i] <= '7') {
                        $okt .= $d[$this->i++];
                    }
                    $v .= chr(octdec($okt) & 0xFF);
                } elseif ($e === "\r" || $e === "\n") {
                    if ($e === "\r" && $this->i < $n && $d[$this->i] === "\n") {
                        $this->i++;
                    }
                } else {
                    $v .= $e;
                }
            } elseif ($c === '(') {
                $hloubka++;
                $v .= $c;
            } else {
                $hloubka--;
                if ($hloubka === 0) {
                    break;
                }
                $v .= $c;
            }
        }
        return new PdfRetezec($v);
    }

    public function objekt($tok = null)
    {
        $tok = $tok === null ? $this->token() : $tok;
        if ($tok === '<<') {
            $s = [];
            while (true) {
                $k = $this->token();
                if ($k === null || $k === '>>') {
                    return new PdfSlovnik($s);
                }
                if ($k instanceof PdfNazev) {
                    $s[$k->n] = $this->objekt();
                }
            }
        }
        if ($tok === '[') {
            $p = [];
            while (true) {
                $t = $this->token();
                if ($t === null || $t === ']') {
                    return new PdfPole($p);
                }
                $p[] = $this->objekt($t);
            }
        }
        if (is_int($tok)) {
            $ulozeno = $this->i;
            $t2 = $this->token();
            if (is_int($t2)) {
                $t3 = $this->token();
                if ($t3 instanceof PdfOp && $t3->o === 'R') {
                    return new PdfOdkaz($tok, $t2);
                }
            }
            $this->i = $ulozeno;
            return $tok;
        }
        if ($tok instanceof PdfOp) {
            if ($tok->o === 'true') {
                return true;
            }
            if ($tok->o === 'false') {
                return false;
            }
            if ($tok->o === 'null') {
                return null;
            }
        }
        return $tok;
    }
}

class PdfText
{
    public $data;
    public $objekty = [];
    public $trailer = [];

    const GLYFY = [
        'space' => ' ', 'exclam' => '!', 'quotedbl' => '"', 'numbersign' => '#', 'dollar' => '$', 'percent' => '%',
        'ampersand' => '&', 'quotesingle' => "'", 'quoteright' => '’', 'quoteleft' => '‘', 'parenleft' => '(',
        'parenright' => ')', 'asterisk' => '*', 'plus' => '+', 'comma' => ',', 'hyphen' => '-', 'period' => '.',
        'slash' => '/', 'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5',
        'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9', 'colon' => ':', 'semicolon' => ';',
        'less' => '<', 'equal' => '=', 'greater' => '>', 'question' => '?', 'at' => '@', 'bracketleft' => '[',
        'backslash' => '\\', 'bracketright' => ']', 'underscore' => '_', 'braceleft' => '{', 'bar' => '|',
        'braceright' => '}', 'section' => '§', 'endash' => '–', 'emdash' => '—', 'quotedblleft' => '“',
        'quotedblright' => '”', 'quotedblbase' => '„', 'quotesinglbase' => '‚', 'bullet' => '•',
        'ellipsis' => '…', 'degree' => '°', 'nbspace' => ' ', 'uni00A0' => ' ',
        'aacute' => 'á', 'Aacute' => 'Á', 'eacute' => 'é', 'Eacute' => 'É', 'iacute' => 'í', 'Iacute' => 'Í',
        'oacute' => 'ó', 'Oacute' => 'Ó', 'uacute' => 'ú', 'Uacute' => 'Ú', 'yacute' => 'ý', 'Yacute' => 'Ý',
        'ecaron' => 'ě', 'Ecaron' => 'Ě', 'scaron' => 'š', 'Scaron' => 'Š', 'ccaron' => 'č', 'Ccaron' => 'Č',
        'rcaron' => 'ř', 'Rcaron' => 'Ř', 'zcaron' => 'ž', 'Zcaron' => 'Ž', 'dcaron' => 'ď', 'Dcaron' => 'Ď',
        'tcaron' => 'ť', 'Tcaron' => 'Ť', 'ncaron' => 'ň', 'Ncaron' => 'Ň', 'uring' => 'ů', 'Uring' => 'Ů',
        'adieresis' => 'ä', 'odieresis' => 'ö', 'udieresis' => 'ü', 'Adieresis' => 'Ä', 'Odieresis' => 'Ö',
        'Udieresis' => 'Ü', 'germandbls' => 'ß', 'lcaron' => 'ľ', 'Lcaron' => 'Ľ', 'lacute' => 'ĺ', 'Lacute' => 'Ĺ',
        'racute' => 'ŕ', 'ocircumflex' => 'ô', 'fi' => 'fi', 'fl' => 'fl', 'ff' => 'ff', 'ffi' => 'ffi',
    ];

    /** Text PDF (bytes); stránky oddělené prázdným řádkem. */
    public static function nacti(string $data): string
    {
        $dok = new PdfText($data);
        if (($dok->trailer['Encrypt'] ?? null) !== null) {
            throw new PdfChyba('PDF je zabezpečené (šifrované) – text z něj číst neumím.');
        }
        $stranky = [];
        $cache = [];
        foreach ($dok->stranky() as [$stranka, $zdroje]) {
            $v = [];
            $dok->textProudu($dok->proud($stranka->get('Contents')), $zdroje, $v, 0, $cache);
            $stranky[] = implode('', $v);
        }
        $text = implode("\n\n", $stranky);
        $text = preg_replace('/[ \t]+\n/', "\n", str_replace("\x00", '', $text));
        if (trim($text) === '') {
            throw new PdfChyba('PDF neobsahuje textovou vrstvu (nejspíš je naskenované) – nelze v něm hledat.');
        }
        return $text;
    }

    public function __construct(string $data)
    {
        if (strncmp(substr(ltrim($data), 0, 5), '%PDF', 4) !== 0) {
            throw new PdfChyba('Soubor není PDF.');
        }
        $this->data = $data;
        $this->nactiObjekty();
    }

    private function nactiObjekty(): void
    {
        $d = $this->data;
        $konec_proudu = 0;
        preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $d, $shody, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($shody as $m) {
            $start = $m[0][1];
            if ($start < $konec_proudu) {
                continue;
            }
            $cislo = (int)$m[1][0];
            $lex = new PdfLexer($d, $start + strlen($m[0][0]));
            try {
                $h = $lex->objekt();
            } catch (Throwable $e) {
                continue;
            }
            if ($h instanceof PdfSlovnik) {
                $lex->bile();
                if (substr($d, $lex->i, 6) === 'stream') {
                    $z = $lex->i + 6;
                    if (substr($d, $z, 2) === "\r\n") {
                        $z += 2;
                    } elseif (in_array(substr($d, $z, 1), ["\n", "\r"], true)) {
                        $z += 1;
                    }
                    $delka = $h->get('Length');
                    $konec = is_int($delka) ? $z + $delka : -1;
                    if ($konec < 0 || strpos(substr($d, $konec, 30), 'endstream') === false) {
                        $konec = strpos($d, 'endstream', $z);
                        $konec = $konec === false ? strlen($d) : $konec;
                    }
                    $h = new PdfProud($h, (string)substr($d, $z, max(0, $konec - $z)));
                    $konec_proudu = $konec;
                }
            }
            $this->objekty[$cislo] = $h;
            if ($h instanceof PdfProud && self::jmeno($h->slovnik->get('Type')) === 'XRef') {
                $this->trailer = array_merge($this->trailer, $h->slovnik->d);
            }
        }
        preg_match_all('/trailer\s*<</', $d, $tr, PREG_OFFSET_CAPTURE);
        foreach ($tr[0] as $m) {
            $t = (new PdfLexer($d, $m[1] + strlen($m[0]) - 2))->objekt();
            if ($t instanceof PdfSlovnik) {
                $this->trailer = array_merge($this->trailer, $t->d);
            }
        }
        foreach ($this->objekty as $cislo => $o) {
            if ($o instanceof PdfProud && self::jmeno($o->slovnik->get('Type')) === 'ObjStm') {
                try {
                    $obsah = $this->dekodujFiltry($o->slovnik, $o->data);
                    $n = (int)$this->r($o->slovnik->get('N'));
                    $prvni = (int)$this->r($o->slovnik->get('First'));
                    $lex = new PdfLexer($obsah);
                    $hl = [];
                    for ($k = 0; $k < 2 * $n; $k++) {
                        $hl[] = $lex->token();
                    }
                    for ($k = 0; $k < $n; $k++) {
                        $c = $hl[2 * $k];
                        $posun = $hl[2 * $k + 1];
                        if (!is_int($c) || !is_int($posun)) {
                            continue;
                        }
                        if (!array_key_exists($c, $this->objekty)) {
                            $this->objekty[$c] = (new PdfLexer($obsah, $prvni + $posun))->objekt();
                        }
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
    }

    public static function jmeno($x): ?string
    {
        return $x instanceof PdfNazev ? $x->n : (is_string($x) ? $x : null);
    }

    public function r($x, int $hloubka = 0)
    {
        while ($x instanceof PdfOdkaz && $hloubka < 20) {
            $x = $this->objekty[$x->c] ?? null;
            $hloubka++;
        }
        return $x;
    }

    public function proud($x): string
    {
        $x = $this->r($x);
        if ($x instanceof PdfProud) {
            return $this->dekodujFiltry($x->slovnik, $x->data);
        }
        if ($x instanceof PdfPole) {
            return implode("\n", array_map([$this, 'proud'], $x->a));
        }
        return '';
    }

    public function dekodujFiltry(PdfSlovnik $s, string $data): string
    {
        $filtry = $this->r($s->get('Filter'));
        if ($filtry === null) {
            return $data;
        }
        $filtry = $filtry instanceof PdfPole ? $filtry->a : [$filtry];
        foreach ($filtry as $f) {
            $f = self::jmeno($this->r($f));
            if ($f === 'FlateDecode' || $f === 'Fl') {
                $z = @gzuncompress($data);
                if ($z === false) {
                    $ctx = @inflate_init(ZLIB_ENCODING_DEFLATE);
                    $z = $ctx ? @inflate_add($ctx, $data, ZLIB_SYNC_FLUSH) : false;
                    if ($z === false) {
                        return '';
                    }
                }
                $data = $z;
                $par = $this->r($s->get('DecodeParms'));
                if ($par instanceof PdfPole) {
                    $nalez = null;
                    foreach ($par->a as $p) {
                        if ($p) {
                            $nalez = $this->r($p);
                            break;
                        }
                    }
                    $par = $nalez;
                }
                $par = $par instanceof PdfSlovnik ? $par : new PdfSlovnik([]);
                $prediktor = $this->r($par->get('Predictor', 1));
                if ($prediktor && $prediktor >= 10) {
                    $data = self::pngPrediktor($data, (int)($this->r($par->get('Columns', 1)) ?: 1),
                        (int)($this->r($par->get('Colors', 1)) ?: 1), (int)($this->r($par->get('BitsPerComponent', 8)) ?: 8));
                }
            } elseif ($f === 'ASCIIHexDecode' || $f === 'AHx') {
                $hexa = preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $data)[0]);
                $data = (string)hex2bin($hexa . str_repeat('0', strlen($hexa) % 2));
            } elseif ($f === 'ASCII85Decode' || $f === 'A85') {
                $telo = trim($data);
                if (substr($telo, 0, 2) === '<~') {
                    $telo = substr($telo, 2);
                }
                $telo = explode('~>', $telo)[0];
                $v = self::a85(preg_replace('/\s/', '', $telo));
                if ($v === null) {
                    return '';
                }
                $data = $v;
            } else {
                return '';
            }
        }
        return $data;
    }

    private static function a85(string $s): ?string
    {
        $v = '';
        $skup = [];
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $c = $s[$i];
            if ($c === 'z' && !$skup) {
                $v .= "\0\0\0\0";
                continue;
            }
            $o = ord($c);
            if ($o < 33 || $o > 117) {
                return null;
            }
            $skup[] = $o - 33;
            if (count($skup) === 5) {
                $x = 0;
                foreach ($skup as $d) {
                    $x = $x * 85 + $d;
                }
                if ($x > 0xFFFFFFFF) {
                    return null;
                }
                $v .= pack('N', $x);
                $skup = [];
            }
        }
        if ($skup) {
            $dopl = 5 - count($skup);
            $x = 0;
            foreach (array_pad($skup, 5, 84) as $d) {
                $x = $x * 85 + $d;
            }
            $v .= substr(pack('N', $x & 0xFFFFFFFF), 0, 4 - $dopl);
        }
        return $v;
    }

    private static function pngPrediktor(string $data, int $sloupcu, int $barev, int $bitu): string
    {
        $sirka = max(1, intdiv($sloupcu * $barev * $bitu, 8));
        $bpp = max(1, intdiv($barev * $bitu, 8));
        $v = '';
        $pred = array_fill(0, $sirka, 0);
        $n = strlen($data);
        for ($i = 0; $i < $n; $i += $sirka + 1) {
            $typ = ord($data[$i]);
            $radek = array_values(unpack('C*', substr($data, $i + 1, $sirka) ?: "\0") ?: []);
            if (substr($data, $i + 1, $sirka) === '') {
                $radek = [];
            }
            $m = count($radek);
            for ($j = 0; $j < $m; $j++) {
                $a = $j >= $bpp ? $radek[$j - $bpp] : 0;
                $b = $pred[$j] ?? 0;
                $c = $j >= $bpp ? ($pred[$j - $bpp] ?? 0) : 0;
                if ($typ === 1) {
                    $radek[$j] = ($radek[$j] + $a) & 0xFF;
                } elseif ($typ === 2) {
                    $radek[$j] = ($radek[$j] + $b) & 0xFF;
                } elseif ($typ === 3) {
                    $radek[$j] = ($radek[$j] + intdiv($a + $b, 2)) & 0xFF;
                } elseif ($typ === 4) {
                    $p = $a + $b - $c;
                    $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                    $radek[$j] = ($radek[$j] + ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c))) & 0xFF;
                }
            }
            $v .= $radek ? pack('C*', ...$radek) : '';
            $pred = $radek;
        }
        return $v;
    }

    /** @return array [[PdfSlovnik stránka, PdfSlovnik zdroje], …] */
    public function stranky(): array
    {
        $v = [];
        $projdi = function ($uzel, $zdedene, $hloubka) use (&$projdi, &$v) {
            $uzel = $this->r($uzel);
            if ($uzel instanceof PdfProud) {
                $uzel = $uzel->slovnik;
            }
            if (!$uzel instanceof PdfSlovnik || $hloubka > 50) {
                return;
            }
            if (array_key_exists('Resources', $uzel->d)) {
                $zdedene = $uzel->d['Resources'];
            }
            if (self::jmeno($uzel->get('Type')) === 'Pages' || array_key_exists('Kids', $uzel->d)) {
                $deti = $this->r($uzel->get('Kids'));
                foreach ($deti instanceof PdfPole ? $deti->a : [] as $d) {
                    $projdi($d, $zdedene, $hloubka + 1);
                }
            } else {
                $z = $this->r($zdedene);
                $v[] = [$uzel, $z instanceof PdfSlovnik ? $z : new PdfSlovnik([])];
            }
        };
        $koren = $this->r($this->trailer['Root'] ?? null);
        if ($koren instanceof PdfSlovnik && $koren->get('Pages') !== null) {
            $projdi($koren->get('Pages'), null, 0);
        }
        if (!$v) {
            $cisla = array_keys($this->objekty);
            sort($cisla);
            foreach ($cisla as $c) {
                $o = $this->objekty[$c];
                if ($o instanceof PdfSlovnik && self::jmeno($o->get('Type')) === 'Page') {
                    $z = $this->r($o->get('Resources'));
                    $v[] = [$o, $z instanceof PdfSlovnik ? $z : new PdfSlovnik([])];
                }
            }
        }
        return $v;
    }

    // ---------------- písma ----------------

    public static function glyf(string $jmeno): string
    {
        if (isset(self::GLYFY[$jmeno])) {
            return self::GLYFY[$jmeno];
        }
        if (mb_strlen($jmeno) === 1) {
            return $jmeno;
        }
        if (preg_match('/^uni([0-9A-Fa-f]{4})/', $jmeno, $m) || preg_match('/^u([0-9A-Fa-f]{4,6})$/', $jmeno, $m)) {
            $cp = hexdec($m[1]);
            return ($cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) ? '' : mb_chr($cp, 'UTF-8');
        }
        $zaklad = explode('_', explode('.', $jmeno)[0])[0];
        return self::GLYFY[$zaklad] ?? (mb_strlen($zaklad) === 1 ? $zaklad : '');
    }

    /** UTF-16BE → UTF-8 (striktně: chyba → null). */
    public static function utf16($b): ?string
    {
        $n = strlen($b);
        if ($n % 2) {
            return null;
        }
        $v = '';
        for ($i = 0; $i < $n; $i += 2) {
            $u = (ord($b[$i]) << 8) | ord($b[$i + 1]);
            if ($u >= 0xD800 && $u <= 0xDBFF) {
                if ($i + 3 >= $n) {
                    return null;
                }
                $u2 = (ord($b[$i + 2]) << 8) | ord($b[$i + 3]);
                if ($u2 < 0xDC00 || $u2 > 0xDFFF) {
                    return null;
                }
                $u = 0x10000 + (($u - 0xD800) << 10) + ($u2 - 0xDC00);
                $i += 2;
            } elseif ($u >= 0xDC00 && $u <= 0xDFFF) {
                return null;
            }
            $v .= mb_chr($u, 'UTF-8');
        }
        return $v;
    }

    public static function cmap(string $data): array
    {
        $mapa = [];
        $delky = [];
        preg_match_all('/begincodespacerange(.*?)endcodespacerange/s', $data, $bl);
        foreach ($bl[1] as $b) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<[0-9A-Fa-f]+>/', $b, $m);
            foreach ($m[1] as $a) {
                $delky[intdiv(strlen($a), 2)] = true;
            }
        }
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $data, $bl);
        foreach ($bl[1] as $b) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $b, $m, PREG_SET_ORDER);
            foreach ($m as $x) {
                $mapa[self::hex($x[1])] = self::utf16(self::hex($x[2])) ?? '';
                $delky[intdiv(strlen($x[1]), 2)] = true;
            }
        }
        preg_match_all('/beginbfrange(.*?)endbfrange/s', $data, $bl);
        foreach ($bl[1] as $b) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])/', $b, $m, PREG_SET_ORDER);
            foreach ($m as $x) {
                $n = intdiv(strlen($x[1]), 2);
                $od = hexdec($x[1]);
                $do = hexdec($x[2]);
                $delky[$n] = true;
                if ($do - $od > 65535) {
                    continue;
                }
                if ($x[3][0] === '[') {
                    preg_match_all('/<([0-9A-Fa-f]*)>/', $x[3], $cile);
                    foreach (array_slice($cile[1], 0, max(0, $do - $od + 1)) as $k => $c) {
                        $mapa[self::naBajty($od + $k, $n)] = self::utf16(self::hex($c)) ?? '';
                    }
                } else {
                    $zaklad = self::hex(substr($x[3], 1, -1));
                    if ($zaklad === '') {
                        continue;
                    }
                    $h = 0;
                    foreach (str_split($zaklad) as $ch) {
                        $h = $h * 256 + ord($ch);
                    }
                    for ($k = 0; $k <= $do - $od; $k++) {
                        $mapa[self::naBajty($od + $k, $n)] = self::utf16(self::naBajty($h + $k, strlen($zaklad))) ?? '';
                    }
                }
            }
        }
        $d = array_keys($delky);
        sort($d);
        return [$mapa, $d ?: [1]];
    }

    private static function hex(string $h): string
    {
        return $h === '' ? '' : (string)hex2bin(strlen($h) % 2 ? $h . '0' : $h);
    }

    private static function naBajty(int $x, int $n): string
    {
        $v = '';
        for ($i = 0; $i < $n; $i++) {
            $v = chr($x & 0xFF) . $v;
            $x >>= 8;
        }
        return $v;
    }

    // ---------------- obsah stránky ----------------

    public function pismo($slovnik): array
    {
        $slovnik = $this->r($slovnik);
        $slovnik = $slovnik instanceof PdfSlovnik ? $slovnik : new PdfSlovnik([]);
        $p = ['mapa' => [], 'delky' => [1], 'kodovani' => null];
        $slozene = self::jmeno($slovnik->get('Subtype')) === 'Type0';
        $tu = $slovnik->get('ToUnicode');
        if ($tu !== null) {
            [$p['mapa'], $p['delky']] = self::cmap($this->proud($tu));
        } elseif ($slozene) {
            $p['delky'] = [2];
        }
        if (!$slozene) {
            $p['delky'] = [1];
            $enc = $this->r($slovnik->get('Encoding'));
            $zakladni = $enc instanceof PdfNazev ? $enc->n : ($enc instanceof PdfSlovnik ? self::jmeno($enc->get('BaseEncoding')) : null);
            $p['kodovani'] = $zakladni === 'MacRomanEncoding' ? 'mac_roman' : 'cp1252';
            if ($enc instanceof PdfSlovnik) {
                $kod = 0;
                $diff = $this->r($enc->get('Differences'));
                foreach ($diff instanceof PdfPole ? $diff->a : [] as $x) {
                    $x = $this->r($x);
                    if (is_int($x)) {
                        $kod = $x;
                    } elseif ($x instanceof PdfNazev) {
                        $bajt = chr($kod & 0xFF);
                        $znak = self::glyf($x->n);
                        if (!array_key_exists($bajt, $p['mapa']) && $znak !== '') {
                            $p['mapa'][$bajt] = $znak;
                        }
                        $kod++;
                    }
                }
            }
        }
        return $p;
    }

    public static function textPisma(array $p, string $b): string
    {
        static $tab = null;
        if ($tab === null) {
            $tab = require __DIR__ . '/kodovani.php';
        }
        if ($b === '') {
            return '';
        }
        $v = '';
        $i = 0;
        $n = strlen($b);
        while ($i < $n) {
            $nalez = false;
            foreach ($p['delky'] as $delka) {
                $kus = substr($b, $i, $delka);
                if (array_key_exists($kus, $p['mapa'])) {
                    $v .= $p['mapa'][$kus];
                    $i += $delka;
                    $nalez = true;
                    break;
                }
            }
            if ($nalez) {
                continue;
            }
            $delka = $p['delky'][0];
            $kus = substr($b, $i, $delka);
            if ($p['kodovani']) {
                foreach (str_split($kus) as $ch) {
                    $o = ord($ch);
                    $v .= $o < 128 ? $ch : $tab[$p['kodovani']][$o];
                }
            } elseif (!$p['mapa'] && $delka === 2) {
                $z = self::utf16($kus) ?? '';
                $v .= preg_match('/^[\p{L}\p{M}\p{N}\p{P}\p{S} ]$/u', $z) ? $z : '';
            }
            $i += $delka;
        }
        return $v;
    }

    public function textProudu(string $obsah, $zdroje, array &$vystup, int $hloubka, array &$cache): void
    {
        if ($hloubka > 8) {
            return;
        }
        $zdroje = $this->r($zdroje);
        $zdroje = $zdroje instanceof PdfSlovnik ? $zdroje : new PdfSlovnik([]);
        $pisma = $this->r($zdroje->get('Font'));
        $pisma = $pisma instanceof PdfSlovnik ? $pisma : new PdfSlovnik([]);
        $xo = $this->r($zdroje->get('XObject'));
        $xo = $xo instanceof PdfSlovnik ? $xo : new PdfSlovnik([]);
        $pismo = null;
        $lex = new PdfLexer($obsah);
        $op = [];
        $posledni_y = $posledni_x = null;
        $novy_radek = function () use (&$vystup) {
            if ($vystup && substr(end($vystup), -1) !== "\n") {
                $vystup[] = "\n";
            }
        };
        $mezera = function () use (&$vystup) {
            if ($vystup) {
                $k = substr(end($vystup), -1);
                if ($k !== ' ' && $k !== "\n") {
                    $vystup[] = ' ';
                }
            }
        };
        $cislo = function ($x) { return is_int($x) || is_float($x); };
        while (true) {
            try {
                $tok = $lex->token();
            } catch (Throwable $e) {
                break;
            }
            if ($tok === null) {
                break;
            }
            if (!$tok instanceof PdfOp) {
                $op[] = ($tok === '[' || $tok === '<<') ? $lex->objekt($tok) : $tok;
                continue;
            }
            $o = $tok->o;
            $posl = $op ? end($op) : null;
            if ($o === 'BI') {
                $m = preg_match('/\sID\s/', $obsah, $mm, PREG_OFFSET_CAPTURE, $lex->i) ? $mm[0][1] + strlen($mm[0][0]) : $lex->i;
                $lex->i = preg_match('/\sEI(\s|$)/', $obsah, $kk, PREG_OFFSET_CAPTURE, $m) ? $kk[0][1] + strlen($kk[0][0]) : strlen($obsah);
            } elseif ($o === 'Tf' && count($op) >= 2) {
                $jm = $op[count($op) - 2];
                $jm = $jm instanceof PdfNazev ? $jm->n : (is_scalar($jm) ? (string)$jm : '');
                $klic = spl_object_id($pisma) . "\x00" . $jm;
                if (!array_key_exists($klic, $cache)) {
                    $cache[$klic] = array_key_exists($jm, $pisma->d) ? $this->pismo($pisma->d[$jm]) : null;
                }
                $pismo = $cache[$klic];
            } elseif (($o === 'Tj' || $o === "'" || $o === '"') && $op) {
                if ($o !== 'Tj') {
                    $novy_radek();
                }
                if ($posl instanceof PdfRetezec && $pismo !== null) {
                    $vystup[] = self::textPisma($pismo, $posl->b);
                }
            } elseif ($o === 'TJ' && $posl instanceof PdfPole) {
                foreach ($posl->a as $x) {
                    if ($x instanceof PdfRetezec && $pismo !== null) {
                        $vystup[] = self::textPisma($pismo, $x->b);
                    } elseif ($cislo($x) && $x < -180) {
                        $mezera();
                    }
                }
            } elseif (($o === 'Td' || $o === 'TD') && count($op) >= 2) {
                $tx = $op[count($op) - 2];
                $ty = $posl;
                if ($cislo($ty) && abs($ty) > 0.5) {
                    $novy_radek();
                } elseif ($cislo($tx) && $tx > 0.5) {
                    $mezera();
                }
            } elseif ($o === 'Tm' && count($op) >= 6) {
                $x = $op[count($op) - 2];
                $y = $posl;
                if ($cislo($y)) {
                    if ($posledni_y !== null && abs($y - $posledni_y) > 0.5) {
                        $novy_radek();
                    } elseif ($posledni_x !== null && $cislo($x) && $x > $posledni_x) {
                        $mezera();
                    }
                    $posledni_y = $y;
                    $posledni_x = $x;
                }
            } elseif ($o === 'T*') {
                $novy_radek();
            } elseif ($o === 'ET') {
                $mezera();
            } elseif ($o === 'Do' && $op) {
                $jm = $posl instanceof PdfNazev ? $posl->n : '';
                $x = $this->r($xo->get($jm));
                if ($x instanceof PdfProud && self::jmeno($x->slovnik->get('Subtype')) === 'Form') {
                    $this->textProudu($this->dekodujFiltry($x->slovnik, $x->data),
                        $x->slovnik->get('Resources') ?: $zdroje, $vystup, $hloubka + 1, $cache);
                }
            }
            $op = [];
        }
    }
}
