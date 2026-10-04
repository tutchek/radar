# -*- coding: utf-8 -*-
"""
esel_vazby.py – ověření vazeb mezi datovými sadami e-Legislativy.

Propojí návrhy (100) s fázemi (102), stavy procesu (103), aktuální vrstvou
návrhu (104), metadaty aktu (105) a účinností (110) a u vybraných návrhů
vypíše celý řetězec. Přidá statistiky: jak u vyhlášek / nařízení poznat resort,
jaké jsou názvy fází a stavů, překlad kódů z globálního číselníku (180).

Použití (soubory si bere z cache esel_analyza.py, případně stáhne):
    py esel_vazby.py            # resort MF
    py esel_vazby.py MZ         # jiný resort (kód autora / začátek čj)
Výstup: esel_vazby.txt
"""

import gzip
import json
import re
import sys
from collections import Counter, defaultdict

from odok import Odok, OdokChyba, bez_diakritiky
from esel_analyza import EselStahovani, ZAKLAD

NAZVY_RESORTU = {"MF": "financí", "MZ": "zdravotnictví", "MV": "vnitra", "MPSV": "práce a sociálních",
                 "MSP": "spravedlnosti", "MS": "spravedlnosti", "MPO": "průmyslu", "MZE": "zemědělství",
                 "MMR": "místní rozvoj", "MD": "dopravy", "MK": "kultury", "MO": "obrany",
                 "MZP": "životního prostředí", "MSMT": "školství", "MZV": "zahraničních"}


def nacti(s, sada):
    nazev = sada + ".json.gz"
    data = gzip.decompress(s._ziskej_s_cache(ZAKLAD + nazev, nazev))
    obsah = json.loads(data.decode("utf-8-sig"))
    return obsah.get("položky", obsah) if isinstance(obsah, dict) else obsah


def zkrat(v, n=160):
    s = v if isinstance(v, str) else json.dumps(v, ensure_ascii=False)
    return s if len(s) <= n else s[:n] + "…"


def main():
    resort = (sys.argv[1] if len(sys.argv) > 1 else "MF").upper()
    resort_text = bez_diakritiky(NAZVY_RESORTU.get(resort, resort))
    s = EselStahovani()
    out_f = open("esel_vazby.txt", "w", encoding="utf-8")

    def p(*a):
        text = " ".join(str(x) for x in a)
        out_f.write(text + "\n")
        print(text)

    try:
        navrhy = nacti(s, "100NavrhAktuNavrh")
        faze = nacti(s, "102NavrhAktuFaze")
        stavy = nacti(s, "103NavrhAktuStavProcesu")
        vrstvy = nacti(s, "104NavrhAktuVrstvaNavrhu")
        metadata = nacti(s, "105NavrhAktuMetadataAktu")
        ucinnost = nacti(s, "110NavrhAktuUcinnostAktu")
        globalni = nacti(s, "180CiselnikGlobalni")
    except (OdokChyba, OSError, ValueError) as chyba:
        p("CHYBA při načítání: %s" % chyba)
        return

    glob = {g.get("globální-id"): g for g in globalni}

    def gnazev(gid):
        g = glob.get(gid) or {}
        n = g.get("název")
        n = n.get("cs") if isinstance(n, dict) else n
        return "%s (%s: %s)" % (gid, g.get("cis-ele-globální-skupina-položka"), n or g.get("kód"))

    # --- indexy
    faze_podle_navrhu = defaultdict(list)
    for f in faze:
        faze_podle_navrhu[(f.get("návrh-aktu-nadřízený-návrh") or {}).get("návrh-id")].append(f)
    stavy_podle_faze = defaultdict(list)
    for st in stavy:
        stavy_podle_faze[(st.get("stav-procesu-fáze-nadřízená") or {}).get("návrh-id")].append(st)
    vrstvy_podle_prefixu = defaultdict(list)
    for v in vrstvy:
        ident = v.get("vrstva-návrhu-identifikátor") or ""
        vrstvy_podle_prefixu["-".join(ident.split("-")[:2])].append(v)
    meta = {m.get("metadata-aktu-id"): m for m in metadata}
    uc = {u.get("účinnost-aktu-id"): u for u in ucinnost}

    # --- složení řetězce pro každý návrh
    slozene = []
    for n in navrhy:
        ident = n.get("návrh-identifikator")
        vs = vrstvy_podle_prefixu.get(ident, [])
        aktualni = [v for v in vs if v.get("vrstva-návrhu-je-aktuální")]
        v = aktualni[0] if aktualni else (max(vs, key=lambda x: x.get("vrstva-návrhu-id") or 0) if vs else None)
        m = meta.get(((v or {}).get("návrh-aktu-metadata-aktu") or {}).get("metadata-aktu-id"))
        u = uc.get(((m or {}).get("návrh-aktu-účinnost-od") or {}).get("účinnost-aktu-id"))
        cj = sorted({x.get("vrstva-návrhu-cjpředkladatele") for x in vs if x.get("vrstva-návrhu-cjpředkladatele")}
                    | ({n["návrh-cj-předkladatele"]} if n.get("návrh-cj-předkladatele") else set()))
        slozene.append({"navrh": n, "vrstva": v, "vrstev": len(vs), "meta": m, "ucinnost": u, "cj": cj,
                        "faze": sorted(faze_podle_navrhu.get(n.get("návrh-id"), []),
                                       key=lambda f: f.get("fáze-datum-čas-zahájení") or "")})

    # --- statistiky
    p("Zdroj: %s\nNávrhů: %d, fází: %d, stavů: %d, vrstev: %d, metadat aktu: %d\n"
      % (ZAKLAD, len(navrhy), len(faze), len(stavy), len(vrstvy), len(metadata)))
    p("Návrhů s nalezenou vrstvou: %d, s aktuální vrstvou: %d, s metadaty přes vrstvu: %d" % (
        sum(1 for x in slozene if x["vrstva"]),
        sum(1 for x in slozene if x["vrstva"] and x["vrstva"].get("vrstva-návrhu-je-aktuální")),
        sum(1 for x in slozene if x["meta"])))
    p("Návrhů bez vazby na fáze: %d" % sum(1 for x in slozene if not x["faze"]))
    druhy = Counter((x["meta"] or {}).get("cis-ele-druh-aktu-položka") for x in slozene)
    p("Druh aktu (přes metadata): %s" % dict(druhy))
    for druh in ("VYHLASKA", "NARIZVLA"):
        vyber = [x for x in slozene if (x["meta"] or {}).get("cis-ele-druh-aktu-položka") == druh]
        p("\n%s: %d návrhů" % (druh, len(vyber)))
        p("  autor aktu vyplněn: %d  %s" % (
            sum(1 for x in vyber if x["meta"].get("cis-ele-autor-aktu-položka")),
            dict(Counter(x["meta"].get("cis-ele-autor-aktu-položka") for x in vyber))))
        p("  s čj předkladatele (návrh nebo vrstvy): %d" % sum(1 for x in vyber if x["cj"]))
        p("  začátky čj: %s" % dict(Counter(re.split(r"[-/ ]", c)[0] for x in vyber for c in x["cj"][:1])))
        p("  s citací ve Sbírce: %d, s datem vyhlášení: %d" % (
            sum(1 for x in vyber if x["meta"].get("metadata-aktu-citace")),
            sum(1 for x in vyber if x["meta"].get("metadata-aktu-datum-čas-vyhlášení"))))
        p("  název obsahuje „%s“: %d" % (resort_text, sum(
            1 for x in vyber if resort_text in bez_diakritiky(x["meta"].get("metadata-aktu-název") or ""))))
    p("\nTyp procesu návrhu (globální kód → název): ")
    for gid, pocet in Counter((x["navrh"].get("cis-ele-globální-typ-procesu-návrhu") or {}).get("globální-id")
                              for x in slozene).most_common():
        p("  %4d× %s" % (pocet, gnazev(gid)))
    p("\nNázvy fází (bez pořadí v závorce): %s" % dict(Counter(
        re.sub(r"\(\d+\)$", "", f.get("fáze-název") or "") for f in faze).most_common()))
    p("\nNázvy stavů procesu (bez pořadí) a globální kód:")
    for (nazev, gid), pocet in Counter((re.sub(r"\(\d+\)$", "", st.get("stav-procesu-název") or ""),
                                        (st.get("cis-ele-globální-stav-procesu") or {}).get("globální-id"))
                                       for st in stavy).most_common():
        p("  %4d× %-45s %s" % (pocet, nazev, gnazev(gid)))
    p("\nSledy fází (nejčastější): ")
    for sled, pocet in Counter(" → ".join(re.sub(r"\(\d+\)$", "", f.get("fáze-název") or "")
                                          for f in x["faze"]) for x in slozene).most_common(15):
        p("  %4d× %s" % (pocet, sled))

    # --- vazba na ODOK: čj OVA (návrh-cj-ova ↔ ODOK identifier.id_no), případně PID v textu
    def norm_ova(c):
        m = re.match(r"\s*(\d+)\s*/\s*(\d{2,4})", c or "")
        return "%d/%02d" % (int(m.group(1)), int(m.group(2)) % 100) if m else None

    vzor_pid = re.compile(r"\b(?:KORN|ALBS|RACK|UVLM)[A-Z0-9]{8}\b")
    s_pid = Counter()
    for sada, zaznamy in (("100", navrhy), ("104", vrstvy), ("105", metadata)):
        for z in zaznamy:
            if vzor_pid.search(json.dumps(z, ensure_ascii=False)):
                s_pid[sada] += 1
    p("\n" + "=" * 78)
    p("VAZBA NA ODOK")
    p("=" * 78)
    p("Záznamy eSeL obsahující něco jako PID eKLEPu: %s" % (dict(s_pid) or "žádné"))
    s_ova = [x for x in slozene if norm_ova(x["navrh"].get("návrh-cj-ova"))]
    p("Návrhů s čj OVA: %d z %d" % (len(s_ova), len(slozene)))
    p("  podle druhu aktu: %s" % dict(Counter((x["meta"] or {}).get("cis-ele-druh-aktu-položka") for x in s_ova)))
    p("Návrhů bez čj OVA podle druhu: %s" % dict(Counter(
        (x["meta"] or {}).get("cis-ele-druh-aktu-položka") for x in slozene if x not in s_ova)))
    try:
        odok = Odok(roky=[2024, 2025, 2026])
        materialy = odok.materialy()
        p("ODOK: %d materiálů (roky %s%s)" % (len(materialy), odok.roky,
          ", přeskočeno %s" % list(odok.chyby_nacteni) if getattr(odok, "chyby_nacteni", None) else ""))
    except OdokChyba as chyba:
        materialy = []
        p("ODOK se nepodařilo načíst: %s" % chyba)
    podle_ova = {}
    for m in materialy:
        k = norm_ova(m.cj_ova)
        if k:
            podle_ova.setdefault(k, m)
    sparovane = [(x, podle_ova[norm_ova(x["navrh"].get("návrh-cj-ova"))]) for x in s_ova
                 if norm_ova(x["navrh"].get("návrh-cj-ova")) in podle_ova]
    p("Spárováno s ODOK přes čj OVA: %d z %d návrhů s OVA" % (len(sparovane), len(s_ova)))
    p("  typy ODOK u spárovaných: %s" % dict(Counter(m.typ_id for _x, m in sparovane)))
    p("  stavy ODOK u spárovaných: %s" % dict(Counter(m.stav_id for _x, m in sparovane)))
    p("  z toho MF (ODOK): %d" % sum(1 for _x, m in sparovane if m.predkladatel_zkratka == resort))
    p("\nUkázka dvojic (ODOK | eSeL):")
    for x, m in sorted(sparovane, key=lambda d: d[1].predkladatel_zkratka != resort)[:12]:
        meta_x = x["meta"] or {}
        p("  %s %s %-5s | %s" % (norm_ova(x["navrh"].get("návrh-cj-ova")), m.pid, m.predkladatel_zkratka,
                                 zkrat(m.nazev, 70)))
        p("  %-27s | %s  [%s, %s, vyhlášeno %s]" % ("", zkrat(x["navrh"].get("návrh-název"), 70),
          meta_x.get("cis-ele-druh-aktu-položka"), meta_x.get("metadata-aktu-citace"),
          meta_x.get("metadata-aktu-datum-čas-vyhlášení")))
    nesparovane = [x for x in s_ova if x not in [d[0] for d in sparovane]]
    if nesparovane:
        p("\nNespárované čj OVA (ukázka): %s" % ", ".join(
            "%s (%s)" % (x["navrh"].get("návrh-cj-ova"), zkrat(x["navrh"].get("návrh-název"), 40))
            for x in nesparovane[:8]))

    # --- detail vybraných návrhů
    def je_resortu(x):
        m = x["meta"] or {}
        return (m.get("cis-ele-autor-aktu-položka") == resort
                or any(c.upper().startswith(resort) for c in x["cj"])
                or resort_text in bez_diakritiky(json.dumps(x["navrh"], ensure_ascii=False))
                or resort_text in bez_diakritiky(m.get("metadata-aktu-název") or ""))
    resortni = [x for x in slozene if je_resortu(x)]
    ostatni_vyhl = [x for x in slozene if x not in resortni and
                    (x["meta"] or {}).get("cis-ele-druh-aktu-položka") in ("VYHLASKA", "NARIZVLA")]
    p("\n" + "=" * 78)
    p("DETAIL: %d návrhů spojených s %s (ukázka 8) + 4 jiné vyhlášky/nařízení" % (len(resortni), resort))
    p("=" * 78)
    for x in resortni[:8] + ostatni_vyhl[:4]:
        n, m, v, u = x["navrh"], x["meta"] or {}, x["vrstva"] or {}, x["ucinnost"] or {}
        p("\n### %s  %s" % (n.get("návrh-identifikator"), zkrat(n.get("návrh-název"), 110)))
        p("  čj OVA: %s | čj předkladatele: %s | vrstev: %d" % (n.get("návrh-cj-ova"), x["cj"], x["vrstev"]))
        p("  typ procesu: %s | datum autorizace: %s" % (
            gnazev((n.get("cis-ele-globální-typ-procesu-návrhu") or {}).get("globální-id")),
            n.get("návrh-datum-autorizace")))
        p("  aktuální vrstva: %s „%s“ (aktuální=%s, sněm. tisk=%s)" % (
            v.get("vrstva-návrhu-identifikátor"), v.get("vrstva-návrhu-název"),
            v.get("vrstva-návrhu-je-aktuální"), v.get("vrstva-návrhu-číslo-sněmovního-tisku")))
        p("  metadata: druh=%s autor=%s citace=%s vyhlášeno=%s schváleno=%s EU=%s termín impl.=%s" % (
            m.get("cis-ele-druh-aktu-položka"), m.get("cis-ele-autor-aktu-položka"),
            m.get("metadata-aktu-citace"), m.get("metadata-aktu-datum-čas-vyhlášení"),
            m.get("metadata-aktu-datum-schválení"), m.get("cis-ele-kompatibilita-eu-položka"),
            m.get("metadata-aktu-termín-pro-implementaci")))
        p("  název aktu: %s" % zkrat(m.get("metadata-aktu-název"), 200))
        p("  účinnost: %s (%s, pracovní %s)" % (u.get("účinnost-aktu-datum-účinnosti"),
                                               u.get("cis-ele-typ-účinnosti-položka"),
                                               u.get("účinnost-aktu-datum-pracovní-účinnosti")))
        for f in x["faze"]:
            p("  fáze %-28s %s → %s" % (f.get("fáze-název"), f.get("fáze-datum-čas-zahájení"),
                                          f.get("fáze-datum-čas-ukončení")))
            for st in sorted(stavy_podle_faze.get(f.get("fáze-id"), []),
                             key=lambda z: z.get("stav-procesu-datum-čas-zahájení") or ""):
                p("      stav %-36s %s → %s" % (st.get("stav-procesu-název"),
                                                  st.get("stav-procesu-datum-čas-zahájení"),
                                                  st.get("stav-procesu-datum-čas-ukončení")))
    out_f.close()
    print("\nUloženo do esel_vazby.txt")


if __name__ == "__main__":
    main()
