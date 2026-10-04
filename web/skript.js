// Legislativní radar – chování stránek: řazení a stránkování tabulek, filtry, líné načítání
// částí, oblíbené, analýza relevance, kopírování podkladů PLPV, ukotvené příčky.
(function () {
  // ---------- tabulky: řazení kliknutím na záhlaví, stránkování, filtr ----------
  var VELIKOSTI = [25, 50, 100, 0];          // 0 = vše
  function bezDiakritiky(s) {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
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
    if (h) document.documentElement.style.setProperty('--vyska-listy', h.offsetHeight + 'px');
  }
  function ukotvi(tab) {
    var obal = tab.closest('.posun.ukotvit');
    if (!obal) return;
    var n = parseInt(obal.dataset.sloupcu || '1', 10);
    var hlavicky = Array.from(tab.tHead.rows);
    // řádky záhlaví pod sebou (PLPV má dva)
    var top = 0;
    hlavicky.forEach(function (r) {
      Array.from(r.cells).forEach(function (c) { c.style.top = top + 'px'; });
      top += r.offsetHeight;
    });
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
  var casovacUkotveni = null;
  window.addEventListener('resize', function () {
    clearTimeout(casovacUkotveni);
    casovacUkotveni = setTimeout(function () {
      vyskaListy();
      document.querySelectorAll('.posun.ukotvit table.data').forEach(ukotvi);
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
    tb.querySelectorAll('tr.relevance-radek').forEach(function (r) {
      var b = r.previousElementSibling && r.previousElementSibling.querySelector('button[data-relevance]');
      if (b) b.textContent = 'Provést analýzu relevance';
      r.remove();
    });
    this.radky.forEach(function (r) { tb.appendChild(r); });
    this.strana = 0;
    this.vykresli();
  };
  Tabulka.prototype.nastavFiltr = function (q) {
    this.filtr = bezDiakritiky(q || '');
    this.strana = 0;
    return this.vykresli();
  };
  Tabulka.prototype.vykresli = function () {
    var self = this, f = this.filtr, jenObl = this.jenOblibene;
    var viditelne = this.radky.filter(function (r) {
      if (jenObl && !r.querySelector('button.hvezda.zapnuto')) return false;
      return !f || r._text.indexOf(f) >= 0;
    });
    var n = viditelne.length, v = this.velikost;
    var stran = v ? Math.max(1, Math.ceil(n / v)) : 1;
    this.strana = Math.min(this.strana, stran - 1);
    var od = v ? this.strana * v : 0, po = v ? Math.min(od + v, n) : n;
    this.radky.forEach(function (r) { r.style.display = 'none'; });
    viditelne.slice(od, po).forEach(function (r) { r.style.display = ''; });
    this.tbody.querySelectorAll('tr.relevance-radek').forEach(function (r) {
      var pred = r.previousElementSibling;
      r.style.display = pred ? pred.style.display : '';
    });
    this.ovladani.forEach(function (div) { self.vykresliOvladani(div, n, od, po, stran); });
    ukotvi(this.tab);
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
  function posli(url, data) {
    return fetch(url, {method: 'POST', headers: {'X-Radar': '1',
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
    posli('/oblibene/prepni', {typ: b.dataset.typ, id: b.dataset.id, nazev: b.dataset.nazev || ''})
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
    posli('/oblibene/videno', {klic: b.dataset.videno})
      .then(function () { location.reload(); })
      .catch(function (err) { alert('Nepodařilo se uložit: ' + err); });
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-relevance]');
    if (!b) return;
    var tr = b.closest('tr');
    var dalsi = tr.nextElementSibling;
    if (dalsi && dalsi.classList.contains('relevance-radek')) { dalsi.remove(); b.textContent = 'Provést analýzu relevance'; return; }
    var novy = document.createElement('tr');
    novy.className = 'relevance-radek';
    var td = document.createElement('td');
    td.colSpan = tr.cells.length;
    td.innerHTML = '<p class="nacitam">Stahuji dokument a hledám výrazy…</p>';
    novy.appendChild(td);
    tr.insertAdjacentElement('afterend', novy);
    b.disabled = true;
    fetch(b.dataset.relevance).then(function (r) { return r.text(); })
      .then(function (t) { td.innerHTML = t; inicializuj(td); b.textContent = 'Skrýt analýzu'; })
      .catch(function (err) { td.innerHTML = '<div class="chyba">' + err + '</div>'; })
      .then(function () { b.disabled = false; });
  });
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-relevance-znovu]');
    if (!b) return;
    var radek = b.closest('tr.relevance-radek');
    var puvodni = radek && radek.previousElementSibling &&
      radek.previousElementSibling.querySelector('button[data-relevance]');
    if (!puvodni) return;
    var td = radek.cells[0];
    b.disabled = true; b.textContent = 'Přegenerovávám…';
    fetch(puvodni.dataset.relevance).then(function (r) { return r.text(); })
      .then(function (t) { td.innerHTML = t; inicializuj(td); })
      .catch(function (err) { td.innerHTML = '<div class="chyba">' + err + '</div>'; });
  });
  document.addEventListener('change', function (ev) {
    var v = ev.target;
    if (!v.matches || !v.matches('input.poznamka')) return;
    posli('/oblibene/poznamka', {klic: v.dataset.klic, text: v.value})
      .then(function () { v.style.borderColor = 'var(--akcent)'; })
      .catch(function (err) { alert('Poznámku se nepodařilo uložit: ' + err); });
  });

  function inicializuj(koren) {
    koren.querySelectorAll('table.data').forEach(function (t) {
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
    koren.querySelectorAll('input[data-filtr]').forEach(function (vstup) {
      if (vstup._napojeno) return;
      vstup._napojeno = true;
      var tab = document.getElementById(vstup.dataset.filtr);
      var pocet = document.getElementById(vstup.dataset.filtr + '-pocet');
      vstup.addEventListener('input', function () {
        if (!tab || !tab._tabulka) return;
        var n = tab._tabulka.nastavFiltr(vstup.value);
        if (pocet) pocet.textContent = n;
      });
    });
    // líné načítání (verze, připomínky, sněmovní tisk, podklady PLPV)
    koren.querySelectorAll('details[data-nacti]').forEach(function (d) {
      if (d._napojeno) return;
      d._napojeno = true;
      function nacti() {
        if (!d.open || d.dataset.nacteno) return;
        d.dataset.nacteno = '1';
        var cil = d.querySelector('.obsah');
        fetch(d.dataset.nacti).then(function (r) { return r.text(); })
          .then(function (t) { cil.innerHTML = t; inicializuj(cil); })
          .catch(function (err) { cil.innerHTML = '<div class="chyba">' + err + '</div>'; });
      }
      d.addEventListener('toggle', nacti);
      nacti();  // details s atributem open se načtou hned
    });
  }

  // kopírování podkladů do schránky (tlačítka jsou v dodatečně načteném fragmentu)
  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-kopiruj]');
    if (!t) return;
    var text = document.getElementById(t.dataset.kopiruj).value;
    var stav = document.querySelector('[data-kopiruj-stav]');
    function hotovo() { if (stav) stav.textContent = 'Zkopírováno – vložte do excelu (Ctrl+V).'; }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(hotovo);
    } else {
      var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta);
      ta.select(); document.execCommand('copy'); document.body.removeChild(ta); hotovo();
    }
  });

  inicializuj(document);
})();
