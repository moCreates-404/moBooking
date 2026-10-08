/*
 * moBooking — staff Manage calendar (Phase 7c). Vanilla JS, no framework, no
 * build step, SEPARATE from the customer grid (mclb-grid.js is never touched).
 *
 * Renders a day view from the mclb/v1/admin/day JSON payload (bookings as blocks
 * spanning their hours, blockouts in event-type colour, a now-line) and writes
 * every change back through the admin REST layer. The server is the authority on
 * price and availability; this file shows advisory previews and surfaces server
 * errors inline. All writes carry staff initials (remembered per device).
 */
(function () {
  'use strict';

  var CFG = window.mclbManage || {};
  var I18N = CFG.i18n || {};
  var root = document.querySelector('.mclb-manage');
  if (!root) { return; }

  var ROW_H = 56;                                     // px per increment row
  var INC = parseInt(CFG.increment, 10) || 60;        // coerce: config may arrive as a string
  var PPM = ROW_H / INC;                              // px per minute
  var TOP_PAD = 10;                                   // top inset so the first hour label clears the sticky header
  // Booleans too: treat only real true / "1" / 1 as true.
  var CANREFUND = CFG.canRefund === true || CFG.canRefund === '1' || CFG.canRefund === 1;

  var state = {
    date: root.getAttribute('data-today') || CFG.today,
    showCancelled: false,
    coachFilter: 0,
    data: null,
    nonce: CFG.nonce,
    panelOpen: false,
    panelEl: null,
    dragging: false,
    timer: null
  };

  // Disable every button in the open panel and show "Saving…" on the active one,
  // so a slow write can't be double-submitted and the user gets feedback.
  function setBusy(activeBtn, on) {
    if (!state.panelEl) { return; }
    state.panelEl.querySelectorAll('button').forEach(function (b) { b.disabled = on; });
    if (activeBtn) {
      if (on) { activeBtn._label = activeBtn.textContent; activeBtn.textContent = (I18N.saving || 'Saving…'); }
      else if (activeBtn._label != null) { activeBtn.textContent = activeBtn._label; }
    }
  }
  // Standard write: busy → on ok close + reload, on error re-enable + inline message.
  function runWrite(btn, errBox, promise) {
    setBusy(btn, true);
    if (errBox) { errBox.style.display = 'none'; }
    promise.then(function (res) {
      if (res && res.ok) { closePanel(); load(true); }
      else { setBusy(btn, false); if (errBox) { showErr(errBox, errText(res)); } }
    });
  }

  // ── small helpers ──────────────────────────────────────────────────────────
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text != null) { e.textContent = text; }
    return e;
  }
  function money(n) { return (CFG.currency || '$') + Number(n || 0).toFixed(2); }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function minToLabel(min) {
    var h = Math.floor(min / 60), m = min % 60, ap = h < 12 ? 'am' : 'pm';
    var h12 = h % 12; if (h12 === 0) { h12 = 12; }
    return h12 + ':' + pad(m) + ' ' + ap;
  }
  function timeInput(min) { return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }
  function dtToMin(dt) {               // "YYYY-MM-DD HH:MM:SS" → minutes of day
    var t = (dt || '').substr(11, 5).split(':');
    return (parseInt(t[0], 10) || 0) * 60 + (parseInt(t[1], 10) || 0);
  }
  function initialsGet() { try { return localStorage.getItem('mclbInitials') || ''; } catch (e) { return ''; } }
  function initialsSet(v) { try { localStorage.setItem('mclbInitials', v); } catch (e) {} }
  function esc(s) { var d = el('span'); d.textContent = s == null ? '' : s; return d.innerHTML; }

  // ── API with nonce refresh + retry ──────────────────────────────────────────
  function refreshNonce() {
    return fetch(CFG.ajaxUrl + '?action=rest-nonce', { method: 'POST', credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (txt) {
        txt = (txt || '').trim();
        if (!txt || txt === '0' || txt === '-1') { throw { session: true }; }
        state.nonce = txt;
        return txt;
      });
  }
  function api(path, opts, isRetry) {
    opts = opts || {};
    var headers = { 'X-WP-Nonce': state.nonce };
    if (opts.body) { headers['Content-Type'] = 'application/json'; }
    return fetch(CFG.rest + path, {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (body) {
        if (r.status === 403 && body && body.code === 'rest_cookie_invalid_nonce' && !isRetry) {
          return refreshNonce().then(function () { return api(path, opts, true); });
        }
        return { status: r.status, ok: r.ok, body: body };
      });
    });
  }
  function errText(res) {
    if (res && res.session) { return I18N.sessionGone; }
    if (res && res.status === 401) { return I18N.sessionGone; }
    if (res && res.body && res.body.message) { return res.body.message; }
    return 'Something went wrong.';
  }

  // ── load + auto-refresh ──────────────────────────────────────────────────────
  function load(silent) {
    if (!silent) { root.innerHTML = '<p class="mclb-manage__loading">' + esc(I18N.loading || 'Loading…') + '</p>'; }
    return api('/day?date=' + encodeURIComponent(state.date) + '&show_cancelled=' + (state.showCancelled ? '1' : '0'))
      .then(function (res) {
        if (res.session || res.status === 401) { root.innerHTML = sessionHtml(); return; }
        if (!res.ok) { root.innerHTML = '<p class="mclb-manage__err">' + esc(errText(res)) + '</p>'; return; }
        state.data = res.body;
        state.nonce = state.nonce; // unchanged
        render();
      })
      .catch(function () { root.innerHTML = '<p class="mclb-manage__err">Could not load the calendar.</p>'; });
  }
  function sessionHtml() {
    return '<p class="mclb-manage__err">' + esc(I18N.sessionGone) + ' <a href="' + esc(CFG.loginUrl) + '">' + esc(I18N.cancel ? 'Log in' : 'Log in') + '</a></p>';
  }
  function scheduleRefresh() {
    if (state.timer) { clearInterval(state.timer); }
    state.timer = setInterval(function () {
      if (!state.panelOpen && !state.dragging) { load(true); }
    }, 60000);
  }

  // ── render ───────────────────────────────────────────────────────────────────
  function render() {
    var d = state.data;
    root.innerHTML = '';
    root.appendChild(toolbar());
    root.appendChild(collectStrip());

    var open = d.grid.open_min, close = d.grid.close_min;
    if (open == null || close == null) {
      root.appendChild(el('p', 'mclb-manage__empty', 'No open ' + (CFG.labels.resourcePlural || 'lanes') + ' this day.'));
      return;
    }
    var wrap = el('div', 'mclb-cal');
    wrap.appendChild(timeGutter(open, close));

    d.types.forEach(function (grp) {
      var group = el('div', 'mclb-cal__group');
      var head = el('div', 'mclb-cal__grouphead', grp.type || '');
      group.appendChild(head);
      var cols = el('div', 'mclb-cal__cols');
      grp.lanes.forEach(function (lane) { cols.appendChild(laneColumn(lane, open, close)); });
      group.appendChild(cols);
      wrap.appendChild(group);
    });
    root.appendChild(wrap);
    paintNowLine(open, close);
  }

  function toolbar() {
    var bar = el('div', 'mclb-cal__toolbar');
    var nav = el('div', 'mclb-cal__nav');
    var prev = el('button', 'button mclb-cal__btn', '‹'); prev.title = I18N.prev;
    var next = el('button', 'button mclb-cal__btn', '›'); next.title = I18N.next;
    var today = el('button', 'button mclb-cal__btn', I18N.today || 'Today');
    var date = el('input', 'mclb-cal__date'); date.type = 'date'; date.value = state.date;
    prev.onclick = function () { shiftDate(-1); };
    next.onclick = function () { shiftDate(1); };
    today.onclick = function () { state.date = CFG.today; load(); };
    date.onchange = function () { if (date.value) { state.date = date.value; load(); } };
    nav.appendChild(prev); nav.appendChild(date); nav.appendChild(next); nav.appendChild(today);

    var filters = el('div', 'mclb-cal__filters');
    var coach = el('select', 'mclb-cal__coach');
    coach.appendChild(opt('0', I18N.allCoaches || 'All coaches'));
    (state.data.coaches || []).forEach(function (c) { coach.appendChild(opt(String(c.id), c.name)); });
    coach.value = String(state.coachFilter);
    coach.onchange = function () { state.coachFilter = parseInt(coach.value, 10) || 0; render(); };
    var lbl = el('label', 'mclb-cal__showcancel');
    var cb = el('input'); cb.type = 'checkbox'; cb.checked = state.showCancelled;
    cb.onchange = function () { state.showCancelled = cb.checked; render(); };
    lbl.appendChild(cb); lbl.appendChild(document.createTextNode(' ' + (I18N.showCancel || 'Show cancelled')));
    filters.appendChild(coach); filters.appendChild(lbl);

    bar.appendChild(nav); bar.appendChild(filters);
    return bar;
  }
  function opt(v, t) { var o = el('option', null, t); o.value = v; return o; }

  function collectStrip() {
    var strip = el('div', 'mclb-cal__collect');
    strip.appendChild(el('strong', null, (I18N.toCollect || 'To collect today') + ': '));
    var items = (state.data.bookings || []).filter(function (b) {
      return !b.held && b.badges.some(function (x) { return x.kind === 'due' || x.kind === 'refund'; });
    });
    if (!items.length) { strip.appendChild(document.createTextNode('—')); return strip; }
    items.forEach(function (b) {
      var badge = b.badges.filter(function (x) { return x.kind === 'due' || x.kind === 'refund'; })[0];
      var chip = el('button', 'mclb-chip mclb-chip--' + badge.kind, '#' + b.id + ' ' + (b.customer || 'Guest') + ' · ' + badge.label);
      chip.onclick = function () { openDetail(b); };
      strip.appendChild(chip);
    });
    return strip;
  }

  function timeGutter(open, close) {
    var g = el('div', 'mclb-cal__gutter');
    g.appendChild(el('div', 'mclb-cal__gutterhead', ''));
    var inner = el('div', 'mclb-cal__gutterinner');
    inner.style.height = ((close - open) * PPM + TOP_PAD) + 'px';
    for (var m = open; m < close; m += INC) {
      var t = el('div', 'mclb-cal__tick', minToLabel(m));
      t.style.top = ((m - open) * PPM + TOP_PAD) + 'px';
      inner.appendChild(t);
    }
    g.appendChild(inner);
    return g;
  }

  function laneColumn(lane, open, close) {
    var col = el('div', 'mclb-cal__col');
    col.appendChild(el('div', 'mclb-cal__colhead', lane.name));
    var body = el('div', 'mclb-cal__colbody');
    body.style.height = ((close - open) * PPM + TOP_PAD) + 'px';
    body.setAttribute('data-lane', lane.id);
    // increment grid lines
    for (var m = open; m < close; m += INC) {
      var line = el('div', 'mclb-cal__line');
      line.style.top = ((m - open) * PPM + TOP_PAD) + 'px';
      body.appendChild(line);
    }
    // closed region shading (outside this lane's own hours)
    if (lane.open_min != null && lane.open_min > open) { body.appendChild(shade(open, lane.open_min, open)); }
    if (lane.close_min != null && lane.close_min < close) { body.appendChild(shade(lane.close_min, close, open)); }

    // blockouts (site-wide lane_id 0 render in every column)
    (state.data.blockouts || []).forEach(function (bo) {
      if (bo.lane_id === 0 || bo.lane_id === lane.id) { body.appendChild(blockoutBlock(bo, open)); }
    });
    // bookings — cancelled first (ghost, under), then active
    var mine = (state.data.bookings || []).filter(function (b) { return b.lane_id === lane.id; });
    // Ghosted cancelled blocks only when the toggle is on (they never block the
    // active booking — pointer-events:none in CSS). The "to collect" strip still
    // surfaces refund-at-counter items regardless (see collectStrip).
    if (state.showCancelled) {
      mine.filter(function (b) { return b.cancelled; }).forEach(function (b) { body.appendChild(bookingBlock(b, open)); });
    }
    mine.filter(function (b) { return !b.cancelled; }).forEach(function (b) { body.appendChild(bookingBlock(b, open)); });

    wireDrag(body, lane, open, close);
    col.appendChild(body);
    return col;
  }

  function shade(from, to, open) {
    var s = el('div', 'mclb-cal__shade');
    s.style.top = ((from - open) * PPM + TOP_PAD) + 'px';
    s.style.height = ((to - from) * PPM) + 'px';
    return s;
  }

  function pos(block, start, end, open) {
    block.style.top = ((start - open) * PPM + TOP_PAD) + 'px';
    block.style.height = (Math.max(end - start, INC / 2) * PPM - 2) + 'px';
  }

  function blockoutBlock(bo, open) {
    var b = el('div', 'mclb-mblock mclb-mblock--blockout');
    pos(b, bo.start_min, bo.end_min, open);
    b.style.setProperty('--bo', bo.colour || '#85c9c2');
    var title = bo.type_label || 'Unavailable';
    b.appendChild(el('span', 'mclb-mblock__title', title + (bo.lane_id === 0 ? ' · all' : '')));
    if (bo.note) { b.appendChild(el('span', 'mclb-mblock__sub', bo.note)); }
    if (!bo.editable) { b.classList.add('mclb-mblock--readonly'); }
    b.onclick = function (e) { e.stopPropagation(); openBlockout(bo); };
    return b;
  }

  function bookingBlock(b, open) {
    var block = el('div', 'mclb-mblock mclb-mblock--booking mclb-mblock--' + b.source);
    pos(block, b.start_min, b.end_min, open);
    if (b.held) { block.classList.add('mclb-mblock--held'); }
    if (b.cancelled) { block.classList.add('mclb-mblock--cancelled'); }
    if (state.coachFilter && b.coach_id !== state.coachFilter) { block.classList.add('mclb-mblock--dim'); }
    if (state.coachFilter && b.coach_id === state.coachFilter) { block.classList.add('mclb-mblock--hi'); }

    if (b.held) {
      block.appendChild(el('span', 'mclb-mblock__title', I18N.inProgress || 'Checkout in progress'));
      return block; // no actions on live holds
    }
    block.appendChild(el('span', 'mclb-mblock__title', '#' + b.id + ' ' + (b.customer || 'Guest')));
    var meta = el('span', 'mclb-mblock__sub', b.source === 'online' ? 'online' : 'manual');
    block.appendChild(meta);
    if (b.coach_label) {
      var chip = el('span', 'mclb-mblock__coach', b.coach_label);
      block.appendChild(chip);
    }
    b.badges.forEach(function (bd) {
      block.appendChild(el('span', 'mclb-badge mclb-badge--' + bd.kind, bd.label));
    });
    if (!b.cancelled) { block.onclick = function (e) { e.stopPropagation(); openDetail(b); }; }
    return block;
  }

  function paintNowLine(open, close) {
    if (state.date !== CFG.today || !state.data.now) { return; }
    var nowMin = dtToMin(state.data.now);
    if (nowMin < open || nowMin > close) { return; }
    document.querySelectorAll('.mclb-cal__colbody').forEach(function (body) {
      var line = el('div', 'mclb-cal__now');
      line.style.top = ((nowMin - open) * PPM + TOP_PAD) + 'px';
      body.appendChild(line);
    });
  }

  // ── drag / tap range select on empty cells ───────────────────────────────────
  function wireDrag(body, lane, open, close) {
    var startMin = null, marker = null, tapStart = null;
    function yToMin(clientY) {
      var rect = body.getBoundingClientRect();
      var min = open + Math.floor(((clientY - rect.top - TOP_PAD) / PPM) / INC) * INC;
      return Math.max(open, Math.min(close - INC, min));
    }
    body.addEventListener('pointerdown', function (e) {
      if (e.target !== body && !e.target.classList.contains('mclb-cal__line') && !e.target.classList.contains('mclb-cal__shade')) { return; }
      startMin = yToMin(e.clientY);
      state.dragging = true;
      marker = el('div', 'mclb-cal__sel'); body.appendChild(marker);
      drawSel(marker, startMin, startMin + INC, open);
      body.setPointerCapture(e.pointerId);
    });
    body.addEventListener('pointermove', function (e) {
      if (startMin == null) { return; }
      var cur = yToMin(e.clientY) + INC;
      drawSel(marker, Math.min(startMin, cur - INC), Math.max(startMin + INC, cur), open);
    });
    body.addEventListener('pointerup', function (e) {
      if (startMin == null) { return; }
      var endMin = yToMin(e.clientY) + INC;
      var s = Math.min(startMin, endMin - INC), en = Math.max(startMin + INC, endMin);
      if (marker) { body.removeChild(marker); marker = null; }
      state.dragging = false;
      startMin = null;
      // a plain tap (no move) on touch: first tap sets start, second completes
      if (e.pointerType === 'touch' && s + INC === en && tapStart == null) {
        tapStart = s; return;
      }
      if (tapStart != null) { s = Math.min(tapStart, s); en = Math.max(tapStart + INC, en); tapStart = null; }
      openNewBooking(lane, s, en);
    });
  }
  function drawSel(marker, s, e, open) {
    marker.style.top = ((s - open) * PPM + TOP_PAD) + 'px';
    marker.style.height = ((e - s) * PPM) + 'px';
  }

  // ── side panel ────────────────────────────────────────────────────────────────
  function openPanel(build) {
    closePanel();
    state.panelOpen = true;
    var overlay = el('div', 'mclb-panel__overlay');
    overlay.onclick = closePanel;
    var panel = el('aside', 'mclb-panel');
    state.panelEl = panel;
    build(panel);
    root.appendChild(overlay);
    root.appendChild(panel);
    requestAnimationFrame(function () { panel.classList.add('is-open'); });
  }
  function closePanel() {
    state.panelOpen = false;
    state.panelEl = null;
    root.querySelectorAll('.mclb-panel').forEach(function (n) { n.remove(); });
    root.querySelectorAll('.mclb-panel__overlay').forEach(function (n) { n.remove(); });
  }
  function panelHead(panel, title) {
    var h = el('div', 'mclb-panel__head');
    var t = el('h3', null, title);
    h.appendChild(t);
    var x = el('button', 'mclb-panel__close', '×'); x.onclick = closePanel;
    h.appendChild(x); panel.appendChild(h);
    panel._title = t;
  }
  function field(panel, labelText, input) {
    var w = el('label', 'mclb-panel__field'); w.appendChild(el('span', null, labelText)); w.appendChild(input); panel.appendChild(w); return input;
  }
  function errBox(panel) { var e = el('p', 'mclb-panel__err'); e.style.display = 'none'; panel.appendChild(e); return e; }
  function showErr(box, msg) { box.textContent = msg; box.style.display = ''; }
  function initialsField(panel) {
    var inp = el('input'); inp.type = 'text'; inp.maxLength = 20; inp.value = initialsGet(); inp.placeholder = I18N.initials || 'Your initials';
    field(panel, I18N.initials || 'Your initials', inp);
    return inp;
  }

  // ── NEW booking / blockout panel (from a drag) ────────────────────────────────
  function openNewBooking(lane, startMin, endMin) {
    openPanel(function (panel) {
      panelHead(panel, I18N.newBooking || 'New booking');
      panel.appendChild(el('p', 'mclb-panel__ctx', lane.name + ' · ' + state.date + ' · ' + minToLabel(startMin) + '–' + minToLabel(endMin)));

      var tabs = el('div', 'mclb-panel__tabs');
      var tabBook = el('button', 'mclb-tab is-active', I18N.newBooking || 'New booking');
      var tabBlock = el('button', 'mclb-tab', I18N.blockout || 'Block out');
      tabs.appendChild(tabBook); tabs.appendChild(tabBlock); panel.appendChild(tabs);

      var bookBody = el('div', 'mclb-tabbody');
      var blockBody = el('div', 'mclb-tabbody'); blockBody.style.display = 'none';
      panel.appendChild(bookBody); panel.appendChild(blockBody);
      tabBook.onclick = function () { tabBook.classList.add('is-active'); tabBlock.classList.remove('is-active'); bookBody.style.display = ''; blockBody.style.display = 'none'; panel._title.textContent = I18N.newBooking || 'New booking'; };
      tabBlock.onclick = function () { tabBlock.classList.add('is-active'); tabBook.classList.remove('is-active'); blockBody.style.display = ''; bookBody.style.display = 'none'; panel._title.textContent = I18N.blockout || 'Block out'; };

      // -- booking tab --
      var name = field(bookBody, 'Name', mkInput('text'));
      var email = field(bookBody, 'Email', mkInput('email'));
      var note = field(bookBody, 'Note', mkInput('text'));
      var coach = el('select'); coach.appendChild(opt('0', '— No coach —'));
      field(bookBody, CFG.labels.staffSingular || 'Coach', coach);
      var preview = el('p', 'mclb-panel__preview');
      bookBody.appendChild(preview);
      var laneRate = lane.price_per_hour || 0, hours = (endMin - startMin) / 60;
      var coachRates = {};
      function updatePreview() {
        var cfee = coachRates[coach.value] ? coachRates[coach.value] * hours : 0;
        preview.textContent = 'Lane ' + money(laneRate * hours) + (coach.value !== '0' ? ' + coach ' + money(cfee) + ' = ' + money(laneRate * hours + cfee) : '') + ' (advisory)';
      }
      api('/coaches?date=' + state.date + '&start=' + timeInput(startMin) + '&end=' + timeInput(endMin))
        .then(function (res) {
          if (res.ok && res.body.coaches) {
            res.body.coaches.forEach(function (c) { coachRates[String(c.id)] = c.rate; coach.appendChild(opt(String(c.id), c.name + ' — ' + money(c.rate) + '/hr')); });
          }
        });
      coach.onchange = updatePreview; updatePreview();

      var ovWrap = el('label', 'mclb-panel__override');
      var ov = el('input'); ov.type = 'checkbox';
      ovWrap.appendChild(ov); ovWrap.appendChild(document.createTextNode(' Override hours / blockouts'));
      bookBody.appendChild(ovWrap);
      var ovWarn = el('p', 'mclb-panel__warn', '⚠ Booking outside normal availability.'); ovWarn.style.display = 'none';
      bookBody.appendChild(ovWarn);
      ov.onchange = function () { ovWarn.style.display = ov.checked ? '' : 'none'; };

      var ini = initialsField(bookBody);
      var err = errBox(bookBody);
      var save = el('button', 'button button-primary mclb-panel__save', 'Create booking');
      bookBody.appendChild(save);
      save.onclick = function () {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        runWrite(save, err, api('/booking', { method: 'POST', body: {
          lane_id: lane.id, date: state.date, start: timeInput(startMin), end: timeInput(endMin),
          name: name.value, email: email.value, note: note.value,
          coach_id: parseInt(coach.value, 10) || 0, override: ov.checked ? 1 : 0, actor: ini.value.trim()
        } }));
      };

      // -- blockout tab --
      blockBody.appendChild(buildBlockoutForm(lane, startMin, endMin));
    });
  }

  function buildBlockoutForm(lane, startMin, endMin) {
    var frag = document.createDocumentFragment();
    var wrap = el('div');
    var etype = el('select');
    etype.appendChild(opt('', '— Event type —'));
    (CFG.eventTypes || []).forEach(function (t) { etype.appendChild(opt(t.slug, t.label)); });
    field(wrap, 'Event type', etype);
    var note = field(wrap, 'Note', mkInput('text'));
    var scope = el('select'); scope.appendChild(opt(String(lane.id), lane.name)); scope.appendChild(opt('0', 'All ' + (CFG.labels.resourcePlural || 'lanes')));
    field(wrap, 'Applies to', scope);
    var ini = initialsField(wrap);
    var err = errBox(wrap);
    var save = el('button', 'button button-primary mclb-panel__save', 'Save blockout');
    wrap.appendChild(save);
    save.onclick = function () {
      if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
      initialsSet(ini.value.trim());
      runWrite(save, err, api('/blockout', { method: 'POST', body: {
        lane_id: parseInt(scope.value, 10) || 0, date: state.date, start: timeInput(startMin), end: timeInput(endMin),
        event_type: etype.value, note: note.value, actor: ini.value.trim()
      } }));
    };
    frag.appendChild(wrap);
    return frag;
  }

  function mkInput(type) { var i = el('input'); i.type = type; return i; }

  // ── DETAIL panel (click a booking) ────────────────────────────────────────────
  function openDetail(b) {
    openPanel(function (panel) {
      panelHead(panel, '#' + b.id + ' · ' + minToLabel(b.start_min) + '–' + minToLabel(b.end_min));
      panel.appendChild(el('p', 'mclb-panel__ctx', (b.source === 'online' ? 'Online' : 'Manual') + ' · ' + (b.customer || 'Guest') + (b.email ? ' · ' + b.email : '')));

      // coach
      var coachRow = el('div', 'mclb-panel__row');
      coachRow.appendChild(el('span', 'mclb-panel__rlabel', (CFG.labels.staffSingular || 'Coach') + ': ' + (b.coach_label || '—')));
      panel.appendChild(coachRow);
      var coach = el('select'); coach.appendChild(opt('0', '— No coach —'));
      field(panel, 'Change ' + (CFG.labels.staffSingular || 'coach'), coach);
      api('/coaches?date=' + state.date + '&start=' + timeInput(b.start_min) + '&end=' + timeInput(b.end_min) + '&exclude=' + b.id)
        .then(function (res) {
          var list = (res.ok && res.body.coaches) ? res.body.coaches : [];
          // Make sure the current coach is an option, then preselect it so an
          // accidental Save can't silently remove them.
          if (b.coach_id && !list.some(function (c) { return c.id === b.coach_id; })) {
            coach.appendChild(opt(String(b.coach_id), b.coach_label));
          }
          list.forEach(function (c) { coach.appendChild(opt(String(c.id), c.name + ' — ' + money(c.rate) + '/hr')); });
          coach.value = b.coach_id ? String(b.coach_id) : '0';
        });
      var coachNote = mkInput('text'); coachNote.placeholder = 'note (required if counter paid)';
      if (b.counter_paid) { field(panel, 'Note', coachNote); }

      // counter
      if (b.counter_due != null && b.counter_due > 0) {
        var cr = el('div', 'mclb-panel__row');
        cr.appendChild(el('span', 'mclb-panel__rlabel', 'Counter due: ' + money(b.counter_due) + (b.counter_paid ? ' (paid)' : '')));
        panel.appendChild(cr);
      }

      // note history
      if (b.note) {
        var hist = el('div', 'mclb-panel__notes');
        hist.appendChild(el('strong', null, 'Notes'));
        b.note.split('\n').forEach(function (ln) { hist.appendChild(el('div', 'mclb-panel__noteln', ln)); });
        panel.appendChild(hist);
      }

      var ini = initialsField(panel);
      var err = errBox(panel);

      function needInitials() { if (!ini.value.trim()) { showErr(err, I18N.needInitials); return false; } initialsSet(ini.value.trim()); return true; }

      // actions
      var actions = el('div', 'mclb-panel__actions');
      actions.appendChild(actionBtn('Save ' + (CFG.labels.staffSingular || 'coach'), function (btn) {
        if (!needInitials()) { return; }
        var coachId = parseInt(coach.value, 10) || 0;
        // A counter-paid booking needs an explicit note to change/remove the coach.
        if (b.counter_paid && !coachNote.value.trim()) {
          showErr(err, I18N.noteRequiredPaid || 'A note is required because the counter payment has been taken.');
          return;
        }
        runWrite(btn, err, api('/booking/' + b.id + '/coach', { method: 'POST', body: { coach_id: coachId, note: coachNote.value, actor: ini.value.trim() } }));
      }));
      if (b.counter_due != null && b.counter_due > 0 && !b.counter_paid) {
        actions.appendChild(actionBtn('Mark counter paid', function () {
          if (!needInitials()) { return; }
          confirmStep(actions, 'Record that the counter payment was taken? (This is not a charge.)', function (yesBtn) {
            runWrite(yesBtn, err, api('/booking/' + b.id + '/counter', { method: 'POST', body: { op: 'paid', actor: ini.value.trim() } }));
          });
        }));
      }
      if (b.counter_paid) {
        actions.appendChild(actionBtn('Reverse counter paid', function () {
          if (!needInitials()) { return; }
          confirmStep(actions, 'Reverse the recorded payment?', function (yesBtn) {
            runWrite(yesBtn, err, api('/booking/' + b.id + '/counter', { method: 'POST', body: { op: 'reverse', actor: ini.value.trim() } }));
          });
        }));
      }
      actions.appendChild(actionBtn('Cancel only', function () {
        if (!needInitials()) { return; }
        confirmStep(actions, 'Cancel this booking?', function (yesBtn) {
          runWrite(yesBtn, err, api('/booking/' + b.id + '/cancel', { method: 'POST', body: { mode: 'only', actor: ini.value.trim() } }));
        });
      }, 'is-danger'));
      if (CANREFUND && b.refundable) {
        actions.appendChild(actionBtn('Cancel + refund', function () {
          if (!needInitials()) { return; }
          confirmStep(actions, 'Cancel and refund online payment?', function (yesBtn) {
            runWrite(yesBtn, err, api('/booking/' + b.id + '/cancel', { method: 'POST', body: { mode: 'refund', actor: ini.value.trim() } }));
          });
        }, 'is-danger'));
      }
      panel.appendChild(actions);
    });
  }

  function actionBtn(label, fn, cls) { var b = el('button', 'button mclb-panel__act ' + (cls || ''), label); b.onclick = function () { fn(b); }; return b; }
  function confirmStep(container, question, fn) {
    var box = el('div', 'mclb-panel__confirm');
    box.appendChild(el('span', null, question + ' '));
    var yes = el('button', 'button button-primary', I18N.confirm || 'Confirm'); var no = el('button', 'button', I18N.back || 'Back');
    // Pass the Confirm button to the write so runWrite can show busy on it; the
    // row stays on error (re-enabled) and vanishes with the panel on success.
    yes.onclick = function () { fn(yes); }; no.onclick = function () { box.remove(); };
    box.appendChild(yes); box.appendChild(no); container.appendChild(box);
  }

  // ── BLOCKOUT panel (click a blockout) ─────────────────────────────────────────
  function openBlockout(bo) {
    openPanel(function (panel) {
      panelHead(panel, (bo.type_label || 'Blockout') + ' · ' + minToLabel(bo.start_min) + '–' + minToLabel(bo.end_min));
      if (!bo.editable) {
        panel.appendChild(el('p', 'mclb-panel__ctx', 'Recurring blockout — edit it on the Closures screen.'));
        if (bo.note) { panel.appendChild(el('p', 'mclb-panel__ctx', 'Note: ' + bo.note)); }
        return;
      }
      var etype = el('select'); etype.appendChild(opt('', '— Event type —'));
      (CFG.eventTypes || []).forEach(function (t) { var o = opt(t.slug, t.label); if (t.slug === bo.event_type) { o.selected = true; } etype.appendChild(o); });
      field(panel, 'Event type', etype);
      var note = field(panel, 'Note', mkInput('text')); note.value = bo.note || '';
      var ini = initialsField(panel);
      var err = errBox(panel);
      var actions = el('div', 'mclb-panel__actions');
      actions.appendChild(actionBtn(I18N.save || 'Save', function (btn) {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        runWrite(btn, err, api('/blockout/' + bo.id, { method: 'POST', body: { event_type: etype.value, note: note.value, actor: ini.value.trim() } }));
      }));
      actions.appendChild(actionBtn(I18N.delete || 'Delete', function () {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        confirmStep(actions, 'Delete this blockout?', function (yesBtn) {
          runWrite(yesBtn, err, api('/blockout/' + bo.id, { method: 'DELETE', body: { actor: ini.value.trim() } }));
        });
      }, 'is-danger'));
      panel.appendChild(actions);
    });
  }

  function shiftDate(days) {
    var d = new Date(state.date + 'T00:00:00');
    d.setDate(d.getDate() + days);
    state.date = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    load();
  }

  // ── boot ──────────────────────────────────────────────────────────────────────
  load();
  scheduleRefresh();
})();
