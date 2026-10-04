# -*- coding: utf-8 -*-
"""
relevance.py – analýza relevance dokumentů (Word .docx, starý .doc a PDF) pro útvary.

Vychází z relevance_oddeleni.py: v textu dokumentu se hledají regulární výrazy
ze sdíleného seznamu v Excelu a pro každý útvar se spočítá skóre

    skóre útvaru = součet přes výrazy (počet výskytů × váha výrazu pro útvar)

Seznam výrazů (výchozí relevance_pravidla.xlsx ve složce s programem, první list; při prvním
použití se založí kopií vzoru relevance_pravidla_vzor.xlsx, který se při aktualizaci přepisuje):

    | Příklad             | Regulární výraz        | 3101 | 3102 | 3103 | Poznámka        |
    |---------------------|------------------------|------|------|------|-----------------|
    | daň z přidané hodn. | dan\\w* z přidané hodnot | 1    | 0    | 0    | DPH             |
    | DPH                 | \\bDPH\\b                | 1    | 0    | 0    |                 |

  * Příklad – jen pro lidi (k hledání se nepoužívá),
  * Regulární výraz – podle něj se hledá (bez ohledu na velikost písmen),
  * sloupce útvarů – 0 / 1 (případně i jiná váha, prázdné = 0),
  * Poznámka – zobrazí se u nálezu jako vysvětlení.
  Řádek, jehož výraz začíná #, se přeskočí. Soubor se načítá
  při každé analýze znovu (stačí ho uložit v Excelu a analýzu přegenerovat).

Použití z příkazové řádky:
    py relevance.py dokument.docx [--pravidla cesta.xlsx]

Jen standardní knihovna Pythonu.
"""

import os
import re
import sys
import threading
import unicodedata
import zipfile
import xml.etree.ElementTree as ET

__version__ = "1.3"
__all__ = ["Relevance", "RelevanceChyba", "nacti_docx", "nacti_doc", "nacti_dokument",
           "VYCHOZI_PRAVIDLA", "VZOR_PRAVIDEL"]

VYCHOZI_PRAVIDLA = os.path.join(os.path.dirname(os.path.abspath(__file__)), "relevance_pravidla.xlsx")
# vzor dodávaný s programem – při aktualizaci se přepisuje, proto se do něj nepíše;
# skutečný seznam (relevance_pravidla.xlsx) se z něj vytvoří kopií, pokud ještě neexistuje
VZOR_PRAVIDEL = os.path.join(os.path.dirname(os.path.abspath(__file__)), "relevance_pravidla_vzor.xlsx")

POCET_UKAZEK = 3        # kolik ukázek kontextu u každého výrazu
SIRKA_KONTEXTU = 60     # kolik znaků kolem nálezu ukázat
SLOVNI_VAHY = {"ano": 1, "x": 1, "vysoká": 3, "vysoka": 3, "střední": 2, "stredni": 2,
               "nízká": 1, "nizka": 1, "ne": 0}

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
S = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"
REL = "{http://schemas.openxmlformats.org/officeDocument/2006/relationships}"
PREL = "{http://schemas.openxmlformats.org/package/2006/relationships}"
MC_FALLBACK = "{http://schemas.openxmlformats.org/markup-compatibility/2006}Fallback"


class RelevanceChyba(Exception):
    pass


def _bez_diakritiky(t):
    return "".join(c for c in unicodedata.normalize("NFD", t or "") if not unicodedata.combining(c)).lower()


# ================================================================ Word

def _odstavec(p, vystup):
    kusy = []

    def projdi(el):
        for ch in el:
            if ch.tag == MC_FALLBACK:          # záložní kopie textového pole → duplicita
                continue
            if ch.tag == W + "p":
                _odstavec(ch, vystup)
            elif ch.tag == W + "t":            # w:delText (smazaný text v revizích) se ignoruje
                kusy.append(ch.text or "")
            elif ch.tag == W + "tab":
                kusy.append("\t")
            elif ch.tag in (W + "br", W + "cr"):
                kusy.append("\n")
            else:
                projdi(ch)

    projdi(p)
    text = "".join(kusy)
    if text.strip():
        vystup.append(text)


def _projdi_telo(el, vystup):
    for ch in el:
        if ch.tag == MC_FALLBACK:
            continue
        if ch.tag == W + "p":
            _odstavec(ch, vystup)
        else:
            _projdi_telo(ch, vystup)


def nacti_docx(zdroj):
    """Text dokumentu .docx (cesta nebo soubor/BytesIO) vč. tabulek, záhlaví, zápatí,
    poznámek pod čarou a komentářů."""
    odstavce = []
    try:
        with zipfile.ZipFile(zdroj) as z:
            jmena = z.namelist()
            casti = ["word/document.xml"]
            casti += sorted(n for n in jmena if re.match(r"word/(header|footer)\d*\.xml$", n))
            casti += ["word/footnotes.xml", "word/endnotes.xml", "word/comments.xml"]
            for n in casti:
                if n in jmena:
                    _projdi_telo(ET.fromstring(z.read(n)), odstavce)
    except zipfile.BadZipFile:
        raise RelevanceChyba("Soubor není ve formátu .docx (starý .doc nebo PDF analyzovat neumím).")
    return "\n".join(odstavce)


# ================================================================ starý Word (.doc)
#
# Soubor .doc (Word 97–2003) je složený soubor OLE2 (Compound File Binary). Text se
# skládá z „kusů“ podle tabulky kusů (CLX / PlcPcd) uložené v proudu 0Table nebo 1Table;
# každý kus je buď UTF-16LE, nebo jednobajtový (cp1252).

import struct

OLE_PODPIS = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"
_KONEC_RETEZCE = 0xFFFFFFFE


class _Ole:
    """Minimální čtečka složeného souboru OLE2 – jen čtení proudů podle názvu."""

    def __init__(self, data):
        if data[:8] != OLE_PODPIS:
            raise RelevanceChyba("Soubor není ve formátu Word 97–2003 (.doc).")
        self.data = data
        self.sektor = 1 << struct.unpack_from("<H", data, 0x1E)[0]
        self.mini_sektor = 1 << struct.unpack_from("<H", data, 0x20)[0]
        pocet_fat, prvni_dir = struct.unpack_from("<II", data, 0x2C)
        self.mini_mez = struct.unpack_from("<I", data, 0x38)[0]
        prvni_minifat, pocet_minifat, prvni_difat, pocet_difat = struct.unpack_from("<IIII", data, 0x3C)
        # DIFAT → seznam sektorů FAT
        fat_sektory = [x for x in struct.unpack_from("<109I", data, 0x4C) if x < _KONEC_RETEZCE]
        dalsi, na_sektor = prvni_difat, self.sektor // 4 - 1
        for _ in range(pocet_difat):
            if dalsi >= _KONEC_RETEZCE:
                break
            hodnoty = struct.unpack_from("<%dI" % (na_sektor + 1), self._sektor(dalsi))
            fat_sektory += [x for x in hodnoty[:na_sektor] if x < _KONEC_RETEZCE]
            dalsi = hodnoty[na_sektor]
        fat = b"".join(self._sektor(x) for x in fat_sektory[:pocet_fat])
        self.fat = struct.unpack("<%dI" % (len(fat) // 4), fat)
        minifat = self._retez(prvni_minifat, self.fat) if pocet_minifat else b""
        self.minifat = struct.unpack("<%dI" % (len(minifat) // 4), minifat)
        # adresář
        adresar = self._retez(prvni_dir, self.fat)
        self.polozky = {}
        self.mini_proud = b""
        for i in range(len(adresar) // 128):
            z = adresar[i * 128:(i + 1) * 128]
            delka_jmena = struct.unpack_from("<H", z, 0x40)[0]
            jmeno = z[:max(0, delka_jmena - 2)].decode("utf-16-le", "replace")
            typ = z[0x42]
            start, velikost = struct.unpack_from("<II", z, 0x74)
            if typ == 5:                      # kořen – nese mini proud
                self.mini_proud = self._retez(start, self.fat)[:velikost]
            elif typ == 2:
                self.polozky[jmeno] = (start, velikost)

    def _sektor(self, i):
        od = (i + 1) * self.sektor
        return self.data[od:od + self.sektor]

    def _retez(self, start, fat, mini=False):
        kusy, i, videno = [], start, set()
        while i < _KONEC_RETEZCE and i < len(fat) and i not in videno:
            videno.add(i)
            if mini:
                od = i * self.mini_sektor
                kusy.append(self.mini_proud[od:od + self.mini_sektor])
            else:
                kusy.append(self._sektor(i))
            i = fat[i]
        return b"".join(kusy)

    def proud(self, jmeno):
        if jmeno not in self.polozky:
            raise RelevanceChyba("V souboru .doc chybí část „%s“." % jmeno)
        start, velikost = self.polozky[jmeno]
        if velikost < self.mini_mez:
            return self._retez(start, self.minifat, mini=True)[:velikost]
        return self._retez(start, self.fat)[:velikost]


def _vycisti_text_doc(t):
    """Odstraní řídicí znaky Wordu; z polí (0x13 kód 0x14 výsledek 0x15) nechá jen výsledek."""
    vysledek, hloubka_kodu = [], []
    for ch in t:
        o = ord(ch)
        if o == 0x13:                  # začátek pole – následuje kód pole
            hloubka_kodu.append(True)
        elif o == 0x14:                # oddělovač – dál je výsledek pole
            if hloubka_kodu:
                hloubka_kodu[-1] = False
        elif o == 0x15:                # konec pole
            if hloubka_kodu:
                hloubka_kodu.pop()
        elif any(hloubka_kodu):
            continue
        elif ch in "\r\x0b\x0c":
            vysledek.append("\n")
        elif o == 0x07:                # konec buňky / řádku tabulky
            vysledek.append("\t")
        elif o == 0x1e:                # nedělitelný spojovník
            vysledek.append("-")
        elif o == 0x1f:                # volitelný spojovník
            continue
        elif o < 0x20 and ch not in "\t\n":
            continue
        else:
            vysledek.append(ch)
    return "".join(vysledek)


def nacti_doc(data):
    """Text starého dokumentu Word 97–2003 (.doc) z bytes."""
    ole = _Ole(data)
    wd = ole.proud("WordDocument")
    if len(wd) < 0x1AA or struct.unpack_from("<H", wd, 0)[0] != 0xA5EC:
        raise RelevanceChyba("Soubor .doc má neznámý formát (podporován je Word 97 a novější).")
    priznaky = struct.unpack_from("<H", wd, 0x0A)[0]
    if priznaky & 0x0100:
        raise RelevanceChyba("Dokument .doc je zaheslovaný – nelze ho prohledat.")
    tabulka = ole.proud("1Table" if priznaky & 0x0200 else "0Table")
    fc_clx, lcb_clx = struct.unpack_from("<II", wd, 0x01A2)
    clx = tabulka[fc_clx:fc_clx + lcb_clx]
    i = 0
    while i < len(clx) and clx[i] == 0x01:            # Prc – přeskočit
        i += 3 + struct.unpack_from("<H", clx, i + 1)[0]
    if i >= len(clx) or clx[i] != 0x02:
        raise RelevanceChyba("V souboru .doc se nepodařilo najít tabulku textu.")
    lcb = struct.unpack_from("<I", clx, i + 1)[0]
    plc = clx[i + 5:i + 5 + lcb]
    n = (lcb - 4) // 12
    cp = struct.unpack_from("<%dI" % (n + 1), plc, 0)
    kusy = []
    for k in range(n):
        fc = struct.unpack_from("<I", plc, 4 * (n + 1) + 8 * k + 2)[0]
        znaku = cp[k + 1] - cp[k]
        if fc & 0x40000000:                          # jednobajtový text (cp1252)
            od = (fc & 0x3FFFFFFF) // 2
            kusy.append(wd[od:od + znaku].decode("cp1252", "replace"))
        else:
            kusy.append(wd[fc:fc + 2 * znaku].decode("utf-16-le", "replace"))
    return _vycisti_text_doc("".join(kusy))


def nacti_dokument(zdroj):
    """Text dokumentu – Word .docx/.docm, starý .doc nebo PDF (pozná se podle obsahu, ne přípony).
    zdroj: cesta, bytes nebo soubor."""
    if isinstance(zdroj, (bytes, bytearray)):
        data = bytes(zdroj)
    elif hasattr(zdroj, "read"):
        data = zdroj.read()
    else:
        with open(zdroj, "rb") as f:
            data = f.read()
    if data[:2] == b"PK":
        import io
        return nacti_docx(io.BytesIO(data))
    if data[:8] == OLE_PODPIS:
        return nacti_doc(data)
    if b"%PDF" in data[:1024]:
        try:
            import pdftext
        except ImportError:
            raise RelevanceChyba("Pro PDF je potřeba soubor pdftext.py ve složce s programem.")
        try:
            return pdftext.nacti_pdf(data)
        except pdftext.PdfChyba as chyba:
            raise RelevanceChyba(str(chyba))
    if data[:5] == b"{\\rtf":
        raise RelevanceChyba("Soubor je RTF – analyzovat umím jen Word (.docx, .doc) a PDF.")
    raise RelevanceChyba("Neznámý formát souboru – analyzovat umím jen Word (.docx, .doc) a PDF.")


# ================================================================ Excel

def _index_sloupce(ref):
    i = 0
    for ch in re.match(r"[A-Z]+", ref).group(0):
        i = i * 26 + (ord(ch) - 64)
    return i - 1


def nacti_xlsx(cesta, nazev_listu=None):
    """Řádky prvního (nebo zadaného) listu jako seznamy textů."""
    with zipfile.ZipFile(cesta) as z:
        jmena = z.namelist()
        sdilene = []
        if "xl/sharedStrings.xml" in jmena:
            for si in ET.fromstring(z.read("xl/sharedStrings.xml")).iter(S + "si"):
                sdilene.append("".join(t.text or "" for t in si.iter(S + "t")))
        wb = ET.fromstring(z.read("xl/workbook.xml"))
        listy = [(s.get("name"), s.get(REL + "id")) for s in wb.iter(S + "sheet")]
        if not listy:
            raise RelevanceChyba("Sešit neobsahuje žádný list.")
        rid = next((r for n, r in listy if n == nazev_listu), listy[0][1])
        rels = ET.fromstring(z.read("xl/_rels/workbook.xml.rels"))
        cil = {r.get("Id"): r.get("Target") for r in rels.iter(PREL + "Relationship")}[rid]
        cesta_listu = cil.lstrip("/") if cil.startswith("/") else "xl/" + cil
        radky = []
        for row in ET.fromstring(z.read(cesta_listu)).iter(S + "row"):
            hodnoty, posledni = {}, -1
            for c in row.findall(S + "c"):
                ref = c.get("r")
                sl = _index_sloupce(ref) if ref else posledni + 1
                posledni = sl
                typ, v = c.get("t"), c.find(S + "v")
                if typ == "s":
                    hod = sdilene[int(v.text)] if v is not None else ""
                elif typ == "inlineStr":
                    hod = "".join(t.text or "" for t in c.iter(S + "t"))
                else:
                    hod = v.text if v is not None and v.text is not None else ""
                hodnoty[sl] = hod
            radky.append([hodnoty.get(i, "") for i in range(max(hodnoty) + 1)] if hodnoty else [])
        return radky


def _vaha(hodnota):
    h = str(hodnota).strip()
    if not h:
        return 0.0
    if h.lower() in SLOVNI_VAHY:
        return float(SLOVNI_VAHY[h.lower()])
    return float(h.replace(",", ".").replace(" ", ""))


def _cislo(x):
    return str(int(x)) if float(x).is_integer() else ("%.2f" % x).replace(".", ",")


# ================================================================ pravidla a vyhodnocení

class Relevance:
    """
    Sdílený seznam výrazů a vyhodnocení dokumentu.

        r = Relevance()                     # relevance_pravidla.xlsx vedle programu
        vysledek = r.analyzuj_docx("navrh.docx")
        for u in vysledek["utvary"]:
            print(u["utvar"], u["skore"], [d["priklad"] for d in u["duvody"]])
    """

    def __init__(self, soubor=None):
        self.soubor = soubor or os.environ.get("RADAR_RELEVANCE") or VYCHOZI_PRAVIDLA
        self._zamek = threading.Lock()
        self.utvary, self.pravidla, self.chyby = [], [], []

    def nacti(self):
        """Načte seznam výrazů ze souboru (při každé analýze znovu – soubor je sdílený
        a mohl ho mezitím kdokoli upravit)."""
        with self._zamek:
            if not os.path.exists(self.soubor):
                if os.path.abspath(self.soubor) == os.path.abspath(VYCHOZI_PRAVIDLA) and \
                        os.path.exists(VZOR_PRAVIDEL):
                    import shutil
                    try:                         # první použití: založit seznam ze vzoru
                        shutil.copyfile(VZOR_PRAVIDEL, self.soubor)
                    except OSError as chyba:
                        raise RelevanceChyba("Seznam výrazů %s neexistuje a ze vzoru %s ho nelze "
                                             "vytvořit (%s). Zkopírujte vzor pod tímto názvem ručně."
                                             % (self.soubor, VZOR_PRAVIDEL, chyba))
                else:
                    raise RelevanceChyba("Seznam výrazů %s neexistuje. Vytvořte ho podle vzoru "
                                         "relevance_pravidla_vzor.xlsx (sloupce Příklad, Regulární "
                                         "výraz, čísla útvarů, Poznámka)." % self.soubor)
            try:
                radky = nacti_xlsx(self.soubor)
            except PermissionError:
                raise RelevanceChyba("Seznam výrazů %s nelze otevřít (je právě uložený / zamčený?)."
                                     % self.soubor)
            except (zipfile.BadZipFile, KeyError, ET.ParseError) as chyba:
                raise RelevanceChyba("Seznam výrazů %s nelze přečíst: %s" % (self.soubor, chyba))
            self.utvary, self.pravidla, self.chyby = self._zpracuj(radky)
            return self

    @staticmethod
    def _zpracuj(radky):
        if not radky:
            raise RelevanceChyba("Seznam výrazů je prázdný.")
        hlavicka = [str(h).strip() for h in radky[0]]
        klice = [_bez_diakritiky(h) for h in hlavicka]
        i_priklad = next((i for i, k in enumerate(klice) if k.startswith("priklad")), None)
        i_vyraz = next((i for i, k in enumerate(klice)
                        if "vyraz" in k or k in ("regex", "vzor", "regularni vyraz")), None)
        i_pozn = next((i for i, k in enumerate(klice) if k.startswith(("poznamk", "komentar", "popis"))),
                      None)
        if i_vyraz is None:
            raise RelevanceChyba("V hlavičce seznamu chybí sloupec „Regulární výraz“.")
        utvary = [(i, h) for i, h in enumerate(hlavicka)
                  if h and i not in (i_priklad, i_vyraz, i_pozn)]
        if not utvary:
            raise RelevanceChyba("V hlavičce seznamu nejsou žádné útvary.")
        pravidla, chyby = [], []
        for cislo_radku, radek in enumerate(radky[1:], start=2):
            def bunka(i):
                return str(radek[i]).strip() if i is not None and i < len(radek) else ""
            vzor = bunka(i_vyraz)
            if not vzor or vzor.startswith("#"):
                continue
            vahy = {}
            for i, utvar in utvary:
                try:
                    v = _vaha(bunka(i))
                except ValueError:
                    chyby.append("řádek %d: neplatná hodnota „%s“ u útvaru %s" % (cislo_radku, bunka(i), utvar))
                    continue
                if v:
                    vahy[utvar] = v
            try:
                regex = re.compile(vzor, re.IGNORECASE)
            except re.error as chyba:
                chyby.append("řádek %d: neplatný regulární výraz „%s“ (%s)" % (cislo_radku, vzor, chyba))
                continue
            pravidla.append({"radek": cislo_radku, "vzor": vzor, "regex": regex, "vahy": vahy,
                             "priklad": bunka(i_priklad), "poznamka": bunka(i_pozn)})
        return [u for _i, u in utvary], pravidla, chyby

    def analyzuj_text(self, text):
        """
        {'utvary': [{'utvar','skore','vyskytu','vyrazu','duvody':[…]}] (od nejvyššího skóre),
         'nalezy': [{'radek','vzor','priklad','poznamka','pocet','ukazky','vahy'}],
         'znaku': len(text), 'chyby': […]}
        ukazky: [(před, nález, za)]
        """
        self.nacti()
        nalezy = []
        for p in self.pravidla:
            pocet, ukazky = 0, []
            for m in p["regex"].finditer(text):
                if m.start() == m.end():
                    continue
                pocet += 1
                if len(ukazky) < POCET_UKAZEK:
                    a, b = max(0, m.start() - SIRKA_KONTEXTU), min(len(text), m.end() + SIRKA_KONTEXTU)
                    ukazky.append((("…" if a else "") + re.sub(r"\s+", " ", text[a:m.start()]),
                                   re.sub(r"\s+", " ", m.group(0)),
                                   re.sub(r"\s+", " ", text[m.end():b]) + ("…" if b < len(text) else "")))
            if pocet:
                nalezy.append(dict(p, pocet=pocet, ukazky=ukazky))
        utvary = []
        for u in self.utvary:
            duvody = [n for n in nalezy if n["vahy"].get(u)]
            skore = sum(n["pocet"] * n["vahy"][u] for n in duvody)
            utvary.append({"utvar": u, "skore": skore, "skore_text": _cislo(skore),
                           "vyskytu": sum(n["pocet"] for n in duvody), "vyrazu": len(duvody),
                           "duvody": sorted(duvody, key=lambda n: -n["pocet"] * n["vahy"][u])})
        utvary.sort(key=lambda x: (-x["skore"], x["utvar"]))
        for n in nalezy:
            n.pop("regex", None)
        return {"utvary": utvary, "nalezy": nalezy, "znaku": len(text), "chyby": list(self.chyby)}

    def analyzuj_docx(self, zdroj):
        return self.analyzuj_text(nacti_docx(zdroj))

    def analyzuj_dokument(self, zdroj):
        """Word dokument .docx nebo .doc (cesta, bytes nebo soubor)."""
        return self.analyzuj_text(nacti_dokument(zdroj))


if __name__ == "__main__":
    import argparse
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:  # noqa: BLE001
        pass
    ap = argparse.ArgumentParser(description="Relevance .docx dokumentu pro útvary")
    ap.add_argument("dokument")
    ap.add_argument("--pravidla", help="seznam výrazů (.xlsx), výchozí %s" % VYCHOZI_PRAVIDLA)
    a = ap.parse_args()
    try:
        v = Relevance(a.pravidla).analyzuj_dokument(a.dokument)
    except RelevanceChyba as chyba:
        sys.exit("CHYBA: %s" % chyba)
    for ch in v["chyby"]:
        print("POZOR – " + ch)
    for u in v["utvary"]:
        print("%-10s skóre %-5s %s" % (u["utvar"], u["skore_text"], ", ".join(
            "%s (%d×)" % (d["priklad"] or d["vzor"], d["pocet"]) for d in u["duvody"])))
