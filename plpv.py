# -*- coding: utf-8 -*-
"""
plpv.py – podklady pro aktualizaci přehledu legislativních prací resortu
(excel „PLPV“ s listy Zákony, Vyhlášky, Nařízení, Publikované ve Sbírce zákonů).
Zobrazují se jen sloupce, pro které jsou data; u každého je písmeno sloupce excelu.

Řídící jsou materiály z ODOK. U zákonů doplňuje průběh a Sbírku Poslanecká sněmovna
(psp.py), u vyhlášek a nařízení vlády e-Legislativa (esel.py): podpis (≈ postoupení
vydavateli), číslo ve Sbírce, datum vyhlášení, účinnost, implementace EU.

Kombinuje data ODOK (odok.py) a Poslanecké sněmovny (psp.py). Sloupce listů
odpovídají excelu; u každého sloupce je uvedeno, odkud se hodnota bere:
    odok  – z ODOK (verze materiálu, program vlády, stav)
    psp   – z dat Poslanecké sněmovny
    rucne – v datech není, doplňuje člověk

Použití:
    from odok import Odok
    from psp import Psp
    from plpv import Podklady

    pod = Podklady(resort="MF", rok=2026, psp=Psp())
    for lst in pod.listy():
        print(lst.nazev, len(lst.radky))
        print(lst.jako_tsv())          # pro vložení do excelu

Z příkazové řádky (vypíše listy jako TSV):
    py plpv.py MF 2026
"""

import datetime as _dt
import sys
from concurrent.futures import ThreadPoolExecutor

from odok import Odok, OdokChyba, STAV_PUBLIKOVANO

__version__ = "1.4"
__all__ = ["Podklady", "ListPLPV", "LISTY"]

ODOK, PSP, ESEL, PSP_ESEL, RUCNE = "odok", "psp", "esel", "psp_esel", "rucne"
POPIS_ZDROJE = {ODOK: "ODOK", PSP: "Poslanecká sněmovna", ESEL: "e-Legislativa",
                PSP_ESEL: "Sněmovna (zákony) / e-Legislativa (vyhlášky, nařízení)",
                RUCNE: "doplňuje se ručně"}

# (záhlaví jako v excelu, klíč, zdroj, písmeno sloupce v excelu)
# Sloupce, pro které v datech nic není (položka PLPV / mimo Plán, sekce,
# implementace práva EU, plán předložení vládě, VPŘ), se nezobrazují.
_C = ("Právní předpis", "nazev", ODOK, "C")
_G = ("MPŘ (den rozeslání)", "mpr", ODOK, "G")
_H = ("Předložení vládě", "predlozeni_vlade", ODOK, "H")

SLOUPCE_ZAKONY = [_C, _G, _H,
                  ("Vláda schválení", "vlada", ODOK, "I"),
                  ("Postoupeno PSP", "postoupeno", PSP, "J"),
                  ("Tisk", "tisk", PSP, "K"),
                  ("Schváleno PSP", "schvaleno_ps", PSP, "L"),
                  ("Schváleno Senátem", "senat", PSP, "M"),
                  ("Podpis presidenta", "prezident", PSP, "N"),
                  ("Priority", "priority", RUCNE, "O")]
SLOUPCE_VYHLASKY = [_C, _G,
                    ("Předložení vládě", "predlozeni_lrv", ODOK, "H"),
                    ("LRV", "lrv", ODOK, "I"),
                    ("Podpis ministryně", "podpis", ESEL, "J"),
                    ("Sbírka zákonů", "sbirka", ESEL, "K"),
                    ("Účinnost", "ucinnost", ESEL, "L")]
SLOUPCE_NARIZENI = [_C, _G, _H,
                    ("LRV", "lrv", ODOK, "I"),
                    ("Vláda schválení", "vlada", ODOK, "J"),
                    ("Sbírka zákonů", "sbirka", ESEL, "K"),
                    ("Účinnost", "ucinnost", ESEL, "L")]
SLOUPCE_PUBLIKOVANE = [_C, _G, _H,
                       ("Vláda schválení", "vlada", ODOK, "I"),
                       ("Postoupeno PSP", "postoupeno", PSP, "J"),
                       ("Tisk", "tisk", PSP, "K"),
                       ("Schváleno PSP", "schvaleno_ps", PSP, "L"),
                       ("Schváleno Senátem", "senat", PSP, "M"),
                       ("Podpis presidenta/ministryně", "prezident", PSP_ESEL, "N"),
                       ("Sbírka zákonů", "sbirka", PSP_ESEL, "O"),
                       ("Účinnost", "ucinnost", ESEL, "P")]

# doplňkové sloupce (za sloupci excelu) – pro orientaci a kontrolu
DOPLNKY_SPOLECNE = [("Stav v ODOK", "stav_odok", ODOK), ("PID", "pid", ODOK),
                    ("Čj", "cj", ODOK), ("Čj ÚV", "cj_ova", ODOK),
                    ("Konec MPŘ", "konec_mpr", ODOK)]
DOPLNKY_PSP = [("Aktuální stav PS", "stav_ps", PSP)] + [
    (nazev, "etapa_%d" % i, PSP) for i, nazev in enumerate(
        ["1. čtení", "garanční výbor", "2. čtení", "garanční výbor (2)", "3. čtení", "Senát (konec)"])]

DOPLNKY_ESEL = [("e-Legislativa (párování)", "esel", ESEL), ("Stav v e-Legislativě", "stav_esel", ESEL),
                ("Vyhlášeno", "vyhlaseno", ESEL), ("Kompatibilita s EU", "eu", ESEL),
                ("Termín implementace", "termin_implementace", ESEL)]

LISTY = [
    ("zakony", "Zákony", SLOUPCE_ZAKONY, DOPLNKY_PSP + DOPLNKY_SPOLECNE),
    ("vyhlasky", "Vyhlášky", SLOUPCE_VYHLASKY, DOPLNKY_ESEL + DOPLNKY_SPOLECNE),
    ("narizeni", "Nařízení", SLOUPCE_NARIZENI, DOPLNKY_ESEL + DOPLNKY_SPOLECNE),
    ("publikovane", "Publikované ve Sbírce zákonů", SLOUPCE_PUBLIKOVANE,
     DOPLNKY_ESEL + DOPLNKY_PSP + DOPLNKY_SPOLECNE),
]

TYP_ZAKON, TYP_VYHLASKA, TYP_NARIZENI = "NZ", "NV", "NNV"
KONECNE_STAVY = {STAV_PUBLIKOVANO, "Y", "DZV"}   # publikováno, skartováno, vzat zpět


def fmt(hodnota):
    """Hodnota do buňky: datum jako d.m.rrrr (excel ho rozpozná), jinak text."""
    if hodnota is None:
        return ""
    if isinstance(hodnota, _dt.datetime):
        hodnota = hodnota.date()
    if isinstance(hodnota, _dt.date):
        return "%d.%d.%d" % (hodnota.day, hodnota.month, hodnota.year)
    return str(hodnota)


def _rok(d):
    return d.year if isinstance(d, (_dt.date, _dt.datetime)) else None


class ListPLPV:
    """Jeden list podkladů: sloupce excelu, doplňkové sloupce a řádky (dict)."""

    def __init__(self, klic, nazev, sloupce, doplnky):
        self.klic, self.nazev, self.sloupce, self.doplnky = klic, nazev, sloupce, doplnky
        self.radky = []

    def jako_tsv(self, s_doplnky=False, se_zahlavim=True):
        """Text oddělený tabulátory pro vložení do excelu."""
        sloupce = self.sloupce + (self.doplnky if s_doplnky else [])
        radky = []
        if se_zahlavim:
            radky.append("\t".join(
                ("%s · %s" % (s[3], s[0]) if len(s) > 3 else s[0]).replace("\n", " ") for s in sloupce))
        for r in self.radky:
            radky.append("\t".join(fmt(r.get(s[1])).replace("\t", " ").replace("\n", " ")
                                   for s in sloupce))
        return "\n".join(radky)


class Podklady:
    """
    Sestaví podklady PLPV pro resort a časové rozmezí – volební období nebo rok.

    Parametry:
        resort – zkratka předkladatele v eKLEPu (author.organization.id), např. 'MF'
        od, do – rozmezí (datetime.date); do=None znamená „dosud“ (běžící období)
        rok    – místo od/do jeden kalendářní rok (výchozí letošní, když není ani od)
        odok   – instance Odok; když chybí, vytvoří se s roky pokrývajícími rozmezí
        psp    – instance Psp (psp.py) nebo None (pak sloupce ze Sněmovny zůstanou prázdné)
        esel   – instance Esel (esel.py) nebo None (pak u vyhlášek a nařízení chybí Sbírka,
                 účinnost a podpis; ostatní sloupce se berou z ODOK)
        vlaken – kolik historií verzí stahovat souběžně

    Pro volební období: Podklady.pro_obdobi(psp.v_obdobi(10).obdobi, resort="MF", psp=psp)
    """

    def __init__(self, resort="MF", rok=None, odok=None, psp=None, vlaken=6, od=None, do=None,
                 esel=None, **odok_param):
        self.resort = resort
        self.esel = esel         # instance esel.Esel (kontext k vyhláškám a nařízením) nebo None
        if od is None:
            rok = int(rok or _dt.date.today().year)
            od, do = _dt.date(rok, 1, 1), _dt.date(rok, 12, 31)
        self.od = od.date() if isinstance(od, _dt.datetime) else od
        self.do = do.date() if isinstance(do, _dt.datetime) else do
        konec = (self.do or _dt.date.today()).year
        # datasety ODOK jsou po letech; rok před začátkem kvůli materiálům rozpracovaným dříve
        self.roky_odok = list(range(self.od.year - 1, konec + 1))
        self.odok = odok or Odok(roky=self.roky_odok, **odok_param)
        self.psp = psp
        self.vlaken = vlaken
        self.chyby = []          # materiály, u kterých se nepodařilo stáhnout verze

    @classmethod
    def pro_obdobi(cls, obdobi, **kw):
        """Podklady za volební období (objekt psp.Obdobi s atributy od a do)."""
        return cls(od=obdobi.od, do=obdobi.do, **kw)

    @property
    def popis(self):
        do = "dosud" if self.do is None else fmt(self.do)
        return "%s – %s" % (fmt(self.od), do)

    # ---------------- výběr materiálů ----------------

    def _kandidati(self):
        typy = (TYP_ZAKON, TYP_VYHLASKA, TYP_NARIZENI)
        return [m for m in self.odok.materialy_ministerstva(self.resort) if m.typ_id in typy]

    def _nacti_verze(self, materialy):
        """Stáhne historie verzí souběžně (cache 2 h v odok)."""
        def jeden(m):
            try:
                return m.pid, m.verze_seznam()
            except (OdokChyba, ValueError, AttributeError) as e:
                return m.pid, e
        with ThreadPoolExecutor(max_workers=self.vlaken) as pool:
            vysledky = dict(pool.map(jeden, materialy))
        for pid, v in vysledky.items():
            if isinstance(v, Exception):
                self.chyby.append("%s: %s" % (pid, v))
                vysledky[pid] = []
        return vysledky

    def _tisk(self, m):
        """Sněmovní tisk k materiálu (podle ODOK psp.id, jinak přes PID v datech PS)."""
        if self.psp is None:
            return None
        t = None
        if m.tisk_psp:
            t = self.psp.tisk_v_obdobi(m.tisk_psp[1], m.tisk_psp[0])
        return t or self.psp.tisk_k_materialu(m.pid)

    # ---------------- řádek ----------------

    def radek(self, m, verze, tisk):
        """Všechny hodnoty pro jeden materiál (klíče podle sloupců listů)."""
        def prvni(*typy):
            data = [v["datum"] for v in verze if v["typ"] in typy and v["datum"]]
            return min(data) if data else None

        mpr, pk, jv, pv = prvni("MPR"), prvni("PK"), prvni("JV"), prvni("PV")
        vlada = m.datum_jednani_vlady
        if vlada and jv and vlada < jv:
            vlada = None            # program vlády starší než předložení → nejde o schválení
        vyhlaska = m.typ_id == TYP_VYHLASKA
        r = {
            "nazev": (m.nazev or "").strip(), "mpr": mpr,
            # u vyhlášek „předložení“ = verze pro komisi LRV; vládou se neschvalují
            "predlozeni_vlade": pk if vyhlaska else jv,
            "vlada": None if vyhlaska else (vlada or pv),
            "predlozeni_lrv": pk, "lrv": pv if m.typ_id == TYP_VYHLASKA else pk,
            # ODOK stav „K“ má jen u zákonů; číslo ve Sbírce doplní data Sněmovny níže
            "sbirka": "publikováno (číslo doplnit)" if m.stav_id == STAV_PUBLIKOVANO else None,
            "stav_odok": m.stav, "pid": m.pid, "cj": m.cislo_jednaci, "cj_ova": m.cj_ova,
            "konec_mpr": m.konec_mpr, "typ_id": m.typ_id, "_material": m, "_tisk": tisk,
        }
        if tisk is not None:
            p = tisk.pro_plpv()
            etapy = tisk.etapy()
            r.update({"postoupeno": p["postoupeno"], "tisk": p["tisk"],
                      "schvaleno_ps": p["schvaleno_ps"], "senat": p["senat"],
                      "prezident": p["prezident"], "stav_ps": p["stav"]})
            if p["sbirka"]:
                r["sbirka"] = p["sbirka"]
            r["_vyhlaseno"] = p["vyhlaseno"]
            nazvy = list(etapy)
            from psp import NAZVY_ETAP
            for i, nazev in enumerate(NAZVY_ETAP):
                e = etapy.get(nazev)
                r["etapa_%d" % i] = ("probíhá" if e and e["probiha"] else e["do"] if e else None)
            r["_etapy"] = nazvy
        elif m.tisk_psp:
            r["tisk"] = m.tisk_psp[1]
        self._doplnit_z_eselu(m, r, pk=pk, pv=pv, jv=jv)
        return r

    def _doplnit_z_eselu(self, m, r, pk=None, pv=None, jv=None):
        """Kontext z e-Legislativy k vyhláškám a nařízením. ODOK má přednost – eSeL doplňuje
        jen chybějící hodnoty a údaje, které ODOK nemá (podpis, Sbírka, účinnost, EU)."""
        if self.esel is None or m.typ_id not in (TYP_VYHLASKA, TYP_NARIZENI):
            return
        try:
            nalez = self.esel.k_materialu(m)
        except OdokChyba as chyba:
            self.chyby.append("e-Legislativa: %s" % chyba)
            return
        if not nalez:
            return
        n, zpusob, jistota = nalez
        e = n.pro_plpv()
        vyhlaska = m.typ_id == TYP_VYHLASKA
        if vyhlaska:
            r["predlozeni_lrv"] = r["predlozeni_lrv"] or e["postupovano_vlade"] or e["lrv_predlozeno"]
            r["predlozeni_vlade"] = r["predlozeni_lrv"]
            r["lrv"] = r["lrv"] or e["lrv_stanoviska"]
        else:
            r["predlozeni_vlade"] = r["predlozeni_vlade"] or e["postupovano_vlade"]
            r["lrv"] = r["lrv"] or e["lrv_predlozeno"]
            r["vlada"] = r["vlada"] or e["jednani_vlady"]
        r["podpis"] = e["postupovano_vydavateli"]          # ≈ podpis a předání k vyhlášení
        if e["citace"]:
            r["sbirka"] = e["citace"]
            r["prezident"] = r.get("prezident") or e["postupovano_vydavateli"]
        r["ucinnost"] = e["ucinnost"]
        r["vyhlaseno"] = e["vyhlaseno"] if e["citace"] else None
        r["_vyhlaseno"] = r.get("_vyhlaseno") or r["vyhlaseno"]
        r["stav_esel"] = e["stav"]
        r["eu"], r["termin_implementace"] = e["eu"], e["termin_implementace"]
        r["esel"] = "%s (%s%s)" % (n.identifikator, zpusob,
                                   "" if jistota >= 1 else ", shoda %d %%" % round(jistota * 100))
        r["_esel"] = n
        r["_esel_data"] = [e[k] for k in ("postupovano_vlade", "jednani_vlady", "lrv_predlozeno",
                                          "lrv_stanoviska", "postupovano_vydavateli", "vyhlaseno")]

    def _v_rozmezi(self, d):
        if not d:
            return False
        d = d.date() if isinstance(d, _dt.datetime) else d
        return self.od <= d and (self.do is None or d <= self.do)

    def _aktivni_v_roce(self, r):
        """Patří materiál do přehledu? (nějaký krok v rozmezí, nebo – u rozmezí končícího
        dnes – stále rozpracovaný a založený nejdéle rok před začátkem)."""
        m = r["_material"]
        data = [m.datum_vytvoreni, m.datum_autorizace, m.konec_mpr, m.datum_jednani_vlady,
                r.get("mpr"), r.get("predlozeni_vlade"), r.get("vlada"), r.get("predlozeni_lrv")]
        t = r.get("_tisk")
        if t is not None:
            data += [t.predlozeno] + [k.datum for k in t.historie()]
        data += r.get("_esel_data") or []
        if any(self._v_rozmezi(d) for d in data):
            return True
        dnes = _dt.date.today()
        if self.do is not None and self.do < dnes:
            return False
        rozpracovany = m.stav_id not in KONECNE_STAVY and not (t is not None and t.projednano)
        return rozpracovany and (_rok(m.datum_vytvoreni) or 0) >= self.od.year - 1

    # ---------------- listy ----------------

    def listy(self):
        """Sestaví listy (ListPLPV) v pořadí jako excel."""
        kandidati = self._kandidati()
        verze = self._nacti_verze(kandidati)
        listy = {k: ListPLPV(k, n, s, d) for k, n, s, d in LISTY}
        for m in kandidati:
            r = self.radek(m, verze.get(m.pid, []), self._tisk(m))
            if not self._aktivni_v_roce(r):
                continue
            publikovano = m.stav_id == STAV_PUBLIKOVANO or bool(
                r["_tisk"] is not None and r["_tisk"].sbirka) or bool(
                r.get("_esel") is not None and r["_esel"].citace)
            vyhlaseno = r.get("_vyhlaseno")
            if publikovano and vyhlaseno and self.do is not None and \
                    (vyhlaseno.date() if isinstance(vyhlaseno, _dt.datetime) else vyhlaseno) > self.do:
                publikovano = False     # v zvoleném rozmezí ještě nebyl vyhlášen
                r["sbirka"] = r["ucinnost"] = r["vyhlaseno"] = None
            if publikovano:
                listy["publikovane"].radky.append(r)
            elif m.typ_id == TYP_ZAKON:
                listy["zakony"].radky.append(r)
            elif m.typ_id == TYP_VYHLASKA:
                listy["vyhlasky"].radky.append(r)
            else:
                listy["narizeni"].radky.append(r)
        for lst in listy.values():
            lst.radky.sort(key=lambda r: (r.get("mpr") or r["_material"].datum_vytvoreni
                                          or _dt.datetime.max))
        return [listy[k] for k, _, _, _ in LISTY]


if __name__ == "__main__":
    resort = sys.argv[1] if len(sys.argv) > 1 else "MF"
    rok = int(sys.argv[2]) if len(sys.argv) > 2 else None
    try:
        from psp import Psp
        snemovna = Psp()
    except ImportError:
        snemovna = None
    try:
        from esel import Esel
        oslik = Esel()
    except ImportError:
        oslik = None
    pod = Podklady(resort=resort, rok=rok, psp=snemovna, esel=oslik)
    try:
        listy = pod.listy()
    except OdokChyba as chyba:
        print("CHYBA:", chyba, file=sys.stderr)
        sys.exit(1)
    for lst in listy:
        print("\n### %s (%d řádků)" % (lst.nazev, len(lst.radky)))
        print(lst.jako_tsv(s_doplnky=True))
    if pod.chyby:
        print("\nNepodařilo se stáhnout verze u: %s" % "; ".join(pod.chyby), file=sys.stderr)
