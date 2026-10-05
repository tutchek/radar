# -*- coding: utf-8 -*-
"""
senat_analyza.py – přehled senátních dat z otevřených dat PS ČR (se_tisk.zip).

Stáhne se_tisk.zip (a tisky.zip, pokud ještě není v cache) stejně jako aplikace
(proxy s přihlášením Windows), vypíše strukturu všech tabulek v archivu a ukázky
propojení sněmovních a senátních tisků. Výsledek uloží do senat_analyza.txt.

Použití:
    py senat_analyza.py
"""

import os
import sys
import zipfile
from collections import Counter, defaultdict

import psp_analyza as pa
from psp import Psp, URL_OPENDATA


def nacti(cesta):
    tabulky = {}
    with zipfile.ZipFile(cesta) as z:
        for info in z.infolist():
            if info.is_dir():
                continue
            data = z.read(info.filename)
            for kod in ("cp1250", "utf-8"):
                try:
                    text = data.decode(kod)
                    break
                except UnicodeDecodeError:
                    continue
            else:
                text = data.decode("cp1250", "replace")
            tabulky[os.path.basename(info.filename).lower()] = list(pa.radky_unl(text))
    return tabulky


def main():
    p = Psp(verbose="-v" in sys.argv)
    with open("senat_analyza.txt", "w", encoding="utf-8") as f:
        class Oba:
            def write(self, s):
                f.write(s)
                sys.stdout.write(s)

            def flush(self):
                pass
        out = Oba()
        try:
            p._ziskej_s_cache(URL_OPENDATA.format(archiv="se_tisk"), "se_tisk.zip")
        except Exception as chyba:  # noqa: BLE001
            print("CHYBA stažení se_tisk.zip: %s" % chyba, file=out)
            return
        cesta = p._cache_cesta("se_tisk.zip")
        print("Zdroj: %s\n" % URL_OPENDATA.format(archiv="se_tisk"), file=out)
        pa.analyzuj_zip(cesta, out)

        se = nacti(cesta)
        print("\n" + "=" * 78 + "\nROZBOR\n" + "=" * 78, file=out)
        st = se.get("se_tisk.unl", [])
        vazby = se.get("psp2senat.unl", [])
        druhy = {r[0]: r[1] for r in se.get("se_druh_tisku.unl", []) if len(r) > 1}
        print("se_tisk: %d řádků, psp2senat: %d řádků" % (len(st), len(vazby)), file=out)
        if st:
            print("\nid_obdobi (sloupec 5):", dict(sorted(Counter(r[4] for r in st if len(r) > 4).items())),
                  file=out)
            print("id_druh (sloupec 2):", ", ".join("%s=%s (%d)" % (k, druhy.get(k, "?"), n)
                                                   for k, n in Counter(r[1] for r in st).most_common()), file=out)
            print("\nid_stav (sloupec 3) – četnost a ukázky anotací (podle stavu poznáme význam):", file=out)
            podle_stavu = defaultdict(list)
            for r in st:
                if len(r) > 5:
                    podle_stavu[r[2]].append(r)
            for stav, radky in sorted(podle_stavu.items(), key=lambda x: -len(x[1])):
                print("  stav %-6s %5d×  např.: %s" % (stav, len(radky), " || ".join(
                    "%s/%s %s" % (r[3], r[4], pa.zkrat(r[5])[:60]) for r in radky[-3:])), file=out)
            nejvyssi = max((int(r[4]) for r in st if len(r) > 4 and r[4].isdigit()), default=None)
            print("\nposledních 15 tisků nejnovějšího období (%s):" % nejvyssi, file=out)
            for r in [r for r in st if len(r) > 4 and r[4] == str(nejvyssi)][-15:]:
                print("   " + " | ".join(pa.zkrat(h) for h in r), file=out)
        if vazby:
            print("\npsp2senat typ:", dict(Counter(r[2] for r in vazby if len(r) > 2)), file=out)
            try:
                tisky = {str(t["id_tisk"]): t for t in p.tabulka("tisky")}
            except Exception as chyba:  # noqa: BLE001
                tisky = {}
                print("(tisky.zip nelze načíst: %s)" % chyba, file=out)
            se_podle_id = {r[0]: r for r in st}
            obdobi = {}
            try:
                for o in p.obdobi_seznam():
                    obdobi[str(o.id_organ)] = o.cislo
            except Exception:  # noqa: BLE001
                pass
            print("\nukázky propojení (posledních 25): sněmovní tisk (období) → senátní tisk", file=out)
            for r in vazby[-25:]:
                t = tisky.get(r[0])
                s = se_podle_id.get(r[1])
                print("   PS %s (%s. obd.) %s  →  typ %s  →  Senát %s / obd. %s, stav %s: %s" % (
                    t.get("ct") if t else "?", obdobi.get(str(t.get("id_org_obd"))) if t else "?",
                    pa.zkrat((t.get("nazev_tisku") or "") if t else "")[:50], r[2],
                    s[3] if s else "?", s[4] if s else "?", s[2] if s else "?",
                    pa.zkrat(s[5] if s and len(s) > 5 else "")[:50]), file=out)
            if tisky:
                bez = sum(1 for r in vazby if r[0] not in tisky)
                print("\nvazeb na sněmovní tisk, který v tisky.zip není: %d" % bez, file=out)
    print("\nUloženo do senat_analyza.txt")


if __name__ == "__main__":
    main()
