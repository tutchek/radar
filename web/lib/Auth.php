<?php
/**
 * Auth.php – přihlášení, relace, CSRF a oblíbené položky uživatelů.
 */

class Auth
{
    private static $uzivatel = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('radar_relace');
        session_set_cookie_params(['lifetime' => 0, 'path' => self::cesta(), 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
        if (!@session_start()) {
            // úložiště relací hostingu není zapisovatelné → vlastní složka data/relace
            $slozka = RADAR_KOREN . '/data/relace';
            if (!is_dir($slozka)) {
                @mkdir($slozka, 0770, true);
            }
            session_save_path($slozka);
            @session_start();
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
    }

    /**
     * Cesta aplikace v adrese, jak ji vidí prohlížeč (pro cookies a odkazy), např. '/' nebo '/radar/'.
     * Bere se z REQUEST_URI, ne ze SCRIPT_NAME: hosting jako WEDOS přepisuje doménu do podsložky
     * (domains/<doména>/) a SCRIPT_NAME pak obsahuje cestu, kterou prohlížeč nezná.
     */
    public static function cesta(): string
    {
        $cesta = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        if (substr($cesta, -1) !== '/') {
            $cesta = str_replace('\\', '/', dirname($cesta));
        }
        return rtrim($cesta, '/') . '/';
    }

    /** Uvolní zámek relace – dlouhý požadavek pak neblokuje ostatní stránky téhož uživatele. */
    public static function uvolni(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ?? '';
    }

    public static function overCsrf(?string $token): bool
    {
        return $token !== null && self::csrf() !== '' && hash_equals(self::csrf(), $token);
    }

    public static function uzivatel(): ?array
    {
        if (self::$uzivatel === false) {
            self::$uzivatel = null;
            if (empty($_SESSION['uid']) && isset($_COOKIE[self::COOKIE_TRVALE])) {
                self::obnovZTrvaleho();
            }
            if (!empty($_SESSION['uid'])) {
                $u = Db::radek('SELECT * FROM {p}uzivatel WHERE id = ? AND aktivni = 1', [$_SESSION['uid']]);
                if ($u && hash_equals((string)($_SESSION['otisk'] ?? ''), self::otisk($u))) {
                    self::$uzivatel = $u;
                } else {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$uzivatel;
    }

    // ---------------- trvalé přihlášení na zařízení ----------------

    const COOKIE_TRVALE = 'radar_trvale';

    /** Kolik dní platí trvalé přihlášení od posledního použití (konfigurace prihlaseni_dni, 0 = vypnuto). */
    public static function dniTrvalehoPrihlaseni(): int
    {
        return max(0, (int)Konfig::get('prihlaseni_dni', 30));
    }

    private static function cookieTrvale(string $hodnota, int $expirace): void
    {
        if (headers_sent()) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie(self::COOKIE_TRVALE, $hodnota, ['expires' => $expirace, 'path' => self::cesta(), 'httponly' => true,
            'samesite' => 'Lax', 'secure' => $https]);
        if ($hodnota === '') {
            unset($_COOKIE[self::COOKIE_TRVALE]);
        } else {
            $_COOKIE[self::COOKIE_TRVALE] = $hodnota;
        }
    }

    /** [selektor, ověřovač] z cookie, nebo null. */
    private static function castiCookie(): ?array
    {
        $c = (string)($_COOKIE[self::COOKIE_TRVALE] ?? '');
        return preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $c, $m) ? [$m[1], $m[2]] : null;
    }

    private static function vytvorTrvale(array $u): void
    {
        $dni = self::dniTrvalehoPrihlaseni();
        if ($dni <= 0) {
            return;
        }
        $selektor = bin2hex(random_bytes(12));
        $overovac = bin2hex(random_bytes(32));
        try {
            Db::q('INSERT INTO {p}trvale_prihlaseni (selektor, overovac, uzivatel_id, otisk, csrf, zarizeni, vytvoreno, pouzito, platnost)
                   VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? DAY)',
                [$selektor, hash('sha256', $overovac), (int)$u['id'], self::otisk($u), $_SESSION['csrf'] ?? null,
                    mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), $dni]);
        } catch (PDOException $e) {
            return;      // tabulka ještě není (databáze se doplní při dalším načtení stránky)
        }
        self::cookieTrvale("$selektor:$overovac", time() + $dni * 86400);
    }

    /** Relace vypršela, ale zařízení má platné trvalé přihlášení → tiše přihlásit. */
    private static function obnovZTrvaleho(): void
    {
        $casti = self::castiCookie();
        if ($casti === null) {
            self::cookieTrvale('', time() - 3600);
            return;
        }
        [$selektor, $overovac] = $casti;
        try {
            $r = Db::radek('SELECT t.*, (t.pouzito < UTC_TIMESTAMP() - INTERVAL 1 DAY) AS stare FROM {p}trvale_prihlaseni t
                            WHERE t.selektor = ? AND t.platnost > UTC_TIMESTAMP()', [$selektor]);
        } catch (PDOException $e) {
            return;
        }
        if (!$r || !hash_equals((string)$r['overovac'], hash('sha256', $overovac))) {
            if ($r) {      // známý selektor se špatným ověřovačem – záznam raději zrušit
                Db::q('DELETE FROM {p}trvale_prihlaseni WHERE selektor = ?', [$selektor]);
            }
            self::cookieTrvale('', time() - 3600);
            return;
        }
        $u = Db::radek('SELECT * FROM {p}uzivatel WHERE id = ? AND aktivni = 1', [$r['uzivatel_id']]);
        if (!$u || !hash_equals((string)$r['otisk'], self::otisk($u))) {
            // změněné heslo, zablokovaný nebo smazaný účet
            Db::q('DELETE FROM {p}trvale_prihlaseni WHERE selektor = ?', [$selektor]);
            self::cookieTrvale('', time() - 3600);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['otisk'] = self::otisk($u);
        if (!empty($r['csrf'])) {
            $_SESSION['csrf'] = $r['csrf'];       // otevřené stránky na zařízení dál fungují
        }
        if ((int)$r['stare']) {                   // klouzavá platnost – prodloužit nejvýš jednou denně
            $dni = max(1, self::dniTrvalehoPrihlaseni());
            Db::q('UPDATE {p}trvale_prihlaseni SET pouzito = UTC_TIMESTAMP(), platnost = UTC_TIMESTAMP() + INTERVAL ? DAY WHERE selektor = ?',
                [$dni, $selektor]);
            Db::q('UPDATE {p}uzivatel SET posledni_prihlaseni = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
            self::cookieTrvale("$selektor:$overovac", time() + $dni * 86400);
        }
    }

    /** Zruší trvalé přihlášení všech zařízení uživatele (smazání účtu apod.). */
    public static function zrusTrvaleUzivatele(int $uid): void
    {
        try {
            Db::q('DELETE FROM {p}trvale_prihlaseni WHERE uzivatel_id = ?', [$uid]);
        } catch (PDOException $e) {
        }
    }

    /** Otisk hesla – po změně hesla se ostatní relace odhlásí. */
    private static function otisk(array $u): string
    {
        return substr(hash('sha256', $u['heslo'] . $u['id']), 0, 16);
    }

    public static function uid(): ?int
    {
        $u = self::uzivatel();
        return $u ? (int)$u['id'] : null;
    }

    public static function jeAdmin(): bool
    {
        $u = self::uzivatel();
        return $u !== null && $u['role'] === 'admin';
    }

    private static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? '?'), 0, 45);
    }

    /** Přihlášení; vrací chybovou zprávu, nebo null při úspěchu. */
    public static function prihlas(string $login, string $heslo, bool $trvale = true): ?string
    {
        $pokusu = (int)Db::hodnota('SELECT COUNT(*) FROM {p}prihlaseni_pokus WHERE ip = ? AND cas > UTC_TIMESTAMP() - INTERVAL 15 MINUTE', [self::ip()]);
        if ($pokusu >= 10) {
            return 'Příliš mnoho neúspěšných pokusů – zkuste to za 15 minut.';
        }
        $u = Db::radek('SELECT * FROM {p}uzivatel WHERE login = ?', [trim($login)]);
        if (!$u || !password_verify($heslo, $u['heslo'])) {
            Db::q('INSERT INTO {p}prihlaseni_pokus (ip, cas) VALUES (?, UTC_TIMESTAMP())', [self::ip()]);
            return 'Neplatné přihlašovací jméno nebo heslo.';
        }
        if (!(int)$u['aktivni']) {
            return $u['posledni_prihlaseni'] === null ? 'Účet čeká na schválení správcem.' : 'Účet je zablokovaný.';
        }
        if (password_needs_rehash($u['heslo'], PASSWORD_DEFAULT)) {
            $u['heslo'] = password_hash($heslo, PASSWORD_DEFAULT);
            Db::q('UPDATE {p}uzivatel SET heslo = ? WHERE id = ?', [$u['heslo'], $u['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['otisk'] = self::otisk($u);
        Db::q('UPDATE {p}uzivatel SET posledni_prihlaseni = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
        Db::q('DELETE FROM {p}prihlaseni_pokus WHERE ip = ?', [self::ip()]);
        if ($trvale) {
            self::vytvorTrvale($u);
        }
        self::$uzivatel = false;
        return null;
    }

    /** Režim registrace (nastavuje správce): otevrena | schvaleni | vypnuta. */
    public static function rezimRegistrace(): string
    {
        $r = nastaveni('registrace', 'otevrena');
        return in_array($r, ['otevrena', 'schvaleni', 'vypnuta'], true) ? $r : 'otevrena';
    }

    /**
     * Registrace nového uživatele (role uživatel; při režimu „schvaleni“ neaktivní, dokud ho správce neschválí).
     * $past = skryté pole, které vyplní jen robot; $cas = čas zobrazení formuláře. Vrací chybu, nebo null.
     */
    public static function registruj(string $login, string $jmeno, string $email, string $heslo, string $heslo2, string $past, int $cas): ?string
    {
        $rezim = self::rezimRegistrace();
        if ($rezim === 'vypnuta') {
            return 'Registrace je vypnutá.';
        }
        if ($past !== '' || $cas <= 0 || time() - $cas < 3) {
            return 'Formulář se nepodařilo odeslat – zkuste to prosím znovu.';
        }
        $ip = 'reg:' . self::ip();
        if ((int)Db::hodnota('SELECT COUNT(*) FROM {p}prihlaseni_pokus WHERE ip = ? AND cas > UTC_TIMESTAMP() - INTERVAL 1 HOUR', [substr($ip, 0, 45)]) >= 5) {
            return 'Z této adresy proběhlo příliš mnoho registrací – zkuste to později.';
        }
        if (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $login)) {
            return 'Přihlašovací jméno smí obsahovat jen písmena bez diakritiky, číslice a znaky . _ @ - (2–64 znaků).';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Neplatný e-mail.';
        }
        if ($ch = self::kontrolaHesla($heslo)) {
            return $ch;
        }
        if ($heslo !== $heslo2) {
            return 'Hesla se neshodují.';
        }
        if (Db::hodnota('SELECT id FROM {p}uzivatel WHERE login = ?', [$login])) {
            return 'Toto přihlašovací jméno už někdo používá.';
        }
        Db::q('INSERT INTO {p}uzivatel (login, jmeno, email, heslo, role, aktivni, vytvoreno) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [$login, mb_substr($jmeno, 0, 190), mb_substr($email, 0, 190), password_hash($heslo, PASSWORD_DEFAULT), 'uzivatel', $rezim === 'otevrena' ? 1 : 0]);
        Db::q('INSERT INTO {p}prihlaseni_pokus (ip, cas) VALUES (?, UTC_TIMESTAMP())', [substr($ip, 0, 45)]);
        log_zprava(sprintf('Nová registrace: %s%s', $login, $rezim === 'schvaleni' ? ' (čeká na schválení)' : ''), 'info');
        return null;
    }

    public static function odhlas(): void
    {
        $casti = self::castiCookie();
        if ($casti !== null) {
            try {
                Db::q('DELETE FROM {p}trvale_prihlaseni WHERE selektor = ?', [$casti[0]]);
            } catch (PDOException $e) {
            }
        }
        if (isset($_COOKIE[self::COOKIE_TRVALE])) {
            self::cookieTrvale('', time() - 3600);
        }
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        self::$uzivatel = null;
    }

    /** Po změně vlastního hesla zůstane tato relace přihlášená. */
    public static function obnovOtisk(): void
    {
        self::$uzivatel = false;
        $u = Db::radek('SELECT * FROM {p}uzivatel WHERE id = ?', [$_SESSION['uid'] ?? 0]);
        if ($u) {
            $_SESSION['otisk'] = self::otisk($u);
            // trvalé přihlášení tohoto zařízení platí dál, ostatní zařízení se změnou hesla odhlásí
            $casti = self::castiCookie();
            if ($casti !== null) {
                try {
                    Db::q('UPDATE {p}trvale_prihlaseni SET otisk = ? WHERE selektor = ? AND uzivatel_id = ?', [self::otisk($u), $casti[0], $u['id']]);
                } catch (PDOException $e) {
                }
            }
        }
    }

    public static function zprava(string $text, string $typ = 'ok'): void
    {
        $_SESSION['zpravy'][] = [$typ, $text];
    }

    public static function zpravy(): array
    {
        $z = $_SESSION['zpravy'] ?? [];
        unset($_SESSION['zpravy']);
        return $z;
    }

    public static function kontrolaHesla(string $heslo): ?string
    {
        if (mb_strlen($heslo) < 8) {
            return 'Heslo musí mít aspoň 8 znaků.';
        }
        return null;
    }
}

/** Oblíbené materiály ODOK a sněmovní tisky přihlášeného uživatele. */
class Oblibene
{
    private $uid;
    private $cache = null;

    public function __construct(?int $uid)
    {
        $this->uid = $uid;
    }

    public function aktivni(): bool
    {
        return $this->uid !== null;
    }

    public static function klic(string $typ, $ident): string
    {
        $ident = trim((string)($ident ?? ''));
        return sprintf('%s:%s', $typ, $typ === 'material' ? mb_strtoupper($ident) : $ident);
    }

    private function nacti(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            if ($this->uid !== null) {
                foreach (Db::vse('SELECT * FROM {p}oblibene WHERE uzivatel_id = ?', [$this->uid]) as $r) {
                    $r['rok'] = $r['rok'] !== null ? json_decode($r['rok'], true) : null;
                    $this->cache[$r['klic']] = $r;
                }
            }
        }
        return $this->cache;
    }

    public function je(string $typ, $ident): bool
    {
        return isset($this->nacti()[self::klic($typ, $ident)]);
    }

    public function klice(): array
    {
        return array_fill_keys(array_keys($this->nacti()), true);
    }

    public function pocet(): int
    {
        return count($this->nacti());
    }

    /** Položky od naposledy přidané. */
    public function seznam(?string $typ = null): array
    {
        $v = [];
        foreach ($this->nacti() as $k => $r) {
            if ($typ === null || $r['typ'] === $typ) {
                $v[] = ['klic' => $k, 'typ' => $r['typ'], 'id' => $r['ident'], 'nazev' => $r['nazev'], 'rok' => $r['rok'],
                    'pridano' => $r['pridano'], 'poznamka' => $r['poznamka'], 'videno' => $r['videno']];
            }
        }
        usort($v, function ($a, $b) { return strcmp((string)$b['pridano'], (string)$a['pridano']); });
        return $v;
    }

    public function prepni(string $typ, $ident, string $nazev = '', $rok = null): bool
    {
        if ($this->uid === null) {
            throw new InvalidArgumentException('Oblíbené jsou jen pro přihlášené uživatele.');
        }
        if (!in_array($typ, ['material', 'tisk', 'predpis'], true) || trim((string)$ident) === '') {
            throw new InvalidArgumentException(sprintf('Neplatná položka oblíbených: %s %s', $typ, $ident));
        }
        $k = self::klic($typ, $ident);
        if ($this->je($typ, $ident)) {
            Db::q('DELETE FROM {p}oblibene WHERE uzivatel_id = ? AND klic = ?', [$this->uid, $k]);
            $v = false;
        } else {
            Db::q('INSERT INTO {p}oblibene (uzivatel_id, klic, typ, ident, nazev, rok, pridano, poznamka, videno) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)',
                [$this->uid, $k, $typ, explode(':', $k, 2)[1], mb_substr($nazev, 0, 2000), $rok === null ? null : json_encode($rok), date('Y-m-d H:i:s'), '']);
            $v = true;
        }
        $this->cache = null;
        return $v;
    }

    public function nastav(string $klic, array $hodnoty): void
    {
        if (!isset($this->nacti()[$klic])) {
            throw new InvalidArgumentException($klic);
        }
        foreach ($hodnoty as $sl => $h) {
            if (in_array($sl, ['poznamka', 'videno'], true)) {
                Db::q("UPDATE {p}oblibene SET $sl = ? WHERE uzivatel_id = ? AND klic = ?", [$h, $this->uid, $klic]);
                $this->cache[$klic][$sl] = $h;
            }
        }
    }
}
