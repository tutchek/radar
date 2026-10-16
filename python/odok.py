# -*- coding: utf-8 -*-
"""
odok.py – práce s otevřenými daty ODOK / VeKLEP (eKLEP) bez externích závislostí.

Používá jen standardní knihovnu Pythonu (urllib, json, zipfile, tempfile ...).

Rychlý start:

    from odok import Odok

    o = Odok()                                   # aktuální rok, cache 2 h v %TEMP%
    m = o.material("ALBSDW3HDEWG")               # podle PID
    m = o.material("MV-12345-6/LG-2026")         # nebo podle našeho čísla jednacího
    print(m)                                     # základní info
    m.pripominky()                               # JSON s připomínkami
    m.verze()                                    # JSON s historickými verzemi
    for s in m.soubory():                        # seznam souborů (název, url)
        print(s["nazev"], s["url"])
    m.stahni_soubory("C:/tmp/material")          # stažení všech souborů

    for m in o.materialy_ministerstva("MZD"):    # podle zkratky v eKLEPu
        print(m.pid, m.cislo_jednaci, m.nazev)

    o.predkladatele()                            # přehled předkladatelů + počty
    o.ukaz_strukturu()                           # vypíše strom klíčů v datech

Protože se názvy klíčů v JSONu mohou lišit (schéma material_schema_v2),
hledá třída hodnoty tolerantně podle více kandidátních názvů (bez ohledu na
velikost písmen, diakritiku, podtržítka). Kandidáty lze upravit v konstantách
KLICE_* níže.
"""

import json
import os
import re
import sys
import tempfile
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from collections import Counter

__version__ = "1.5"  # 1.5: předkladatelé sloučení podle zkratky; 1.4: víc ročníků – duplicity, nedostupný ročník; 1.3: skutečná struktura ODOK (verze, typy, psp); 1.2: společná třída Stahovani; 1.1: Material.dokumenty()

__all__ = ["Odok", "Material", "OdokChyba", "Stahovani", "SitovaChyba", "bez_diakritiky"]

# --------------------------------------------------------------------------
# Konfigurace
# --------------------------------------------------------------------------

# Dataset se zkouší postupně ze všech uvedených domén (první funkční vyhrává).
DOMENY_DATASETU = ["https://www.odok.cz", "https://www.odok.gov.cz"]
URL_DATASET_JSON = "{domena}/opendata/dataset/odok/requests_{rok}.json"
URL_DATASET_ZIP = "{domena}/opendata/dataset/odok/requests_zip_{rok}.zip"
URL_VERZE = "https://www.odok.gov.cz/portal/veklep/material/verze/{pid}/json"
URL_PRIPOMINKY = "https://www.odok.gov.cz/portal/veklep/material/pripominky/{pid}/json"
URL_DETAIL_WEB = "https://www.odok.gov.cz/portal/veklep/material/{pid}/"
# Vzor URL pro stažení dokumentu. Dokumenty v datasetu přímou URL nemají
# (mají pid, name, old_name, filenet_id …). Až bude vzor známý, doplňte ho,
# např. "https://www.odok.gov.cz/.../dokument/{pid}" – k dispozici jsou všechny
# textové klíče dokumentu a navíc {material_pid}.
URL_DOKUMENT = "https://www.odok.cz/portal/services/download/attachment/{pid}/"
ZAKLADNI_URL = "https://www.odok.gov.cz"  # pro relativní odkazy na soubory

USER_AGENT = "odok.py (python-urllib)"

# Skutečná struktura dat ODOK (material_schema_v2), ověřeno na datech 2025–2026:
#   title, pid, identifier.{id_no = čj ÚV (OVA), id_no_ext = čj předkladatele},
#   type.{id,name}, status.{id,name}, author.organization.{id,name},
#   metadata.date_created, date_authorized, review_process.date_finish (konec MPŘ),
#   government_program.date (jednání vlády), psp.{id = číslo tisku, period},
#   attachments[] (přílohy), verze: versions_list[].version_type.id
TYPY_MATERIALU = {"NZ": "Návrh zákona", "NV": "Návrh vyhlášky", "NNV": "Návrh nařízení vlády",
                  "PNZ": "Návrh zákona předložený PS", "SNZ": "Senátní návrh zákona",
                  "NKZ": "Návrh krajského zastupitelstva"}
TYPY_VERZI = {"MPR": "Verze do připomínkového řízení", "PK": "Verze pro jednání pracovních komisí",
              "JV": "Verze pro jednání vlády", "PV": "Projednaná verze"}
STAV_PUBLIKOVANO = "K"               # K - publikováno ve Sbírce zákonů

# Kandidátní názvy klíčů (porovnává se po normalizaci: malá písmena, bez
# diakritiky, bez '_', '-', mezer). První nalezený vyhrává.
KLICE_PID = ["pid", "materialPid", "idMaterialu", "materialId", "id"]
KLICE_CJ = ["id_no_ext", "cisloJednaci", "cj", "cjPredkladatele", "cisloJednaciPredkladatele",
            "spisovaZnacka", "evidencniCislo", "cisloJednaciMaterialu",
            "reference_number", "ref_number", "file_number", "number_of_reference"]
KLICE_NAZEV = ["title", "nazev", "nazevMaterialu", "name", "predmet", "subject"]
# Předkladatel v datech eKLEPu: "author": {"organization": {"id": "MZD", "name": "..."}}
CESTA_ORGANIZACE_ID = "author.organization.id"
CESTA_ORGANIZACE_NAZEV = "author.organization.name"

KLICE_PREDKLADATEL = ["predkladatel", "predkladatelNazev", "gestor", "resort",
                      "ustredniOrgan", "zadavatel", "autor", "organizace"]
KLICE_STAV = ["stav", "stavMaterialu", "status", "faze", "state", "phase"]
KLICE_TYP = ["typ", "typMaterialu", "druh", "druhMaterialu", "type", "kind"]
KLICE_DATUM = ["datumVlozeni", "datumZalozeni", "datum", "vlozeno", "zalozeno",
               "datumZahajeni", "date_created", "created", "created_at", "date",
               "date_published", "date_modified"]

# Klíče, které obvykle obsahují odkaz na soubor / název souboru
KLICE_URL = ["uri", "url", "odkaz", "href", "link", "downloadUrl", "urlSouboru",
             "odkazKeStazeni", "cesta"]
KLICE_NAZEV_SOUBORU = ["nazevSouboru", "jmenoSouboru", "fileName", "filename",
                       "nazev", "name", "popis", "titulek"]


class OdokChyba(Exception):
    """Chyba při práci s daty ODOK."""


# --------------------------------------------------------------------------
# Pomocné funkce
# --------------------------------------------------------------------------

def bez_diakritiky(text):
    """'Ministerstvo vnitra ČR' -> 'ministerstvo vnitra cr'"""
    if text is None:
        return ""
    text = unicodedata.normalize("NFKD", str(text))
    text = "".join(c for c in text if not unicodedata.combining(c))
    return text.lower()


def _norm_klic(k):
    return re.sub(r"[^a-z0-9]", "", bez_diakritiky(k))


def _norm_id(hodnota):
    """Normalizace identifikátoru (PID, čj) pro porovnání: bez mezer, velká písmena."""
    return re.sub(r"\s+", "", str(hodnota or "")).upper()


def _najdi_klic(d, kandidati):
    """Vrátí hodnotu prvního klíče z `kandidati`, který je v dict `d` (tolerantně)."""
    if not isinstance(d, dict):
        return None
    mapa = {_norm_klic(k): k for k in d}
    for kand in kandidati:
        k = mapa.get(_norm_klic(kand))
        if k is not None and d[k] not in (None, "", [], {}):
            return d[k]
    return None


def _najdi_hluboko(d, kandidati, hloubka=2):
    """Jako _najdi_klic, ale když není na nejvyšší úrovni, hledá i ve vnořených dict."""
    v = _najdi_klic(d, kandidati)
    if v is not None or hloubka <= 0 or not isinstance(d, dict):
        return v
    for hodnota in d.values():
        if isinstance(hodnota, dict):
            v = _najdi_hluboko(hodnota, kandidati, hloubka - 1)
            if v is not None:
                return v
    return None


def _hodnota_cesty(d, cesta, i_vnorene=True):
    """Hodnota podle cesty oddělené tečkami, např. 'author.organization.id'.
    Když na nejvyšší úrovni není, zkusí ji i o úroveň níž (obalený materiál)."""
    puvodni = d
    for cast in cesta.split("."):
        if not isinstance(d, dict):
            d = None
            break
        d = d.get(cast)
    if isinstance(d, (str, int, float)):
        return d
    if i_vnorene and isinstance(puvodni, dict):
        for v in puvodni.values():
            if isinstance(v, dict):
                h = _hodnota_cesty(v, cesta, False)
                if h is not None:
                    return h
    return None


def _prvni_cesta(d, *cesty):
    """První neprázdná hodnota z uvedených cest (bez hledání do hloubky)."""
    for c in cesty:
        v = _hodnota_cesty(d, c, False)
        if v not in (None, ""):
            return v
    return None


def parsuj_cas_odok(hodnota):
    """'2026-07-22T13:58:03Z' → datetime v místním čase (bez časové zóny), jinak None."""
    if not hodnota or not isinstance(hodnota, str):
        return None
    try:
        from datetime import datetime, timezone
        t = datetime.fromisoformat(hodnota.strip().replace("Z", "+00:00"))
        if t.tzinfo is None:
            t = t.replace(tzinfo=timezone.utc)
        return t.astimezone().replace(tzinfo=None)
    except ValueError:
        return None


def _jako_text(hodnota):
    """Převede hodnotu (str / dict / list) na čitelný text."""
    if hodnota is None:
        return ""
    if isinstance(hodnota, dict):
        # typicky {"nazev": "...", "zkratka": "..."} apod.
        v = _najdi_klic(hodnota, ["nazev", "name", "zkratka", "text", "hodnota", "value"])
        if v is not None and not isinstance(v, (dict, list)):
            return str(v)
        return ", ".join(_jako_text(x) for x in hodnota.values()
                         if isinstance(x, (str, int, float)))
    if isinstance(hodnota, list):
        return ", ".join(_jako_text(x) for x in hodnota)
    return str(hodnota)


def _vsechny_texty(hodnota):
    """Všechny řetězcové hodnoty v libovolně vnořené struktuře (pro fulltext)."""
    if isinstance(hodnota, dict):
        for v in hodnota.values():
            yield from _vsechny_texty(v)
    elif isinstance(hodnota, list):
        for v in hodnota:
            yield from _vsechny_texty(v)
    elif isinstance(hodnota, (str, int)):
        yield str(hodnota)


def _vypada_jako_material(d):
    """Materiál (na rozdíl od dokumentu v něm) má předkladatele: author.organization.
    Připouští se i o úroveň vnořená podoba, např. {"material": {"author": ...}}."""
    if not isinstance(d, dict):
        return False
    if "author" in d or _najdi_klic(d, KLICE_PREDKLADATEL) is not None:
        return True
    # obal s právě jedním vnořeným materiálem (slovník {PID: materiál} obalem není)
    return sum(1 for v in d.values() if isinstance(v, dict) and "author" in v) == 1


def _najdi_materialy(data, hloubka=4):
    """
    Z JSONu datasetu vytáhne seznam materiálů. Hledá co nejmělčeji
    a do nalezeného materiálu už nezanořuje (aby nevzal jeho dokumenty).
    Zvládne: seznam materiálů, {"neco": [materiály]}, {PID: materiál},
    i jeden materiál na soubor (ZIP s více JSONy).
    """
    if hloubka < 0:
        return []
    if isinstance(data, list):
        materialy = [x for x in data if _vypada_jako_material(x)]
        if materialy:
            return materialy
        vysledek = []
        for x in data:
            vysledek.extend(_najdi_materialy(x, hloubka - 1))
        return vysledek
    if isinstance(data, dict):
        if _vypada_jako_material(data):
            return [data]
        # {PID: materiál, ...}
        materialy = [v for v in data.values() if _vypada_jako_material(v)]
        if materialy:
            return materialy
        # {"requests": [materiály], ...}
        for v in data.values():
            if isinstance(v, list):
                materialy = [x for x in v if _vypada_jako_material(x)]
                if materialy:
                    return materialy
        vysledek = []
        for v in data.values():
            if isinstance(v, (dict, list)):
                vysledek.extend(_najdi_materialy(v, hloubka - 1))
        return vysledek
    return []


def _popis_json(data, hloubka=2, odsazeni="  "):
    """Krátký popis horní úrovně JSONu (typy, klíče, délky seznamů)."""
    radky = []

    def rek(v, uroven, prefix):
        if isinstance(v, list):
            radky.append("%s[seznam, %d položek]" % (prefix, len(v)))
            if v and uroven < hloubka:
                rek(v[0], uroven + 1, prefix + odsazeni)
        elif isinstance(v, dict):
            klice = list(v)
            radky.append("%s{slovník, %d klíčů: %s%s}" % (
                prefix, len(klice), ", ".join(map(str, klice[:12])),
                ", …" if len(klice) > 12 else ""))
            if uroven < hloubka:
                for k in klice[:12]:
                    if isinstance(v[k], (dict, list)):
                        radky.append("%s%s:" % (prefix + odsazeni, k))
                        rek(v[k], uroven + 1, prefix + odsazeni * 2)
        else:
            radky.append("%s%r" % (prefix, v)[:100])

    rek(data, 0, odsazeni)
    return "\n".join(radky)


def _najdi_seznam_zaznamu(data):
    """Obecně: z JSONu vytáhne hlavní seznam záznamů (např. připomínek)."""
    if isinstance(data, list):
        return data
    if isinstance(data, dict):
        # preferované klíče
        v = _najdi_klic(data, ["materialy", "material", "requests", "data", "items",
                               "zaznamy", "polozky", "results"])
        if isinstance(v, list):
            return v
        # jinak nejdelší seznam dict
        seznamy = [v for v in data.values()
                   if isinstance(v, list) and v and isinstance(v[0], dict)]
        if seznamy:
            return max(seznamy, key=len)
        for v in data.values():
            if isinstance(v, dict):
                s = _najdi_seznam_zaznamu(v)
                if s:
                    return s
    return []


_JE_WINDOWS = sys.platform.startswith("win")
_ZPUSOBY_STAHOVANI = ("windows", "powershell", "python")
_NEZJISTENO = object()

# Nejčastější chybové kódy urlmon/WinINet
_POPIS_HRESULT = {
    0x800C0005: " (zdroj nenalezen / server nedostupný)",
    0x800C0007: " (data nejsou k dispozici)",
    0x800C0008: " (stahování selhalo)",
    0x800C000E: " (chyba zabezpečení)",
    0x800C0019: " (neplatný certifikát)",
    0x800C002F: " (chyba zabezpečeného spojení)",
    0x80070005: " (přístup odepřen)",
    0x800C0002: " (neplatná URL)",
}


class SitovaChyba(OSError):
    """Stažení se nepodařilo žádným způsobem (síť, proxy, server)."""


def _je_chyba_spojeni(chyba):
    """True, pokud se nepodařilo vůbec spojit přes urllib (ne HTTP chyba typu 404).
    U způsobů 'windows'/'powershell' to nejde rozlišit, proto False."""
    pricina = chyba.__cause__
    return isinstance(pricina, (urllib.error.URLError, OSError)) and \
        not isinstance(pricina, (urllib.error.HTTPError, SitovaChyba))


def zjisti_proxy(verbose=False):
    """
    Zjistí proxy pro HTTPS. Pořadí:
      1. proměnná prostředí ODOK_PROXY
      2. HTTPS_PROXY / HTTP_PROXY a ruční nastavení proxy ve Windows (registry)
      3. automatický konfigurační skript (PAC, AutoConfigURL ve Windows) –
         z něj se vezme první uvedená proxy (zjednodušení, obvykle stačí)
    Vrací URL proxy (např. 'http://proxy.mfcr.cz:8080'), nebo None (přímé spojení).
    """
    def log(*a):
        if verbose:
            print("[odok]", *a, file=sys.stderr)

    p = os.environ.get("ODOK_PROXY")
    if p is not None:
        log("proxy z ODOK_PROXY:", p or "(přímé spojení)")
        return p or None

    systemove = urllib.request.getproxies()
    p = systemove.get("https") or systemove.get("http")
    if p:
        log("proxy ze systému:", p)
        return p

    pac_url = None
    if sys.platform.startswith("win"):
        try:
            import winreg
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                                r"Software\Microsoft\Windows\CurrentVersion\Internet Settings") as k:
                pac_url = winreg.QueryValueEx(k, "AutoConfigURL")[0]
        except OSError:
            pac_url = None
    if pac_url:
        log("PAC skript:", pac_url)
        try:
            # PAC se stahuje přímo (bez proxy), bývá na vnitřní síti
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            with opener.open(pac_url, timeout=10) as r:
                pac = r.read().decode("utf-8", "replace")
            m = re.search(r"PROXY\s+([A-Za-z0-9.\-]+:\d+)", pac)
            if m:
                p = "http://" + m.group(1)
                log("proxy z PAC:", p)
                return p
        except (urllib.error.URLError, OSError) as e:
            log("PAC nelze načíst:", e)
    log("proxy nenalezena, přímé spojení")
    return None


def _bezpecny_nazev(nazev):
    nazev = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", str(nazev)).strip(" .")
    return nazev[:180] or "soubor"


# --------------------------------------------------------------------------
# Hlavní třída
# --------------------------------------------------------------------------

class Stahovani:
    """
    Společný základ pro třídy, které stahují data z webu: způsoby stahování
    (Windows / PowerShell / Python), proxy a souborová cache s dobou platnosti.
    Používá ji Odok i Psp (psp.py).

    Parametry:
        cache_dir   – adresář cache
        max_stari_h – jak dlouho (hodiny) se cache považuje za čerstvou
        timeout     – timeout HTTP požadavků v sekundách
        proxy       – None = zjistit automaticky (viz zjisti_proxy),
                      "" = přímé spojení bez proxy,
                      "http://server:port" = konkrétní proxy
                      (s přihlášením: "http://uzivatel:heslo@server:port")
                      Platí jen pro způsob stahování "python".
        sit         – způsob stahování, řetězec nebo seznam (zkouší se popořadě):
                      "windows"    – systémová knihovna Windows (urlmon) přes ctypes;
                                     proxy i přihlášení účtem Windows řeší systém
                      "powershell" – PowerShell / .NET, také s přihlášením účtem Windows
                      "python"     – čistý urllib (proxy bez přihlášení nebo jménem/heslem)
                      "auto"       – ve Windows ["windows", "powershell", "python"],
                                     jinde ["python"]
        verbose     – vypisovat informace o stahování
    """

    CHYBA = OdokChyba          # třída výjimky při neúspěšném stažení
    DIAGNOSTICKE_URL = []      # adresy, které testuje diagnostika()

    def __init__(self, cache_dir, max_stari_h=2.0, timeout=30, proxy=None,
                 sit="auto", verbose=False):
        self.cache_dir = cache_dir
        self.max_stari_s = float(max_stari_h) * 3600
        self.timeout = timeout
        self.verbose = verbose
        self._proxy_param = proxy
        self._proxy = _NEZJISTENO
        self._opener = None
        if sit == "auto":
            sit = ["windows", "powershell", "python"] if _JE_WINDOWS else ["python"]
        elif isinstance(sit, str):
            sit = [sit]
        nezname = [x for x in sit if x not in _ZPUSOBY_STAHOVANI]
        if nezname:
            raise ValueError("Neznámý způsob stahování: %s (povolené: %s)"
                             % (nezname, ", ".join(_ZPUSOBY_STAHOVANI)))
        self.sit = list(sit)
        os.makedirs(self.cache_dir, exist_ok=True)

    # ---------------- HTTP + cache ----------------

    def _log(self, *a):
        if self.verbose:
            print("[odok]", *a, file=sys.stderr)

    @property
    def proxy(self):
        """Proxy pro způsob stahování 'python' (zjišťuje se až při potřebě)."""
        if self._proxy is _NEZJISTENO:
            p = self._proxy_param
            self._proxy = zjisti_proxy(self.verbose) if p is None else (p or None)
        return self._proxy

    def _stahni_python(self, url):
        """Stažení přes urllib (neumí přihlášení k proxy účtem Windows)."""
        if self._opener is None:
            handler = urllib.request.ProxyHandler(
                {"http": self.proxy, "https": self.proxy} if self.proxy else {})
            self._opener = urllib.request.build_opener(handler)
        req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT,
                                                   "Accept": "*/*"})
        with self._opener.open(req, timeout=self.timeout) as r:
            return r.read(), r.headers

    def _stahni_windows(self, url):
        """
        Stažení přes systémovou knihovnu Windows (urlmon/WinINet) pomocí ctypes.
        Použije nastavení proxy z Windows včetně konfiguračního skriptu (PAC)
        a k proxy se přihlásí automaticky účtem přihlášeného uživatele.
        """
        if not _JE_WINDOWS:
            raise SitovaChyba("způsob 'windows' funguje jen ve Windows")
        import ctypes
        import threading
        from ctypes import wintypes

        fd, cesta = tempfile.mkstemp(prefix="odok_", dir=self.cache_dir)
        os.close(fd)
        vysledek = {}

        def prace():
            try:
                # smazat případnou kopii v cache Windows, ať dostaneme čerstvá data
                try:
                    ctypes.windll.wininet.DeleteUrlCacheEntryW(wintypes.LPCWSTR(url))
                except (OSError, AttributeError):
                    pass
                fce = ctypes.windll.urlmon.URLDownloadToFileW
                fce.argtypes = [ctypes.c_void_p, wintypes.LPCWSTR, wintypes.LPCWSTR,
                                wintypes.DWORD, ctypes.c_void_p]
                fce.restype = ctypes.c_long
                vysledek["hr"] = fce(None, url, cesta, 0, None)
            except Exception as e:  # noqa: BLE001 - předáme do hlavního vlákna
                vysledek["chyba"] = e

        vlakno = threading.Thread(target=prace, daemon=True)
        vlakno.start()
        vlakno.join(self.timeout * 2)
        try:
            if vlakno.is_alive():
                raise SitovaChyba("vypršel čas (%s s)" % (self.timeout * 2))
            if "chyba" in vysledek:
                raise SitovaChyba(str(vysledek["chyba"]))
            hr = vysledek.get("hr", -1) & 0xFFFFFFFF
            if hr != 0:
                raise SitovaChyba("Windows vrátil chybu 0x%08X%s"
                                  % (hr, _POPIS_HRESULT.get(hr, "")))
            with open(cesta, "rb") as f:
                return f.read(), None
        finally:
            if not vlakno.is_alive():
                try:
                    os.remove(cesta)
                except OSError:
                    pass

    def _stahni_powershell(self, url):
        """
        Stažení přes PowerShell (.NET WebClient) se systémovou proxy
        a přihlášením účtem Windows. Pomalejší start (~1 s), ale velmi spolehlivé.
        """
        import subprocess
        fd, cesta = tempfile.mkstemp(prefix="odok_", dir=self.cache_dir)
        os.close(fd)
        skript = (
            "$ErrorActionPreference='Stop';"
            "[Net.ServicePointManager]::SecurityProtocol="
            "[Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11;"
            "$wc=New-Object Net.WebClient;"
            "$wc.Headers.Add('User-Agent',$env:ODOK_UA);"
            "$wc.Proxy=[Net.WebRequest]::GetSystemWebProxy();"
            "$wc.Proxy.Credentials=[Net.CredentialCache]::DefaultNetworkCredentials;"
            "$wc.UseDefaultCredentials=$true;"
            "$wc.DownloadFile($env:ODOK_URL,$env:ODOK_CIL)"
        )
        prostredi = dict(os.environ, ODOK_URL=url, ODOK_CIL=cesta, ODOK_UA=USER_AGENT)
        try:
            try:
                p = subprocess.run(
                    ["powershell", "-NoProfile", "-NonInteractive",
                     "-ExecutionPolicy", "Bypass", "-Command", skript],
                    env=prostredi, capture_output=True, timeout=self.timeout * 2 + 15,
                    creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
            except FileNotFoundError:
                raise SitovaChyba("PowerShell nenalezen")
            except subprocess.TimeoutExpired:
                raise SitovaChyba("vypršel čas")
            if p.returncode != 0:
                kodovani = "cp852" if _JE_WINDOWS else "utf-8"  # konzole ve Windows (CZ)
                zprava = (p.stderr or p.stdout).decode(kodovani, "replace")
                zprava = " ".join(zprava.split())[:300]
                raise SitovaChyba("PowerShell: %s" % zprava)
            with open(cesta, "rb") as f:
                return f.read(), None
        finally:
            try:
                os.remove(cesta)
            except OSError:
                pass

    def _stahni_bytes(self, url):
        """Stáhne URL. Zkouší způsoby ze self.sit; ten, který zafunguje,
        se pak zkouší jako první."""
        chyby = []
        for zpusob in list(self.sit):
            self._log("stahuji (%s)" % zpusob, url)
            try:
                vysledek = getattr(self, "_stahni_" + zpusob)(url)
            except urllib.error.HTTPError as e:
                if e.code != 407:  # 407 = proxy chce přihlásit, zkusíme jiný způsob
                    raise  # server odpověděl (např. 404) – jiný způsob nepomůže
                self._log("  %s: proxy vyžaduje přihlášení (HTTP 407)" % zpusob)
                chyby.append("%s: proxy vyžaduje přihlášení (HTTP 407)" % zpusob)
                continue
            except (urllib.error.URLError, OSError) as e:
                self._log("  %s selhal: %s" % (zpusob, e))
                chyby.append("%s: %s" % (zpusob, getattr(e, "reason", e)))
                continue
            if self.sit[0] != zpusob:
                self.sit.remove(zpusob)
                self.sit.insert(0, zpusob)
            return vysledek
        raise SitovaChyba("; ".join(chyby))

    def diagnostika(self):
        """Otestuje všechny způsoby stahování na adresách DIAGNOSTICKE_URL."""
        print("Cache:", self.cache_dir)
        print("Pořadí způsobů stahování:", ", ".join(self.sit))
        adresy = list(dict.fromkeys(self.DIAGNOSTICKE_URL))
        for zpusob in self.sit:
            if zpusob == "python":
                print("\n[python]  proxy:", self.proxy or "(žádná, přímé spojení)")
            else:
                print("\n[%s]  proxy a přihlášení podle Windows" % zpusob)
            for url in adresy:
                zacatek = time.time()
                try:
                    getattr(self, "_stahni_" + zpusob)(url)
                    stav = "OK"
                except urllib.error.HTTPError as e:
                    stav = "server odpověděl HTTP %s (spojení funguje)" % e.code
                except (urllib.error.URLError, OSError) as e:
                    stav = "CHYBA: %s" % getattr(e, "reason", e)
                print("  %-32s %s  [%.1f s]" % (url, stav, time.time() - zacatek))

    def _cache_cesta(self, nazev):
        return os.path.join(self.cache_dir, _bezpecny_nazev(nazev))

    def _je_cerstve(self, cesta):
        return os.path.exists(cesta) and (time.time() - os.path.getmtime(cesta)) < self.max_stari_s

    def _ziskej_s_cache(self, url, nazev_cache, vynutit=False):
        """Vrátí bytes z cache (je-li čerstvá), nebo stáhne. Při chybě sítě
        použije i starou cache, pokud existuje."""
        cesta = self._cache_cesta(nazev_cache)
        if not vynutit and self._je_cerstve(cesta):
            self._log("cache", cesta)
            with open(cesta, "rb") as f:
                return f.read()
        try:
            data, _ = self._stahni_bytes(url)
        except (urllib.error.URLError, OSError) as e:
            if os.path.exists(cesta):
                self._log("stažení selhalo (%s), používám starou cache" % e)
                with open(cesta, "rb") as f:
                    return f.read()
            raise self.CHYBA("Nelze stáhnout %s: %s" % (url, e)) from e
        tmp = cesta + ".part"
        with open(tmp, "wb") as f:
            f.write(data)
        os.replace(tmp, cesta)
        return data


class Odok(Stahovani):
    """
    Přístup k otevřeným datům ODOK.

    Parametry:
        roky        – rok nebo seznam roků datasetu (výchozí: aktuální rok)
        cache_dir   – adresář cache (výchozí: %TEMP%/odok_cache)
        max_stari_h – jak dlouho (hodiny) se cache považuje za čerstvou (výchozí 2)
        pouzit_zip  – stahovat ZIP variantu datasetu (menší přenos), jinak JSON
        timeout, proxy, sit, verbose – viz třída Stahovani
    """

    DIAGNOSTICKE_URL = [d + "/" for d in DOMENY_DATASETU] + [ZAKLADNI_URL + "/"]

    def __init__(self, roky=None, cache_dir=None, max_stari_h=2.0,
                 pouzit_zip=True, timeout=30, proxy=None, sit="auto", verbose=False):
        if roky is None:
            roky = [time.localtime().tm_year]
        elif isinstance(roky, int):
            roky = [roky]
        self.roky = list(roky)
        self.pouzit_zip = pouzit_zip
        super().__init__(cache_dir or os.path.join(tempfile.gettempdir(), "odok_cache"),
                         max_stari_h=max_stari_h, timeout=timeout, proxy=proxy,
                         sit=sit, verbose=verbose)
        self._zaznamy = None
        self._index_pid = None
        self._index_cj = None
        self._popis_zdroje = {}
        self.chyby_nacteni = {}       # rok → chyba u ročníků, které se nepodařilo načíst

    @staticmethod
    def _parsuj_json(data):
        return json.loads(data.decode("utf-8-sig"))

    def _nacti_rok(self, rok, vynutit=False):
        chyby = []
        for domena in DOMENY_DATASETU:
            if self.pouzit_zip:
                url = URL_DATASET_ZIP.format(domena=domena, rok=rok)
                cesta = self._cache_cesta("requests_zip_%s.zip" % rok)
                try:
                    self._ziskej_s_cache(url, "requests_zip_%s.zip" % rok, vynutit)
                    with zipfile.ZipFile(cesta) as z:
                        jsony = [n for n in z.namelist() if n.lower().endswith(".json")]
                        if not jsony:
                            raise OdokChyba("ZIP neobsahuje JSON: %s" % url)
                        zaznamy = []
                        self._popis_zdroje[rok] = "%s\nZIP: %d JSON souborů, např. %s\n%s" % (
                            url, len(jsony), ", ".join(jsony[:3]),
                            _popis_json(self._parsuj_json(z.read(jsony[0]))))
                        for n in jsony:
                            zaznamy.extend(_najdi_materialy(self._parsuj_json(z.read(n))))
                        return zaznamy
                except (OdokChyba, zipfile.BadZipFile) as e:
                    self._log("ZIP selhal (%s)" % e)
                    chyby.append(str(e))
                    if _je_chyba_spojeni(e):
                        continue  # server neodpovídá – JSON ze stejné domény nezkoušet
            url = URL_DATASET_JSON.format(domena=domena, rok=rok)
            try:
                data = self._ziskej_s_cache(url, "requests_%s.json" % rok, vynutit)
                surova = self._parsuj_json(data)
                self._popis_zdroje[rok] = "%s\n%s" % (url, _popis_json(surova))
                return _najdi_materialy(surova)
            except OdokChyba as e:
                self._log("JSON selhal (%s)" % e)
                chyby.append(str(e))
        raise OdokChyba(
            "Dataset za rok %s se nepodařilo stáhnout (způsoby: %s).\n  %s\n"
            "Tip: spusťte 'py odok.py --diagnostika'."
            % (rok, ", ".join(self.sit), "\n  ".join(chyby)))

    # ---------------- Dataset ----------------

    def obnov(self, vynutit=True):
        """Znovu načte dataset (vynutit=True ignoruje dvouhodinovou cache)."""
        zaznamy, chyby = [], {}
        for rok in self.roky:
            try:
                zaznamy.extend(self._nacti_rok(rok, vynutit=vynutit))
            except OdokChyba as chyba:
                chyby[rok] = chyba
        if chyby and len(chyby) == len(self.roky):
            raise next(iter(chyby.values()))
        for rok, chyba in chyby.items():   # nedostupný ročník přeskočit, ostatní použít
            self._log("rok %s přeskočen: %s" % (rok, chyba))
        self.chyby_nacteni = chyby
        # materiál může být ve více ročnících – ponechat poslední výskyt
        podle_pid = {}
        for z in zaznamy:
            pid = _najdi_hluboko(z, KLICE_PID, 1)
            podle_pid[_norm_id(pid) if pid else id(z)] = z
        zaznamy = list(podle_pid.values())
        self._zaznamy = zaznamy
        self._index_pid = {}
        self._index_cj = {}
        for z in zaznamy:
            pid = _najdi_hluboko(z, KLICE_PID, 1)
            if pid:
                self._index_pid[_norm_id(pid)] = z
            cj = _prvni_cesta(z, "identifier.id_no_ext") or _najdi_hluboko(z, KLICE_CJ, 2)
            for c in (cj if isinstance(cj, list) else [cj]):
                if c and not isinstance(c, dict):
                    self._index_cj.setdefault(_norm_id(c), z)
            ova = _prvni_cesta(z, "identifier.id_no")
            if ova:
                self._index_cj.setdefault(_norm_id(ova), z)
        return self

    def zaznamy(self):
        """Surová data datasetu (seznam dict)."""
        if self._zaznamy is None:
            self.obnov(vynutit=False)
        return self._zaznamy

    def materialy(self):
        """Všechny materiály jako objekty Material."""
        return [Material(z, self) for z in self.zaznamy()]

    def __iter__(self):
        return iter(self.materialy())

    def __len__(self):
        return len(self.zaznamy())

    # ---------------- Vyhledávání ----------------

    def material(self, identifikator):
        """
        Najde materiál podle PID (např. 'ALBSDW3HDEWG') nebo čísla jednacího.
        Když není v datasetu, ale vypadá jako PID, vrátí Material jen s PID
        (detail z verzí/připomínek půjde stáhnout i tak).
        """
        self.zaznamy()
        klic = _norm_id(identifikator)
        z = self._index_pid.get(klic) or self._index_cj.get(klic)
        if z is None:
            # záloha: přesná shoda libovolné textové hodnoty v záznamu
            for zaz in self._zaznamy:
                if any(_norm_id(t) == klic for t in _vsechny_texty(zaz)):
                    z = zaz
                    break
        if z is not None:
            return Material(z, self)
        if re.fullmatch(r"[A-Z0-9]{12}", klic):
            return Material({"pid": klic}, self)
        raise OdokChyba("Materiál '%s' nenalezen (roky %s)." % (identifikator, self.roky))

    def hledej(self, text, pole=None):
        """
        Fulltextové hledání (bez ohledu na diakritiku a velikost písmen).
        pole = None → hledá ve všech hodnotách; jinak seznam kandidátních klíčů.
        """
        t = bez_diakritiky(text)
        vysledek = []
        for z in self.zaznamy():
            if pole:
                hodnoty = [_jako_text(_najdi_hluboko(z, pole))]
            else:
                hodnoty = _vsechny_texty(z)
            if any(t in bez_diakritiky(h) for h in hodnoty):
                vysledek.append(Material(z, self))
        return vysledek

    def materialy_ministerstva(self, ministerstvo):
        """
        Materiály daného předkladatele.

        Primárně se porovnává zkratka organizace z eKLEPu (author.organization.id),
        např. 'MZD', 'MV', 'MF' – přesná shoda, bez ohledu na velikost písmen.
        Když žádný materiál takovou zkratku nemá, hledá se text v názvu
        předkladatele (author.organization.name), např. 'zdravotnictví'.
        """
        dotaz = _norm_id(ministerstvo)
        podle_zkratky = [z for z in self.zaznamy()
                         if _norm_id(_hodnota_cesty(z, CESTA_ORGANIZACE_ID)) == dotaz]
        if podle_zkratky:
            return [Material(z, self) for z in podle_zkratky]

        text = bez_diakritiky(ministerstvo).strip()
        vysledek = []
        for z in self.zaznamy():
            nazev = _hodnota_cesty(z, CESTA_ORGANIZACE_NAZEV)
            if nazev is None:  # záloha pro jinou strukturu dat
                nazev = _jako_text(_najdi_hluboko(z, KLICE_PREDKLADATEL))
            if text and text in bez_diakritiky(nazev):
                vysledek.append(Material(z, self))
        return vysledek

    def predkladatele(self):
        """
        Přehled předkladatelů: Counter {(zkratka, název): počet materiálů}.
        Zkratku (např. 'MZD') lze rovnou použít v materialy_ministerstva().
        """
        return Counter((_hodnota_cesty(z, CESTA_ORGANIZACE_ID) or "?",
                        _hodnota_cesty(z, CESTA_ORGANIZACE_NAZEV)
                        or _jako_text(_najdi_hluboko(z, KLICE_PREDKLADATEL)) or "(neuvedeno)")
                       for z in self.zaznamy())

    def predkladatele_souhrn(self):
        """
        Předkladatelé sloučení podle zkratky: [(zkratka, název, počet, další názvy)]
        od nejčastějšího. Název = nejčastější uvedený název (materiály bez názvu
        předkladatele se započítají ke zkratce); další názvy = ostatní varianty
        (např. změna funkce u Úřadu vlády).
        """
        podle = {}
        for (zkratka, nazev), pocet in self.predkladatele().items():
            podle.setdefault(zkratka, Counter())[nazev] += pocet
        vysledek = []
        for zkratka, nazvy in podle.items():
            uvedene = [(n, c) for n, c in nazvy.most_common() if n != "(neuvedeno)"]
            hlavni = uvedene[0][0] if uvedene else "(neuvedeno)"
            vysledek.append((zkratka, hlavni, sum(nazvy.values()), [n for n, _c in uvedene[1:]]))
        vysledek.sort(key=lambda x: (-x[2], x[0]))
        return vysledek

    # ---------------- Detail materiálu ----------------

    def verze(self, pid, vynutit=False):
        """Historické verze materiálu (JSON z portálu), cache stejně jako dataset."""
        pid = _norm_id(pid)
        data = self._ziskej_s_cache(URL_VERZE.format(pid=pid), "verze_%s.json" % pid, vynutit)
        return self._parsuj_json(data)

    def pripominky(self, pid, vynutit=False):
        """Připomínky k materiálu (JSON z portálu)."""
        pid = _norm_id(pid)
        data = self._ziskej_s_cache(URL_PRIPOMINKY.format(pid=pid),
                                    "pripominky_%s.json" % pid, vynutit)
        return self._parsuj_json(data)

    # ---------------- Diagnostika ----------------

    def ukaz_strukturu(self, data=None, max_hloubka=6, soubor=None):
        """
        Vypíše strom klíčů (s typy a ukázkou hodnoty). Bez parametru vypíše
        horní úroveň staženého souboru a strukturu prvního materiálu.
        """
        out = soubor or sys.stdout
        if data is None:
            z = self.zaznamy()
            for rok, popis in self._popis_zdroje.items():
                print("=== Soubor datasetu %s ===\n%s\n" % (rok, popis), file=out)
            print("=== Nalezeno materiálů: %d; první z nich: ===" % len(z), file=out)
            data = z[0] if z else {}

        def rek(v, odsazeni, hloubka):
            if hloubka > max_hloubka:
                return
            if isinstance(v, dict):
                for k, h in v.items():
                    ukazka = ""
                    if not isinstance(h, (dict, list)):
                        ukazka = " = " + repr(h)[:70]
                    print("%s%s (%s)%s" % ("  " * odsazeni, k, type(h).__name__, ukazka), file=out)
                    rek(h, odsazeni + 1, hloubka + 1)
            elif isinstance(v, list) and v:
                print("%s[%d položek, ukázka první:]" % ("  " * odsazeni, len(v)), file=out)
                rek(v[0], odsazeni + 1, hloubka + 1)

        rek(data, 0, 0)


# --------------------------------------------------------------------------
# Material
# --------------------------------------------------------------------------

class Material:
    """Jeden materiál. Surová data jsou v .data, detaily se stahují líně (až při potřebě)."""

    def __init__(self, data, odok):
        self.data = data
        self._odok = odok
        self._verze = None
        self._pripominky = None

    # --- základní atributy ---
    @property
    def pid(self):
        v = _najdi_hluboko(self.data, KLICE_PID, 1)
        return _norm_id(v) if v else None

    @property
    def cislo_jednaci(self):
        """Čj předkladatele (identifier.id_no_ext), např. 'MF-37169/2026/3503'."""
        return (_prvni_cesta(self.data, "identifier.id_no_ext")
                or _jako_text(_najdi_hluboko(self.data, KLICE_CJ)) or None)

    @property
    def cj_ova(self):
        """Čj Úřadu vlády / OVA (identifier.id_no), např. '689/26'."""
        return _prvni_cesta(self.data, "identifier.id_no")

    @property
    def nazev(self):
        return (_prvni_cesta(self.data, "title")
                or _jako_text(_najdi_hluboko(self.data, KLICE_NAZEV, 1)) or None)

    @property
    def predkladatel(self):
        """Název předkladatele, např. 'Ministerstvo zdravotnictví'."""
        return (_hodnota_cesty(self.data, CESTA_ORGANIZACE_NAZEV)
                or _jako_text(_najdi_hluboko(self.data, KLICE_PREDKLADATEL)) or None)

    @property
    def predkladatel_zkratka(self):
        """Zkratka předkladatele v eKLEPu, např. 'MZD'."""
        return _hodnota_cesty(self.data, CESTA_ORGANIZACE_ID)

    @property
    def stav(self):
        return (_prvni_cesta(self.data, "status.name")
                or _jako_text(_najdi_hluboko(self.data, KLICE_STAV)) or None)

    @property
    def stav_id(self):
        """Kód stavu, např. '2' (v MPŘ), 'B' (signováno), 'D' (v PSP), 'K' (ve Sbírce)."""
        return _prvni_cesta(self.data, "status.id")

    @property
    def typ(self):
        return (_prvni_cesta(self.data, "type.name")
                or _jako_text(_najdi_hluboko(self.data, KLICE_TYP)) or None)

    @property
    def typ_id(self):
        """Kód typu: NZ zákon, NV vyhláška, NNV nařízení vlády, PNZ, SNZ, NKZ."""
        return _prvni_cesta(self.data, "type.id")

    @property
    def datum(self):
        return (_prvni_cesta(self.data, "date_authorized", "metadata.date_created")
                or _jako_text(_najdi_hluboko(self.data, KLICE_DATUM)) or None)

    @property
    def datum_vytvoreni(self):
        return parsuj_cas_odok(_prvni_cesta(self.data, "metadata.date_created"))

    @property
    def datum_autorizace(self):
        return parsuj_cas_odok(_prvni_cesta(self.data, "date_authorized"))

    @property
    def konec_mpr(self):
        """Termín pro uplatnění připomínek v MPŘ (review_process.date_finish)."""
        return parsuj_cas_odok(_prvni_cesta(self.data, "review_process.date_finish"))

    @property
    def datum_jednani_vlady(self):
        """Datum jednání vlády, na jehož programu materiál byl (government_program.date)."""
        return parsuj_cas_odok(_prvni_cesta(self.data, "government_program.date"))

    @property
    def tisk_psp(self):
        """(volební období, číslo tisku) podle ODOK, např. (10, 218); jinak None."""
        cislo, obdobi = _prvni_cesta(self.data, "psp.id"), _prvni_cesta(self.data, "psp.period")
        try:
            return (int(obdobi), int(cislo)) if cislo and obdobi else None
        except (TypeError, ValueError):
            return None

    @property
    def plan(self):
        """
        Údaje o plánu vyčtené z odůvodnění (reason): {'polozka', 'termin'}, např.
        {'polozka': 'PLPV 2026', 'termin': '31. srpna'} nebo {'polozka': 'mimo Plán'}.
        """
        text = " ".join(str(_prvni_cesta(self.data, k) or "") for k in ("reason", "description"))
        t = bez_diakritiky(text)
        vysledek = {}
        m = re.search(r"plan(?:u|em)? legislativnich praci vlady na rok (\d{4})", t)
        if "mimo plan" in t:
            vysledek["polozka"] = "mimo Plán"
        elif m:
            vysledek["polozka"] = "PLPV %s" % m.group(1)
        else:
            m2 = re.search(r"plan(?:u|em)? vyhlasek na rok (\d{4})", t)
            if m2:
                vysledek["polozka"] = "Plán vyhlášek %s" % m2.group(1)
            elif "plan legislativnich praci" in t or "planu legislativnich praci" in t:
                vysledek["polozka"] = "PLPV"
        m = re.search(r"terminem pro predlozeni vlade\s+((?:\d{1,2}\.\s*)?(?:\d{1,2}\.\s*\d{4}|[a-z]+"
                      r"(?:\s+\d{4})?)|\d{1,2}\.\s*\d{1,2}\.\s*\d{4})", t)
        if m:
            # vrátit úsek z původního textu (s diakritikou) na stejné pozici
            vysledek["termin"] = text[m.start(1):m.end(1)].strip()
        return vysledek

    @property
    def url_web(self):
        return URL_DETAIL_WEB.format(pid=self.pid) if self.pid else None

    def get(self, *klice, default=None):
        """Libovolná hodnota podle kandidátních názvů klíčů, např. m.get('termin')."""
        v = _najdi_hluboko(self.data, list(klice))
        return default if v is None else v

    def info(self):
        """Základní údaje jako dict."""
        return {
            "pid": self.pid, "cislo_jednaci": self.cislo_jednaci, "cj_ova": self.cj_ova,
            "nazev": self.nazev, "predkladatel": self.predkladatel,
            "predkladatel_zkratka": self.predkladatel_zkratka, "typ": self.typ, "stav": self.stav,
            "datum": self.datum,
            "tisk_psp": "%s (%s. období)" % (self.tisk_psp[1], self.tisk_psp[0]) if self.tisk_psp else None,
            "url_web": self.url_web,
        }

    # --- detaily ---
    def _vyzaduj_pid(self):
        if not self.pid:
            raise OdokChyba("Materiál nemá PID, detail nelze stáhnout.")
        return self.pid

    def verze(self, vynutit=False):
        """Historické verze (JSON)."""
        if self._verze is None or vynutit:
            self._verze = self._odok.verze(self._vyzaduj_pid(), vynutit)
        return self._verze

    def pripominky(self, vynutit=False):
        """Připomínky (JSON)."""
        if self._pripominky is None or vynutit:
            self._pripominky = self._odok.pripominky(self._vyzaduj_pid(), vynutit)
        return self._pripominky

    def verze_seznam(self):
        """
        Historické verze jako seznam dict seřazený podle data:
        {'typ': 'MPR'|'PK'|'JV'|'PV'|…, 'typ_nazev', 'datum', 'stav', 'pid'}.
        Stahuje JSON verzí (cache stejně jako dataset).
        """
        data = self.verze()
        seznam = data.get("versions_list") if isinstance(data, dict) else None
        if not isinstance(seznam, list):
            seznam = _najdi_seznam_zaznamu(data)
        vysledek = []
        for v in seznam:
            if not isinstance(v, dict):
                continue
            vysledek.append({
                "typ": _prvni_cesta(v, "version_type.id"),
                "typ_nazev": _prvni_cesta(v, "version_type.name"),
                "datum": parsuj_cas_odok(_prvni_cesta(v, "date_authorized", "metadata.date_created")),
                "stav": _prvni_cesta(v, "status.name"),
                "pid": _prvni_cesta(v, "pid", "identifier.pid"),
            })
        vysledek.sort(key=lambda x: x["datum"] or datetime_min())
        return vysledek

    def datum_verze(self, *typy, posledni=False):
        """Datum první (nebo poslední) verze daného typu, např. datum_verze('MPR')."""
        data = [v["datum"] for v in self.verze_seznam() if v["typ"] in typy and v["datum"]]
        if not data:
            return None
        return data[-1] if posledni else data[0]

    def dokumenty(self):
        """
        Dokumenty materiálu z datasetu (průvodní dopis, návrh, důvodová zpráva …)
        jako seznam dict, seřazený podle 'order'. Dokument se pozná podle toho,
        že má 'name' a aspoň jeden z klíčů size / filenet_id / old_name.
        """
        vysledek = []
        for d in _dicty(self.data):
            if d is self.data or "name" not in d:
                continue
            if any(k in d for k in ("size", "filenet_id", "old_name")):
                vysledek.append(d)
        vysledek.sort(key=lambda d: (d.get("order") if isinstance(d.get("order"), int) else 10**6))
        return vysledek

    def url_dokumentu(self, dokument):
        """URL ke stažení dokumentu podle URL_DOKUMENT (None, když vzor není nastaven)."""
        if not URL_DOKUMENT:
            return None
        hodnoty = {k: v for k, v in dokument.items() if isinstance(v, (str, int))}
        hodnoty["material_pid"] = self.pid or ""
        try:
            return URL_DOKUMENT.format(**hodnoty)
        except (KeyError, IndexError, ValueError):
            return None

    def pripominky_seznam(self):
        """Připomínky jako plochý seznam dict (pokud je JSON vnořený)."""
        return _najdi_seznam_zaznamu(self.pripominky())

    def soubory(self, i_z_verzi=True, i_z_pripominek=False):
        """
        Seznam souborů materiálu: [{'nazev':..., 'url':..., 'zdroj':..., 'data': {...}}].
        Hledá odkazy v datech datasetu, volitelně i ve verzích a připomínkách.
        """
        zdroje = [("material", self.data)]
        if i_z_verzi and self.pid:
            try:
                zdroje.append(("verze", self.verze()))
            except OdokChyba as e:
                self._odok._log("verze nedostupné:", e)
        if i_z_pripominek and self.pid:
            try:
                zdroje.append(("pripominky", self.pripominky()))
            except OdokChyba as e:
                self._odok._log("připomínky nedostupné:", e)

        vysledek, videno = [], set()
        for d in self.dokumenty():
            url = self.url_dokumentu(d)
            if url and url not in videno:
                videno.add(url)
                vysledek.append({"nazev": d.get("old_name") or d.get("name"), "url": url,
                                 "zdroj": "material", "data": d})
        for zdroj, data in zdroje:
            for d in _dicty(data):
                url = _najdi_klic(d, KLICE_URL)
                if not isinstance(url, str):
                    continue
                url = url.strip()
                if not (url.startswith("http") or url.startswith("/")):
                    continue
                url = urllib.parse.urljoin(ZAKLADNI_URL + "/", url)
                if url in videno:
                    continue
                videno.add(url)
                nazev = _najdi_klic(d, KLICE_NAZEV_SOUBORU)
                nazev = _jako_text(nazev) if nazev else os.path.basename(
                    urllib.parse.urlparse(url).path) or "soubor"
                vysledek.append({"nazev": nazev, "url": url, "zdroj": zdroj, "data": d})
        return vysledek

    def stahni_soubory(self, cil=None, prepsat=False, **kw):
        """
        Stáhne soubory materiálu do adresáře `cil`
        (výchozí: <cache>/soubory/<PID>). Vrací seznam cest.
        """
        cil = cil or os.path.join(self._odok.cache_dir, "soubory", self.pid or "bez_pid")
        os.makedirs(cil, exist_ok=True)
        cesty = []
        for s in self.soubory(**kw):
            try:
                obsah, hlavicky = self._odok._stahni_bytes(s["url"])
            except (urllib.error.URLError, OSError) as e:
                self._odok._log("nelze stáhnout", s["url"], e)
                continue
            jmeno = _jmeno_z_hlavicek(hlavicky) or s["nazev"]
            cesta = os.path.join(cil, _bezpecny_nazev(jmeno))
            if os.path.exists(cesta) and not prepsat:
                zaklad, pripona = os.path.splitext(cesta)
                i = 2
                while os.path.exists("%s_%d%s" % (zaklad, i, pripona)):
                    i += 1
                cesta = "%s_%d%s" % (zaklad, i, pripona)
            with open(cesta, "wb") as f:
                f.write(obsah)
            cesty.append(cesta)
        return cesty

    def __repr__(self):
        return "<Material %s | %s | %s>" % (self.pid, self.cislo_jednaci,
                                             (self.nazev or "")[:60])

    def __str__(self):
        return "\n".join("%-14s %s" % (k + ":", v) for k, v in self.info().items())


def datetime_min():
    from datetime import datetime
    return datetime.min


def _dicty(data):
    """Všechny dict v libovolně vnořené struktuře."""
    if isinstance(data, dict):
        yield data
        for v in data.values():
            yield from _dicty(v)
    elif isinstance(data, list):
        for v in data:
            yield from _dicty(v)


def _jmeno_z_hlavicek(hlavicky):
    cd = hlavicky.get("Content-Disposition", "") if hlavicky else ""
    m = re.search(r"filename\*=(?:UTF-8'')?([^;]+)", cd, re.I)
    if m:
        return urllib.parse.unquote(m.group(1).strip('" '))
    m = re.search(r'filename="?([^";]+)"?', cd, re.I)
    if m:
        jmeno = m.group(1)
        try:  # servery často posílají UTF-8 jako latin-1
            jmeno = jmeno.encode("latin-1").decode("utf-8")
        except (UnicodeEncodeError, UnicodeDecodeError):
            pass
        return jmeno
    return None


# --------------------------------------------------------------------------
# CLI:  python odok.py <PID|čj>   |   python odok.py --ministerstvo vnitra
#       python odok.py --struktura
# --------------------------------------------------------------------------

def _hlavni(a, ap):
    sit = a.sit.split(",") if a.sit else "auto"
    o = Odok(roky=a.rok, proxy=a.proxy, sit=sit, verbose=a.verbose)
    if a.diagnostika:
        o.diagnostika()
    elif a.struktura:
        o.ukaz_strukturu()
    elif a.predkladatele:
        for (zkratka, nazev), n in o.predkladatele().most_common():
            print("%5d  %-8s %s" % (n, zkratka, nazev))
    elif a.ministerstvo:
        for m in o.materialy_ministerstva(a.ministerstvo):
            print("%s  %-25s  %s" % (m.pid, m.cislo_jednaci or "-", m.nazev))
    elif a.id:
        m = o.material(a.id)
        print(m)
        if a.soubory:
            for s in m.soubory():
                print(" -", s["nazev"], s["url"])
        if a.stahni:
            for c in m.stahni_soubory(a.stahni):
                print("staženo:", c)
    else:
        ap.print_help()


if __name__ == "__main__":
    import argparse

    ap = argparse.ArgumentParser(description="Data z ODOK / VeKLEP")
    ap.add_argument("id", nargs="?", help="PID nebo číslo jednací")
    ap.add_argument("--rok", type=int, action="append", help="rok datasetu (lze opakovat)")
    ap.add_argument("--ministerstvo", help="výpis materiálů předkladatele podle zkratky (MZD, MV, …)")
    ap.add_argument("--predkladatele", action="store_true", help="přehled předkladatelů")
    ap.add_argument("--struktura", action="store_true", help="struktura dat")
    ap.add_argument("--soubory", action="store_true", help="výpis souborů materiálu")
    ap.add_argument("--stahni", metavar="ADRESAR", help="stáhni soubory materiálu")
    ap.add_argument("--proxy", help="proxy pro způsob 'python', např. "
                                    "http://proxy.urad.cz:8080 ('' = bez proxy)")
    ap.add_argument("--sit", help="způsob stahování: windows, powershell, python "
                                  "(lze víc oddělených čárkou; výchozí auto)")
    ap.add_argument("--diagnostika", action="store_true", help="test spojení a proxy")
    ap.add_argument("-v", "--verbose", action="store_true")
    a = ap.parse_args()

    try:
        _hlavni(a, ap)
    except OdokChyba as e:
        print("CHYBA:", e, file=sys.stderr)
        sys.exit(1)
