# -*- coding: utf-8 -*-
"""
odok_okno.py – okno aplikace Legislativní radar (tkinter, součást Pythonu).

Zobrazí, že server běží, stav a stáří dat a tlačítka
„Otevřít v prohlížeči“, „Obnovit data“ a „Ukončit“. Zavřením okna se server ukončí.

Spouští se z odok_web.py (výchozí režim). Samostatně:
    from odok_web import OdokServer
    from odok_okno import spust_okno
    spust_okno(OdokServer(port=8765))

Tip: spuštění přes „pyw odok_web.py“ (nebo dvojklik na soubor .pyw) nezobrazí
černé okno konzole – zůstane jen toto okno.
"""

import os
import sys
import tempfile
import threading
import time
import webbrowser

import tkinter as tk
from tkinter import ttk, messagebox

INTERVAL_MS = 3000   # jak často se obnovuje stav v okně


def _stari(sekundy):
    """Lidsky čitelné stáří: 'před 5 min', 'před 2 h 10 min'."""
    if sekundy is None:
        return ""
    minuty = int(sekundy // 60)
    if minuty < 1:
        return "právě teď"
    if minuty < 60:
        return "před %d min" % minuty
    hodiny, minuty = divmod(minuty, 60)
    if hodiny < 48:
        return "před %d h %d min" % (hodiny, minuty)
    return "před %d dny" % (hodiny // 24)


def _cas(ts):
    t = time.localtime(ts)
    return "%d. %d. %d:%02d" % (t.tm_mday, t.tm_mon, t.tm_hour, t.tm_min)


def _presmeruj_vystupy():
    """Pod pythonw.exe nejsou stdout/stderr – výpisy půjdou do souboru v %TEMP%."""
    if sys.stdout is None or sys.stderr is None:
        log = open(os.path.join(tempfile.gettempdir(), "odok_web.log"), "a",
                   encoding="utf-8", buffering=1)
        if sys.stdout is None:
            sys.stdout = log
        if sys.stderr is None:
            sys.stderr = log


class OknoServeru:
    """Okno se stavem serveru. Server běží ve vlákně na pozadí."""

    def __init__(self, server):
        self.server = server
        self.root = tk.Tk()
        self.root.title("Legislativní radar")
        self.root.minsize(560, 0)
        self.root.protocol("WM_DELETE_WINDOW", self.ukoncit)
        self._obnovuje_se = False
        self._sestav()

    # ---------------- rozvržení ----------------

    def _sestav(self):
        r = self.root
        styl = ttk.Style(r)
        if "vista" in styl.theme_names():
            styl.theme_use("vista")
        styl.configure("Nadpis.TLabel", font=("Segoe UI", 13, "bold"))
        styl.configure("Tlumene.TLabel", foreground="#666666")
        styl.configure("Ok.TLabel", foreground="#1b7a2f")
        styl.configure("Pozor.TLabel", foreground="#a15c00")
        styl.configure("Chyba.TLabel", foreground="#b3261e")

        ram = ttk.Frame(r, padding=16)
        ram.pack(fill="both", expand=True)

        ttk.Label(ram, text="§ Legislativní radar", style="Nadpis.TLabel").pack(anchor="w")
        self.lbl_server = ttk.Label(ram, text="Spouštím server…")
        self.lbl_server.pack(anchor="w", pady=(4, 0))
        self.lbl_url = ttk.Label(ram, text="", foreground="#1f5fa8", cursor="hand2")
        self.lbl_url.pack(anchor="w")
        self.lbl_url.bind("<Button-1>", lambda _e: self.otevrit())

        tlacitka = ttk.Frame(ram)
        tlacitka.pack(fill="x", pady=(12, 12))
        self.btn_otevrit = ttk.Button(tlacitka, text="Otevřít v prohlížeči", command=self.otevrit)
        self.btn_otevrit.pack(side="left")
        self.btn_obnovit = ttk.Button(tlacitka, text="Obnovit data", command=self.obnovit)
        self.btn_obnovit.pack(side="left", padx=8)
        ttk.Button(tlacitka, text="Ukončit", command=self.ukoncit).pack(side="right")

        # tabulka stavu dat
        skupina = ttk.LabelFrame(ram, text="Data", padding=(10, 6))
        skupina.pack(fill="x")
        self.mrizka = ttk.Frame(skupina)
        self.mrizka.pack(fill="x")
        for i, (text, sirka) in enumerate((("Zdroj", 20), ("Stav", 26), ("Staženo", 22),
                                           ("Platnost", 14))):
            ttk.Label(self.mrizka, text=text, style="Tlumene.TLabel", width=sirka).grid(
                row=0, column=i, sticky="w")

        self.lbl_souhrn = ttk.Label(ram, text="", justify="left", wraplength=540)
        self.lbl_souhrn.pack(anchor="w", pady=(10, 0))
        self.lbl_info = ttk.Label(ram, text="", style="Tlumene.TLabel", justify="left",
                                  wraplength=540)
        self.lbl_info.pack(anchor="w", pady=(6, 0))

    def _radky_dat(self, stav):
        """Řádky tabulky: (zdroj, stav, styl, staženo, styl)."""
        platnost = stav["platnost_h"]
        radky = []

        def styl_stavu(text):
            return ("Ok.TLabel" if text == "načteno" else
                    "Chyba.TLabel" if text.startswith("chyba") else "Pozor.TLabel")

        odok = stav["odok"]
        popis = odok["stav"]
        if odok["materialu"] is not None:
            popis = "načteno – %d materiálů" % odok["materialu"]
        for i, (nazev, info) in enumerate(odok["soubory"]):
            radky.append((nazev, popis if i == 0 else "", styl_stavu(odok["stav"]), info))

        psp = stav["psp"]
        popis = psp["stav"]
        if psp["stav"] == "načteno" and psp["obdobi"]:
            popis = "načteno – %s" % psp["obdobi"]
        if psp["soubory"]:
            for i, (nazev, info) in enumerate(psp["soubory"]):
                radky.append((nazev, popis if i == 0 else "", styl_stavu(psp["stav"]), info))
        else:
            radky.append(("Sněmovna", popis, styl_stavu(psp["stav"]), None))

        esel = stav.get("esel")
        if esel:
            popis = esel["stav"]
            if esel["stav"] == "načteno" and esel["navrhu"] is not None:
                popis = "načteno – %d návrhů" % esel["navrhu"]
            if esel["soubory"]:
                for i, (nazev, info) in enumerate(esel["soubory"]):
                    radky.append((nazev, popis if i == 0 else "", styl_stavu(esel["stav"]), info))
            else:
                radky.append(("e-Legislativa", popis, styl_stavu(esel["stav"]), None))

        vysledek = []
        for nazev, popis, styl, info in radky:
            if info is None:
                stazeno, platna, styl_pl = "zatím nestaženo", "", "Tlumene.TLabel"
            else:
                stazeno = "%s (%s)" % (_cas(info["stazeno"]), _stari(info["stari_s"]))
                if info["platny"]:
                    zbyva = int((info.get("platnost_s", platnost * 3600) - info["stari_s"]) // 60)
                    platna = "ještě %d h %d min" % divmod(zbyva, 60) if zbyva >= 60 else \
                        "ještě %d min" % zbyva
                    styl_pl = "Ok.TLabel"
                else:
                    platna, styl_pl = "prošlá – obnoví se", "Pozor.TLabel"
            vysledek.append((nazev, popis, styl, stazeno, platna, styl_pl))
        return vysledek

    # ---------------- aktualizace ----------------

    def aktualizuj(self):
        try:
            stav = self.server.stav()
        except Exception as chyba:  # noqa: BLE001 – okno nesmí spadnout
            self.lbl_souhrn.configure(text="Stav nelze zjistit: %s" % chyba)
            self.root.after(INTERVAL_MS, self.aktualizuj)
            return

        if stav["bezi"]:
            self.lbl_server.configure(text="Server běží – aplikaci otevřete v prohlížeči na adrese:",
                                      style="TLabel")
            self.lbl_url.configure(text=stav["url"])
            self.btn_otevrit.state(["!disabled"])
        else:
            self.lbl_server.configure(text="Server neběží.", style="Chyba.TLabel")
            self.btn_otevrit.state(["disabled"])

        for w in self.mrizka.grid_slaves():
            if int(w.grid_info()["row"]) > 0:
                w.destroy()
        for i, (nazev, popis, styl, stazeno, platna, styl_pl) in enumerate(
                self._radky_dat(stav), start=1):
            ttk.Label(self.mrizka, text=nazev).grid(row=i, column=0, sticky="nw")
            ttk.Label(self.mrizka, text=popis, style=styl, wraplength=190).grid(
                row=i, column=1, sticky="nw")
            ttk.Label(self.mrizka, text=stazeno).grid(row=i, column=2, sticky="nw")
            ttk.Label(self.mrizka, text=platna, style=styl_pl).grid(row=i, column=3, sticky="nw")

        souhrn = []
        if stav["psp"]["tisku_s_eklep"]:
            souhrn.append("Sněmovních tisků s vazbou na eKLEP: %d" % stav["psp"]["tisku_s_eklep"])
        if stav["plpv"]:
            souhrn.append("Sestavené podklady PLPV: %s" % ", ".join(stav["plpv"]))
        souhrn.append("Dotazů z prohlížeče: %d%s" % (
            stav["dotazu"], " (poslední %s)" % _stari(time.time() - stav["posledni_dotaz"])
            if stav["posledni_dotaz"] else ""))
        if self._obnovuje_se:
            souhrn.append("Probíhá obnova dat…")
        self.lbl_souhrn.configure(text="\n".join(souhrn))
        self.lbl_info.configure(text=(
            "Data se berou z cache; když jsou starší než %g h, aplikace je sama stáhne znovu "
            "(tlačítkem „Obnovit data“ hned). Způsob stahování: %s. Cache: %s" % (
                stav["platnost_h"], ", ".join(stav["sit"]), stav["cache"])))
        self.root.after(INTERVAL_MS, self.aktualizuj)

    # ---------------- akce ----------------

    def otevrit(self):
        if self.server.bezi:
            webbrowser.open(self.server.url)

    def obnovit(self):
        if self._obnovuje_se:
            return
        self._obnovuje_se = True
        self.btn_obnovit.state(["disabled"])

        def prace():
            chyba = None
            try:
                self.server.obnov_data()
            except Exception as e:  # noqa: BLE001
                chyba = e
            self.root.after(0, lambda: self._obnova_hotova(chyba))
        threading.Thread(target=prace, daemon=True).start()

    def _obnova_hotova(self, chyba):
        self._obnovuje_se = False
        self.btn_obnovit.state(["!disabled"])
        if chyba:
            messagebox.showerror("Obnova dat", "Data se nepodařilo obnovit:\n%s" % chyba)

    def ukoncit(self):
        self.lbl_server.configure(text="Ukončuji server…")
        self.root.update_idletasks()
        try:
            self.server.zastav()
        finally:
            self.root.destroy()

    def spust(self):
        self.aktualizuj()
        self.root.mainloop()


def spust_okno(server):
    """Spustí server na pozadí (bez otevření prohlížeče) a zobrazí okno."""
    _presmeruj_vystupy()
    server.spust(otevrit_prohlizec=False, na_pozadi=True)
    server.nacti_odok_na_pozadi()
    OknoServeru(server).spust()
