# -*- coding: utf-8 -*-
"""
odok_web.py – Legislativní radar: lokální webová aplikace pro sledování legislativního
procesu – ODOK (eKLEP), e-Legislativa a Poslanecká sněmovna na jednom místě.

Používá jen standardní knihovnu Pythonu a třídu Odok z odok.py
(soubor musí ležet ve stejné složce nebo být importovatelný).

Spuštění z příkazové řádky:

    py odok_web.py                  # spustí server a zobrazí okno aplikace
    pyw odok_web.py                 # totéž bez černého okna konzole
    py odok_web.py --bez-okna       # jen konzole, rovnou otevře prohlížeč
    py odok_web.py --port 8080 --rok 2025 --rok 2026
    py odok_web.py --bez-prohlizece

Použití z kódu (singleton – v celém programu je jen jedna instance):

    from odok_web import OdokServer

    server = OdokServer(port=8765)        # první volání vytvoří instanci
    server.spust(na_pozadi=True)          # běží ve vlákně, program pokračuje
    ...
    OdokServer() is server                # True – vždy stejný objekt
    OdokServer.instance().url             # 'http://127.0.0.1:8765/'
    server.zastav()

Stránky:
    /                        přehled předkladatelů + hledání
    /ministerstvo?z=MZD      materiály daného předkladatele
    /hledej?q=...            PID / čj → rovnou detail, jinak fulltext
    /material?id=PID|čj      detail materiálu (dokumenty, verze, připomínky)
    /obnov                   vynucené stažení čerstvých dat (ODOK, Sněmovna, e-Legislativa)
    /tisky                   návrhy zákonů ve Sněmovně (aktuální volební období)
    /tisk?id=35              detail sněmovního tisku (i id:<id_tisk>, PID, OVA čj)
    /schuze                  schůze Sněmovny, /schuze?c=35 pořad schůze
    /plpv?r=MF&rok=2026      podklady pro excel PLPV (zákony, vyhlášky, nařízení, Sbírka)
    /oblibene                oblíbené materiály a tisky s hlášením změn

Vzhled stránek je ve složce web/ (stranka.html, styl.css, skript.js, favicon.svg);
HTML jednotlivých částí stránek skládají metody třídy OdokServer.

Sněmovní část potřebuje psp.py; když chybí, aplikace běží jen s daty ODOK.
Data Sněmovny se načítají na pozadí po startu serveru.
e-Legislativa (esel.py, volitelná) doplňuje u vyhlášek a nařízení fáze, stavy, číslo ve Sbírce
a účinnost; primární zůstávají data ODOK.
"""

import datetime as _dt
from collections import Counter
import html
import json
import http.cookies
import os
import re
import string
import sys
import threading
import time
import traceback
import urllib.parse
import webbrowser
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import odok as _odok
from odok import Odok, OdokChyba

POTREBNA_VERZE_ODOK = (1, 5)
_verze = tuple(int(x) for x in getattr(_odok, "__version__", "0").split("."))
if _verze < POTREBNA_VERZE_ODOK:
    raise ImportError(
        "odok_web.py potřebuje odok.py ve verzi %s nebo novější, načtena je %s (%s).\n"
        "Nahraďte odok.py novou verzí."
        % (".".join(map(str, POTREBNA_VERZE_ODOK)), getattr(_odok, "__version__", "stará"),
           _odok.__file__))

try:
    import psp as _psp
    from psp import fmt_datum
except ImportError:  # sněmovní část je volitelná
    _psp = None

    def fmt_datum(d):
        return "" if d is None else str(d)

try:
    import plpv as _plpv
except ImportError:  # podklady PLPV jsou volitelné
    _plpv = None

try:
    import relevance as _relevance
except ImportError:  # analýza relevance je volitelná
    _relevance = None

try:
    import esel as _esel
except ImportError:  # e-Legislativa je volitelná (kontext k vyhláškám a nařízením)
    _esel = None

__all__ = ["OdokServer", "Oblibene", "NAZEV_APLIKACE"]

NAZEV_APLIKACE = "Legislativní radar"

# volební období PS pro výběr v liště, dokud se nenačtou data Sněmovny: číslo → (rok od, rok do)
ZNAMA_OBDOBI = {10: (2025, None), 9: (2021, 2025), 8: (2017, 2021), 7: (2013, 2017)}
# začátky období (volby do PS) pro přesné hranice, než se načtou data Sněmovny
ZACATKY_OBDOBI = {10: _dt.date(2025, 10, 4), 9: _dt.date(2021, 10, 9), 8: _dt.date(2017, 10, 21),
                  7: _dt.date(2013, 10, 26), 6: _dt.date(2010, 5, 29)}
PRVNI_ROK_ODOK = 2016      # nejstarší ročník datasetu ODOK nabízený ve výběru


def roky_obdobi(od, do):
    """Ročníky ODOK, které pokrývají volební období (rok od–do, do=None → dosud)."""
    dnes = _dt.date.today().year
    return list(range(max(od, PRVNI_ROK_ODOK), min(do or dnes, dnes) + 1)) or [dnes]
LOGO = "§"            # znak před názvem v liště a v okně
HVEZDA, HVEZDA_PRAZDNA = "⭐", "☆"

# Vzhled a chování stránek leží v samostatných souborech ve složce web/ vedle programu:
#   stranka.html – kostra stránky (string.Template), styl.css – vzhled, skript.js – chování,
#   favicon.svg – ikona. Soubory se čtou při každé změně, úpravy se projeví po obnovení stránky.
WEB_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "web")
STATICKE_TYPY = {".css": "text/css; charset=utf-8", ".js": "text/javascript; charset=utf-8",
                 ".svg": "image/svg+xml", ".png": "image/png", ".ico": "image/x-icon"}
_sablony = {}
_zamek_sablon = threading.Lock()


def soubor_webu(nazev):
    """Obsah souboru ze složky web/ (text; v paměti, dokud se soubor nezmění)."""
    cesta = os.path.join(WEB_DIR, nazev)
    try:
        mtime = os.path.getmtime(cesta)
    except OSError:
        raise OdokChyba("Chybí soubor %s – složka web/ musí ležet vedle odok_web.py." % cesta)
    with _zamek_sablon:
        zaznam = _sablony.get(nazev)
        if zaznam is None or zaznam[0] != mtime:
            with open(cesta, encoding="utf-8") as f:
                zaznam = _sablony[nazev] = (mtime, f.read())
        return zaznam[1]


def verze_webu():
    """Krátký otisk času změny statických souborů – v adrese CSS/JS, ať prohlížeč nebere starou kopii."""
    try:
        return "%x" % int(max(os.path.getmtime(os.path.join(WEB_DIR, n)) for n in ("styl.css", "skript.js")))
    except OSError:
        return "0"



URL_SBIRKA = "https://www.e-sbirka.cz/sb/{rok}/{cislo}"
_RE_SBIRKA = re.compile(r"(\d+)\s*/\s*(\d{4})\s*Sb\.?")

MAX_VYSLEDKU = 1000  # limit řádků ve fulltextovém hledání

POPISKY = {
    "pid": "PID", "cislo_jednaci": "Číslo jednací", "nazev": "Název",
    "predkladatel": "Předkladatel", "predkladatel_zkratka": "Zkratka předkladatele",
    "typ": "Typ", "stav": "Stav", "datum": "Datum", "url_web": "Stránka v ODOK",
    "cj_ova": "Čj ÚV (OVA)", "tisk_psp": "Sněmovní tisk (dle ODOK)",
}


# --------------------------------------------------------------------------
# Pomocné funkce pro HTML
# --------------------------------------------------------------------------

def e(hodnota):
    """Escapování pro HTML."""
    return html.escape("" if hodnota is None else str(hodnota))


def _fmt_cas_odok(hodnota):
    """'2026-08-18T10:00:00Z' → '18. 8. 2026' (jinak beze změny)."""
    d = _odok.parsuj_cas_odok(hodnota) if isinstance(hodnota, str) else None
    return fmt_datum(d.date()) if d else (hodnota or "")


def odkaz(cesta, **parametry):
    return cesta + ("?" + urllib.parse.urlencode(parametry) if parametry else "")


def json_na_html(data, hloubka=0):
    """
    Obecné zobrazení libovolného JSONu: slovník → tabulka klíč/hodnota,
    seznam slovníků → tabulka se sloupci, vnořené věci → rozbalovací bloky.
    """
    if hloubka > 8:
        return "<code>…</code>"
    if isinstance(data, dict):
        if not data:
            return '<span class="tlumene">{}</span>'
        if hloubka == 0 and len(data) == 1:  # obal typu {"verze": [...]} – rozbalit
            (klic, hodnota), = data.items()
            if isinstance(hodnota, (list, dict)):
                return "<p class='tlumene'>%s</p>%s" % (e(klic), json_na_html(hodnota, 0))
        radky = "".join(
            "<tr><th>%s</th><td>%s</td></tr>" % (e(k), json_na_html(v, hloubka + 1))
            for k, v in data.items())
        return '<table class="kv">%s</table>' % radky
    if isinstance(data, list):
        if not data:
            return '<span class="tlumene">[]</span>'
        if all(isinstance(x, dict) for x in data):
            sloupce = []
            for x in data:
                for k in x:
                    if k not in sloupce:
                        sloupce.append(k)
            hlavicka = "".join("<th>%s</th>" % e(k) for k in sloupce)
            telo = "".join(
                "<tr>%s</tr>" % "".join(
                    "<td>%s</td>" % (json_na_html(x[k], hloubka + 1) if k in x else "")
                    for k in sloupce)
                for x in data)
            tabulka = '<div class="posun"><table class="data"><thead><tr>%s</tr></thead>' \
                      '<tbody>%s</tbody></table></div>' % (hlavicka, telo)
            if hloubka > 0:
                return "<details><summary>%d položek</summary>%s</details>" % (len(data), tabulka)
            return tabulka
        polozky = "".join("<li>%s</li>" % json_na_html(x, hloubka + 1) for x in data)
        return "<ul>%s</ul>" % polozky
    if isinstance(data, str) and data.startswith(("http://", "https://")):
        return '<a href="%s" target="_blank" rel="noopener">%s</a>' % (e(data), e(data))
    if isinstance(data, bool):
        return "ano" if data else "ne"
    if data is None:
        return '<span class="tlumene">–</span>'
    return e(data)







# --------------------------------------------------------------------------
# Server (singleton)
# --------------------------------------------------------------------------

class Oblibene:
    """
    Oblíbené materiály ODOK a sněmovní tisky uživatele – malý JSON soubor na disku.

    Výchozí umístění: %APPDATA%\\LegislativniRadar\\oblibene.json (jinde ~/.legislativni_radar/).
    Položka: klíč 'material:PID' / 'tisk:<id_tisk>' → {typ, id, nazev, rok, pridano, poznamka, videno}
      videno – stav položky při poslední návštěvě přehledu (podle něj se hlásí změny 🔔).
    """

    def __init__(self, soubor=None):
        if soubor is None:
            zaklad = os.environ.get("APPDATA")
            slozka = os.path.join(zaklad, "LegislativniRadar") if zaklad else \
                os.path.join(os.path.expanduser("~"), ".legislativni_radar")
            soubor = os.path.join(slozka, "oblibene.json")
        self.soubor = soubor
        self._zamek = threading.Lock()
        self._data = None

    @staticmethod
    def klic(typ, ident):
        ident = str(ident or "").strip()
        return "%s:%s" % (typ, ident.upper() if typ == "material" else ident)

    def _nacti(self):
        if self._data is None:
            try:
                with open(self.soubor, encoding="utf-8") as f:
                    data = json.load(f)
                self._data = data.get("polozky", {}) if isinstance(data, dict) else {}
            except FileNotFoundError:
                self._data = {}
            except (OSError, ValueError) as chyba:
                sys.stderr.write("Oblíbené nelze načíst (%s): %s\n" % (self.soubor, chyba))
                self._data = {}
        return self._data

    def _uloz(self):
        os.makedirs(os.path.dirname(self.soubor) or ".", exist_ok=True)
        docasny = self.soubor + ".tmp"
        with open(docasny, "w", encoding="utf-8") as f:
            json.dump({"verze": 1, "polozky": self._data}, f, ensure_ascii=False, indent=1)
        os.replace(docasny, self.soubor)

    def je(self, typ, ident):
        with self._zamek:
            return self.klic(typ, ident) in self._nacti()

    def klice(self):
        with self._zamek:
            return set(self._nacti())

    def __len__(self):
        with self._zamek:
            return len(self._nacti())

    def seznam(self, typ=None):
        """Položky (kopie) od naposledy přidané."""
        with self._zamek:
            polozky = [dict(v, klic=k) for k, v in self._nacti().items()
                       if typ is None or v.get("typ") == typ]
        return sorted(polozky, key=lambda x: x.get("pridano") or "", reverse=True)

    def prepni(self, typ, ident, nazev="", rok=None):
        """Přidá / odebere položku; vrací True, pokud je po změně oblíbená."""
        if typ not in ("material", "tisk") or not str(ident or "").strip():
            raise ValueError("Neplatná položka oblíbených: %r %r" % (typ, ident))
        k = self.klic(typ, ident)
        with self._zamek:
            data = self._nacti()
            if k in data:
                del data[k]
                vysledek = False
            else:
                data[k] = {"typ": typ, "id": k.split(":", 1)[1], "nazev": nazev or "", "rok": rok,
                           "pridano": _dt.datetime.now().isoformat(timespec="seconds"),
                           "poznamka": "", "videno": None}
                vysledek = True
            self._uloz()
        return vysledek

    def nastav(self, klic, **hodnoty):
        with self._zamek:
            data = self._nacti()
            if klic not in data:
                raise KeyError(klic)
            data[klic].update(hodnoty)
            self._uloz()


def odkazy_esel(n, text=None, kratce=False):
    """Odkazy na návrh: text vede do e-Legislativy (pracovní), vedle malý odkaz na veřejný portál."""
    if n is None or getattr(n, "id", None) is None:
        return e(text) if text is not None else ""
    url = getattr(n, "url_elegislativa", None) or n.url
    hlavni = "<a href='%s' target='_blank' rel='noopener' title='Otevřít v e-Legislativě'>%s ↗</a>" % (
        e(url), e(text if text is not None else "e-Legislativa"))
    if getattr(n, "url_elegislativa", None) and getattr(n, "url", None):
        hlavni += (" <a class='tlumene' href='%s' target='_blank' rel='noopener' "
                   "title='Veřejný portál e-Legislativy'>%s</a>" % (e(n.url), "(veř.)" if kratce else "(veřejný portál)"))
    return hlavni


def _jako_den(d):
    return d.date() if isinstance(d, _dt.datetime) else d


def odkaz_sbirka(text):
    """'526/1990 Sb.' → odkaz do e-Sbírky; jiný text jen escapovaný."""
    if not text:
        return ""
    t = str(text)
    m = _RE_SBIRKA.search(t)
    if not m:
        return e(t)
    url = URL_SBIRKA.format(rok=m.group(2), cislo=m.group(1))
    return "%s<a href='%s' target='_blank' rel='noopener' title='e-Sbírka'>%s ↗</a>%s" % (
        e(t[:m.start()]), e(url), e(m.group(0)), e(t[m.end():]))


class OdokServer:
    """
    Lokální webserver nad daty ODOK. Singleton: OdokServer() vrací vždy
    stejnou instanci; parametry se uplatní jen při prvním vytvoření.

    Parametry:
        host  – adresa (výchozí 127.0.0.1 = jen tento počítač)
        port  – port (výchozí 8765; je-li obsazený, zkusí se další)
        odok  – hotová instance Odok; jinak se vytvoří s parametry **odok_param
        psp   – hotová instance Psp (psp.py); jinak se vytvoří se stejným nastavením sítě
    """

    _instance = None
    _zamek_instance = threading.Lock()

    def __new__(cls, *args, **kwargs):
        with cls._zamek_instance:
            if cls._instance is None:
                cls._instance = super().__new__(cls)
                cls._instance._inicializovano = False
        return cls._instance

    def __init__(self, host="127.0.0.1", port=8765, odok=None, psp=None, oblibene=None,
                 relevance=None, **odok_param):
        if self._inicializovano:
            return
        self._inicializovano = True
        self.host = host
        self.port = port
        if odok is None and not odok_param.get("roky"):
            # výchozí výběr = celé aktuální volební období
            cislo = max(ZNAMA_OBDOBI)
            odok_param["roky"] = roky_obdobi(*ZNAMA_OBDOBI[cislo])
        self.odok = odok or Odok(**odok_param)
        self._zamek_dat = threading.Lock()  # Odok není psaný pro souběžný přístup
        # Sněmovna (psp.py) – načítá se na pozadí
        self.psp = psp
        self._zamek_psp = threading.Lock()
        self._psp_hotovo = threading.Event()
        self._psp_chyba = None
        self._pid_tisk = {}                  # PID eKLEPu → id_tisk
        # index čísel jednacích připomínek (norm. čj → [{pid, cj, org, typ}])
        self._zamek_indexu = threading.Lock()
        self._index_cj = {}
        self._index_pid = set()
        # e-Legislativa (esel.py) – kontext k podzákonným předpisům, načítá se na pozadí
        self.esel = None
        self._zamek_esel = threading.Lock()
        self._esel_hotovo = threading.Event()
        self._esel_chyba = None
        # Podklady PLPV
        self._zamek_plpv = threading.Lock()
        self._plpv_cache = {}                # (resort, rok) → (čas, výsledek)
        self._plpv_odok = {}                 # rok → Odok s roky [rok-1, rok]
        self._pocet_dotazu = 0
        self._kontext = threading.local()   # volby z cookie pro právě obsluhovaný dotaz
        self._odok_roky = {}                # rok → Odok (jiné ročníky než výchozí)
        self._zamek_odok_roky = threading.Lock()
        self._psp_pohledy = {}              # období → Psp.v_obdobi(…)
        self.oblibene = oblibene if isinstance(oblibene, Oblibene) else Oblibene(oblibene)
        self.relevance = _relevance.Relevance(relevance) if _relevance is not None else None
        self._posledni_dotaz = None
        self._odok_chyba = None
        self._pokusy_obnovy = {}
        self._httpd = None
        self._vlakno = None

    @classmethod
    def instance(cls, **kwargs):
        """Vrátí (a případně vytvoří) jedinou instanci serveru."""
        return cls(**kwargs)

    # ---------------- řízení ----------------

    @property
    def bezi(self):
        return self._httpd is not None

    @property
    def url(self):
        return "http://%s:%d/" % ("localhost" if self.host in ("127.0.0.1", "0.0.0.0") else self.host,
                                  self.port)

    def spust(self, otevrit_prohlizec=True, na_pozadi=False):
        """
        Spustí server. na_pozadi=False blokuje až do Ctrl+C;
        na_pozadi=True běží ve vlákně a metoda se hned vrátí.
        """
        if self.bezi:
            return self
        handler = self._vytvor_handler()
        posledni_chyba = None
        for port in range(self.port, self.port + 20):
            try:
                self._httpd = ThreadingHTTPServer((self.host, port), handler)
                self.port = port
                break
            except OSError as chyba:
                posledni_chyba = chyba
        else:
            raise OSError("Nepodařilo se otevřít port %d–%d: %s"
                          % (self.port, self.port + 19, posledni_chyba))
        self._httpd.daemon_threads = True
        if not self._psp_hotovo.is_set():
            self.nacti_psp_na_pozadi()
        if not self._esel_hotovo.is_set():
            self.nacti_esel_na_pozadi()
        threading.Thread(target=self._hlidej_stari_dat, daemon=True).start()
        self.indexuj_pripominky_na_pozadi()
        print("%s běží na %s%s" % (NAZEV_APLIKACE, self.url, "" if na_pozadi else "  (ukončení: Ctrl+C)"))
        if otevrit_prohlizec:
            threading.Timer(0.5, webbrowser.open, args=(self.url,)).start()
        if na_pozadi:
            self._vlakno = threading.Thread(target=self._httpd.serve_forever, daemon=True)
            self._vlakno.start()
        else:
            try:
                self._httpd.serve_forever()
            except KeyboardInterrupt:
                print("\nUkončuji…")
            finally:
                self.zastav()
        return self

    def zastav(self):
        """Zastaví server (instance zůstává, jde znovu spustit)."""
        if self._httpd is None:
            return
        httpd, self._httpd = self._httpd, None
        if self._vlakno is not None:
            httpd.shutdown()
            self._vlakno.join(5)
            self._vlakno = None
        httpd.server_close()

    # ---------------- stránky ----------------

    def hvezda(self, typ, ident, nazev="", klice=None):
        """Tlačítko ⭐/☆ pro přidání materiálu ('material', PID) nebo tisku ('tisk', id_tisk)."""
        if not ident:
            return ""
        zapnuto = (Oblibene.klic(typ, ident) in klice) if klice is not None else \
            self.oblibene.je(typ, ident)
        return ("<button type='button' class='hvezda%s' data-typ='%s' data-id='%s' data-nazev='%s' "
                "title='%s'>%s</button>" % (
                    " zapnuto" if zapnuto else "", e(typ), e(ident), e((nazev or "")[:300]),
                    "Odebrat z oblíbených" if zapnuto else "Přidat do oblíbených",
                    HVEZDA if zapnuto else HVEZDA_PRAZDNA))

    def _bunka_hvezdy(self, typ, ident, nazev, klice):
        zapnuto = Oblibene.klic(typ, ident) in klice
        return "<td class='sl-hvezda' data-razeni='%d'>%s</td>" % (
            zapnuto, self.hvezda(typ, ident, nazev, klice))

    @staticmethod
    def _tlacitko_jen_oblibene(id_tabulky):
        return ("<button type='button' class='jen-obl' data-tabulka='%s' title='Zobrazit jen "
                "oblíbené řádky'>⭐ jen oblíbené</button>" % e(id_tabulky))

    def _stranka(self, titulek, obsah, sekce="odok"):
        pocet_obl = len(self.oblibene)
        nav = "".join("<a href='%s'%s>%s</a>" % (url, " class='aktivni'" if klic == sekce else "", text)
                      for klic, url, text in (("odok", "/", "Materiály ODOK"),
                                              ("tisky", "/tisky", "Sněmovní tisky"),
                                              ("schuze", "/schuze", "Schůze PS"),
                                              ("plpv", "/plpv", "Podklady PLPV"))
                      if klic == "odok" or (klic == "plpv" and _plpv is not None)
                      or (klic in ("tisky", "schuze") and _psp is not None))
        nav += "<a href='/oblibene' title='Moje oblíbené návrhy'%s>⭐<span class='pocet-obl' " \
               "id='pocet-obl'>%s</span></a>" % (" class='aktivni'" if sekce == "oblibene" else "",
                                                 pocet_obl or "")
        try:
            sablona = soubor_webu("stranka.html")
        except OdokChyba as chyba:     # bez složky web/ aspoň holá stránka s vysvětlením
            sablona = ("<!DOCTYPE html><meta charset='utf-8'><title>$titulek</title><p><b>%s</b></p>"
                       "<nav>$nav</nav><main>$obsah</main>" % e(chyba))
        return string.Template(sablona).safe_substitute(
            titulek=e(titulek), nav=nav, prepinac=self._prepinac(), obsah=obsah, verze=verze_webu())

    # ---------------- volba roku (ODOK) a volebního období (Sněmovna) ----------------

    def _nove_odok(self, roky):
        o = self.odok
        return Odok(roky=list(roky), cache_dir=o.cache_dir, sit=list(o.sit), proxy=o._proxy_param,
                    max_stari_h=o.max_stari_s / 3600.0, timeout=o.timeout, verbose=o.verbose)

    def _vybrany_rok(self):
        """Rok zvolený v liště, nebo None = celé volební období."""
        v = getattr(self._kontext, "rok", None)
        return int(v) if v and str(v).isdigit() else None

    def _seznam_obdobi(self):
        """[(číslo, rok od, rok do|None)] od nejnovějšího – z dat Sněmovny, jinak ze ZNAMA_OBDOBI."""
        if _psp is not None and self.psp is not None and self._psp_hotovo.is_set() \
                and not self._psp_chyba:
            try:
                seznam = [(o.cislo, o.od.year, o.do.year if o.do else None)
                          for o in self.psp.obdobi_seznam() if o.cislo and o.od]
                seznam = [x for x in seznam if (x[2] or 9999) >= PRVNI_ROK_ODOK]
                if seznam:
                    return seznam
            except OdokChyba:
                pass
        return [(c, od, do) for c, (od, do) in sorted(ZNAMA_OBDOBI.items(), reverse=True)]

    def _cislo_obdobi(self):
        """Číslo zvoleného období (výchozí aktuální) nebo 'vse'."""
        v = self._vybrane_obdobi()
        if v == "vse":
            return "vse"
        seznam = self._seznam_obdobi()
        if isinstance(v, int) and any(c == v for c, _o, _d in seznam):
            return v
        return seznam[0][0]

    def _vybrane_roky(self):
        """Ročníky ODOK podle výběru v liště (rok, celé období, nebo vše)."""
        rok = self._vybrany_rok()
        if rok:
            return [rok]
        cislo = self._cislo_obdobi()
        if cislo == "vse":
            return list(range(PRVNI_ROK_ODOK, _dt.date.today().year + 1))
        for c, od, do in self._seznam_obdobi():
            if c == cislo:
                return roky_obdobi(od, do)
        return [_dt.date.today().year]

    def _rozsah_vyberu(self):
        """(od, do, popis) – dny, do kterých musí spadat založení materiálu podle výběru v liště;
        None = bez omezení (všechna období). do=None → dosud."""
        rok, cislo = self._vybrany_rok(), self._cislo_obdobi()
        if cislo == "vse":
            return (_dt.date(rok, 1, 1), _dt.date(rok, 12, 31), "rok %d" % rok) if rok else None
        od = do = None
        if _psp is not None and self.psp is not None and self._psp_hotovo.is_set() and not self._psp_chyba:
            try:
                o = next((x for x in self.psp.obdobi_seznam() if x.cislo == cislo), None)
                if o is not None and o.od:
                    od, do = o.od.date(), (o.do.date() if o.do else None)
            except OdokChyba:
                pass
        if od is None:
            od = ZACATKY_OBDOBI.get(cislo)
            dalsi = ZACATKY_OBDOBI.get(cislo + 1) if isinstance(cislo, int) else None
            do = dalsi - _dt.timedelta(days=1) if dalsi else None
        if od is None:
            return None
        popis = "%s. VO (%s – %s)" % (cislo, fmt_datum(od), fmt_datum(do) if do else "dosud")
        if rok:
            od, do = max(od, _dt.date(rok, 1, 1)), min(do or _dt.date(rok, 12, 31), _dt.date(rok, 12, 31))
            popis = "rok %d v %s. VO (%s – %s)" % (rok, cislo, fmt_datum(od), fmt_datum(do))
        return od, do, popis

    @staticmethod
    def _datum_materialu(m):
        d = m.datum_vytvoreni or m.datum_autorizace
        return d.date() if d else None

    def _filtr_vyberu(self, materialy):
        """(vybrané materiály, počet skrytých, rozsah) – jen materiály založené ve zvoleném období.
        Ročníky ODOK jsou po kalendářních letech, období začínají v říjnu – bez filtru by se míchala."""
        rozsah = self._rozsah_vyberu()
        if rozsah is None:
            return list(materialy), 0, None
        od, do, _popis = rozsah
        vybrane = []
        for m in materialy:
            d = self._datum_materialu(m)
            if d is None or (d >= od and (do is None or d <= do)):
                vybrane.append(m)
        return vybrane, len(materialy) - len(vybrane), rozsah

    @staticmethod
    def _poznamka_filtru(skryto, rozsah):
        if not rozsah:
            return ""
        return ("<p class='tlumene'>Zobrazeny materiály založené v období %s%s.</p>" % (
            e(rozsah[2]), "; skryto %d založených mimo toto období (ročníky ODOK jsou po kalendářních "
                          "letech, volební období začínají v říjnu)" % skryto if skryto else ""))

    def _popis_vyberu(self):
        rok, cislo = self._vybrany_rok(), self._cislo_obdobi()
        if cislo == "vse":
            return "rok %d" % rok if rok else "všechna období"
        return "%s (%s. VO)" % (rok, cislo) if rok else "celé %s. období" % cislo

    def _odok_pro(self, roky=None):
        """Instance Odok pro rok / seznam roků (výchozí: podle výběru v liště).
        Výchozí sada roků sdílí self.odok."""
        if roky is None:
            roky = self._vybrane_roky()
        elif isinstance(roky, int):
            roky = [roky]
        klic = tuple(sorted(int(r) for r in roky))
        if tuple(sorted(self.odok.roky)) == klic:
            return self.odok
        with self._zamek_odok_roky:
            o = self._odok_roky.get(klic)
            if o is None:
                o = self._odok_roky[klic] = self._nove_odok(list(klic))
            return o

    def _vybrane_obdobi(self):
        v = getattr(self._kontext, "obdobi", None)
        if v and str(v).isdigit():
            return int(v)
        return v or None

    def _psp_pro(self, obdobi="zvolene"):
        """Data Sněmovny pohledem zvoleného volebního období (sdílí načtená data)."""
        p = self._snemovna()
        param = self._vybrane_obdobi() if obdobi == "zvolene" else obdobi
        if param is None:
            return p
        pohled = self._psp_pohledy.get(param)
        if pohled is None or pohled._tabulky is not p._tabulky:
            pohled = self._psp_pohledy[param] = p.v_obdobi(param)
        try:
            _ = pohled.obdobi
        except OdokChyba:
            return p
        return pohled

    def _prepinac(self):
        """Jeden výběr období v horní liště: volební období PS, případně rok v něm
        (určuje ročníky ODOK i pohled na Sněmovnu; ukládá se do cookie)."""
        rok, cislo = self._vybrany_rok(), self._cislo_obdobi()
        dnes = _dt.date.today().year
        skupiny = []
        for c, od, do in self._seznam_obdobi():
            roky = roky_obdobi(od, do)
            volby = ["<option value='%d'%s>celé období (%d. VO)</option>" % (
                c, " selected" if cislo == c and not rok else "", c)]
            volby += ["<option value='%d:%d'%s>%d (%d. VO)</option>" % (
                c, r, " selected" if cislo == c and rok == r else "", r, c) for r in reversed(roky)]
            skupiny.append("<optgroup label='%d. období (%d–%s)'>%s</optgroup>" % (
                c, od, do or "", "".join(volby)))
        skupiny.append("<optgroup label='Bez omezení'><option value='vse'%s>všechna období "
                       "(ODOK %d–%d)</option></optgroup>" % (
                           " selected" if cislo == "vse" else "", PRVNI_ROK_ODOK, dnes))
        return """<form class="prepinac" action="/nastav">
<input type="hidden" name="zpet" value="%s">
<label title="Volební období Poslanecké sněmovny, případně jeden rok v něm – určuje ročníky ODOK i sněmovní tisky">Období
<select name="vyber" onchange="this.form.submit()">%s</select></label>
<noscript><button>OK</button></noscript></form>""" % (
            e(getattr(self._kontext, "cesta", "/") or "/"), "".join(skupiny))

    def stranka_nastav(self, parametry):
        """Uloží volbu roku / období do cookie a vrátí se na předchozí stránku."""
        cookies = []
        rok = parametry.get("rok", "")
        obdobi = parametry.get("obdobi", "")
        vyber = parametry.get("vyber", "")
        if vyber:     # '10' = celé období, '10:2026' = rok v období, 'vse' / 'vse:2026'
            obdobi, _dvojtecka, rok = vyber.partition(":")
            if not rok:
                cookies.append("odok_rok=; Path=/; Max-Age=0; SameSite=Lax")
        if rok.isdigit():
            cookies.append("odok_rok=%s; Path=/; Max-Age=31536000; SameSite=Lax" % int(rok))
        if obdobi.isdigit() or obdobi == "vse":
            cookies.append("ps_obdobi=%s; Path=/; Max-Age=31536000; SameSite=Lax" % obdobi)
        zpet = parametry.get("zpet") or "/"
        if not zpet.startswith("/") or zpet.startswith("//"):
            zpet = "/"
        if obdobi:   # období zadané přímo v adrese (podklady PLPV) nahradí nová volba
            casti = urllib.parse.urlsplit(zpet)
            dotaz = [(k, v) for k, v in urllib.parse.parse_qsl(casti.query) if k not in ("o", "rok")]
            zpet = urllib.parse.urlunsplit(("", "", casti.path, urllib.parse.urlencode(dotaz), ""))
        return ("presmeruj", zpet, cookies)

    def _tabulka_materialu(self, materialy, id_tabulky="materialy"):
        radky = []
        klice = self.oblibene.klice()
        tisky_hotove = (self._psp_hotovo.is_set() and self.psp is not None
                        and not self._psp_chyba)
        for m in materialy:
            tisk = ""
            if tisky_hotove and (m.pid or "").upper() in self._pid_tisk:
                with self._zamek_psp:
                    tisk = self._odkaz_tisk_kratky(self._pid_tisk[m.pid.upper()])
            radky.append(
                "<tr>" + self._bunka_hvezdy("material", m.pid, m.nazev, klice) +
                "<td><a href='%s'>%s</a></td><td>%s</td><td>%s</td><td>%s</td>"
                "<td>%s</td><td>%s</td><td data-razeni='%s'>%s</td><td>%s</td></tr>" % (
                    e(odkaz("/material", id=m.pid or m.cislo_jednaci or "")), e(m.pid),
                    e(m.cislo_jednaci), e(m.nazev), e(m.predkladatel_zkratka),
                    e(m.typ), e(m.stav), e(m.datum or ""), e(_fmt_cas_odok(m.datum)), tisk))
        return """
<div class="lista"><input type="text" data-filtr="%(id)s" placeholder="Filtrovat v tabulce…">
%(jen_obl)s<span class="tlumene"><span id="%(id)s-pocet">%(n)d</span> materiálů</span></div>
<div class="posun"><table class="data raditelna" id="%(id)s"><thead><tr>
<th title="Oblíbené">⭐</th><th>PID</th><th>Čj</th><th>Název</th><th>Předkl.</th><th>Typ</th><th>Stav</th><th>Datum</th>
<th title="Sněmovní tisk">Tisk PS</th>
</tr></thead><tbody>%(radky)s</tbody></table></div>%(pozn)s""" % {
            "id": id_tabulky, "n": len(materialy), "radky": "".join(radky),
            "jen_obl": self._tlacitko_jen_oblibene(id_tabulky),
            "pozn": "" if tisky_hotove or _psp is None else
            "<p class='tlumene'>Data Sněmovny se ještě načítají – sloupec „Tisk PS“ se doplní "
            "po obnovení stránky.</p>"}

    def stranka_uvod(self, parametry):
        odok = self._odok_pro()
        with self._zamek_dat:
            materialy, skryto, rozsah = self._filtr_vyberu(odok.materialy())
        podle = {}
        for m in materialy:
            podle.setdefault(m.predkladatel_zkratka or "?", Counter())[m.predkladatel or "(neuvedeno)"] += 1
        predkladatele = []
        for zkratka, nazvy in podle.items():
            uvedene = [n for n, _c in nazvy.most_common() if n != "(neuvedeno)"]
            predkladatele.append((zkratka, uvedene[0] if uvedene else "(neuvedeno)",
                                  sum(nazvy.values()), uvedene[1:]))
        predkladatele.sort(key=lambda x: (-x[2], x[0]))
        celkem = len(materialy)
        dlazdice = "".join(
            "<a href='%s'%s><span class='zkratka'>%s</span><span>%s</span>"
            "<span class='pocet'>%d</span></a>" % (
                e(odkaz("/ministerstvo", z=zkratka)),
                " title='Dříve / také: %s'" % e("; ".join(dalsi)) if dalsi else "",
                e(zkratka), e(nazev), pocet)
            for zkratka, nazev, pocet, dalsi in predkladatele)
        obsah = """<h1>Předkladatelé</h1>
<p class="tlumene">%d materiálů (%s, ročníky ODOK %s). Klikněte na předkladatele
nebo zadejte PID / čj do vyhledávání nahoře.</p>%s
<div class="mrizka">%s</div>""" % (celkem, e(rozsah[2] if rozsah else "všechna období"),
                                   e(", ".join(map(str, odok.roky))),
                                   self._poznamka_filtru(skryto, rozsah) if skryto else "", dlazdice)
        return self._stranka("Předkladatelé", obsah)

    def stranka_ministerstvo(self, parametry):
        zkratka = parametry.get("z", "")
        with self._zamek_dat:
            materialy, skryto, rozsah = self._filtr_vyberu(self._odok_pro().materialy_ministerstva(zkratka))
        nazev = next((m.predkladatel for m in materialy if m.predkladatel), "")
        obsah = "<h1>%s <span class='tlumene'>%s</span></h1>%s%s" % (
            e(zkratka), e(nazev), self._poznamka_filtru(skryto, rozsah),
            self._tabulka_materialu(materialy) if materialy else
            "<p>Žádné materiály pro „%s“.</p>" % e(zkratka))
        return self._stranka(zkratka, obsah)

    def stranka_hledej(self, parametry):
        q = parametry.get("q", "").strip()
        if not q:
            return self._presmeruj("/")
        poznamka_tisk = ""
        # čj připomínky (např. kolegů k cizímu návrhu) → rovnou materiál, ke kterému patří
        nalezy = self.hledej_cj_pripominek(q, jen_presne=True)
        if len({n["pid"] for n in nalezy}) == 1:
            return self._presmeruj(odkaz("/material", id=nalezy[0]["pid"]))
        if _psp is not None and re.fullmatch(r"(OVA\s*)?\d+\s*(-\s*E)?(\s*/\s*\d+)?", q, re.I):
            # „123/26“ / „OVA 123/26“ je nejčastěji čj ÚV materiálu → nejdřív ODOK, pak Sněmovna
            if "/" in q:
                cj = re.sub(r"^OVA\s*", "", q, flags=re.I)
                with self._zamek_dat:
                    for o in dict.fromkeys([self._odok_pro(), self.odok] + list(self._odok_roky.values())):
                        if o._zaznamy is None and o is not self._odok_pro():
                            continue
                        try:
                            m = o.material(cj)
                        except OdokChyba:
                            continue
                        if m.nazev:
                            return self._presmeruj(odkaz("/material", id=m.pid or cj))
            try:                                       # číslo tisku / čj OVA u sněmovního tisku
                with self._zamek_psp:
                    t = self._psp_pro().tisk(q)
                return self._presmeruj(odkaz("/tisk", id="id:%s" % t.id_tisk))
            except OdokChyba as chyba:
                poznamka_tisk = "<p class='tlumene'>Sněmovní tisk nenalezen: %s Hledám v materiálech ODOK.</p>" \
                    % e(chyba)
        vse = parametry.get("vse") == "1"
        roky = list(range(PRVNI_ROK_ODOK, _dt.date.today().year + 1)) if vse else None
        odok = self._odok_pro(roky)
        with self._zamek_dat:
            try:
                m = odok.material(q)
                return self._presmeruj(odkaz("/material", id=m.pid or q,
                                             rok=m.rok if getattr(m, "rok", None) else ""))
            except OdokChyba:
                pass
            vysledky = odok.hledej(q)
            skryto, rozsah = 0, None
            if not vse:
                vysledky, skryto, rozsah = self._filtr_vyberu(vysledky)
            roky_text = "%d–%d" % (min(odok.roky), max(odok.roky)) if len(odok.roky) > 1 \
                else str(odok.roky[0])
        orez = len(vysledky) > MAX_VYSLEDKU
        rozsah_html = ("<p class='tlumene'>Hledáno ve všech ročnících ODOK (%s).</p>" % roky_text if vse else
                  "<div class='lista'><span class='tlumene'>Hledáno: %s (ODOK %s).</span>"
                  "<a class='tlacitko' href='%s'>Hledat ve všech letech %d–%d</a></div>" % (
                      e(self._popis_vyberu()), e(roky_text), e(odkaz("/hledej", q=q, vse="1")),
                      PRVNI_ROK_ODOK, _dt.date.today().year))
        obsah = "<h1>Hledání: „%s“</h1>%s%s%s%s%s%s" % (
            e(q), poznamka_tisk, self._html_nalezy_pripominek(q), rozsah_html,
            self._poznamka_filtru(skryto, rozsah) if skryto else "",
            "<p class='tlumene'>Zobrazeno prvních %d z %d.</p>" % (MAX_VYSLEDKU, len(vysledky))
            if orez else "",
            self._tabulka_materialu(vysledky[:MAX_VYSLEDKU]) if vysledky else
            "<p>Nic nenalezeno.%s</p>" % ("" if vse else " Materiál může být z jiného roku – zkuste "
                                          "hledání ve všech letech (první načtení chvíli trvá)."))
        return self._stranka("Hledání", obsah)

    def _najdi_material(self, ident, rok=None):
        """Materiál ze zvoleného (nebo zadaného) ročníku; když tam není, z ostatních
        už načtených ročníků. Jinak (u PID) aspoň holý materiál pro verze a připomínky."""
        instance = [self._odok_pro(rok)] + [self.odok] + list(self._odok_roky.values()) + \
            list(self._plpv_odok.values())
        prvni_chyba, holy = None, None
        for o in dict((id(x), x) for x in instance).values():
            if o is not instance[0] and o._zaznamy is None:
                continue
            try:
                m = o.material(ident)
            except OdokChyba as chyba:
                prvni_chyba = prvni_chyba or chyba
                continue
            if m.nazev:
                return m
            holy = holy or m
        if holy is not None:
            return holy
        raise prvni_chyba or OdokChyba("Materiál '%s' nenalezen." % ident)

    def stranka_material(self, parametry):
        ident = parametry.get("id", "")
        rok = parametry.get("rok", "")
        with self._zamek_dat:
            m = self._najdi_material(ident, int(rok) if rok.isdigit() else None)
            info = m.info()
            dokumenty = m.dokumenty()
            url_dok = [m.url_dokumentu(d) for d in dokumenty]
        radky_info = "".join(
            "<tr><th>%s</th><td>%s</td></tr>" % (e(POPISKY.get(k, k)), json_na_html(v))
            for k, v in info.items() if v is not None)

        def tlacitko_relevance(d, u):
            return " ".join(x for x in (self._tlacitko_nahled(pid, d, u),
                                        self._tlacitko_relevance(pid, d, u)) if x)

        pid = m.pid or ""
        if dokumenty:
            radky_dok = "".join(
                "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
                    e(d.get("order")),
                    e((d.get("type") or {}).get("name") if isinstance(d.get("type"), dict) else d.get("type")),
                    ("<a href='%s' target='_blank' rel='noopener'>%s</a>" % (e(u), e(d.get("old_name") or d.get("name")))
                     if u else e(d.get("old_name") or d.get("name"))),
                    e(d.get("name")), e(d.get("size_str") or d.get("size")),
                    e(d.get("date_modified")), tlacitko_relevance(d, u))
                for d, u in zip(dokumenty, url_dok))
            tab_dok = """<div class="posun"><table class="data raditelna"><thead><tr>
<th>#</th><th>Typ</th><th>Původní název</th><th>Soubor</th><th>Velikost</th><th>Změněno</th>
<th>Obsah</th></tr></thead><tbody>%s</tbody></table></div>""" % radky_dok
            if not any(url_dok):
                tab_dok += ("<p class='tlumene'>Soubory stáhnete na <a href='%s' target='_blank' "
                            "rel='noopener'>stránce materiálu v ODOK</a> (přímý odkaz na dokument "
                            "zatím není nastaven – konstanta URL_DOKUMENT v odok.py).</p>"
                            % e(m.url_web))
        else:
            tab_dok = "<p class='tlumene'>V datasetu nejsou u materiálu žádné dokumenty.</p>"

        pid = m.pid or ""
        obsah = """<h1>%(hvezda)s%(nazev)s</h1>
<p class="tlumene">%(pid)s · %(cj)s · <a href="%(web)s" target="_blank" rel="noopener">otevřít v ODOK ↗</a></p>
<div class="karta"><table class="kv">%(info)s</table></div>
%(snemovna)s%(esel)s
<h2>Dokumenty (%(ndok)d)</h2>%(dok)s
<h2>Historické verze</h2>
<details data-nacti="%(url_verze)s"><summary>Zobrazit verze</summary>
<div class="obsah"><p class="nacitam">Načítám…</p></div></details>
<h2>Připomínky</h2>
<details data-nacti="%(url_prip)s"><summary>Zobrazit připomínky</summary>
<div class="obsah"><p class="nacitam">Načítám…</p></div></details>
<h2>Všechna data materiálu</h2>
<details><summary>Zobrazit surová data z datasetu</summary>%(surova)s</details>
""" % {
            "nazev": e(m.nazev or pid), "pid": e(pid),
            "hvezda": self.hvezda("material", pid, m.nazev), "cj": e(m.cislo_jednaci or "čj neuvedeno"),
            "web": e(m.url_web or "#"), "info": radky_info, "ndok": len(dokumenty), "dok": tab_dok,
            "url_verze": e(odkaz("/cast", typ="verze", pid=pid)),
            "url_prip": e(odkaz("/cast", typ="pripominky", pid=pid)),
            "surova": json_na_html(m.data),
            "snemovna": "" if _psp is None or not pid else
            "<h2>Sněmovní tisk</h2><details open data-nacti='%s'><summary>Vazba na Poslaneckou "
            "sněmovnu</summary><div class='obsah'><p class='nacitam'>Načítám data Sněmovny…</p>"
            "</div></details>" % e(odkaz("/cast", typ="psp", pid=pid)),
            "esel": "" if _esel is None or not pid or m.typ_id not in ("NV", "NNV") else
            "<h2>e-Legislativa</h2><details open data-nacti='%s'><summary>Návrh v e-Legislativě "
            "(fáze, stavy, Sbírka, účinnost)</summary><div class='obsah'><p class='nacitam'>Načítám "
            "data e-Legislativy…</p></div></details>" % e(odkaz("/cast", typ="esel", pid=pid)),
        }
        return self._stranka(m.nazev or pid, obsah)

    def cast_detailu(self, parametry):
        """HTML fragment s verzemi / připomínkami (načítá se až po rozbalení)."""
        typ, pid = parametry.get("typ"), parametry.get("pid", "")
        if typ == "psp":  # mimo zámek ODOK – načítání Sněmovny nesmí blokovat ODOK
            return self.cast_tisk_k_materialu(pid)
        if typ == "esel":
            return self.cast_esel_k_materialu(pid)
        if typ == "relevance":
            return self.cast_relevance(pid, parametry.get("d", ""))
        if typ == "nahled":
            return self.cast_nahled(pid, parametry.get("d", ""))
        if typ == "plpv":
            return self.cast_plpv(parametry)
        with self._zamek_dat:
            if typ == "verze":
                return self._html_verze(pid, self.odok.verze(pid))
            elif typ == "pripominky":
                data = self.odok.pripominky(pid)
                self._pridej_do_indexu(pid.upper(), data)
                return self._html_pripominky(data, pid)
            else:
                raise ValueError("Neznámý typ: %s" % typ)
        return json_na_html(data)

    def _tlacitko_relevance(self, pid_materialu, d, url):
        """Tlačítko analýzy relevance u Word dokumentu (.docx / .doc)."""
        nazvy = " ".join(str(d.get(k) or "") for k in ("name", "old_name")).lower()
        if self.relevance is None or not url or not d.get("pid") or not pid_materialu or \
                not re.search(r"\.(doc[xm]?|pdf)\b", nazvy):
            return ""
        return ("<button type='button' class='tlacitko-mini' data-vlozit='%s' data-skryt='Skrýt analýzu' "
                "data-nacitam='Stahuji dokument a hledám výrazy…' title='Stáhne dokument a vyhledá v něm "
                "výrazy ze seznamu útvarů'>Provést analýzu relevance</button>"
                % e(odkaz("/cast", typ="relevance", pid=pid_materialu, d=d["pid"])))

    def _tlacitko_nahled(self, pid_materialu, d, url):
        """Tlačítko pro zobrazení obsahu dokumentu přímo na stránce (.docx, .doc, PDF)."""
        nazvy = " ".join(str(d.get(k) or "") for k in ("name", "old_name")).lower()
        if _relevance is None or not url or not d.get("pid") or not pid_materialu or \
                not re.search(r"\.(doc[xm]?|pdf)\b", nazvy):
            return ""
        return ("<button type='button' class='tlacitko-mini' data-vlozit='%s' data-skryt='Skrýt text' "
                "data-nacitam='Stahuji a načítám dokument…' title='Zobrazí obsah dokumentu přímo na "
                "stránce'>Zobrazit text</button>" % e(odkaz("/cast", typ="nahled", pid=pid_materialu, d=d["pid"])))

    # typy verzí materiálu v pořadí legislativního procesu
    TYPY_VERZI = {"MPR": "Verze do připomínkového řízení", "PK": "Verze pro jednání pracovních komisí",
                  "JV": "Verze pro jednání vlády", "PV": "Projednaná verze"}

    def _html_verze(self, pid, data):
        """Historické verze materiálu jako časová osa: typ verze, stav, datum a dokumenty."""
        if not isinstance(data, dict) or not isinstance(data.get("versions_list"), list):
            return json_na_html(data)
        verze = sorted(data["versions_list"], key=lambda v: v.get("date_authorized") or "")
        if not verze:
            return "<p class='tlumene'>Materiál zatím nemá žádné zveřejněné verze.</p>"
        prehled = " → ".join(
            "<span class='verze-typ %s'>%s</span>" % (
                e(((v.get("version_type") or {}).get("id") or "").lower()),
                e((v.get("version_type") or {}).get("id") or "?")) for v in verze)
        casti = ["<div class='verze'><p class='tlumene'>%d %s v pořadí vzniku: %s</p>" % (
            len(verze), "verze" if len(verze) < 5 else "verzí", prehled)]
        for i, v in enumerate(reversed(verze)):
            vt = v.get("version_type") or {}
            stav = (v.get("status") or {}).get("name") or ""
            ident = v.get("identifier") or {}
            udaje = [("Stav", e(stav)),
                     ("Čj předkladatele", e(ident.get("id_no_ext") or "")),
                     ("Čj ÚV (OVA)", e(ident.get("id_no") or "")),
                     ("Vytvořeno", e(_fmt_cas_odok((v.get("metadata") or {}).get("date_created")))),
                     ("Název", e(v.get("title") or "") if (v.get("title") or "").strip() !=
                      (data.get("title") or "").strip() else ""),
                     ("Důvod předložení", e(v.get("reason") or "")),
                     ("Poznámka", e(v.get("notes") or "")),
                     ("Právní oblast", e(", ".join(v.get("law_areas") or []))),
                     ("Klíčová slova", e(", ".join(v.get("keywords") or [])))]
            rizeni = v.get("review_process") or {}
            if rizeni.get("date_finish") or rizeni.get("reviewers"):
                udaje.append(("Připomínkové řízení", "do %s · %d připomínkových míst%s" % (
                    e(_fmt_cas_odok(rizeni.get("date_finish"))), len(rizeni.get("reviewers") or []),
                    " · e-mail <span class='vyber'>%s</span>" % e(rizeni["email_for_reviews"])
                    if rizeni.get("email_for_reviews") else "")))
            prilohy = sorted(v.get("attachments") or [], key=lambda a: a.get("order") or 0)
            if prilohy:
                radky = "".join(
                    "<tr><td>%s</td><td>%s</td><td><a href='%s' target='_blank' rel='noopener'>%s</a></td>"
                    "<td class='tlumene'>%s</td><td>%s</td><td>%s</td></tr>" % (
                        e(a.get("order") or ""), e((a.get("type") or {}).get("name") or ""),
                        e(a.get("uri") or _odok.URL_DOKUMENT.format(pid=a.get("pid", ""))),
                        e(a.get("old_name") or a.get("name") or "soubor"), e(a.get("name") or ""),
                        e(a.get("size_str") or ""),
                        " ".join(x for x in (self._tlacitko_nahled(pid, a, a.get("uri") or a.get("pid")),
                                             self._tlacitko_relevance(pid, a, a.get("uri") or a.get("pid"))) if x))
                    for a in prilohy)
                tab = ("<div class='posun'><table class='data raditelna'><thead><tr><th>#</th><th>Typ</th>"
                       "<th>Původní název</th><th>Soubor</th><th>Velikost</th><th>Obsah</th></tr></thead>"
                       "<tbody>%s</tbody></table></div>" % radky)
            else:
                tab = "<p class='tlumene'>Bez dokumentů.</p>"
            casti.append("""<details class="verze-karta"%(open)s><summary><span class="verze-typ %(tid)s">%(typ)s</span>
<b>%(datum)s</b> <span class="tlumene">%(stav)s · %(n)d %(dok)s</span>%(nejnovejsi)s</summary>
<div class="karta"><table class="kv">%(udaje)s</table>%(tab)s</div></details>""" % {
                "open": " open" if i == 0 else "", "tid": e((vt.get("id") or "").lower()),
                "typ": e(vt.get("name") or self.TYPY_VERZI.get(vt.get("id"), vt.get("id") or "verze")),
                "datum": e(_fmt_cas_odok(v.get("date_authorized"))), "stav": e(stav), "n": len(prilohy),
                "dok": "dokument" if len(prilohy) == 1 else "dokumenty" if 1 < len(prilohy) < 5 else "dokumentů",
                "nejnovejsi": " <span class='stitek'>nejnovější</span>" if i == 0 else "",
                "udaje": "".join("<tr><th>%s</th><td>%s</td></tr>" % (k, h) for k, h in udaje if h),
                "tab": tab})
        casti.append("<details><summary>Surová data verzí</summary>%s</details></div>" % json_na_html(data))
        return "".join(casti)

    # pořadí a vzhled typů připomínek (review_type.id)
    TYPY_PRIPOMINEK = {"Z": (0, "zasadni", "Zásadní"), "D": (1, "doporucujici", "Doporučující"),
                       "BP": (2, "bez", "Bez připomínek")}

    def _html_pripominky(self, data, pid=None):
        """Připomínky z ODOK jako přehled: souhrn, tabulka podle míst, kdo se nevyjádřil."""
        if not isinstance(data, dict):
            return json_na_html(data)
        rizeni = data.get("review_process") or {}
        mista = rizeni.get("reviewers") or []
        pripominky = data.get("reviews_list") or []

        def typ(r):
            t = r.get("review_type") or {}
            return t.get("id") or "", t.get("name") or ""

        pocty = {}
        for r in pripominky:
            pocty[typ(r)] = pocty.get(typ(r), 0) + 1
        odpovedeli = {((r.get("author") or {}).get("organization") or {}).get("id") for r in pripominky}
        nevyjadrili = [m for m in mista if m.get("id") not in odpovedeli]

        # souhrn
        stitky = "".join(
            "<span class='pripominka %s'>%s: <b>%d</b></span>" % (
                self.TYPY_PRIPOMINEK.get(tid, (9, "jina", ""))[1], e(nazev or tid), n)
            for (tid, nazev), n in sorted(pocty.items(),
                                          key=lambda x: self.TYPY_PRIPOMINEK.get(x[0][0], (9,))[0]))
        souhrn = []
        if rizeni.get("date_finish"):
            souhrn.append("Připomínky do <b>%s</b>" % e(_fmt_cas_odok(rizeni["date_finish"])))
        if mista:
            souhrn.append("%d připomínkových míst, vyjádřilo se %d" % (
                len(mista), len(mista) - len(nevyjadrili)))
        if rizeni.get("email_for_reviews"):
            souhrn.append("e-mail pro připomínky: <span class='vyber'>%s</span>"
                          % e(rizeni["email_for_reviews"]))
        casti = ["<div class='pripominky'>"]
        if souhrn:
            casti.append("<p>%s</p>" % " · ".join(souhrn))
        if stitky:
            casti.append("<div class='lista'>%s</div>" % stitky)

        if pripominky:
            def poradi(r):
                return (self.TYPY_PRIPOMINEK.get(typ(r)[0], (9,))[0], r.get("date_authorized") or "")

            radky = []
            for r in sorted(pripominky, key=poradi):
                tid, tnazev = typ(r)
                org = (r.get("author") or {}).get("organization") or {}
                soubory = "".join(
                    "<div class='soubor'><a href='%s' target='_blank' rel='noopener' title='%s'>%s</a> "
                    "<span class='tlumene'>(%s%s)</span> %s</div>" % (
                        e(a.get("uri") or _odok.URL_DOKUMENT.format(pid=a.get("pid", ""))),
                        e(a.get("name") or ""), e(a.get("old_name") or a.get("name") or "soubor"),
                        e((a.get("type") or {}).get("name") or ""),
                        ", " + e(a["size_str"]) if a.get("size_str") else "",
                        self._tlacitko_nahled(pid, a, a.get("uri") or a.get("pid")))
                    for a in sorted(r.get("attachments") or [], key=lambda a: a.get("order") or 0))
                text = " ".join(x for x in (r.get("notes"), r.get("reason")) if x and x.strip())
                radky.append(
                    "<tr><td><b>%s</b> <span class='tlumene'>%s</span></td>"
                    "<td data-razeni='%d'><span class='pripominka %s'>%s</span></td>"
                    "<td data-razeni='%s'>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
                        e(org.get("id") or ""), e(org.get("name") or ""),
                        self.TYPY_PRIPOMINEK.get(tid, (9,))[0],
                        self.TYPY_PRIPOMINEK.get(tid, (9, "jina"))[1], e(tnazev or tid),
                        e(r.get("date_authorized") or ""), e(_fmt_cas_odok(r.get("date_authorized"))),
                        e((r.get("identifier") or {}).get("id_no_ext") or ""), soubory,
                        "<div class='text-pripominky'>%s</div>" % e(text) if text else ""))
            casti.append("""<div class="lista"><input type="text" data-filtr="pripominky-tab" placeholder="Filtrovat (místo, typ, čj…)">
<span class="tlumene"><span id="pripominky-tab-pocet">%d</span> vyjádření</span></div>
<div class="posun"><table class="data raditelna" id="pripominky-tab"><thead><tr><th>Připomínkové místo</th>
<th>Typ</th><th>Vloženo</th><th>Čj místa</th><th>Soubory</th><th>Text</th></tr></thead><tbody>%s</tbody></table></div>"""
                         % (len(pripominky), "".join(radky)))
        else:
            casti.append("<p class='tlumene'>Zatím nejsou vložené žádné připomínky.</p>")
        if nevyjadrili:
            casti.append("<details><summary>Nevyjádřili se (%d)</summary><p>%s</p></details>" % (
                len(nevyjadrili), ", ".join(
                    "<span title='%s'>%s</span>" % (e(m.get("name") or ""), e(m.get("id") or m.get("name") or ""))
                    for m in sorted(nevyjadrili, key=lambda m: m.get("id") or ""))))
        casti.append("<details><summary>Surová data připomínek</summary>%s</details></div>"
                     % json_na_html(data))
        return "".join(casti)

    def stranka_obnov(self, parametry):
        self.obnov_data()
        return self._presmeruj("/")

    # ---------------- stav a obnova (pro okno aplikace) ----------------

    def obnov_data(self):
        """Stáhne čerstvá data ODOK i Sněmovny (ignoruje cache) a zahodí sestavené podklady."""
        with self._zamek_dat:
            self.odok.obnov(vynutit=True)
        with self._zamek_plpv:
            self._plpv_cache.clear()
            self._plpv_odok.clear()
        if self.psp is not None or _psp is not None:
            self.nacti_psp_na_pozadi(vynutit=True)
        if _esel is not None:
            self.nacti_esel_na_pozadi(vynutit=True)
        self.indexuj_pripominky_na_pozadi()

    def nacti_odok_na_pozadi(self):
        """Načte dataset ODOK hned po startu (jinak by se načítal až při první stránce)."""
        def prace():
            try:
                with self._zamek_dat:
                    self.odok.zaznamy()
            except Exception as chyba:  # noqa: BLE001 – ukáže se ve stavu
                self._odok_chyba = chyba
        self._odok_chyba = None
        threading.Thread(target=prace, daemon=True).start()

    def _hlidej_stari_dat(self):
        """Vlákno na pozadí: data starší než platnost cache se automaticky obnoví."""
        while self._httpd is not None:
            time.sleep(60)
            try:
                stav = self.stav()
                if stav["odok"]["materialu"] is not None and self._ma_se_obnovit(
                        "odok", stav["odok"]["soubory"]):
                    with self._zamek_dat:
                        self.odok.obnov(vynutit=False)   # prošlá cache → stáhne znovu
                    with self._zamek_plpv:
                        self._plpv_cache.clear()
                for o in list(self._odok_roky.values()) + list(self._plpv_odok.values()):
                    if o._zaznamy is None:
                        continue
                    soubory = [("ODOK %s" % rok, self._soubor_info(o._cache_cesta(
                        "requests_zip_%s.zip" % rok), o.max_stari_s)) for rok in o.roky]
                    if self._ma_se_obnovit("odok-%s" % "-".join(map(str, o.roky)), soubory):
                        with self._zamek_dat:
                            o.obnov(vynutit=False)
                if stav["psp"]["stav"] == "načteno" and self._ma_se_obnovit(
                        "psp", stav["psp"]["soubory"]):
                    self.nacti_psp_na_pozadi(zahodit=True)
                if stav["esel"]["stav"] == "načteno" and self._ma_se_obnovit(
                        "esel", stav["esel"]["soubory"]):
                    self.nacti_esel_na_pozadi(zahodit=True)
                    with self._zamek_plpv:
                        self._plpv_cache.clear()
            except Exception as chyba:  # noqa: BLE001 – hlídač nesmí spadnout
                sys.stderr.write("Automatická obnova dat selhala: %s\n" % chyba)

    def _ma_se_obnovit(self, zdroj, soubory, odstup_s=900):
        """Je některý soubor prošlý? Po neúspěšném pokusu (soubor zůstal starý) se další
        pokus dělá nejdřív za odstup_s sekund, aby se při výpadku sítě nestahovalo pořád."""
        if not any(info and not info["platny"] for _n, info in soubory):
            return False
        posledni = self._pokusy_obnovy.get(zdroj, 0)
        if time.time() - posledni < odstup_s:
            return False
        self._pokusy_obnovy[zdroj] = time.time()
        return True

    @staticmethod
    def _soubor_info(cesta, platnost_s):
        """Údaje o souboru cache: kdy stažen a zda je ještě platný."""
        if not cesta or not os.path.exists(cesta):
            return None
        mtime = os.path.getmtime(cesta)
        return {"cesta": cesta, "stazeno": mtime, "stari_s": time.time() - mtime,
                "platny": time.time() - mtime < platnost_s, "platnost_s": platnost_s}

    def stav(self):
        """
        Přehled stavu pro okno aplikace (neblokuje – nečeká na zámky):
        url, běh, dotazy, ODOK (roky, materiály, soubory cache), Sněmovna (stav, období, soubory).
        """
        o = self.odok
        odok_soubory = []
        for rok in o.roky:
            kandidati = [o._cache_cesta(n % rok) for n in ("requests_zip_%s.zip", "requests_%s.json")]
            existujici = [c for c in kandidati if os.path.exists(c)]
            info = self._soubor_info(max(existujici, key=os.path.getmtime) if existujici else None,
                                     o.max_stari_s)
            odok_soubory.append(("ODOK %s" % rok, info))
        zaznamy = o._zaznamy
        odok_stav = ("načteno" if zaznamy is not None else
                     "chyba: %s" % self._odok_chyba if getattr(self, "_odok_chyba", None) else "načítá se")

        psp_stav, psp_soubory, obdobi = "není k dispozici (chybí psp.py)", [], None
        if _psp is not None:
            if self._psp_chyba:
                psp_stav = "chyba: %s" % self._psp_chyba
            elif self._psp_hotovo.is_set():
                psp_stav = "načteno"
            else:
                psp_stav = "načítá se"
            p = self.psp
            if p is not None:
                for archiv in ("tisky", "schuze", "poslanci", "se_tisk"):
                    psp_soubory.append(("Sněmovna %s.zip" % archiv,
                                        self._soubor_info(p._cache_cesta(archiv + ".zip"), p.max_stari_s)))
                if self._psp_hotovo.is_set() and not self._psp_chyba:
                    obdobi = str(p.obdobi) if p.obdobi else "všechna období"
        esel_stav, esel_soubory, navrhu = "není k dispozici (chybí esel.py)", [], None
        if _esel is not None:
            esel_stav = ("chyba: %s" % self._esel_chyba if self._esel_chyba else
                         "načteno" if self._esel_hotovo.is_set() else "načítá se")
            if self.esel is not None:
                popisy = {"100NavrhAktuNavrh": "e-Legislativa návrhy", "105NavrhAktuMetadataAktu": "e-Legislativa metadata"}
                esel_soubory = [(popisy.get(nazev, "e-Legislativa %s" % nazev),
                                 self._soubor_info(cesta, self.esel.max_stari_s))
                                for nazev, cesta in self.esel.soubory_cache()]
                if self._esel_hotovo.is_set() and self.esel._navrhy is not None:
                    navrhu = len(self.esel._navrhy)
        return {
            "url": self.url, "bezi": self.bezi, "dotazu": self._pocet_dotazu,
            "posledni_dotaz": self._posledni_dotaz, "sit": list(o.sit), "cache": o.cache_dir,
            "platnost_h": o.max_stari_s / 3600.0,
            "odok": {"stav": odok_stav, "roky": list(o.roky),
                     "materialu": len(zaznamy) if zaznamy is not None else None,
                     "soubory": odok_soubory},
            "psp": {"stav": psp_stav, "obdobi": obdobi, "soubory": psp_soubory,
                    "tisku_s_eklep": len(self._pid_tisk)},
            "esel": {"stav": esel_stav, "soubory": esel_soubory, "navrhu": navrhu},
            "plpv": sorted("%s %s" % k for k in self._plpv_cache),
            "pripominky": {"cj": len(self._index_cj), "materialu": len(self._index_pid)},
        }

    # ---------------- Sněmovna (psp.py) ----------------

    def nacti_psp_na_pozadi(self, vynutit=False, zahodit=False):
        """Spustí (znovu)načtení dat Sněmovny ve vlákně na pozadí.
        vynutit – stáhnout i při platné cache; zahodit – znovu načíst data z cache/sítě."""
        if _psp is None:
            return
        self._psp_hotovo.clear()
        self._psp_chyba = None
        threading.Thread(target=self._nacti_psp, args=(vynutit, zahodit), daemon=True).start()

    def _nacti_psp(self, vynutit, zahodit=False):
        try:
            with self._zamek_psp:
                if self.psp is None:
                    self.psp = _psp.Psp(sit=list(self.odok.sit), proxy=self.odok._proxy_param,
                                        verbose=self.odok.verbose)
                if vynutit:
                    self.psp.obnov()
                elif zahodit:
                    self.psp.zahod_nactena_data()
                for tabulka in ("tisky", "hist", "stavy", "typ_stavu", "prechody", "typ_akce",
                                "druh_tisku", "typ_zakon", "tisk_eklep", "tz_eklep", "schuze",
                                "bod_schuze", "bod_stav", "tisky_za", "vysledek"):
                    self.psp.tabulka(tabulka)
                for tabulka in ("se_tisk", "se_druh_tisku", "psp2senat"):   # Senát je volitelný
                    self.psp._bezpecne(lambda: self.psp.tabulka(tabulka))
                _ = self.psp.obdobi
                mapa = {}
                for tab in ("tisk_eklep", "tz_eklep"):
                    for r in self.psp.tabulka(tab):
                        pid = (r.get("pid") or "").strip().upper()
                        if pid and r.get("id_tisk"):
                            mapa.setdefault(pid, r["id_tisk"])
                self._pid_tisk = mapa
                print("Data Sněmovny načtena (%s, %d tisků s vazbou na eKLEP)."
                      % (self.psp.obdobi, len(mapa)))
        except Exception as chyba:  # noqa: BLE001 – chybu ukážeme na stránkách
            self._psp_chyba = chyba
            sys.stderr.write("Data Sněmovny se nepodařilo načíst: %s\n" % chyba)
        finally:
            self._psp_hotovo.set()

    # ---------------- analýza relevance (relevance.py) ----------------

    def _dokument(self, pid, pid_dokumentu):
        """(popis dokumentu, bytes) – dokument materiálu, některé jeho verze nebo připomínky.
        Dokument se v ODOK nemění → stáhne se jen jednou a pak se bere z %TEMP% (bez ohledu na stáří)."""
        with self._zamek_dat:
            m = self._najdi_material(pid)
            d = next((x for x in m.dokumenty() if x.get("pid") == pid_dokumentu), None)
            url = m.url_dokumentu(d) if d is not None else None
            zdroje = (("verze", "versions_list"), ("pripominky", "reviews_list"))
            for metoda, klic in zdroje:          # dokument z historické verze nebo z připomínky
                if d is not None:
                    break
                try:
                    seznam = getattr(self.odok, metoda)(pid).get(klic) or []
                except (OdokChyba, AttributeError, ValueError):
                    seznam = []
                d = next((a for v in seznam for a in (v.get("attachments") or [])
                          if a.get("pid") == pid_dokumentu), None)
                if d is not None:
                    url = d.get("uri") or _odok.URL_DOKUMENT.format(pid=pid_dokumentu)
            if d is None:
                raise OdokChyba("Dokument %s u materiálu %s nenalezen." % (pid_dokumentu, pid))
        nazev_cache = "dokument_%s.docx" % re.sub(r"\W", "", pid_dokumentu)   # formát se pozná z obsahu
        cesta = self.odok._cache_cesta(nazev_cache)
        if os.path.exists(cesta) and os.path.getsize(cesta) > 0:
            with open(cesta, "rb") as f:
                return d, f.read()
        return d, self.odok._ziskej_s_cache(url, nazev_cache, vynutit=True)

    def cast_nahled(self, pid, pid_dokumentu):
        """HTML fragment: obsah dokumentu (Word se strukturou a tabulkami, .doc a PDF jako text)."""
        if _relevance is None or not hasattr(_relevance, "dokument_na_html"):
            raise OdokChyba("Náhled není k dispozici – vedle odok_web.py chybí aktuální relevance.py.")
        d, obsah = self._dokument(pid, pid_dokumentu)
        try:
            telo = _relevance.dokument_na_html(obsah)
        except _relevance.RelevanceChyba as chyba:
            raise OdokChyba(str(chyba))
        url = d.get("uri") or _odok.URL_DOKUMENT.format(pid=pid_dokumentu)
        return ("<div class='nahled'><div class='lista'><b>%s</b><span class='tlumene'>%s</span>"
                "<a href='%s' target='_blank' rel='noopener'>stáhnout ↗</a>"
                "</div><div class='nahled-obsah'>%s</div></div>" % (
                    e(d.get("old_name") or d.get("name") or "dokument"),
                    e((d.get("type") or {}).get("name") or "") if isinstance(d.get("type"), dict) else "",
                    e(url), telo or "<p class='tlumene'>Dokument neobsahuje žádný text.</p>"))

    def cast_relevance(self, pid, pid_dokumentu):
        """HTML fragment: pro které útvary je dokument relevantní a proč."""
        if self.relevance is None:
            raise OdokChyba("Analýza relevance není k dispozici – vedle odok_web.py chybí relevance.py.")
        d, obsah = self._dokument(pid, pid_dokumentu)
        import io
        try:
            v = self.relevance.analyzuj_dokument(obsah) if hasattr(self.relevance, "analyzuj_dokument") \
                else self.relevance.analyzuj_docx(io.BytesIO(obsah))
        except _relevance.RelevanceChyba as chyba:
            raise OdokChyba(str(chyba))
        relevantni = [u for u in v["utvary"] if u["skore"] > 0]
        nazev = d.get("old_name") or d.get("name") or ""
        casti = ["<div class='relevance'>"]
        if relevantni:
            nejvic = relevantni[0]["skore"] or 1
            radky = []
            for u in relevantni:
                duvody = "".join(
                    "<li><b>%s</b> – %d× %s%s%s</li>" % (
                        e(n["priklad"] or n["vzor"]), n["pocet"],
                        "" if n["vahy"][u["utvar"]] == 1 else "(váha %s) " % e(str(n["vahy"][u["utvar"]])),
                        "<span class='tlumene'>%s</span>" % e(n["poznamka"]) if n["poznamka"] else "",
                        "<div class='ukazky'>%s</div>" % "".join(
                            "<div>%s<mark>%s</mark>%s</div>" % (e(a), e(b), e(c)) for a, b, c in n["ukazky"]))
                    for n in u["duvody"])
                radky.append(
                    "<tr><td><b>%s</b></td><td data-razeni='%09.2f'><span class='sloupek' style='width:%dpx'>"
                    "</span> %s</td><td>%d</td><td><details open><summary>%d %s</summary><ul>%s</ul>"
                    "</details></td></tr>" % (
                        e(u["utvar"]), u["skore"], max(4, int(80 * u["skore"] / nejvic)), e(u["skore_text"]),
                        u["vyskytu"], u["vyrazu"], "výraz" if u["vyrazu"] == 1 else
                        "výrazy" if u["vyrazu"] < 5 else "výrazů", duvody))
            casti.append("<p>Dokument <b>%s</b> může být relevantní pro: %s</p>" % (
                e(nazev), ", ".join("<b>%s</b>" % e(u["utvar"]) for u in relevantni)))
            casti.append("<div class='posun'><table class='data raditelna'><thead><tr><th>Útvar</th>"
                         "<th>Skóre</th><th>Výskytů</th><th>Proč</th></tr></thead><tbody>%s</tbody>"
                         "</table></div>" % "".join(radky))
        else:
            casti.append("<p>V dokumentu <b>%s</b> se nenašel žádný výraz ze seznamu – podle něj není "
                         "relevantní pro žádný útvar.</p>" % e(nazev))
        bez = [u["utvar"] for u in v["utvary"] if u["skore"] <= 0]
        if relevantni and bez:
            casti.append("<p class='tlumene'>Bez nálezu: %s</p>" % e(", ".join(bez)))
        bez_utvaru = [n for n in v["nalezy"] if not n["vahy"]]
        if bez_utvaru:
            casti.append("<p class='tlumene'>Nalezeno, ale bez přiřazeného útvaru: %s</p>" % e(", ".join(
                "%s (%d×)" % (n["priklad"] or n["vzor"], n["pocet"]) for n in bez_utvaru)))
        if v["chyby"]:
            casti.append("<p class='tlumene'>Chyby v seznamu výrazů (řádky přeskočeny): %s</p>"
                         % e("; ".join(v["chyby"])))
        casti.append("<div class='lista'><button type='button' class='tlacitko-mini' data-relevance-znovu>"
                     "↻ Přegenerovat relevanci</button><span class='tlumene'>Prohledáno %d znaků textu podle "
                     "%d výrazů ze souboru <code>%s</code> (načten %s). Po úpravě seznamu výrazů stačí "
                     "přegenerovat.</span></div>" % (
                         v["znaku"], len(self.relevance.pravidla), e(self.relevance.soubor),
                         _dt.datetime.now().strftime("%H:%M:%S")))
        casti.append("</div>")
        return "".join(casti)

    # ---------------- e-Legislativa (esel.py) ----------------

    def nacti_esel_na_pozadi(self, vynutit=False, zahodit=False):
        """Spustí (znovu)načtení dat e-Legislativy ve vlákně na pozadí."""
        if _esel is None:
            return
        self._esel_hotovo.clear()
        self._esel_chyba = None
        threading.Thread(target=self._nacti_esel, args=(vynutit, zahodit), daemon=True).start()

    def _nacti_esel(self, vynutit, zahodit=False):
        try:
            with self._zamek_esel:
                if self.esel is None:
                    self.esel = _esel.Esel(sit=list(self.odok.sit), proxy=self.odok._proxy_param,
                                           verbose=self.odok.verbose)
                if vynutit:
                    self.esel.obnov()
                elif zahodit:
                    self.esel.zahod_nactena_data()
                print("Data e-Legislativy načtena (%d návrhů)." % len(self.esel.navrhy()))
        except Exception as chyba:  # noqa: BLE001 – ukáže se ve stavu a na stránkách
            self._esel_chyba = chyba
            sys.stderr.write("Data e-Legislativy se nepodařilo načíst: %s\n" % chyba)
        finally:
            self._esel_hotovo.set()

    def _legislativa(self, cekat=300):
        """Vrátí načtenou instanci Esel (případně počká na dokončení načítání)."""
        if _esel is None:
            raise OdokChyba("e-Legislativa není k dispozici – vedle odok_web.py chybí esel.py.")
        if not self._esel_hotovo.is_set() and self.esel is None and not self._esel_chyba:
            self.nacti_esel_na_pozadi()
        if not self._esel_hotovo.wait(cekat):
            raise OdokChyba("Data e-Legislativy se stále načítají, zkuste to prosím za chvíli.")
        if self._esel_chyba:
            raise OdokChyba("Data e-Legislativy se nepodařilo načíst: %s" % self._esel_chyba)
        return self.esel

    def cast_esel_k_materialu(self, pid):
        """HTML fragment pro detail materiálu: návrh v e-Legislativě s fázemi a stavy."""
        with self._zamek_dat:
            m = self._najdi_material(pid)
        es = self._legislativa()
        with self._zamek_esel:
            nalez = es.k_materialu(m)
        if nalez is None:
            return ("<p class='tlumene'>K materiálu se v otevřených datech e-Legislativy nepodařilo "
                    "najít návrh (hledá se podle čj OVA, čj předkladatele a podobnosti názvu). "
                    "Podzákonné předpisy vytvořené mimo e-Legislativu v ní nejsou.</p>")
        n, zpusob, jistota = nalez
        vazba = zpusob if jistota >= 1 else "%s (shoda %d %%)" % (zpusob, round(jistota * 100))
        druhy = {"VYHLASKA": "vyhláška", "NARIZVLA": "nařízení vlády", "ZAKON": "zákon"}

        def den(x):
            if x is None:
                return ""
            if hasattr(x, "year"):
                return e(fmt_datum(x.date() if hasattr(x, "hour") else x))
            return e(x)

        def cas(x):
            return "%s %d:%02d" % (fmt_datum(x.date()), x.hour, x.minute) if x else ""

        radky = [("Návrh", "<b>%s</b> · %s" % (e(n.identifikator or ""),
                                                odkazy_esel(n, "otevřít v e-Legislativě"))),
                 ("Vazba nalezena podle", e(vazba)),
                 ("Název v e-Legislativě", e(n.nazev_aktu) if n.nazev_aktu and n.nazev_aktu != m.nazev
                  else ""),
                 ("Druh", e(druhy.get(n.druh, n.druh or ""))),
                 ("Typ procesu", e(n.typ_procesu or "")),
                 ("Čj OVA", e(n.cj_ova or "")),
                 ("Čj předkladatele", e(", ".join(sorted(n.cj_predkladatele)))),
                 ("Aktuální stav", "<span class='stitek'>%s</span>" % e(n.aktualni_stav)
                  if n.aktualni_stav else ""),
                 ("Sbírka", "<b>%s</b>" % odkaz_sbirka(n.citace) if n.citace else ""),
                 ("Vyhlášeno", den(n.datum_vyhlaseni)),
                 ("Schváleno", den(n.datum_schvaleni)),
                 ("Účinnost", den(n.ucinnost)),
                 ("Kompatibilita s EU", e(n.kompatibilita_eu or "")),
                 ("Termín pro implementaci", den(n.termin_implementace))]
        karta = "<div class='karta'><table class='kv'>%s</table></div>" % "".join(
            "<tr><th>%s</th><td>%s</td></tr>" % (k, v) for k, v in radky if v)
        stavy = n.stavy()
        if not stavy:
            return karta
        telo = "".join(
            "<tr><td>%s</td><td>%s</td><td data-razeni='%s'>%s</td><td data-razeni='%s'>%s</td></tr>" % (
                e(s["faze"] or ""), e(re.sub(r"\s*\((\d+)\)$", r" (\1. kolo)", s["nazev"] or "")),
                e(s["od"].isoformat() if s["od"] else ""), e(cas(s["od"])),
                e(s["do"].isoformat() if s["do"] else "9999"),
                e(cas(s["do"])) if s["do"] else "<span class='stitek'>probíhá</span>")
            for s in stavy)
        return karta + (
            "<h3>Fáze a stavy procesu</h3><div class='posun'><table class='data raditelna'>"
            "<thead><tr><th>Fáze</th><th>Stav</th><th>Od</th><th>Do</th></tr></thead>"
            "<tbody>%s</tbody></table></div>" % telo)

    def _snemovna(self, cekat=300):
        """Vrátí načtenou instanci Psp (případně počká na dokončení načítání)."""
        if _psp is None:
            raise OdokChyba("Sněmovní část není k dispozici – vedle odok_web.py chybí psp.py.")
        if not self._psp_hotovo.is_set() and self.psp is None and not self._psp_chyba:
            self.nacti_psp_na_pozadi()
        if not self._psp_hotovo.wait(cekat):
            raise OdokChyba("Data Sněmovny se stále načítají, zkuste to prosím za chvíli.")
        if self._psp_chyba:
            raise OdokChyba("Data Sněmovny se nepodařilo načíst: %s" % self._psp_chyba)
        return self.psp

    @staticmethod
    def _url_psp(url):
        if not url:
            return None
        return "https://www.psp.cz" + url if url.startswith("/") else url

    def _odkaz_tisk_kratky(self, id_tisk):
        """Odkaz na tisk podle id_tisk: '35' (u jiného období '35 (9.)')."""
        p = self._psp_pro()
        radek = p._jeden("tisky", "id_tisk", id_tisk)
        if not radek:
            return ""
        t = _psp.Tisk(radek, p)
        aktualni = p.obdobi.cislo if p.obdobi else None
        text = t.cislo if t.obdobi == aktualni else "%s (%s. obd.)" % (t.cislo, t.obdobi)
        return "<a href='%s' title='%s'>%s</a>" % (
            e(odkaz("/tisk", id="id:%s" % id_tisk)), e(t.nazev), e(text))

    @staticmethod
    def _bunka_etapy(etapa, doplnek=""):
        """Buňka tabulky s koncem etapy (nebo 'probíhá'); doplnek = HTML pod datem."""
        if doplnek:
            doplnek = "<div class='mensi'>%s</div>" % doplnek
        if not etapa:
            return "<td>%s</td>" % doplnek
        if etapa["probiha"]:
            return "<td data-razeni='9999'><span class='stitek'>probíhá</span>%s</td>" % doplnek
        d = etapa["do"]
        return "<td data-razeni='%s' title='%s'>%s%s</td>" % (
            e(d.isoformat() if hasattr(d, "isoformat") else ""), e(etapa["akce"]),
            e(fmt_datum(d.date() if hasattr(d, "date") else d)), doplnek)

    @staticmethod
    def _text_lhuty_senatu(l, kratce=False):
        """Text 30denní lhůty Senátu: 'do 3. 11. 2026 (zbývá 12 dní)'."""
        if not l:
            return ""
        if not l.get("doruceno"):
            return "odesláno %s, zatím nedoručeno" % fmt_datum(_jako_den(l.get("odeslano"))) \
                if l.get("odeslano") else ""
        if l.get("rozhodnuto") or l.get("akce"):
            if kratce:
                return ""
            return "doručeno %s, lhůta do %s; Senát rozhodl%s (%s)" % (
                fmt_datum(_jako_den(l["doruceno"])), fmt_datum(l["lhuta_do"]),
                " " + fmt_datum(_jako_den(l["rozhodnuto"])) if l.get("rozhodnuto") else "",
                l.get("akce") or "")
        if l.get("zaniklo"):
            return "" if kratce else "doručeno %s, lhůta do %s; projednávání skončilo koncem volebního období" % (
                fmt_datum(_jako_den(l["doruceno"])), fmt_datum(l["lhuta_do"]))
        if not l.get("lhuta_do") or l.get("zbyva_dni") is None:
            return "doručeno %s" % fmt_datum(_jako_den(l["doruceno"]))
        n = l["zbyva_dni"]
        zbyva = ("lhůta uplynula" if n < 0 else "poslední den lhůty" if n == 0 else
                 "zbývá %d %s" % (n, "den" if n == 1 else "dny" if n < 5 else "dní"))
        if kratce:
            return "lhůta do %s (%s)" % (fmt_datum(l["lhuta_do"]), zbyva)
        return "doručeno %s → lhůta do %s (%s)" % (fmt_datum(_jako_den(l["doruceno"])),
                                                  fmt_datum(l["lhuta_do"]), zbyva)

    @staticmethod
    def _senat_kratky(senatni, jen_postoupene=False):
        """Odkazy na senátní tisky: 'ST 287 (15. FO)' → senat.cz."""
        vyber = [x for x in senatni if not jen_postoupene or x["typ"] in (20, 21, 30)]
        return ", ".join(
            "<a href='%s' target='_blank' rel='noopener' title='%s – %s'>ST %s ↗</a>" % (
                e(x["url"]), e(x["anotace"] or ""), e(x["stav_nazev"]), e(x["cislo"]))
            for x in vyber)

    @staticmethod
    def _stitek_stavu(t):
        puvodni = getattr(t, "stav_v_datech", None) if getattr(t, "zanikl_koncem_obdobi", False) else None
        return "<span class='stitek%s'%s>%s</span>" % (
            " konec" if t.projednano else "",
            " title='V datech Sněmovny zůstal stav: %s'" % e(puvodni) if puvodni else "", e(t.stav or "?"))

    @staticmethod
    def _bod_kratky(b):
        if not b:
            return ""
        return "<a href='%s'>%s. schůze</a> · %s%s" % (
            e(odkaz("/schuze", c=b.schuze)), e(b.schuze), e(b.typ or "bod %s" % b.bod),
            " <span class='tlumene'>(navrženo)</span>" if b.navrzeny_porad else "")

    def stranka_tisky(self, parametry):
        filtr = parametry.get("f", "vse")
        if filtr == "neprojednane":          # dřívější název volby
            filtr = "projednavane"
        druh = parametry.get("d", "vse")
        with self._zamek_psp:
            p = self._psp_pro()
            zakony = p.zakony(jen_vladni=(druh == "vladni"), jen_neprojednane=(filtr == "projednavane"),
                              jen_ukoncene=(filtr == "ukoncene"))
            radky = []
            klice = self.oblibene.klice()
            for t in zakony:
                pid = t.pid_eklep
                etapy = t.etapy()
                senat = self._senat_kratky(t.senatni_tisky(), jen_postoupene=True) \
                    if hasattr(t, "senatni_tisky") else ""
                lhuta = self._text_lhuty_senatu((etapy.get("Senát") or {}).get("lhuta"), kratce=True)
                senat = "<br>".join(x for x in (lhuta and "<span class='lhuta'>%s</span>" % e(lhuta), senat) if x)
                bunky_etap = "".join(self._bunka_etapy(etapy.get(n), senat if n == "Senát" else "")
                                     for n in _psp.NAZVY_ETAP)
                radky.append((
                    "<tr>" + self._bunka_hvezdy("tisk", t.id_tisk, "Tisk %s – %s" % (t.cislo, t.nazev),
                                                klice).replace("%", "%%") +
                    "<td><a href='%s'>%s</a></td><td title='%s'>%s</td><td>%s</td><td>%s</td>"
                    "<td>%s</td><td data-razeni='%s'>%s</td>" + bunky_etap.replace("%", "%%") +
                    "<td>%s</td><td>%s</td></tr>") % (
                        e(odkaz("/tisk", id="id:%s" % t.id_tisk)), e(t.cislo),
                        e(t.uplny_nazev), e(t.nazev),
                        e(_psp.DRUHY_ZAKONU.get(t.data.get("id_druh"), t.druh)),
                        e(t.navrhovatel), self._stitek_stavu(t),
                        e(t.datum_stavu.isoformat() if hasattr(t.datum_stavu, "isoformat") else ""),
                        e(fmt_datum(t.datum_stavu)), self._bod_kratky(t.aktualni_bod()),
                        "<a href='%s'>%s</a>" % (e(odkaz("/material", id=pid)), e(pid)) if pid else ""))
            obdobi = str(p.obdobi) if p.obdobi else "všechna období"

        def volba(klic, hodnota, text, aktualni):
            parametry_odkazu = {"f": filtr, "d": druh}
            parametry_odkazu[klic] = hodnota
            return "<a class='tlacitko%s' href='%s'>%s</a>" % (
                " aktivni" if aktualni == hodnota else "", e(odkaz("/tisky", **parametry_odkazu)), text)

        obsah = """<h1>Návrhy zákonů <span class="tlumene">%(obdobi)s</span></h1>
<div class="lista filtry">%(f1)s%(f2)s &nbsp; %(d1)s%(d2)s</div>
<div class="lista"><input type="text" data-filtr="tisky" placeholder="Filtrovat v tabulce…">
%(jen_obl)s<span class="tlumene"><span id="tisky-pocet">%(n)d</span> tisků</span></div>
<div class="posun ukotvit" data-sloupcu="3"><table class="data raditelna" id="tisky"><thead><tr>
<th title="Oblíbené">⭐</th><th>Tisk</th><th>Název</th><th>Druh</th><th>Navrhovatel</th><th>Aktuální stav</th><th>Od</th>
<th title="Konec 1. čtení">1. čtení</th><th title="Usnesení garančního výboru po 1. čtení">GV</th>
<th title="Konec 2. čtení">2. čtení</th><th title="Usnesení garančního výboru po 2. čtení">GV</th>
<th title="Konec 3. čtení">3. čtení</th><th title="Senát">Senát</th>
<th>Schůze</th><th>eKLEP</th></tr></thead><tbody>%(radky)s</tbody></table></div>
<p class="tlumene">Sloupce etap ukazují datum ukončení (u garančního výboru rozeslání jeho usnesení);
po najetí myší je vidět, jakým krokem etapa skončila.</p>""" % {
            "obdobi": e(obdobi), "n": len(radky), "radky": "".join(radky),
            "jen_obl": self._tlacitko_jen_oblibene("tisky"),
            "f1": volba("f", "vse", "Všechny", filtr),
            "f2": volba("f", "projednavane", "Projednávané", filtr) +
                  volba("f", "ukoncene", "Ukončené (vč. vyhlášených)", filtr),
            "d1": volba("d", "vse", "Všechny návrhy", druh),
            "d2": volba("d", "vladni", "Jen vládní", druh)}
        return self._stranka("Sněmovní tisky", obsah, sekce="tisky")

    def stranka_tisk(self, parametry):
        ident = parametry.get("id", "")
        with self._zamek_psp:
            p = self._psp_pro()
            t = p.tisk(ident)
            senatni = t.senatni_tisky() if hasattr(t, "senatni_tisky") else []
            info = [("Druh", e(t.druh)), ("Navrhovatel", e(t.navrhovatel)),
                    ("Typ navrhovatele", e(t.typ_navrhovatele)),
                    ("Předkladatelé", e(", ".join(x for x in t.predkladatele() if x))),
                    ("Předloženo", e(fmt_datum(t.predlozeno))),
                    ("Rozesláno", e(fmt_datum(t.rozeslano))),
                    ("Stav", "%s · od %s" % (self._stitek_stavu(t), e(fmt_datum(t.datum_stavu)))),
                    ("Lhůta", e(t.lhuta)),
                    ("Aktuální schůze", self._bod_kratky(t.aktualni_bod())),
                    ("Další projednávání", e(fmt_datum(t.dalsi_projednavani))),
                    ("Senát", self._senat_kratky(senatni)),
                    ("Lhůta Senátu", e(self._text_lhuty_senatu(t.lhuta_senatu()))
                     if hasattr(t, "lhuta_senatu") else ""),
                    ("Sbírka zákonů", odkaz_sbirka(t.sbirka)),
                    ("Úplný název", e(t.uplny_nazev))]
            historie = t.historie()
            etapy = t.etapy()
            body = t.body_schuze()
            vybory = t.vybory()
            nasledne = t.nasledne_tisky()
            eklep = t.eklep()
            nadpis = "Sněmovní tisk %s" % t.cislo
            podnadpis = "%s. volební období" % t.obdobi if t.obdobi else ""
            nazev = t.nazev
            id_tisk = t.id_tisk
            url_psp, url_text = t.url, self._url_psp(t.url_text)

        radky_info = "".join("<tr><th>%s</th><td>%s</td></tr>" % (k, v) for k, v in info if v)

        if eklep:
            tab_eklep = "<div class='posun'><table class='data'><thead><tr><th>Tisk</th><th>PID</th>" \
                        "<th>Čj OVA</th><th>Veřejné</th><th></th></tr></thead><tbody>%s</tbody></table></div>" % "".join(
                "<tr><td>%s%s</td><td><a href='%s'>%s</a></td><td>%s</td><td>%s</td>"
                "<td><a href='%s' target='_blank' rel='noopener'>ODOK ↗</a></td></tr>" % (
                    e(x["tisk"]), " <span class='tlumene'>(následný)</span>" if x["nasledny"] else "",
                    e(odkaz("/material", id=x["pid"])), e(x["pid"]), e(x["cj_ova"]),
                    "ano" if x["verejne"] else "ne", e(x["url"])) for x in eklep)
        else:
            tab_eklep = "<p class='tlumene'>Tisk nemá v datech Sněmovny vazbu na eKLEP.</p>"

        tab_etapy = "<div class='posun'><table class='data'><thead><tr><th>Etapa</th><th>Začátek</th>" \
                    "<th>Konec</th><th>Jak skončila</th></tr></thead><tbody>%s</tbody></table></div>" % "".join(
            "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
                e(nazev), e(fmt_datum(x["od"])) + (" <span class='tlumene'>(doručení)</span>"
                                                   if (x.get("lhuta") or {}).get("doruceno") else ""),
                "<span class='stitek'>probíhá</span>" if x["probiha"] else e(fmt_datum(x["do"])),
                e(x["akce"]) + ("<div class='lhuta'>%s</div>" % e(self._text_lhuty_senatu(x["lhuta"], kratce=True))
                                if x.get("lhuta") and x["probiha"] else ""))
            for nazev, x in etapy.items()) if etapy else \
            "<p class='tlumene'>Tisk zatím neprošel žádnou etapou projednávání.</p>"

        tab_hist = "<div class='posun'><table class='data'><thead><tr><th>Datum</th><th>Akce</th>" \
                   "<th>Nový stav</th><th>Schůze</th><th>Usnesení</th><th>Hlasování</th><th>Sbírka</th>" \
                   "<th>Poznámka</th></tr></thead><tbody>%s</tbody></table></div>" % "".join(
            "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
                e(fmt_datum(k.datum)), e(k.akce), e(k.stav),
                ("<a href='%s'>%s. schůze</a>%s" % (e(odkaz("/schuze", c=k.schuze)), e(k.schuze),
                                                     ", bod %s" % e(k.bod) if k.bod else "")) if k.schuze else "",
                e(k.usneseni), ("<a href='%s' target='_blank' rel='noopener'>%s ↗</a>"
                                % (e(k.url_hlasovani), e(k.id_hlasovani))) if k.id_hlasovani else "",
                odkaz_sbirka(k.sbirka), e(k.poznamka)) for k in historie) if historie else \
            "<p class='tlumene'>Bez záznamů.</p>"

        tab_body = "<div class='posun'><table class='data'><thead><tr><th>Schůze</th><th>Termín</th>" \
                   "<th>Bod</th><th>Projednávání</th><th>Stav bodu</th></tr></thead><tbody>%s</tbody>" \
                   "</table></div>" % "".join(
            "<tr><td><a href='%s'>%s. schůze</a></td><td>%s – %s</td><td>%s</td><td>%s%s</td><td>%s</td></tr>" % (
                e(odkaz("/schuze", c=b.schuze)), e(b.schuze), e(fmt_datum(b.od)), e(fmt_datum(b.do)),
                e(b.bod), e(b.typ), " <span class='tlumene'>(navržený pořad)</span>" if b.navrzeny_porad else "",
                e(b.stav)) for b in body) if body else "<p class='tlumene'>Tisk zatím nebyl na pořadu schůze.</p>"

        tab_vybory = "<div class='posun'><table class='data'><thead><tr><th>Výbor</th><th>Typ</th>" \
                     "<th>Garanční</th><th>Zpravodaj</th></tr></thead><tbody>%s</tbody></table></div>" % "".join(
            "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
                e(v["vybor"]), e(v["typ"]), "ano" if v["garancni"] else "", e(v["zpravodaj"]))
            for v in vybory) if vybory else "<p class='tlumene'>Bez přikázání výborům.</p>"

        tab_nasl = "<div class='posun'><table class='data'><thead><tr><th>Tisk</th><th>Druh</th>" \
                   "<th>Název</th><th>Rozesláno</th><th>Výsledek</th></tr></thead><tbody>%s</tbody>" \
                   "</table></div>" % "".join(
            "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
                ("<a href='%s' target='_blank' rel='noopener'>%s ↗</a>" % (e(self._url_psp(n["url"])), e(n["cislo"])))
                if n["url"] else e(n["cislo"]),
                e(n["druh"]), e(n["nazev"]), e(fmt_datum(n["rozeslano"])), e(n["vysledek"]))
            for n in nasledne) if nasledne else "<p class='tlumene'>Žádné následné tisky.</p>"

        tab_senat = "<div class='posun'><table class='data'><thead><tr><th>Senátní tisk</th><th>Období</th>" \
                    "<th>Druh</th><th>Stav</th><th>Vazba</th><th>Název</th></tr></thead><tbody>%s</tbody>" \
                    "</table></div>" % "".join(
            "<tr><td><a href='%s' target='_blank' rel='noopener'>%s ↗</a></td><td>%s. funkční období</td>"
            "<td>%s</td><td><span class='stitek'>%s</span></td><td>%s</td><td><b>%s</b><div class='tlumene'>%s"
            "</div></td></tr>" % (
                e(x["url"]), e(x["cislo"]), e(x["obdobi"]), e(x["druh"] or ""), e(x["stav_nazev"]),
                e(x["typ_popis"]), e(x["anotace"] or ""), e(x["nazev"] or ""))
            for x in senatni) if senatni else "<p class='tlumene'>Tisk zatím nebyl postoupen Senátu.</p>"

        odkazy = " · ".join(x for x in (
            "<a href='%s' target='_blank' rel='noopener'>historie na psp.cz ↗</a>" % e(url_psp) if url_psp else "",
            "<a href='%s' target='_blank' rel='noopener'>text tisku ↗</a>" % e(url_text) if url_text else "") if x)
        obsah = """<h1>%(hvezda)s%(nadpis)s <span class="tlumene">%(podnadpis)s</span></h1>
<p>%(nazev)s</p><p class="tlumene">%(odkazy)s</p>
<div class="karta"><table class="kv">%(info)s</table></div>
<h2>Vazba na eKLEP / ODOK</h2>%(eklep)s
<h2>Etapy projednávání</h2>%(etapy)s
<h2>Legislativní proces</h2>%(hist)s
<h2>Projednávání na schůzích</h2>%(body)s
<h2>Výbory</h2>%(vybory)s
<h2>Následné tisky</h2>%(nasl)s
<h2>Senát</h2>%(senat)s""" % {
            "nadpis": e(nadpis), "podnadpis": e(podnadpis),
            "hvezda": self.hvezda("tisk", id_tisk, "%s – %s" % (nadpis, nazev)), "nazev": e(nazev), "odkazy": odkazy,
            "info": radky_info, "eklep": tab_eklep, "etapy": tab_etapy, "hist": tab_hist, "body": tab_body,
            "vybory": tab_vybory, "nasl": tab_nasl, "senat": tab_senat}
        return self._stranka(nadpis, obsah, sekce="tisky")

    def stranka_schuze(self, parametry):
        cislo = parametry.get("c")
        with self._zamek_psp:
            p = self._psp_pro()
            obdobi = str(p.obdobi) if p.obdobi else ""
            if not cislo:
                seznam = p.schuze()
                radky = "".join(
                    "<tr><td><a href='%s'>%s. schůze</a></td><td>%s</td><td>%s</td><td>%s</td>"
                    "<td>%s</td></tr>" % (
                        e(odkaz("/schuze", c=s["cislo"])), e(s["cislo"]), e(fmt_datum(s["od"])),
                        e(fmt_datum(s["do"])), "navržený" if s["navrzeny_porad"] else "schválený",
                        "<a href='%s' target='_blank' rel='noopener'>psp.cz ↗</a>" % e(s["url"]) if s["url"] else "")
                    for s in seznam)
                obsah = """<h1>Schůze Poslanecké sněmovny <span class="tlumene">%s</span></h1>
<div class="posun"><table class="data"><thead><tr><th>Schůze</th><th>Od</th><th>Do</th><th>Pořad</th><th></th>
</tr></thead><tbody>%s</tbody></table></div>""" % (e(obdobi), radky)
                return self._stranka("Schůze PS", obsah, sekce="schuze")

            cislo = int(cislo)
            body = p.body_schuze(cislo)
            info = next((s for s in p.schuze() if s["cislo"] == cislo), {})
            radky = "".join(
                "<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>" % (
                    e(b.bod), self._odkaz_tisk_kratky(b.id_tisk) if b.id_tisk else "",
                    e(b.typ), e(b.stav), e(b.nazev)) for b in body)
        obsah = """<h1>%(c)s. schůze <span class="tlumene">%(obdobi)s</span></h1>
<p class="tlumene">%(od)s – %(do)s · %(porad)s pořad %(odkaz)s</p>
<div class="lista"><input type="text" data-filtr="porad" placeholder="Filtrovat v pořadu…">
<span class="tlumene"><span id="porad-pocet">%(n)d</span> bodů</span></div>
<div class="posun"><table class="data raditelna" id="porad"><thead><tr><th>Bod</th><th>Tisk</th>
<th>Projednávání</th><th>Stav</th><th>Název</th></tr></thead><tbody>%(radky)s</tbody></table></div>""" % {
            "c": cislo, "obdobi": e(obdobi), "od": e(fmt_datum(info.get("od"))), "do": e(fmt_datum(info.get("do"))),
            "porad": "navržený" if info.get("navrzeny_porad") else "schválený", "n": len(body), "radky": radky,
            "odkaz": "· <a href='%s' target='_blank' rel='noopener'>psp.cz ↗</a>" % e(info["url"]) if info.get("url") else ""}
        return self._stranka("%s. schůze" % cislo, obsah, sekce="schuze")

    def cast_tisk_k_materialu(self, pid):
        """HTML fragment pro detail materiálu: navázaný sněmovní tisk."""
        with self._zamek_psp:
            p = self._psp_pro()
            t = p.tisk_k_materialu(pid)
            if t is None:
                return ("<p class='tlumene'>K materiálu není v datech Sněmovny žádný tisk "
                        "(zatím nebyl předložen, nebo nemá vazbu na eKLEP).</p>")
            vazba = next((x for x in t.eklep() if x["pid"].upper() == pid.upper()), None)
            radky = [("Sněmovní tisk", "<a href='%s'>%s</a> (%s. volební období)" % (
                          e(odkaz("/tisk", id="id:%s" % t.id_tisk)), e(t.cislo), e(t.obdobi))),
                     ("Název", e(t.nazev)),
                     ("Stav", "%s · od %s" % (self._stitek_stavu(t), e(fmt_datum(t.datum_stavu)))),
                     ("Poslední krok", e(t.posledni_krok.akce if t.posledni_krok else "")),
                     ("Schůze", self._bod_kratky(t.aktualni_bod())),
                     ("Senát", self._senat_kratky(t.senatni_tisky()) if hasattr(t, "senatni_tisky") else ""),
                     ("Lhůta Senátu", e(self._text_lhuty_senatu(t.lhuta_senatu()))
                      if hasattr(t, "lhuta_senatu") else ""),
                     ("Sbírka zákonů", odkaz_sbirka(t.sbirka)),
                     ("Čj OVA", e(vazba["cj_ova"]) if vazba else "")]
            if vazba and vazba["nasledny"]:
                radky.insert(1, ("Vazba", "materiál je následným tiskem %s (např. stanovisko vlády)"
                                 % e(vazba["tisk"])))
        return "<div class='karta'><table class='kv'>%s</table></div>" % "".join(
            "<tr><th>%s</th><td>%s</td></tr>" % (k, v) for k, v in radky if v)

    # ---------------- index čísel jednacích připomínek ----------------
    # Čj připomínkových míst nejsou v datové sadě ODOK, jen v JSONu připomínek každého materiálu.
    # Kvůli šetření přenosu se nic navíc nestahuje: index se skládá jen z připomínek, které už
    # jsou v cache (materiály, u kterých se připomínky někdy zobrazily), a doplňuje se průběžně.

    @staticmethod
    def _norm_cj(text):
        return re.sub(r"[^A-Z0-9]", "", _odok.bez_diakritiky(text or "").upper())

    def _pridej_do_indexu(self, pid, data):
        zaznamy = []
        for r in (data.get("reviews_list") or []) if isinstance(data, dict) else []:
            cj = ((r.get("identifier") or {}).get("id_no_ext") or "").strip()
            if not cj:
                continue
            org = (r.get("author") or {}).get("organization") or {}
            zaznamy.append((self._norm_cj(cj), {"pid": pid, "cj": cj, "org": org.get("id") or "",
                                                "org_nazev": org.get("name") or "",
                                                "typ": (r.get("review_type") or {}).get("name") or ""}))
        with self._zamek_indexu:
            for klic in [k for k, v in self._index_cj.items() if any(x["pid"] == pid for x in v)]:
                self._index_cj[klic] = [x for x in self._index_cj[klic] if x["pid"] != pid]
            for klic, z in zaznamy:
                if klic:
                    self._index_cj.setdefault(klic, []).append(z)
            self._index_pid.add(pid)

    def indexuj_pripominky_na_pozadi(self):
        if getattr(self, "_index_bezi", False):
            return
        self._index_bezi = True
        threading.Thread(target=self._indexuj_pripominky, daemon=True).start()

    def _indexuj_pripominky(self):
        import json as _json
        import glob
        try:
            # už stažené soubory připomínek (nic se nestahuje)
            for cesta in glob.glob(os.path.join(self.odok.cache_dir, "pripominky_*.json")):
                pid = os.path.basename(cesta)[11:-5]
                try:
                    with open(cesta, encoding="utf-8-sig") as f:
                        self._pridej_do_indexu(pid, _json.load(f))
                except (OSError, ValueError):
                    continue
        except Exception as chyba:  # noqa: BLE001 – index je jen pomůcka
            sys.stderr.write("Index čj připomínek: %s\n" % chyba)
        finally:
            self._index_bezi = False
            self._index_dokoncen = time.time()

    def hledej_cj_pripominek(self, dotaz, jen_presne=False):
        """Připomínky, jejichž čj odpovídá dotazu (bez ohledu na mezery, pomlčky a diakritiku)."""
        q = self._norm_cj(dotaz)
        if len(q) < 5 or not re.search(r"\d", q):
            return []
        with self._zamek_indexu:
            nalezy = list(self._index_cj.get(q, []))
            if not nalezy and not jen_presne:
                for klic, v in self._index_cj.items():
                    if q in klic:
                        nalezy.extend(v)
                        if len(nalezy) > 50:
                            break
        return nalezy

    def _html_nalezy_pripominek(self, q):
        nalezy = self.hledej_cj_pripominek(q)
        stav = ("<p class='tlumene'>Čísla jednací připomínek se prohledávají jen u materiálů, jejichž "
                "připomínky už byly v aplikaci zobrazeny (%d materiálů).</p>" % len(self._index_pid))
        if not nalezy:
            return ""
        radky = []
        for n in nalezy:
            try:
                with self._zamek_dat:
                    m = self._najdi_material(n["pid"])
                nazev = m.nazev or n["pid"]
            except OdokChyba:
                nazev = n["pid"]
            radky.append("<tr><td><a href='%s'>%s</a></td><td><b>%s</b> <span class='tlumene'>%s</span></td>"
                         "<td>%s</td><td>%s</td></tr>" % (
                             e(odkaz("/material", id=n["pid"])), e(nazev), e(n["org"]), e(n["org_nazev"]),
                             e(n["cj"]), e(n["typ"])))
        return ("<h2>Nalezeno v číslech jednacích připomínek</h2><div class='posun'><table class='data raditelna'>"
                "<thead><tr><th>Materiál</th><th>Připomínkové místo</th><th>Čj připomínky</th><th>Typ</th></tr>"
                "</thead><tbody>%s</tbody></table></div>%s<h2>Materiály</h2>" % ("".join(radky), stav))

    # ---------------- Oblíbené ⭐ ----------------

    def akce_oblibene_prepni(self, parametry):
        typ, ident = parametry.get("typ", ""), parametry.get("id", "")
        oblibeny = self.oblibene.prepni(typ, ident, parametry.get("nazev", ""),
                                        rok=self._vybrane_roky() if typ == "material" else None)
        return {"oblibeny": oblibeny, "pocet": len(self.oblibene)}

    def akce_oblibene_poznamka(self, parametry):
        self.oblibene.nastav(parametry.get("klic", ""), poznamka=parametry.get("text", "")[:2000])
        return {"ok": True}

    def akce_oblibene_videno(self, parametry):
        """Potvrzení změn: uloží aktuální stav (jedné položky nebo všech) jako viděný."""
        klic = parametry.get("klic") or None
        for p in self.oblibene.seznam():
            if klic is None or p["klic"] == klic:
                if p["typ"] == "material":
                    radek = self._oblibeny_material(p)
                else:
                    radek = self._oblibeny_tisk(p)
                if radek["otisk"] is not None:
                    self.oblibene.nastav(p["klic"], videno=radek["otisk"])
        return {"ok": True}

    def _oblibeny_material(self, polozka):
        """Aktuální údaje oblíbeného materiálu z ODOK, Sněmovny a e-Legislativy."""
        pid = polozka["id"]
        r = {"nazev": polozka.get("nazev") or pid, "resort": "", "typ": "", "stav_odok": "",
             "tisk": "", "stav_psp": "", "esel": "", "sbirka": "", "otisk": None, "m": None}
        try:
            with self._zamek_dat:
                m = self._najdi_material(pid, polozka.get("rok"))
        except OdokChyba:
            m = None
        casti = []
        if m is not None and m.nazev:
            r.update(m=m, nazev=m.nazev, resort=m.predkladatel_zkratka or "", typ=m.typ or "",
                     stav_odok=m.stav or "")
            casti.append("ODOK: %s" % (m.stav or "?"))
        psp_ok = _psp is not None and self._psp_hotovo.is_set() and self.psp is not None \
            and not self._psp_chyba
        if psp_ok and pid.upper() in self._pid_tisk:
            with self._zamek_psp:
                t = _psp.Tisk(self.psp._jeden("tisky", "id_tisk", self._pid_tisk[pid.upper()]), self.psp)
                r["tisk"] = self._odkaz_tisk_kratky(t.id_tisk)
                r["stav_psp"] = self._stitek_stavu(t)
                r["sbirka"] = t.sbirka or ""
                casti.append("PS: %s" % t.stav)
        esel_ok = _esel is not None and self._esel_hotovo.is_set() and self.esel is not None \
            and not self._esel_chyba
        if esel_ok and m is not None and m.nazev and m.typ_id in ("NV", "NNV"):
            with self._zamek_esel:
                nalez = self.esel.k_materialu(m)
            if nalez:
                n = nalez[0]
                r["esel"] = n.aktualni_stav or ""
                r["esel_navrh"] = n
                r["sbirka"] = r["sbirka"] or n.citace or ""
                casti.append("eSeL: %s" % (n.aktualni_stav or "?"))
        if r["sbirka"]:
            casti.append("Sb.: %s" % r["sbirka"])
        # otisk jen když jsou načtené všechny dostupné zdroje (ať se nehlásí falešné změny)
        vse_nacteno = m is not None and m.nazev and (_psp is None or self._psp_hotovo.is_set()) \
            and (_esel is None or self._esel_hotovo.is_set())
        r["otisk"] = " · ".join(casti) if vse_nacteno else None
        return r

    def _oblibeny_tisk(self, polozka):
        r = {"nazev": polozka.get("nazev") or "tisk", "odkaz": "", "stav": "", "od": None,
             "etapa": "", "sbirka": "", "pid": "", "otisk": None}
        if _psp is None or not self._psp_hotovo.is_set() or self._psp_chyba or self.psp is None:
            return r
        with self._zamek_psp:
            ident = polozka["id"]
            radek = self.psp._jeden("tisky", "id_tisk", int(ident) if ident.isdigit() else ident) \
                or self.psp._jeden("tisky", "id_tisk", ident)
            if not radek:
                return r
            t = _psp.Tisk(radek, self.psp)
            etapy = t.etapy()
            probiha = next((_psp.NAZVY_ETAP[n] if isinstance(_psp.NAZVY_ETAP, dict) else n
                            for n in _psp.NAZVY_ETAP if (etapy.get(n) or {}).get("probiha")), "")
            if probiha == "Senát":
                probiha += " – " + self._text_lhuty_senatu((etapy.get("Senát") or {}).get("lhuta"), kratce=True)
            r.update(nazev=t.nazev, stav=self._stitek_stavu(t), od=t.datum_stavu, etapa=probiha,
                     sbirka=t.sbirka or "", pid=t.pid_eklep or "",
                     odkaz="<a href='%s'>%s</a>%s" % (
                         e(odkaz("/tisk", id="id:%s" % t.id_tisk)), e(t.cislo),
                         "" if t.obdobi == (self.psp.obdobi.cislo if self.psp.obdobi else None)
                         else " <span class='tlumene'>(%s. obd.)</span>" % e(t.obdobi)))
            r["otisk"] = " · ".join(x for x in ("PS: %s" % t.stav,
                                                "Sb.: %s" % t.sbirka if t.sbirka else "") if x)
        return r

    def _bunka_zmeny(self, polozka, otisk):
        """🔔 když se stav od posledního potvrzení změnil; první zobrazení stav jen uloží."""
        if otisk is None:
            return "<td class='tlumene' data-razeni='0'>…</td>"
        if polozka.get("videno") is None:
            self.oblibene.nastav(polozka["klic"], videno=otisk)
            return "<td data-razeni='0'></td>"
        if polozka["videno"] == otisk:
            return "<td data-razeni='0'></td>"
        return ("<td data-razeni='1'><span class='zmena' title='Dříve: %s&#10;Nyní: %s'>🔔 změna</span> "
                "<button type='button' class='tlacitko-mini' data-videno='%s' title='Beru na vědomí'>✔"
                "</button></td>" % (e(polozka["videno"]), e(otisk), e(polozka["klic"])))

    def stranka_oblibene(self, parametry):
        """Přehled oblíbených materiálů a tisků s aktuálním stavem a hlášením změn."""
        materialy = self.oblibene.seznam("material")
        tisky = self.oblibene.seznam("tisk")
        klice = self.oblibene.klice()
        zmen = 0

        def poznamka(p):
            return "<td><input class='poznamka' data-klic='%s' value='%s' placeholder='poznámka…'></td>" % (
                e(p["klic"]), e(p.get("poznamka") or ""))

        def pridano(p):
            return "<td data-razeni='%s'>%s</td>" % (e(p.get("pridano") or ""),
                                                   e(fmt_datum(_dt.date.fromisoformat(p["pridano"][:10]))
                                                     if p.get("pridano") else ""))

        radky_m = []
        for p in materialy:
            r = self._oblibeny_material(p)
            bunka_zmeny = self._bunka_zmeny(p, r["otisk"])
            zmen += "🔔" in bunka_zmeny
            radky_m.append(
                "<tr>%s<td><a href='%s'>%s</a><div class='tlumene'>%s</div></td><td>%s</td><td>%s</td>"
                "<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>%s%s%s</tr>" % (
                    self._bunka_hvezdy("material", p["id"], r["nazev"], klice),
                    e(odkaz("/material", id=p["id"], rok=p.get("rok") or "")), e(r["nazev"]), e(p["id"]),
                    e(r["resort"]), e(r["typ"]), e(r["stav_odok"]), r["tisk"], r["stav_psp"],
                    odkazy_esel(r.get("esel_navrh"), r["esel"], kratce=True), odkaz_sbirka(r["sbirka"]), bunka_zmeny, poznamka(p), pridano(p)))
        radky_t = []
        for p in tisky:
            r = self._oblibeny_tisk(p)
            bunka_zmeny = self._bunka_zmeny(p, r["otisk"])
            zmen += "🔔" in bunka_zmeny
            od = r["od"]
            radky_t.append(
                "<tr>%s<td>%s</td><td>%s</td><td>%s</td><td data-razeni='%s'>%s</td><td>%s</td>"
                "<td>%s</td><td>%s</td>%s%s%s</tr>" % (
                    self._bunka_hvezdy("tisk", p["id"], r["nazev"], klice),
                    r["odkaz"] or e(p["id"]), e(r["nazev"]), r["stav"],
                    e(od.isoformat() if hasattr(od, "isoformat") else ""), e(fmt_datum(od)),
                    e(r["etapa"]), odkaz_sbirka(r["sbirka"]),
                    "<a href='%s'>%s</a>" % (e(odkaz("/material", id=r["pid"])), e(r["pid"]))
                    if r["pid"] else "", bunka_zmeny, poznamka(p), pridano(p)))

        if not materialy and not tisky:
            obsah = """<h1>⭐ Moje oblíbené</h1>
<div class="karta"><p>Zatím tu nic není. Hvězdičkou ☆ u materiálu (v seznamech, v detailu nebo
v podkladech PLPV) nebo u sněmovního tisku si návrh přidáte do oblíbených – a tady pak uvidíte,
kde se právě nachází: stav v ODOK, ve Sněmovně i v e-Legislativě, a jestli se od minula něco změnilo 🔔.</p></div>"""
            return self._stranka("Oblíbené", obsah, sekce="oblibene")

        cast_m = """<h2>Materiály ODOK (%d)</h2>
<div class="lista"><input type="text" data-filtr="obl-materialy" placeholder="Filtrovat…">
<span class="tlumene"><span id="obl-materialy-pocet">%d</span> materiálů</span></div>
<div class="posun ukotvit" data-sloupcu="2"><table class="data raditelna" id="obl-materialy"><thead><tr>
<th>⭐</th><th>Název</th><th>Předkl.</th><th>Typ</th><th>Stav ODOK</th><th>Tisk PS</th><th>Stav PS</th>
<th>e-Legislativa</th><th>Sbírka</th><th>Změna</th><th>Poznámka</th><th>Přidáno</th>
</tr></thead><tbody>%s</tbody></table></div>""" % (len(materialy), len(materialy), "".join(radky_m)) \
            if materialy else ""
        cast_t = """<h2>Sněmovní tisky (%d)</h2>
<div class="posun"><table class="data raditelna" id="obl-tisky"><thead><tr>
<th>⭐</th><th>Tisk</th><th>Název</th><th>Stav</th><th>Od</th><th>Probíhá</th><th>Sbírka</th><th>eKLEP</th>
<th>Změna</th><th>Poznámka</th><th>Přidáno</th></tr></thead><tbody>%s</tbody></table></div>""" % (
            len(tisky), "".join(radky_t)) if tisky else ""
        nacita = []
        if _psp is not None and not self._psp_hotovo.is_set():
            nacita.append("Sněmovny")
        if _esel is not None and not self._esel_hotovo.is_set():
            nacita.append("e-Legislativy")
        obsah = """<h1>⭐ Moje oblíbené</h1>
<p class="tlumene">Aktuální stav sledovaných návrhů napříč ODOK, Sněmovnou a e-Legislativou.
🔔 označuje změnu stavu od posledního potvrzení (po najetí myší je vidět, co se změnilo).
Oblíbené se ukládají do souboru <code>%(soubor)s</code>.</p>
%(nacita)s%(potvrdit)s%(m)s%(t)s""" % {
            "soubor": e(self.oblibene.soubor), "m": cast_m, "t": cast_t,
            "nacita": "<p class='nacitam'>Data %s se ještě načítají – některé sloupce se doplní po "
                      "obnovení stránky.</p>" % " a ".join(nacita) if nacita else "",
            "potvrdit": "<div class='lista'><button type='button' data-videno=''>✔ Vzít na vědomí "
                        "všechny změny (%d)</button></div>" % zmen if zmen else ""}
        return self._stranka("Oblíbené", obsah, sekce="oblibene")

    # ---------------- Podklady PLPV (plpv.py) ----------------

    def stranka_plpv(self, parametry):
        """Záložka s podklady pro excel PLPV – obsah se načítá dodatečně (fragment)."""
        if _plpv is None:
            raise OdokChyba("Podklady PLPV nejsou k dispozici – vedle odok_web.py chybí plpv.py.")
        resort, obdobi, rok, _od, _do, popis = self._plpv_parametry(parametry)
        vybrany = parametry.get("l") or "zakony"
        with self._zamek_dat:
            resorty = sorted({z for (z, _n) in self.odok.predkladatele() if z and z != "?"})
        volby_obdobi, volby_roku = self._plpv_volby(obdobi, rok)
        if resort not in resorty:
            resorty.append(resort)
        moznosti = "".join("<option%s>%s</option>" % (" selected" if z == resort else "", e(z))
                           for z in sorted(resorty))
        zalozky = "".join(
            "<a class='tlacitko%s' href='%s'>%s</a>" % (
                " aktivni" if klic == vybrany else "",
                e(odkaz("/plpv", r=resort, o=obdobi or "", rok=rok or "", l=klic)), e(nazev))
            for klic, nazev, _s, _d in _plpv.LISTY)
        legenda = " ".join("<span class='zdroj-%s'>%s</span>" % (k, e(v))
                           for k, v in _plpv.POPIS_ZDROJE.items())
        obsah = """<h1>Podklady PLPV <span class="tlumene">%(resort)s · %(popis)s</span></h1>
<form class="lista" action="/plpv">
<label>Resort <select name="r">%(moznosti)s</select></label>
%(volby_obdobi)s
<label>Rok <select name="rok">%(volby_roku)s</select></label>
<input type="hidden" name="l" value="%(l)s"><button>Zobrazit</button>
<a class="tlacitko" href="%(obnov)s" title="Znovu sestavit (ignoruje uložené podklady)">Přepočítat</a>
</form>
<div class="lista filtry">%(zalozky)s</div>
<p class="tlumene legenda">Zdroj sloupce: %(legenda)s</p>
<details open data-nacti="%(url)s"><summary>Tabulka</summary><div class="obsah">
<p class="nacitam">Připravuji podklady… První sestavení může trvat i minutu – stahují se
historie verzí materiálů z ODOK (pak se 2 hodiny berou z cache).</p></div></details>""" % {
            "resort": e(resort), "popis": e(popis), "moznosti": moznosti, "l": e(vybrany),
            "zalozky": zalozky, "legenda": legenda,
            "volby_obdobi": volby_obdobi, "volby_roku": volby_roku,
            "url": e(odkaz("/cast", typ="plpv", r=resort, o=obdobi or "", rok=rok or "", l=vybrany,
                           **({"obnovit": "1"} if parametry.get("obnovit") else {}))),
            "obnov": e(odkaz("/plpv", r=resort, o=obdobi or "", rok=rok or "", l=vybrany, obnovit="1"))}
        return self._stranka("Podklady PLPV", obsah, sekce="plpv")

    def _plpv_parametry(self, parametry):
        """(resort, období, rok, od, do, popis) z parametrů; období výchozí dle lišty."""
        resort = (parametry.get("r") or "MF").strip().upper()
        o = parametry.get("o", "")
        rok = parametry.get("rok", "")
        if "o" not in parametry and "rok" not in parametry and self._vybrany_rok():
            rok = str(self._vybrany_rok())        # výchozí rok podle výběru v liště
        rok = int(rok) if rok.isdigit() else None
        obdobi = None
        if _psp is not None:
            try:
                p = self._psp_pro(int(o)) if o.isdigit() else self._psp_pro()
                obdobi = p.obdobi if p.obdobi and p.obdobi.od else None
            except OdokChyba:
                obdobi = None
        if rok:
            od, do = _dt.date(rok, 1, 1), _dt.date(rok, 12, 31)
            popis = ("%s · rok %d" % (obdobi, rok)) if obdobi else "rok %d" % rok
        elif obdobi is not None:
            od, do = obdobi.od.date(), obdobi.do.date() if obdobi.do else None
            popis = str(obdobi)
        else:   # bez dat Sněmovny – letošní rok
            rok = _dt.date.today().year
            od, do, popis = _dt.date(rok, 1, 1), _dt.date(rok, 12, 31), "rok %d" % rok
        return resort, obdobi.cislo if obdobi else None, rok, od, do, popis

    def _plpv_volby(self, obdobi, rok):
        """<select> volebních období a roků v období pro formulář PLPV."""
        volby_obdobi = ""
        roky = [_dt.date.today().year]
        if _psp is not None and self.psp is not None and self._psp_hotovo.is_set():
            seznam = [o for o in self.psp.obdobi_seznam() if o.cislo and o.cislo >= 5 and o.od]
            volby_obdobi = "<label>Volební období <select name='o'>%s</select></label>" % "".join(
                "<option value='%s'%s>%s</option>" % (o.cislo, " selected" if o.cislo == obdobi else "",
                                                       e(str(o))) for o in seznam)
            vybrane = next((o for o in seznam if o.cislo == obdobi), None)
            if vybrane:
                roky = list(range(vybrane.od.year, (vybrane.do or _dt.datetime.now()).year + 1))
        volby_roku = "<option value=''>celé období</option>" + "".join(
            "<option%s>%d</option>" % (" selected" if r == rok else "", r) for r in roky)
        return volby_obdobi, volby_roku

    def _podklady(self, resort, obdobi, rok, od, do, obnovit=False):
        """Sestavené podklady (cache 10 minut v paměti; data ODOK mají vlastní 2h cache)."""
        klic = (resort, obdobi, rok)
        with self._zamek_plpv:
            zaznam = self._plpv_cache.get(klic)
            if zaznam and not obnovit and time.time() - zaznam[0] < 600:
                return zaznam[1]
            roky = tuple(range(od.year - 1, (do or _dt.date.today()).year + 1))
            odok_roky = self._plpv_odok.get(roky)
            if odok_roky is None or obnovit:
                odok_roky = self._plpv_odok[roky] = self._nove_odok(roky)
            snemovna = None
            if _psp is not None:
                try:
                    snemovna = self._psp_pro(obdobi) if obdobi else self._psp_pro()
                except OdokChyba as chyba:
                    sys.stderr.write("PLPV bez dat Sněmovny: %s\n" % chyba)
            legislativa = None
            if _esel is not None:
                try:
                    legislativa = self._legislativa()
                except OdokChyba as chyba:
                    sys.stderr.write("PLPV bez dat e-Legislativy: %s\n" % chyba)
            with self._zamek_psp, self._zamek_esel:
                podklady = _plpv.Podklady(resort=resort, od=od, do=do, odok=odok_roky, psp=snemovna,
                                          esel=legislativa)
                listy = podklady.listy()
            vysledek = (listy, podklady.chyby, snemovna is not None, legislativa is not None)
            self._plpv_cache[klic] = (time.time(), vysledek)
            return vysledek

    def cast_plpv(self, parametry):
        """HTML fragment: tabulka jednoho listu podkladů PLPV."""
        obnovit = bool(parametry.get("obnovit"))
        resort, obdobi, rok, od, do, _popis = self._plpv_parametry(parametry)
        klic_listu = parametry.get("l") or "zakony"
        listy, chyby, se_snemovnou, s_eselem = self._podklady(resort, obdobi, rok, od, do, obnovit=obnovit)
        lst = next((x for x in listy if x.klic == klic_listu), listy[0])
        pocty = " · ".join("%s: %d" % (x.nazev, len(x.radky)) for x in listy)

        def hlavicka(s, doplnek):
            pismeno = "<span class='pismeno'>%s</span>" % e(s[3]) if len(s) > 3 else ""
            return "<th class='zdroj-%s%s' title='%s'>%s%s</th>" % (
                s[2], " doplnek" if doplnek else "", e(_plpv.POPIS_ZDROJE[s[2]]),
                pismeno, e(s[0]).replace("\n", "<br>"))

        def bunka(r, s):
            klic, zdroj = s[1], s[2]
            hodnota = r.get(klic)
            text = e(_plpv.fmt(hodnota))
            if klic == "nazev" and r.get("pid"):
                text = self.hvezda("material", r["pid"], hodnota, klice) + \
                    "<a href='%s'>%s</a>" % (e(odkaz("/material", id=r["pid"])), text)
            elif klic == "tisk" and r.get("_tisk") is not None:
                text = "<a href='%s'>%s</a>" % (
                    e(odkaz("/tisk", id="id:%s" % r["_tisk"].id_tisk)), text)
            elif klic == "pid" and hodnota:
                text = "<a href='%s' target='_blank' rel='noopener'>%s ↗</a>" % (
                    e(_odok.URL_DETAIL_WEB.format(pid=hodnota)), text)
            elif klic == "sbirka" and hodnota:
                text = odkaz_sbirka(hodnota)
            elif klic == "esel" and hodnota and r.get("_esel") is not None:
                text = odkazy_esel(r["_esel"], _plpv.fmt(hodnota), kratce=True)
            elif hodnota == "probíhá":
                text = "<span class='stitek'>probíhá</span>"
            razeni = hodnota.isoformat() if hasattr(hodnota, "isoformat") else ""
            return "<td class='zdroj-%s'%s>%s</td>" % (
                zdroj, " data-razeni='%s'" % e(razeni) if razeni else "", text)

        sloupce = lst.sloupce + lst.doplnky
        klice = self.oblibene.klice()
        telo = "".join("<tr>%s</tr>" % "".join(bunka(r, s) for s in sloupce) for r in lst.radky)
        tabulka = """<div class="posun ukotvit" data-sloupcu="1"><table class="data raditelna plpv" id="plpv"><thead>
<tr><th colspan="%d" class="skupina">Sloupce excelu</th><th colspan="%d" class="skupina doplnek">Doplňkové údaje (pro kontrolu)</th></tr>
<tr>%s</tr></thead><tbody>%s</tbody></table></div>""" % (
            len(lst.sloupce), len(lst.doplnky),
            "".join(hlavicka(s, False) for s in lst.sloupce) + "".join(hlavicka(s, True) for s in lst.doplnky),
            telo) if lst.radky else "<p class='tlumene'>Žádné materiály pro tento list.</p>"

        upozorneni = []
        if not se_snemovnou:
            upozorneni.append("Data Sněmovny nejsou k dispozici – sloupce ze Sněmovny jsou prázdné.")
        if not s_eselem:
            upozorneni.append("Data e-Legislativy nejsou k dispozici – u vyhlášek a nařízení chybí "
                              "podpis, číslo ve Sbírce a účinnost.")
        if chyby:
            upozorneni.append("U %d materiálů se nepodařilo stáhnout historii verzí (sloupce MPŘ / "
                              "předložení / LRV mohou chybět): %s" % (len(chyby), "; ".join(chyby[:5])))
        return """<p class="tlumene">%(pocty)s</p>%(upoz)s
<div class="lista">
<button type="button" data-kopiruj="tsv-excel">Kopírovat řádky (sloupce %(pismena)s)</button>
<button type="button" data-kopiruj="tsv-vse">Kopírovat vč. záhlaví a doplňkových sloupců</button>
<span class="tlumene" data-kopiruj-stav></span></div>
<textarea id="tsv-excel" hidden>%(tsv_excel)s</textarea><textarea id="tsv-vse" hidden>%(tsv_vse)s</textarea>
%(tabulka)s
<p class="tlumene">Písmeno nad sloupcem je sloupec v excelu. Zobrazují se jen sloupce, pro které jsou
data (sloupce A, B a D–F – položka PLPV, sekce, implementace EU, plán a VPŘ – v datech nejsou).
Zkopírované řádky obsahují sloupce %(pismena)s v tomto pořadí, bez záhlaví; data jsou ve tvaru d.m.rrrr.
Přiřazení k řádkům excelu je potřeba zkontrolovat – názvy v ODOK se mohou mírně lišit.</p>""" % {
            "pocty": e(pocty),
            "upoz": "".join("<div class='chyba'>%s</div>" % e(u) for u in upozorneni),
            "tsv_excel": e(lst.jako_tsv(se_zahlavim=False)),
            "tsv_vse": e(lst.jako_tsv(s_doplnky=True)),
            "tabulka": tabulka, "pismena": ", ".join(s[3] for s in lst.sloupce)}

    # ---------------- HTTP ----------------

    def _presmeruj(self, kam):
        return ("presmeruj", kam)

    def _vytvor_handler(self):
        server = self
        cesty = {
            "/": server.stranka_uvod,
            "/ministerstvo": server.stranka_ministerstvo,
            "/hledej": server.stranka_hledej,
            "/material": server.stranka_material,
            "/cast": server.cast_detailu,
            "/obnov": server.stranka_obnov,
            "/tisky": server.stranka_tisky,
            "/tisk": server.stranka_tisk,
            "/schuze": server.stranka_schuze,
            "/plpv": server.stranka_plpv,
            "/nastav": server.stranka_nastav,
            "/oblibene": server.stranka_oblibene,
        }
        akce_post = {
            "/oblibene/prepni": server.akce_oblibene_prepni,
            "/oblibene/poznamka": server.akce_oblibene_poznamka,
            "/oblibene/videno": server.akce_oblibene_videno,
        }

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, format, *args):  # tichý provoz
                if server.odok.verbose:
                    sys.stderr.write("[web] " + (format % args) + "\n")

            def _posli(self, kod, telo, typ="text/html; charset=utf-8", hlavicky=None):
                data = telo.encode("utf-8")
                self.send_response(kod)
                self.send_header("Content-Type", typ)
                self.send_header("Content-Length", str(len(data)))
                self.send_header("Cache-Control", "no-store")
                for k, v in (hlavicky or {}).items():
                    self.send_header(k, v)
                self.end_headers()
                self.wfile.write(data)

            def do_GET(self):
                server._pocet_dotazu += 1
                server._posledni_dotaz = time.time()
                casti = urllib.parse.urlsplit(self.path)
                parametry = {k: v[0] for k, v in urllib.parse.parse_qs(casti.query).items()}
                kolacky = http.cookies.SimpleCookie()
                try:
                    kolacky.load(self.headers.get("Cookie") or "")
                except http.cookies.CookieError:
                    pass
                server._kontext.rok = kolacky["odok_rok"].value if "odok_rok" in kolacky else None
                server._kontext.obdobi = kolacky["ps_obdobi"].value if "ps_obdobi" in kolacky else None
                server._kontext.cesta = self.path if casti.path not in ("/nastav", "/cast") else "/"
                fce = cesty.get(casti.path.rstrip("/") or "/")
                if casti.path in ("/favicon.svg", "/favicon.ico") or casti.path.startswith("/static/"):
                    nazev = "favicon.svg" if casti.path.startswith("/favicon") else casti.path[8:]
                    pripona = os.path.splitext(nazev)[1].lower()
                    if "/" in nazev or "\\" in nazev or nazev.startswith(".") or pripona not in STATICKE_TYPY:
                        self._posli(404, "nenalezeno", "text/plain; charset=utf-8")
                        return
                    try:
                        with open(os.path.join(WEB_DIR, nazev), "rb") as f:
                            data = f.read()
                    except OSError:
                        self._posli(404, "nenalezeno", "text/plain; charset=utf-8")
                        return
                    self.send_response(200)
                    self.send_header("Content-Type", STATICKE_TYPY[pripona])
                    self.send_header("Content-Length", str(len(data)))
                    self.send_header("Cache-Control", "max-age=86400")   # verze je v adrese (?v=…)
                    self.end_headers()
                    self.wfile.write(data)
                    return
                if fce is None:
                    self._posli(404, server._stranka("Nenalezeno", "<h1>Stránka nenalezena</h1>"))
                    return
                try:
                    vysledek = fce(parametry)
                except OdokChyba as chyba:
                    self._posli(200 if casti.path == "/cast" else 404,
                                "<div class='chyba'>%s</div>" % e(chyba) if casti.path == "/cast"
                                else server._stranka("Chyba", "<h1>Chyba</h1><div class='chyba'>%s</div>"
                                                     % e(chyba)))
                    return
                except Exception:  # noqa: BLE001 – ukázat chybu v prohlížeči
                    podrobnosti = traceback.format_exc()
                    sys.stderr.write(podrobnosti)
                    self._posli(500, server._stranka(
                        "Chyba", "<h1>Chyba serveru</h1><div class='chyba'>%s</div>" % e(podrobnosti)))
                    return
                if isinstance(vysledek, tuple) and vysledek[0] == "presmeruj":
                    self.send_response(303)
                    self.send_header("Location", vysledek[1])
                    for kolacek in (vysledek[2] if len(vysledek) > 2 else []):
                        self.send_header("Set-Cookie", kolacek)
                    self.end_headers()
                    return
                self._posli(200, vysledek)

            def do_POST(self):
                """Změny oblíbených (volá JavaScript stránky; hlavička X-Radar brání
                odeslání z cizích webů)."""
                casti = urllib.parse.urlsplit(self.path)
                fce = akce_post.get(casti.path.rstrip("/"))
                if fce is None or self.headers.get("X-Radar") != "1":
                    self._posli(403 if fce else 404, '{"chyba": "nepovoleno"}', "application/json")
                    return
                try:
                    delka = min(int(self.headers.get("Content-Length") or 0), 100000)
                    telo = self.rfile.read(delka).decode("utf-8")
                    parametry = {k: v[0] for k, v in urllib.parse.parse_qs(
                        telo, keep_blank_values=True).items()}
                    kolacky = http.cookies.SimpleCookie()
                    try:
                        kolacky.load(self.headers.get("Cookie") or "")
                    except http.cookies.CookieError:
                        pass
                    server._kontext.rok = kolacky["odok_rok"].value if "odok_rok" in kolacky else None
                    vysledek = fce(parametry)
                except (ValueError, KeyError) as chyba:
                    self._posli(400, json.dumps({"chyba": str(chyba)}, ensure_ascii=False),
                                "application/json")
                    return
                except Exception as chyba:  # noqa: BLE001
                    sys.stderr.write(traceback.format_exc())
                    self._posli(500, json.dumps({"chyba": str(chyba)}, ensure_ascii=False),
                                "application/json")
                    return
                if isinstance(vysledek, tuple) and vysledek[0] == "presmeruj":
                    self.send_response(303)
                    self.send_header("Location", vysledek[1])
                    self.end_headers()
                    return
                self._posli(200, json.dumps(vysledek, ensure_ascii=False), "application/json")

        return Handler


# --------------------------------------------------------------------------
# Spuštění z příkazové řádky
# --------------------------------------------------------------------------

def main():
    import argparse
    ap = argparse.ArgumentParser(description="%s – lokální přehled legislativního procesu "
                                             "(ODOK, e-Legislativa, Sněmovna)" % NAZEV_APLIKACE)
    if sys.stdout is None or sys.stderr is None:   # pythonw.exe – bez konzole
        try:
            from odok_okno import _presmeruj_vystupy
            _presmeruj_vystupy()
        except ImportError:
            pass
    ap.add_argument("--port", type=int, default=8765)
    ap.add_argument("--host", default="127.0.0.1",
                    help="0.0.0.0 = dostupné i z jiných počítačů v síti")
    ap.add_argument("--rok", type=int, action="append", help="rok datasetu (lze opakovat)")
    ap.add_argument("--sit", help="způsob stahování: windows, powershell, python")
    ap.add_argument("--obdobi", help="volební období PS: číslo (10), rok (2025); výchozí aktuální")
    ap.add_argument("--bez-okna", action="store_true",
                    help="bez okna aplikace – server běží v konzoli (ukončení Ctrl+C)")
    ap.add_argument("--bez-prohlizece", action="store_true",
                    help="v režimu bez okna neotevírat prohlížeč")
    ap.add_argument("--pravidla-relevance", help="seznam výrazů pro analýzu relevance (.xlsx); "
                    "výchozí relevance_pravidla.xlsx ve složce s programem")
    ap.add_argument("-v", "--verbose", action="store_true")
    a = ap.parse_args()

    sit = a.sit.split(",") if a.sit else "auto"
    snemovna = None
    if _psp is not None:
        snemovna = _psp.Psp(obdobi=int(a.obdobi) if a.obdobi and a.obdobi.isdigit() else a.obdobi,
                            sit=sit, verbose=a.verbose)
    server = OdokServer(host=a.host, port=a.port, roky=a.rok, psp=snemovna,
                        relevance=a.pravidla_relevance, sit=sit, verbose=a.verbose)
    if not a.bez_okna:
        try:
            from odok_okno import spust_okno
        except ImportError as chyba:   # chybí tkinter nebo odok_okno.py
            print("Okno aplikace nelze zobrazit (%s), běžím v konzoli." % chyba)
        else:
            spust_okno(server)
            return
    server.spust(otevrit_prohlizec=not a.bez_prohlizece)


if __name__ == "__main__":
    main()
