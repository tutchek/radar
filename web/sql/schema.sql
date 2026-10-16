-- Legislativní radar – tabulky aplikace (MariaDB / MySQL, InnoDB, utf8mb4).
-- {p} = prefix tabulek z konfigurace. Tabulky dat Sněmovny (psp_*) vytváří instalátor podle Psp::SCHEMA.

CREATE TABLE IF NOT EXISTS {p}uzivatel (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  login VARCHAR(64) NOT NULL,
  jmeno VARCHAR(190) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  heslo VARCHAR(255) NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'uzivatel',
  aktivni TINYINT(1) NOT NULL DEFAULT 1,
  vytvoreno DATETIME NULL,
  posledni_prihlaseni DATETIME NULL,
  pravidla VARCHAR(190) NULL,
  UNIQUE KEY login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}nastaveni (
  klic VARCHAR(64) NOT NULL PRIMARY KEY,
  hodnota TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- trvalé přihlášení na zařízení: cookie „selektor:ověřovač“, v databázi jen otisk ověřovače
CREATE TABLE IF NOT EXISTS {p}trvale_prihlaseni (
  selektor CHAR(24) NOT NULL PRIMARY KEY,
  overovac CHAR(64) NOT NULL,
  uzivatel_id INT UNSIGNED NOT NULL,
  otisk VARCHAR(32) NOT NULL,
  csrf VARCHAR(64) NULL,
  zarizeni VARCHAR(255) NULL,
  vytvoreno DATETIME NOT NULL,
  pouzito DATETIME NOT NULL,
  platnost DATETIME NOT NULL,
  KEY uzivatel (uzivatel_id),
  KEY platnost (platnost)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}prihlaseni_pokus (
  ip VARCHAR(45) NOT NULL,
  cas DATETIME NOT NULL,
  KEY ip_cas (ip, cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}oblibene (
  uzivatel_id INT UNSIGNED NOT NULL,
  klic VARCHAR(80) NOT NULL,
  typ VARCHAR(16) NOT NULL,
  ident VARCHAR(64) NOT NULL,
  nazev TEXT NULL,
  rok VARCHAR(255) NULL,
  pridano DATETIME NULL,
  poznamka TEXT NULL,
  videno TEXT NULL,
  PRIMARY KEY (uzivatel_id, klic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}soubor (
  nazev VARCHAR(100) NOT NULL PRIMARY KEY,
  obsah LONGBLOB NULL,
  zmeneno DATETIME NULL,
  kdo VARCHAR(64) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}zdroj (
  klic VARCHAR(64) NOT NULL PRIMARY KEY,
  popis VARCHAR(255) NOT NULL DEFAULT '',
  stazeno DATETIME NULL,
  hash CHAR(40) NULL,
  stav VARCHAR(20) NOT NULL DEFAULT '',
  zprava TEXT NULL,
  radku INT NULL,
  trvani_s FLOAT NULL,
  pokus DATETIME NULL,
  vynutit TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cas DATETIME NOT NULL,
  uroven VARCHAR(10) NOT NULL,
  zprava TEXT NOT NULL,
  KEY cas (cas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}http_cache (
  url_hash CHAR(40) NOT NULL PRIMARY KEY,
  url TEXT NOT NULL,
  stazeno DATETIME NOT NULL,
  velikost INT UNSIGNED NOT NULL DEFAULT 0,
  obsah LONGBLOB NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}kv_cache (
  klic CHAR(40) NOT NULL PRIMARY KEY,
  hodnota MEDIUMBLOB NULL,
  platnost DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- index čísel jednacích připomínek (plní se při zobrazení připomínek materiálu)
CREATE TABLE IF NOT EXISTS {p}pripominky_cj (
  norm VARCHAR(190) NOT NULL,
  pid VARCHAR(32) NOT NULL,
  poradi INT NOT NULL DEFAULT 0,
  cj VARCHAR(255) NOT NULL,
  org VARCHAR(64) NOT NULL DEFAULT '',
  org_nazev VARCHAR(255) NOT NULL DEFAULT '',
  typ VARCHAR(100) NOT NULL DEFAULT '',
  KEY norm (norm),
  KEY pid (pid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------- ODOK
-- jeden řádek = materiál v jednom ročníku datasetu (materiál může být ve více ročnících)
CREATE TABLE IF NOT EXISTS {p}odok_material (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pid VARCHAR(32) NULL,
  rok SMALLINT NOT NULL,
  poradi INT NOT NULL,
  nazev TEXT NULL,
  cj VARCHAR(255) NULL,
  cj_ova VARCHAR(100) NULL,
  predkladatel VARCHAR(255) NULL,
  predkladatel_zkratka VARCHAR(64) NULL,
  typ VARCHAR(255) NULL,
  typ_id VARCHAR(16) NULL,
  stav VARCHAR(255) NULL,
  stav_id VARCHAR(16) NULL,
  datum VARCHAR(64) NULL,
  datum_vytvoreni DATETIME NULL,
  datum_autorizace DATETIME NULL,
  hledani MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  idtexty MEDIUMTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  data MEDIUMBLOB NULL,
  UNIQUE KEY pid_rok (pid, rok),
  KEY rok_poradi (rok, poradi),
  KEY zkratka (predkladatel_zkratka)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}odok_cj (
  norm VARCHAR(255) NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  rok SMALLINT NOT NULL,
  poradi INT NOT NULL,
  KEY norm (norm(100)),
  KEY material (material_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- ---------------------------------------------------------------- e-Legislativa
CREATE TABLE IF NOT EXISTS {p}esel_navrh (
  id BIGINT NOT NULL PRIMARY KEY,
  poradi INT NOT NULL,
  identifikator VARCHAR(64) NULL,
  nazev TEXT NULL,
  nazev_aktu TEXT NULL,
  norm_nazev TEXT NULL,
  cj_ova VARCHAR(100) NULL,
  norm_ova VARCHAR(32) NULL,
  druh VARCHAR(64) NULL,
  typ_procesu VARCHAR(255) NULL,
  citace VARCHAR(100) NULL,
  datum_vyhlaseni DATETIME NULL,
  datum_schvaleni DATETIME NULL,
  ucinnost VARCHAR(255) NULL,
  kompatibilita_eu VARCHAR(255) NULL,
  termin_implementace DATETIME NULL,
  cj_predkladatele TEXT NULL,
  KEY norm_ova (norm_ova),
  KEY druh (druh),
  KEY identifikator (identifikator)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}esel_cj (
  norm VARCHAR(100) NOT NULL,
  navrh_id BIGINT NOT NULL,
  poradi INT NOT NULL,
  KEY norm (norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS {p}esel_faze (
  id BIGINT NOT NULL,
  navrh_id BIGINT NOT NULL,
  idx INT NOT NULL,
  posledni TINYINT(1) NOT NULL DEFAULT 1,
  nazev VARCHAR(255) NULL,
  od DATETIME NULL,
  do DATETIME NULL,
  KEY id (id),
  KEY navrh (navrh_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

CREATE TABLE IF NOT EXISTS {p}esel_stav (
  faze_id BIGINT NOT NULL,
  navrh_id BIGINT NOT NULL,
  idx INT NOT NULL,
  nazev VARCHAR(255) NULL,
  od DATETIME NULL,
  do DATETIME NULL,
  KEY navrh (navrh_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;

-- ---------------------------------------------------------------- novely podzákonných i ostatních předpisů
-- e-Legislativa: návrh → předpis, jehož úplné znění s vyznačením změn návrh obsahuje (měněný předpis)
CREATE TABLE IF NOT EXISTS {p}esel_novela (
  navrh_id BIGINT NOT NULL,
  predpis_cislo INT NOT NULL,
  predpis_rok INT NOT NULL,
  PRIMARY KEY (navrh_id, predpis_rok, predpis_cislo),
  KEY predpis (predpis_rok, predpis_cislo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- e-Sbírka: vyhlášený předpis (novela) → předpis, který mění / ruší (z konsolidačních vazeb)
CREATE TABLE IF NOT EXISTS {p}esb_novela (
  novela_cislo INT NOT NULL,
  novela_rok INT NOT NULL,
  predpis_cislo INT NOT NULL,
  predpis_rok INT NOT NULL,
  rusi TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (novela_rok, novela_cislo, predpis_rok, predpis_cislo),
  KEY predpis (predpis_rok, predpis_cislo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
