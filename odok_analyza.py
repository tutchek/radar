# -*- coding: utf-8 -*-
"""
odok_analyza.py – stručný přehled struktury dat ODOK pro doladění aplikace.

Použije třídu Odok (stahování přes proxy stejně jako aplikace) a vytvoří
krátký textový soubor odok_analyza.txt:
  * strom všech klíčů v materiálech datasetu (sjednocený přes všechny materiály),
    s počty výskytů a ukázkovými hodnotami,
  * četnosti hodnot u položek s málo různými hodnotami (typ, stav …),
  * u několika materiálů resortu ukázku JSONu historických verzí a připomínek.

Použití:
    py odok_analyza.py                 # resort MF, letošní a loňský rok
    py odok_analyza.py MF 2025 2026    # resort a roky
    py odok_analyza.py MF 2025 2026 --stav=K --typ=NV,NNV
                                       # detail jen publikovaných vyhlášek a nařízení
"""

import sys
import time
from collections import Counter, defaultdict

from odok import Odok, OdokChyba

MAX_RUZNYCH = 40      # položky s nejvýše tolika různými hodnotami → vypsat četnosti
MAX_VYPSAT = 25      # četnosti se vypíší jen u položek s nejvýše tolika hodnotami
MAX_ZNAKU = 120
POCET_DETAILU = 6     # u kolika materiálů resortu stáhnout verze a připomínky


def zkrat(v):
    s = repr(v)
    return s if len(s) <= MAX_ZNAKU else s[:MAX_ZNAKU] + "…"


def projdi(hodnota, cesta, statistika):
    """Sbírá pro každou cestu klíčů: typy, počet výskytů, ukázky a různé hodnoty."""
    st = statistika[cesta]
    st["pocet"] += 1
    st["typy"][type(hodnota).__name__] += 1
    if isinstance(hodnota, dict):
        for k, v in hodnota.items():
            projdi(v, cesta + "." + str(k) if cesta else str(k), statistika)
    elif isinstance(hodnota, list):
        st["delky"][min(len(hodnota), 10)] += 1
        for v in hodnota:
            projdi(v, cesta + "[]", statistika)
    else:
        if len(st["hodnoty"]) <= MAX_RUZNYCH:
            st["hodnoty"][hodnota if not isinstance(hodnota, str) else hodnota[:80]] += 1
        else:
            st["mnoho"] = True
        if len(st["ukazky"]) < 3 and hodnota not in (None, "") and hodnota not in st["ukazky"]:
            st["ukazky"].append(hodnota)


def nova_statistika():
    return defaultdict(lambda: {"pocet": 0, "typy": Counter(), "hodnoty": Counter(),
                                "ukazky": [], "delky": Counter(), "mnoho": False})


def vypis_statistiku(statistika, out, celkem):
    for cesta in sorted(statistika):
        st = statistika[cesta]
        typy = "/".join(t for t, _ in st["typy"].most_common())
        radek = "%-60s %-12s %6d×" % (cesta, typy, st["pocet"])
        if st["delky"]:
            radek += "  délky seznamu: %s" % dict(sorted(st["delky"].items()))
        print(radek, file=out)
        if st["ukazky"]:
            print("      ukázky: %s" % ", ".join(zkrat(u) for u in st["ukazky"]), file=out)
        if not st["mnoho"] and 1 < len(st["hodnoty"]) <= MAX_VYPSAT and not st["delky"]:
            print("      hodnoty: %s" % ", ".join(
                "%s (%d)" % (zkrat(h), n) for h, n in st["hodnoty"].most_common()), file=out)


def strom(data, out, odsazeni="  ", hloubka=0, max_hloubka=6, polozek=2):
    """Zkrácený výpis konkrétního JSONu (u seznamů jen první položky)."""
    if hloubka > max_hloubka:
        return
    if isinstance(data, dict):
        for k, v in data.items():
            if isinstance(v, (dict, list)):
                print("%s%s:%s" % (odsazeni * (hloubka + 1), k,
                                    " [%d]" % len(v) if isinstance(v, list) else ""), file=out)
                strom(v, out, odsazeni, hloubka + 1, max_hloubka, polozek)
            else:
                print("%s%s = %s" % (odsazeni * (hloubka + 1), k, zkrat(v)), file=out)
    elif isinstance(data, list):
        for i, v in enumerate(data[:polozek]):
            print("%s- [%d]" % (odsazeni * (hloubka + 1), i), file=out)
            strom(v, out, odsazeni, hloubka + 1, max_hloubka, polozek)
        if len(data) > polozek:
            print("%s… (%d dalších)" % (odsazeni * (hloubka + 1), len(data) - polozek), file=out)
    else:
        print("%s%s" % (odsazeni * (hloubka + 1), zkrat(data)), file=out)


def main():
    argumenty = sys.argv[1:]
    stavy = typy = None
    for volba in list(argumenty):          # --stav K  --typ NV,NNV
        if volba.startswith("--stav=") or volba.startswith("--typ="):
            klic, hodnota = volba[2:].split("=", 1)
            if klic == "stav":
                stavy = set(hodnota.split(","))
            else:
                typy = set(hodnota.split(","))
            argumenty.remove(volba)
    resort = argumenty[0] if argumenty and not argumenty[0].isdigit() else "MF"
    roky = [int(a) for a in argumenty if a.isdigit()]
    if not roky:
        rok = time.localtime().tm_year
        roky = [rok - 1, rok]

    with open("odok_analyza.txt", "w", encoding="utf-8") as f:
        class Oba:
            def write(self, s):
                f.write(s)
                sys.stdout.write(s)

            def flush(self):
                pass
        out = Oba()

        o = Odok(roky=roky)
        try:
            materialy = o.zaznamy()
        except OdokChyba as e:
            print("CHYBA při stahování datasetu: %s" % e, file=out)
            return
        print("Roky %s, materiálů celkem: %d\n" % (roky, len(materialy)), file=out)
        for rok, popis in getattr(o, "_popis_zdroje", {}).items():
            print("=== Soubor %s ===\n%s\n" % (rok, popis), file=out)

        print("=" * 78, file=out)
        print("KLÍČE MATERIÁLŮ (sjednoceno přes všechny materiály)", file=out)
        print("=" * 78, file=out)
        st = nova_statistika()
        for m in materialy:
            projdi(m, "", st)
        vypis_statistiku(st, out, len(materialy))

        vybrane = o.materialy_ministerstva(resort)
        print("\n" + "=" * 78, file=out)
        print("RESORT %s: %d materiálů" % (resort, len(vybrane)), file=out)
        print("=" * 78, file=out)
        for m in vybrane[:60]:
            print("  %s | %s | %s | %s" % (m.pid, (m.typ or "")[:20], (m.stav or "")[:25],
                                           (m.nazev or "")[:90]), file=out)

        if stavy or typy:   # jen materiály v zadaném stavu / typu
            vybrane = [m for m in vybrane if (not stavy or m.stav_id in stavy)
                       and (not typy or m.typ_id in typy)]
            print("\nVýběr pro detail: stav %s, typ %s → %d materiálů" % (
                ",".join(sorted(stavy or [])) or "libovolný", ",".join(sorted(typy or [])) or "libovolný",
                len(vybrane)), file=out)
        # detail: z každého stavu materiál s nejvíce dokumenty (ať jsou vidět různé etapy)
        podle_stavu = {}
        for m in sorted(vybrane, key=lambda m: len(m.dokumenty()), reverse=True):
            podle_stavu.setdefault((m.stav, m.typ), m)
        detail = list(podle_stavu.values())[:POCET_DETAILU]
        if len(detail) < POCET_DETAILU:
            detail += [m for m in vybrane if m not in detail][:POCET_DETAILU - len(detail)]
        st_verze, st_prip = nova_statistika(), nova_statistika()
        for m in detail:
            print("\n" + "-" * 78, file=out)
            print("MATERIÁL %s – %s" % (m.pid, (m.nazev or "")[:100]), file=out)
            print("-" * 78, file=out)
            print("Údaje materiálu v datasetu (bez dokumentů):", file=out)
            strom({k: v for k, v in m.data.items() if k not in ("documents", "attachments")}, out)
            print("  metadata.date_modified = %r" % (m.data.get("metadata") or {}).get("date_modified"),
                  file=out)
            for nazev, fce, stat in (("VERZE", m.verze, st_verze),
                                     ("PŘIPOMÍNKY", m.pripominky, st_prip)):
                try:
                    data = fce()
                except (OdokChyba, ValueError) as e:
                    print("\n%s: CHYBA %s" % (nazev, e), file=out)
                    continue
                projdi(data, "", stat)
                print("\n%s (zkráceně):" % nazev, file=out)
                strom(data, out, polozek=3)

        for nazev, stat in (("VERZE", st_verze), ("PŘIPOMÍNKY", st_prip)):
            print("\n" + "=" * 78, file=out)
            print("KLÍČE JSONu %s (sjednoceno přes stažené materiály)" % nazev, file=out)
            print("=" * 78, file=out)
            vypis_statistiku(stat, out, POCET_DETAILU)

    print("\nUloženo do odok_analyza.txt")


if __name__ == "__main__":
    main()
