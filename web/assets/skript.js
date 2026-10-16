// Legislativní radar – chování stránek: řazení a stránkování tabulek, filtry, líné načítání
// částí, oblíbené, analýza relevance, kopírování podkladů PLPV, ukotvené příčky.
(function () {
  // ---------- tabulky: řazení kliknutím na záhlaví, stránkování, filtr ----------
  var VELIKOSTI = [25, 50, 100, 0];          // 0 = vše
  function bezDiakritiky(s) {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  }
  // „zákon, který“ = „návrh zákona, kterým“ (stejně jako vzor_hledani() v PHP); null = obyčejný podřetězec
  function vzorHledani(q) {
    var slova = bezDiakritiky(q.trim()).split(/[^a-z0-9\/]+/).filter(Boolean);
    var zmena = false, casti = [];
    for (var i = 0; i < slova.length; i++) {
      var s = slova[i], dalsi = slova[i + 1] || '';
      if (/^navrh(u|em|y)?$/.test(s) && /^(zakon|vyhlas|narizen|ustavn|usnesen)/.test(dalsi)) { zmena = true; continue; }
      var c = null;
      if (/^zakon(a|u|em|y|ech|um)?$/.test(s)) c = 'zakon[a-z]{0,3}';
      else if (/^vyhlas(ka|ky|ce|ku|kou|ek|kam|kach|kami)$/.test(s)) c = 'vyhlas(ka|ky|ce|ku|kou|ek|kam|kach|kami)';
      else if (/^narizeni(m|mi)?$/.test(s)) c = 'narizeni[a-z]{0,2}';
      else if (/^kter(y|ym|ou|a|e|eho|emu|ymi|ych|i)$/.test(s)) c = 'kter[a-z]{0,3}';
      else if (/^navrh(u|em|y|ech|um)?$/.test(s)) c = 'navrh[a-z]{0,3}';
      if (c) { zmena = true; casti.push(c); }
      else casti.push(s.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&'));
    }
    return (zmena && casti.length) ? new RegExp(casti.join('[^a-z0-9]+')) : null;
  }
  function dva(x) { x = String(x); return x.length < 2 ? '0' + x : x; }
  function klicRazeni(td) {
    if (!td) return '';
    if (td.dataset.razeni) return td.dataset.razeni;
    var t = td.textContent.trim();
    var m = t.match(/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/);
    if (m) return m[3] + '-' + dva(m[2]) + '-' + dva(m[1]) + ' ' + dva(m[4] || 0) + ':' + (m[5] || '00');
    return t;
  }
  function ulozenaVelikost() {
    try { var v = localStorage.getItem('odok_na_stranku'); return v === null ? 25 : parseInt(v, 10); }
    catch (e) { return 25; }
  }
  function ulozVelikost(v) { try { localStorage.setItem('odok_na_stranku', String(v)); } catch (e) {} }

  // ---------- ukotvené příčky (jako v Excelu) ----------
  function vyskaListy() {
    var h = document.querySelector('body > header');
    if (h) document.documentElement.style.setProperty('--vyska-listy',
      (getComputedStyle(h).position === 'sticky' ? h.offsetHeight : 0) + 'px');
  }
  function ukotvi(tab) {
    var obal = tab.closest('.posun.ukotvit');
    if (!obal) return;
    var n = parseInt(obal.dataset.sloupcu || '1', 10);
    var hlavicky = Array.from(tab.tHead.rows);
    // šířky ukotvených sloupců podle posledního řádku záhlaví
    var posledni = hlavicky[hlavicky.length - 1];
    var leva = [], x = 0;
    for (var i = 0; i < n && i < posledni.cells.length; i++) {
      leva.push(x);
      x += posledni.cells[i].getBoundingClientRect().width;
    }
    var radky = [posledni].concat(Array.from(tab.tBodies[0].rows));
    radky.forEach(function (r) {
      if (r.style.display === 'none') return;
      for (var i = 0; i < leva.length && i < r.cells.length; i++) {
        var c = r.cells[i];
        c.classList.add('ukotveny');
        c.classList.toggle('posledni', i === leva.length - 1);
        c.style.left = leva[i] + 'px';
      }
    });
  }
  // Plovoucí záhlaví: tabulka je plné výšky (bez vlastního svislého posuvníku), záhlaví se při posouvání
  // stránky posune pod horní lištu, dokud je tabulka vidět. CSS sticky to neumí, když má tabulka vodorovný
  // posuvník (ukotvila by se jen vůči vlastnímu rámečku).
  function plovouciZahlavi() {
    var uzky = window.matchMedia('(max-width: 760px)').matches;   // na mobilu jsou tabulky karty
    var h = document.querySelector('body > header');
    var horni = (h && getComputedStyle(h).position === 'sticky') ? h.getBoundingClientRect().bottom : 0;
    document.querySelectorAll('table.data').forEach(function (tab) {
      if (!tab.tHead || !tab.tHead.rows.length) return;
      var d = 0;
      if (!uzky && tab.offsetParent !== null) {
        var r = tab.getBoundingClientRect(), vyska = tab.tHead.offsetHeight;
        d = Math.round(horni - r.top);
        if (d < 0) d = 0;
        if (d > r.height - vyska - 4) d = Math.max(0, Math.round(r.height - vyska - 4));
      }
      if (tab._plove === d) return;
      tab._plove = d;
      var t = d ? 'translateY(' + d + 'px)' : '';
      Array.from(tab.tHead.rows).forEach(function (radek) {
        Array.from(radek.cells).forEach(function (c) { c.style.transform = t; });
      });
      tab.tHead.classList.toggle('plove', d > 0);
    });
  }
  var cekaPlovouci = false;
  function naplanujPlovouci() {
    if (cekaPlovouci) return;
    cekaPlovouci = true;
    requestAnimationFrame(function () { cekaPlovouci = false; plovouciZahlavi(); });
  }
  window.addEventListener('scroll', naplanujPlovouci, {passive: true});
  var casovacUkotveni = null;
  window.addEventListener('resize', function () {
    clearTimeout(casovacUkotveni);
    casovacUkotveni = setTimeout(function () {
      vyskaListy();
      document.querySelectorAll('.posun.ukotvit table.data').forEach(ukotvi);
      plovouciZahlavi();
    }, 150);
  });
  vyskaListy();

  function Tabulka(tab) {
    var self = this;
    this.tab = tab;
    this.tbody = tab.tBodies[0];
    this.radky = Array.from(this.tbody.rows);
    this.radky.forEach(function (r) { r._text = bezDiakritiky(r.textContent); });
    this.filtr = '';
    this.strana = 0;
    this.velikost = ulozenaVelikost();
    var hlavicka = tab.tHead.rows[tab.tHead.rows.length - 1];
    this.zahlavi = Array.from(hlavicka.cells);
    this.zahlavi.forEach(function (th, i) {
      th.classList.add('raditelne');
      th.addEventListener('click', function () { self.serad(i); });
    });
    this.ovladani = [];
    if (this.radky.length > VELIKOSTI[0]) {
      var obal = tab.closest('.posun') || tab;
      ['beforebegin', 'afterend'].forEach(function (kde) {
        var div = document.createElement('div');
        div.className = 'strankovani';
        obal.insertAdjacentElement(kde, div);
        self.ovladani.push(div);
      });
    }
    this.vykresli();
  }
  Tabulka.prototype.serad = function (i) {
    var th = this.zahlavi[i];
    var vzestupne = !th.classList.contains('vzestupne');
    this.zahlavi.forEach(function (x) { x.classList.remove('vzestupne', 'sestupne'); });
    th.classList.add(vzestupne ? 'vzestupne' : 'sestupne');
    this.radky.sort(function (a, b) {
      var c = klicRazeni(a.cells[i]).localeCompare(klicRazeni(b.cells[i]), 'cs', {numeric: true});
      return vzestupne ? c : -c;
    });
    var tb = this.tbody;
    tb.querySelectorAll('tr.relevance-radek').forEach(function (r) { zrusVlozenyRadek(r); });
    this.radky.forEach(function (r) { tb.appendChild(r); });
    this.strana = 0;
    this.vykresli();
  };
  Tabulka.prototype.nastavFiltr = function (q) {
    this.filtr = bezDiakritiky(q || '');
    this.vzor = vzorHledani(q || '');
    this.strana = 0;
    return this.vykresli();
  };
  Tabulka.prototype.vykresli = function () {
    var self = this, f = this.filtr, vzor = this.vzor, jenObl = this.jenOblibene;
    var viditelne = this.radky.filter(function (r) {
      if (jenObl && !r.querySelector('button.hvezda.zapnuto') && !r.classList.contains('oblibeny')) return false;
      return !f || (vzor ? vzor.test(r._text) : r._text.indexOf(f) >= 0);
    });
    var n = viditelne.length, v = this.velikost;
    var stran = v ? Math.max(1, Math.ceil(n / v)) : 1;
    this.strana = Math.min(this.strana, stran - 1);
    var od = v ? this.strana * v : 0, po = v ? Math.min(od + v, n) : n;
    this.radky.forEach(function (r) { r.style.display = 'none'; });
    viditelne.slice(od, po).forEach(function (r) { r.style.display = ''; });
    this.tbody.querySelectorAll('tr.relevance-radek').forEach(function (r) {
      var pred = r.previousElementSibling;
      while (pred && pred.classList.contains('relevance-radek')) pred = pred.previousElementSibling;
      r.style.display = pred ? pred.style.display : '';
    });
    this.ovladani.forEach(function (div) { self.vykresliOvladani(div, n, od, po, stran); });
    ukotvi(this.tab);
    naplanujPlovouci();
    return n;
  };
  Tabulka.prototype.vykresliOvladani = function (div, n, od, po, stran) {
    var self = this;
    div.innerHTML = '';
    var info = document.createElement('span');
    info.className = 'tlumene';
    info.textContent = n ? ('Řádky ' + (od + 1) + '–' + po + ' z ' + n) : 'Žádné řádky';
    div.appendChild(info);
    var velikosti = document.createElement('span');
    velikosti.appendChild(document.createTextNode('Na stránku: '));
    VELIKOSTI.forEach(function (v) {
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = v ? String(v) : 'vše';
      if (v === self.velikost) b.className = 'aktivni';
      b.addEventListener('click', function () {
        self.velikost = v; self.strana = 0; ulozVelikost(v); self.vykresli();
      });
      velikosti.appendChild(b);
    });
    div.appendChild(velikosti);
    if (stran > 1) {
      var nav = document.createElement('span');
      var zpet = document.createElement('button');
      zpet.type = 'button'; zpet.textContent = '‹ Předchozí'; zpet.disabled = this.strana === 0;
      zpet.addEventListener('click', function () { self.strana--; self.vykresli(); });
      var dal = document.createElement('button');
      dal.type = 'button'; dal.textContent = 'Další ›'; dal.disabled = this.strana >= stran - 1;
      dal.addEventListener('click', function () { self.strana++; self.vykresli(); });
      var text = document.createElement('span');
      text.textContent = ' strana ' + (this.strana + 1) + ' / ' + stran + ' ';
      nav.appendChild(zpet); nav.appendChild(text); nav.appendChild(dal);
      div.appendChild(nav);
    }
  };

  // ---------- oblíbené ⭐ ----------
  var CSRF = (document.querySelector('meta[name=csrf]') || {}).content || '';
  function posli(url, data) {
    return fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'X-Radar': CSRF,
      'Content-Type': 'application/x-www-form-urlencoded'}, body: new URLSearchParams(data)})
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
  }
  function nastavHvezdu(b, zapnuto) {
    b.classList.toggle('zapnuto', zapnuto);
    b.textContent = zapnuto ? '⭐' : '☆';
    b.title = zapnuto ? 'Odebrat z oblíbených' : 'Přidat do oblíbených';
    var td = b.closest('td');
    if (td) td.dataset.razeni = zapnuto ? '1' : '0';
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button.hvezda');
    if (!b) return;
    ev.preventDefault();
    posli('index.php?p=oblibene/prepni', {typ: b.dataset.typ, id: b.dataset.id, nazev: b.dataset.nazev || ''})
      .then(function (v) {
        document.querySelectorAll('button.hvezda').forEach(function (x) {
          if (x.dataset.typ === b.dataset.typ && x.dataset.id === b.dataset.id) nastavHvezdu(x, v.oblibeny);
        });
        var p = document.getElementById('pocet-obl');
        if (p) p.textContent = v.pocet ? String(v.pocet) : '';
        var tab = b.closest('table');
        if (tab && tab._tabulka && tab._tabulka.jenOblibene) tab._tabulka.vykresli();
      })
      .catch(function (err) { alert('Oblíbené se nepodařilo uložit: ' + err); });
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-videno]');
    if (!b) return;
    posli('index.php?p=oblibene/videno', {klic: b.dataset.videno})
      .then(function () { location.reload(); })
      .catch(function (err) { alert('Nepodařilo se uložit: ' + err); });
  });
  // ---------- řádek vložený pod řádek tabulky (náhled dokumentu, analýza relevance) ----------
  // Tlačítko data-vlozit="URL" načte HTML z URL do nového řádku pod svým řádkem;
  // druhé kliknutí řádek zase skryje. data-skryt / data-nacitam = texty tlačítka a načítání.
  function zrusVlozenyRadek(r) {
    var b = r._tlacitko;
    if (b && b.dataset.puvodni) b.textContent = b.dataset.puvodni;
    r.remove();
  }
  function nactiDoRadku(td, url) {
    return fetch(url, {credentials: 'same-origin'}).then(function (r) { return r.text(); })
      .then(function (t) { td.innerHTML = t; inicializuj(td); })
      .catch(function (err) { td.innerHTML = '<div class="chyba">' + err + '</div>'; })
      .then(function () { var r = td.parentNode; if (r && r._dole) td.appendChild(r._dole); });
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-vlozit]');
    if (!b) return;
    var tr = b.closest('tr');
    if (!tr) return;
    var url = b.dataset.vlozit;
    // už vložený řádek od tohoto tlačítka → skrýt
    var r = tr.nextElementSibling, posledni = tr;
    while (r && r.classList.contains('relevance-radek')) {
      if (r.dataset.zdroj === url) { zrusVlozenyRadek(r); return; }
      posledni = r; r = r.nextElementSibling;
    }
    if (!b.dataset.puvodni) b.dataset.puvodni = b.textContent;
    var novy = document.createElement('tr');
    novy.className = 'relevance-radek';
    novy.dataset.zdroj = url;
    novy._tlacitko = b;
    var td = document.createElement('td');
    td.colSpan = Array.from(tr.cells).reduce(function (n, c) { return n + c.colSpan; }, 0);
    td.className = 'cela';
    td.innerHTML = '<p class="nacitam">' + (b.dataset.nacitam || 'Načítám…') + '</p>';
    novy.appendChild(td);
    posledni.insertAdjacentElement('afterend', novy);
    b.disabled = true;
    // na mobilu se vložený obsah může protáhnout přes celou obrazovku – zavírací tlačítko i dole
    var dole = document.createElement('button');
    dole.type = 'button';
    dole.className = 'tlacitko-mini zavrit-vlozene';
    dole.textContent = '▲ ' + (b.dataset.skryt || 'Skrýt');
    dole.addEventListener('click', function () {
      zrusVlozenyRadek(novy);
      var y = tr.getBoundingClientRect().top;
      if (y < 0) tr.scrollIntoView({block: 'start'});
    });
    novy._dole = dole;
    nactiDoRadku(td, url).then(function () {
      b.disabled = false;
      if (b.dataset.skryt) b.textContent = b.dataset.skryt;
    });
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-relevance-znovu]');
    if (!b) return;
    var radek = b.closest('tr.relevance-radek');
    if (!radek || !radek.dataset.zdroj) return;
    b.disabled = true; b.textContent = 'Přegenerovávám…';
    nactiDoRadku(radek.cells[0], radek.dataset.zdroj);
  });
  // přepnutí sady výrazů u výsledku analýzy relevance → uložit a analýzu přegenerovat
  document.addEventListener('change', function (ev) {
    var s = ev.target;
    if (!s.matches || !s.matches('select[data-pravidla-vyber]')) return;
    var radek = s.closest('tr.relevance-radek');
    s.disabled = true;
    posli('index.php?p=pravidla/vyber', {pravidla: s.value})
      .then(function () { if (radek && radek.dataset.zdroj) nactiDoRadku(radek.cells[0], radek.dataset.zdroj); })
      .catch(function (err) { s.disabled = false; alert('Sadu se nepodařilo uložit: ' + err); });
  });
  document.addEventListener('change', function (ev) {
    var v = ev.target;
    if (!v.matches || !v.matches('input.poznamka')) return;
    posli('index.php?p=oblibene/poznamka', {klic: v.dataset.klic, text: v.value})
      .then(function () { v.style.borderColor = 'var(--akcent)'; })
      .catch(function (err) { alert('Poznámku se nepodařilo uložit: ' + err); });
  });

  // ---------- tabulky jako karty na mobilu ----------
  // Buňkám doplní data-l = název sloupce (z hlavičky, i víceřádkové); na úzkém displeji
  // se pak řádky zobrazí jako karty s popisky (CSS @media). Buňky přes celou šířku → .cela,
  // buňky s dlouhým nebo strukturovaným obsahem → .siroka (popisek nad hodnotou).
  function nazvySloupcu(tab) {
    var mrizka = [];
    Array.from(tab.tHead.rows).forEach(function (r, ri) {
      mrizka[ri] = mrizka[ri] || [];
      var c = 0;
      Array.from(r.cells).forEach(function (th) {
        while (mrizka[ri][c] !== undefined) c++;
        var text = th.textContent.replace(/\s+/g, ' ').trim();
        for (var i = 0; i < th.rowSpan; i++) {
          mrizka[ri + i] = mrizka[ri + i] || [];
          for (var j = 0; j < th.colSpan; j++) mrizka[ri + i][c + j] = text;
        }
        c += th.colSpan;
      });
    });
    return mrizka.length ? mrizka[mrizka.length - 1] : [];
  }
  function oznacBunky(tab) {
    if (tab.classList.contains('plpv') || !tab.tHead || !tab.tHead.rows.length) return;
    var nazvy = nazvySloupcu(tab), sloupcu = nazvy.length;
    tab.classList.add('karty');
    // řadicí štítky nad kartami má smysl ukazovat jen u delších seznamů
    tab.classList.toggle('mnoho', tab.tBodies.length > 0 && tab.tBodies[0].rows.length > 5);
    Array.from(tab.tBodies).forEach(function (tb) {
      Array.from(tb.rows).forEach(function (r) {
        if (r.classList.contains('relevance-radek')) return;
        var c = 0;
        Array.from(r.cells).forEach(function (td) {
          if (!td.children.length && !td.textContent.trim()) td.classList.add('prazdna');
          if (td.querySelector('button.hvezda') && td.textContent.trim().length <= 2) {
            td.classList.add('sl-hvezda');
            td.dataset.l = '';
          }
          if (td.colSpan >= sloupcu && sloupcu > 1) td.classList.add('cela');
          else if (td.dataset.l === undefined) td.dataset.l = nazvy[c] || '';
          if (td.querySelector('ul, ol, table, details, pre, .nahled') || td.textContent.trim().length > 70) {
            td.classList.add('siroka');
          } else if (td.dataset.l && !td.classList.contains('cela') && !td.classList.contains('prazdna') && !td._obaleno) {
            // obsah do jednoho prvku, aby šel na mobilu vedle popisku do mřížky
            td._obaleno = true;
            var h = document.createElement('span');
            h.className = 'hodnota';
            while (td.firstChild) h.appendChild(td.firstChild);
            td.appendChild(h);
          }
          c += td.colSpan;
        });
      });
    });
  }

  function inicializuj(koren) {
    koren.querySelectorAll('table.data').forEach(function (t) {
      oznacBunky(t);
      if (!t._tabulka && t.tHead && t.tBodies.length) t._tabulka = new Tabulka(t);
    });
    koren.querySelectorAll('button.jen-obl').forEach(function (b) {
      if (b._napojeno) return;
      b._napojeno = true;
      b.addEventListener('click', function () {
        var tab = document.getElementById(b.dataset.tabulka);
        if (!tab || !tab._tabulka) return;
        tab._tabulka.jenOblibene = !tab._tabulka.jenOblibene;
        b.classList.toggle('aktivni', tab._tabulka.jenOblibene);
        tab._tabulka.strana = 0;
        var n = tab._tabulka.vykresli();
        var pocet = document.getElementById(b.dataset.tabulka + '-pocet');
        if (pocet) pocet.textContent = n;
      });
    });
    var filtry = koren.querySelectorAll('input[data-filtr]');
    filtry.forEach(function (vstup) {
      if (vstup._napojeno) return;
      vstup._napojeno = true;
      var tab = document.getElementById(vstup.dataset.filtr);
      var pocet = document.getElementById(vstup.dataset.filtr + '-pocet');
      // filtry stránky (ne dodatečně načtených částí) se propisují do adresy, aby šla poslat odkazem
      var param = koren === document ? (filtry.length === 1 ? 'filtr' : 'filtr_' + vstup.dataset.filtr) : null;
      function pouzij() {
        if (!tab || !tab._tabulka) return;
        var n = tab._tabulka.nastavFiltr(vstup.value);
        if (pocet) pocet.textContent = n;
      }
      vstup.addEventListener('input', function () {
        pouzij();
        if (param) zapisDoAdresy(param, vstup.value.trim());
      });
      if (param) {
        var z = new URLSearchParams(location.search).get(param);
        if (z) { vstup.value = z; pouzij(); zapisDoAdresy(param, z); }
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'odkaz-filtru';
        b.textContent = '🔗 Odkaz';
        b.title = 'Zkopírovat odkaz na tuto stránku s aktuálním hledáním a obdobím (adresa v prohlížeči se mění průběžně)';
        b.addEventListener('click', function () {
          zapisDoAdresy(param, vstup.value.trim(), true);
          kopiruj(location.href, function () {
            b.textContent = '✓ Odkaz zkopírován';
            setTimeout(function () { b.textContent = '🔗 Odkaz'; }, 2500);
          });
        });
        vstup.insertAdjacentElement('afterend', b);
      }
    });
    // líné načítání (verze, připomínky, sněmovní tisk, podklady PLPV)
    koren.querySelectorAll('details[data-nacti]').forEach(function (d) {
      if (d._napojeno) return;
      d._napojeno = true;
      function nacti() {
        if (!d.open || d.dataset.nacteno) return;
        d.dataset.nacteno = '1';
        var cil = d.querySelector('.obsah');
        fetch(d.dataset.nacti, {credentials: 'same-origin'}).then(function (r) { return r.text(); })
          .then(function (t) { cil.innerHTML = t; inicializuj(cil); })
          .catch(function (err) { cil.innerHTML = '<div class="chyba">' + err + '</div>'; });
      }
      d.addEventListener('toggle', nacti);
      nacti();  // details s atributem open se načtou hned
    });
  }

  function kopiruj(text, hotovo) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(hotovo);
    } else {
      var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
      ta.select(); document.execCommand('copy'); document.body.removeChild(ta); hotovo();
    }
  }
  // hodnota filtru v adrese (bez nového záznamu v historii); s filtrem i zvolené období (vo), aby příjemce
  // odkazu viděl totéž bez ohledu na své nastavení
  function zapisDoAdresy(param, hodnota, vzdyObdobi) {
    var u = new URL(location.href), sp = u.searchParams;
    if (hodnota) sp.set(param, hodnota); else sp.delete(param);
    var nejakyFiltr = Array.from(sp.keys()).some(function (k) { return k === 'filtr' || k.indexOf('filtr_') === 0; });
    var vyber = document.querySelector('form.prepinac select[name=vyber]');
    if ((nejakyFiltr || vzdyObdobi) && vyber) sp.set('vo', vyber.value);
    else if (!nejakyFiltr) sp.delete('vo');
    history.replaceState(history.state, '', u.pathname + (sp.toString() ? '?' + sp.toString() : '') + u.hash);
    var zpet = document.querySelector('form.prepinac input[name=zpet]');
    if (zpet) zpet.value = 'index.php' + location.search;
  }
  // přepínače nad tabulkou (Projednávané, Jen vládní …) zachovají zadané hledání
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('.filtry a[href]');
    if (!a) return;
    var ted = new URLSearchParams(location.search), u = new URL(a.href, location.href), zmena = false;
    ted.forEach(function (v, k) {
      if (k === 'filtr' || k.indexOf('filtr_') === 0 || k === 'vo') { u.searchParams.set(k, v); zmena = true; }
    });
    if (zmena) a.href = u.href;
  });

  // kopírování podkladů do schránky (tlačítka jsou v dodatečně načteném fragmentu)
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-kopiruj]');
    if (!t) return;
    var text = document.getElementById(t.dataset.kopiruj).value;
    var stav = document.querySelector('[data-kopiruj-stav]');
    kopiruj(text, function () { if (stav) stav.textContent = 'Zkopírováno – vložte do excelu (Ctrl+V).'; });
  });

  // ---------- nástěnka: filtr lepíků a „jen oblíbené“ ----------
  function prepocitejNastenku(nast) {
    nast.querySelectorAll('section.faze').forEach(function (sek) {
      var n = 0;
      sek.querySelectorAll('.lepik').forEach(function (l) { if (getComputedStyle(l).display !== 'none') n++; });
      var p = sek.querySelector('h2 .pocet');
      if (p) p.textContent = n;
      sek.querySelectorAll('details.dalsi-lepiky').forEach(function (d) { if (nast._filtr || nast.classList.contains('jen-obl')) d.open = true; });
    });
  }
  document.querySelectorAll('input[data-filtr-lepiku]').forEach(function (vstup) {
    var nast = document.getElementById(vstup.dataset.filtrLepiku);
    if (!nast) return;
    var lepiky = Array.from(nast.querySelectorAll('.lepik'));
    lepiky.forEach(function (l) { l._text = bezDiakritiky(l.textContent + ' ' + (l.querySelector('a.nazev') || {}).title); });
    function pouzij() {
      var f = bezDiakritiky(vstup.value.trim()), vzor = vzorHledani(vstup.value);
      nast._filtr = !!f;
      lepiky.forEach(function (l) {
        l.classList.toggle('skryty', !!f && !(vzor ? vzor.test(l._text) : l._text.indexOf(f) >= 0));
      });
      prepocitejNastenku(nast);
    }
    vstup.addEventListener('input', function () { pouzij(); zapisDoAdresy('filtr', vstup.value.trim()); });
    var z = new URLSearchParams(location.search).get('filtr');
    if (z) { vstup.value = z; pouzij(); zapisDoAdresy('filtr', z); }
    // zvolený resort vždy v adrese, aby odkaz ukázal totéž i jinému uživateli
    var r = vstup.form && vstup.form.querySelector('select[name=r]');
    if (r && !new URLSearchParams(location.search).get('r')) {
      var u = new URL(location.href); u.searchParams.set('r', r.value);
      history.replaceState(history.state, '', u.pathname + '?' + u.searchParams.toString() + u.hash);
    }
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button.jen-obl-lepiky');
    if (b) {
      var nast = document.getElementById(b.dataset.nastenka);
      nast.classList.toggle('jen-obl');
      var zap = nast.classList.contains('jen-obl');
      b.classList.toggle('aktivni', zap);
      prepocitejNastenku(nast);
      var u = new URL(location.href);         // „jen oblíbené“ v adrese (záložka, odkaz)
      if (zap) u.searchParams.set('obl', '1'); else u.searchParams.delete('obl');
      history.replaceState(history.state, '', u.pathname + '?' + u.searchParams.toString() + u.hash);
      return;
    }
    if (ev.target.closest('.nastenka button.hvezda')) {
      setTimeout(function () { var n = document.querySelector('.nastenka.jen-obl'); if (n) prepocitejNastenku(n); }, 800);
    }
  });

  document.querySelectorAll('button.jen-obl-lepiky').forEach(function (b) {
    if (new URLSearchParams(location.search).get('obl') === '1') b.click();
  });

  inicializuj(document);
  plovouciZahlavi();
  // obsah načítaný dodatečně (rozbalené sekce, vložené řádky) mění polohu tabulek
  new MutationObserver(naplanujPlovouci).observe(document.body, {childList: true, subtree: true});
  document.addEventListener('toggle', naplanujPlovouci, true);
})();
