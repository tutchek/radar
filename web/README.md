# § Legislativní radar – PHP verze pro webhosting

Webová aplikace pro sledování legislativního procesu v Česku. Na jednom místě spojuje otevřená data:

- **ODOK / eKLEP** – materiály vlády, jejich verze, připomínkové řízení a dokumenty,
- **e-Legislativa (eSeL)** – fáze a stavy podzákonných předpisů, číslo ve Sbírce, účinnost,
- **Poslanecká sněmovna a Senát** – sněmovní tisky, jejich stav, čtení a schůze, navázané senátní tisky.

Jde o převod původní lokální aplikace v Pythonu (`odok_web.py` a spol.) do PHP: stránky, výpočty
(etapy projednávání, lhůty Senátu, párování s e-Legislativou, podklady PLPV, analýza relevance)
i vzhled zůstaly stejné. Rozdíly jsou dané provozem na webhostingu:

| Python (lokálně) | PHP (webhosting) |
|---|---|
| vlastní server na `localhost:8765`, okno aplikace | běží pod webovým serverem hostingu (`index.php`) |
| data v paměti, cache v `%TEMP%` | data v databázi MariaDB / MySQL |
| stahování při startu a na pozadí | stahování a import skriptem `cron.php` (cron) |
| oblíbené v souboru `oblibene.json` | oblíbené v databázi, zvlášť pro každého uživatele |
| seznam výrazů `relevance_pravidla.xlsx` vedle programu | víc sad výrazů ve složce `pravidla/`, každý uživatel si vybere svou |
| bez přihlášení | přihlášení a registrace uživatelů, správa uživatelů (role správce / uživatel) |
| tlačítko **Obnovit data** stáhne vše hned | **Obnovit data** označí zdroje k obnově při nejbližším běhu cronu; správce může import spustit i ručně |

> Neoficiální nástroj. Není produktem Úřadu vlády ČR, Ministerstva vnitra ani Poslanecké
> sněmovny. Data mohou být neúplná nebo zpožděná; rozhodující jsou vždy původní zdroje.

## Požadavky

- PHP 8.0 nebo novější s rozšířeními `pdo_mysql`, `zip`, `zlib`, `mbstring`, `dom`
  a `curl` (doporučeno; jinak stačí `allow_url_fopen`) – na běžném hostingu bývají všechna zapnutá,
- MariaDB 10.2+ nebo MySQL 5.7+,
- hosting musí smět stahovat z internetu (odok.cz, odok.gov.cz, psp.cz, opendata.eselpoint.gov.cz),
- žádné další knihovny ani Composer – vše je napsané jen ve standardním PHP
  (čtení ZIP/UNL/JSON, Word .docx/.doc, PDF, Excel .xlsx).

## Instalace

1. V administraci hostingu založte databázi (MariaDB) a jejího uživatele.
2. Nahrajte obsah archivu na hosting, např. do složky `radar/`.
3. Otevřete v prohlížeči `https://vas-web.cz/radar/install.php`. Instalátor zkontroluje požadavky,
   zeptá se na přístup k databázi a účet správce, vytvoří tabulky a soubor `config.php`.
4. Na konci instalace lze data rovnou naplnit tlačítkem **Naplnit data teď**
   (import běží po částech, první naplnění trvá několik minut).
5. Nastavte cron (viz níže) a přihlaste se. Další uživatele přidáte v sekci **Správa**.

Pokud do složky nelze zapisovat, instalátor zobrazí obsah `config.php` k ručnímu nahrání.
Po instalaci se instalátor sám zablokuje; soubor `install.php` můžete smazat.

## Cron – plnění dat

Data plní `cron.php`. Letošní ročník ODOK, Sněmovna, Senát, e-Legislativa a názvy z e-Sbírky se stahují
dvakrát denně (po 7:00, kdy už jsou noční aktualizace venku, a po 19:00 jako pojistka, kdyby se ranní
aktualizace zpozdila); minulé ročníky ODOK se po importu už nestahují – jen
jednou po skončení roku (2. ledna) se dotáhne jejich konečná podoba (ručně je jde obnovit v Správa →
Data a import). Nezměněný soubor se znovu neimportuje – stačí tedy spouštět cron často, třeba každých 15 minut:

```
*/15 * * * * php /cesta/k/radar/cron.php
```

Hosting, který umí cron jen jako volání adresy:

```
https://vas-web.cz/radar/cron.php?klic=<cron_klic z config.php>
```

Přes adresu běží import s časovým rozpočtem 25 s; co se nestihne, doplní se při dalších voláních.
Velké soubory se čtou proudově, takže import vystačí i s pamětí běžného webhostingu.

| Příkaz | Význam |
|---|---|
| `php cron.php` | obnoví zdroje, kterým vypršela platnost (nebo je někdo označil k obnově) |
| `php cron.php --vse` | stáhne a naimportuje vše hned |
| `php cron.php --jen=psp,esel` | jen vybrané zdroje (`odok` = všechny ročníky, `odok:2026` = jeden) |
| `php cron.php --rozpocet=300` | skončí po 5 minutách, zbytek příště |
| `php cron.php --stav` | stav zdrojů |

Stav zdrojů, poslední běh cronu, ruční spuštění importu a log chyb najde správce ve **Správa → Data a import**.

Verze a připomínky materiálu a dokumenty pro náhled a analýzu relevance se stahují až při zobrazení
(jako v původní aplikaci) a ukládají se do cache v databázi (verze a připomínky 2 h, dokumenty natrvalo,
po 180 dnech se mažou). Podklady PLPV stahují historie verzí souběžně; aby se sestavily rychle,
lze jejich předběžné stažení cronem zapnout volbou `plpv_prednacist` (např. `['MF']`).

## Co umí

- **Materiály ODOK** – přehled podle předkladatelů, fulltextové hledání, detail materiálu
  s dokumenty, historickými verzemi a přehledem připomínek.
- **Sněmovní tisky** – stav tisku, data ukončení čtení, garančního výboru a Senátu (s 30denní lhůtou),
  pořady schůzí, následné tisky, vazba na eKLEP, senátní tisky.
- **Novelizace** – které sněmovní tisky mění nebo ruší daný předpis (např. `280/2009`), se stavem
  projednávání a výsledkem ve Sbírce; předpisy lze sledovat ⭐ a na úvodu stránky je přehled jejich
  právě projednávaných novel. Předpis lze hledat číslem (`280/2009` – funguje i ve vyhledávání nahoře),
  názvem nebo zkratkou (`daňový řád`, `DPH`, `NOZ`). Data: `sbirka.zip` ze Sněmovny (vazby tisk → předpis;
  senátní návrhy nejsou zahrnuty) a názvy a zkratky předpisů z otevřených dat e-Sbírky
  (`006PravniAktMetadata.json.gz`, zdroj „e-Sbírka – názvy předpisů“, jednou denně).
- **e-Legislativa** – fáze a stavy procesu, číslo ve Sbírce (proklik do e-Sbírky) a účinnost.
- **Podklady PLPV** – tabulky ve struktuře excelu Plánu legislativních prací vlády s kopírováním do Excelu.
- **Oblíbené ⭐** – sledované materiály a tisky s aktuálním stavem, hlášením změn 🔔 a poznámkou (pro každého uživatele zvlášť).
- **Analýza relevance** – u dokumentu (Word .docx/.doc i PDF) vyhledá výrazy ze sdíleného seznamu
  a ukáže, pro které útvary může být relevantní a proč.
- **Výběr období** – volební období Sněmovny nebo rok v něm.
- **Správa** – uživatelé (přidání, úprava, blokace, reset hesla, smazání), stav a obnova dat,
  seznam výrazů pro analýzu relevance (stažení / nahrání .xlsx), log chyb.

## Uživatelé a registrace

- Kdo nemá účet, se může zaregistrovat (odkaz u přihlášení a v hlavičce). Režim registrace nastaví správce
  ve **Správa → Uživatelé**: *otevřená* (účet funguje hned), *se schválením* (nový účet čeká, až ho správce
  schválí tlačítkem **Schválit**) nebo *vypnutá* (účty zakládá jen správce). Výchozí je otevřená.
- Registrace je chráněná proti robotům (skryté pole, minimální doba vyplnění) a omezená na 5 účtů z jedné
  adresy za hodinu.
- Správce může uživatele přidat, upravit (jméno, e-mail, role, sada výrazů, blokace, nové heslo) a smazat.
- Každý uživatel si v **Můj účet** upraví jméno a e-mail, změní heslo a zvolí sadu výrazů.
- **Zůstat přihlášen** (při přihlášení zaškrtnuto): zařízení zůstane přihlášené 30 dní od posledního použití
  (`prihlaseni_dni` v `config.php`, 0 = vypnuto). V databázi je jen otisk náhodného tokenu; přihlášení přestane
  platit odhlášením, změnou hesla (na ostatních zařízeních), zablokováním nebo smazáním účtu.

## Analýza relevance – sady výrazů

Sady výrazů jsou sešity Excelu ve složce **`pravidla/`** (chráněná `.htaccess`). Sad může být víc:

- **správce** je nahrává, stahuje, maže a jednu určí jako **základní** ve **Správa → Sady výrazů**
  (sadu lze do složky nahrát i přímo přes FTP – aplikace ji najde sama, název bude podle souboru),
- **přihlášený uživatel** si vybere svou sadu v **Můj účet**, nebo ji přepne přímo u výsledku analýzy
  (analýza se hned přepočítá),
- **nepřihlášený** uživatel a ti, kdo si nic nevybrali, používají základní sadu.

Při instalaci se základní sada založí ze vzoru `relevance_pravidla_vzor.xlsx` (soubor `pravidla/zakladni.xlsx`).
Zobrazované názvy sad nahraných přes administraci jsou v `pravidla/nazvy.json`.

| Příklad | Regulární výraz | 3101 | 3102 | … | Poznámka |
|---|---|---|---|---|---|
| daň z přidané hodnoty | `dan\w* z přidané hodnot\w*` | 1 | 0 | | |
| DPH | `\bDPH\b` | 1 | 0 | | |

- **Příklad** – jen pro lidi, k hledání se nepoužívá,
- **Regulární výraz** – podle něj se hledá (bez ohledu na velikost písmen),
- **sloupce útvarů** – 0 / 1, případně vyšší váha (nebo slovně ano, x, vysoká, střední, nízká),
- **Poznámka** – zobrazí se u nálezu jako vysvětlení,
- řádek, jehož výraz začíná `#`, se přeskočí.

## Když něco nefunguje

Otevřete `https://vas-web.cz/radar/diagnostika.php?klic=<cron_klic z config.php>` (nebo `php diagnostika.php`).
Ukáže nastavení PHP, připojení k databázi a obsah tabulek, zda právě běží import, spojení na zdroje
dat, stav zdrojů, poslední chyby a zkusí vykreslit hlavní stránky s měřením času. Fatální chyba PHP se
místo bílé stránky zobrazí jako hlášení a zapíše se do logu aplikace.

**Podklady PLPV hlásí, že se nepodařilo stáhnout historii verzí** („server ODOK ukončil spojení“,
„přetížený“): server ODOK při náporu požadavků z jedné adresy spojení odmítá. Aplikace neúspěšné
pokusy sama zopakuje (po 2 a 5 s, s menší souběžností) a co se jednou stáhne, zůstává uložené – další
načtení stránky dotáhne zbytek. Když se to opakuje, nastavte v `config.php` `'soubezne_stahovani' => 2`
a `'plpv_prednacist' => ['MF']`, aby historii verzí stahoval průběžně cron a stránka ji měla připravenou.

## Konfigurace

`config.php` vytvoří instalátor. Přehled všech voleb (platnost cache, nejstarší importovaný ročník
ODOK, prohlížení bez přihlášení, proxy, předběžné stažení verzí pro PLPV) je v `config.sample.php`.

## Struktura

| Soubor | Obsah |
|---|---|
| `index.php` | webová aplikace (všechny stránky, `index.php?p=…`) |
| `cron.php` | import dat (příkazová řádka nebo HTTP s klíčem) |
| `install.php` | instalace a aktualizace databáze |
| `diagnostika.php` | kontrola prostředí a hledání chyb (s klíčem `cron_klic`) |
| `lib/zaklad.php` | konfigurace, databáze, stahování s cache, pomocné funkce |
| `lib/Odok.php` | data ODOK (třídy `Odok`, `Material`) – port `odok.py` |
| `lib/Psp.php` | data Sněmovny a Senátu (`Psp`, `Tisk`, `Krok`, `BodSchuze`) – port `psp.py` |
| `lib/Esel.php` | e-Legislativa a párování na materiály – port `esel.py` |
| `lib/Plpv.php` | podklady PLPV – port `plpv.py` |
| `lib/Relevance.php`, `lib/PdfText.php` | analýza relevance, čtení Wordu a PDF – port `relevance.py`, `pdftext.py` |
| `lib/Import.php` | import zdrojů do databáze (proudové čtení JSON, formát UNL) |
| `lib/Web.php`, `lib/WebPsp.php` | stránky – port `odok_web.py` |
| `lib/Auth.php`, `lib/Admin.php` | přihlášení, registrace, oblíbené, správa |
| `lib/Pravidla.php` | sady výrazů ve složce `pravidla/` |
| `assets/` | vzhled: `stranka.html`, `styl.css`, `skript.js`, `favicon.svg` |
| `sql/schema.sql` | tabulky aplikace (tabulky Sněmovny vytváří instalátor podle schématu v `Psp.php`) |
| `pravidla/` | sady výrazů pro analýzu relevance (.xlsx, chráněno `.htaccess`) |
| `data/` | dočasné soubory importu, případně relace (chráněno `.htaccess`) |

Složky `lib/`, `sql/`, `data/` a `pravidla/` i soubor `config.php` jsou na Apache chráněné souborem `.htaccess`.
Na serveru Nginx zakažte přístup k nim v konfiguraci webu (nebo umístěte aplikaci tak, aby byly mimo
veřejnou část).

## Aktualizace aplikace

Nahrajte nové soubory (kromě `config.php`; složku `pravidla/` archiv nepřepíše – obsahuje jen ochranu).
Databáze se doplní sama při prvním načtení stránky (chybějící tabulky a sloupce, data zůstanou);
ručně to jde přes `install.php?klic=<cron_klic>` nebo `php install.php --aktualizace`.

## Licence

© 2026 Michal Tuláček. Šířeno pod licencí [MIT](LICENSE). Program se poskytuje bez záruky.
Data stahovaná aplikací nejsou součástí díla a podléhají podmínkám jejich poskytovatelů.
