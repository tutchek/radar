# -*- coding: utf-8 -*-
"""
psp.py – práce s otevřenými daty Poslanecké sněmovny (sněmovní tisky,
legislativní proces, schůze) bez externích závislostí.

Dokumentace dat: https://www.psp.cz/sqw/hp.sqw?k=1300
Stahování a cache zajišťuje třída Stahovani z odok.py (stejně jako u Odok,
včetně přihlášení k proxy účtem Windows).

Rychlý start:

    from psp import Psp

    p = Psp()                          # aktuální volební období, cache 2 h v %TEMP%\\psp_cache
    t = p.tisk(123)                    # sněmovní tisk 123 (také "123/0", "123-E")
    t = p.tisk("ALBSDW3HDEWG")         # podle PID z eKLEPu (ODOK)
    t = p.tisk("OVA 345/2026")         # podle čj Úřadu vlády (OVA) z eKLEPu
    print(t)                           # souhrn: stav, datum, schůze, eKLEP …
    t.stav, t.datum_stavu, t.projednano
    t.historie()                       # kroky legislativního procesu
    t.body_schuze()                    # na kterých schůzích je / byl na pořadu
    t.aktualni_bod()                   # nejbližší (nebo poslední) projednávání
    t.pid_eklep                        # vazba na ODOK
    t.material_odok()                  # objekt Material z odok.py
    t.vybory(), t.nasledne_tisky(), t.sbirka

    p.zakony()                         # návrhy zákonů v období
    p.zakony(jen_vladni=True, jen_neprojednane=True)
    p.body_schuze(12)                  # pořad 12. schůze
    p.hledej("zdravotní pojištění")

Čísla tisků se vztahují k volebnímu období: Psp(obdobi=9) nebo Psp(obdobi=2021)
pro období 2021–2025, Psp(obdobi="vse") pro všechna.
"""

import datetime as _dt
import os
import re
import sys
import tempfile
import time
import zipfile
from collections import defaultdict

from odok import Stahovani, OdokChyba, bez_diakritiky

__version__ = "1.5"  # 1.1: úpravy podle skutečných dat (stavy, druhy, OVA čj)
__all__ = ["Psp", "Tisk", "Krok", "BodSchuze", "PspChyba", "NAZVY_ETAP"]


class PspChyba(OdokChyba):
    """Chyba při práci s daty Poslanecké sněmovny."""


# --------------------------------------------------------------------------
# Konfigurace
# --------------------------------------------------------------------------

URL_OPENDATA = "https://www.psp.cz/eknih/cdrom/opendata/{archiv}.zip"
URL_TISK = "https://www.psp.cz/sqw/historie.sqw?o={o}&t={ct}"
URL_TISK_TEXT = "https://www.psp.cz/sqw/text/tiskt.sqw?O={o}&CT={ct}&CT1={ct1}"   # text tisku / následného tisku
URL_SCHUZE = "https://www.psp.cz/sqw/ischuze.sqw?o={o}&s={schuze}"
URL_HLASOVANI = "https://www.psp.cz/sqw/hlasy.sqw?g={id}"
URL_ODOK = "https://www.odok.gov.cz/portal/veklep/material/{pid}/"

# Typy sloupců: i = celé číslo, s = text, d = datum, t = datum a čas
_I, _S, _D, _T = "i", "s", "d", "t"

# Tabulka -> (archiv, [(sloupec, typ), ...]) podle dokumentace psp.cz
SCHEMA = {
    # --- tisky.zip (https://www.psp.cz/sqw/hp.sqw?k=1303)
    "druh_tisku": ("tisky", [("id_druh", _I), ("druh_t", _S), ("nazev_druh", _S)]),
    "typ_zakon": ("tisky", [("id_navrh", _I), ("druh_navrhovatele", _S)]),
    "typ_stavu": ("tisky", [("id_typ", _I), ("popis_stavu", _S)]),
    "stavy": ("tisky", [("id_stav", _I), ("id_typ", _I), ("id_druh", _I), ("popis", _S),
                        ("lhuta", _I), ("lhuta_where", _I)]),
    "typ_akce": ("tisky", [("id_akce", _I), ("popis_akce", _S)]),
    "prechody": ("tisky", [("id_prechod", _I), ("odkud", _I), ("kam", _I), ("id_akce", _I),
                           ("typ_prechodu", _I)]),
    "tisky": ("tisky", [("id_tisk", _I), ("id_druh", _I), ("id_stav", _I), ("ct", _I),
                        ("cislo_za", _I), ("id_navrh", _I), ("id_org", _I), ("id_org_obd", _I),
                        ("id_osoba", _I), ("navrhovatel", _S), ("nazev_tisku", _S),
                        ("predlozeno", _D), ("rozeslano", _D), ("dal", _T),
                        ("tech_nos_dat", _D), ("uplny_nazev_tisku", _S), ("zm_lhuty", _I),
                        ("lhuta", _I), ("rj", _I), ("t_url", _S), ("is_eu", _I), ("roz", _T),
                        ("is_sdv", _I), ("status", _I)]),
    "hist": ("tisky", [("id_hist", _I), ("id_tisk", _I), ("datum", _T), ("id_hlas", _I),
                       ("id_prechod", _I), ("id_bod", _I), ("schuze", _I), ("usnes_ps", _I),
                       ("orgv_id_posl", _I), ("ps_id_posl", _I), ("orgv_p_usn", _I),
                       ("zaver_publik", _D), ("zaver_sb_castka", _I), ("zaver_sb_cislo", _I),
                       ("poznamka", _S)]),
    "hist_vybory": ("tisky", [("id_tisku", _I), ("id_organ", _I), ("typ", _I), ("id_hist", _I),
                              ("id_posl", _I), ("poradi", _I), ("garancni", _I)]),
    "vysledek": ("tisky", [("id_vysledek", _I), ("druh_vysledek", _S)]),
    "tisky_za": ("tisky", [("id_tisk", _I), ("cislo_za", _I), ("id_hist", _I), ("id_druh", _I),
                           ("nazev_za", _S), ("uplny_nazev_za", _S), ("rozeslano", _T),
                           ("id_org", _I), ("usn_vybor", _I), ("id_posl", _I), ("t_url", _S),
                           ("id_vysledek", _I), ("cislo_za_post", _I), ("sort_it", _I),
                           ("roz", _T), ("status", _I)]),
    "predkladatel": ("tisky", [("id_tisk", _I), ("id_osoba", _I), ("poradi", _I), ("typ", _I)]),
    # navrh_podpis je v dokumentaci, ale v tisky.zip se nevydává
    "tisk_eklep": ("tisky", [("pid", _S), ("ova_c", _I), ("ova_r", _I), ("verejne", _I),
                             ("id_tisk", _I), ("cislo_za", _I)]),
    "tz_eklep": ("tisky", [("pid", _S), ("ova_c", _I), ("ova_r", _I), ("verejne", _I),
                           ("id_tisk", _I), ("cislo_za", _I)]),
    # --- schuze.zip (https://www.psp.cz/sqw/hp.sqw?k=1308)
    "schuze": ("schuze", [("id_schuze", _I), ("id_org", _I), ("schuze", _I), ("od_schuze", _T),
                          ("do_schuze", _T), ("aktualizace", _T), ("pozvanka", _I)]),
    "schuze_stav": ("schuze", [("id_schuze", _I), ("stav", _I), ("typ", _I), ("text_dt", _S),
                               ("text_st", _S), ("tm_line", _S)]),
    "bod_schuze": ("schuze", [("id_bod", _I), ("id_schuze", _I), ("id_tisk", _I), ("id_typ", _I),
                              ("bod", _I), ("uplny_naz", _S), ("uplny_kon", _S),
                              ("poznamka", _S), ("id_bod_stav", _I), ("pozvanka", _I),
                              ("rj", _I), ("pozn2", _S), ("druh_bodu", _I), ("id_sd", _I),
                              ("zkratka", _S)]),
    "bod_stav": ("schuze", [("id_bod_stav", _I), ("popis", _S)]),
    # --- poslanci.zip (https://www.psp.cz/sqw/hp.sqw?k=1301)
    "typ_organu": ("poslanci", [("id_typ_org", _I), ("typ_id_typ_org", _I),
                                ("nazev_typ_org_cz", _S), ("nazev_typ_org_en", _S),
                                ("typ_org_obecny", _I), ("priorita", _I)]),
    "organy": ("poslanci", [("id_organ", _I), ("organ_id_organ", _I), ("id_typ_organu", _I),
                            ("zkratka", _S), ("nazev_organu_cz", _S), ("nazev_organu_en", _S),
                            ("od_organ", _D), ("do_organ", _D), ("priorita", _I),
                            ("cl_organ_base", _I)]),
    "osoby": ("poslanci", [("id_osoba", _I), ("pred", _S), ("prijmeni", _S), ("jmeno", _S),
                           ("za", _S), ("narozeni", _D), ("pohlavi", _S), ("zmena", _D),
                           ("umrti", _D)]),
    "poslanec": ("poslanci", [("id_poslanec", _I), ("id_osoba", _I), ("id_kraj", _I),
                              ("id_kandidatka", _I), ("id_obdobi", _I), ("web", _S),
                              ("ulice", _S), ("obec", _S), ("psc", _S), ("email", _S),
                              ("telefon", _S), ("fax", _S), ("psp_telefon", _S),
                              ("facebook", _S), ("foto", _I)]),
}

# bod_schuze.id_typ podle dokumentace
TYP_BODU = {1: "1. čtení", 7: "1. čtení", 15: "1. čtení", 17: "1. čtení", 18: "1. čtení",
            40: "1. čtení", 41: "1. čtení", 2: "2. čtení", 3: "2. čtení", 4: "2. čtení",
            5: "3. čtení", 6: "odpovědi na písemné interpelace", 10: "zkrácené jednání",
            13: "vráceno prezidentem", 14: "vráceno / zamítnuto Senátem"}
DRUH_BODU_PORAD = 7               # technický bod „Pořad schůze“

# Etapy projednávání podle typu stavu (typ_stavu.id_typ). Odvozeno z číselníků
# stavy/typ_stavu: u zákonů je po 1. čtení stav typu 3 („60 dnů od přikázání“)
# = projednávání ve výborech, po 2. čtení typ 21 (lhůta pro PN) a 54/55 (GV).
ETAPY = [
    ("1. čtení", {1, 17, 18, 40, 41, 44, 67, 71}),
    ("garanční výbor (po 1. čtení)", {3, 62, 65}),
    ("2. čtení", {2, 4, 72}),
    ("garanční výbor (po 2. čtení)", {21, 54, 55}),
    ("3. čtení", {5, 73, 43, 66, 77}),
    ("Senát", {12, 64}),
]
DRUHY_USNESENI_VYBORU = (8, 44, 50)   # usnesení výboru, zpráva výboru, usnesení GV
TYPY_3_CTENI = {5, 73, 43, 66, 77}
TYPY_SNEMOVNA_PO_VRACENI = {13, 14, 63}   # Sněmovna po vrácení prezidentem / Senátem
TYP_SENAT, TYP_PREZIDENT = 12, 8
ETAPA_PODLE_TYPU = {typ: nazev for nazev, typy in ETAPY for typ in typy}
NAZVY_ETAP = [nazev for nazev, _ in ETAPY]
TYP_VYBORU = {1: "navrženo", 2: "přikázáno", 3: "iniciativně"}
TYP_STAVU_PROJEDNANO = 6          # typ_stavu 6 = „KONEC“ → projednávání skončilo
DRUHY_ZAKONU = {1: "vládní návrh zákona", 2: "návrh zákona (nevládní)", 3: "státní rozpočet"}
DRUHY_VLADNI = (1, 3)
DRUHY_EU = (41, 45, 46, 47)       # k číslu tisku se přidává "-E"


# --------------------------------------------------------------------------
# Formát UNL a převody hodnot
# --------------------------------------------------------------------------

def _rozdel_radek(radek):
    """Rozdělí řádek UNL podle '|' se zohledněním escapování '\\'."""
    if "\\" not in radek:
        pole = radek.split("|")
    else:
        pole, aktualni, i, n = [], [], 0, len(radek)
        while i < n:
            znak = radek[i]
            if znak == "\\" and i + 1 < n:
                aktualni.append(radek[i + 1])
                i += 2
                continue
            if znak == "|":
                pole.append("".join(aktualni))
                aktualni = []
            else:
                aktualni.append(znak)
            i += 1
        pole.append("".join(aktualni))
    if pole and pole[-1] == "":  # řádek končí oddělovačem
        pole.pop()
    return pole


def parsuj_unl(text):
    """Text souboru UNL → seznam seznamů hodnot (řetězce, '' = null)."""
    vysledek, rozpracovano = [], None
    for radek in text.split("\n"):
        if radek.endswith("\r"):
            radek = radek[:-1]
        if rozpracovano is not None:
            radek = rozpracovano + "\n" + radek
            rozpracovano = None
        zpetnych = len(radek) - len(radek.rstrip("\\"))
        if zpetnych % 2 == 1:  # escapovaný konec řádku → hodnota pokračuje
            rozpracovano = radek[:-1]
            continue
        if radek:
            vysledek.append(_rozdel_radek(radek))
    if rozpracovano:
        vysledek.append(_rozdel_radek(rozpracovano))
    return vysledek


_FORMATY_DATA = ("%d.%m.%Y", "%Y-%m-%d")
_FORMATY_CASU = ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M", "%Y-%m-%d %H",
                 "%d.%m.%Y %H:%M:%S", "%d.%m.%Y %H:%M", "%d.%m.%Y %H",
                 "%Y-%m-%dT%H:%M:%S", "%Y-%m-%dT%H:%M")


def parsuj_datum(hodnota):
    """Tolerantní převod textu na datetime.date / datetime.datetime (jinak None)."""
    if not hodnota:
        return None
    h = hodnota.strip()
    for f in _FORMATY_CASU:
        try:
            return _dt.datetime.strptime(h, f)
        except ValueError:
            pass
    for f in _FORMATY_DATA:
        try:
            return _dt.datetime.strptime(h, f).date()
        except ValueError:
            pass
    return None


def _preved(hodnota, typ):
    if hodnota == "":
        return None
    if typ == _I:
        try:
            return int(hodnota)
        except ValueError:
            return hodnota.strip() or None
    if typ in (_D, _T):
        d = parsuj_datum(hodnota)
        return d if d is not None else hodnota
    return hodnota


def _jako_datetime(d):
    if isinstance(d, _dt.datetime):
        return d
    if isinstance(d, _dt.date):
        return _dt.datetime(d.year, d.month, d.day)
    return None


def _den(d):
    """datetime → date (pro výpis bez času)."""
    return d.date() if isinstance(d, _dt.datetime) else d


def fmt_datum(d):
    """Datum do češtiny: 5. 3. 2026 (10:00)."""
    if isinstance(d, _dt.datetime):
        if d.hour or d.minute:
            return "%d. %d. %d %d:%02d" % (d.day, d.month, d.year, d.hour, d.minute)
        return "%d. %d. %d" % (d.day, d.month, d.year)
    if isinstance(d, _dt.date):
        return "%d. %d. %d" % (d.day, d.month, d.year)
    return "" if d is None else str(d)


# --------------------------------------------------------------------------
# Hlavní třída
# --------------------------------------------------------------------------

class Obdobi:
    """Volební období PS (orgán PSPn z tabulky organy)."""

    def __init__(self, id_organ, cislo, od, do, nazev):
        self.id_organ, self.cislo, self.od, self.do, self.nazev = id_organ, cislo, od, do, nazev

    @property
    def rok_od(self):
        return self.od.year if self.od else None

    def __str__(self):
        return "%s. volební období (%s–%s)" % (
            self.cislo, self.rok_od or "?", self.do.year if self.do else "")

    def __repr__(self):
        return "<Obdobi %s>" % self


class Psp(Stahovani):
    """
    Přístup k otevřeným datům Poslanecké sněmovny.

    Parametry:
        obdobi      – None = aktuální volební období; číslo období (9, 10),
                      rok začátku (2021, 2025), id orgánu, nebo "vse"
        cache_dir   – adresář cache (výchozí %TEMP%/psp_cache)
        max_stari_h – platnost cache v hodinách (výchozí 2; data se mění denně)
        odok        – instance Odok pro vazbu na eKLEP (jinak se vytvoří až při potřebě)
        timeout, proxy, sit, verbose – viz odok.Stahovani
    """

    CHYBA = PspChyba
    DIAGNOSTICKE_URL = ["https://www.psp.cz/", URL_OPENDATA.format(archiv="schuze")]

    def __init__(self, obdobi=None, cache_dir=None, max_stari_h=2.0, odok=None,
                 timeout=60, proxy=None, sit="auto", verbose=False):
        super().__init__(cache_dir or os.path.join(tempfile.gettempdir(), "psp_cache"),
                         max_stari_h=max_stari_h, timeout=timeout, proxy=proxy,
                         sit=sit, verbose=verbose)
        self._obdobi_param = obdobi
        self._obdobi = None
        self._odok = odok
        self._tabulky = {}
        self._indexy = {}
        self._archivy_ok = {}

    # ---------------- načítání tabulek ----------------

    def _archiv(self, archiv, vynutit=False):
        """Cesta ke staženému ZIP archivu (z cache nebo nově stažený)."""
        nazev = "%s.zip" % archiv
        self._ziskej_s_cache(URL_OPENDATA.format(archiv=archiv), nazev, vynutit)
        return self._cache_cesta(nazev)

    def tabulka(self, nazev):
        """Tabulka jako seznam dict (sloupce podle SCHEMA). Načítá se jen jednou."""
        if nazev in self._tabulky:
            return self._tabulky[nazev]
        if nazev not in SCHEMA:
            raise PspChyba("Neznámá tabulka: %s" % nazev)
        archiv, sloupce = SCHEMA[nazev]
        cesta = self._archiv(archiv)
        try:
            with zipfile.ZipFile(cesta) as z:
                jmena = {os.path.basename(n).lower(): n for n in z.namelist()}
                clen = jmena.get(nazev + ".unl") or next(
                    (v for k, v in jmena.items() if k.split(".")[0] == nazev), None)
                if clen is None:
                    raise PspChyba("V archivu %s.zip chybí tabulka %s (obsah: %s)"
                                   % (archiv, nazev, ", ".join(sorted(jmena))))
                text = z.read(clen).decode("cp1250", "replace")
        except zipfile.BadZipFile as e:
            raise PspChyba("Poškozený archiv %s (%s) – smažte ho a zkuste znovu." % (cesta, e))
        radky = []
        for hodnoty in parsuj_unl(text):
            zaznam = {}
            for i, h in enumerate(hodnoty):
                if i < len(sloupce):
                    zaznam[sloupce[i][0]] = _preved(h, sloupce[i][1])
                else:
                    zaznam["_sloupec%d" % (i + 1)] = h or None
            for jmeno, _ in sloupce[len(hodnoty):]:
                zaznam[jmeno] = None
            radky.append(zaznam)
        self._log("tabulka %s: %d řádků" % (nazev, len(radky)))
        self._tabulky[nazev] = radky
        return radky

    def _index(self, tabulka, sloupec):
        """Slovník hodnota → seznam řádků (vytváří se jednou)."""
        klic = (tabulka, sloupec)
        if klic not in self._indexy:
            idx = defaultdict(list)
            for r in self.tabulka(tabulka):
                idx[r.get(sloupec)].append(r)
            self._indexy[klic] = idx
        return self._indexy[klic]

    def _jeden(self, tabulka, sloupec, hodnota):
        radky = self._index(tabulka, sloupec).get(hodnota)
        return radky[0] if radky else None

    def _bezpecne(self, fce, vychozi=None):
        """Zavolá fce(); když chybí volitelná data (např. poslanci.zip), vrátí výchozí."""
        try:
            return fce()
        except PspChyba as e:
            self._log("volitelná data nedostupná:", e)
            return vychozi

    def v_obdobi(self, obdobi):
        """
        Stejná data (sdílené tabulky a indexy, žádné nové načítání), ale jiné
        volební období: číslo (9), rok začátku (2021), id orgánu nebo "vse".
        """
        kopie = object.__new__(type(self))
        kopie.__dict__.update(self.__dict__)
        kopie._obdobi_param = obdobi
        kopie._obdobi = None
        return kopie

    def zahod_nactena_data(self):
        """Zahodí data v paměti; při dalším použití se načtou z cache nebo stáhnou (je-li prošlá)."""
        self._tabulky.clear()
        self._indexy.clear()
        self._obdobi = None
        return self

    def obnov(self):
        """Vynutí stažení čerstvých archivů a zahodí načtená data."""
        for archiv in {a for a, _ in SCHEMA.values()}:
            self._archiv(archiv, vynutit=True)
        self._tabulky.clear()
        self._indexy.clear()
        self._obdobi = None
        return self

    # ---------------- číselníky a pomocné převody ----------------

    def osoba(self, id_osoba):
        """Jméno osoby podle id_osoba (s tituly)."""
        o = self._bezpecne(lambda: self._jeden("osoby", "id_osoba", id_osoba))
        if not o:
            return None
        casti = [o.get("pred"), o.get("jmeno"), o.get("prijmeni")]
        jmeno = " ".join(c for c in casti if c)
        return jmeno + (", " + o["za"] if o.get("za") else "")

    def poslanec(self, id_poslanec):
        """Jméno poslance podle id_poslanec (zpravodajové v hist)."""
        p = self._bezpecne(lambda: self._jeden("poslanec", "id_poslanec", id_poslanec))
        return self.osoba(p["id_osoba"]) if p else None

    def organ(self, id_organ):
        """Název orgánu podle id_organ."""
        o = self._bezpecne(lambda: self._jeden("organy", "id_organ", id_organ))
        return o.get("nazev_organu_cz") if o else None

    def _stav(self, id_stav):
        return self._jeden("stavy", "id_stav", id_stav)

    def _popis_stavu(self, id_stav):
        """Název stavu: typ_stavu.popis_stavu (např. '1. čtení', 'Senát', 'KONEC')."""
        s = self._stav(id_stav)
        if not s:
            return None
        t = self._jeden("typ_stavu", "id_typ", s.get("id_typ"))
        return (t.get("popis_stavu") or "").strip() or None if t else None

    def _lhuta_stavu(self, id_stav):
        """Popis lhůty ve stavu (stavy.popis), např. '60 dnů (+-20) od přikázání'."""
        s = self._stav(id_stav)
        return (s.get("popis") or "").strip() or None if s else None

    def _druh(self, id_druh):
        d = self._jeden("druh_tisku", "id_druh", id_druh)
        return d.get("nazev_druh") if d else None

    # ---------------- volební období ----------------

    def obdobi_seznam(self):
        """Všechna volební období PS (podle orgánů PSPn), od nejnovějšího."""
        seznam = []
        for o in self._bezpecne(lambda: self.tabulka("organy"), []):
            m = re.fullmatch(r"PSP(\d+)", (o.get("zkratka") or "").strip())
            if m:
                seznam.append(Obdobi(o["id_organ"], int(m.group(1)), _jako_datetime(o.get("od_organ")),
                                     _jako_datetime(o.get("do_organ")), o.get("nazev_organu_cz")))
        seznam.sort(key=lambda x: x.cislo, reverse=True)
        return seznam

    @property
    def obdobi(self):
        """Zvolené období (Obdobi), nebo None = všechna."""
        if self._obdobi is None:
            self._obdobi = self._urci_obdobi(self._obdobi_param) or False
        return self._obdobi or None

    def _urci_obdobi(self, param):
        if isinstance(param, str) and param.lower() in ("vse", "vše", "*", "all"):
            return None
        seznam = self.obdobi_seznam()
        if not seznam:
            # bez poslanci.zip: období = nejvyšší id_org_obd v tiscích
            ids = sorted({t["id_org_obd"] for t in self.tabulka("tisky") if t.get("id_org_obd")})
            if param is None and ids:
                return Obdobi(ids[-1], None, None, None, "období id %s" % ids[-1])
            if isinstance(param, int) and param in ids:
                return Obdobi(param, None, None, None, "období id %s" % param)
            raise PspChyba("Volební období nelze určit (chybí poslanci.zip).")
        if param is None:
            return seznam[0]
        param = int(param)
        for o in seznam:
            if param in (o.cislo, o.id_organ) or (param > 1900 and o.rok_od == param):
                return o
        for o in seznam:  # rok uvnitř období
            if param > 1900 and o.od and o.od.year <= param and (o.do is None or o.do.year >= param):
                return o
        raise PspChyba("Volební období %s nenalezeno. Dostupná: %s" % (
            param, ", ".join("%d (%s)" % (o.cislo, o.rok_od) for o in seznam)))

    def _v_obdobi(self, tisk_radek):
        o = self.obdobi
        return o is None or tisk_radek.get("id_org_obd") == o.id_organ

    def _cislo_obdobi(self, id_org_obd):
        for o in self.obdobi_seznam():
            if o.id_organ == id_org_obd:
                return o.cislo
        return None

    # ---------------- tisky ----------------

    def tisky(self):
        """Všechny hlavní sněmovní tisky ve zvoleném období."""
        return [Tisk(r, self) for r in self.tabulka("tisky") if self._v_obdobi(r)]

    def zakony(self, jen_vladni=False, jen_neprojednane=False, jen_ukoncene=False):
        """
        Návrhy zákonů ve zvoleném období: druh tisku 1 (vládní návrh),
        2 (návrh zákona – poslanecký, senátní, krajský) a 3 (státní rozpočet).
        jen_vladni       – jen vládní návrhy
        jen_neprojednane – jen ty, jejichž projednávání ještě běží (ne KONEC, ne revokované)
        jen_ukoncene     – jen ty s ukončeným projednáváním (vyhlášené, zamítnuté, stažené …)
        """
        vysledek = []
        for t in self.tisky():
            druh = t.data.get("id_druh")
            if druh not in DRUHY_ZAKONU:
                continue
            if jen_vladni and druh not in DRUHY_VLADNI:
                continue
            if jen_neprojednane and (t.projednano or t.revokovano):
                continue
            if jen_ukoncene and not (t.projednano or t.revokovano):
                continue
            vysledek.append(t)
        vysledek.sort(key=lambda t: (t.ct or 0))
        return vysledek

    def tisk(self, identifikator):
        """
        Najde sněmovní tisk. Přijímá:
          123, "123", "123/0", "123-E"   – číslo tisku ve zvoleném období
          "ALBSDW3HDEWG"                 – PID materiálu v eKLEPu (ODOK)
          "OVA 345/26", "OVA 345/2026",  – čj Úřadu vlády (OVA) z eKLEPu
          "345/2026"                       (bez „OVA“ jen se čtyřmístným rokem)
          "id:4567"                      – interní id_tisk
        """
        s = str(identifikator).strip()
        if s.lower().startswith("id:"):
            r = self._jeden("tisky", "id_tisk", int(s[3:]))
            if r:
                return Tisk(r, self)
            raise PspChyba("Tisk s id_tisk %s nenalezen." % s[3:])

        # PID eKLEPu
        if re.fullmatch(r"[A-Za-z0-9]{12}", s) and not s.isdigit():
            t = self.tisk_k_materialu(s)
            if t:
                return t
            raise PspChyba("K PID %s není v datech Sněmovny žádný tisk." % s.upper())

        # čj OVA: 345/2026 (číslo za lomítkem je rok)
        m = re.fullmatch(r"(OVA\s*)?(\d+)\s*/\s*(\d{2}|\d{4})", s, re.I)
        if m and (m.group(1) or len(m.group(3)) == 4):
            c, r = int(m.group(2)), int(m.group(3)) % 100   # v datech je rok dvoumístný
            for tab in ("tisk_eklep", "tz_eklep"):
                for e in self._bezpecne(lambda: self.tabulka(tab), []):
                    if e.get("ova_c") == c and isinstance(e.get("ova_r"), int) \
                            and e["ova_r"] % 100 == r:
                        t = self._jeden("tisky", "id_tisk", e.get("id_tisk"))
                        if t:
                            return Tisk(t, self)
            raise PspChyba("K čj OVA %d/%02d není v datech Sněmovny žádný tisk." % (c, r))

        # číslo tisku
        m = re.fullmatch(r"(\d+)(?:\s*-\s*E)?(?:\s*/\s*(\d+))?", s, re.I)
        if m:
            ct = int(m.group(1))
            kandidati = [r for r in self._index("tisky", "ct").get(ct, []) if self._v_obdobi(r)]
            if kandidati:
                kandidati.sort(key=lambda r: r.get("id_org_obd") or 0, reverse=True)
                return Tisk(kandidati[0], self)
            raise PspChyba("Tisk %s ve volebním období %s nenalezen." % (
                s, self.obdobi.cislo if self.obdobi else "(všechna)"))
        raise PspChyba("Nerozumím identifikátoru tisku: %r" % identifikator)

    def tisk_v_obdobi(self, ct, cislo_obdobi):
        """Tisk podle čísla a čísla volebního období (bez ohledu na zvolené období)."""
        ids = {o.id_organ for o in self.obdobi_seznam() if o.cislo == int(cislo_obdobi)}
        for r in self._index("tisky", "ct").get(int(ct), []):
            if r.get("id_org_obd") in ids and not r.get("cislo_za"):
                return Tisk(r, self)
        return None

    def tisk_k_materialu(self, pid):
        """Tisk navázaný na materiál eKLEPu (PID z ODOK), nebo None. Hledá ve všech obdobích."""
        pid = str(pid).strip().upper()
        for tab in ("tisk_eklep", "tz_eklep"):
            for e in self._bezpecne(lambda: self.tabulka(tab), []):
                if (e.get("pid") or "").strip().upper() == pid:
                    t = self._jeden("tisky", "id_tisk", e.get("id_tisk"))
                    if t:
                        return Tisk(t, self)
        return None

    def hledej(self, text, jen_zakony=False):
        """Hledání v názvech tisků (bez ohledu na diakritiku a velikost písmen)."""
        q = bez_diakritiky(text)
        zdroj = self.zakony() if jen_zakony else self.tisky()
        return [t for t in zdroj
                if q in bez_diakritiky(t.nazev) or q in bez_diakritiky(t.uplny_nazev)]

    # ---------------- schůze ----------------

    def _schuze_radky(self):
        """Řádky tabulky schuze pro zvolené období (bez ohledu na pozvánku)."""
        vse = self.tabulka("schuze")
        o = self.obdobi
        if o is None:
            return vse
        podle_org = [s for s in vse if s.get("id_org") == o.id_organ]
        if podle_org:
            return podle_org
        # záloha: podle data (kdyby schuze.id_org neodkazoval na orgán období)
        return [s for s in vse if o.od and _jako_datetime(s.get("od_schuze"))
                and _jako_datetime(s["od_schuze"]) >= o.od
                and (o.do is None or _jako_datetime(s["od_schuze"]) <= o.do)]

    def schuze(self):
        """Seznam schůzí období: dict cislo, od, do, id_schuze, pozvanka (od nejnovější)."""
        podle_id = {}
        for s in self._schuze_radky():
            stavajici = podle_id.get(s["id_schuze"])
            if stavajici is None or (stavajici.get("pozvanka") and not s.get("pozvanka")):
                podle_id[s["id_schuze"]] = s
        seznam = [{"id_schuze": s["id_schuze"], "cislo": s.get("schuze"), "od": s.get("od_schuze"),
                   "do": s.get("do_schuze"), "navrzeny_porad": bool(s.get("pozvanka")),
                   "url": self._url_schuze(s.get("schuze"))} for s in podle_id.values()]
        seznam.sort(key=lambda x: x["cislo"] or 0, reverse=True)
        return seznam

    def _url_schuze(self, cislo):
        o = self.obdobi
        if cislo is None or o is None or o.cislo is None:
            return None
        return URL_SCHUZE.format(o=o.cislo, schuze=cislo)

    def _body(self, radky):
        """Řádky bod_schuze → BodSchuze; u stejného bodu má přednost schválený pořad."""
        schuze = {}
        for s in self.tabulka("schuze"):
            st = schuze.get(s["id_schuze"])
            if st is None or (st.get("pozvanka") and not s.get("pozvanka")):
                schuze[s["id_schuze"]] = s
        vybrane = {}
        for b in radky:
            st = vybrane.get(b["id_bod"])
            if st is None or (st.get("pozvanka") and not b.get("pozvanka")):
                vybrane[b["id_bod"]] = b
        body = [BodSchuze(b, schuze.get(b.get("id_schuze")), self) for b in vybrane.values()]
        body.sort(key=lambda x: (x.od or _dt.datetime.min, x.bod or 0))
        return body

    def body_schuze(self, cislo_schuze):
        """Pořad schůze daného čísla ve zvoleném období (seznam BodSchuze)."""
        ids = {s["id_schuze"] for s in self._schuze_radky() if s.get("schuze") == cislo_schuze}
        if not ids:
            raise PspChyba("Schůze %s ve zvoleném období nenalezena." % cislo_schuze)
        radky = [b for b in self.tabulka("bod_schuze") if b.get("id_schuze") in ids
                 and b.get("druh_bodu") != DRUH_BODU_PORAD]
        body = self._body(radky)
        body.sort(key=lambda x: x.bod or 0)
        return body

    # ---------------- ODOK ----------------

    @property
    def odok(self):
        """Instance Odok (vytvoří se až při potřebě se stejným nastavením sítě)."""
        if self._odok is None:
            from odok import Odok
            self._odok = Odok(sit=list(self.sit), proxy=self._proxy_param,
                              timeout=self.timeout, verbose=self.verbose)
        return self._odok

    # ---------------- diagnostika ----------------

    def ukaz_strukturu(self, soubor=None, ukazka=2):
        """
        Vypíše pro každou tabulku počet řádků, kontrolu počtu sloupců proti
        dokumentaci, ukázkové řádky a číselníky důležité pro stavy tisků.
        """
        out = soubor or sys.stdout
        for nazev, (archiv, sloupce) in SCHEMA.items():
            try:
                radky = self.tabulka(nazev)
            except PspChyba as e:
                print("\n## %s (%s.zip): CHYBA %s" % (nazev, archiv, e), file=out)
                continue
            navic = sum(1 for r in radky if any(k.startswith("_sloupec") for k in r))
            print("\n## %s (%s.zip): %d řádků, %d sloupců dle dokumentace%s" % (
                nazev, archiv, len(radky), len(sloupce),
                "; POZOR: %d řádků má sloupce navíc" % navic if navic else ""), file=out)
            for r in radky[-ukazka:]:
                print("   ", {k: v for k, v in r.items() if v is not None}, file=out)
        for nazev, klic, popis in (("druh_tisku", "id_druh", "nazev_druh"),
                                   ("typ_stavu", "id_typ", "popis_stavu"),
                                   ("typ_akce", "id_akce", "popis_akce"),
                                   ("bod_stav", "id_bod_stav", "popis")):
            radky = self._bezpecne(lambda: self.tabulka(nazev), [])
            print("\n## Číselník %s" % nazev, file=out)
            for r in radky:
                print("   %5s  %s" % (r.get(klic), r.get(popis)), file=out)
        print("\n## Volební období", file=out)
        for o in self.obdobi_seznam():
            print("   %2s  id_organ=%s  %s" % (o.cislo, o.id_organ, o.nazev), file=out)
        print("   zvoleno:", self.obdobi, file=out)


# --------------------------------------------------------------------------
# Tisk, krok historie, bod schůze
# --------------------------------------------------------------------------

class Krok:
    """Jeden krok legislativního procesu (řádek tabulky hist)."""

    def __init__(self, radek, psp):
        self.data = radek
        self._psp = psp
        prechod = psp._jeden("prechody", "id_prechod", radek.get("id_prechod")) or {}
        akce = psp._jeden("typ_akce", "id_akce", prechod.get("id_akce")) or {}
        self.id_hist = radek.get("id_hist")
        self.datum = radek.get("datum")
        self.akce = akce.get("popis_akce")
        self.stav = psp._popis_stavu(prechod.get("kam"))
        self.stav_predtim = psp._popis_stavu(prechod.get("odkud"))
        self.typ_kam = (psp._stav(prechod.get("kam")) or {}).get("id_typ")
        self.typ_odkud = (psp._stav(prechod.get("odkud")) or {}).get("id_typ")
        self.etapa = ETAPA_PODLE_TYPU.get(self.typ_kam)
        self.etapa_predtim = ETAPA_PODLE_TYPU.get(self.typ_odkud)
        self.schuze = radek.get("schuze")
        self.usneseni = radek.get("usnes_ps")
        self.id_hlasovani = radek.get("id_hlas")
        self.poznamka = radek.get("poznamka")
        bod = psp._jeden("bod_schuze", "id_bod", radek.get("id_bod")) if radek.get("id_bod") else None
        self.bod = bod.get("bod") if bod else None
        self.zpravodaj = psp.poslanec(radek.get("orgv_id_posl") or radek.get("ps_id_posl")) \
            if (radek.get("orgv_id_posl") or radek.get("ps_id_posl")) else None

    @property
    def sbirka(self):
        """'123/2026 Sb.' pokud krok zaznamenává vyhlášení ve Sbírce."""
        c = self.data.get("zaver_sb_cislo")
        if not c:
            return None
        rok = self.datum.year if isinstance(self.datum, _dt.date) else ""
        return "%s/%s Sb." % (c, rok)

    @property
    def url_hlasovani(self):
        return URL_HLASOVANI.format(id=self.id_hlasovani) if self.id_hlasovani else None

    def info(self):
        return {"datum": self.datum, "akce": self.akce, "stav": self.stav, "schuze": self.schuze,
                "bod": self.bod, "usneseni": self.usneseni, "hlasovani": self.id_hlasovani,
                "zpravodaj": self.zpravodaj, "sbirka": self.sbirka, "poznamka": self.poznamka}

    def __repr__(self):
        return "<Krok %s | %s → %s>" % (fmt_datum(self.datum), self.akce, self.stav)

    def __str__(self):
        doplnky = []
        if self.schuze:
            doplnky.append("%s. schůze" % self.schuze + (", bod %s" % self.bod if self.bod else ""))
        if self.usneseni:
            doplnky.append("usnesení %s" % self.usneseni)
        if self.sbirka:
            doplnky.append(self.sbirka)
        return "%-17s %s → %s%s" % (fmt_datum(self.datum), self.akce or "?", self.stav or "?",
                                    " (%s)" % ", ".join(doplnky) if doplnky else "")


class BodSchuze:
    """Bod pořadu schůze."""

    def __init__(self, radek, schuze_radek, psp):
        self.data = radek
        s = schuze_radek or {}
        self.id_bod = radek.get("id_bod")
        self.id_tisk = radek.get("id_tisk")
        self.bod = radek.get("bod")
        self.schuze = s.get("schuze")
        self.od = _jako_datetime(s.get("od_schuze"))
        self.do = _jako_datetime(s.get("do_schuze"))
        self.nazev = " ".join(x for x in (radek.get("uplny_naz"), radek.get("uplny_kon")) if x)
        self.typ = TYP_BODU.get(radek.get("id_typ"), radek.get("id_typ") or None)
        stav = psp._jeden("bod_stav", "id_bod_stav", radek.get("id_bod_stav"))
        self.stav = stav.get("popis") if stav else None
        self.navrzeny_porad = bool(radek.get("pozvanka"))
        self.url = psp._url_schuze(self.schuze)

    def info(self):
        return {"schuze": self.schuze, "od": self.od, "do": self.do, "bod": self.bod,
                "typ": self.typ, "stav": self.stav, "nazev": self.nazev,
                "navrzeny_porad": self.navrzeny_porad, "url": self.url}

    def __repr__(self):
        return "<Bod %s. schůze, bod %s: %s>" % (self.schuze, self.bod, self.typ)

    def __str__(self):
        return "%s. schůze (%s–%s), bod %s: %s%s%s" % (
            self.schuze, fmt_datum(self.od), fmt_datum(self.do), self.bod, self.typ or "",
            " – %s" % self.stav if self.stav else "",
            " [navržený pořad]" if self.navrzeny_porad else "")


class Tisk:
    """Sněmovní tisk. Surová data řádku tabulky tisky jsou v .data."""

    def __init__(self, radek, psp):
        self.data = radek
        self._psp = psp

    # --- základní údaje ---
    @property
    def id_tisk(self):
        return self.data.get("id_tisk")

    @property
    def ct(self):
        return self.data.get("ct")

    @property
    def cislo(self):
        """Číslo tisku, např. '123', '123/2', '45-E'."""
        c = str(self.ct)
        if self.data.get("id_druh") in DRUHY_EU:
            c += "-E"
        if self.data.get("cislo_za"):
            c += "/%s" % self.data["cislo_za"]
        return c

    @property
    def nazev(self):
        return self.data.get("nazev_tisku")

    @property
    def uplny_nazev(self):
        return self.data.get("uplny_nazev_tisku") or self.nazev

    @property
    def druh(self):
        return self._psp._druh(self.data.get("id_druh"))

    @property
    def obdobi(self):
        """Číslo volebního období (např. 10)."""
        return self._psp._cislo_obdobi(self.data.get("id_org_obd"))

    @property
    def navrhovatel(self):
        d = self.data
        if d.get("navrhovatel"):
            return d["navrhovatel"]
        if d.get("id_osoba"):
            return self._psp.osoba(d["id_osoba"])
        if d.get("id_org"):
            return self._psp.organ(d["id_org"])
        return None

    @property
    def typ_navrhovatele(self):
        """Typ navrhovatele z číselníku typ_zakon, např. 'VL - vláda', 'SP - skupina poslanců'."""
        t = self._psp._jeden("typ_zakon", "id_navrh", self.data.get("id_navrh"))
        return t.get("druh_navrhovatele") if t else None

    @property
    def vladni(self):
        return self.data.get("id_druh") in DRUHY_VLADNI or self.data.get("id_navrh") == 1

    def predkladatele(self):
        """Jména předkladatelů (u poslaneckých návrhů jich bývá víc)."""
        radky = sorted(self._psp._bezpecne(lambda: self._psp._index("predkladatel", "id_tisk")
                                           .get(self.id_tisk, []), []),
                       key=lambda r: r.get("poradi") or 0)
        return [self._psp.osoba(r["id_osoba"]) for r in radky]

    @property
    def predlozeno(self):
        return self.data.get("predlozeno")

    @property
    def rozeslano(self):
        return self.data.get("roz") or self.data.get("rozeslano")

    @property
    def dalsi_projednavani(self):
        """Nejbližší začátek dalšího projednávání (sloupec dal)."""
        return self.data.get("dal")

    @property
    def url(self):
        o = self.obdobi
        return URL_TISK.format(o=o, ct=self.ct) if o else None

    @property
    def url_text(self):
        return self.data.get("t_url")

    # --- stav ---
    @property
    def stav(self):
        """Popis aktuálního stavu projednávání."""
        return self._psp._popis_stavu(self.data.get("id_stav"))

    @property
    def lhuta(self):
        """Popis lhůty v aktuálním stavu (když ji stav má)."""
        return self._psp._lhuta_stavu(self.data.get("id_stav"))

    @property
    def projednano(self):
        s = self._psp._stav(self.data.get("id_stav")) or {}
        return s.get("id_typ") == TYP_STAVU_PROJEDNANO

    @property
    def revokovano(self):
        return self.data.get("status") in (1, 2)

    def historie(self):
        """Kroky legislativního procesu od nejstaršího (seznam Krok)."""
        radky = self._psp._index("hist", "id_tisk").get(self.id_tisk, [])
        radky = sorted(radky, key=lambda r: (_jako_datetime(r.get("datum")) or _dt.datetime.min,
                                             r.get("id_hist") or 0))
        return [Krok(r, self._psp) for r in radky]

    @property
    def posledni_krok(self):
        h = self.historie()
        return h[-1] if h else None

    @property
    def datum_stavu(self):
        """Kdy tisk přešel do aktuálního stavu (datum posledního kroku)."""
        k = self.posledni_krok
        return k.datum if k else None

    def etapy(self):
        """
        Etapy projednávání (1. čtení, garanční výbor, 2. čtení, garanční výbor,
        3. čtení, Senát) jako {název: {'od', 'do', 'akce', 'probiha'}}.
          od      – kdy tisk do etapy vstoupil (první vstup)
          do      – kdy etapu naposledy opustil (None, pokud v ní je / nebyl)
          akce    – akce, kterou etapu opustil (např. 'přikázán', 'schválen')
          probiha – tisk je v etapě právě teď
        Etapy, kterými tisk neprošel, ve slovníku nejsou.
        """
        vysledek = {}
        for k in self.historie():
            if k.etapa and k.etapa != k.etapa_predtim:
                e = vysledek.setdefault(k.etapa, {"od": k.datum, "do": None, "akce": None,
                                                 "probiha": False})
                e["do"], e["akce"] = None, None   # znovu vstoupil → zatím neukončena
            if k.etapa_predtim and k.etapa_predtim != k.etapa:
                e = vysledek.setdefault(k.etapa_predtim, {"od": None, "do": None, "akce": None,
                                                         "probiha": False})
                e["do"], e["akce"] = k.datum, k.akce
        aktualni = self.posledni_krok.etapa if self.posledni_krok else None
        if aktualni in vysledek and vysledek[aktualni]["do"] is None:
            vysledek[aktualni]["probiha"] = True
        self._zpresni_garancni_vybor(vysledek)
        return {n: vysledek[n] for n in NAZVY_ETAP if n in vysledek}

    def usneseni_garancniho_vyboru(self):
        """
        Usnesení garančního výboru jako následné tisky: [(datum rozeslání, číslo tisku)],
        seřazeno podle data. Když garanční výbor není v datech určen, bere
        usnesení všech výborů.
        """
        p = self._psp
        garancni = {r.get("id_organ") for r in p._bezpecne(
            lambda: p._index("hist_vybory", "id_tisku").get(self.id_tisk, []), []) if r.get("garancni")}
        vysledek = []
        for r in p._index("tisky_za", "id_tisk").get(self.id_tisk, []):
            if r.get("id_druh") not in DRUHY_USNESENI_VYBORU:
                continue
            if garancni and r.get("id_org") not in garancni:
                continue
            datum = _jako_datetime(r.get("roz") or r.get("rozeslano"))
            if datum:
                vysledek.append((datum, "%s/%s" % (self.ct, r.get("cislo_za"))))
        return sorted(vysledek)

    def _zpresni_garancni_vybor(self, etapy):
        """Konec etap garančního výboru = rozeslání usnesení GV (je-li k dispozici)."""
        usneseni = self.usneseni_garancniho_vyboru()
        if not usneseni:
            return
        gv1, gv2 = NAZVY_ETAP[1], NAZVY_ETAP[3]
        konec_1ct = _jako_datetime((etapy.get(NAZVY_ETAP[0]) or {}).get("do"))
        konec_2ct = _jako_datetime((etapy.get(NAZVY_ETAP[2]) or {}).get("do"))
        for nazev, od, do in ((gv1, konec_1ct, konec_2ct), (gv2, konec_2ct, None)):
            if nazev not in etapy or od is None:
                continue
            kandidati = [u for u in usneseni if u[0] >= od and (do is None or u[0] <= do)]
            if kandidati:
                datum, cislo = kandidati[0] if nazev == gv1 else kandidati[-1]
                etapy[nazev].update({"do": datum, "akce": "usnesení výboru (tisk %s)" % cislo,
                                     "probiha": False})

    def pro_plpv(self):
        """
        Údaje pro přehled legislativních prací (sloupce J–O excelu PLPV):
          postoupeno  – datum předložení Sněmovně
          tisk        – číslo tisku
          schvaleno_ps – text, např. '15. 7. 2026, 9. 9. 2026 (opakované hlasování)'
          senat       – text, např. '19. 8. 2026 vrácen' / '18. 6. 2026'
          prezident   – text, např. '17. 9. 2026' / '1. 5. 2026 nepodepsal'
          sbirka, vyhlaseno – číslo ve Sbírce a datum vydání částky
        """
        schvaleno, senat, prezident = [], [], []
        vyhlaseno = None

        def fmt_datum(d):  # kompaktně jako v excelu: 15.7.2026
            d = _den(d)
            return "%d.%d.%d" % (d.day, d.month, d.year) if isinstance(d, _dt.date) else ""

        for k in self.historie():
            akce = k.akce or ""
            schvaleni = any(x in bez_diakritiky(akce) for x in ("schval", "setrv"))
            if k.typ_odkud in TYPY_3_CTENI and k.typ_kam not in TYPY_3_CTENI:
                schvaleno.append(fmt_datum(_den(k.datum)) + ("" if schvaleni else " " + akce))
            elif k.typ_odkud in TYPY_SNEMOVNA_PO_VRACENI and k.typ_kam not in TYPY_SNEMOVNA_PO_VRACENI:
                schvaleno.append("%s (opakované hlasování%s)" % (
                    fmt_datum(_den(k.datum)), "" if schvaleni else ": " + akce))
            elif k.typ_odkud == TYP_SENAT and k.typ_kam != TYP_SENAT:
                senat.append(fmt_datum(_den(k.datum)) + ("" if schvaleni else " " + akce))
            elif k.typ_odkud == TYP_PREZIDENT and k.typ_kam != TYP_PREZIDENT:
                prezident.append(fmt_datum(_den(k.datum)) +
                                 ("" if "podepsal" == bez_diakritiky(akce).strip() else " " + akce))
            if k.data.get("zaver_publik"):
                vyhlaseno = k.data["zaver_publik"]
        return {
            "postoupeno": self.predlozeno, "tisk": self.cislo, "obdobi": self.obdobi,
            "schvaleno_ps": ", ".join(schvaleno) or None, "senat": ", ".join(senat) or None,
            "prezident": ", ".join(prezident) or None, "sbirka": self.sbirka,
            "vyhlaseno": vyhlaseno, "stav": self.stav, "projednano": self.projednano,
        }

    @property
    def aktualni_etapa(self):
        k = self.posledni_krok
        return k.etapa if k else None

    @property
    def sbirka(self):
        """Vyhlášení ve Sbírce zákonů, např. '123/2026 Sb.' (nebo None)."""
        for k in reversed(self.historie()):
            if k.sbirka:
                return k.sbirka
        return None

    # --- schůze ---
    def body_schuze(self):
        """Body pořadu schůzí, na kterých je / byl tisk (od nejstaršího)."""
        radky = self._psp._index("bod_schuze", "id_tisk").get(self.id_tisk, [])
        return self._psp._body(radky)

    def aktualni_bod(self):
        """Nejbližší budoucí projednávání na schůzi; když žádné není, poslední minulé."""
        body = self.body_schuze()
        if not body:
            return None
        ted = _dt.datetime.now()
        budouci = [b for b in body if (b.do or b.od or _dt.datetime.min) >= ted]
        return budouci[0] if budouci else body[-1]

    # --- výbory a následné tisky ---
    def vybory(self):
        """Výbory, kterým byl tisk navržen / přikázán (dict)."""
        radky = self._psp._bezpecne(
            lambda: self._psp._index("hist_vybory", "id_tisku").get(self.id_tisk, []), [])
        vysledek = []
        for r in sorted(radky, key=lambda r: r.get("poradi") or 0):
            vysledek.append({
                "vybor": self._psp.organ(r.get("id_organ")) or r.get("id_organ"),
                "typ": TYP_VYBORU.get(r.get("typ"), r.get("typ")),
                "garancni": r.get("garancni") in (1, 2),
                "zpravodaj": self._psp.poslanec(r.get("id_posl")) if r.get("id_posl") else None,
            })
        return vysledek

    def nasledne_tisky(self):
        """Následné tisky (usnesení výborů, pozměňovací návrhy, stanovisko vlády …)."""
        radky = self._psp._index("tisky_za", "id_tisk").get(self.id_tisk, [])
        vysledek = []
        for r in sorted(radky, key=lambda r: (r.get("sort_it") or 0, r.get("cislo_za") or 0)):
            vysl = self._psp._jeden("vysledek", "id_vysledek", r.get("id_vysledek")) or {}
            post = "abcdefghij"[r["cislo_za_post"] - 1] if isinstance(r.get("cislo_za_post"), int) \
                and 0 < r["cislo_za_post"] <= 10 else ""
            cz = r.get("cislo_za")
            cz_text = {-1: "Z", -2: "Z1", -3: "Z2"}.get(cz, cz)
            vysledek.append({
                "cislo": "%s/%s%s" % (self.cislo, cz_text, post),
                "druh": self._psp._druh(r.get("id_druh")),
                "nazev": r.get("uplny_nazev_za") or r.get("nazev_za"),
                "rozeslano": r.get("roz") or r.get("rozeslano"),
                "organ": self._psp.organ(r.get("id_org")) if r.get("id_org") else None,
                "vysledek": vysl.get("druh_vysledek"),
                "url": r.get("t_url") or (URL_TISK_TEXT.format(o=self.obdobi, ct=self.ct, ct1=cz)
                                          if isinstance(cz, int) and cz > 0 and self.obdobi else None),
            })
        return vysledek

    # --- eKLEP / ODOK ---
    def eklep(self):
        """
        Vazby na materiály v eKLEPu: [{'pid', 'cj_ova', 'verejne', 'tisk', 'url'}].
        Zahrnuje samotný tisk (tisk_eklep) i následné tisky (tz_eklep), typicky
        stanovisko vlády k nevládnímu návrhu – ty mají 'tisk' např. '123/1'.
        """
        vysledek = []
        for tab in ("tisk_eklep", "tz_eklep"):
            radky = self._psp._bezpecne(
                lambda: self._psp._index(tab, "id_tisk").get(self.id_tisk, []), [])
            for r in radky:
                pid = (r.get("pid") or "").strip()
                cz = r.get("cislo_za")
                vysledek.append({
                    "pid": pid,
                    "cj_ova": "%s/%02d" % (r["ova_c"], r["ova_r"] % 100)
                    if isinstance(r.get("ova_c"), int) and isinstance(r.get("ova_r"), int) else None,
                    "verejne": r.get("verejne") == 1,
                    "tisk": "%s/%s" % (self.ct, cz) if cz else self.cislo,
                    "nasledny": tab == "tz_eklep",
                    "url": URL_ODOK.format(pid=pid),
                })
        return vysledek

    @property
    def pid_eklep(self):
        """PID materiálu v eKLEPu k samotnému tisku (ne k následným), nebo None."""
        for e in self.eklep():
            if not e["nasledny"]:
                return e["pid"]
        return None

    def material_odok(self):
        """Materiál z ODOK (objekt odok.Material) podle PID, nebo None."""
        pid = self.pid_eklep
        return self._psp.odok.material(pid) if pid else None

    # --- výstupy ---
    def info(self):
        k = self.posledni_krok
        b = self.aktualni_bod()
        return {
            "cislo": self.cislo, "obdobi": self.obdobi, "nazev": self.nazev, "druh": self.druh,
            "navrhovatel": self.navrhovatel, "predlozeno": self.predlozeno,
            "typ_navrhovatele": self.typ_navrhovatele,
            "rozeslano": self.rozeslano, "stav": self.stav, "lhuta": self.lhuta,
            "projednano": self.projednano, "datum_stavu": self.datum_stavu,
            "posledni_akce": k.akce if k else None, "schuze": str(b) if b else None,
            "dalsi_projednavani": self.dalsi_projednavani, "sbirka": self.sbirka,
            "pid_eklep": self.pid_eklep, "url": self.url,
        }

    def __repr__(self):
        return "<Tisk %s (%s. období) | %s>" % (self.cislo, self.obdobi, (self.nazev or "")[:60])

    def __str__(self):
        popisky = {"cislo": "Tisk", "obdobi": "Období", "nazev": "Název", "druh": "Druh",
                   "navrhovatel": "Navrhovatel", "predlozeno": "Předloženo",
                   "rozeslano": "Rozesláno", "stav": "Stav",
                   "projednano": "Projednáno", "datum_stavu": "Datum stavu",
                   "typ_navrhovatele": "Typ navrhov.", "lhuta": "Lhůta",
                   "posledni_akce": "Poslední akce", "schuze": "Schůze",
                   "dalsi_projednavani": "Další projedn.", "sbirka": "Sbírka",
                   "pid_eklep": "eKLEP PID", "url": "URL"}
        radky = []
        for k, v in self.info().items():
            if v is None:
                continue
            if isinstance(v, bool):
                v = "ano" if v else "ne"
            elif isinstance(v, _dt.date):
                v = fmt_datum(v)
            radky.append("%-15s %s" % (popisky.get(k, k) + ":", v))
        return "\n".join(radky)


# --------------------------------------------------------------------------
# Příkazová řádka
# --------------------------------------------------------------------------

def _hlavni(a, ap):
    p = Psp(obdobi=a.obdobi, sit=a.sit.split(",") if a.sit else "auto",
            proxy=a.proxy, verbose=a.verbose)
    if a.diagnostika:
        p.diagnostika()
    elif a.struktura:
        p.ukaz_strukturu()
    elif a.schuze is not None:
        for b in p.body_schuze(a.schuze):
            t = p.tisk("id:%s" % b.id_tisk) if b.id_tisk else None
            print("%4s  %-12s %-6s %s" % (b.bod, b.typ or "", t.cislo if t else "", b.nazev[:90]))
    elif a.zakony:
        for t in p.zakony(jen_vladni=a.vladni, jen_neprojednane=a.neprojednane):
            print("%-6s %-10s %-40s %s" % (t.cislo, fmt_datum(t.datum_stavu),
                                           (t.stav or "")[:40], (t.nazev or "")[:80]))
    elif a.hledej:
        for t in p.hledej(a.hledej):
            print("%-6s %-40s %s" % (t.cislo, (t.stav or "")[:40], t.nazev))
    elif a.tisk:
        t = p.tisk(a.tisk)
        print(t)
        etapy = t.etapy()
        if etapy:
            print("\nEtapy:")
            for nazev, e in etapy.items():
                print("   %-30s %s" % (nazev, "probíhá od %s" % fmt_datum(e["od"]) if e["probiha"]
                                      else "ukončeno %s (%s)" % (fmt_datum(e["do"]), e["akce"] or "")
                                      if e["do"] else ""))
        print("\nHistorie:")
        for k in t.historie():
            print("  ", k)
        body = t.body_schuze()
        if body:
            print("\nSchůze:")
            for b in body:
                print("  ", b)
        for e in t.eklep():
            print("\neKLEP (tisk %s): %s  čj OVA %s%s  %s" % (
                e["tisk"], e["pid"], e["cj_ova"], "" if e["verejne"] else " (neveřejné)", e["url"]))
    else:
        ap.print_help()


if __name__ == "__main__":
    import argparse

    ap = argparse.ArgumentParser(description="Sněmovní tisky z otevřených dat PS ČR")
    ap.add_argument("tisk", nargs="?", help="číslo tisku, PID eKLEPu nebo čj OVA")
    ap.add_argument("--obdobi", help="volební období: číslo (10), rok (2025) nebo 'vse'")
    ap.add_argument("--zakony", action="store_true", help="seznam návrhů zákonů")
    ap.add_argument("--vladni", action="store_true", help="jen vládní návrhy (s --zakony)")
    ap.add_argument("--neprojednane", action="store_true", help="jen neprojednané (s --zakony)")
    ap.add_argument("--schuze", type=int, help="pořad schůze daného čísla")
    ap.add_argument("--hledej", help="hledání v názvech tisků")
    ap.add_argument("--struktura", action="store_true", help="kontrola struktury dat a číselníky")
    ap.add_argument("--sit", help="způsob stahování: windows, powershell, python")
    ap.add_argument("--proxy", help="proxy pro způsob 'python'")
    ap.add_argument("--diagnostika", action="store_true", help="test spojení")
    ap.add_argument("-v", "--verbose", action="store_true")
    a = ap.parse_args()
    if a.obdobi and a.obdobi.isdigit():
        a.obdobi = int(a.obdobi)
    try:
        _hlavni(a, ap)
    except OdokChyba as e:
        print("CHYBA:", e, file=sys.stderr)
        sys.exit(1)
