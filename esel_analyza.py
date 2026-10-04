# -*- coding: utf-8 -*-
"""
esel_analyza.py – stručný přehled otevřených dat e-Legislativy (eSeL).

Stáhne datové sady z https://opendata.eselpoint.gov.cz/datove-sady-elegislativa/
(stejným způsobem jako odok.py – přes proxy a přihlášení Windows) a vytvoří krátký
textový soubor esel_analyza.txt:
  * pro každou sadu strom klíčů záznamů s počty a ukázkami hodnot,
  * číselníky (malé sady) vypíše celé,
  * u návrhů ukáže několik záznamů, přednostně týkajících se zadaného resortu.

Použití:
    py esel_analyza.py                 # resort „financí“ (hledá se v textu záznamů)
    py esel_analyza.py zdravotnictví   # jiný resort (část názvu)
    py esel_analyza.py --vse           # i velká sada 140NavrhAktuObjekt (15 MB)
"""

import gzip
import json
import os
import sys
import tempfile
from collections import Counter, defaultdict

from odok import Stahovani, OdokChyba, bez_diakritiky, _najdi_seznam_zaznamu

ZAKLAD = "https://opendata.eselpoint.gov.cz/datove-sady-elegislativa/"
SADY = [
    "150CiselnikTypPravniAkt", "151CiselnikPodtypPravniAkt", "152CiselnikDruhAktu",
    "153CiselnikAutorAktu", "154CiselnikTypUcinnosti", "155CiselnikKompatibilitaEU",
    "156CiselnikTypNavrhu", "157CiselnikDruhPravnihoAktu", "158CiselnikDruhBinarniObsah",
    "159CiselnikStavObjektu", "160CiselnikObdobiPSP", "161CiselnikObdobiSEN",
    "162CiselnikTypKonzumenta", "163CiselnikStavLegislativniZamer", "181CiselnikGlobalniSkupina",
    "100NavrhAktuNavrh", "101NavrhAktuLegislativniZamer", "102NavrhAktuFaze",
    "103NavrhAktuStavProcesu", "105NavrhAktuMetadataAktu", "106NavrhAktuMetadataPredpisu",
    "110NavrhAktuUcinnostAktu", "107NavrhAktuDokument", "108NavrhAktuDokumentBinarni",
    "109NavrhAktuBinarniSoubor", "141NavrhAktuMetaEntita", "104NavrhAktuVrstvaNavrhu",
    "180CiselnikGlobalni",
]
VELKE = ["140NavrhAktuObjekt"]

CELY_VYPIS_DO = 60        # sady s nejvýše tolika záznamy se vypíší celé
UKAZEK = 3                # počet ukázkových záznamů
MAX_ZNAKU = 150
MAX_RUZNYCH = 25


class EselStahovani(Stahovani):
    DIAGNOSTICKE_URL = [ZAKLAD]

    def __init__(self, **kw):
        super().__init__(os.path.join(tempfile.gettempdir(), "esel_cache"), max_stari_h=12, **kw)


def zkrat(v):
    s = json.dumps(v, ensure_ascii=False) if isinstance(v, (dict, list)) else repr(v)
    return s if len(s) <= MAX_ZNAKU else s[:MAX_ZNAKU] + "…"


def projdi(hodnota, cesta, st):
    z = st[cesta]
    z["pocet"] += 1
    z["typy"][type(hodnota).__name__] += 1
    if isinstance(hodnota, dict):
        for k, v in hodnota.items():
            projdi(v, cesta + "." + k if cesta else k, st)
    elif isinstance(hodnota, list):
        for v in hodnota[:50]:
            projdi(v, cesta + "[]", st)
    else:
        if len(z["hodnoty"]) <= MAX_RUZNYCH:
            z["hodnoty"][str(hodnota)[:80]] += 1
        else:
            z["mnoho"] = True
        if len(z["ukazky"]) < 3 and hodnota not in (None, "") and hodnota not in z["ukazky"]:
            z["ukazky"].append(hodnota)


def nova():
    return defaultdict(lambda: {"pocet": 0, "typy": Counter(), "hodnoty": Counter(),
                                "ukazky": [], "mnoho": False})


def vypis_strom(st, out):
    for cesta in sorted(st):
        z = st[cesta]
        print("  %-55s %-10s %6d×" % (cesta or "(záznam)", "/".join(t for t, _ in z["typy"].most_common()),
                                       z["pocet"]), file=out)
        if z["ukazky"]:
            print("        ukázky: %s" % ", ".join(zkrat(u) for u in z["ukazky"]), file=out)
        if not z["mnoho"] and 1 < len(z["hodnoty"]) <= MAX_RUZNYCH:
            print("        hodnoty: %s" % ", ".join("%s (%d)" % (zkrat(h), n)
                                                  for h, n in z["hodnoty"].most_common()), file=out)


def main():
    argumenty = sys.argv[1:]
    sady = SADY + (VELKE if "--vse" in argumenty else [])
    argumenty = [a for a in argumenty if not a.startswith("--")]
    resort = bez_diakritiky(argumenty[0] if argumenty else "financí")
    s = EselStahovani()

    with open("esel_analyza.txt", "w", encoding="utf-8") as f:
        class Oba:
            def write(self, t):
                f.write(t)
                sys.stdout.write(t)

            def flush(self):
                pass
        out = Oba()
        print("Zdroj: %s (resort pro ukázky: %s)" % (ZAKLAD, resort), file=out)
        for sada in sady:
            nazev = sada + ".json.gz"
            print("\n" + "=" * 78 + "\n%s\n" % nazev + "=" * 78, file=out)
            try:
                data = gzip.decompress(s._ziskej_s_cache(ZAKLAD + nazev, nazev))
                obsah = json.loads(data.decode("utf-8-sig"))
            except (OdokChyba, OSError, ValueError) as chyba:
                print("CHYBA: %s" % chyba, file=out)
                continue
            if isinstance(obsah, dict):
                print("horní úroveň: slovník s klíči %s" % ", ".join(list(obsah)[:15]), file=out)
            zaznamy = obsah if isinstance(obsah, list) else _najdi_seznam_zaznamu(obsah)
            print("záznamů: %d (velikost po rozbalení %d kB)" % (len(zaznamy), len(data) // 1024), file=out)
            if not zaznamy:
                print("ukázka: %s" % zkrat(obsah), file=out)
                continue
            st = nova()
            for z in zaznamy:
                projdi(z, "", st)
            vypis_strom(st, out)
            if len(zaznamy) <= CELY_VYPIS_DO:
                print("  --- celý obsah ---", file=out)
                for z in zaznamy:
                    print("  " + zkrat(z).replace("\n", " ")[:400], file=out)
                continue
            vybrane = [z for z in zaznamy if resort in bez_diakritiky(json.dumps(z, ensure_ascii=False))]
            print("  --- ukázky (%d záznamů obsahuje „%s“) ---" % (len(vybrane), resort), file=out)
            for z in (vybrane[:UKAZEK] or zaznamy[:UKAZEK]):
                print("  " + json.dumps(z, ensure_ascii=False)[:1500], file=out)
    print("\nUloženo do esel_analyza.txt")


if __name__ == "__main__":
    main()
