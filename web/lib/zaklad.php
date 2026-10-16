<?php
/**
 * Legislativní radar (PHP) – společný základ: konfigurace, databáze, stahování, pomocné funkce.
 *
 * Načítá ho index.php, cron.php i install.php. Žádné externí knihovny – jen rozšíření PHP
 * (pdo_mysql, zip, curl, zlib, mbstring/iconv, dom).
 */

declare(strict_types=1);

const RADAR_VERZE = '1.0';
const NAZEV_APLIKACE = 'Legislativní radar';
define('RADAR_KOREN', dirname(__DIR__));

mb_internal_encoding('UTF-8');
// varování a upozornění na zastaralé konstrukce nesmí rozbít stránku (zapisují se do logu hostingu)
if (!getenv('RADAR_LADENI')) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

class OdokChyba extends RuntimeException {}
class SitovaChyba extends OdokChyba
{
    public $kod = 0;   // HTTP kód (0 = spojení se nepodařilo)
}

spl_autoload_register(function ($trida) {
    $mapa = [
        'Odok' => 'Odok.php', 'Material' => 'Odok.php',
        'Psp' => 'Psp.php', 'Tisk' => 'Psp.php', 'Krok' => 'Psp.php', 'BodSchuze' => 'Psp.php', 'Obdobi' => 'Psp.php',
        'Esel' => 'Esel.php', 'NavrhEsel' => 'Esel.php', 'SequenceMatcher' => 'Esel.php',
        'Podklady' => 'Plpv.php', 'ListPLPV' => 'Plpv.php',
        'Relevance' => 'Relevance.php', 'RelevanceChyba' => 'Relevance.php', 'Dokumenty' => 'Relevance.php',
        'PdfText' => 'PdfText.php', 'PdfChyba' => 'PdfText.php',
        'Import' => 'Import.php', 'JsonProud' => 'Import.php',
        'Auth' => 'Auth.php', 'Oblibene' => 'Auth.php', 'SadyPravidel' => 'Pravidla.php',
        'Web' => 'Web.php', 'Admin' => 'Admin.php',
    ];
    if (isset($mapa[$trida])) {
        require_once __DIR__ . '/' . $mapa[$trida];
    }
});

// --------------------------------------------------------------------------
// Konfigurace
// --------------------------------------------------------------------------

class Konfig
{
    private static $data = null;

    public static function vychozi(): array
    {
        return [
            'db' => ['host' => 'localhost', 'port' => 3306, 'nazev' => '', 'uzivatel' => '', 'heslo' => '',
                     'prefix' => 'lr_'],
            'cron_klic' => '',             // klíč pro spuštění cron.php přes HTTP (?klic=…)
            'anonymni_cteni' => false,     // true = prohlížení bez přihlášení (oblíbené jen přihlášeným)
            'prihlaseni_dni' => 30,        // trvalé přihlášení na zařízení (dní od posledního použití; 0 = vypnuto)
            'odok_prvni_rok' => 2016,      // nejstarší ročník datasetu ODOK, který se importuje
            'platnost_h' => [              // po jaké době (hodiny) se zdroj stáhne znovu; 24 = jednou denně
                                           // (2× denně po hodinách 'denni_obnova'), 0 = jen jednou (pak už jen ručně)
                'odok' => 24,              //   ODOK – letošní ročník
                'odok_stary' => 0,         //   ODOK – minulé ročníky (už se nemění; po skončení roku se
                                           //   ještě jednou stáhnou 2. ledna, pak už ne)
                'psp' => 24,               //   Poslanecká sněmovna a Senát (data se aktualizují jednou denně)
                'esel' => 24,              //   e-Legislativa (aktualizace jednou denně)
                'esbirka' => 24,           //   e-Sbírka – názvy předpisů
                'esel_novely' => 24,       //   e-Legislativa – předpisy měněné návrhy (strom objektů, 16 MB)
                'esbirka_novely' => 168,   //   e-Sbírka – novelizační vazby (19 MB, přibývají pomalu – týdně)
                'detail' => 2,             //   verze a připomínky materiálu (stahují se při zobrazení)
            ],
            'denni_obnova' => [7, 19],     // po kterých hodinách se „denní“ zdroje stahují znovu (každá z nich)
            'soubezne_stahovani' => 4,     // kolik souborů stahovat najednou (historie verzí pro PLPV)
            'plpv_prednacist' => [],       // resorty (např. ['MF']), jejichž historie verzí cron předem stáhne
            'proxy' => '',                 // proxy pro odchozí spojení, např. 'http://proxy:8080'
            'timeout' => 60,
            'casova_zona' => 'Europe/Prague',
        ];
    }

    public static function soubor(): string
    {
        return RADAR_KOREN . '/config.php';
    }

    public static function existuje(): bool
    {
        return is_file(self::soubor());
    }

    public static function vse(): array
    {
        if (self::$data === null) {
            $vlastni = self::existuje() ? (require self::soubor()) : [];
            self::$data = self::sluc(self::vychozi(), is_array($vlastni) ? $vlastni : []);
        }
        return self::$data;
    }

    public static function nastav(array $data): void
    {
        self::$data = self::sluc(self::vychozi(), $data);
    }

    /** Výchozí + vlastní konfigurace; seznamy (např. denni_obnova => [7, 19]) se nahrazují celé, ne po prvcích. */
    private static function sluc(array $vychozi, array $vlastni): array
    {
        foreach ($vlastni as $k => $v) {
            if (is_array($v) && isset($vychozi[$k]) && is_array($vychozi[$k]) && $vychozi[$k] && array_keys($vychozi[$k]) !== range(0, count($vychozi[$k]) - 1)) {
                $vychozi[$k] = self::sluc($vychozi[$k], $v);    // asociativní pole (db, platnost_h) – po klíčích
            } else {
                $vychozi[$k] = $v;
            }
        }
        return $vychozi;
    }

    public static function get(string $klic, $vychozi = null)
    {
        $d = self::vse();
        foreach (explode('.', $klic) as $k) {
            if (!is_array($d) || !array_key_exists($k, $d)) {
                return $vychozi;
            }
            $d = $d[$k];
        }
        return $d;
    }
}

date_default_timezone_set((string)(Konfig::get('casova_zona') ?: 'Europe/Prague'));

// --------------------------------------------------------------------------
// Databáze
// --------------------------------------------------------------------------

class Db
{
    private static $pdo = null;

    public static function pripoj(?array $c = null): PDO
    {
        if (self::$pdo !== null && $c === null) {
            return self::$pdo;
        }
        $c = $c ?: Konfig::get('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)($c['port'] ?: 3306), $c['nazev']);
        $pdo = new PDO($dsn, $c['uzivatel'], $c['heslo'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_czech_ci, time_zone = '+00:00', sql_mode = ''");
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function pdo(): PDO
    {
        return self::pripoj();
    }

    public static function t(string $tabulka): string
    {
        return '`' . Konfig::get('db.prefix') . $tabulka . '`';
    }

    /** SQL s {p} místo prefixu tabulek. */
    public static function sql(string $sql): string
    {
        return str_replace('{p}', (string)Konfig::get('db.prefix'), $sql);
    }

    public static function q(string $sql, array $param = []): PDOStatement
    {
        $st = self::pdo()->prepare(self::sql($sql));
        $st->execute(array_values($param));
        return $st;
    }

    public static function vse(string $sql, array $param = []): array
    {
        return self::q($sql, $param)->fetchAll();
    }

    public static function radek(string $sql, array $param = []): ?array
    {
        $r = self::q($sql, $param)->fetch();
        return $r === false ? null : $r;
    }

    public static function hodnota(string $sql, array $param = [])
    {
        $r = self::q($sql, $param)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function sloupec(string $sql, array $param = []): array
    {
        return self::q($sql, $param)->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Hromadné vložení řádků (po dávkách). $radky = seznam polí hodnot ve stejném pořadí jako $sloupce. */
    public static function vlozVice(string $tabulka, array $sloupce, array $radky, int $davka = 400, string $prikaz = 'INSERT'): void
    {
        if (!$radky) {
            return;
        }
        $seznam = implode(',', array_map(function ($s) { return '`' . $s . '`'; }, $sloupce));
        $jeden = '(' . implode(',', array_fill(0, count($sloupce), '?')) . ')';
        foreach (array_chunk($radky, $davka) as $kus) {
            $sql = "$prikaz INTO " . (strpos($tabulka, '`') === false ? self::t($tabulka) : $tabulka)
                . " ($seznam) VALUES " . implode(',', array_fill(0, count($kus), $jeden));
            $hodnoty = [];
            foreach ($kus as $r) {
                foreach ($r as $v) {
                    $hodnoty[] = $v;
                }
            }
            self::pdo()->prepare($sql)->execute($hodnoty);
        }
    }

    public static function existujeTabulka(string $tabulka): bool
    {
        return (bool)self::hodnota('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [Konfig::get('db.prefix') . $tabulka]);
    }

    /** Parametry pro IN (…): vrací řetězec otazníků. */
    public static function in(array $hodnoty): string
    {
        return $hodnoty ? implode(',', array_fill(0, count($hodnoty), '?')) : 'NULL';
    }
}

// --------------------------------------------------------------------------
// Stahování (curl, cache v databázi)
// --------------------------------------------------------------------------

class Http
{
    const USER_AGENT = 'legislativni-radar-php/1.0';

    /** Volitelné zrcadlo (konfigurace 'zrcadlo'): https://host/cesta → <zrcadlo>/host/cesta. Pro testy. */
    public static function adresa(string $url): string
    {
        $z = Konfig::get('zrcadlo');
        return $z ? rtrim($z, '/') . '/' . preg_replace('~^https?://~', '', $url) : $url;
    }

    private static function curl(string $url, int $timeout)
    {
        $ch = curl_init(self::adresa($url));
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::USER_AGENT, CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_ENCODING => '',
            // HTTP/1.1 jako urllib v Pythonu: curl jinak u HTTPS posílá souběžné požadavky jedním
            // spojením HTTP/2 a firewally úřadů takový nápor ukončují resetem spojení
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        if (Konfig::get('proxy')) {
            curl_setopt($ch, CURLOPT_PROXY, Konfig::get('proxy'));
        }
        return $ch;
    }

    private static function chyba(string $url, string $zprava, int $kod = 0): SitovaChyba
    {
        $ch = new SitovaChyba("Nelze stáhnout $url: $zprava");
        $ch->kod = $kod;
        return $ch;
    }

    /** Stáhne URL do paměti. Vrací [tělo, hlavičky(string)]. */
    public static function stahni(string $url, ?int $timeout = null): array
    {
        $timeout = $timeout ?: (int)Konfig::get('timeout', 60);
        if (function_exists('curl_init')) {
            $ch = self::curl($url, $timeout);
            $hlavicky = '';
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $h) use (&$hlavicky) {
                $hlavicky .= $h;
                return strlen($h);
            });
            $telo = curl_exec($ch);
            $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            if ($telo === false) {
                throw self::chyba($url, $err ?: 'chyba spojení');
            }
            if ($kod >= 400) {
                throw self::chyba($url, "HTTP $kod", $kod);
            }
            return [$telo, $hlavicky];
        }
        $kontext = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => self::USER_AGENT,
            'ignore_errors' => true, 'proxy' => Konfig::get('proxy') ? str_replace('http://', 'tcp://', Konfig::get('proxy')) : null,
            'request_fulluri' => (bool)Konfig::get('proxy')]]);
        $telo = @file_get_contents($url, false, $kontext);
        $hlavicky = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? null);
        $hl = $hlavicky ? implode("\r\n", $hlavicky) : '';
        if ($telo === false) {
            throw self::chyba($url, 'chyba spojení');
        }
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $hl, $m) && (int)$m[1] >= 400) {
            throw self::chyba($url, "HTTP {$m[1]}", (int)$m[1]);
        }
        return [$telo, $hl];
    }

    /** Stáhne URL do souboru (velké archivy – bez načtení do paměti). */
    public static function doSouboru(string $url, string $cil, ?int $timeout = null): void
    {
        $timeout = $timeout ?: max(300, (int)Konfig::get('timeout', 60));
        if (!function_exists('curl_init')) {
            [$telo] = self::stahni($url, $timeout);
            file_put_contents($cil, $telo);
            return;
        }
        $f = fopen($cil, 'wb');
        if (!$f) {
            throw new OdokChyba("Nelze zapisovat do $cil");
        }
        $ch = self::curl($url, $timeout);
        curl_setopt($ch, CURLOPT_FILE, $f);
        $ok = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        fclose($f);
        if ($ok === false || $kod >= 400) {
            @unlink($cil);
            throw self::chyba($url, $ok === false ? ($err ?: 'chyba spojení') : "HTTP $kod", $ok === false ? 0 : $kod);
        }
    }

    /**
     * Obsah URL z cache v databázi (je-li čerstvá), jinak stáhne a uloží.
     * Při chybě sítě vrátí i starou kopii, pokud existuje. $platnost_h = null → bez omezení stáří.
     */
    public static function sCache(string $url, ?float $platnost_h, bool $vynutit = false): string
    {
        $hash = sha1($url);
        $r = Db::radek('SELECT stazeno, obsah FROM {p}http_cache WHERE url_hash = ?', [$hash]);
        if ($r && self::neplatnyJson($url, (string)$r['obsah'])) {
            $r = null;      // v cache zůstala chybová stránka ze starší verze – stáhnout znovu
        }
        if ($r && !$vynutit && ($platnost_h === null || time() - strtotime($r['stazeno'] . ' UTC') < $platnost_h * 3600)) {
            return (string)$r['obsah'];
        }
        if (isset(self::$selhalo[$url]) && !$vynutit) {
            // souběžné stažení v tomto požadavku už selhalo (i po opakování) – nezkoušet znovu
            if ($r) {
                return (string)$r['obsah'];
            }
            throw self::chyba($url, self::$selhalo[$url]);
        }
        try {
            [$telo] = self::stahni($url);
        } catch (SitovaChyba $e) {
            if ($r) {
                return (string)$r['obsah'];
            }
            throw $e;
        }
        if (self::neplatnyJson($url, $telo)) {
            log_zprava("Neplatná odpověď (místo JSON) z $url: " . mb_substr(trim(strip_tags($telo)), 0, 200), 'chyba');
            if ($r && !self::neplatnyJson($url, (string)$r['obsah'])) {
                return (string)$r['obsah'];      // zdroj zrovna vrací chybovou stránku – použít starší kopii
            }
        } else {
            self::ulozCache($url, $telo);
        }
        return $telo;
    }

    /**
     * Adresa, která má vracet JSON (…/json, ….json), vrátila něco jiného (stránku údržby, chybovou HTML stránku,
     * prázdnou odpověď)? Taková odpověď se nebere jako úspěch – neuloží se a zůstane starší kopie.
     */
    public static function neplatnyJson(string $url, string $telo): bool
    {
        if (!preg_match('~(/json|\.json)(\?|$)~i', $url)) {
            return false;
        }
        $t = ltrim(strncmp($telo, "\xEF\xBB\xBF", 3) === 0 ? substr($telo, 3) : $telo);
        return $t === '' || ($t[0] !== '{' && $t[0] !== '[');
    }

    public static function ulozCache(string $url, string $telo): void
    {
        try {
            Db::q('REPLACE INTO {p}http_cache (url_hash, url, stazeno, velikost, obsah) VALUES (?, ?, UTC_TIMESTAMP(), ?, ?)',
                [sha1($url), $url, strlen($telo), $telo]);
        } catch (PDOException $e) {
            // příliš velký soubor pro max_allowed_packet apod. – jen se neuloží do cache
        }
    }

    public static function jeVCache(string $url, ?float $platnost_h): bool
    {
        $r = Db::radek('SELECT stazeno, ' . (preg_match('~(/json|\.json)(\?|$)~i', $url) ? 'LEFT(obsah, 16)' : "''") . ' AS zacatek FROM {p}http_cache WHERE url_hash = ?', [sha1($url)]);
        if (!$r || self::neplatnyJson($url, (string)$r['zacatek'])) {
            return false;
        }
        return $platnost_h === null || time() - strtotime($r['stazeno'] . ' UTC') < $platnost_h * 3600;
    }

    /** URL, která v tomto požadavku selhala při souběžném stahování → sCache už je znovu nestahuje. */
    public static $selhalo = [];

    /**
     * Souběžné stažení více URL do cache (curl_multi). Stahuje jen ty, které nejsou čerstvé.
     * Neúspěšné zkusí znovu po pauze a s menší souběžností (servery úřadů při náporu resetují
     * spojení), dokud nevyprší čas $do_kdy (unix čas; výchozí: na webu ~18 s od začátku požadavku).
     * Vrací [url => chyba] pro neúspěšné; ty si zapamatuje v $selhalo, aby je sCache už
     * nestahovala znovu a rovnou použila starší kopii z cache (je-li).
     */
    public static function predNacti(array $urls, float $platnost_h, int $soubezne = 0, ?float $do_kdy = null): array
    {
        $soubezne = $soubezne ?: max(1, (int)Konfig::get('soubezne_stahovani', 4));
        if ($do_kdy === null) {
            $do_kdy = PHP_SAPI === 'cli' ? microtime(true) + 300
                : (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) + 18;
        }
        $urls = array_values(array_filter(array_unique($urls), function ($u) use ($platnost_h) {
            return !self::jeVCache($u, $platnost_h);
        }));
        $chyby = [];
        $pauzy = [0, 2, 5];              // kolo 1 hned, další po pauze
        foreach ($pauzy as $kolo => $pauza) {
            if (!$urls) {
                break;
            }
            if ($kolo > 0) {
                if (microtime(true) + $pauza + 3 > $do_kdy) {
                    break;               // na další pokus už nezbývá čas
                }
                sleep($pauza);
            }
            $n = max(1, intdiv($soubezne, 1 << $kolo));     // 4 → 2 → 1
            $chyby_kola = self::davka($urls, $n, $do_kdy);
            foreach ($urls as $u) {
                if (!isset($chyby_kola[$u])) {
                    unset($chyby[$u]);
                }
            }
            $chyby = $chyby_kola + $chyby;
            // znovu jen přechodné chyby (spojení, časový limit, 429, 5xx); 404 apod. nemá smysl opakovat
            $urls = array_keys(array_filter($chyby_kola, function ($c) { return $c[1]; }));
        }
        $vysledek = [];
        foreach ($chyby as $u => [$zprava]) {
            $vysledek[$u] = $zprava;
            self::$selhalo[$u] = $zprava;
        }
        return $vysledek;
    }

    /** Jedno kolo souběžného stahování. Vrací [url => [zpráva, přechodná?]]. */
    private static function davka(array $urls, int $soubezne, float $do_kdy): array
    {
        $chyby = [];
        if (!function_exists('curl_multi_init')) {
            foreach ($urls as $u) {
                if (microtime(true) > $do_kdy) {
                    $chyby[$u] = ['nestihlo se stáhnout (časový limit stránky)', true];
                    continue;
                }
                try {
                    [$telo] = self::stahni($u, max(5, min((int)Konfig::get('timeout', 60), (int)($do_kdy - microtime(true)))));
                    if (self::neplatnyJson($u, $telo)) {
                        $chyby[$u] = ['neplatná odpověď (místo JSON)', true];
                        continue;
                    }
                    self::ulozCache($u, $telo);
                } catch (SitovaChyba $e) {
                    $chyby[$u] = [preg_replace('~^Nelze stáhnout \S+: ~', '', $e->getMessage()), $e->kod === 0 || $e->kod === 429 || $e->kod >= 500];
                }
            }
            return $chyby;
        }
        $mh = curl_multi_init();
        $fronta = $urls;
        $aktivni = [];
        $pridej = function () use (&$fronta, &$aktivni, $mh, $do_kdy) {
            $u = array_shift($fronta);
            $limit = max(5, min((int)Konfig::get('timeout', 60), (int)ceil($do_kdy - microtime(true))));
            $ch = self::curl($u, $limit);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($mh, $ch);
            $aktivni[(int)$ch] = [$ch, $u];
        };
        while ($fronta && count($aktivni) < $soubezne) {
            $pridej();
        }
        do {
            curl_multi_exec($mh, $bezi);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $u] = $aktivni[(int)$ch];
                $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $telo = curl_multi_getcontent($ch);
                if ($info['result'] === CURLE_OK && $kod < 400 && $telo !== null && self::neplatnyJson($u, $telo)) {
                    $chyby[$u] = ['neplatná odpověď (místo JSON)', true];
                } elseif ($info['result'] === CURLE_OK && $kod < 400 && $telo !== null) {
                    self::ulozCache($u, $telo);
                } elseif ($info['result'] !== CURLE_OK) {
                    $chyby[$u] = [curl_error($ch) ?: curl_strerror($info['result']), true];
                } else {
                    $chyby[$u] = ["HTTP $kod", $kod === 429 || $kod >= 500];
                }
                curl_multi_remove_handle($mh, $ch);
                unset($aktivni[(int)$ch]);
                if ($fronta) {
                    if (microtime(true) < $do_kdy) {
                        $pridej();
                        $bezi = 1;
                    } else {
                        foreach ($fronta as $z) {
                            $chyby[$z] = ['nestihlo se stáhnout (časový limit stránky)', true];
                        }
                        $fronta = [];
                    }
                }
            }
        } while ($bezi || $aktivni);
        curl_multi_close($mh);
        return $chyby;
    }
}

// --------------------------------------------------------------------------
// Pomocné funkce
// --------------------------------------------------------------------------

/** 'Ministerstvo vnitra ČR' → 'ministerstvo vnitra cr' (jako unicodedata NFKD v Pythonu). */
function bez_diakritiky($text): string
{
    static $mapa = null;
    if ($text === null) {
        return '';
    }
    if ($mapa === null) {
        $mapa = require __DIR__ . '/diakritika.php';
    }
    $t = (string)$text;
    if (preg_match('/[\x80-\xff]/', $t)) {
        $t = strtr($t, $mapa);
    }
    return mb_strtolower($t, 'UTF-8');
}

/**
 * Regulární výraz pro hledání, ve kterém jsou si rovny tvary jako „zákon, který“ a „návrh zákona, kterým“
 * (návrh / zákon / vyhláška / nařízení / který v libovolném pádě, „návrh“ před druhem předpisu nepovinný,
 * mezi slovy libovolná interpunkce). Hledá se v textu bez diakritiky a malými písmeny. Vrací null,
 * když dotaz nic takového neobsahuje – pak stačí obyčejné hledání podřetězce.
 */
function vzor_hledani(string $q): ?string
{
    $slova = preg_split('~[^a-z0-9/]+~', bez_diakritiky(trim($q)), -1, PREG_SPLIT_NO_EMPTY);
    if (!$slova) {
        return null;
    }
    $zmena = false;
    $casti = [];
    $n = count($slova);
    for ($i = 0; $i < $n; $i++) {
        $s = $slova[$i];
        $dalsi = $slova[$i + 1] ?? '';
        if (preg_match('/^navrh(u|em|y)?$/', $s) && preg_match('/^(zakon|vyhlas|narizen|ustavn|usnesen)/', $dalsi)) {
            $zmena = true;          // „návrh zákona …“ = „zákon …“
            continue;
        }
        if (preg_match('/^zakon(a|u|em|y|ech|um)?$/', $s)) {
            $casti[] = 'zakon[a-z]{0,3}';
        } elseif (preg_match('/^vyhlas(ka|ky|ce|ku|kou|ek|kam|kach|kami)$/', $s)) {
            $casti[] = 'vyhlas(ka|ky|ce|ku|kou|ek|kam|kach|kami)';
        } elseif (preg_match('/^narizeni(m|mi)?$/', $s)) {
            $casti[] = 'narizeni[a-z]{0,2}';
        } elseif (preg_match('/^kter(y|ym|ou|a|e|eho|emu|ymi|ych|i)$/', $s)) {
            $casti[] = 'kter[a-z]{0,3}';
        } elseif (preg_match('/^navrh(u|em|y|ech|um)?$/', $s)) {
            $casti[] = 'navrh[a-z]{0,3}';
        } else {
            $casti[] = preg_quote($s, '~');
            continue;
        }
        $zmena = true;
    }
    if (!$zmena || !$casti) {
        return null;
    }
    return implode('[^a-z0-9]+', $casti);
}

/** Escapování pro HTML. */
function e($hodnota): string
{
    if ($hodnota === null) {
        return '';
    }
    if (is_bool($hodnota)) {
        $hodnota = $hodnota ? 'True' : 'False';
    }
    return htmlspecialchars((string)$hodnota, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Odkaz na stránku aplikace: odkaz('/material', ['id' => …]) → index.php?p=material&id=… */
function odkaz(string $cesta, array $parametry = []): string
{
    $p = trim($cesta, '/');
    $dotaz = $p === '' ? [] : ['p' => $p];
    foreach ($parametry as $k => $v) {
        $dotaz[$k] = $v === null ? '' : (is_bool($v) ? ($v ? 'True' : 'False') : (string)$v);
    }
    return 'index.php' . ($dotaz ? '?' . http_build_query($dotaz, '', '&', PHP_QUERY_RFC1738) : '');
}

/** Datum (řetězec 'Y-m-d' nebo 'Y-m-d H:i:s') česky: 5. 3. 2026 (10:00). */
function fmt_datum($d): string
{
    if ($d === null || $d === '' || $d === false) {
        return '';
    }
    $d = (string)$d;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $d, $m)) {
        $s = sprintf('%d. %d. %d', (int)$m[3], (int)$m[2], (int)$m[1]);
        if (isset($m[4]) && ((int)$m[4] || (int)$m[5])) {
            $s .= sprintf(' %d:%02d', (int)$m[4], (int)$m[5]);
        }
        return $s;
    }
    return $d;
}

/** Jen den z data/času: '2026-03-05 10:00:00' → '2026-03-05'. */
function den($d): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$d) ? substr((string)$d, 0, 10) : null;
}

/** Datum/čas jako 'Y-m-d H:i:s' (datum bez času = půlnoc); jinak null. Pro porovnávání. */
function jako_cas($d): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    $d = (string)$d;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return $d . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $d)) {
        return substr($d, 0, 10) . ' ' . substr($d, 11, 8);
    }
    return null;
}

function je_datum($d): bool
{
    return is_string($d) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}/', $d);
}

function dnes(): string
{
    return date('Y-m-d');
}

function ted(): string
{
    return date('Y-m-d H:i:s');
}

/** Počet dní mezi dvěma daty 'Y-m-d' (b - a). */
function rozdil_dni(string $a, string $b): int
{
    return (int)round((strtotime($b . ' 12:00:00') - strtotime($a . ' 12:00:00')) / 86400);
}

function pridej_dny(string $d, int $dni): string
{
    return date('Y-m-d', strtotime(substr($d, 0, 10) . " 12:00:00 $dni days"));
}

/** Normalizace identifikátoru (PID, čj) pro porovnání: bez mezer, velká písmena. */
function norm_id($hodnota): string
{
    return mb_strtoupper(preg_replace('/\s+/u', '', (string)($hodnota ?? '')), 'UTF-8');
}

/** Regulární výraz v Pythonu → PCRE (Unicode, bez ohledu na velikost písmen). */
function py_regex(string $vzor, string $priznaky = 'u'): string
{
    return "\x01" . $vzor . "\x01" . $priznaky;
}

/** Hodnota pro <option>, cookie, … – celé číslo z řetězce, jinak null. */
function cele_cislo($v): ?int
{
    if (is_int($v)) {
        return $v;
    }
    return (is_string($v) && preg_match('/^\d+$/', $v)) ? (int)$v : null;
}

/** Python-like str(): None → 'None', bool → True/False, float → 1.0 … (pro texty, kde Python skládal str()). */
function py_str($v): string
{
    if ($v === null) {
        return 'None';
    }
    if (is_bool($v)) {
        return $v ? 'True' : 'False';
    }
    if (is_float($v)) {
        return floor($v) == $v && abs($v) < 1e16 ? sprintf('%.1f', $v) : (string)$v;
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE);
    }
    return (string)$v;
}

function log_zprava(string $zprava, string $uroven = 'info'): void
{
    try {
        Db::q('INSERT INTO {p}log (cas, uroven, zprava) VALUES (UTC_TIMESTAMP(), ?, ?)', [$uroven, mb_substr($zprava, 0, 4000)]);
    } catch (Throwable $e) {
        error_log("[radar] $uroven: $zprava");
    }
}

/** Dočasný adresář aplikace (data/tmp), jinak systémový. */
function docasny_adresar(): string
{
    $d = RADAR_KOREN . '/data/tmp';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return is_dir($d) && is_writable($d) ? $d : sys_get_temp_dir();
}

/** Nastavení uložené v databázi (mění ho správce v administraci). */
class Nastaveni
{
    public static $cache = null;
}

function nastaveni(string $klic, ?string $vychozi = null): ?string
{
    if (Nastaveni::$cache === null) {
        Nastaveni::$cache = [];
        try {
            foreach (Db::vse('SELECT klic, hodnota FROM {p}nastaveni') as $r) {
                Nastaveni::$cache[$r['klic']] = $r['hodnota'];
            }
        } catch (Throwable $e) {
        }
    }
    return array_key_exists($klic, Nastaveni::$cache) ? Nastaveni::$cache[$klic] : $vychozi;
}

function nastav(string $klic, ?string $hodnota): void
{
    Db::q('REPLACE INTO {p}nastaveni (klic, hodnota) VALUES (?, ?)', [$klic, $hodnota]);
    Nastaveni::$cache = null;
}

/** Hodnota z kv_cache (serializovaná), nebo null. */
function kv_get(string $klic)
{
    $r = Db::radek('SELECT hodnota FROM {p}kv_cache WHERE klic = ? AND platnost > UTC_TIMESTAMP()', [sha1($klic)]);
    return $r ? unserialize((string)$r['hodnota']) : null;
}

function kv_set(string $klic, $hodnota, int $platnost_s): void
{
    try {
        Db::q('REPLACE INTO {p}kv_cache (klic, hodnota, platnost) VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? SECOND)',
            [sha1($klic), serialize($hodnota), $platnost_s]);
    } catch (PDOException $e) {
        // nevadí
    }
}

/**
 * Fatální chyba PHP (paměť, čas, chybějící funkce…) by jinak skončila bílou stránkou bez vysvětlení:
 * zobrazí krátké hlášení a zapíše ho do logu aplikace.
 */
function zachyt_fatalni_chyby(bool $html = true): void
{
    register_shutdown_function(function () use ($html) {
        $ch = error_get_last();
        if (!$ch || !in_array($ch['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $zprava = sprintf('%s (%s:%d)', $ch['message'], basename((string)$ch['file']), $ch['line']);
        try {
            log_zprava('Fatální chyba: ' . $zprava, 'chyba');
        } catch (Throwable $e) {
        }
        if (PHP_SAPI === 'cli' || !$html) {
            echo "\nFATÁLNÍ CHYBA: $zprava\n";
            return;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo "<div style='margin:20px;padding:12px 16px;border:1px solid #f3b8b1;background:#fdecea;color:#8a1c12;border-radius:8px;font:15px system-ui,sans-serif'>"
            . '<b>Chyba aplikace.</b> ' . htmlspecialchars($zprava, ENT_QUOTES, 'UTF-8')
            . '<br><small>Podrobnosti zjistí správce v diagnostice (diagnostika.php?klic=…).</small></div>';
    });
}

// datové moduly (obsahují konstanty a funkce, autoload by je nenačetl)
require_once __DIR__ . '/Odok.php';
require_once __DIR__ . '/Psp.php';
require_once __DIR__ . '/Esel.php';
