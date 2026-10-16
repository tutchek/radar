# § Legislativní radar

[![Licence: MIT](https://img.shields.io/badge/licence-MIT-blue.svg)](LICENSE)

Lokální webová aplikace pro sledování legislativního procesu v Česku. Na jednom místě
spojuje otevřená data:

- **ODOK / eKLEP** – materiály vlády, jejich verze, připomínkové řízení a dokumenty,
- **e-Legislativa (eSeL)** – fáze a stavy podzákonných předpisů, číslo ve Sbírce, účinnost,
- **Poslanecká sněmovna a Senát** – sněmovní tisky, jejich stav, čtení a schůze, navázané senátní tisky.

Aplikace běží na vašem počítači, data stahuje z veřejných zdrojů a zobrazuje je v prohlížeči
na `http://localhost:8765/`. Potřebuje jen Python, žádné další knihovny.

> Neoficiální nástroj. Není produktem Úřadu vlády ČR, Ministerstva vnitra ani Poslanecké
> sněmovny. Data mohou být neúplná nebo zpožděná; rozhodující jsou vždy původní zdroje.

## Co umí

- **Materiály ODOK** – přehled podle předkladatelů, fulltextové hledání, detail materiálu
  s dokumenty, historickými verzemi (MPŘ → PK → JV → PV) a přehledem připomínek
  (zásadní / doporučující / bez připomínek, kdo se nevyjádřil).
- **Sněmovní tisky** – stav tisku, data ukončení 1. čtení, garančního výboru, 2. a 3. čtení
  a Senátu, pořady schůzí, následné tisky s odkazy na psp.cz, vazba na materiál v eKLEPu,
  senátní tisky (číslo, stav, proklik na senat.cz).
- **e-Legislativa** – k vyhláškám a nařízením vlády fáze a stavy procesu, číslo ve Sbírce
  (s proklikem do e-Sbírky) a účinnost; odkazy na veřejný portál i pracovní e-Legislativu.
- **Podklady PLPV** – tabulky ve struktuře excelu Plánu legislativních prací vlády
  (zákony, vyhlášky, nařízení, publikované ve Sbírce) za volební období nebo rok,
  s kopírováním řádků přímo do Excelu.
- **Oblíbené ⭐** – sledované materiály a tisky na jedné stránce s aktuálním stavem
  ze všech zdrojů, upozorněním na změnu 🔔 a vlastní poznámkou.
- **Analýza relevance** – u dokumentu (Word .docx/.doc i PDF) vyhledá výrazy ze sdíleného
  seznamu v Excelu a ukáže, pro které útvary může být dokument relevantní a proč.
- **Výběr období** – volební období Sněmovny nebo jeden rok v něm; podle toho se načtou
  ročníky ODOK i sněmovní tisky.
- Tabulky s řazením kliknutím na záhlaví, stránkováním, filtrem a ukotveným záhlavím
  a prvním sloupcem (jako „ukotvit příčky“ v Excelu).

## Požadavky

- Python 3.8 nebo novější (vyvíjeno a testováno na 3.11) – stačí běžná instalace
  z [python.org](https://www.python.org/), žádné balíčky přes pip.
- Windows je hlavní cílová platforma (stahování přes firemní proxy s přihlášením účtem
  Windows). Na Linuxu a macOS aplikace běží také, stahuje přes `urllib`.
- Přístup k internetu na adresy zdrojů uvedené níže.

## Spuštění

```
git clone <adresa-repozitáře>
cd <složka>
py odok_web.py
```

Otevře se malé okno aplikace s tlačítky **Otevřít v prohlížeči**, **Obnovit data**
a **Ukončit** a s přehledem stáří stažených dat. Na Windows lze aplikaci spustit i dvojklikem
na `legislativni_radar.pyw` (bez černého okna konzole).

První spuštění chvíli trvá – stahují se datové sady (ODOK po ročnících, Sněmovna, e-Legislativa).
Data Sněmovny a e-Legislativy se načítají na pozadí, stránky ODOK jsou k dispozici hned.

### Volby příkazové řádky

| Volba | Význam |
|---|---|
| `--port 8765` | port webového serveru (při obsazení se zkusí další) |
| `--host 0.0.0.0` | zpřístupnit aplikaci i z jiných počítačů v síti (výchozí jen tento počítač) |
| `--rok 2025 --rok 2026` | ročníky ODOK načtené při startu (výchozí celé aktuální volební období) |
| `--obdobi 10` | volební období Sněmovny (výchozí aktuální) |
| `--sit windows,powershell,python` | způsoby stahování v pořadí, v jakém se zkoušejí |
| `--pravidla-relevance cesta.xlsx` | jiný seznam výrazů pro analýzu relevance |
| `--bez-okna` | bez okna aplikace, server běží v konzoli a rovnou otevře prohlížeč |
| `--bez-prohlizece` | s `--bez-okna` neotevírat prohlížeč |
| `-v` | podrobný výpis (stahování, dotazy) |

## Síť a proxy

Ve firemní síti s proxy, která vyžaduje přihlášení účtem Windows, běžný Python neprojde.
Aplikace proto stahuje postupně těmito způsoby:

1. **windows** – systémová knihovna Windows (urlmon/WinINet) přes `ctypes`; použije nastavení
   proxy z Windows včetně PAC skriptu a přihlásí se automaticky,
2. **powershell** – .NET `WebClient` s výchozími přihlašovacími údaji,
3. **python** – `urllib` s proxy z proměnných prostředí nebo z nastavení Windows.

Způsob, který zafunguje, se pak zkouší jako první. Test spojení: `py odok.py --diagnostika`.

## Data a cache

Stažená data se ukládají do dočasné složky systému (`%TEMP%`) a znovu se stahují až po
uplynutí platnosti. Při výpadku sítě se použije i starší kopie.

| Zdroj | Adresa | Cache | Platnost |
|---|---|---|---|
| ODOK – datové sady po ročnících | `https://www.odok.cz/opendata/dataset/odok/` | `%TEMP%\odok_cache` | 2 h |
| ODOK – verze a připomínky materiálu | `https://www.odok.gov.cz/portal/veklep/material/…/json` | `%TEMP%\odok_cache` | 2 h |
| Dokumenty pro analýzu relevance | `https://www.odok.cz/portal/services/download/attachment/…` | `%TEMP%\odok_cache` | bez omezení (dokumenty se nemění) |
| Poslanecká sněmovna a Senát | `https://www.psp.cz/eknih/cdrom/opendata/` (tisky, schuze, poslanci, se_tisk) | `%TEMP%\psp_cache` | 2 h |
| e-Legislativa | `https://opendata.eselpoint.gov.cz/datove-sady-elegislativa/` | `%TEMP%\esel_cache` | 12 h |

Běžící aplikace prošlá data obnovuje sama; tlačítko **Obnovit data** stáhne vše hned.

**Oblíbené** se ukládají do `%APPDATA%\LegislativniRadar\oblibene.json`
(na Linuxu/macOS `~/.legislativni_radar/oblibene.json`).

## Analýza relevance

Seznam výrazů je `relevance_pravidla.xlsx` ve složce s programem (jiné umístění:
`--pravidla-relevance` nebo proměnná prostředí `RADAR_RELEVANCE`). Je určen ke sdílení
mezi uživateli – soubor se načítá při každé analýze znovu.

V repozitáři je jen **vzor** `relevance_pravidla_vzor.xlsx`. Při první analýze se z něj
vytvoří `relevance_pravidla.xlsx` (pokud ještě neexistuje) a dál se upravuje už jen ten.
Vzor se při aktualizaci programu přepisuje, vlastní seznam zůstává netknutý
(a je uvedený v `.gitignore`).

| Příklad | Regulární výraz | 3101 | 3102 | … | Poznámka |
|---|---|---|---|---|---|
| daň z přidané hodnoty | `dan\w* z přidané hodnot\w*` | 1 | 0 | | |
| DPH | `\bDPH\b` | 1 | 0 | | |

- **Příklad** – jen pro lidi, k hledání se nepoužívá,
- **Regulární výraz** – podle něj se hledá (bez ohledu na velikost písmen),
- **sloupce útvarů** – 0 / 1, případně vyšší váha; útvary lze libovolně přidávat,
- **Poznámka** – zobrazí se u nálezu jako vysvětlení,
- řádek, jehož výraz začíná `#`, se přeskočí.

Vzor obsahuje jen ukázkové útvary – ve vlastním seznamu je přepište na skutečné.
Z příkazové řádky: `py relevance.py dokument.docx` (také `.doc` a `.pdf`).

## Struktura projektu

| Soubor | Obsah |
|---|---|
| `odok_web.py` | webový server (třída `OdokServer`, singleton) a skládání stránek |
| `odok_okno.py` | okno aplikace (tkinter) se stavem dat |
| `legislativni_radar.pyw` | spouštěč bez okna konzole |
| `web/` | vzhled stránek: `stranka.html` (kostra), `styl.css`, `skript.js`, `favicon.svg` |
| `odok.py` | třída `Odok` – data ODOK; `Stahovani` – stahování s cache a proxy |
| `psp.py` | třída `Psp` – data Poslanecké sněmovny (tisky, stavy, etapy, schůze) |
| `esel.py` | třída `Esel` – data e-Legislativy a párování na materiály ODOK |
| `plpv.py` | sestavení podkladů pro excel PLPV |
| `relevance.py` | analýza relevance dokumentů pro útvary (Word .docx/.doc, PDF přes pdftext.py) |
| `pdftext.py` | čtení textu z PDF (jen standardní knihovna) |
| `relevance_pravidla_vzor.xlsx` | vzor seznamu výrazů pro analýzu relevance (vlastní seznam se z něj založí při prvním použití) |
| `odok_analyza.py`, `psp_analyza.py`, `senat_analyza.py`, `esel_analyza.py`, `esel_vazby.py` | diagnostika struktury zdrojových dat |

Moduly `psp.py`, `esel.py`, `plpv.py`, `relevance.py` a `pdftext.py` jsou volitelné –
když některý chybí, aplikace běží bez příslušné části.

### Použití tříd v kódu

```python
from odok import Odok
from psp import Psp
from esel import Esel

odok = Odok(roky=[2025, 2026])
m = odok.material("ALBSDW3HDEWG")           # PID, čj předkladatele nebo čj ÚV
print(m.nazev, m.stav, m.cj_ova)
for d in m.dokumenty():
    print(d["old_name"], m.url_dokumentu(d))

psp = Psp()                                  # aktuální volební období
t = psp.tisk_k_materialu(m.pid)              # sněmovní tisk k materiálu (nebo None)
if t:
    print(t.cislo, t.stav, t.etapy())

esel = Esel()
nalez = esel.k_materialu(m)                  # (návrh, způsob párování, jistota) nebo None
```

## Úpravy vzhledu

Kostra stránky, styly a skripty jsou ve složce `web/`. Soubory se načítají při změně,
úpravy se projeví po obnovení stránky v prohlížeči (Ctrl+F5) bez restartu aplikace.
Obsah jednotlivých stránek (tabulky, karty) skládají metody `stranka_*` a `cast_*`
v `odok_web.py`.

## Licence

© 2026 Michal Tuláček. Šířeno pod licencí [MIT](LICENSE) – program smíte volně používat,
upravovat a šířit (i komerčně), pokud zachováte upozornění o autorských právech a text licence.
Program se poskytuje bez záruky.

Data stahovaná aplikací nejsou součástí díla a podléhají podmínkám jejich poskytovatelů.
