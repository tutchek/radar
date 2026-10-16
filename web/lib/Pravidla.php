<?php
/**
 * Pravidla.php – sady výrazů pro analýzu relevance.
 *
 * Každá sada je sešit Excelu (.xlsx) ve složce pravidla/ (chráněná .htaccess). Správce sady nahrává
 * a maže v administraci (nebo je nahraje přímo přes FTP – aplikace je najde sama) a jednu určí jako
 * základní. Přihlášený uživatel si v profilu nebo u výsledku analýzy vybere, kterou sadu používá;
 * nepřihlášený má vždy základní.
 */

class SadyPravidel
{
    const ZAKLADNI = 'zakladni.xlsx';

    public static function slozka(): string
    {
        return RADAR_KOREN . '/pravidla';
    }

    /** Založí složku (s ochranou) a v ní základní sadu – ze starší verze v databázi, jinak ze vzoru. */
    public static function zajisti(): void
    {
        $d = self::slozka();
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        if (!is_file("$d/.htaccess")) {
            @file_put_contents("$d/.htaccess", "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
        if (glob("$d/*.xlsx")) {
            return;
        }
        $obsah = null;
        try {     // verze 1.0 ukládala jeden seznam do databáze
            $obsah = Db::hodnota('SELECT obsah FROM {p}soubor WHERE nazev = ?', ['relevance_pravidla.xlsx']);
        } catch (Throwable $e) {
        }
        if (!$obsah && is_file(RADAR_KOREN . '/relevance_pravidla_vzor.xlsx')) {
            $obsah = file_get_contents(RADAR_KOREN . '/relevance_pravidla_vzor.xlsx');
        }
        if ($obsah && @file_put_contents($d . '/' . self::ZAKLADNI, $obsah) === false) {
            throw new RelevanceChyba("Do složky $d nelze zapisovat – nastavte jí na hostingu práva pro zápis.");
        }
    }

    /** Název souboru z názvu sady: 'Odbor 31 – daně' → 'odbor-31-dane.xlsx'. */
    public static function nazev_souboru(string $nazev): string
    {
        $t = preg_replace('/\.xlsx$/i', '', trim($nazev));
        $t = trim(preg_replace('/[^a-z0-9_]+/', '-', bez_diakritiky($t)), '-');
        return ($t === '' ? 'sada' : mb_substr($t, 0, 80)) . '.xlsx';
    }

    /** Je to platný soubor sady ve složce? (ochrana proti cestám typu ../) */
    public static function existuje(?string $soubor): bool
    {
        return $soubor !== null && preg_match('/^[a-z0-9_-]+\.xlsx$/', $soubor) && is_file(self::slozka() . '/' . $soubor);
    }

    public static function cesta(string $soubor): string
    {
        if (!self::existuje($soubor)) {
            throw new RelevanceChyba("Sada výrazů „{$soubor}“ neexistuje.");
        }
        return self::slozka() . '/' . $soubor;
    }

    /** Zobrazované názvy sad (nazvy.json ve složce; soubor nahraný přes FTP má název podle souboru). */
    private static function nazvy(): array
    {
        $f = self::slozka() . '/nazvy.json';
        $v = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($v) ? $v : [];
    }

    private static function ulozNazvy(array $nazvy): void
    {
        @file_put_contents(self::slozka() . '/nazvy.json', json_encode($nazvy, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public static function popis(string $soubor): string
    {
        $n = self::nazvy();
        if (isset($n[$soubor]) && $n[$soubor] !== '') {
            return (string)$n[$soubor];
        }
        return $soubor === self::ZAKLADNI ? 'Základní' : preg_replace('/\.xlsx$/', '', $soubor);
    }

    /** [soubor => ['soubor', 'nazev', 'velikost', 'zmeneno' (timestamp)]] podle názvu. */
    public static function seznam(): array
    {
        self::zajisti();
        $v = [];
        foreach (glob(self::slozka() . '/*.xlsx') ?: [] as $c) {
            $s = basename($c);
            if (preg_match('/^[a-z0-9_-]+\.xlsx$/', $s)) {
                $v[$s] = ['soubor' => $s, 'nazev' => self::popis($s), 'velikost' => filesize($c), 'zmeneno' => filemtime($c)];
            }
        }
        ksort($v, SORT_STRING);
        return $v;
    }

    /** Základní sada (nastavení správce; jinak zakladni.xlsx; jinak první). */
    public static function vychozi(): string
    {
        $s = self::seznam();
        $nast = nastaveni('pravidla_vychozi');
        if ($nast && isset($s[$nast])) {
            return $nast;
        }
        if (isset($s[self::ZAKLADNI])) {
            return self::ZAKLADNI;
        }
        if (!$s) {
            throw new RelevanceChyba('Ve složce pravidla/ není žádná sada výrazů – nahrajte ji v administraci.');
        }
        return array_key_first($s);
    }

    /** Sada, kterou používá uživatel (nepřihlášený → základní). */
    public static function pro_uzivatele(?array $u): string
    {
        if ($u && self::existuje($u['pravidla'] ?? null)) {
            return $u['pravidla'];
        }
        return self::vychozi();
    }

    /** Uloží nahranou sadu (po kontrole, že jde přečíst). Vrací název souboru. */
    public static function uloz(string $nazev, string $obsah, bool $prepsat): string
    {
        self::zajisti();
        Relevance::zpracuj(Dokumenty::nacti_xlsx($obsah));
        $soubor = self::nazev_souboru($nazev);
        $cil = self::slozka() . '/' . $soubor;
        if (is_file($cil) && !$prepsat) {
            throw new RelevanceChyba("Sada „" . self::popis($soubor) . "“ už existuje – zaškrtněte přepsání, nebo zvolte jiný název.");
        }
        $tmp = $cil . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $obsah) === false || !@rename($tmp, $cil)) {
            @unlink($tmp);
            throw new RelevanceChyba('Sadu se nepodařilo uložit – složka pravidla/ není zapisovatelná.');
        }
        $nazvy = self::nazvy();
        $nazvy[$soubor] = mb_substr(preg_replace('/\.xlsx$/i', '', trim($nazev)), 0, 120);
        self::ulozNazvy($nazvy);
        return $soubor;
    }

    public static function smaz(string $soubor): void
    {
        $c = self::cesta($soubor);
        if ($soubor === self::vychozi()) {
            throw new RelevanceChyba('Základní sadu nelze smazat – nejdřív určete jinou jako základní.');
        }
        if (!@unlink($c)) {
            throw new RelevanceChyba('Soubor se nepodařilo smazat.');
        }
        Db::q('UPDATE {p}uzivatel SET pravidla = NULL WHERE pravidla = ?', [$soubor]);
        $nazvy = self::nazvy();
        unset($nazvy[$soubor]);
        self::ulozNazvy($nazvy);
    }

    /** Počet uživatelů, kteří sadu výslovně používají. */
    public static function pocty_uzivatelu(): array
    {
        $v = [];
        foreach (Db::vse('SELECT pravidla, COUNT(*) AS n FROM {p}uzivatel WHERE pravidla IS NOT NULL GROUP BY pravidla') as $r) {
            $v[$r['pravidla']] = (int)$r['n'];
        }
        return $v;
    }

    /** <select> sad výrazů (prázdná volba = základní). */
    public static function vyber(string $jmeno, ?string $vybrana, string $navic = ''): string
    {
        $vychozi = self::vychozi();
        $h = sprintf("<select name='%s'%s><option value=''%s>výchozí: %s</option>", e($jmeno), $navic,
            ($vybrana === null || $vybrana === '') ? ' selected' : '', e(self::popis($vychozi)));
        foreach (self::seznam() as $s => $info) {
            $h .= sprintf("<option value='%s'%s>%s</option>", e($s), $s === $vybrana ? ' selected' : '', e($info['nazev']));
        }
        return $h . '</select>';
    }
}
