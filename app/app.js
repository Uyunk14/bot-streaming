/* Mini App streaming — tanpa framework. Rute pakai hash:
 *  #/                    beranda
 *  #/search?q=           cari
 *  #/d/p/id/path         detail
 *  #/w/p/id/path/se/ep   tonton
 *  #/vip                 paket VIP
 *  #/me  #/me/fav        profil (riwayat / favorit)
 */
(function () {
  'use strict';
  var TG = window.Telegram && window.Telegram.WebApp;
  var API = '../api/';
  var S = { boot: null, prov: 'filmbox', homeCache: {}, detailCache: {}, timers: [] };
  var $view = document.getElementById('view');
  var $tab = document.getElementById('tabbar');

  /* ---------------- util ---------------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function enc(s) { return encodeURIComponent(s); }
  function rupiah(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID'); }
  function fmtDate(ts) {
    var d = new Date(ts * 1000);
    return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) + ', ' +
      d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
  }
  function toast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg; t.hidden = false;
    clearTimeout(t._h); t._h = setTimeout(function () { t.hidden = true; }, 2600);
  }
  function haptic(kind) { try { TG && TG.HapticFeedback.impactOccurred(kind || 'light'); } catch (e) {} }
  function clearTimers() { S.timers.forEach(function (t) { clearInterval(t); clearTimeout(t); }); S.timers = []; }
  function loading() { $view.innerHTML = '<div class="center-load"><div class="spinner"></div></div>'; }
  function errBox(msg) { return '<div class="err">' + esc(msg) + '</div>'; }
  var ICON = {
    play: '<svg viewBox="0 0 24 24" style="fill:currentColor;stroke:none"><path d="M7 4l13 8-13 8z"/></svg>',
    back: '<svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>',
    lock: '<svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
    heart: '<svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/></svg>',
    search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
    crown: '<svg viewBox="0 0 24 24"><path d="M3 8l4 4 5-7 5 7 4-4-2 11H5z"/></svg>',
    chev: '<svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg>',
    check: '<svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg>',
    users: '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>'
  };

  function api(action, params, method) {
    params = params || {};
    var url = API + '?a=' + action;
    var opt = { method: method || 'GET', headers: { 'X-Init-Data': TG ? TG.initData : '' } };
    if (opt.method === 'GET') {
      Object.keys(params).forEach(function (k) { url += '&' + enc(k) + '=' + enc(params[k]); });
    } else {
      opt.headers['Content-Type'] = 'application/json';
      opt.body = JSON.stringify(params);
    }
    return fetch(url, opt).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Server tidak menjawab dengan benar.' }; })
        .then(function (j) { j._status = r.status; return j; });
    }).catch(function () { return { ok: false, error: 'Koneksi bermasalah, coba lagi.' }; });
  }

  function detailHref(p, it) { return '#/d/' + enc(p) + '/' + enc(it.id) + '/' + enc(it.path); }
  function watchHref(p, id, path, se, ep) { return '#/w/' + enc(p) + '/' + enc(id) + '/' + enc(path) + '/' + se + '/' + ep; }
  function img(src, alt) { return src ? '<img loading="lazy" src="' + esc(src) + '" alt="' + esc(alt || '') + '">' : ''; }

  function posterHTML(p, it) {
    var tag = it.type === 2 ? 'Series' : '';
    return '<a class="poster" href="' + detailHref(p, it) + '"><div class="img">' + img(it.cover, it.title) +
      (it.rating ? '<span class="rate">★ ' + esc(it.rating) + '</span>' : '') +
      (it.badge ? '<span class="tag">' + esc(it.badge) + '</span>' : (tag ? '<span class="tag" style="background:rgba(0,0,0,.65)">' + tag + '</span>' : '')) +
      '</div><span class="name">' + esc(it.title) + '</span></a>';
  }

  function epLabel(type, se, ep) { return type === 1 ? 'Film' : ('S' + se + ' · Eps ' + ep); }

  /* ---------------- tema & telegram ---------------- */
  function applyTheme(app) {
    var c = /^#[0-9a-f]{6}$/i.test(app.color || '') ? app.color : '#6A4DF4';
    document.documentElement.style.setProperty('--accent', c);
    document.title = app.name || 'Nonton';
  }
  function setBack(show) {
    if (!TG) return;
    if (show) TG.BackButton.show(); else TG.BackButton.hide();
  }

  /* ---------------- router ---------------- */
  function parseHash() {
    var h = location.hash.replace(/^#/, '') || '/';
    var qi = h.indexOf('?');
    var path = qi >= 0 ? h.slice(0, qi) : h;
    var query = {};
    if (qi >= 0) h.slice(qi + 1).split('&').forEach(function (kv) {
      var p = kv.split('='); if (p[0]) query[decodeURIComponent(p[0])] = decodeURIComponent((p[1] || '').replace(/\+/g, ' '));
    });
    var parts = path.split('/').filter(Boolean).map(decodeURIComponent);
    return { parts: parts, query: query };
  }

  function route() {
    clearTimers();
    var oldVid = document.getElementById('vid');
    if (oldVid && !oldVid.paused) oldVid.pause(); // memicu simpan posisi tontonan
    Array.prototype.forEach.call(document.querySelectorAll('.sheet-bg'), function (el) { el.remove(); });
    if (!S.boot) return;
    var r = parseHash();
    var p = r.parts;
    var tab = '';
    if (S.boot.gate.required && !S.boot.gate.ok) { $tab.hidden = true; setBack(false); return renderGate(); }
    window.scrollTo(0, 0);
    if (!p.length) { tab = 'home'; renderHome(); }
    else if (p[0] === 'search') { tab = 'search'; renderSearch(r.query.q || ''); }
    else if (p[0] === 'd' && p.length >= 4) renderDetail(p[1], p[2], p.slice(3).join('/'));
    else if (p[0] === 'w' && p.length >= 6) renderWatch(p[1], p[2], p.slice(3, p.length - 2).join('/'), +p[p.length - 2], +p[p.length - 1]);
    else if (p[0] === 'vip') renderVip();
    else if (p[0] === 'me') { tab = p[1] === 'fav' ? 'fav' : 'me'; renderMe(p[1] === 'fav' ? 'fav' : 'hist'); }
    else { tab = 'home'; renderHome(); }
    $tab.hidden = !tab;
    Array.prototype.forEach.call($tab.querySelectorAll('a'), function (a) { a.classList.toggle('on', a.getAttribute('data-tab') === tab); });
    setBack(!tab);
  }

  /* ---------------- gate (wajib gabung) ---------------- */
  function renderGate() {
    var g = S.boot.gate;
    var items = g.items.map(function (it) {
      return '<div class="gitem"><div style="flex:1;display:flex;flex-direction:column;gap:2px"><b>' + (it.kind === 'channel' ? 'Channel' : 'Grup') +
        '</b><span class="muted" style="font-size:12px">' + esc(it.name) + '</span></div>' +
        (it.joined ? '<span class="joined">' + ICON.check + 'Sudah</span>'
          : '<button class="btn btn-accent" style="height:38px" data-join="' + esc(it.link) + '">Gabung</button>') + '</div>';
    }).join('');
    $view.innerHTML = '<div class="gate"><div style="text-align:center;display:flex;flex-direction:column;gap:12px">' +
      '<div class="ico">' + ICON.users + '</div><h1 class="h1">Gabung dulu, yuk</h1>' +
      '<p class="muted" style="margin:0;line-height:1.5">Untuk mulai nonton, gabung ke channel dan grup kami. Info film baru dan promo VIP ada di sana.</p></div>' +
      '<div style="display:flex;flex-direction:column;gap:10px">' + items + '</div>' +
      '<button class="btn btn-line btn-block" id="recheck">Saya sudah gabung, cek lagi</button></div>';
    $view.querySelectorAll('[data-join]').forEach(function (b) {
      b.onclick = function () {
        var link = b.getAttribute('data-join');
        if (!link) return toast('Tautan belum diatur admin.');
        if (TG && TG.openTelegramLink) TG.openTelegramLink(link); else window.open(link, '_blank');
      };
    });
    document.getElementById('recheck').onclick = function () {
      var b = this; b.disabled = true; b.textContent = 'Mengecek…';
      api('gate').then(function (j) {
        b.disabled = false; b.textContent = 'Saya sudah gabung, cek lagi';
        if (!j.ok) return toast(j.error);
        S.boot.gate = j.gate;
        if (j.gate.ok) { toast('Terima kasih sudah bergabung!'); route(); } else { renderGate(); toast('Belum terdeteksi bergabung.'); }
      });
    };
  }

  /* ---------------- beranda ---------------- */
  function renderHome() {
    var app = S.boot.app, u = S.boot.user;
    var provs = app.providers;
    var head = '<div class="top"><div class="brand"><div class="logo">' + (app.logo ? img(app.logo, '') : ICON.play) + '</div>' + esc(app.name) + '</div>' +
      (u.vip ? '<span class="badge">VIP</span>' : '<a class="badge" href="#/vip">Jadi VIP</a>') + '</div>' +
      '<a class="searchbar" href="#/search">' + ICON.search + 'Cari film, drama, anime…</a>' +
      (provs.length > 1 ? '<div class="chips">' + provs.map(function (p) {
        return '<button class="chip' + (p.id === S.prov ? ' on' : '') + '" data-prov="' + esc(p.id) + '">' + esc(p.name) + '</button>';
      }).join('') + '</div>' : '');

    if (!provs.length) { $view.innerHTML = head + '<div class="empty">Belum ada sumber film yang aktif.</div>'; return; }
    if (!provs.some(function (p) { return p.id === S.prov; })) S.prov = provs[0].id;

    var draw = function (j) {
      if (!j.ok) { $view.innerHTML = head + errBox(j.error); bindProv(); return; }
      var d = j.data, prov = S.prov;
      var hero = d.hero.length ? '<div class="hero" id="hero">' + d.hero.map(function (it) {
        return '<a class="hero-item" href="' + detailHref(prov, it) + '">' + img(it.cover, it.title) + '<div class="shade"></div><div class="info">' +
          '<span class="m">' + esc([it.genre, it.rating ? '★ ' + it.rating : ''].filter(Boolean).join(' · ')) + '</span>' +
          '<span class="t">' + esc(it.title) + '</span>' +
          '<span><span class="btn btn-accent" style="height:36px;font-size:13px">' + ICON.play + 'Tonton</span></span></div></a>';
      }).join('') + '</div><div class="dots" id="dots">' + d.hero.map(function (_, i) { return '<i' + (i ? '' : ' class="on"') + '></i>'; }).join('') + '</div>' : '';
      var cont = (j.continue || []).filter(function (h) { return h.provider === prov; });
      var contHTML = cont.length ? '<div class="sec"><div class="pad"><span class="h2">Lanjut Nonton</span></div><div class="hscroll">' + cont.map(function (h) {
        var pct = h.duration ? Math.min(100, Math.round(h.position / h.duration * 100)) : 0;
        return '<a class="cont" href="' + watchHref(h.provider, h.id, h.path, h.se, h.ep) + '"><div class="img">' + img(h.cover, h.title) +
          '<div class="bar" style="width:' + pct + '%"></div></div><span class="name">' + esc(h.title) + ' · ' + epLabel(+h.type, h.se, h.ep) + '</span></a>';
      }).join('') + '</div></div>' : '';
      var secs = d.sections.map(function (s) {
        return '<div class="sec"><div class="pad row between"><span class="h2">' + esc(s.title) + '</span></div><div class="hscroll">' +
          s.items.map(function (it) { return posterHTML(prov, it); }).join('') + '</div></div>';
      }).join('');
      $view.innerHTML = head + hero + contHTML + secs + '<div style="height:16px"></div>';
      bindProv();
      autoHero();
    };
    var bindProv = function () {
      $view.querySelectorAll('[data-prov]').forEach(function (b) {
        b.onclick = function () { S.prov = b.getAttribute('data-prov'); renderHome(); };
      });
    };
    var cached = S.homeCache[S.prov];
    if (cached && Date.now() - cached.t < 120000) return draw(cached.j);
    $view.innerHTML = head + '<div class="center-load"><div class="spinner"></div></div>';
    bindProv();
    api('home', { p: S.prov }).then(function (j) {
      if (j.ok) S.homeCache[S.prov] = { t: Date.now(), j: j };
      if (parseHash().parts.length === 0) draw(j);
    });
  }

  function autoHero() {
    var hero = document.getElementById('hero'), dots = document.getElementById('dots');
    if (!hero) return;
    var sync = function () {
      var i = Math.round(hero.scrollLeft / hero.clientWidth);
      Array.prototype.forEach.call(dots.children, function (d, k) { d.classList.toggle('on', k === i); });
    };
    hero.addEventListener('scroll', function () { clearTimeout(hero._t); hero._t = setTimeout(sync, 80); });
    S.timers.push(setInterval(function () {
      var n = hero.children.length; if (n < 2) return;
      var i = Math.round(hero.scrollLeft / hero.clientWidth);
      hero.scrollTo({ left: ((i + 1) % n) * hero.clientWidth, behavior: 'smooth' });
    }, 5000));
  }

  /* ---------------- cari ---------------- */
  function renderSearch(q0) {
    var st = { q: q0, type: 0, page: 1, items: [], more: false, busy: false };
    $view.innerHTML = '<div style="padding:16px;display:flex;flex-direction:column;gap:12px">' +
      '<label class="searchbar" style="margin:0;border:1px solid var(--accent)">' + ICON.search +
      '<input class="search" id="q" type="search" placeholder="Judul film, drama, anime…" autocomplete="off" enterkeyhint="search"></label>' +
      '<div class="chips" style="padding:0" id="types"><button class="chip on" data-t="0">Semua</button><button class="chip" data-t="1">Film</button><button class="chip" data-t="2">Series</button></div>' +
      '<span class="muted" id="info" style="font-size:12px"></span></div><div class="grid3" id="res"></div><div class="pad" id="moreBox" style="margin-top:16px"></div>';
    var $q = document.getElementById('q'), $res = document.getElementById('res'), $info = document.getElementById('info'), $more = document.getElementById('moreBox');
    $q.value = q0;
    var run = function (reset) {
      var kw = $q.value.trim();
      if (reset) { st.page = 1; st.items = []; }
      if (kw.length < 2) { $res.innerHTML = ''; $more.innerHTML = ''; $info.textContent = 'Ketik minimal 2 huruf.'; return; }
      st.busy = true;
      if (reset) $res.innerHTML = '<div style="grid-column:1/-1" class="center-load"><div class="spinner"></div></div>';
      var stamp = st.stamp = Date.now();
      api('search', { p: S.prov, q: kw, type: st.type, page: st.page }).then(function (j) {
        if (stamp !== st.stamp) return;
        st.busy = false;
        if (!j.ok) { $res.innerHTML = '<div style="grid-column:1/-1">' + errBox(j.error) + '</div>'; return; }
        st.items = st.items.concat(j.data.items); st.more = j.data.has_more;
        $info.textContent = st.items.length ? (j.data.total + ' hasil untuk “' + kw + '”') : '';
        $res.innerHTML = st.items.length ? st.items.map(function (it) { return posterHTML(S.prov, it); }).join('')
          : '<div class="empty" style="grid-column:1/-1">Tidak ditemukan. Coba judul lain.</div>';
        $more.innerHTML = st.more ? '<button class="btn btn-ghost btn-block" id="more">Muat lagi</button>' : '';
        if (st.more) document.getElementById('more').onclick = function () { st.page++; this.disabled = true; this.textContent = 'Memuat…'; run(false); };
        history.replaceState(null, '', '#/search?q=' + enc(kw));
      });
    };
    var deb;
    $q.oninput = function () { clearTimeout(deb); deb = setTimeout(function () { run(true); }, 550); };
    $q.onkeydown = function (e) { if (e.key === 'Enter') { clearTimeout(deb); $q.blur(); run(true); } };
    document.getElementById('types').querySelectorAll('[data-t]').forEach(function (b) {
      b.onclick = function () {
        st.type = +b.getAttribute('data-t');
        this.parentNode.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('on', c === b); });
        run(true);
      };
    });
    if (q0) run(true); else { $info.textContent = ''; setTimeout(function () { $q.focus(); }, 300); }
  }

  /* ---------------- detail ---------------- */
  function loadDetail(p, id, path) {
    var k = p + '|' + id;
    var c = S.detailCache[k];
    if (c && Date.now() - c.t < 300000) return Promise.resolve(c.j);
    return api('detail', { p: p, id: id, path: path }).then(function (j) {
      if (j.ok) { S.detailCache[k] = { t: Date.now(), j: j }; S.boot.user = j.user; }
      return j;
    });
  }
  function canWatch(d, se, ep) {
    if (S.boot.user.vip) return true;
    if (d.type === 1) return S.boot.app.free_minutes > 0;
    return d.free.indexOf(se + ':' + ep) >= 0;
  }
  function epsOf(d) {
    var out = [];
    d.seasons.forEach(function (s) { s.episodes.forEach(function (e) { out.push({ se: s.season, ep: e }); }); });
    return out;
  }

  function renderDetail(p, id, path) {
    loading();
    loadDetail(p, id, path).then(function (j) {
      if (!j.ok) { $view.innerHTML = '<div style="padding-top:64px"></div>' + errBox(j.error); return; }
      var d = j.data, vip = S.boot.user.vip;
      var eps = epsOf(d);
      var h = d.history;
      var start = h && (d.type === 1 || eps.some(function (e) { return e.se === +h.se && e.ep === +h.ep; })) ? { se: +h.se, ep: +h.ep } : (eps[0] || { se: 0, ep: 0 });
      var playLabel = d.type === 1
        ? (vip ? 'Tonton Film' : (S.boot.app.free_minutes > 0 ? 'Tonton · Gratis ' + S.boot.app.free_minutes + ' menit' : 'Khusus VIP'))
        : (h ? 'Lanjut Eps ' + start.ep : 'Tonton Eps ' + start.ep + (canWatch(d, start.se, start.ep) && !vip ? ' · Gratis' : ''));
      var playHref = canWatch(d, start.se, start.ep) ? watchHref(p, d.id, d.path, start.se, start.ep) : '#/vip';
      var meta = [d.rating ? '<span class="star">★ ' + d.rating + '</span>' : '', d.year, d.country, d.type === 2 ? 'Series' : 'Film'].filter(Boolean).join('<span>·</span>');
      var genres = (d.genre || '').split(',').map(function (g) { return g.trim(); }).filter(Boolean).map(function (g) { return '<span>' + esc(g) + '</span>'; }).join('');
      var html = '<div class="backdrop">' + img(d.cover, d.title) + '<div class="shade"></div><button class="backbtn" id="bk" aria-label="Kembali">' + ICON.back + '</button></div>' +
        '<div class="dhead"><h1 class="h1">' + esc(d.title) + '</h1><div class="meta">' + meta + '</div>' +
        (genres ? '<div class="genres">' + genres + '</div>' : '') +
        '<div class="actions"><a class="btn btn-accent" href="' + playHref + '">' + (playHref === '#/vip' ? ICON.lock : ICON.play) + esc(playLabel) + '</a>' +
        '<button class="favbtn' + (d.is_fav ? ' on' : '') + '" id="fav" aria-label="Favorit">' + ICON.heart + '</button></div>' +
        (d.description ? '<p class="desc clamp" id="desc">' + esc(d.description) + '</p><button class="more" id="moreDesc">Selengkapnya</button>' : '') + '</div>';

      if (d.type === 2) {
        var seasons = d.seasons.filter(function (s) { return s.episodes.length; });
        var curSe = start.se;
        html += '<div class="pad" style="margin-top:20px;display:flex;flex-direction:column;gap:12px">' +
          '<div class="row between"><span class="h2">Episode</span><span class="muted" style="font-size:12px">' + eps.length + ' episode</span></div>' +
          (seasons.length > 1 ? '<div class="chips" style="padding:0">' + seasons.map(function (s) {
            return '<button class="chip' + (s.season === curSe ? ' on' : '') + '" data-se="' + s.season + '">Season ' + s.season + '</button>';
          }).join('') + '</div>' : '') +
          '<div class="eps" id="eps"></div>' +
          (!vip ? '<a class="vipcta" href="#/vip"><span class="ic">' + ICON.crown + '</span><span style="flex:1;display:flex;flex-direction:column;gap:2px"><b style="font-size:13px">Buka semua episode</b><span class="muted" style="font-size:12px">Jadi VIP, bayar QRIS, langsung aktif</span></span>' + ICON.chev + '</a>' : '') +
          '</div>';
        $view.innerHTML = html;
        var drawEps = function (se) {
          var s = seasons.filter(function (x) { return x.season === se; })[0] || seasons[0];
          if (!s) { document.getElementById('eps').innerHTML = '<span class="muted">Belum ada episode.</span>'; return; }
          document.getElementById('eps').innerHTML = s.episodes.map(function (e) {
            var free = d.free.indexOf(s.season + ':' + e) >= 0, ok = vip || free;
            var cur = h && +h.se === s.season && +h.ep === e;
            return '<a class="ep' + (cur ? ' cur' : '') + (free && !vip ? ' free' : '') + '" href="' + (ok ? watchHref(p, d.id, d.path, s.season, e) : '#/vip') + '">' + e +
              (!ok ? ICON.lock : (free && !vip ? '<small>GRATIS</small>' : '')) + '</a>';
          }).join('');
        };
        drawEps(curSe);
        $view.querySelectorAll('[data-se]').forEach(function (b) {
          b.onclick = function () {
            $view.querySelectorAll('[data-se]').forEach(function (c) { c.classList.toggle('on', c === b); });
            drawEps(+b.getAttribute('data-se'));
          };
        });
      } else {
        $view.innerHTML = html;
      }
      document.getElementById('bk').onclick = function () { history.length > 1 ? history.back() : (location.hash = '#/'); };
      var md = document.getElementById('moreDesc');
      if (md) md.onclick = function () { document.getElementById('desc').classList.remove('clamp'); md.remove(); };
      document.getElementById('fav').onclick = function () {
        var b = this; haptic();
        api('fav', { p: p, id: d.id, path: d.path, title: d.title, cover: d.cover, type: d.type, eps: eps.length }, 'POST').then(function (r) {
          if (!r.ok) return toast(r.error);
          d.is_fav = r.is_fav; b.classList.toggle('on', r.is_fav);
          toast(r.is_fav ? (d.type === 2 ? 'Disimpan. Kamu akan dikabari episode baru.' : 'Disimpan ke favorit.') : 'Dihapus dari favorit.');
        });
      };
    });
  }

  /* ---------------- tonton ---------------- */
  function renderWatch(p, id, path, se, ep) {
    loading();
    loadDetail(p, id, path).then(function (dj) {
      if (!dj.ok) { $view.innerHTML = errBox(dj.error); return; }
      var d = dj.data, eps = epsOf(d);
      var idx = d.type === 1 ? 0 : eps.findIndex(function (e) { return e.se === se && e.ep === ep; });
      var prev = d.type === 2 && idx > 0 ? eps[idx - 1] : null;
      var next = d.type === 2 && idx >= 0 && idx < eps.length - 1 ? eps[idx + 1] : null;

      $view.innerHTML = playerHTML(d.cover) +
        '<div style="padding:16px;display:flex;flex-direction:column;gap:16px">' +
        '<div><div class="h2" style="font-size:18px;font-weight:800">' + esc(d.title) + '</div><div class="muted" style="margin-top:4px;font-size:13px">' +
        (d.type === 1 ? 'Film' : 'Season ' + se + ' · Episode ' + ep) + '</div></div>' +
        '<div id="opts"><div class="center-load" style="min-height:80px"><div class="spinner"></div></div></div>' +
        (d.type === 2 ? '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">' +
          (prev ? '<a class="btn btn-ghost" href="' + (canWatch(d, prev.se, prev.ep) ? watchHref(p, id, path, prev.se, prev.ep) : '#/vip') + '">‹ Eps ' + prev.ep + '</a>' : '<span></span>') +
          (next ? '<a class="btn btn-accent" href="' + (canWatch(d, next.se, next.ep) ? watchHref(p, id, path, next.se, next.ep) : '#/vip') + '">Eps ' + next.ep + ' ›</a>' : '<span></span>') + '</div>' : '') +
        '<a class="row" href="' + detailHref(p, d) + '" style="color:var(--muted);font-size:13px">' + ICON.chev + 'Lihat detail & semua episode</a></div>';

      var vid = document.getElementById('vid'), pl = document.getElementById('pl'), opts = document.getElementById('opts');
      api('play', { p: p, id: id, path: path, se: se, ep: ep }).then(function (j) {
        if (!j.ok) {
          if (j.gate) { S.boot.gate = j.gate; return route(); }
          var over = document.createElement('div'); over.className = 'over';
          over.innerHTML = j.need_vip
            ? '<div style="font-weight:800;font-size:16px">Khusus VIP</div><div class="muted" style="font-size:13px">' + esc(j.error) + '</div><a class="btn btn-accent" href="#/vip">' + ICON.crown + 'Jadi VIP</a>'
            : '<div class="muted" style="font-size:13px">' + esc(j.error) + '</div><button class="btn btn-ghost" id="retry">Coba lagi</button>';
          pl.appendChild(over); opts.innerHTML = '';
          var rt = document.getElementById('retry'); if (rt) rt.onclick = function () { renderWatch(p, id, path, se, ep); };
          return;
        }
        setupPlayer(p, d, j.data, vid, pl, opts, next);
      });
    });
  }

  function setupPlayer(p, d, data, vid, pl, opts, next) {
    var srcs = data.sources, subs = data.subtitles, preview = data.preview;
    var hist = d.history;
    var resume = 0;
    if (hist && +hist.se === data.se && +hist.ep === data.ep && hist.position > 10 && (!hist.duration || hist.position < hist.duration - 30)) resume = +hist.position;
    if (preview && resume >= preview) resume = 0;
    var cur = { q: 0, sub: subs.length ? subs[0].lang : '', useProxy: false };
    var lastSave = 0;

    var load = function (keepTime) {
      var t = keepTime ? vid.currentTime : resume;
      var s = srcs[cur.q];
      vid.src = cur.useProxy && s.proxy ? s.proxy : s.url;
      vid.querySelectorAll('track').forEach(function (tr) { tr.remove(); });
      subs.forEach(function (sb) {
        var tr = document.createElement('track');
        tr.kind = 'subtitles'; tr.label = sb.label; tr.srclang = sb.lang === 'in_id' ? 'id' : sb.lang.slice(0, 3); tr.src = sb.url;
        if (sb.lang === cur.sub) tr.default = true;
        vid.appendChild(tr);
      });
      vid.addEventListener('loadedmetadata', function once() {
        vid.removeEventListener('loadedmetadata', once);
        if (t > 0) vid.currentTime = t;
        showSub();
      });
      vid.load();
      var pr = vid.play(); if (pr && pr.catch) pr.catch(function () {});
    };
    var showSub = function () {
      Array.prototype.forEach.call(vid.textTracks, function (tt, i) { tt.mode = subs[i] && subs[i].lang === cur.sub ? 'showing' : 'disabled'; });
    };
    vid.addEventListener('error', function () {
      if (!cur.useProxy && srcs[cur.q].proxy) { cur.useProxy = true; load(true); }
      else toast('Video gagal dimuat. Coba kualitas lain.');
    });

    var save = function (force) {
      if (!vid.duration || isNaN(vid.duration)) return;
      var now = Date.now();
      if (!force && now - lastSave < 15000) return;
      lastSave = now;
      api('progress', { p: p, id: d.id, path: d.path, title: d.title, cover: d.cover, type: d.type, se: data.se, ep: data.ep,
        pos: Math.floor(vid.currentTime), dur: Math.floor(vid.duration) }, 'POST');
      d.history = { se: data.se, ep: data.ep, position: Math.floor(vid.currentTime), duration: Math.floor(vid.duration) };
    };
    vid.addEventListener('timeupdate', function () {
      save(false);
      if (preview && vid.currentTime >= preview) {
        vid.pause(); vid.currentTime = preview;
        if (!pl.querySelector('.over')) {
          if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
          if (vid.webkitExitFullscreen) try { vid.webkitExitFullscreen(); } catch (e) {}
          var o = document.createElement('div'); o.className = 'over';
          o.innerHTML = '<div style="font-weight:800;font-size:16px">Waktu gratis habis</div><div class="muted" style="font-size:13px">Lanjutkan film ini dengan VIP.</div>' +
            '<a class="btn btn-accent" href="#/vip">' + ICON.crown + 'Jadi VIP</a>';
          pl.appendChild(o);
        }
      }
    });
    vid.addEventListener('seeking', function () { if (preview && vid.currentTime > preview) vid.currentTime = Math.max(0, preview - 1); });
    vid.addEventListener('pause', function () { save(true); });
    vid.addEventListener('ended', function () {
      save(true);
      if (next && canWatch(d, next.se, next.ep)) { toast('Lanjut ke episode ' + next.ep + '…'); location.hash = watchHref(p, d.id, d.path, next.se, next.ep); }
    });

    var drawOpts = function () {
      opts.innerHTML = (preview ? '<div class="card" style="font-size:13px;line-height:1.5">Mode gratis: bisa ditonton <b>' + Math.round(preview / 60) + ' menit</b> pertama. <a href="#/vip" style="color:var(--accent);font-weight:700">Jadi VIP</a> untuk menonton penuh.</div><div style="height:16px"></div>' : '') +
        '<div class="label">Kualitas</div><div class="pills">' + srcs.map(function (s, i) {
          return '<button class="pill' + (i === cur.q ? ' on' : '') + '" data-q="' + i + '">' + (s.res ? s.res + 'p' : 'Auto') + '</button>';
        }).join('') + '</div>' +
        (subs.length ? '<div class="label" style="margin-top:16px">Subtitle</div><div class="pills">' + subs.slice(0, 6).map(function (s) {
          return '<button class="pill' + (s.lang === cur.sub ? ' on' : '') + '" data-sub="' + esc(s.lang) + '">' + esc(s.label) + '</button>';
        }).join('') + '<button class="pill' + (cur.sub === '' ? ' on' : '') + '" data-sub="">Mati</button></div>' : '');
      opts.querySelectorAll('[data-q]').forEach(function (b) {
        b.onclick = function () { cur.q = +b.getAttribute('data-q'); cur.useProxy = false; drawOpts(); load(true); };
      });
      opts.querySelectorAll('[data-sub]').forEach(function (b) {
        b.onclick = function () { cur.sub = b.getAttribute('data-sub'); drawOpts(); showSub(); };
      });
    };
    drawOpts();
    bindControls(vid, pl, preview);
    load(false);
  }

  /* ---------------- pemutar kustom (tanpa tombol download) ---------------- */
  var PI = {
    play: '<svg viewBox="0 0 24 24" style="fill:currentColor;stroke:none"><path d="M8 5l12 7-12 7z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" style="fill:currentColor;stroke:none"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>',
    back10: '<svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
    fwd10: '<svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>',
    fs: '<svg viewBox="0 0 24 24"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
    fsx: '<svg viewBox="0 0 24 24"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg>'
  };
  var SPEEDS = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
  function savedRate() {
    var r = 1;
    try { r = parseFloat(localStorage.getItem('rate')) || 1; } catch (e) {}
    return SPEEDS.indexOf(r) >= 0 ? r : 1;
  }
  function playerHTML(poster) {
    return '<div class="player" id="pl">' +
      '<video id="vid" playsinline webkit-playsinline preload="metadata" disablepictureinpicture disableremoteplayback ' +
      'controlslist="nodownload noplaybackrate noremoteplayback" poster="' + esc(poster) + '"></video>' +
      '<div class="pc" id="pc">' +
        '<div class="pc-spin" id="pcSpin"><div class="spinner"></div></div>' +
        '<div class="pc-mid">' +
          '<button class="pc-skip" data-skip="-10" aria-label="Mundur 10 detik">' + PI.back10 + '<b>10</b></button>' +
          '<button class="pc-play" id="pcPlay" aria-label="Putar">' + PI.play + '</button>' +
          '<button class="pc-skip" data-skip="10" aria-label="Maju 10 detik">' + PI.fwd10 + '<b>10</b></button>' +
        '</div>' +
        '<div class="pc-bot">' +
          '<span class="pc-time" id="pcTime">0:00</span>' +
          '<div class="pc-bar" id="pcBar"><div class="pc-track"><i class="pc-buf" id="pcBuf"></i><i class="pc-fill" id="pcFill"></i><i class="pc-lim" id="pcLim" hidden></i></div><span class="pc-knob" id="pcKnob"></span></div>' +
          '<span class="pc-time" id="pcDur">0:00</span>' +
          '<button class="pc-speed" id="pcSpeed" aria-label="Kecepatan pemutaran">1x</button>' +
          '<button class="pc-fs" id="pcFs" aria-label="Layar penuh">' + PI.fs + '</button>' +
        '</div>' +
      '</div>' +
      '<div class="pc-fast" id="pcFast" hidden>2x <svg viewBox="0 0 24 24" style="fill:currentColor;stroke:none"><path d="M4 6l8 6-8 6zM12 6l8 6-8 6z"/></svg></div>' +
      '<div class="pc-menu" id="pcMenu" hidden><div class="pc-menu-h">Kecepatan pemutaran</div>' +
        SPEEDS.map(function (s) { return '<button data-rate="' + s + '">' + s + 'x</button>'; }).join('') +
      '</div></div>';
  }
  function fmtTime(s) {
    s = Math.max(0, Math.floor(s || 0));
    var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
    return (h ? h + ':' + ('0' + m).slice(-2) : m) + ':' + ('0' + x).slice(-2);
  }
  function bindControls(vid, pl, preview) {
    var $ = function (id) { return document.getElementById(id); };
    var play = $('pcPlay'), bar = $('pcBar'), fill = $('pcFill'), buf = $('pcBuf'), knob = $('pcKnob'), lim = $('pcLim');
    var tCur = $('pcTime'), tDur = $('pcDur'), fsBtn = $('pcFs'), spin = $('pcSpin');
    var hideT, dragging = false;

    pl.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    vid.addEventListener('contextmenu', function (e) { e.preventDefault(); });

    var show = function () {
      pl.classList.add('show');
      clearTimeout(hideT);
      if (!vid.paused) hideT = setTimeout(function () { if (!dragging) pl.classList.remove('show'); }, 3000);
    };
    var toggle = function () { if (vid.paused) { var p = vid.play(); if (p && p.catch) p.catch(function () {}); } else vid.pause(); };
    var dur = function () { return vid.duration && isFinite(vid.duration) ? vid.duration : 0; };
    var maxTime = function () { return preview ? Math.min(preview, dur() || preview) : dur(); };
    var paint = function (t) {
      var d = dur(); if (!d) return;
      var pct = Math.min(100, t / d * 100);
      fill.style.width = pct + '%'; knob.style.left = pct + '%';
      tCur.textContent = fmtTime(t);
    };

    play.onclick = function (e) { e.stopPropagation(); toggle(); show(); };
    Array.prototype.forEach.call(pl.querySelectorAll('[data-skip]'), function (b) {
      b.onclick = function (e) {
        e.stopPropagation();
        var t = Math.max(0, Math.min(maxTime() || 1e9, vid.currentTime + (+b.getAttribute('data-skip'))));
        vid.currentTime = t; paint(t); show(); haptic();
      };
    });
    // Kecepatan pemutaran (diingat di perangkat ini)
    var rate = savedRate();
    var speedBtn = $('pcSpeed'), menu = $('pcMenu'), fast = $('pcFast');
    var applyRate = function () {
      vid.defaultPlaybackRate = rate; vid.playbackRate = rate;
      speedBtn.textContent = rate === 1 ? '1x' : rate + 'x';
      Array.prototype.forEach.call(menu.querySelectorAll('[data-rate]'), function (b) { b.classList.toggle('on', +b.getAttribute('data-rate') === rate); });
    };
    applyRate();
    vid.addEventListener('loadedmetadata', function () { if (!holding) vid.playbackRate = rate; });
    speedBtn.onclick = function (e) { e.stopPropagation(); menu.hidden = !menu.hidden; clearTimeout(hideT); pl.classList.add('show'); };
    Array.prototype.forEach.call(menu.querySelectorAll('[data-rate]'), function (b) {
      b.onclick = function (e) {
        e.stopPropagation();
        rate = +b.getAttribute('data-rate');
        try { localStorage.setItem('rate', String(rate)); } catch (x) {}
        applyRate(); menu.hidden = true; show(); haptic();
      };
    });
    menu.addEventListener('click', function (e) { e.stopPropagation(); });

    // Tekan lama di video = 2x selama ditahan (seperti YouTube)
    var holdT, holding = false, suppressClick = false;
    var startHold = function (e) {
      if (e.target.closest('button') || e.target.closest('.pc-bar') || e.target.closest('.pc-menu')) return;
      clearTimeout(holdT);
      holdT = setTimeout(function () {
        if (vid.paused) return;
        holding = true; suppressClick = true;
        vid.playbackRate = 2; fast.hidden = false;
        pl.classList.remove('show'); haptic('medium');
      }, 450);
    };
    var endHold = function () {
      clearTimeout(holdT);
      if (!holding) return;
      holding = false; vid.playbackRate = rate; fast.hidden = true;
    };
    $('pc').addEventListener('pointerdown', startHold);
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { $('pc').addEventListener(ev, endHold); });
    $('pc').addEventListener('pointermove', function (e) {
      // geser jari (scroll) membatalkan tekan lama yang belum aktif
      if (!holding && (Math.abs(e.movementX) > 6 || Math.abs(e.movementY) > 6)) clearTimeout(holdT);
    });

    // Ketuk area video: tampilkan/sembunyikan kontrol; ketuk dua kali kiri/kanan = mundur/maju 10 dtk
    var lastTap = 0;
    $('pc').addEventListener('click', function (e) {
      if (suppressClick) { suppressClick = false; return; }
      if (!menu.hidden) { menu.hidden = true; return; }
      if (e.target.closest('button') || e.target.closest('.pc-bar')) return;
      var now = Date.now(), r = pl.getBoundingClientRect(), x = (e.clientX - r.left) / r.width;
      if (now - lastTap < 300 && (x < 0.35 || x > 0.65)) {
        var t = Math.max(0, Math.min(maxTime() || 1e9, vid.currentTime + (x < 0.5 ? -10 : 10)));
        vid.currentTime = t; paint(t); show(); lastTap = 0; return;
      }
      lastTap = now;
      if (pl.classList.contains('show') && !vid.paused) { pl.classList.remove('show'); clearTimeout(hideT); } else show();
    });

    // Seek bar (sentuh & geser)
    var ratioAt = function (clientX) { var r = bar.getBoundingClientRect(); return Math.max(0, Math.min(1, (clientX - r.left) / r.width)); };
    var seekTo = function (clientX, commit) {
      var d = dur(); if (!d) return;
      var t = ratioAt(clientX) * d;
      if (preview && t > preview) t = Math.max(0, preview - 1);
      paint(t);
      if (commit) vid.currentTime = t;
    };
    bar.addEventListener('pointerdown', function (e) {
      dragging = true; try { bar.setPointerCapture(e.pointerId); } catch (x) {} pl.classList.add('drag'); seekTo(e.clientX, false); show();
    });
    bar.addEventListener('pointermove', function (e) { if (dragging) seekTo(e.clientX, false); });
    var end = function (e) { if (!dragging) return; dragging = false; pl.classList.remove('drag'); seekTo(e.clientX, true); show(); };
    bar.addEventListener('pointerup', end);
    bar.addEventListener('pointercancel', end);

    vid.addEventListener('loadedmetadata', function () {
      tDur.textContent = fmtTime(dur());
      if (preview && dur() > preview) { lim.hidden = false; lim.style.left = (preview / dur() * 100) + '%'; }
    });
    vid.addEventListener('timeupdate', function () { if (!dragging) paint(vid.currentTime); });
    vid.addEventListener('progress', function () {
      var d = dur(); if (!d || !vid.buffered.length) return;
      buf.style.width = Math.min(100, vid.buffered.end(vid.buffered.length - 1) / d * 100) + '%';
    });
    vid.addEventListener('play', function () { play.innerHTML = PI.pause; play.setAttribute('aria-label', 'Jeda'); show(); });
    vid.addEventListener('pause', function () { play.innerHTML = PI.play; play.setAttribute('aria-label', 'Putar'); pl.classList.add('show'); clearTimeout(hideT); });
    vid.addEventListener('waiting', function () { spin.classList.add('on'); });
    ['playing', 'canplay', 'pause', 'error'].forEach(function (ev) { vid.addEventListener(ev, function () { spin.classList.remove('on'); }); });

    // Layar penuh: seluruh kotak pemutar (supaya kontrol kustom ikut), putar ke landscape bila bisa
    var isFs = function () { return document.fullscreenElement || document.webkitFullscreenElement; };
    fsBtn.onclick = function (e) {
      e.stopPropagation();
      if (isFs()) {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        return;
      }
      var req = pl.requestFullscreen || pl.webkitRequestFullscreen;
      if (req) {
        var p = req.call(pl);
        var lock = function () { try { screen.orientation.lock('landscape').catch(function () {}); } catch (x) {} };
        if (p && p.then) p.then(lock).catch(function () {}); else lock();
      } else if (vid.webkitEnterFullscreen) {
        vid.webkitEnterFullscreen(); // iPhone: hanya layar penuh bawaan
      }
    };
    var onFs = function () {
      fsBtn.innerHTML = isFs() ? PI.fsx : PI.fs;
      if (!isFs()) { try { screen.orientation.unlock(); } catch (x) {} }
    };
    document.addEventListener('fullscreenchange', onFs);
    document.addEventListener('webkitfullscreenchange', onFs);

    pl.classList.add('show');
  }

  /* ---------------- VIP ---------------- */
  function renderVip() {
    loading();
    api('plans').then(function (j) {
      if (!j.ok) { $view.innerHTML = errBox(j.error); return; }
      S.boot.user = j.user;
      var u = j.user, plans = j.plans, sel = plans.length ? plans[Math.min(1, plans.length - 1)].id : 0;
      var draw = function () {
        $view.innerHTML = '<div style="padding:16px;display:flex;flex-direction:column;gap:14px">' +
          '<div class="row"><button class="backbtn" style="position:static;background:var(--surface)" id="bk">' + ICON.back + '</button><span class="h1" style="font-size:18px">Jadi VIP</span></div>' +
          (u.vip ? '<div class="card" style="border:1px solid var(--accent)"><b>VIP aktif</b><div class="muted" style="font-size:12px;margin-top:4px">Sampai ' + fmtDate(u.vip_until) + '. Beli lagi untuk memperpanjang.</div></div>' : '') +
          '<div class="card benefit"><span>✓&nbsp; Semua episode terbuka</span><span>✓&nbsp; Film ditonton penuh</span><span>✓&nbsp; Kualitas sampai 720p</span></div>' +
          '<span class="h2" style="font-size:14px">Pilih paket</span>' +
          (plans.length ? plans.map(function (pl) {
            return '<button class="plan' + (pl.id == sel ? ' on' : '') + '" data-plan="' + pl.id + '"><span class="radio"></span>' +
              '<span style="flex:1;display:flex;flex-direction:column;gap:2px"><b>' + esc(pl.name) + '</b><span class="muted" style="font-size:12px">' +
              esc(pl.label || (pl.days + ' hari')) + '</span></span><b>' + rupiah(pl.price) + '</b></button>';
          }).join('') : '<div class="empty">Paket belum tersedia.</div>') +
          (plans.length ? '<button class="btn btn-accent btn-block" id="pay" style="margin-top:4px">Bayar dengan QRIS</button>' : '') + '</div>';
        $view.querySelectorAll('[data-plan]').forEach(function (b) { b.onclick = function () { sel = +b.getAttribute('data-plan'); haptic(); draw(); }; });
        document.getElementById('bk').onclick = function () { history.length > 1 ? history.back() : (location.hash = '#/'); };
        var pb = document.getElementById('pay');
        if (pb) pb.onclick = function () {
          pb.disabled = true; pb.textContent = 'Membuat tagihan…';
          api('pay', { plan: sel }, 'POST').then(function (r) {
            pb.disabled = false; pb.textContent = 'Bayar dengan QRIS';
            if (!r.ok) return toast(r.error);
            paySheet(r.payment);
          });
        };
      };
      draw();
    });
  }

  function paySheet(pay) {
    var bg = document.createElement('div'); bg.className = 'sheet-bg';
    bg.innerHTML = '<div class="sheet" id="sheet"></div>';
    document.body.appendChild(bg);
    var sh = bg.querySelector('#sheet');
    var poll, tick;
    var close = function () { clearInterval(poll); clearInterval(tick); bg.remove(); };
    var drawPending = function () {
      sh.innerHTML = '<span class="grab"></span><b style="font-size:15px">Bayar dengan QRIS</b><div class="qrbox" id="qr"></div>' +
        '<span style="font-size:12px;color:#475569">Total bayar (sudah termasuk kode unik ' + pay.unique_code + ')</span>' +
        '<span class="amount">' + rupiah(pay.amount) + '</span><span class="warn" id="cd"></span>' +
        '<span class="note">Bayar <b>tepat</b> sesuai nominal di atas pakai e-wallet atau m-banking apa saja.<br>VIP aktif otomatis setelah pembayaran masuk.</span>' +
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%;margin-top:6px"><button class="btn btn-line" id="cancel">Batalkan</button>' +
        '<button class="btn" style="background:#065AD8;color:#fff" id="check">Cek status</button></div>';
      try { new QRCode(document.getElementById('qr'), { text: pay.qris, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M }); }
      catch (e) { document.getElementById('qr').textContent = 'QR gagal dibuat.'; }
      var cd = document.getElementById('cd');
      var upd = function () {
        var s = pay.expires_at - Math.floor(Date.now() / 1000);
        cd.textContent = s > 0 ? 'Bayar sebelum ' + Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2) : 'Waktu habis — sedang memastikan pembayaran…';
      };
      upd(); tick = setInterval(upd, 1000); S.timers.push(tick);
      document.getElementById('cancel').onclick = function () {
        if (!confirm('Batalkan tagihan ini? Jangan batalkan bila sudah membayar.')) return;
        api('pay_cancel', { ref: pay.ref }, 'POST'); close();
      };
      document.getElementById('check').onclick = function () { check(true); };
    };
    var check = function (manual) {
      api('pay_status', { ref: pay.ref }).then(function (j) {
        if (!j.ok) return manual && toast(j.error);
        pay = j.payment; S.boot.user = j.user;
        if (pay.status === 'paid') {
          clearInterval(poll); clearInterval(tick); haptic('heavy');
          S.detailCache = {};
          sh.innerHTML = '<span class="grab"></span><div class="paid-ok">' + ICON.check + '</div><b style="font-size:18px">Pembayaran berhasil!</b>' +
            '<span class="note" style="font-size:13px">VIP aktif sampai ' + fmtDate(j.user.vip_until) + '.</span>' +
            '<button class="btn btn-block" style="background:#065AD8;color:#fff" id="done">Mulai nonton</button>';
          document.getElementById('done').onclick = function () { close(); history.length > 2 ? history.go(-1) : (location.hash = '#/'); };
        } else if (pay.status !== 'pending') {
          clearInterval(poll); clearInterval(tick);
          sh.innerHTML = '<span class="grab"></span><b style="font-size:16px">Tagihan ' + (pay.status === 'expired' ? 'kedaluwarsa' : 'dibatalkan') + '</b>' +
            '<span class="note">Bila kamu sudah terlanjur membayar, hubungi admin dengan kode <b>' + esc(pay.ref) + '</b>.</span>' +
            '<button class="btn btn-line btn-block" id="cl">Tutup</button>';
          document.getElementById('cl').onclick = close;
        } else if (manual) toast('Belum terdeteksi. Tunggu sebentar setelah membayar.');
      });
    };
    bg.onclick = function (e) { if (e.target === bg) toast('Tekan Batalkan untuk menutup.'); };
    drawPending();
    poll = setInterval(function () { if (document.body.contains(bg)) check(false); else clearInterval(poll); }, 6000);
    S.timers.push(poll);
  }

  /* ---------------- profil ---------------- */
  function renderMe(tab) {
    var u = S.boot.user;
    var initial = (u.name || '?').trim().charAt(0).toUpperCase();
    $view.innerHTML = '<div class="row" style="padding:20px 16px 12px;gap:14px"><div class="avatar">' + esc(initial) + '</div>' +
      '<div style="display:flex;flex-direction:column;gap:3px"><b style="font-size:17px">' + esc(u.name) + '</b><span class="muted" style="font-size:12px">' +
      (u.username ? '@' + esc(u.username) + ' · ' : '') + 'ID ' + u.id + '</span></div></div>' +
      '<div class="pad"><div class="card row between" style="border:1px solid ' + (u.vip ? 'var(--accent)' : 'var(--line)') + '"><div style="display:flex;flex-direction:column;gap:3px">' +
      '<b>' + (u.vip ? 'VIP aktif' : 'Akun gratis') + '</b><span class="muted" style="font-size:12px">' + (u.vip ? 'Sampai ' + fmtDate(u.vip_until) : 'Episode 1 & cuplikan film gratis') + '</span></div>' +
      '<a class="btn btn-accent" style="height:36px;font-size:13px" href="#/vip">' + (u.vip ? 'Perpanjang' : 'Jadi VIP') + '</a></div>' +
      '<div class="card row between" style="margin-top:10px;background:var(--surface2)"><span style="font-size:13px">Notifikasi episode baru</span><button class="switch' + (u.notify ? ' on' : '') + '" id="notif" aria-label="Notifikasi"></button></div></div>' +
      '<div class="tabs" style="margin-top:18px"><button data-tab="hist" class="' + (tab === 'hist' ? 'on' : '') + '">Riwayat</button><button data-tab="fav" class="' + (tab === 'fav' ? 'on' : '') + '">Favorit</button></div>' +
      '<div id="list" style="padding-top:8px"><div class="center-load" style="min-height:120px"><div class="spinner"></div></div></div>';
    document.getElementById('notif').onclick = function () {
      var b = this, on = !b.classList.contains('on');
      b.classList.toggle('on', on);
      api('notify', { on: on ? 1 : 0 }, 'POST').then(function (r) { if (r.ok) { S.boot.user.notify = on; toast(on ? 'Notifikasi aktif.' : 'Notifikasi dimatikan.'); } });
    };
    $view.querySelectorAll('.tabs [data-tab]').forEach(function (b) {
      b.onclick = function () { location.replace(b.getAttribute('data-tab') === 'fav' ? '#/me/fav' : '#/me'); };
    });
    var $l = document.getElementById('list');
    api(tab === 'fav' ? 'favorites' : 'history').then(function (j) {
      if (!j.ok) { $l.innerHTML = errBox(j.error); return; }
      if (!j.items.length) { $l.innerHTML = '<div class="empty">' + (tab === 'fav' ? 'Belum ada favorit. Tekan ♡ di halaman judul.' : 'Belum ada riwayat tontonan.') + '</div>'; return; }
      $l.innerHTML = j.items.map(function (it) {
        var href = tab === 'fav' ? detailHref(it.provider, it) : watchHref(it.provider, it.id, it.path, it.se, it.ep);
        var pct = it.duration ? Math.min(100, Math.round(it.position / it.duration * 100)) : 0;
        return '<a class="hitem" href="' + href + '"><div class="img">' + img(it.cover, it.title) + '</div><div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:6px">' +
          '<b style="font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(it.title) + '</b>' +
          '<span class="muted" style="font-size:12px">' + (tab === 'fav' ? (+it.type === 2 ? 'Series' : 'Film') : epLabel(+it.type, it.se, it.ep)) + '</span>' +
          (tab === 'fav' ? '' : '<div class="prog"><i style="width:' + pct + '%"></i></div>') + '</div><span class="muted">' + ICON.chev + '</span></a>';
      }).join('');
    });
  }

  /* ---------------- mulai ---------------- */
  function start() {
    if (TG) {
      TG.ready(); TG.expand();
      try { TG.setHeaderColor('#0E0F13'); TG.setBackgroundColor('#0E0F13'); } catch (e) {}
      TG.BackButton.onClick(function () { history.length > 1 ? history.back() : (location.hash = '#/'); });
    }
    if (!TG || !TG.initData) {
      $view.innerHTML = '<div class="empty" style="padding-top:30vh">Buka aplikasi ini dari bot Telegram.</div>';
      return;
    }
    api('boot').then(function (j) {
      if (!j.ok) { $view.innerHTML = '<div style="padding-top:25vh"></div>' + errBox(j.error); return; }
      S.boot = j;
      applyTheme(j.app);
      if (j.app.providers.length) S.prov = j.app.providers[0].id;
      // start_param dari tautan t.me/bot/app?startapp=...
      route();
    });
    window.addEventListener('hashchange', route);
  }
  start();
})();
