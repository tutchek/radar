# -*- coding: utf-8 -*-
"""
pdftext.py – vytažení textu z PDF jen pomocí standardní knihovny Pythonu.

Určeno pro vyhledávání výrazů (analýza relevance), ne pro věrnou sazbu:
  * čte objekty přímo i z objektových proudů (PDF 1.5+), filtry Flate / ASCIIHex / ASCII85,
  * text dekóduje přes ToUnicode (bfchar / bfrange), u jednoduchých písem přes
    WinAnsi / MacRoman a tabulku Differences (vč. českých znaků),
  * prochází stránky v pořadí stromu stránek včetně formulářových XObjektů,
  * mezery a konce řádků odhaduje podle posunů textu.
Neumí: naskenované PDF (obrázek bez textové vrstvy), šifrované PDF.

    from pdftext import nacti_pdf, PdfChyba
    text = nacti_pdf(open("soubor.pdf", "rb").read())
"""

import base64
import re
import zlib

__version__ = "1.0"
__all__ = ["nacti_pdf", "PdfChyba"]


class PdfChyba(Exception):
    pass


# ---------------------------------------------------------------- základní typy

class Nazev(str):
    """/Jméno v PDF."""


class Operator(str):
    """Operátor obsahového proudu (Tj, TJ, BT …) nebo klíčové slovo (obj, R, true …)."""


class Odkaz(tuple):
    """Nepřímý odkaz 12 0 R."""


class Proud:
    def __init__(self, slovnik, data):
        self.slovnik, self.data = slovnik, data


_BILE = b" \t\r\n\x0c\x00"
_ODDELOVACE = b"()<>[]{}/%"
_RE_CISLO = re.compile(rb"[+-]?(\d+\.?\d*|\.\d+)$")


class _Lexer:
    """Tokenizér syntaxe PDF (objekty i obsahové proudy)."""

    def __init__(self, data, pozice=0):
        self.d, self.i = data, pozice

    def _bile(self):
        d, n = self.d, len(self.d)
        while self.i < n:
            c = d[self.i]
            if c in _BILE:
                self.i += 1
            elif c == 0x25:                                   # % komentář
                while self.i < n and d[self.i] not in b"\r\n":
                    self.i += 1
            else:
                break

    def token(self):
        """Další token: int/float, Nazev, bytes (řetězec), Operator, nebo '[', ']', '<<', '>>'; None = konec."""
        self._bile()
        d, n = self.d, len(self.d)
        if self.i >= n:
            return None
        c = d[self.i]
        if c == 0x2F:                                         # /Nazev
            j = self.i + 1
            while j < n and d[j] not in _BILE and d[j] not in _ODDELOVACE:
                j += 1
            surove = d[self.i + 1:j]
            self.i = j
            return Nazev(re.sub(rb"#([0-9A-Fa-f]{2})", lambda m: bytes([int(m.group(1), 16)]),
                                surove).decode("latin-1"))
        if c == 0x28:                                         # (literál)
            return self._literal()
        if c == 0x3C:
            if d[self.i + 1:self.i + 2] == b"<":
                self.i += 2
                return "<<"
            j = d.find(b">", self.i)
            j = n if j < 0 else j
            hexa = re.sub(rb"[^0-9A-Fa-f]", b"", d[self.i + 1:j])
            self.i = j + 1
            if len(hexa) % 2:
                hexa += b"0"
            return bytes.fromhex(hexa.decode("ascii"))
        if c == 0x3E:
            self.i += 2 if d[self.i + 1:self.i + 2] == b">" else 1
            return ">>"
        if c in b"[]{}":
            self.i += 1
            return chr(c)
        j = self.i
        while j < n and d[j] not in _BILE and d[j] not in _ODDELOVACE:
            j += 1
        if j == self.i:                                       # osamocený oddělovač
            self.i += 1
            return self.token()
        slovo = d[self.i:j]
        self.i = j
        if _RE_CISLO.match(slovo):
            try:
                return int(slovo)
            except ValueError:
                return float(slovo)
        return Operator(slovo.decode("latin-1"))

    def _literal(self):
        d, n = self.d, len(self.d)
        self.i += 1
        vystup, hloubka = bytearray(), 1
        while self.i < n:
            c = d[self.i]
            self.i += 1
            if c == 0x5C:                                     # \escape
                if self.i >= n:
                    break
                e = d[self.i]
                self.i += 1
                if e in b"nrtbf":
                    vystup += {ord("n"): b"\n", ord("r"): b"\r", ord("t"): b"\t",
                               ord("b"): b"\b", ord("f"): b"\f"}[e]
                elif 0x30 <= e <= 0x37:
                    okt = bytes([e])
                    while len(okt) < 3 and self.i < n and 0x30 <= d[self.i] <= 0x37:
                        okt += bytes([d[self.i]])
                        self.i += 1
                    vystup.append(int(okt, 8) & 0xFF)
                elif e in b"\r\n":                            # pokračování řádku
                    if e == 0x0D and self.i < n and d[self.i] == 0x0A:
                        self.i += 1
                else:
                    vystup.append(e)
            elif c == 0x28:
                hloubka += 1
                vystup.append(c)
            elif c == 0x29:
                hloubka -= 1
                if hloubka == 0:
                    break
                vystup.append(c)
            else:
                vystup.append(c)
        return bytes(vystup)

    def objekt(self, tok=None):
        """Složí jeden objekt (slovník, pole, odkaz …) z tokenů."""
        tok = self.token() if tok is None else tok
        if tok == "<<":
            slovnik = {}
            while True:
                k = self.token()
                if k is None or k == ">>":
                    return slovnik
                if isinstance(k, Nazev):
                    slovnik[str(k)] = self.objekt()
        if tok == "[":
            pole = []
            while True:
                t = self.token()
                if t is None or t == "]":
                    return pole
                pole.append(self.objekt(t))
        if isinstance(tok, int) and not isinstance(tok, bool):
            ulozeno = self.i
            t2 = self.token()
            if isinstance(t2, int):
                t3 = self.token()
                if t3 == "R":
                    return Odkaz((tok, t2))
            self.i = ulozeno
            return tok
        if tok == "true":
            return True
        if tok == "false":
            return False
        if tok == "null":
            return None
        return tok


# ---------------------------------------------------------------- dokument

def _dekoduj_filtry(slovnik, data, dokument):
    filtry = dokument.r(slovnik.get("Filter"))
    if filtry is None:
        return data
    if not isinstance(filtry, list):
        filtry = [filtry]
    for f in filtry:
        f = str(dokument.r(f))
        if f in ("FlateDecode", "Fl"):
            try:
                data = zlib.decompress(data)
            except zlib.error:
                o = zlib.decompressobj()
                try:
                    data = o.decompress(data)
                except zlib.error:
                    return b""
            parametry = dokument.r(slovnik.get("DecodeParms")) or {}
            if isinstance(parametry, list):
                parametry = next((dokument.r(p) for p in parametry if p), {}) or {}
            prediktor = dokument.r(parametry.get("Predictor", 1)) if isinstance(parametry, dict) else 1
            if prediktor and prediktor >= 10:
                data = _png_prediktor(data, dokument.r(parametry.get("Columns", 1)) or 1,
                                      dokument.r(parametry.get("Colors", 1)) or 1,
                                      dokument.r(parametry.get("BitsPerComponent", 8)) or 8)
        elif f in ("ASCIIHexDecode", "AHx"):
            hexa = re.sub(rb"[^0-9A-Fa-f]", b"", data.split(b">")[0])
            data = bytes.fromhex((hexa + b"0" * (len(hexa) % 2)).decode("ascii"))
        elif f in ("ASCII85Decode", "A85"):
            telo = data.strip()
            if telo.startswith(b"<~"):
                telo = telo[2:]
            telo = telo.split(b"~>")[0]
            try:
                data = base64.a85decode(re.sub(rb"\s", b"", telo))
            except ValueError:
                return b""
        else:                                  # obrázkové a jiné filtry – text v nich není
            return b""
    return data


def _png_prediktor(data, sloupcu, barev, bitu):
    sirka = max(1, sloupcu * barev * bitu // 8)
    bpp = max(1, barev * bitu // 8)
    vystup, predchozi = bytearray(), bytearray(sirka)
    for i in range(0, len(data), sirka + 1):
        typ, radek = data[i], bytearray(data[i + 1:i + 1 + sirka])
        for j in range(len(radek)):
            a = radek[j - bpp] if j >= bpp else 0
            b = predchozi[j] if j < len(predchozi) else 0
            c = predchozi[j - bpp] if j >= bpp and j - bpp < len(predchozi) else 0
            if typ == 1:
                radek[j] = (radek[j] + a) & 0xFF
            elif typ == 2:
                radek[j] = (radek[j] + b) & 0xFF
            elif typ == 3:
                radek[j] = (radek[j] + (a + b) // 2) & 0xFF
            elif typ == 4:
                p = a + b - c
                pa, pb, pc = abs(p - a), abs(p - b), abs(p - c)
                radek[j] = (radek[j] + (a if pa <= pb and pa <= pc else b if pb <= pc else c)) & 0xFF
        vystup += radek
        predchozi = radek
    return bytes(vystup)


class _Dokument:
    def __init__(self, data):
        if not data.lstrip()[:5].startswith(b"%PDF"):
            raise PdfChyba("Soubor není PDF.")
        self.data = data
        self.objekty = {}
        self.trailer = {}
        self._nacti_objekty()

    def _nacti_objekty(self):
        d = self.data
        konec_proudu = 0
        for m in re.finditer(rb"(?<![0-9])(\d+)\s+(\d+)\s+obj\b", d):
            if m.start() < konec_proudu:                  # shoda uvnitř binárních dat proudu
                continue
            cislo = int(m.group(1))
            lex = _Lexer(d, m.end())
            try:
                hodnota = lex.objekt()
            except (IndexError, ValueError):
                continue
            if isinstance(hodnota, dict):
                lex._bile()
                if d.startswith(b"stream", lex.i):
                    zacatek = lex.i + 6
                    if d[zacatek:zacatek + 2] == b"\r\n":
                        zacatek += 2
                    elif d[zacatek:zacatek + 1] in (b"\n", b"\r"):
                        zacatek += 1
                    delka = hodnota.get("Length")
                    konec = zacatek + delka if isinstance(delka, int) else -1
                    if konec < 0 or d[konec:konec + 30].find(b"endstream") < 0:
                        konec = d.find(b"endstream", zacatek)
                        konec = len(d) if konec < 0 else konec
                    hodnota = Proud(hodnota, d[zacatek:konec])
                    konec_proudu = konec
            self.objekty[cislo] = hodnota                 # pozdější definice (aktualizace) vyhrává
            if isinstance(hodnota, Proud) and hodnota.slovnik.get("Type") == "XRef":
                self.trailer.update(hodnota.slovnik)
        for m in re.finditer(rb"trailer\s*<<", d):
            t = _Lexer(d, m.end() - 2).objekt()
            if isinstance(t, dict):
                self.trailer.update(t)
        # objekty uložené v objektových proudech
        for cislo, o in list(self.objekty.items()):
            if isinstance(o, Proud) and o.slovnik.get("Type") == "ObjStm":
                try:
                    obsah = _dekoduj_filtry(o.slovnik, o.data, self)
                    n, prvni = int(self.r(o.slovnik["N"])), int(self.r(o.slovnik["First"]))
                    lex = _Lexer(obsah)
                    hlavicka = [lex.token() for _ in range(2 * n)]
                    for k in range(n):
                        c, posun = hlavicka[2 * k], hlavicka[2 * k + 1]
                        if c not in self.objekty:
                            self.objekty[c] = _Lexer(obsah, prvni + posun).objekt()
                except (KeyError, TypeError, ValueError, IndexError):
                    continue

    def r(self, x, hloubka=0):
        """Rozřeší nepřímý odkaz."""
        while isinstance(x, Odkaz) and hloubka < 20:
            x = self.objekty.get(x[0])
            hloubka += 1
        return x

    def proud(self, x):
        x = self.r(x)
        if isinstance(x, Proud):
            return _dekoduj_filtry(x.slovnik, x.data, self)
        if isinstance(x, list):                    # obsah stránky může být pole proudů
            return b"\n".join(self.proud(p) for p in x)
        return b""

    def stranky(self):
        vysledek = []

        def projdi(uzel, zdedene, hloubka=0):
            uzel = self.r(uzel)
            if isinstance(uzel, Proud):
                uzel = uzel.slovnik
            if not isinstance(uzel, dict) or hloubka > 50:
                return
            zdedene = dict(zdedene)
            if "Resources" in uzel:
                zdedene["Resources"] = uzel["Resources"]
            if uzel.get("Type") == "Pages" or "Kids" in uzel:
                for dite in self.r(uzel.get("Kids")) or []:
                    projdi(dite, zdedene, hloubka + 1)
            else:
                vysledek.append((uzel, self.r(zdedene.get("Resources")) or {}))

        koren = self.r(self.trailer.get("Root"))
        if isinstance(koren, dict) and koren.get("Pages") is not None:
            projdi(koren["Pages"], {})
        if not vysledek:                           # záložní cesta: všechny stránky podle čísla objektu
            for c in sorted(self.objekty):
                o = self.objekty[c]
                if isinstance(o, dict) and o.get("Type") == "Page":
                    vysledek.append((o, self.r(o.get("Resources")) or {}))
        return vysledek


# ---------------------------------------------------------------- písma

_GLYFY = {
    "space": " ", "exclam": "!", "quotedbl": '"', "numbersign": "#", "dollar": "$", "percent": "%",
    "ampersand": "&", "quotesingle": "'", "quoteright": "’", "quoteleft": "‘", "parenleft": "(",
    "parenright": ")", "asterisk": "*", "plus": "+", "comma": ",", "hyphen": "-", "period": ".",
    "slash": "/", "zero": "0", "one": "1", "two": "2", "three": "3", "four": "4", "five": "5",
    "six": "6", "seven": "7", "eight": "8", "nine": "9", "colon": ":", "semicolon": ";",
    "less": "<", "equal": "=", "greater": ">", "question": "?", "at": "@", "bracketleft": "[",
    "backslash": "\\", "bracketright": "]", "underscore": "_", "braceleft": "{", "bar": "|",
    "braceright": "}", "section": "§", "endash": "–", "emdash": "—", "quotedblleft": "“",
    "quotedblright": "”", "quotedblbase": "„", "quotesinglbase": "‚", "bullet": "•",
    "ellipsis": "…", "degree": "°", "nbspace": " ", "uni00A0": " ",
    "aacute": "á", "Aacute": "Á", "eacute": "é", "Eacute": "É", "iacute": "í", "Iacute": "Í",
    "oacute": "ó", "Oacute": "Ó", "uacute": "ú", "Uacute": "Ú", "yacute": "ý", "Yacute": "Ý",
    "ecaron": "ě", "Ecaron": "Ě", "scaron": "š", "Scaron": "Š", "ccaron": "č", "Ccaron": "Č",
    "rcaron": "ř", "Rcaron": "Ř", "zcaron": "ž", "Zcaron": "Ž", "dcaron": "ď", "Dcaron": "Ď",
    "tcaron": "ť", "Tcaron": "Ť", "ncaron": "ň", "Ncaron": "Ň", "uring": "ů", "Uring": "Ů",
    "adieresis": "ä", "odieresis": "ö", "udieresis": "ü", "Adieresis": "Ä", "Odieresis": "Ö",
    "Udieresis": "Ü", "germandbls": "ß", "lcaron": "ľ", "Lcaron": "Ľ", "lacute": "ĺ", "Lacute": "Ĺ",
    "racute": "ŕ", "ocircumflex": "ô", "fi": "fi", "fl": "fl", "ff": "ff", "ffi": "ffi",
}


def _glyf(jmeno):
    if jmeno in _GLYFY:
        return _GLYFY[jmeno]
    if len(jmeno) == 1:
        return jmeno
    m = re.match(r"uni([0-9A-Fa-f]{4})", jmeno) or re.match(r"u([0-9A-Fa-f]{4,6})$", jmeno)
    if m:
        try:
            return chr(int(m.group(1), 16))
        except ValueError:
            return ""
    zaklad = jmeno.split(".")[0].split("_")[0]
    return _GLYFY.get(zaklad, zaklad if len(zaklad) == 1 else "")


def _utf16(b):
    try:
        return b.decode("utf-16-be")
    except UnicodeDecodeError:
        return ""


def _cti_cmap(data):
    """ToUnicode CMap → ({kód bytes: text}, [délky kódů])."""
    mapa, delky = {}, set()
    for blok in re.findall(rb"begincodespacerange(.*?)endcodespacerange", data, re.S):
        for a in re.findall(rb"<([0-9A-Fa-f]+)>\s*<[0-9A-Fa-f]+>", blok):
            delky.add(len(a) // 2)
    for blok in re.findall(rb"beginbfchar(.*?)endbfchar", data, re.S):
        for a, b in re.findall(rb"<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>", blok):
            mapa[bytes.fromhex(a.decode())] = _utf16(bytes.fromhex(b.decode()))
            delky.add(len(a) // 2)
    for blok in re.findall(rb"beginbfrange(.*?)endbfrange", data, re.S):
        for a, b, cil in re.findall(rb"<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])",
                                    blok):
            n = len(a) // 2
            od, do = int(a, 16), int(b, 16)
            delky.add(n)
            if do - od > 65535:
                continue
            if cil.startswith(b"["):
                cile = re.findall(rb"<([0-9A-Fa-f]*)>", cil)
                for k, c in enumerate(cile[:do - od + 1]):
                    mapa[(od + k).to_bytes(n, "big")] = _utf16(bytes.fromhex(c.decode()))
            else:
                zaklad = bytes.fromhex(cil[1:-1].decode())
                if not zaklad:
                    continue
                hodnota = int.from_bytes(zaklad, "big")
                for k in range(do - od + 1):
                    mapa[(od + k).to_bytes(n, "big")] = _utf16(
                        (hodnota + k).to_bytes(len(zaklad), "big"))
    return mapa, sorted(delky) or [1]


class _Pismo:
    def __init__(self, slovnik, dok):
        slovnik = dok.r(slovnik) or {}
        self.mapa, self.delky = {}, [1]
        self.slozene = slovnik.get("Subtype") == "Type0"
        tu = slovnik.get("ToUnicode")
        if tu is not None:
            self.mapa, self.delky = _cti_cmap(dok.proud(tu))
        elif self.slozene:
            self.delky = [2]
        self.kodovani = None
        if not self.slozene:
            self.delky = [1]
            enc = dok.r(slovnik.get("Encoding"))
            zakladni = enc if isinstance(enc, str) else (enc or {}).get("BaseEncoding") \
                if isinstance(enc, dict) else None
            self.kodovani = "mac_roman" if zakladni == "MacRomanEncoding" else "cp1252"
            if isinstance(enc, dict):
                kod = 0
                for x in dok.r(enc.get("Differences")) or []:
                    x = dok.r(x)
                    if isinstance(x, int):
                        kod = x
                    elif isinstance(x, str):
                        bajt = bytes([kod & 0xFF])
                        znak = _glyf(str(x))
                        if bajt not in self.mapa and znak:     # neznámý glyf → základní kódování
                            self.mapa[bajt] = znak
                        kod += 1

    def text(self, b):
        if not b:
            return ""
        vystup, i, n = [], 0, len(b)
        while i < n:
            for delka in self.delky:
                kus = b[i:i + delka]
                if kus in self.mapa:
                    vystup.append(self.mapa[kus])
                    i += delka
                    break
            else:
                delka = self.delky[0]
                kus = b[i:i + delka]
                if self.kodovani:
                    vystup.append(kus.decode(self.kodovani, "replace"))
                elif not self.mapa and delka == 2:     # bez ToUnicode – zkusit UTF-16
                    znak = _utf16(kus)
                    vystup.append(znak if znak.isprintable() else "")
                i += delka
        return "".join(vystup)


# ---------------------------------------------------------------- obsah stránky

def _text_proudu(obsah, zdroje, dok, vystup, hloubka=0, cache_pisem=None):
    if hloubka > 8:
        return
    cache_pisem = {} if cache_pisem is None else cache_pisem
    zdroje = dok.r(zdroje) or {}
    pisma = dok.r(zdroje.get("Font")) or {}
    xobjekty = dok.r(zdroje.get("XObject")) or {}
    pismo = None
    lex = _Lexer(obsah)
    operandy = []
    posledni_y, posledni_x = None, None

    def novy_radek():
        if vystup and not vystup[-1].endswith("\n"):
            vystup.append("\n")

    def mezera():
        if vystup and not vystup[-1].endswith((" ", "\n")):
            vystup.append(" ")

    while True:
        try:
            tok = lex.token()
        except (IndexError, ValueError):
            break
        if tok is None:
            break
        if not isinstance(tok, Operator):
            if tok in ("[", "<<"):
                operandy.append(lex.objekt(tok))
            else:
                operandy.append(tok)
            continue
        op = str(tok)
        if op == "BI":                                   # vložený obrázek – přeskočit data
            m = re.compile(rb"\sID\s").search(obsah, lex.i)
            k = re.compile(rb"\sEI(\s|$)").search(obsah, m.end() if m else lex.i)
            lex.i = k.end() if k else len(obsah)
        elif op == "Tf" and len(operandy) >= 2:
            jmeno = str(operandy[-2])
            klic = (id(pisma), jmeno)
            if klic not in cache_pisem:
                cache_pisem[klic] = _Pismo(pisma.get(jmeno), dok) if jmeno in pisma else None
            pismo = cache_pisem[klic]
        elif op in ("Tj", "'", '"') and operandy:
            if op != "Tj":
                novy_radek()
            if isinstance(operandy[-1], bytes) and pismo is not None:
                vystup.append(pismo.text(operandy[-1]))
        elif op == "TJ" and operandy and isinstance(operandy[-1], list):
            for x in operandy[-1]:
                if isinstance(x, bytes) and pismo is not None:
                    vystup.append(pismo.text(x))
                elif isinstance(x, (int, float)) and x < -180:
                    mezera()
        elif op in ("Td", "TD") and len(operandy) >= 2:
            tx, ty = operandy[-2], operandy[-1]
            if isinstance(ty, (int, float)) and abs(ty) > 0.5:
                novy_radek()
            elif isinstance(tx, (int, float)) and tx > 0.5:
                mezera()
        elif op == "Tm" and len(operandy) >= 6:
            x, y = operandy[-2], operandy[-1]
            if isinstance(y, (int, float)):
                if posledni_y is not None and abs(y - posledni_y) > 0.5:
                    novy_radek()
                elif posledni_x is not None and isinstance(x, (int, float)) and x > posledni_x:
                    mezera()
                posledni_y, posledni_x = y, x
        elif op == "T*":
            novy_radek()
        elif op == "ET":
            mezera()
        elif op == "Do" and operandy:
            xo = dok.r(xobjekty.get(str(operandy[-1])))
            if isinstance(xo, Proud) and xo.slovnik.get("Subtype") == "Form":
                _text_proudu(_dekoduj_filtry(xo.slovnik, xo.data, dok),
                             xo.slovnik.get("Resources") or zdroje, dok, vystup, hloubka + 1, cache_pisem)
        operandy = []


def nacti_pdf(data):
    """Text PDF souboru (bytes). Stránky oddělí prázdným řádkem."""
    dok = _Dokument(data)
    if dok.trailer.get("Encrypt") is not None:
        raise PdfChyba("PDF je zabezpečené (šifrované) – text z něj číst neumím.")
    stranky = []
    cache_pisem = {}
    for stranka, zdroje in dok.stranky():
        vystup = []
        _text_proudu(dok.proud(stranka.get("Contents")), zdroje, dok, vystup, cache_pisem=cache_pisem)
        stranky.append("".join(vystup))
    text = "\n\n".join(stranky)
    text = re.sub(r"[ \t]+\n", "\n", text.replace("\x00", ""))
    if not text.strip():
        raise PdfChyba("PDF neobsahuje textovou vrstvu (nejspíš je naskenované) – nelze v něm hledat.")
    return text


if __name__ == "__main__":
    import sys
    try:
        sys.stdout.reconfigure(encoding="utf-8")
    except Exception:  # noqa: BLE001
        pass
    with open(sys.argv[1], "rb") as f:
        print(nacti_pdf(f.read()))
