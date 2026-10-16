# -*- coding: utf-8 -*-
"""
esel.py – kontextová data z e-Legislativy (eSeL) k materiálům z ODOK.

„ODOK řídí oslíka“: řídící jsou materiály v ODOK; e-Legislativa k nim doplňuje,
co ODOK nemá – u nařízení vlády a vyhlášek fáze po projednání, číslo ve Sbírce,
datum vyhlášení, účinnost a údaje o implementaci práva EU.

Zdroj: otevřená data https://opendata.eselpoint.gov.cz/datove-sady-elegislativa/
(aktualizace denně po půlnoci). Stahování a cache jako u Odok (proxy, Windows).

Párování s materiálem z ODOK (od nejspolehlivějšího):
  1. čj OVA            – ODOK identifier.id_no ↔ eSeL návrh-cj-ova (nařízení vlády)
  2. čj předkladatele  – ODOK identifier.id_no_ext ↔ čj ve vrstvách návrhu,
                         porovnává se základ čj bez pořadového čísla („MF-36218/2025“)
  3. podobnost názvu   – úřední název aktu v eSeL ↔ název v ODOK (u vyhlášek), jen
                         v rámci stejného druhu aktu a nad prahem PRAH_NAZEV

Použití:
    from odok import Odok
    from esel import Esel
    esel = Esel()
    n, zpusob, jistota = esel.k_materialu(Odok().material("KORNDJTGJTAQ"))
    n.citace, n.datum_vyhlaseni, n.ucinnost, n.datum_stavu("Postupováno vládě")
"""

import datetime as _dt
import difflib
import gzip
import json
import os
import re
import sys
import tempfile
from collections import defaultdict

from odok import Stahovani, OdokChyba, bez_diakritiky

__version__ = "1.2"
__all__ = ["Esel", "NavrhEsel", "EselChyba"]

ZAKLAD = "https://opendata.eselpoint.gov.cz/datove-sady-elegislativa/"
# veřejná stránka návrhu; číslo je interní návrh-id z datové sady 100 (ne identifikátor 2026-0210)
URL_PORTAL = "https://e-legislativa.gov.cz/portal/navrh/{id}"
# stejný návrh v pracovní e-Legislativě (pro přihlášené uživatele), stejné návrh-id
URL_ELEGISLATIVA = "https://portal.e-legislativa.gov.cz/portal/navrh/{id}"
SADY = {
    "navrhy": "100NavrhAktuNavrh",
    "faze": "102NavrhAktuFaze",
    "stavy": "103NavrhAktuStavProcesu",
    "vrstvy": "104NavrhAktuVrstvaNavrhu",
    "metadata": "105NavrhAktuMetadataAktu",
    "ucinnost": "110NavrhAktuUcinnostAktu",
    "globalni": "180CiselnikGlobalni",
}
# druh aktu v eSeL ↔ typ materiálu v ODOK
DRUH_PODLE_TYPU_ODOK = {"NV": "VYHLASKA", "NNV": "NARIZVLA", "NZ": "ZAKON"}
PRAH_NAZEV = 0.86          # minimální podobnost názvů pro párování podle názvu


class EselChyba(OdokChyba):
    """Chyba při práci s daty e-Legislativy."""


def _cas(hodnota):
    """'2025-09-24T13:33:48' / '2025-09-10' → datetime (bez časové zóny), jinak None."""
    if not hodnota or not isinstance(hodnota, str):
        return None
    try:
        return _dt.datetime.fromisoformat(hodnota.strip()[:19])
    except ValueError:
        return None


def norm_ova(cj):
    """'783/25', '1025/14-01', '689/2026' → '783/25' (číslo/dvoumístný rok)."""
    m = re.match(r"\s*(\d+)\s*/\s*(\d{2,4})", cj or "")
    return "%d/%02d" % (int(m.group(1)), int(m.group(2)) % 100) if m else None


def norm_cj(cj):
    """Základ čj předkladatele bez pořadí dokumentu: 'MF-36218/2025/2901-29' → 'MF-36218/2025'."""
    if not cj:
        return None
    t = bez_diakritiky(cj).upper().replace(" ", "")
    m = re.match(r"([A-Z]+)[-/]?(\d+)/(\d{4})", t)
    return "%s-%s/%s" % (m.group(1), int(m.group(2)), m.group(3)) if m else None


def norm_nazev(nazev):
    """Název pro porovnání: bez diakritiky, bez „Návrh“, bez „ve znění pozdějších předpisů“."""
    t = bez_diakritiky(nazev or "")
    t = re.sub(r"^\s*navrh\s+", "", t)
    t = re.sub(r",?\s*ve zneni pozdejsich predpisu", "", t)
    t = re.sub(r"[^a-z0-9]+", " ", t)
    return " ".join(t.split())


def _bez_poradi(nazev):
    """'Postupováno vládě(1)' / 'Zařazeno na jednání vlády (2)' → 'Postupováno vládě'."""
    return re.sub(r"\s*\(\d+\)\s*$", "", nazev or "").strip()


class NavrhEsel:
    """Návrh aktu v e-Legislativě s fázemi, stavy, metadaty a účinností."""

    def __init__(self, data, esel):
        self.data = data
        self._esel = esel
        self.meta = {}
        self.ucinnost_data = {}
        self.vrstva = {}
        self.cj_predkladatele = []
        self.faze = []              # [{'nazev', 'od', 'do', 'stavy': [{'nazev','od','do'}]}]

    # --- identifikace ---
    @property
    def id(self):
        return self.data.get("návrh-id")

    @property
    def url(self):
        """Veřejná stránka návrhu na portálu e-Legislativy."""
        return URL_PORTAL.format(id=self.id) if self.id is not None else None

    @property
    def url_elegislativa(self):
        """Návrh v pracovní e-Legislativě (vyžaduje přihlášení)."""
        return URL_ELEGISLATIVA.format(id=self.id) if self.id is not None else None

    @property
    def identifikator(self):
        return self.data.get("návrh-identifikator")

    @property
    def nazev(self):
        return self.data.get("návrh-název")

    @property
    def nazev_aktu(self):
        return self.meta.get("metadata-aktu-název") or self.nazev

    @property
    def cj_ova(self):
        return self.data.get("návrh-cj-ova")

    @property
    def druh(self):
        """VYHLASKA, NARIZVLA, SDELENI, ZAKON …"""
        return self.meta.get("cis-ele-druh-aktu-položka")

    @property
    def typ_procesu(self):
        gid = (self.data.get("cis-ele-globální-typ-procesu-návrhu") or {}).get("globální-id")
        return self._esel.globalni_nazev(gid)

    # --- výsledky ---
    @property
    def citace(self):
        """Číslo ve Sbírce, např. '357/2025 Sb.' (jen u vyhlášených)."""
        return self.meta.get("metadata-aktu-citace")

    @property
    def datum_vyhlaseni(self):
        return _cas(self.meta.get("metadata-aktu-datum-čas-vyhlášení"))

    @property
    def datum_schvaleni(self):
        """Den schválení (u nařízení vlády den jednání vlády)."""
        return _cas(self.meta.get("metadata-aktu-datum-schválení"))

    @property
    def ucinnost(self):
        """Datum účinnosti (skutečné, jinak pracovní), případně účinnost textem."""
        u = self.ucinnost_data
        d = _cas(u.get("účinnost-aktu-datum-účinnosti")) or _cas(u.get("účinnost-aktu-datum-pracovní-účinnosti"))
        if d:
            return d.date()
        text = (u.get("účinnost-aktu-účinnost-textem") or {}).get("účinnost-textem")
        return text.strip() if text else None

    @property
    def kompatibilita_eu(self):
        return self.meta.get("cis-ele-kompatibilita-eu-položka")

    @property
    def termin_implementace(self):
        return _cas(self.meta.get("metadata-aktu-termín-pro-implementaci"))

    # --- průběh ---
    def stavy(self):
        """Všechny stavy procesu přes všechny fáze, chronologicky."""
        vysledek = []
        for f in self.faze:
            for s in f["stavy"]:
                vysledek.append(dict(s, faze=f["nazev"]))
        return sorted(vysledek, key=lambda s: s["od"] or _dt.datetime.min)

    def datum_stavu(self, *nazvy, posledni=False):
        """Začátek prvního (nebo posledního) stavu daného názvu, např. 'Postupováno vládě'."""
        data = [s["od"] for s in self.stavy() if _bez_poradi(s["nazev"]) in nazvy and s["od"]]
        if not data:
            return None
        return data[-1] if posledni else data[0]

    @property
    def aktualni_stav(self):
        st = [s for s in self.stavy() if s["do"] is None] or self.stavy()
        return _bez_poradi(st[-1]["nazev"]) if st else None

    def pro_plpv(self):
        """Hodnoty pro přehled legislativních prací."""
        return {
            "postupovano_vlade": self.datum_stavu("Postupováno vládě"),
            "jednani_vlady": self.datum_schvaleni or self.datum_stavu("Zapracování závěrů vlády"),
            "lrv_predlozeno": self.datum_stavu("Příprava pro jednání OKOM a PK LRV"),
            "lrv_stanoviska": self.datum_stavu("Stanoviska OKOM a PK LRV"),
            "postupovano_vydavateli": self.datum_stavu("Postupováno vydavateli", posledni=True),
            "vyhlaseno": self.datum_vyhlaseni or self.datum_stavu("Právní akt vyhlášen"),
            "citace": self.citace, "ucinnost": self.ucinnost,
            "stav": self.aktualni_stav, "eu": self.kompatibilita_eu,
            "termin_implementace": self.termin_implementace,
        }

    def __repr__(self):
        return "<NavrhEsel %s %s | %s>" % (self.identifikator, self.druh, (self.nazev or "")[:50])


class Esel(Stahovani):
    """
    Přístup k otevřeným datům e-Legislativy.

    Parametry:
        cache_dir   – adresář cache (výchozí %TEMP%/esel_cache – sdílí ho esel_analyza.py)
        max_stari_h – platnost cache v hodinách (výchozí 12; data se mění jednou denně)
        timeout, proxy, sit, verbose – viz odok.Stahovani
    """

    CHYBA = EselChyba
    DIAGNOSTICKE_URL = [ZAKLAD]

    def __init__(self, cache_dir=None, max_stari_h=12.0, timeout=60, proxy=None, sit="auto",
                 verbose=False):
        super().__init__(cache_dir or os.path.join(tempfile.gettempdir(), "esel_cache"),
                         max_stari_h=max_stari_h, timeout=timeout, proxy=proxy, sit=sit,
                         verbose=verbose)
        self._navrhy = None
        self._globalni = {}
        self._index_ova = {}
        self._index_cj = {}
        self._podle_druhu = defaultdict(list)

    # ---------------- načítání ----------------

    def _sada(self, klic):
        nazev = SADY[klic] + ".json.gz"
        data = self._ziskej_s_cache(ZAKLAD + nazev, nazev)
        try:
            obsah = json.loads(gzip.decompress(data).decode("utf-8-sig"))
        except (OSError, ValueError) as chyba:
            raise EselChyba("Soubor %s nelze přečíst: %s" % (nazev, chyba))
        return obsah.get("položky", []) if isinstance(obsah, dict) else obsah

    def soubory_cache(self):
        """Cesty k souborům cache (pro zobrazení stáří dat)."""
        return [(SADY[k], self._cache_cesta(SADY[k] + ".json.gz"))
                for k in ("navrhy", "metadata")]

    def zahod_nactena_data(self):
        self._navrhy = None
        return self

    def obnov(self):
        """Vynutí stažení čerstvých dat."""
        for klic in SADY:
            nazev = SADY[klic] + ".json.gz"
            self._ziskej_s_cache(ZAKLAD + nazev, nazev, vynutit=True)
        return self.zahod_nactena_data()

    def globalni_nazev(self, gid):
        g = self._globalni.get(gid) or {}
        n = g.get("název")
        return (n.get("cs") if isinstance(n, dict) else n) or g.get("kód")

    def navrhy(self):
        """Všechny návrhy (NavrhEsel) se složenými fázemi, stavy, metadaty a účinností."""
        if self._navrhy is not None:
            return self._navrhy
        navrhy = [NavrhEsel(n, self) for n in self._sada("navrhy")]
        podle_ident = {n.identifikator: n for n in navrhy}
        podle_id = {n.id: n for n in navrhy}

        # vrstvy: aktuální vrstva → metadata; čj předkladatele ze všech vrstev
        aktualni, cj = {}, defaultdict(set)
        for v in self._sada("vrstvy"):
            ident = "-".join((v.get("vrstva-návrhu-identifikátor") or "").split("-")[:2])
            if ident not in podle_ident:
                continue
            if v.get("vrstva-návrhu-cjpředkladatele"):
                cj[ident].add(v["vrstva-návrhu-cjpředkladatele"])
            stavajici = aktualni.get(ident)
            if v.get("vrstva-návrhu-je-aktuální") or stavajici is None or (
                    not stavajici.get("vrstva-návrhu-je-aktuální")
                    and (v.get("vrstva-návrhu-id") or 0) > (stavajici.get("vrstva-návrhu-id") or 0)):
                aktualni[ident] = {k: v.get(k) for k in (
                    "vrstva-návrhu-id", "vrstva-návrhu-identifikátor", "vrstva-návrhu-název",
                    "vrstva-návrhu-je-aktuální", "vrstva-návrhu-číslo-sněmovního-tisku",
                    "návrh-aktu-metadata-aktu")}
        potrebna_metadata = {(v.get("návrh-aktu-metadata-aktu") or {}).get("metadata-aktu-id")
                             for v in aktualni.values()}
        metadata = {m.get("metadata-aktu-id"): m for m in self._sada("metadata")
                    if m.get("metadata-aktu-id") in potrebna_metadata}
        ucinnost = {u.get("účinnost-aktu-id"): u for u in self._sada("ucinnost")}
        for ident, n in podle_ident.items():
            n.vrstva = aktualni.get(ident, {})
            n.meta = metadata.get((n.vrstva.get("návrh-aktu-metadata-aktu") or {}).get("metadata-aktu-id"), {})
            n.ucinnost_data = ucinnost.get((n.meta.get("návrh-aktu-účinnost-od") or {}).get("účinnost-aktu-id"), {})
            n.cj_predkladatele = sorted(cj.get(ident, set()) | (
                {n.data["návrh-cj-předkladatele"]} if n.data.get("návrh-cj-předkladatele") else set()))

        # fáze a stavy (stav odkazuje na fázi polem „…fáze-nadřízená.návrh-id“)
        faze_podle_id = {}
        for f in self._sada("faze"):
            n = podle_id.get((f.get("návrh-aktu-nadřízený-návrh") or {}).get("návrh-id"))
            if n is None:
                continue
            zaznam = {"nazev": f.get("fáze-název"), "od": _cas(f.get("fáze-datum-čas-zahájení")),
                      "do": _cas(f.get("fáze-datum-čas-ukončení")), "stavy": []}
            n.faze.append(zaznam)
            faze_podle_id[f.get("fáze-id")] = zaznam
        for s in self._sada("stavy"):
            f = faze_podle_id.get((s.get("stav-procesu-fáze-nadřízená") or {}).get("návrh-id"))
            if f is not None:
                f["stavy"].append({"nazev": s.get("stav-procesu-název"),
                                   "od": _cas(s.get("stav-procesu-datum-čas-zahájení")),
                                   "do": _cas(s.get("stav-procesu-datum-čas-ukončení"))})
        for n in navrhy:
            n.faze.sort(key=lambda f: f["od"] or _dt.datetime.min)
            for f in n.faze:
                f["stavy"].sort(key=lambda s: s["od"] or _dt.datetime.min)

        try:
            self._globalni = {g.get("globální-id"): g for g in self._sada("globalni")}
        except EselChyba as chyba:
            self._log("globální číselník nedostupný:", chyba)

        # indexy pro párování
        self._index_ova, self._index_cj = {}, {}
        self._podle_druhu = defaultdict(list)
        for n in navrhy:
            k = norm_ova(n.cj_ova)
            if k:
                self._index_ova.setdefault(k, n)
            for c in n.cj_predkladatele:
                k = norm_cj(c)
                if k:
                    self._index_cj.setdefault(k, n)
            self._podle_druhu[n.druh].append((norm_nazev(n.nazev_aktu), n))
        self._navrhy = navrhy
        self._log("e-Legislativa: %d návrhů" % len(navrhy))
        return navrhy

    # ---------------- vyhledání ----------------

    def navrh(self, identifikator):
        """Návrh podle identifikátoru ('2025-0092') nebo id."""
        for n in self.navrhy():
            if str(identifikator) in (n.identifikator, str(n.id)):
                return n
        raise EselChyba("Návrh %s v e-Legislativě nenalezen." % identifikator)

    def k_materialu(self, material):
        """
        Návrh v e-Legislativě k materiálu z ODOK: (NavrhEsel, způsob, jistota) nebo None.
        způsob: 'čj OVA' | 'čj předkladatele' | 'podobnost názvu'
        """
        self.navrhy()
        druh = DRUH_PODLE_TYPU_ODOK.get(getattr(material, "typ_id", None))
        k = norm_ova(getattr(material, "cj_ova", None))
        if k and k in self._index_ova:
            n = self._index_ova[k]
            if druh is None or n.druh in (druh, None):
                return n, "čj OVA", 1.0
        k = norm_cj(getattr(material, "cislo_jednaci", None))
        if k and k in self._index_cj:
            n = self._index_cj[k]
            if druh is None or n.druh in (druh, None):
                return n, "čj předkladatele", 0.95
        if druh is None:
            return None
        cil = norm_nazev(getattr(material, "nazev", ""))
        if not cil:
            return None
        nejlepsi, skore = None, 0.0
        for nazev, n in self._podle_druhu.get(druh, []):
            if not nazev:
                continue
            r = difflib.SequenceMatcher(None, cil, nazev).ratio()
            if r > skore:
                nejlepsi, skore = n, r
        if nejlepsi is not None and skore >= PRAH_NAZEV:
            return nejlepsi, "podobnost názvu", round(skore, 2)
        return None


if __name__ == "__main__":
    e = Esel(verbose="-v" in sys.argv)
    try:
        navrhy = e.navrhy()
    except OdokChyba as chyba:
        print("CHYBA:", chyba, file=sys.stderr)
        sys.exit(1)
    argumenty = [a for a in sys.argv[1:] if not a.startswith("-")]
    if argumenty:
        n = e.navrh(argumenty[0])
        print(n, "\n návrh-id %s → %s | %s\n" % (n.id, n.url, n.url_elegislativa), json.dumps(n.pro_plpv(), default=str, ensure_ascii=False, indent=1))
        for f in n.faze:
            print("fáze %-25s %s → %s" % (f["nazev"], f["od"], f["do"]))
            for s in f["stavy"]:
                print("    %-40s %s → %s" % (s["nazev"], s["od"], s["do"]))
    else:
        from collections import Counter
        print("Návrhů: %d, podle druhu: %s" % (len(navrhy), dict(Counter(n.druh for n in navrhy))))
