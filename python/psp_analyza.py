# -*- coding: utf-8 -*-
"""
psp_analyza.py – stručný přehled obsahu ZIP archivů otevřených dat PS ČR.

Vytvoří krátký textový soubor (desítky kB), podle kterého lze ověřit strukturu
dat, aniž by bylo nutné posílat celé archivy.

Použití:
    py psp_analyza.py tisky.zip schuze.zip poslanci.zip
    py psp_analyza.py                      # vezme *.zip v aktuální složce
Výstup: psp_analyza.txt (a zároveň na obrazovku)
"""

import glob
import re
import sys
import zipfile
from collections import Counter

MALE_TABULKY = 300        # tabulky do tolika řádků se vypíší celé (číselníky)
UKAZKA = 4                # počet ukázkových řádků ze začátku a z konce
MAX_ZNAKU = 160           # zkrácení dlouhých hodnot
ORGANY_ZKRATKY = re.compile(r"^PSP\d+$")

RE_DATUM = [
    ("DD.MM.YYYY", re.compile(r"^\d{1,2}\.\d{1,2}\.\d{4}$")),
    ("DD.MM.YYYY hh:mm", re.compile(r"^\d{1,2}\.\d{1,2}\.\d{4} \d{1,2}:\d{2}$")),
    ("YYYY-MM-DD", re.compile(r"^\d{4}-\d{2}-\d{2}$")),
    ("YYYY-MM-DD hh", re.compile(r"^\d{4}-\d{2}-\d{2} \d{1,2}$")),
    ("YYYY-MM-DD hh:mm", re.compile(r"^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}$")),
    ("YYYY-MM-DD hh:mm:ss", re.compile(r"^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}:\d{2}")),
]


def rozdel(radek):
    if "\\" not in radek:
        pole = radek.split("|")
    else:
        pole, akt, i = [], [], 0
        while i < len(radek):
            z = radek[i]
            if z == "\\" and i + 1 < len(radek):
                akt.append(radek[i + 1]); i += 2; continue
            if z == "|":
                pole.append("".join(akt)); akt = []
            else:
                akt.append(z)
            i += 1
        pole.append("".join(akt))
    if pole and pole[-1] == "":
        pole.pop()
    return pole


def radky_unl(text):
    rozpr = None
    for r in text.split("\n"):
        r = r.rstrip("\r")
        if rozpr is not None:
            r = rozpr + "\n" + r
            rozpr = None
        if (len(r) - len(r.rstrip("\\"))) % 2 == 1:
            rozpr = r[:-1]
            continue
        if r:
            yield rozdel(r)


def zkrat(h):
    h = h.replace("\n", "\\n")
    return h if len(h) <= MAX_ZNAKU else h[:MAX_ZNAKU] + "…"


def formaty_sloupcu(radky):
    """Pro každý sloupec: kolik hodnot vypadá jako datum v jakém formátu."""
    vysledek = {}
    for radek in radky[:5000]:
        for i, h in enumerate(radek):
            if not h:
                continue
            for jmeno, rx in RE_DATUM:
                if rx.match(h):
                    vysledek.setdefault(i, Counter())[jmeno] += 1
                    break
    return vysledek


def analyzuj_zip(cesta, out):
    print("=" * 78, file=out)
    print("ARCHIV %s" % cesta, file=out)
    with zipfile.ZipFile(cesta) as z:
        for info in z.infolist():
            print("  %-30s %10d B" % (info.filename, info.file_size), file=out)
        for info in z.infolist():
            if info.is_dir():
                continue
            print("\n--- %s ---" % info.filename, file=out)
            data = z.read(info.filename)
            for kod in ("cp1250", "utf-8"):
                try:
                    text = data.decode(kod)
                    break
                except UnicodeDecodeError:
                    continue
            else:
                text = data.decode("cp1250", "replace")
            print("kódování: %s, končí řádky \\r\\n: %s" % (kod, "\r\n" in text[:20000]), file=out)
            radky = list(radky_unl(text))
            pocty = Counter(len(r) for r in radky)
            print("řádků: %d, počty sloupců: %s" % (
                len(radky), ", ".join("%d×%d" % (k, v) for k, v in sorted(pocty.items()))), file=out)
            for i, f in sorted(formaty_sloupcu(radky).items()):
                print("  sloupec %d vypadá jako datum: %s" % (
                    i + 1, ", ".join("%s (%d)" % x for x in f.most_common())), file=out)
            if len(radky) <= MALE_TABULKY:
                vyber = radky
                print("celá tabulka:", file=out)
            else:
                vyber = radky[:UKAZKA] + [None] + radky[-UKAZKA:]
                print("ukázka (začátek … konec):", file=out)
            for r in vyber:
                print("   …" if r is None else "   " + " | ".join(zkrat(h) for h in r), file=out)
            # speciality: období PS v organy
            if info.filename.lower().endswith("organy.unl"):
                print("orgány volebních období (zkratka PSPn):", file=out)
                for r in radky:
                    if len(r) > 3 and ORGANY_ZKRATKY.match(r[3].strip()):
                        print("   " + " | ".join(zkrat(h) for h in r), file=out)


def main():
    soubory = sys.argv[1:] or sorted(glob.glob("*.zip"))
    if not soubory:
        print(__doc__)
        return
    with open("psp_analyza.txt", "w", encoding="utf-8") as f:
        class Oba:
            def write(self, s):
                f.write(s)
                sys.stdout.write(s)

            def flush(self):
                pass
        out = Oba()
        for s in soubory:
            try:
                analyzuj_zip(s, out)
            except (OSError, zipfile.BadZipFile) as e:
                print("CHYBA %s: %s" % (s, e), file=out)
    print("\nUloženo do psp_analyza.txt")


if __name__ == "__main__":
    main()
