/* ==========================================================================
   Valbridge Truffle Auctions — Homepage interactions (vanilla JS, no deps)

   WordPress integration notes
   ---------------------------
   • Each lot card carries data attributes the Valbridge Core plugin should
     render server-side:
       data-lot-id     lot number
       data-price      current price in CHF (base currency)
       data-increment  bid increment in CHF
       data-bids       number of bids
       data-end        auction end, ISO-8601 UTC (empty = awaiting first bid)
       data-buynow     Buy Now price in CHF (empty = none)
     For this static preview `data-remaining` (seconds) is used instead of
     `data-end`, so the timers never show an expired demo.
   • Server time is authoritative. Pass the server clock as
     window.VB_CONFIG.serverNow (ms) so countdowns are offset-corrected.
   • Live updates: replace the DEMO block with an SSE/WebSocket or a cached
     polling endpoint that calls VB.applyBid(lotId, {price, bids, end}).
   • Exchange rates come from the multi-currency plugin via VB_CONFIG.rates.
   ========================================================================== */
(function () {
  'use strict';

  var CFG = Object.assign({
    duration: 900,                 // rolling timer, seconds (admin setting)
    currency: 'CHF',
    rates: { CHF: 1, EUR: 1.06, USD: 1.24, GBP: 0.92 }, // DEMO rates
    locale: document.documentElement.lang || 'en',
    serverNow: null,
    demo: true                     // ← set false in production
  }, window.VB_CONFIG || {});

  var clockOffset = CFG.serverNow ? CFG.serverNow - Date.now() : 0;
  var now = function () { return Date.now() + clockOffset; };
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- storage helpers (never required to work) ---------- */
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  };

  /* ---------- money ---------- */
  var currency = store.get('vb-currency', CFG.currency);
  function fmt(chf) {
    var rate = CFG.rates[currency] || 1;
    var value = Math.round(chf * rate);
    try {
      return new Intl.NumberFormat(CFG.locale, { style: 'currency', currency: currency, maximumFractionDigits: 0, currencyDisplay: 'code' })
        .format(value).replace(/ /g, ' ');
    } catch (e) { return currency + ' ' + value; }
  }

  /* ---------- lots ---------- */
  var lots = $$('[data-vb-lot]').map(function (el) {
    var end = null;
    if (el.dataset.end) end = Date.parse(el.dataset.end);
    else if (el.dataset.remaining) end = now() + parseInt(el.dataset.remaining, 10) * 1000;
    return {
      el: el,
      id: el.dataset.lotId,
      price: parseFloat(el.dataset.price) || 0,
      inc: parseFloat(el.dataset.increment) || 5,
      bids: parseInt(el.dataset.bids, 10) || 0,
      end: end,
      ring: $('[data-vb-ring]', el),
      timer: $('[data-vb-countdown]', el),
      ended: false
    };
  });

  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function formatLeft(ms) {
    var s = Math.max(0, Math.ceil(ms / 1000));
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    return h ? h + ':' + pad(m) + ':' + pad(sec) : pad(m) + ':' + pad(sec);
  }

  function renderPrice(lot) {
    var p = $('[data-vb-price]', lot.el), n = $('[data-vb-next]', lot.el), b = $('[data-vb-bids]', lot.el);
    if (p) p.textContent = fmt(lot.price);
    if (n) n.textContent = fmt(lot.bids ? lot.price + lot.inc : lot.price);
    if (b) b.textContent = lot.bids;
    var label = $('.vb-lot__row .vb-label', lot.el);
    if (label && lot.bids > 0) label.textContent = 'Current bid';
  }

  function renderTimer(lot) {
    if (!lot.timer) return;
    var wrap = lot.timer.parentElement;
    if (!lot.end) {
      lot.timer.textContent = 'Awaiting first bid';
      if (wrap.classList.contains('vb-lot__timer')) wrap.classList.add('is-idle');
      return;
    }
    if (wrap.classList.contains('vb-lot__timer')) wrap.classList.remove('is-idle');
    var left = lot.end - now();
    if (left <= 0) {
      if (!lot.ended) {
        lot.ended = true;
        lot.el.classList.remove('is-urgent');
        lot.el.classList.add('is-ended');
        // Production: the server closes the lot and pushes the final state.
        lot.timer.textContent = lot.bids ? 'Sold' : 'Ended';
      }
      return;
    }
    lot.timer.textContent = formatLeft(left);
    lot.el.classList.toggle('is-urgent', left < 60000);
    if (lot.ring) {
      var C = 339.3, frac = Math.min(1, left / (CFG.duration * 1000));
      lot.ring.style.strokeDashoffset = (C * (1 - frac)).toFixed(1);
    }
  }

  function tick() { lots.forEach(renderTimer); }

  /* Public hook for live updates (SSE / polling) */
  function applyBid(id, data) {
    var lot = lots.filter(function (l) { return l.id === id; })[0];
    if (!lot) return;
    lot.price = data.price;
    lot.bids = data.bids;
    lot.end = data.end;
    lot.ended = false;
    lot.el.classList.remove('is-ended');
    renderPrice(lot);
    renderTimer(lot);
    if (!reduceMotion) {
      lot.el.classList.remove('is-flash'); void lot.el.offsetWidth; lot.el.classList.add('is-flash');
      var p = $('[data-vb-price]', lot.el);
      if (p) { p.classList.remove('is-bump'); void p.offsetWidth; p.classList.add('is-bump'); }
    }
    pushTicker('<strong>Lot ' + id + '</strong> new bid <span>' + fmt(data.price) + '</span>');
  }

  /* ---------- ticker ---------- */
  var ticker = $('[data-vb-ticker]');
  function cloneTicker() {
    if (!ticker) return;
    $$('[aria-hidden="true"]', ticker).forEach(function (n) { n.remove(); });
    $$('li', ticker).forEach(function (li) {
      var c = li.cloneNode(true); c.setAttribute('aria-hidden', 'true'); ticker.appendChild(c);
    });
  }
  function pushTicker(html) {
    if (!ticker) return;
    var originals = $$('li:not([aria-hidden])', ticker);
    var li = document.createElement('li');
    li.innerHTML = html;
    ticker.insertBefore(li, originals[0]);
    if (originals.length > 8) originals[originals.length - 1].remove();
    cloneTicker();
  }

  /* ---------- currency ---------- */
  function renderAllPrices() {
    lots.forEach(renderPrice);
    $$('[data-vb-chf]').forEach(function (el) { el.textContent = fmt(parseFloat(el.dataset.vbChf)); });
  }
  var curSel = $('[data-vb-currency]');
  if (curSel) {
    curSel.value = currency;
    curSel.addEventListener('change', function () {
      currency = curSel.value; store.set('vb-currency', currency); renderAllPrices();
      // Production: reload via plugin's currency switcher (cookie) so server prices match.
    });
  }

  /* ---------- header ---------- */
  var header = $('[data-vb-header]');
  var onScroll = function () { header && header.classList.toggle('is-scrolled', window.scrollY > 40); };
  window.addEventListener('scroll', onScroll, { passive: true }); onScroll();

  /* mobile nav */
  var burger = $('[data-vb-toggle="nav"]'), nav = $('#vb-nav');
  function setNav(open) {
    if (!burger || !nav) return;
    burger.setAttribute('aria-expanded', open);
    burger.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    nav.classList.toggle('is-open', open);
    document.body.classList.toggle('is-nav-open', open);
    if (open) { var f = $('a', nav); f && f.focus(); }
  }
  burger && burger.addEventListener('click', function () { setNav(burger.getAttribute('aria-expanded') !== 'true'); });
  document.addEventListener('click', function (e) {
    if (document.body.classList.contains('is-nav-open') && !nav.contains(e.target) && !burger.contains(e.target)) setNav(false);
  });

  /* language menu */
  var langBtn = $('[data-vb-toggle="lang"]'), langMenu = $('#vb-lang-menu');
  function setLang(open) { langBtn.setAttribute('aria-expanded', open); langMenu.hidden = !open; }
  if (langBtn && langMenu) {
    langBtn.addEventListener('click', function (e) { e.stopPropagation(); setLang(langMenu.hidden); });
    document.addEventListener('click', function (e) { if (!langMenu.contains(e.target)) setLang(false); });
  }

  /* search */
  var searchBtn = $('[data-vb-toggle="search"]'), search = $('[data-vb-search]');
  searchBtn && searchBtn.addEventListener('click', function () {
    search.hidden = !search.hidden;
    searchBtn.setAttribute('aria-expanded', !search.hidden);
    if (!search.hidden) $('input', search).focus();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    setNav(false);
    if (langMenu && !langMenu.hidden) { setLang(false); langBtn.focus(); }
    if (search && !search.hidden) { search.hidden = true; searchBtn.focus(); }
  });

  /* ---------- slider ---------- */
  var slider = $('[data-vb-slider]');
  if (slider) {
    var prev = $('[data-vb-slide="prev"]'), next = $('[data-vb-slide="next"]');
    var step = function () { var c = slider.firstElementChild; return c ? c.getBoundingClientRect().width + 24 : 300; };
    var sync = function () {
      prev.disabled = slider.scrollLeft < 8;
      next.disabled = slider.scrollLeft + slider.clientWidth >= slider.scrollWidth - 8;
    };
    prev.addEventListener('click', function () { slider.scrollBy({ left: -step(), behavior: reduceMotion ? 'auto' : 'smooth' }); });
    next.addEventListener('click', function () { slider.scrollBy({ left: step(), behavior: reduceMotion ? 'auto' : 'smooth' }); });
    slider.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();
  }

  /* ---------- watchlist ---------- */
  var toast = $('[data-vb-toast]'), toastT;
  function showToast(msg) {
    if (!toast) return;
    toast.textContent = msg; toast.classList.add('is-show');
    clearTimeout(toastT); toastT = setTimeout(function () { toast.classList.remove('is-show'); }, 2600);
  }
  var watched = store.get('vb-watch', []);
  var watchCount = $('[data-vb-watch-count]');
  function renderWatch() {
    $$('[data-vb-watch]').forEach(function (btn) {
      var id = btn.closest('[data-vb-lot]').dataset.lotId, on = watched.indexOf(id) > -1;
      btn.setAttribute('aria-pressed', on);
      btn.setAttribute('aria-label', (on ? 'Remove lot ' : 'Add lot ') + id + (on ? ' from watchlist' : ' to watchlist'));
    });
    if (watchCount) { watchCount.textContent = watched.length; watchCount.hidden = !watched.length; }
  }
  $$('[data-vb-watch]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      // Production: POST to REST endpoint (nonce) for logged-in users; prompt login otherwise.
      var id = btn.closest('[data-vb-lot]').dataset.lotId, i = watched.indexOf(id);
      if (i > -1) { watched.splice(i, 1); showToast('Removed lot ' + id + ' from your watchlist'); }
      else { watched.push(id); showToast('Lot ' + id + ' added to your watchlist'); }
      store.set('vb-watch', watched); renderWatch();
      btn.classList.remove('is-pop'); void btn.offsetWidth; btn.classList.add('is-pop');
    });
  });

  /* ---------- reveal on scroll ---------- */
  var reveals = $$('.vb-reveal');
  if ('IntersectionObserver' in window && !reduceMotion) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    reveals.forEach(function (el) { io.observe(el); });
  } else reveals.forEach(function (el) { el.classList.add('is-in'); });

  /* ---------- "how it works" demo clock ---------- */
  var demoClock = $('[data-vb-demo-clock]'), demoState = $('[data-vb-demo-state]');
  if (demoClock && !reduceMotion) {
    var states = [
      { t: 900, s: 'Waiting for first bid', run: false },
      { t: 900, s: 'First bid · timer starts', run: true, len: 5 },
      { t: 900, s: 'New bid · reset to 15:00', run: true, len: 5 },
      { t: 900, s: 'New bid · reset to 15:00', run: true, len: 7, toZero: true },
      { t: 0, s: 'Sold to the highest bidder', run: false }
    ];
    var si = 0, cur = 900, frames = 0, demoTimer = null;
    var drawClock = function () { demoClock.textContent = pad(Math.floor(cur / 60)) + ':' + pad(cur % 60); };
    var stepDemo = function () {
      var st = states[si];
      if (frames === 0) { cur = st.t; demoState.textContent = st.s; }
      frames++;
      if (st.run) cur = Math.max(0, cur - (st.toZero ? Math.ceil(900 / (st.len * 2)) : 37));
      drawClock();
      var limit = st.run ? st.len * 2 : 5;
      if (frames >= limit || (st.toZero && cur === 0)) { si = (si + 1) % states.length; frames = 0; }
    };
    var tl = $('.vb-timeline');
    new IntersectionObserver(function (en) {
      if (en[0].isIntersecting && !demoTimer) demoTimer = setInterval(stepDemo, 500);
      else if (!en[0].isIntersecting && demoTimer) { clearInterval(demoTimer); demoTimer = null; }
    }).observe(tl);
  }

  /* ---------- sign-up ---------- */
  var form = $('[data-vb-signup]'), msg = $('[data-vb-signup-msg]');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = form.email.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { msg.textContent = 'Please enter a valid e-mail address.'; form.email.focus(); return; }
      if (!form.consent.checked) { msg.textContent = 'Please confirm your consent to receive alerts.'; form.consent.focus(); return; }
      // Production: POST to newsletter provider (double opt-in).
      msg.textContent = 'Thank you. Please confirm the e-mail we just sent you.';
      form.reset();
    });
    var pushBtn = $('[data-vb-push]');
    pushBtn && pushBtn.addEventListener('click', function () {
      // Production: hand over to OneSignal / PushEngage SDK. iOS requires the PWA on the home screen.
      if (!('Notification' in window)) { msg.textContent = 'Push alerts are not supported in this browser. On iPhone, add this site to your home screen first.'; return; }
      Notification.requestPermission().then(function (p) {
        msg.textContent = p === 'granted' ? 'Push alerts enabled for this device.' : 'Push alerts were not enabled.';
      });
    });
  }

  /* ---------- cookie consent ---------- */
  var cookie = $('[data-vb-cookie]');
  if (cookie) {
    if (!store.get('vb-consent', null)) setTimeout(function () { cookie.hidden = false; }, 900);
    $$('[data-vb-cookie-choice]').forEach(function (b) {
      b.addEventListener('click', function () {
        var choice = b.dataset.vbCookieChoice;
        store.set('vb-consent', { choice: choice, at: new Date().toISOString(), v: 1 });
        cookie.hidden = true;
        // Production: CMP updates Google Consent Mode v2 here.
        if (window.gtag) window.gtag('consent', 'update', { analytics_storage: choice === 'all' ? 'granted' : 'denied', ad_storage: choice === 'all' ? 'granted' : 'denied' });
      });
    });
    $$('[data-vb-cookie-open]').forEach(function (b) { b.addEventListener('click', function () { cookie.hidden = false; }); });
  }

  /* ---------- DEMO: simulated bids (remove in production) ----------
     Shows the rolling timer behaviour in the static preview: a random live
     lot receives a bid and its timer resets to 15:00. Real sites must only
     show real bids (fair-trading rules: no fake activity).               */
  if (CFG.demo) {
    var cities = ['Zürich', 'Genève', 'Milano', 'Lyon', 'London', 'Madrid', 'München', 'Wien', 'Paris', 'Basel'];
    var demoBid = function () {
      var live = lots.filter(function (l) { return l.end && !l.ended; });
      if (live.length) {
        // favour lots close to ending so the reset is visible
        live.sort(function (a, b) { return a.end - b.end; });
        var lot = live[Math.floor(Math.random() * Math.min(3, live.length))];
        applyBid(lot.id, { price: lot.price + lot.inc, bids: lot.bids + 1, end: now() + CFG.duration * 1000 });
        var first = ticker && $('li', ticker);
        if (first) first.insertAdjacentHTML('beforeend', ' · ' + cities[Math.floor(Math.random() * cities.length)]);
        cloneTicker();
      }
      setTimeout(demoBid, 7000 + Math.random() * 9000);
    };
    setTimeout(demoBid, 6000);
  }

  /* ---------- init ---------- */
  cloneTicker();
  renderAllPrices();
  renderWatch();
  tick();
  setInterval(tick, 1000);

  window.VB = { applyBid: applyBid, setCurrency: function (c) { currency = c; renderAllPrices(); } };
})();
