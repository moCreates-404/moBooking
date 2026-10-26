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

  var ROW_H = 72;                                     // px per increment row (taller so 30-min blocks stay readable)
  var INC = parseInt(CFG.increment, 10) || 60;        // coerce: config may arrive as a string
  var PPM = ROW_H / INC;                              // px per minute
  var TOP_PAD = 10;                                   // top inset so the first hour label clears the sticky header
  var GAP = 2;                                        // vertical gap so back-to-back blocks read as separate
  // Booleans too: treat only real true / "1" / 1 as true.
  var CANREFUND = CFG.canRefund === true || CFG.canRefund === '1' || CFG.canRefund === 1;

  function lsGet(k, d) { try { var v = localStorage.getItem('mclb_' + k); return v === null ? d : v; } catch (e) { return d; } }
  function lsSet(k, v) { try { localStorage.setItem('mclb_' + k, v); } catch (e) {} }

  var ROS_ROWH = 48;   // coach-row height
  var ROS_GUT = 150;   // coach-label column width
  var ROS_MINHR = 64;  // minimum px per hour; the timeline otherwise stretches to fill the width

  var state = {
    date: root.getAttribute('data-today') || CFG.today,
    showCancelled: false,
    coachFilter: 0,
    view: (lsGet('view', 'lanes') === 'roster') ? 'roster' : 'lanes', // Lanes | Roster (per device)
    rosterWeek: lsGet('rosterWeek', '0') === '1',                      // Day | Week within Roster
    showAll: lsGet('rosterAll', '0') === '1',                          // expand no-session coaches
    week: null,        // cached /week payload
    data: null,
    nonce: CFG.nonce,
    panelOpen: false,
    panelEl: null,
    dragging: false,
    timer: null,
    loadSeq: 0        // guards against a slow response landing after the date changed
  };

  // Reload the ACTIVE view (day grid/roster from /day, or the week roster from /week).
  function reload(silent) {
    if (state.view === 'roster' && state.rosterWeek) { state.week = null; return loadWeek(silent); }
    return load(silent);
  }

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
  // Coach name + hours for chips/tooltips. When the coach covers only part of the
  // session (partial coach time, v1.4.1) the hours are appended: "Rob 4:00–5:00 pm".
  function coachChip(b) {
    if (!b.coach_label) { return ''; }
    if (b.coach_partial && b.coach_start_min != null && b.coach_end_min != null) {
      return b.coach_label + ' ' + minToLabel(b.coach_start_min) + '–' + minToLabel(b.coach_end_min);
    }
    return b.coach_label;
  }
  // "1h" / "1.5h" for the fee breakdown.
  function fmtHrs(h) { return (Math.round(h * 100) / 100).toString().replace(/\.0+$/, '') + 'h'; }
  // Coupon snapshot (v1.4.2): the day payload sends coupons as null (unchecked),
  // [] (none), or [{codes,amount,credit}] for this line's discount incl tax.
  function couponEntry(b) { return (b.coupons && b.coupons.length) ? b.coupons[0] : null; }
  // Prepaid-pass cover (v1.5.0).
  function passHours(b) { return (b.pass_hours && b.pass_hours > 0) ? b.pass_hours : 0; }
  function passChip(b) {
    var h = passHours(b); if (!h) { return ''; }
    var hh = (Math.round(h * 100) / 100).toString().replace(/\.0+$/, '');
    var left = (b.pass_balance != null) ? ' · ' + (Math.round(b.pass_balance * 100) / 100).toString().replace(/\.0+$/, '') + 'h ' + (I18N.left || 'left') : '';
    return (I18N.paidByPass || 'Paid by pass') + ' (' + hh + 'h)' + (b.pass_code ? ' · ' + b.pass_code : '') + left;
  }
  function couponText(b) {
    var e = couponEntry(b); if (!e) { return ''; }
    var codes = (e.codes || []).join(', ');
    return (e.credit ? (I18N.credit || 'Credit') + ' · ' : (I18N.coupon || 'Coupon') + ': ')
      + codes + ' · −' + money(e.amount);
  }
  // "Booked Mon 20 Oct 2026, 3:42 pm (online)" / "(manual · CL)" (v1.4.2).
  function createdText(b) {
    if (!b.created_label) { return ''; }
    var how = (b.source === 'online')
      ? (I18N.srcOnline || 'online')
      : (I18N.srcManual || 'manual') + (b.created_by ? ' · ' + b.created_by : '');
    return (I18N.booked || 'Booked') + ' ' + b.created_label + ' (' + how + ')';
  }
  // Coach-time control (partial coach time, v1.4.1): two increment-snapped selects
  // bounded by the booking range, defaulting to the whole session. partial() is
  // false while it spans the whole booking (→ send no coach range = NULL = whole).
  function buildCoachTime(startMin, endMin) {
    var wrap = el('label', 'mclb-panel__field mclb-coachtime');
    wrap.appendChild(el('span', null, (I18N.coachTime || 'Coach time')));
    var row = el('div', 'mclb-coachtime__row');
    var cStart = el('select'), cEnd = el('select');
    var m;
    for (m = startMin; m < endMin; m += INC) { cStart.appendChild(opt(String(m), minToLabel(m))); }
    for (m = startMin + INC; m <= endMin; m += INC) { cEnd.appendChild(opt(String(m), minToLabel(m))); }
    cStart.value = String(startMin); cEnd.value = String(endMin);
    row.appendChild(cStart); row.appendChild(el('span', 'mclb-coachtime__dash', '–')); row.appendChild(cEnd);
    wrap.appendChild(row);
    // Keep end strictly after start (nudge end forward when needed).
    function guard() {
      if (parseInt(cEnd.value, 10) <= parseInt(cStart.value, 10)) {
        cEnd.value = String(Math.min(endMin, parseInt(cStart.value, 10) + INC));
      }
    }
    cStart.addEventListener('change', guard);
    return {
      wrap: wrap, cStart: cStart, cEnd: cEnd,
      partial: function () { return parseInt(cStart.value, 10) !== startMin || parseInt(cEnd.value, 10) !== endMin; },
      hours: function () { return (parseInt(cEnd.value, 10) - parseInt(cStart.value, 10)) / 60; },
      startHHMM: function () { return timeInput(parseInt(cStart.value, 10)); },
      endHHMM: function () { return timeInput(parseInt(cEnd.value, 10)); },
      onChange: function (cb) { cStart.addEventListener('change', cb); cEnd.addEventListener('change', cb); }
    };
  }
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
    var seq = ++state.loadSeq;
    return api('/day?date=' + encodeURIComponent(state.date) + '&show_cancelled=' + (state.showCancelled ? '1' : '0'))
      .then(function (res) {
        if (seq !== state.loadSeq) { return; } // a newer load started — ignore this stale response
        if (res.session || res.status === 401) { root.innerHTML = sessionHtml(); return; }
        if (!res.ok) { root.innerHTML = '<p class="mclb-manage__err">' + esc(errText(res)) + '</p>'; return; }
        state.data = res.body;
        render();
      })
      .catch(function () { if (seq === state.loadSeq) { root.innerHTML = '<p class="mclb-manage__err">Could not load the calendar.</p>'; } });
  }
  function sessionHtml() {
    return '<p class="mclb-manage__err">' + esc(I18N.sessionGone) + ' <a href="' + esc(CFG.loginUrl) + '">' + esc(I18N.cancel ? 'Log in' : 'Log in') + '</a></p>';
  }
  function scheduleRefresh() {
    if (state.timer) { clearInterval(state.timer); }
    state.timer = setInterval(function () {
      if (!state.panelOpen && !state.dragging) { reload(true); }
    }, 60000);
  }

  // ── render ───────────────────────────────────────────────────────────────────
  function render() {
    var d = state.data;
    root.innerHTML = '';
    root.appendChild(toolbar());
    if (state.view === 'roster') { renderRoster(); return; }
    root.appendChild(collectStrip());
    var rel = releasedStrip();
    if (rel) { root.appendChild(rel); }

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
    // Chevrons via unicode escapes so they can't be mangled by file encoding
    // (the literal '‹' was rendering as '|' for some fonts/encodings on the page).
    var prev = el('button', 'button mclb-cal__btn', '‹'); prev.title = I18N.prev; prev.setAttribute('aria-label', I18N.prev || 'Previous day');
    var next = el('button', 'button mclb-cal__btn', '›'); next.title = I18N.next; next.setAttribute('aria-label', I18N.next || 'Next day');
    var today = el('button', 'button mclb-cal__btn', I18N.today || 'Today');
    var date = el('input', 'mclb-cal__date'); date.type = 'date'; date.value = state.date;
    prev.onclick = function () { shiftDate(-1); };
    next.onclick = function () { shiftDate(1); };
    today.onclick = function () { state.date = CFG.today; reload(); };
    date.onchange = function () { if (date.value) { state.date = date.value; reload(); } };
    nav.appendChild(prev); nav.appendChild(date); nav.appendChild(next); nav.appendChild(today);

    var filters = el('div', 'mclb-cal__filters');

    // Lanes / Roster view toggle (v1.5.1), remembered per device.
    var vtog = el('div', 'mclb-cal__viewtog');
    var vlanes = el('button', 'button mclb-cal__btn' + (state.view === 'lanes' ? ' is-active' : ''), I18N.lanesView || 'Lanes');
    var vros   = el('button', 'button mclb-cal__btn' + (state.view === 'roster' ? ' is-active' : ''), I18N.rosterView || 'Roster');
    vlanes.onclick = function () { if (state.view !== 'lanes') { state.view = 'lanes'; lsSet('view', 'lanes'); render(); } };
    vros.onclick   = function () { if (state.view !== 'roster') { state.view = 'roster'; lsSet('view', 'roster'); if (state.rosterWeek && !state.week) { render(); } else { render(); } } };
    vtog.appendChild(vlanes); vtog.appendChild(vros);
    filters.appendChild(vtog);

    if (state.view === 'roster') {
      // Day / Week + Show-all + Send-to-all + Print (roster only).
      var dtog = el('div', 'mclb-cal__viewtog');
      var bDay  = el('button', 'button mclb-cal__btn' + (!state.rosterWeek ? ' is-active' : ''), I18N.day || 'Day');
      var bWeek = el('button', 'button mclb-cal__btn' + (state.rosterWeek ? ' is-active' : ''), I18N.week || 'Week');
      bDay.onclick  = function () { if (state.rosterWeek) { state.rosterWeek = false; lsSet('rosterWeek', '0'); render(); } };
      bWeek.onclick = function () { if (!state.rosterWeek) { state.rosterWeek = true; lsSet('rosterWeek', '1'); state.week = null; render(); } };
      dtog.appendChild(bDay); dtog.appendChild(bWeek); filters.appendChild(dtog);

      var allLbl = el('label', 'mclb-cal__showcancel');
      var allCb = el('input'); allCb.type = 'checkbox'; allCb.checked = state.showAll;
      allCb.onchange = function () { state.showAll = allCb.checked; lsSet('rosterAll', allCb.checked ? '1' : '0'); render(); };
      allLbl.appendChild(allCb); allLbl.appendChild(document.createTextNode(' ' + (I18N.showAllCoaches || 'Show all coaches')));
      filters.appendChild(allLbl);

      var sendAll = el('button', 'button mclb-cal__btn', I18N.sendAll || 'Send day sheets to all');
      sendAll.onclick = function () { openSendAll(); };
      filters.appendChild(sendAll);
      if (state.rosterWeek) {
        var pw = el('button', 'button mclb-cal__btn', I18N.printWeek || 'Print week');
        pw.onclick = function () { window.print(); };
        filters.appendChild(pw);
      }
    } else {
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

      // Day-sheet controls for the selected coach (viewed date).
      if (state.coachFilter) {
        var cobj = (state.data.coaches || []).filter(function (c) { return c.id === state.coachFilter; })[0];
        if (cobj) {
          var sheet = el('span', 'mclb-cal__sheet');
          if (cobj.sheet_sent_at) {
            sheet.appendChild(el('span', 'mclb-cal__sheetnote', (I18N.sheetSent || 'Sheet sent') + (cobj.sheet_changed ? ' · ' + (I18N.sheetChanged || 'changed since sent') : '')));
          }
          var sbtn = el('button', 'button mclb-cal__btn', cobj.sheet_sent_at ? (I18N.resend || 'Resend day sheet') : (I18N.sendSheet || 'Send day sheet'));
          sbtn.onclick = function () { openSendSheet(cobj); };
          sheet.appendChild(sbtn);
          filters.appendChild(sheet);
        }
      }
    }

    // Prepaid passes (v1.5.0): issue / look up / void from the counter.
    var passesBtn = el('button', 'button mclb-cal__btn', I18N.passes || 'Passes');
    passesBtn.onclick = function () { openPasses(); };
    filters.appendChild(passesBtn);

    var labelText = (state.data && state.data.date_label) || '';
    if (state.view === 'roster' && state.rosterWeek && state.week) { labelText = (I18N.weekOf || 'Week of') + ' ' + (state.week.start_label || state.week.start); }
    var dlabel = el('div', 'mclb-cal__datelabel', labelText);
    bar.appendChild(nav); bar.appendChild(dlabel); bar.appendChild(filters);
    return bar;
  }
  function opt(v, t) { var o = el('option', null, t); o.value = v; return o; }

  // Released-blockout markers (v1.4.1). A whole-day release removes the blockout
  // block entirely, so the only sign it is free is this strip; clicking a marker
  // offers "Undo release" (which refuses if a booking now sits in the window).
  function releasedStrip() {
    var marks = (state.data && state.data.released) || [];
    if (!marks.length) { return null; }
    var strip = el('div', 'mclb-cal__collect mclb-cal__released');
    strip.appendChild(el('strong', null, (I18N.released || 'Released') + ': '));
    marks.forEach(function (m) {
      var scope = (m.lane_id === 0) ? '' : (' · ' + (laneName(m.lane_id) || ('#' + m.lane_id)));
      var when = m.from ? (' ' + (I18N.releasedFrom || 'from') + ' ' + m.from) : (' · ' + (I18N.wholeDay || 'whole day'));
      var label = (m.type_label || 'Blockout') + when + scope + (m.actor ? ' (' + m.actor + ')' : '');
      var chip = el('button', 'mclb-chip mclb-chip--released', label);
      chip.onclick = function () { openReleaseUndo(m); };
      strip.appendChild(chip);
    });
    return strip;
  }

  // Lane display name from the grouped grid data.
  function laneName(laneId) {
    var found = '';
    ((state.data && state.data.types) || []).forEach(function (grp) {
      (grp.lanes || []).forEach(function (l) { if (l.id === laneId) { found = l.name; } });
    });
    return found;
  }

  function collectStrip() {
    var strip = el('div', 'mclb-cal__collect');
    // "To collect today" only when viewing today; otherwise name the date.
    var collectLabel = (state.date === CFG.today)
      ? (I18N.toCollect || 'To collect today')
      : (I18N.toCollectOn || 'To collect') + ' · ' + ((state.data && state.data.date_label) || state.date);
    strip.appendChild(el('strong', null, collectLabel + ': '));
    var items = (state.data.bookings || []).filter(function (b) {
      return !b.held && b.badges.some(function (x) { return x.kind === 'due' || x.kind === 'refund'; });
    });
    if (!items.length) { strip.appendChild(document.createTextNode('—')); return strip; }

    // Group by normalised name + email, split by kind (due vs refund). Empty or
    // "Guest" names never merge — each stays a single pill (we can't yet tell
    // them apart; phone joins the key in 8a). Click opens the first booking;
    // mark-paid stays per booking in the detail panel.
    var groups = [], byKey = {};
    items.forEach(function (b) {
      var badge = b.badges.filter(function (x) { return x.kind === 'due' || x.kind === 'refund'; })[0];
      var nameRaw = (b.customer || '').trim();
      var nameNorm = nameRaw.toLowerCase();
      var anon = (nameNorm === '' || nameNorm === 'guest');
      var key = (anon ? 'anon#' + b.id : nameNorm + '|' + (b.phone_raw || '').trim() + '|' + (b.email || '').trim().toLowerCase()) + '|' + badge.kind;
      var g = byKey[key];
      if (!g) { g = { name: nameRaw || 'Guest', kind: badge.kind, count: 0, total: 0, first: b }; byKey[key] = g; groups.push(g); }
      g.count += 1;
      g.total += (b.counter_due || 0);
    });
    var one = (CFG.labels && CFG.labels.resourceSingular ? CFG.labels.resourceSingular : 'Lane').toLowerCase();
    var many = (CFG.labels && CFG.labels.resourcePlural ? CFG.labels.resourcePlural : 'Lanes').toLowerCase();
    groups.forEach(function (g) {
      var label = g.name + ' · ' + g.count + ' ' + (g.count === 1 ? one : many)
                + ' · ' + (g.kind === 'refund' ? (I18N.refund || 'Refund') : (I18N.due || 'Due')) + ' ' + money(g.total);
      var chip = el('button', 'mclb-chip mclb-chip--' + g.kind, label);
      chip.onclick = function () { openDetail(g.first); };
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

    // Ghosted cancelled blocks only when the toggle is on — rendered full-width
    // underneath, never part of the overlap layout (pointer-events:none in CSS).
    // The "to collect" strip still surfaces refund-at-counter items regardless.
    if (state.showCancelled) {
      (state.data.bookings || [])
        .filter(function (b) { return b.lane_id === lane.id && b.cancelled; })
        .forEach(function (b) { body.appendChild(bookingBlock(b, open, null)); });
    }

    // Blockouts (site-wide lane_id 0 render in every column) + active bookings
    // share one overlap layout, so a genuine overlap (closure over a booking) is
    // placed side by side rather than stacked.
    var items = [];
    (state.data.blockouts || []).forEach(function (bo) {
      if (bo.lane_id === 0 || bo.lane_id === lane.id) { items.push({ start: bo.start_min, end: bo.end_min, kind: 'bo', data: bo }); }
    });
    (state.data.bookings || [])
      .filter(function (b) { return b.lane_id === lane.id && !b.cancelled; })
      .forEach(function (b) { items.push({ start: b.start_min, end: b.end_min, kind: 'bk', data: b }); });
    layoutColumns(items);
    // Blockouts first so they sit behind (also z-index:1 in CSS); bookings above.
    items.forEach(function (it) { if (it.kind === 'bo') { body.appendChild(blockoutBlock(it.data, open, it)); } });
    items.forEach(function (it) { if (it.kind === 'bk') { body.appendChild(bookingBlock(it.data, open, it)); } });

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

  function pos(block, start, end, open, layout) {
    block.style.top = ((start - open) * PPM + TOP_PAD) + 'px';
    block.style.height = (Math.max(end - start, INC / 2) * PPM - GAP) + 'px';
    // Side-by-side within a genuine overlap cluster (e.g. a closure over a booking).
    if (layout && layout.width < 99.9) {
      block.style.left = 'calc(' + layout.left + '% + 2px)';
      block.style.right = 'auto';
      block.style.width = 'calc(' + layout.width + '% - 4px)';
    }
  }

  // Assign side-by-side columns to overlapping items; sets .left and .width
  // (percentages) on each. Non-overlapping items span the full width.
  function layoutColumns(items) {
    items.sort(function (a, b) { return a.start - b.start || a.end - b.end; });
    var columns = [], group = [], clusterEnd = null;
    function pack() {
      var n = Math.max(columns.length, 1);
      group.forEach(function (it) { it.width = 100 / n; it.left = it._col * (100 / n); });
      columns = []; group = []; clusterEnd = null;
    }
    items.forEach(function (it) {
      if (clusterEnd !== null && it.start >= clusterEnd) { pack(); }
      var placed = false;
      for (var c = 0; c < columns.length; c++) {
        var col = columns[c];
        if (col[col.length - 1].end <= it.start) { col.push(it); it._col = c; placed = true; break; }
      }
      if (!placed) { columns.push([it]); it._col = columns.length - 1; }
      group.push(it);
      clusterEnd = (clusterEnd === null) ? it.end : Math.max(clusterEnd, it.end);
    });
    pack();
  }

  // Advisory: is [startMin,endMin) outside this lane's open hours, over a blockout,
  // or in the past today? Computed from the day payload (server stays authoritative).
  function rangeOutside(lane, startMin, endMin) {
    if (lane.open_min == null || lane.close_min == null) { return true; }
    if (startMin < lane.open_min || endMin > lane.close_min) { return true; }
    var bos = state.data.blockouts || [];
    for (var i = 0; i < bos.length; i++) {
      var bo = bos[i];
      if ((bo.lane_id === 0 || bo.lane_id === lane.id) && startMin < bo.end_min && bo.start_min < endMin) { return true; }
    }
    if (state.date === CFG.today && state.data.now && startMin < dtToMin(state.data.now)) { return true; }
    return false;
  }

  // Is this booking happening right now (today only)? Refreshed each render /
  // 60s reload, in step with the now-line.
  function nowActive(b) {
    if (state.date !== CFG.today || !state.data || !state.data.now || b.held || b.cancelled) { return false; }
    var n = dtToMin(state.data.now);
    return b.start_min <= n && n < b.end_min;
  }

  function blockoutBlock(bo, open, layout) {
    var b = el('div', 'mclb-mblock mclb-mblock--blockout');
    pos(b, bo.start_min, bo.end_min, open, layout);
    b.style.setProperty('--bo', bo.colour || '#85c9c2');
    var title = (bo.type_label || 'Unavailable') + (bo.lane_id === 0 ? ' · all' : '');
    b.appendChild(el('span', 'mclb-mblock__title', title));
    if (bo.note) { b.appendChild(el('span', 'mclb-mblock__sub', bo.note)); }
    b.title = title + ' · ' + minToLabel(bo.start_min) + '–' + minToLabel(bo.end_min) + (bo.note ? ' · ' + bo.note : '');
    if (!bo.editable) { b.classList.add('mclb-mblock--readonly'); }
    b.onclick = function (e) { e.stopPropagation(); openBlockout(bo); };
    return b;
  }

  function bookingBlock(b, open, layout) {
    var block = el('div', 'mclb-mblock mclb-mblock--booking mclb-mblock--' + b.source);
    pos(block, b.start_min, b.end_min, open, layout);
    if (b.held) { block.classList.add('mclb-mblock--held'); }
    if (b.cancelled) { block.classList.add('mclb-mblock--cancelled'); }
    if (state.coachFilter && b.coach_id !== state.coachFilter) { block.classList.add('mclb-mblock--dim'); }
    if (state.coachFilter && b.coach_id === state.coachFilter) { block.classList.add('mclb-mblock--hi'); }

    if (b.held) {
      block.appendChild(el('span', 'mclb-mblock__title', I18N.inProgress || 'Checkout in progress'));
      block.title = (I18N.inProgress || 'Checkout in progress') + ' · ' + minToLabel(b.start_min) + '–' + minToLabel(b.end_min);
      return block; // no actions on live holds
    }

    // "Who's on now" outline (today only) + a price-overridden marker.
    if (b.price_original != null) { block.classList.add('mclb-mblock--overridden'); }
    if (nowActive(b)) { block.classList.add('mclb-mblock--now'); }

    // Line 1: "#87 Hayden Brown" (bold). The source (online/manual) stays in the
    // detail panel; the block only carries the colour.
    var titleText = '#' + b.id + ' ' + (b.customer || 'Guest');
    block.appendChild(el('span', 'mclb-mblock__title', titleText));
    var seriesLabel = (b.series_id && b.series_m > 1)
      ? (I18N.series || 'Series') + ' · ' + (I18N.ofN || '%n of %m').replace('%n', b.series_n).replace('%m', b.series_m)
      : '';
    if (seriesLabel && (b.end_min - b.start_min) > 30) {
      block.appendChild(el('span', 'mclb-mblock__series', seriesLabel));
    }

    // Prefer the actionable pill (Due/Refund), else the paid state.
    var pill = b.badges.filter(function (x) { return x.kind === 'due' || x.kind === 'refund'; })[0]
            || b.badges.filter(function (x) { return x.kind === 'counter_paid' || x.kind === 'paid_online'; })[0];

    // Full detail lives in the tooltip (essential for short blocks).
    block.title = titleText + ' · ' + minToLabel(b.start_min) + '–' + minToLabel(b.end_min)
      + ' · ' + (b.source === 'online' ? 'Online' : 'Manual')
      + (seriesLabel ? ' · ' + seriesLabel : '')
      + (b.coach_label ? ' · ' + coachChip(b) : '')
      + (b.phone ? ' · ' + b.phone : '')
      + (pill ? ' · ' + pill.label : '')
      + (b.price_original != null ? ' · ' + (I18N.priceOverridden || 'price overridden') : '')
      + (couponEntry(b) ? ' · ' + couponText(b) : '')
      + (passHours(b) ? ' · ' + passChip(b) : '')
      + (createdText(b) ? ' · ' + createdText(b) : '');

    // Line 2 (coach chip + payment pill on one row) — hidden on ≤30-min blocks,
    // where the name alone must stay readable and the rest is in the tooltip.
    if ((b.end_min - b.start_min) > 30) {
      var row = el('div', 'mclb-mblock__row');
      if (b.coach_label) { row.appendChild(el('span', 'mclb-mblock__coach', coachChip(b))); }
      if (pill) { row.appendChild(el('span', 'mclb-badge mclb-badge--' + pill.kind, pill.label)); }
      var ce = couponEntry(b);
      if (ce) { row.appendChild(el('span', 'mclb-badge mclb-badge--' + (ce.credit ? 'credit' : 'coupon'), ce.credit ? (I18N.credit || 'Credit') : (I18N.coupon || 'Coupon'))); }
      if (passHours(b)) { row.appendChild(el('span', 'mclb-badge mclb-badge--pass', I18N.pass || 'Pass')); }
      if (row.childNodes.length) { block.appendChild(row); }
    }

    if (!b.cancelled) { wireBlockMove(block, b, open); }
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

  // ── drag a booking block to a new lane/time (same day) ────────────────────────
  // Desktop: press-drag (a ≤6px press is a click → detail). Touch: long-press
  // (~400ms) to arm, then drag; a tap or a scroll never moves the booking. The
  // server stays authoritative — a drop opens the Move panel, which previews and
  // confirms. The "Move…" button is the everywhere fallback.
  var moveDrag = null;
  function wireBlockMove(block, b, open) {
    block.addEventListener('pointerdown', function (e) {
      if (e.button != null && e.button !== 0) { return; }
      if (!state.data || !state.data.grid) { return; }
      moveDrag = {
        b: b, block: block, openMin: state.data.grid.open_min, close: state.data.grid.close_min,
        x0: e.clientX, y0: e.clientY, dur: b.end_min - b.start_min,
        armed: e.pointerType !== 'touch', moved: false, ghost: null, outline: null, target: null, lp: null
      };
      if (e.pointerType === 'touch') {
        block.classList.add('mclb-mblock--pressing');
        moveDrag.lp = setTimeout(function () {
          if (moveDrag && moveDrag.block === block) {
            moveDrag.armed = true;
            block.classList.remove('mclb-mblock--pressing');
            block.classList.add('mclb-mblock--dragging');
            if (navigator.vibrate) { try { navigator.vibrate(10); } catch (x) {} }
          }
        }, 400);
      }
      try { block.setPointerCapture(e.pointerId); } catch (x) {}
    });
    block.addEventListener('pointermove', function (e) {
      if (!moveDrag || moveDrag.block !== block) { return; }
      var dx = e.clientX - moveDrag.x0, dy = e.clientY - moveDrag.y0;
      if (!moveDrag.moved && (Math.abs(dx) > 6 || Math.abs(dy) > 6)) {
        if (!moveDrag.armed) { clearMove(); return; } // touch moved before long-press = a scroll
        moveDrag.moved = true;
        state.dragging = true;
        block.classList.add('mclb-mblock--dragging');
        root.classList.add('is-moving'); // suppress text selection while dragging
        var seln = window.getSelection && window.getSelection();
        if (seln && seln.removeAllRanges) { try { seln.removeAllRanges(); } catch (x) {} }
        makeGhost();
      }
      if (moveDrag.moved) { e.preventDefault(); updateMoveTarget(e.clientX, e.clientY); }
    });
    block.addEventListener('pointerup', function () {
      if (!moveDrag || moveDrag.block !== block) { return; }
      var wasDrag = moveDrag.moved, target = moveDrag.target;
      finishMove();
      if (!wasDrag) { openDetail(b); return; }
      if (target && (target.lane_id !== b.lane_id || target.start !== b.start_min)) {
        openMoveForm(b, { date: state.date, lane_id: target.lane_id, start_min: target.start, dur: b.end_min - b.start_min });
      }
    });
    block.addEventListener('pointercancel', function () { if (moveDrag && moveDrag.block === block) { clearMove(); } });
  }
  function makeGhost() {
    var g = el('div', 'mclb-move-ghost', '#' + moveDrag.b.id + ' ' + (moveDrag.b.customer || 'Guest'));
    document.body.appendChild(g);
    moveDrag.ghost = g;
  }
  function updateMoveTarget(x, y) {
    if (moveDrag.ghost) { moveDrag.ghost.style.left = (x + 12) + 'px'; moveDrag.ghost.style.top = (y + 12) + 'px'; }
    if (moveDrag.outline && moveDrag.outline.parentNode) { moveDrag.outline.parentNode.removeChild(moveDrag.outline); moveDrag.outline = null; }
    var at = document.elementFromPoint(x, y);
    var body = at ? at.closest('.mclb-cal__colbody') : null;
    if (!body) { moveDrag.target = null; return; }
    var laneId = parseInt(body.getAttribute('data-lane'), 10);
    var lane = laneById(laneId);
    var rect = body.getBoundingClientRect();
    var open = moveDrag.openMin, close = moveDrag.close, dur = moveDrag.dur;
    var raw = open + Math.round(((y - rect.top - TOP_PAD) / PPM) / INC) * INC;
    var start = Math.max(open, Math.min(close - dur, raw));
    var valid = !!lane && !rangeOutside(lane, start, start + dur) && !laneClash(laneId, start, start + dur, moveDrag.b.id);
    moveDrag.target = { lane_id: laneId, laneName: lane ? lane.name : '', start: start, valid: valid };
    var o = el('div', 'mclb-move-outline ' + (valid ? 'is-ok' : 'is-bad'));
    o.style.top = ((start - open) * PPM + TOP_PAD) + 'px';
    o.style.height = (dur * PPM - GAP) + 'px';
    body.appendChild(o);
    moveDrag.outline = o;
  }
  function finishMove() {
    if (!moveDrag) { return; }
    if (moveDrag.lp) { clearTimeout(moveDrag.lp); }
    if (moveDrag.ghost && moveDrag.ghost.parentNode) { moveDrag.ghost.parentNode.removeChild(moveDrag.ghost); }
    if (moveDrag.outline && moveDrag.outline.parentNode) { moveDrag.outline.parentNode.removeChild(moveDrag.outline); }
    moveDrag.block.classList.remove('mclb-mblock--dragging', 'mclb-mblock--pressing');
    root.classList.remove('is-moving');
    state.dragging = false;
    moveDrag = null;
  }
  function clearMove() { finishMove(); }
  function laneById(id) {
    var found = null;
    (state.data.types || []).forEach(function (grp) { grp.lanes.forEach(function (l) { if (l.id === id) { found = l; } }); });
    return found;
  }
  function allLanes() {
    var out = [];
    (state.data.types || []).forEach(function (grp) { grp.lanes.forEach(function (l) { out.push({ id: l.id, name: l.name, type: grp.type }); }); });
    return out;
  }
  function laneClash(laneId, startMin, endMin, selfId) {
    return (state.data.bookings || []).some(function (b) {
      return b.lane_id === laneId && !b.cancelled && !b.held && b.id !== selfId && startMin < b.end_min && b.start_min < endMin;
    });
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
      var dstr = (state.data && state.data.date_label) || state.date;
      panel.appendChild(el('p', 'mclb-panel__ctx', lane.name + ' · ' + dstr + ' · ' + minToLabel(startMin) + '–' + minToLabel(endMin)));

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
      var name = field(bookBody, I18N.name || 'Name', mkInput('text'));
      var email = field(bookBody, I18N.email || 'Email', mkInput('email'));
      var phone = field(bookBody, (I18N.phone || 'Phone') + ' *', mkInput('tel'));
      var note = field(bookBody, I18N.notes || 'Note', mkInput('text'));
      var coach = el('select'); coach.appendChild(opt('0', '— No coach —'));
      field(bookBody, CFG.labels.staffSingular || 'Coach', coach);
      // Coach time is always shown (it bounds which coaches are free): narrowing it
      // re-fetches the free-coach list for that window, so a coach busy for the
      // whole slot but free for a sub-range can still be picked (v1.4.1 fix #1).
      var ct = buildCoachTime(startMin, endMin);
      bookBody.appendChild(ct.wrap);

      var laneRate = lane.price_per_hour || 0, hours = (endMin - startMin) / 60;
      var baseLane = Math.round(laneRate * hours * 100) / 100;
      var priceIn = field(bookBody, I18N.lanePrice || 'Lane price', mkInput('number'));
      priceIn.step = '0.01'; priceIn.min = '0'; priceIn.value = baseLane.toFixed(2);
      var reasonWrap = el('label', 'mclb-panel__field'); reasonWrap.style.display = 'none';
      reasonWrap.appendChild(el('span', null, I18N.overrideReason || 'Reason for price change'));
      var reason = mkInput('text'); reasonWrap.appendChild(reason); bookBody.appendChild(reasonWrap);

      var preview = el('p', 'mclb-panel__preview');
      bookBody.appendChild(preview);
      var coachRates = {};
      var coachLoaded = false;
      var passState = { valid: false, balance: 0, code: '' }; // live pass preview (fix 2)
      function laneVal() { var v = parseFloat(priceIn.value); return isNaN(v) ? 0 : v; }
      function passCoveredHrs() { return passState.valid ? Math.min(Math.floor(passState.balance), Math.floor(hours)) : 0; }
      function updatePreview() {
        var isCoach = coach.value !== '0';
        var rate = coachRates[coach.value]; if (rate == null) { rate = 0; }
        var chrs = isCoach ? ct.hours() : 0;      // coach fee = rate × COACH hours (v1.4.1)
        var cfee = Math.round(rate * chrs * 100) / 100;
        var l = laneVal();
        // Prepaid pass (fix 2): covered whole lane hours come off the lane price.
        var cov = passCoveredHrs();
        var passAmt = Math.round(cov * laneRate * 100) / 100;
        var laneNet = Math.max(0, Math.round((l - passAmt) * 100) / 100);
        var passStr = cov > 0 ? (' − ' + (I18N.pass || 'Pass') + ' ' + cov + 'h (' + money(passAmt) + ')') : '';
        var coachStr = isCoach ? (' + ' + (I18N.perSessionCoach || 'Coach') + ' ' + money(cfee) + ' (' + fmtHrs(chrs) + ' × ' + money(rate) + ')') : '';
        preview.textContent = (I18N.perSessionLane || 'Lane') + ' ' + money(l) + passStr + coachStr + ' = ' + (I18N.due || 'Due') + ' ' + money(laneNet + (isCoach ? cfee : 0));
        reasonWrap.style.display = (Math.abs(l - baseLane) > 0.001) ? '' : 'none';
      }
      // Debounced free-coach fetch for the current coach window; keeps the chosen
      // coach if still free, resets to "No coach" if not, and disables Save until
      // a list is in hand (fix #1).
      var coachSeq = 0, coachTimer = null;
      function refreshCoaches() {
        var want = String(coach.value);
        var qs = '/coaches?date=' + state.date + '&start=' + timeInput(startMin) + '&end=' + timeInput(endMin);
        if (ct.partial()) { qs += '&coach_start=' + ct.startHHMM() + '&coach_end=' + ct.endHHMM(); }
        var seq = ++coachSeq;
        if (save) { save.disabled = true; }
        api(qs).then(function (res) {
          if (seq !== coachSeq) { return; } // a newer fetch superseded this one
          coachRates = {};
          while (coach.options.length > 1) { coach.remove(1); } // keep "— No coach —"
          if (res.ok && res.body.coaches) {
            res.body.coaches.forEach(function (c) { coachRates[String(c.id)] = c.rate; coach.appendChild(opt(String(c.id), c.name + ' — ' + money(c.rate) + '/hr')); });
          }
          coach.value = (want !== '0' && coachRates[want] != null) ? want : '0';
          coachLoaded = true;
          if (save) { save.disabled = false; }
          updatePreview();
        });
      }
      function scheduleCoachRefresh() { if (coachTimer) { clearTimeout(coachTimer); } coachTimer = setTimeout(refreshCoaches, 180); }
      coach.onchange = updatePreview;
      ct.onChange(function () { updatePreview(); scheduleCoachRefresh(); });
      priceIn.oninput = updatePreview;
      refreshCoaches(); updatePreview();

      // Prepaid pass (v1.5.0): optional code — covered whole lane hours price $0.
      var passCode = field(bookBody, I18N.passCode || 'Pass code (optional)', mkInput('text'));
      passCode.placeholder = 'CC-XXXX-XXXX'; passCode.style.textTransform = 'uppercase';
      var passNote = el('p', 'mclb-panel__sub'); bookBody.appendChild(passNote);
      var passTimer = null;
      function lookupPass() {
        var code = passCode.value.trim();
        if (code === '') { passState = { valid: false, balance: 0, code: '' }; passNote.textContent = ''; updatePreview(); return; }
        if (save) { save.disabled = true; } // hold Save while the check runs (fix 2)
        api('/pass/lookup?code=' + encodeURIComponent(code) + '&actor=' + encodeURIComponent((ini && ini.value.trim()) || 'x')).then(function (res) {
          if (res.ok && res.body && res.body.usable) {
            passState = { valid: true, balance: res.body.balance, code: res.body.code };
            var cov = passCoveredHrs(), after = Math.max(0, passState.balance - cov);
            passNote.style.color = '#1a7f37';
            passNote.textContent = (I18N.pass || 'Pass') + ' ' + passState.code + ' · ' + (I18N.covers || 'covers') + ' ' + cov + 'h · ' + after + 'h ' + (I18N.leftAfter || 'left after');
          } else {
            passState = { valid: false, balance: 0, code: '' };
            passNote.style.color = '#b32d2e';
            passNote.textContent = I18N.passInvalid || 'That pass code is invalid or expired.';
          }
          if (save) { save.disabled = !coachLoaded; }
          updatePreview();
        });
      }
      passCode.oninput = function () { if (passTimer) { clearTimeout(passTimer); } passTimer = setTimeout(lookupPass, 350); };

      var ovWrap = el('label', 'mclb-panel__override');
      var ov = el('input'); ov.type = 'checkbox';
      ovWrap.appendChild(ov); ovWrap.appendChild(document.createTextNode(' Override hours / blockouts'));
      bookBody.appendChild(ovWrap);
      var ovWarn = el('p', 'mclb-panel__warn', '⚠ Booking outside normal availability.'); ovWarn.style.display = 'none';
      bookBody.appendChild(ovWarn);

      // Book-over (v1.4.1): the dragged range overlaps a blockout on this lane.
      // Pre-tick override, name the blockout, and require a reason (History only).
      var blocking = (state.data.blockouts || []).filter(function (bo) {
        return (bo.lane_id === 0 || bo.lane_id === lane.id) && bo.start_min < endMin && bo.end_min > startMin;
      })[0];
      var boWrap = el('label', 'mclb-panel__field'); boWrap.style.display = 'none';
      boWrap.appendChild(el('span', null, I18N.bookOverReason || 'Reason for booking over the blockout'));
      var boReason = mkInput('text'); boWrap.appendChild(boReason); bookBody.appendChild(boWrap);
      if (blocking) {
        ov.checked = true;
        boWrap.style.display = '';
        var boNote = el('p', 'mclb-panel__warn', (I18N.slotBlocked || 'This slot is blocked') + ': ' + (blocking.type_label || 'Unavailable') + (blocking.note ? ' (' + blocking.note + ')' : ''));
        ovWrap.parentNode.insertBefore(boNote, ovWrap);
      }

      // Show the warning when the range is genuinely outside hours/blockouts/past,
      // or when override is ticked. (Advisory — the server is the authority.)
      var outside = rangeOutside(lane, startMin, endMin);
      function syncWarn() { ovWarn.style.display = (ov.checked || outside) ? '' : 'none'; boWrap.style.display = (blocking && ov.checked) ? '' : 'none'; }
      ov.onchange = syncWarn;
      syncWarn();

      // -- recurring (series) --
      var repeat = el('select');
      [['', I18N.repeatNone || 'Does not repeat'], ['weekly', I18N.repeatWeekly || 'Weekly'], ['fortnightly', I18N.repeatFortnightly || 'Fortnightly']].forEach(function (o) { repeat.appendChild(opt(o[0], o[1])); });
      field(bookBody, I18N.repeat || 'Repeat', repeat);
      var sOpts = el('div', 'mclb-series-opts'); sOpts.style.display = 'none'; bookBody.appendChild(sOpts);
      var endMode = el('select'); endMode.appendChild(opt('count', I18N.forN || 'For a number of times')); endMode.appendChild(opt('until', I18N.untilDate || 'Until a date'));
      field(sOpts, I18N.ends || 'Ends', endMode);
      var countLbl = el('label', 'mclb-panel__field'); countLbl.appendChild(el('span', null, I18N.occurrences || 'Occurrences (max 52)'));
      var countIn = mkInput('number'); countIn.min = '1'; countIn.max = '52'; countIn.value = '8'; countLbl.appendChild(countIn); sOpts.appendChild(countLbl);
      var untilLbl = el('label', 'mclb-panel__field'); untilLbl.style.display = 'none'; untilLbl.appendChild(el('span', null, I18N.untilDate || 'Until date'));
      var untilIn = mkInput('date'); untilLbl.appendChild(untilIn); sOpts.appendChild(untilLbl);
      var paidWrap = el('label', 'mclb-panel__override'); var paidUp = el('input'); paidUp.type = 'checkbox';
      paidWrap.appendChild(paidUp); paidWrap.appendChild(document.createTextNode(' ' + (I18N.paidUpfront || 'Paid upfront for whole series'))); sOpts.appendChild(paidWrap);
      endMode.onchange = function () { countLbl.style.display = endMode.value === 'count' ? '' : 'none'; untilLbl.style.display = endMode.value === 'until' ? '' : 'none'; };

      var ini = initialsField(bookBody);
      var err = errBox(bookBody);
      var save = el('button', 'button button-primary mclb-panel__save', 'Create booking');
      save.disabled = !coachLoaded; // enabled once the free-coach list is in hand (fix #1)
      bookBody.appendChild(save);
      var seriesBox = el('div', 'mclb-series-result'); seriesBox.style.display = 'none'; bookBody.appendChild(seriesBox);

      repeat.onchange = function () {
        sOpts.style.display = repeat.value ? '' : 'none';
        save.textContent = repeat.value ? (I18N.previewSeries || 'Preview series') : 'Create booking';
        seriesBox.style.display = 'none';
      };

      function seriesBody() {
        var body = {
          lane_id: lane.id, date: state.date, start: timeInput(startMin), end: timeInput(endMin),
          repeat: repeat.value, mode: endMode.value, count: countIn.value, until: untilIn.value,
          coach_id: parseInt(coach.value, 10) || 0, override: ov.checked ? 1 : 0
        };
        if (coach.value !== '0' && ct.partial()) { body.coach_start = ct.startHHMM(); body.coach_end = ct.endHHMM(); }
        if (passCode.value.trim()) { body.pass_code = passCode.value.trim(); }
        return body;
      }

      save.onclick = function () {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        if (!phone.value.trim()) { showErr(err, I18N.phoneRequired || 'A phone number is required.'); return; }
        initialsSet(ini.value.trim());

        if (blocking && ov.checked && !boReason.value.trim()) { showErr(err, I18N.bookOverReasonRequired || 'A reason is required to book over a blockout.'); return; }

        if (!repeat.value) {
          var cbody = {
            lane_id: lane.id, date: state.date, start: timeInput(startMin), end: timeInput(endMin),
            name: name.value, email: email.value, phone: phone.value, note: note.value,
            coach_id: parseInt(coach.value, 10) || 0, override: ov.checked ? 1 : 0,
            override_price: priceIn.value, price_reason: reason.value, actor: ini.value.trim()
          };
          if (coach.value !== '0' && ct.partial()) { cbody.coach_start = ct.startHHMM(); cbody.coach_end = ct.endHHMM(); }
          if (blocking && ov.checked) { cbody.book_over_reason = boReason.value.trim(); }
          if (passCode.value.trim()) { cbody.pass_code = passCode.value.trim(); }
          runWrite(save, err, api('/booking', { method: 'POST', body: cbody }));
          return;
        }

        // Series: preview first, then a confirm button books the available dates.
        setBusy(save, true); err.style.display = 'none';
        api('/series-preview', { method: 'POST', body: seriesBody() }).then(function (res) {
          setBusy(save, false);
          if (!res.ok) { showErr(err, errText(res)); return; }
          renderSeriesPreview(res.body);
        });
      };

      function renderSeriesPreview(p) {
        seriesBox.innerHTML = '';
        seriesBox.style.display = '';
        // Per-session breakdown (Lane + Coach = Due), matching the single-booking line.
        var perLine = (I18N.perSessionLane || 'Lane') + ' ' + money(p.per_lane)
          + (p.per_coach > 0 ? (' + ' + (I18N.perSessionCoach || 'Coach') + ' ' + money(p.per_coach)) : '')
          + ' = ' + money(p.per_due) + ' ' + (I18N.perSession || 'per session');
        seriesBox.appendChild(el('p', 'mclb-series-result__per', perLine));
        // Total line — net of pass cover when a valid pass is applied (fix 3).
        var headText;
        if (p.pass && p.pass.code) {
          var durHrs = Math.floor((endMin - startMin) / 60);
          var coveredCount = p.dates.filter(function (d) { return d.available && d.pass === 'covered'; }).length;
          var counter = Math.round((coveredCount * p.per_coach + (p.available - coveredCount) * p.per_due) * 100) / 100;
          headText = p.available + ' / ' + p.count + ' ' + (I18N.datesAvailable || 'dates available')
            + ' · ' + (I18N.dueAtCounter || 'Due at counter:') + ' ' + money(counter)
            + ' · ' + (coveredCount * durHrs) + 'h ' + (I18N.fromPass || 'from pass') + ' ' + p.pass.code;
        } else {
          headText = p.available + ' / ' + p.count + ' ' + (I18N.datesAvailable || 'dates available') + ' · ' + money(p.total) + ' ' + (I18N.total || 'total');
        }
        seriesBox.appendChild(el('p', 'mclb-series-result__head', headText));
        if (p.pass && p.pass.error) { seriesBox.appendChild(el('p', 'mclb-panel__warn', I18N.passInvalid || 'That pass code is invalid or expired.')); }
        var list = el('div', 'mclb-series-list');
        p.dates.forEach(function (d) {
          var row = el('div', 'mclb-series-row ' + (d.available ? 'is-ok' : 'is-bad'));
          row.appendChild(el('span', 'mclb-series-row__date', d.label));
          var state = d.available ? '✓' : ('✗ ' + seriesReason(d.reason));
          if (d.available && d.pass === 'covered') { state += ' · ' + (I18N.coveredByPass || 'covered by pass'); }
          else if (d.available && d.pass === 'empty') { state += ' · ' + (I18N.passEmpty || 'pass empty: due at counter'); }
          row.appendChild(el('span', 'mclb-series-row__state', state));
          list.appendChild(row);
        });
        seriesBox.appendChild(list);
        var book = el('button', 'button button-primary mclb-panel__save', (I18N.bookAvailable || 'Book available dates only') + ' (' + p.available + ')');
        book.disabled = p.available === 0;
        seriesBox.appendChild(book);
        book.onclick = function () {
          var body = seriesBody();
          body.name = name.value; body.email = email.value; body.phone = phone.value; body.note = note.value;
          body.paid_upfront = paidUp.checked ? 1 : 0; body.actor = ini.value.trim();
          setBusy(book, true);
          api('/series', { method: 'POST', body: body }).then(function (res) {
            if (res && res.ok) {
              // Keep the panel open on the summary until "Done" — do NOT load() here,
              // since render() rebuilds root and would wipe the panel. The grid is
              // refreshed when the staff member dismisses the summary.
              renderSeriesResult(res.body);
            } else { setBusy(book, false); showErr(err, errText(res)); }
          });
        };
      }
      function seriesReason(r) {
        if (r === 'lane') { return I18N.reasonLane || 'taken'; }
        if (r === 'coach') { return I18N.reasonCoach || 'coach busy'; }
        if (r === 'outside') { return I18N.reasonOutside || 'closed/out of hours'; }
        if (r === 'busy') { return I18N.reasonBusy || 'busy, retry'; }
        return r || '';
      }
      // Persistent result summary (stays until dismissed) — replaces the preview.
      function renderSeriesResult(b) {
        var planned = b.created + b.skipped.length;
        seriesBox.innerHTML = '';
        seriesBox.style.display = '';
        var head = el('p', 'mclb-series-result__head',
          (I18N.createdNofM || 'Created %n of %m sessions').replace('%n', b.created).replace('%m', planned));
        seriesBox.appendChild(head);
        if (b.skipped.length) {
          var sk = el('p', 'mclb-series-result__skipped',
            (I18N.skippedColon || 'Skipped:') + ' ' + b.skipped.map(function (s) {
              return (s.label || s.date) + ' (' + seriesReason(s.reason) + ')';
            }).join(', '));
          seriesBox.appendChild(sk);
        }
        var done = el('button', 'button button-primary mclb-panel__save', I18N.done || 'Done');
        done.onclick = function () { closePanel(); load(true); }; // refresh the grid now the summary is dismissed
        seriesBox.appendChild(done);
      }

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
      if (b.phone) { panel.appendChild(el('p', 'mclb-panel__ctx', (I18N.phone || 'Phone') + ': ' + b.phone)); }
      if (createdText(b)) { panel.appendChild(el('p', 'mclb-panel__ctx', createdText(b))); }
      if (couponEntry(b)) {
        var cp = el('p', 'mclb-panel__ctx mclb-panel__coupon', couponText(b));
        if (couponEntry(b).credit) { cp.classList.add('is-credit'); }
        panel.appendChild(cp);
      }
      if (b.price_original != null) {
        panel.appendChild(el('p', 'mclb-panel__warn', (I18N.priceOverridden || 'Price overridden') + ' (' + (I18N.was || 'was') + ' ' + money(b.price_original) + ')'));
      }
      if (b.series_id && b.series_m > 1) {
        panel.appendChild(el('p', 'mclb-panel__ctx', (I18N.series || 'Series') + ' · ' + (I18N.ofN || '%n of %m').replace('%n', b.series_n).replace('%m', b.series_m)));
      }

      // ── Prepaid pass (v1.5.0): show cover + return, or draw onto a manual booking.
      if (passHours(b)) {
        var prow = el('p', 'mclb-panel__ctx mclb-panel__pass', passChip(b));
        panel.appendChild(prow);
        if (b.source === 'manual' && !b.counter_paid) {
          var retBtn = el('button', 'button mclb-panel__act', I18N.returnToPass || 'Return hours to pass');
          panel.appendChild(retBtn);
          retBtn.onclick = function () {
            if (!needInitials()) { return; }
            runWrite(retBtn, err, api('/booking/' + b.id + '/pass', { method: 'POST', body: { op: 'return', actor: ini.value.trim() } }));
          };
        }
      } else if (b.source === 'manual' && !b.counter_paid) {
        var dpToggle = el('button', 'button mclb-panel__act', I18N.drawFromPass || 'Draw from pass');
        panel.appendChild(dpToggle);
        var dpForm = el('div', 'mclb-panel__edit'); dpForm.style.display = 'none'; panel.appendChild(dpForm);
        var dpCode = field(dpForm, I18N.passCode || 'Pass code', mkInput('text')); dpCode.placeholder = 'CC-XXXX-XXXX'; dpCode.style.textTransform = 'uppercase';
        var dpNote = el('p', 'mclb-panel__sub'); dpForm.appendChild(dpNote);
        var dpSave = el('button', 'button button-primary mclb-panel__save', I18N.drawFromPass || 'Draw from pass'); dpForm.appendChild(dpSave);
        var dpDur = Math.floor((b.end_min - b.start_min) / 60), dpTimer = null;
        dpToggle.onclick = function () { dpForm.style.display = (dpForm.style.display === 'none') ? '' : 'none'; };
        dpCode.oninput = function () {
          if (dpTimer) { clearTimeout(dpTimer); }
          dpTimer = setTimeout(function () {
            var c = dpCode.value.trim();
            if (c === '') { dpNote.textContent = ''; return; }
            dpSave.disabled = true;
            api('/pass/lookup?code=' + encodeURIComponent(c) + '&actor=' + encodeURIComponent((ini && ini.value.trim()) || 'x')).then(function (res) {
              dpSave.disabled = false;
              if (res.ok && res.body && res.body.usable) {
                var cov = Math.min(Math.floor(res.body.balance), dpDur), after = Math.max(0, res.body.balance - cov);
                dpNote.style.color = '#1a7f37';
                dpNote.textContent = (I18N.pass || 'Pass') + ' ' + res.body.code + ' · ' + (I18N.covers || 'covers') + ' ' + cov + 'h · ' + after + 'h ' + (I18N.leftAfter || 'left after');
              } else {
                dpNote.style.color = '#b32d2e';
                dpNote.textContent = I18N.passInvalid || 'That pass code is invalid or expired.';
              }
            });
          }, 350);
        };
        dpSave.onclick = function () {
          if (!needInitials()) { return; }
          if (!dpCode.value.trim()) { showErr(err, I18N.passCodeNeeded || 'Enter a pass code.'); return; }
          runWrite(dpSave, err, api('/booking/' + b.id + '/pass', { method: 'POST', body: { op: 'draw', code: dpCode.value.trim(), actor: ini.value.trim() } }));
        };
      }

      // ── Edit details (name / email / phone / staff note) — the audit log below
      //    records each change; "Notes" here is the editable staff note. ──
      var edToggle = el('button', 'button mclb-panel__act', I18N.editDetails || 'Edit details');
      panel.appendChild(edToggle);
      var edForm = el('div', 'mclb-panel__edit'); edForm.style.display = 'none'; panel.appendChild(edForm);
      var edName = field(edForm, I18N.name || 'Name', mkInput('text')); edName.value = b.customer || '';
      var edEmail = field(edForm, I18N.email || 'Email', mkInput('email')); edEmail.value = b.email || '';
      var edPhone = field(edForm, I18N.phone || 'Phone', mkInput('tel')); edPhone.value = b.phone_raw || '';
      var edNoteLbl = el('label', 'mclb-panel__field'); edNoteLbl.appendChild(el('span', null, I18N.notes || 'Notes'));
      var edNote = el('textarea'); edNote.rows = 2; edNote.value = b.staff_note || ''; edNoteLbl.appendChild(edNote); edForm.appendChild(edNoteLbl);
      var edSave = el('button', 'button button-primary mclb-panel__save', I18N.saveDetails || 'Save details'); edForm.appendChild(edSave);
      edToggle.onclick = function () { edForm.style.display = (edForm.style.display === 'none') ? '' : 'none'; };
      edSave.onclick = function () {
        if (!needInitials()) { return; }
        runWrite(edSave, err, api('/booking/' + b.id + '/details', { method: 'POST', body: {
          name: edName.value, email: edEmail.value, phone: edPhone.value, staff_note: edNote.value, actor: ini.value.trim()
        } }));
      };

      // ── Override lane price (manual + unpaid only; coach fee is never changed) ──
      if (b.source === 'manual' && !b.counter_paid) {
        var opToggle = el('button', 'button mclb-panel__act', I18N.overridePrice || 'Override price');
        panel.appendChild(opToggle);
        var opForm = el('div', 'mclb-panel__edit'); opForm.style.display = 'none'; panel.appendChild(opForm);
        var opPrice = field(opForm, I18N.lanePrice || 'Lane price', mkInput('number')); opPrice.step = '0.01'; opPrice.min = '0';
        opPrice.value = (b.price != null ? Number(b.price).toFixed(2) : '0.00');
        var opReason = field(opForm, I18N.overrideReason || 'Reason', mkInput('text'));
        var opPrev = el('p', 'mclb-panel__preview'); opForm.appendChild(opPrev);
        var opFee = b.coach_fee || 0;
        function opUpdate() { var l = parseFloat(opPrice.value); if (isNaN(l)) { l = 0; } opPrev.textContent = 'Lane ' + money(l) + (opFee ? ' + Coach ' + money(opFee) : '') + ' = Due ' + money(l + opFee); }
        opPrice.oninput = opUpdate; opUpdate();
        var opSave = el('button', 'button button-primary mclb-panel__save', I18N.savePrice || 'Save price'); opForm.appendChild(opSave);
        opToggle.onclick = function () { opForm.style.display = (opForm.style.display === 'none') ? '' : 'none'; };
        opSave.onclick = function () {
          if (!needInitials()) { return; }
          if (!opReason.value.trim()) { showErr(err, I18N.reasonRequired || 'A reason is required.'); return; }
          runWrite(opSave, err, api('/booking/' + b.id + '/price', { method: 'POST', body: {
            price: opPrice.value, reason: opReason.value, actor: ini.value.trim()
          } }));
        };
      }

      // coach
      var coachRow = el('div', 'mclb-panel__row');
      coachRow.appendChild(el('span', 'mclb-panel__rlabel', (CFG.labels.staffSingular || 'Coach') + ': ' + (b.coach_label || '—')));
      panel.appendChild(coachRow);
      var coachSaveBtn = null; // set when the actions are built; enabled once coaches load
      var coach = el('select'); coach.appendChild(opt('0', '— No coach —'));
      field(panel, 'Change ' + (CFG.labels.staffSingular || 'coach'), coach);

      // Partial coach time (v1.4.1): always shown. Narrowing it re-fetches the
      // free-coach list for that window (fix #1), so a coach busy for the whole
      // slot but free for a sub-range can still be assigned. Defaults to this
      // booking's current coach window, else the whole session.
      var dct = buildCoachTime(b.start_min, b.end_min);
      if (b.coach_partial && b.coach_start_min != null && b.coach_end_min != null) {
        dct.cStart.value = String(b.coach_start_min); dct.cEnd.value = String(b.coach_end_min);
      }
      panel.appendChild(dct.wrap);

      // Series scope: apply the coach change to this one, or this + all following.
      var scopeSel = null;
      if (b.series_id && b.series_m > 1) {
        scopeSel = el('select');
        scopeSel.appendChild(opt('this', I18N.scopeThis || 'This session only'));
        scopeSel.appendChild(opt('following', I18N.scopeFollowing || 'This and all following'));
        field(panel, I18N.applyTo || 'Apply to', scopeSel);
      }

      var dSeq = 0, dTimer = null;
      function refreshDetailCoaches() {
        var want = coach.value ? String(coach.value) : (b.coach_id ? String(b.coach_id) : '0');
        var qs = '/coaches?date=' + state.date + '&start=' + timeInput(b.start_min) + '&end=' + timeInput(b.end_min) + '&exclude=' + b.id;
        if (dct.partial()) { qs += '&coach_start=' + dct.startHHMM() + '&coach_end=' + dct.endHHMM(); }
        var seq = ++dSeq;
        if (coachSaveBtn) { coachSaveBtn.disabled = true; }
        api(qs).then(function (res) {
          if (seq !== dSeq) { return; }
          var list = (res.ok && res.body.coaches) ? res.body.coaches : [];
          while (coach.options.length > 1) { coach.remove(1); } // keep "— No coach —"
          // Keep the booking's current coach selectable even if busy elsewhere now.
          if (b.coach_id && !list.some(function (c) { return c.id === b.coach_id; })) {
            coach.appendChild(opt(String(b.coach_id), b.coach_label));
          }
          list.forEach(function (c) { coach.appendChild(opt(String(c.id), c.name + ' — ' + money(c.rate) + '/hr')); });
          var has = Array.prototype.some.call(coach.options, function (o) { return o.value === want; });
          coach.value = has ? want : (b.coach_id ? String(b.coach_id) : '0');
          if (coachSaveBtn) { coachSaveBtn.disabled = false; }
        });
      }
      function scheduleDetailRefresh() { if (dTimer) { clearTimeout(dTimer); } dTimer = setTimeout(refreshDetailCoaches, 180); }
      dct.onChange(scheduleDetailRefresh);
      refreshDetailCoaches();
      var coachNote = mkInput('text'); coachNote.placeholder = 'note (required if counter paid)';
      if (b.counter_paid) { field(panel, 'Note', coachNote); }

      // Coach-fee override (v1.4.1): mirrors the lane-price override — allowed on
      // any booking while the coach fee is unpaid; a reason is required.
      if (b.coach_id > 0 && !b.counter_paid) {
        if (b.coach_fee_original != null) {
          panel.appendChild(el('p', 'mclb-panel__warn', (I18N.coachFeeOverridden || 'Coach fee overridden') + ' (' + (I18N.was || 'was') + ' ' + money(b.coach_fee_original) + ')'));
        }
        var cfToggle = el('button', 'button mclb-panel__act', I18N.overrideCoachFee || 'Override coach fee');
        panel.appendChild(cfToggle);
        var cfForm = el('div', 'mclb-panel__edit'); cfForm.style.display = 'none'; panel.appendChild(cfForm);
        var cfFee = field(cfForm, I18N.coachFee || 'Coach fee', mkInput('number')); cfFee.step = '0.01'; cfFee.min = '0';
        cfFee.value = (b.coach_fee != null ? Number(b.coach_fee).toFixed(2) : '0.00');
        var cfReason = field(cfForm, I18N.overrideReason || 'Reason', mkInput('text'));
        var cfSave = el('button', 'button button-primary mclb-panel__save', I18N.saveCoachFee || 'Save coach fee'); cfForm.appendChild(cfSave);
        cfToggle.onclick = function () { cfForm.style.display = (cfForm.style.display === 'none') ? '' : 'none'; };
        cfSave.onclick = function () {
          if (!needInitials()) { return; }
          if (!cfReason.value.trim()) { showErr(err, I18N.reasonRequired || 'A reason is required.'); return; }
          runWrite(cfSave, err, api('/booking/' + b.id + '/coach-fee', { method: 'POST', body: {
            fee: cfFee.value, reason: cfReason.value, actor: ini.value.trim()
          } }));
        };
      }

      // counter
      if (b.counter_due != null && b.counter_due > 0) {
        var cr = el('div', 'mclb-panel__row');
        cr.appendChild(el('span', 'mclb-panel__rlabel', 'Counter due: ' + money(b.counter_due) + (b.counter_paid ? ' (paid)' : '')));
        panel.appendChild(cr);
      }

      // Staff note (read view) — editable version lives in "Edit details" above.
      if (b.staff_note) {
        var sn = el('div', 'mclb-panel__notes');
        sn.appendChild(el('strong', null, I18N.notes || 'Notes'));
        sn.appendChild(el('div', 'mclb-panel__noteln', b.staff_note));
        panel.appendChild(sn);
      }

      // audit history (admin_note, append-only, read-only)
      if (b.note) {
        var hist = el('div', 'mclb-panel__notes');
        hist.appendChild(el('strong', null, I18N.history || 'History'));
        b.note.split('\n').forEach(function (ln) { hist.appendChild(el('div', 'mclb-panel__noteln', ln)); });
        panel.appendChild(hist);
      }

      var ini = initialsField(panel);
      var err = errBox(panel);

      function needInitials() { if (!ini.value.trim()) { showErr(err, I18N.needInitials); return false; } initialsSet(ini.value.trim()); return true; }

      // actions
      var actions = el('div', 'mclb-panel__actions');
      actions.appendChild(actionBtn(I18N.move || 'Move…', function () {
        openMoveForm(b, { date: state.date, lane_id: b.lane_id, start_min: b.start_min, dur: b.end_min - b.start_min });
      }));
      if (b.source === 'manual') {
        var resendBtn = actionBtn(I18N.resendConfirmation || 'Resend confirmation', function (btn) {
          if (!needInitials()) { return; }
          runWrite(btn, err, api('/booking/' + b.id + '/resend-confirmation', { method: 'POST', body: { actor: ini.value.trim() } }));
        });
        if (!b.email) { resendBtn.disabled = true; resendBtn.title = I18N.noEmailOnFile || 'No email address on file'; }
        actions.appendChild(resendBtn);
      }
      coachSaveBtn = actionBtn('Save ' + (CFG.labels.staffSingular || 'coach'), function (btn) {
        if (!needInitials()) { return; }
        var coachId = parseInt(coach.value, 10) || 0;
        // A counter-paid booking needs an explicit note to change/remove the coach.
        if (b.counter_paid && !coachNote.value.trim()) {
          showErr(err, I18N.noteRequiredPaid || 'A note is required because the counter payment has been taken.');
          return;
        }
        var cbody = { coach_id: coachId, note: coachNote.value, actor: ini.value.trim() };
        if (coachId > 0 && dct.partial()) { cbody.coach_start = dct.startHHMM(); cbody.coach_end = dct.endHHMM(); }
        if (scopeSel) { cbody.scope = scopeSel.value; }
        runWrite(btn, err, api('/booking/' + b.id + '/coach', { method: 'POST', body: cbody }));
      });
      // Disabled until the free-coach list has loaded + the current coach is
      // preselected, so an accidental click can't fire the default "No coach".
      coachSaveBtn.disabled = true;
      actions.appendChild(coachSaveBtn);
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
      // Notify-customer checkbox (v1.4.3), shared by the cancel actions; default
      // on, disabled with a note when there's no email on file.
      var cnWrap = el('label', 'mclb-panel__override mclb-panel__notify');
      var cnCb = el('input'); cnCb.type = 'checkbox'; cnCb.checked = !!b.email;
      cnWrap.appendChild(cnCb);
      cnWrap.appendChild(document.createTextNode(' ' + (I18N.notifyCustomer || 'Notify customer by email')));
      if (!b.email) { cnCb.disabled = true; cnWrap.appendChild(el('span', 'mclb-panel__sub', ' (' + (I18N.noEmailOnFile || 'No email address on file') + ')')); }
      actions.appendChild(cnWrap);
      function notifyVal() { return cnCb.checked ? 1 : 0; }
      // Paid-online hint (addition a): staff should refund the card, not just cancel.
      if (b.source === 'online' && b.order_id && b.price > 0) {
        actions.appendChild(el('p', 'mclb-panel__warn', (I18N.paidOnlineHint || 'Customer paid %s online. Use Cancel + refund to refund their card.').replace('%s', money(b.price))));
      }
      actions.appendChild(actionBtn('Cancel only', function () {
        if (!needInitials()) { return; }
        confirmStep(actions, 'Cancel this booking?', function (yesBtn) {
          runWrite(yesBtn, err, api('/booking/' + b.id + '/cancel', { method: 'POST', body: { mode: 'only', notify: notifyVal(), actor: ini.value.trim() } }));
        });
      }, 'is-danger'));
      if (CANREFUND && b.refundable) {
        actions.appendChild(actionBtn('Cancel + refund', function () {
          if (!needInitials()) { return; }
          confirmStep(actions, 'Cancel and refund online payment?', function (yesBtn) {
            runWrite(yesBtn, err, api('/booking/' + b.id + '/cancel', { method: 'POST', body: { mode: 'refund', notify: notifyVal(), actor: ini.value.trim() } }));
          });
        }, 'is-danger'));
      }
      // Series cancel (future-only). "Cancel only" above already handles "this one".
      // Flow: a dry-run fetches the count + date range + any sensitive (paid/past)
      // sessions, so a SINGLE confirm states exactly what will go; confirming then
      // cancels in one call (no stacked second confirm).
      if (b.series_id && b.series_m > 1) {
        function seriesCancelBtn(mode, label) {
          return actionBtn(label, function (btn) {
            if (!needInitials()) { return; }
            setBusy(btn, true);
            api('/booking/' + b.id + '/cancel-series', { method: 'POST', body: { mode: mode, dry_run: 1, actor: ini.value.trim() } }).then(function (res) {
              setBusy(btn, false);
              if (!(res && res.ok && res.body && res.body.dry_run)) { showErr(err, errText(res)); return; }
              var d = res.body;
              if (d.count === 0) { showErr(err, I18N.noneToCancel || 'No future sessions to cancel.'); return; }
              var range = d.count === 1 ? d.first : (d.first + ' – ' + d.last);
              var q = (I18N.confirmCancelN || 'Cancel %n sessions (%r)?').replace('%n', d.count).replace('%r', range);
              if (d.sensitive_count > 0) {
                q += ' ' + (I18N.seriesPaidWarn || '%n prepaid or past session(s) will be cancelled with no automatic refund:').replace('%n', d.sensitive_count) + ' ' + d.sensitive_dates.join(', ');
              }
              confirmStep(actions, q, function (yes) {
                runWrite(yes, err, api('/booking/' + b.id + '/cancel-series', { method: 'POST', body: { mode: mode, confirm_paid: 1, notify: notifyVal(), actor: ini.value.trim() } }));
              });
            });
          }, 'is-danger');
        }
        actions.appendChild(seriesCancelBtn('following', I18N.cancelFollowing || 'Cancel this & following'));
        actions.appendChild(seriesCancelBtn('whole', I18N.cancelWhole || 'Cancel whole series (future)'));
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

  // ── MOVE panel (drag-drop target, or the "Move…" button) ──────────────────────
  function radio(name, val, checked) { var i = el('input'); i.type = 'radio'; i.name = name; i.value = val; i.checked = !!checked; return i; }
  function durLabel(mins) {
    var h = Math.floor(mins / 60), m = mins % 60, out = [];
    if (h) { out.push(h + ' hr'); }
    if (m) { out.push(m + ' min'); }
    return out.join(' ') || '0 min';
  }
  function hmToMin(v) { var p = (v || '').split(':'); return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0); }

  function openMoveForm(b, prefill) {
    prefill = prefill || { date: state.date, lane_id: b.lane_id, start_min: b.start_min, dur: b.end_min - b.start_min };
    openPanel(function (panel) {
      panelHead(panel, (I18N.moveTitle || 'Move booking') + ' · #' + b.id);
      panel.appendChild(el('p', 'mclb-panel__ctx', (b.customer || 'Guest') + ' · ' + minToLabel(b.start_min) + '–' + minToLabel(b.end_min)));

      var dateIn = mkInput('date'); dateIn.value = prefill.date; field(panel, I18N.moveDate || 'Date', dateIn);

      var laneSel = el('select');
      (state.data.types || []).forEach(function (grp) {
        var og = document.createElement('optgroup'); og.label = grp.type || '';
        grp.lanes.forEach(function (l) { var o = opt(String(l.id), l.name); if (l.id === prefill.lane_id) { o.selected = true; } og.appendChild(o); });
        laneSel.appendChild(og);
      });
      field(panel, I18N.moveResource || 'Resource', laneSel);

      var startIn = mkInput('time'); startIn.step = INC * 60; startIn.value = timeInput(prefill.start_min);
      field(panel, I18N.moveStart || 'Start time', startIn);

      var durSel = el('select');
      for (var d = INC; d <= 8 * 60; d += INC) { var o = opt(String(d), durLabel(d)); if (d === prefill.dur) { o.selected = true; } durSel.appendChild(o); }
      field(panel, I18N.moveDuration || 'Duration', durSel);

      var ovWrap = el('label', 'mclb-panel__override');
      var ov = el('input'); ov.type = 'checkbox';
      ovWrap.appendChild(ov); ovWrap.appendChild(document.createTextNode(' ' + (I18N.moveOverride || 'Override hours / blockouts')));
      panel.appendChild(ovWrap);

      var preview = el('p', 'mclb-panel__preview', I18N.moveChecking || 'Checking…');
      panel.appendChild(preview);

      // Refund choice — revealed only for an online move to a cheaper slot.
      var refundWrap = el('div', 'mclb-panel__refund'); refundWrap.style.display = 'none';
      var rRefund = radio('mclb_refund', 'refund', true);
      var rNone = radio('mclb_refund', 'none', false);
      var refTextR = document.createTextNode('');
      var lblR = el('label'); lblR.appendChild(rRefund); lblR.appendChild(refTextR);
      var lblN = el('label'); lblN.appendChild(rNone); lblN.appendChild(document.createTextNode(' ' + (I18N.moveRefundNo || 'No refund')));
      var reasonWrap = el('label', 'mclb-panel__field'); reasonWrap.style.display = 'none';
      reasonWrap.appendChild(el('span', null, I18N.moveRefundReason || 'Reason for no refund'));
      var reason = mkInput('text'); reasonWrap.appendChild(reason);
      refundWrap.appendChild(lblR); refundWrap.appendChild(lblN); refundWrap.appendChild(reasonWrap);
      panel.appendChild(refundWrap);
      function syncReason() { reasonWrap.style.display = rNone.checked ? '' : 'none'; }
      rRefund.onchange = syncReason; rNone.onchange = syncReason;

      // Notify-customer checkbox (default on) — staff can skip the "moved" email,
      // e.g. when the customer is at the counter.
      var notifyWrap = el('label', 'mclb-panel__override');
      var notify = el('input'); notify.type = 'checkbox'; notify.checked = true;
      notifyWrap.appendChild(notify); notifyWrap.appendChild(document.createTextNode(' ' + (I18N.notifyCustomer || 'Notify customer by email')));
      panel.appendChild(notifyWrap);

      var ini = initialsField(panel);
      var err = errBox(panel);
      var save = el('button', 'button button-primary mclb-panel__save', I18N.moveSave || 'Move booking');
      panel.appendChild(save);

      function endTime() { return timeInput(hmToMin(startIn.value) + (parseInt(durSel.value, 10) || INC)); }
      var pvTimer = null;
      function schedule() { if (pvTimer) { clearTimeout(pvTimer); } pvTimer = setTimeout(doPreview, 180); }
      function doPreview() {
        preview.className = 'mclb-panel__preview'; preview.textContent = I18N.moveChecking || 'Checking…';
        save.disabled = true; refundWrap.style.display = 'none';
        api('/booking/' + b.id + '/move-preview', { method: 'POST', body: {
          lane_id: parseInt(laneSel.value, 10) || 0, date: dateIn.value, start: startIn.value, end: endTime(), override: ov.checked ? 1 : 0
        } }).then(function (res) {
          if (!res.ok) { preview.classList.add('is-bad'); preview.textContent = errText(res); return; }
          renderPreview(res.body);
        });
      }
      function renderPreview(p) {
        if (!p.available) {
          preview.classList.add('is-bad');
          if (p.reason === 'lane') { preview.textContent = (I18N.moveClash || 'Clashes with') + ' ' + (p.conflict_label || 'another booking') + '.'; save.disabled = true; }
          else if (p.reason === 'coach') { preview.textContent = (I18N.moveClash || 'Clashes with') + ' ' + (CFG.labels.staffSingular || 'coach') + (p.conflict_label ? ' (' + p.conflict_label + ')' : '') + '.'; save.disabled = true; }
          else if (p.reason === 'outside') { preview.textContent = I18N.moveOutside || '⚠ Outside normal availability.'; save.disabled = !ov.checked; }
          else { preview.textContent = 'Not available.'; save.disabled = true; }
          return;
        }
        preview.classList.add('is-ok');
        var absd = money(Math.abs(p.delta));
        if (p.mode === 'counter_up') { preview.textContent = '+' + absd + ' ' + (I18N.movePriceUp || 'to collect at counter') + '.'; }
        else if (p.mode === 'manual_recompute') { preview.textContent = (I18N.lanePrice || 'Lane price') + ' ' + money(p.old_price) + ' → ' + money(p.new_lane_price) + ' (' + (I18N.movePriceUp || 'at counter') + ').'; }
        else if (p.mode === 'refund_or_none') {
          preview.textContent = (I18N.moveRefundQ || 'This slot is cheaper. Refund the difference to the card?');
          refTextR.textContent = ' ' + (I18N.moveRefundYes || 'Refund %s to card').replace('%s', absd);
          refundWrap.style.display = ''; rRefund.checked = true; syncReason();
        } else if (p.mode === 'locked_paid' && Math.abs(p.delta) > 0.001) { preview.textContent = I18N.moveLockedPaid || 'Counter payment taken — handled manually.'; }
        else { preview.textContent = (state.data && '✓ ') + minToLabel(hmToMin(startIn.value)) + '–' + minToLabel(hmToMin(endTime())); }
        save.disabled = false;
      }
      [dateIn, laneSel, startIn, durSel].forEach(function (f) { f.onchange = schedule; });
      ov.onchange = schedule;
      doPreview();

      save.onclick = function () {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        if (refundWrap.style.display !== 'none' && rNone.checked && !reason.value.trim()) { showErr(err, I18N.reasonRequired || 'A reason is required.'); return; }
        initialsSet(ini.value.trim());
        var body = {
          lane_id: parseInt(laneSel.value, 10) || 0, date: dateIn.value, start: startIn.value, end: endTime(),
          override: ov.checked ? 1 : 0, notify: notify.checked ? 1 : 0, actor: ini.value.trim()
        };
        if (refundWrap.style.display !== 'none') { body.refund_choice = rNone.checked ? 'none' : 'refund'; body.refund_reason = reason.value; }
        setBusy(save, true); if (err) { err.style.display = 'none'; }
        api('/booking/' + b.id + '/move', { method: 'POST', body: body }).then(function (res) {
          if (res && res.ok) {
            closePanel();
            var label = (res.body && res.body.moved_to) || '';
            var jumped = res.body && res.body.date && res.body.date !== state.date;
            if (jumped) { toast((I18N.movedToast || 'Moved to %s').replace('%s', label), I18N.goToDay || 'Go to that day', function () { state.date = res.body.date; load(); }); }
            else { toast((I18N.movedToast || 'Moved to %s').replace('%s', label)); }
            // Partial coach time (v1.4.1): warn when the coach window was clamped
            // to fit a shorter session.
            if (res.body && res.body.coach_clamped) { toast(I18N.coachClamped || 'Coach time was shortened to fit the new session.'); }
            load(true);
          } else { setBusy(save, false); showErr(err, errText(res)); }
        });
      };
    });
  }

  // Transient toast at the bottom of the calendar (e.g. after a cross-day move).
  function toast(msg, linkText, linkFn) {
    var t = el('div', 'mclb-toast');
    t.appendChild(el('span', null, msg));
    if (linkText && linkFn) { var a = el('button', 'mclb-toast__link', linkText); a.onclick = function () { t.remove(); linkFn(); }; t.appendChild(a); }
    root.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { if (t.parentNode) { t.remove(); } }, 400); }, 7000);
  }

  // ── BLOCKOUT panel (click a blockout) ─────────────────────────────────────────
  function openBlockout(bo) {
    openPanel(function (panel) {
      panelHead(panel, (bo.type_label || 'Blockout') + ' · ' + minToLabel(bo.start_min) + '–' + minToLabel(bo.end_min));
      var recurring = !bo.editable;
      if (recurring) {
        panel.appendChild(el('p', 'mclb-panel__ctx', (I18N.recurringBlockout || 'Recurring blockout') + ' — ' + (I18N.editOnClosures || 'edit it on the Closures screen') + '.'));
      }
      if (bo.note) { panel.appendChild(el('p', 'mclb-panel__ctx', (I18N.note || 'Note') + ': ' + bo.note)); }

      var ini = initialsField(panel);
      var err = errBox(panel);

      // Existing releases for this blockout on this date (fix #6): show them with
      // an Undo instead of re-offering "Ended early" as if nothing happened.
      var myReleases = ((state.data && state.data.released) || []).filter(function (mk) { return mk.closure_id === bo.id; });
      if (myReleases.length) {
        var relDone = el('div', 'mclb-panel__release');
        relDone.appendChild(el('strong', null, (I18N.alreadyReleased || 'Already released') + ' · ' + ((state.data && state.data.date_label) || state.date)));
        myReleases.forEach(function (mk) {
          var row = el('div', 'mclb-panel__relrow');
          var when = mk.from ? ((I18N.releasedFrom || 'from') + ' ' + mk.from) : (I18N.wholeDay || 'whole day');
          row.appendChild(el('span', null, when + (mk.actor ? ' (' + mk.actor + ')' : '')));
          var undo = el('button', 'button mclb-panel__act', I18N.undoRelease || 'Undo release');
          undo.onclick = function () { closePanel(); openReleaseUndo(mk); };
          row.appendChild(undo);
          relDone.appendChild(row);
        });
        panel.appendChild(relDone);
      }

      // ── Release this date (v1.4.1) — frees the slot without touching the rule.
      //    Works for one-off and recurring blockouts alike. "Release the whole
      //    blockout" is the Delete button below (one-off only). Hidden once a
      //    release already exists for this date (undo it first). ──
      if (!myReleases.length) {
        var rel = el('div', 'mclb-panel__release');
        rel.appendChild(el('strong', null, (I18N.releaseThisDate || 'Release this date') + ' · ' + ((state.data && state.data.date_label) || state.date)));
        var endEarly = el('select');
        endEarly.appendChild(opt('', I18N.releaseWholeDay || 'Free the whole day'));
        for (var m = bo.start_min + INC; m < bo.end_min; m += INC) { endEarly.appendChild(opt(timeInput(m), (I18N.endedEarlyAt || 'Ended early at') + ' ' + minToLabel(m))); }
        field(rel, I18N.howMuch || 'How much', endEarly);
        var relBtn = actionBtn(I18N.release || 'Release', function (btn) {
          if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
          initialsSet(ini.value.trim());
          var body = { date: state.date, actor: ini.value.trim() };
          if (endEarly.value) { body.release_from = endEarly.value; }
          if (bo.lane_id) { body.lane_id = bo.lane_id; }
          runWrite(btn, err, api('/closure/' + bo.id + '/release', { method: 'POST', body: body }));
        });
        var relActions = el('div', 'mclb-panel__actions'); relActions.appendChild(relBtn); rel.appendChild(relActions);
        panel.appendChild(rel);
      }

      if (recurring) { return; } // no edit/delete for recurring here

      // ── Edit / delete the one-off blockout ──
      var etype = el('select'); etype.appendChild(opt('', '— Event type —'));
      (CFG.eventTypes || []).forEach(function (t) { var o = opt(t.slug, t.label); if (t.slug === bo.event_type) { o.selected = true; } etype.appendChild(o); });
      field(panel, 'Event type', etype);
      var note = field(panel, 'Note', mkInput('text')); note.value = bo.note || '';
      var actions = el('div', 'mclb-panel__actions');
      actions.appendChild(actionBtn(I18N.save || 'Save', function (btn) {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        runWrite(btn, err, api('/blockout/' + bo.id, { method: 'POST', body: { event_type: etype.value, note: note.value, actor: ini.value.trim() } }));
      }));
      actions.appendChild(actionBtn((I18N.deleteWholeBlockout || 'Delete whole blockout'), function () {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        confirmStep(actions, 'Delete this blockout?', function (yesBtn) {
          runWrite(yesBtn, err, api('/blockout/' + bo.id, { method: 'DELETE', body: { actor: ini.value.trim() } }));
        });
      }, 'is-danger'));
      panel.appendChild(actions);
    });
  }

  // ── Undo a release (click a "released" marker) ────────────────────────────────
  function openReleaseUndo(m) {
    openPanel(function (panel) {
      panelHead(panel, (I18N.undoRelease || 'Undo release'));
      var scope = (m.lane_id === 0) ? '' : (' · ' + (laneName(m.lane_id) || ('#' + m.lane_id)));
      var when = m.from ? (' ' + (I18N.releasedFrom || 'from') + ' ' + m.from) : (' · ' + (I18N.wholeDay || 'whole day'));
      panel.appendChild(el('p', 'mclb-panel__ctx', (m.type_label || 'Blockout') + when + scope + ' · ' + ((state.data && state.data.date_label) || state.date) + (m.actor ? ' (' + m.actor + ')' : '')));
      panel.appendChild(el('p', 'mclb-panel__ctx', I18N.undoReleaseHint || 'This re-applies the blockout for this date. It will refuse if a booking now sits in the released window.'));
      var ini = initialsField(panel);
      var err = errBox(panel);
      var actions = el('div', 'mclb-panel__actions');
      actions.appendChild(actionBtn((I18N.undoRelease || 'Undo release'), function (btn) {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        runWrite(btn, err, api('/closure-exception/' + m.id, { method: 'DELETE', body: { actor: ini.value.trim() } }));
      }, 'is-danger'));
      panel.appendChild(actions);
    });
  }

  // ── Send day sheet (from the coach filter) ───────────────────────────────────
  function openSendSheet(coach, date) {
    date = date || state.date;
    openPanel(function (panel) {
      panelHead(panel, I18N.sendSheet || 'Send day sheet');
      panel.appendChild(el('p', 'mclb-panel__ctx', coach.name + ' · ' + date));
      if (CFG.testRecipient) {
        panel.appendChild(el('p', 'mclb-panel__warn', (I18N.testMode || 'Test mode:') + ' ' + (I18N.testGoesTo || 'this will go to') + ' ' + CFG.testRecipient + ' — ' + (I18N.notTheCoach || 'not the coach') + '.'));
      }
      if (coach.sheet_sent_at) {
        panel.appendChild(el('p', 'mclb-panel__ctx', (I18N.sheetSent || 'Sheet sent') + ' ' + coach.sheet_sent_at + (coach.sheet_changed ? ' — ' + (I18N.sheetChanged || 'changed since sent') : '')));
      }
      var ini = initialsField(panel);
      var err = errBox(panel);
      var actions = el('div', 'mclb-panel__actions');
      actions.appendChild(actionBtn(I18N.sendSheet || 'Send day sheet', function () {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        confirmStep(actions, (I18N.sendConfirm || 'Send the day sheet now?'), function (yesBtn) {
          setBusy(yesBtn, true); err.style.display = 'none';
          api('/daysheet', { method: 'POST', body: { coach_id: coach.id, date: date, actor: ini.value.trim() } })
            .then(function (res) {
              if (res.ok && res.body && res.body.ok) {
                yesBtn.textContent = (I18N.sentOk || 'Sent ✓');
                setTimeout(function () { closePanel(); reload(true); }, 900);
              } else {
                setBusy(yesBtn, false);
                showErr(err, (res.body && res.body.result) ? ((I18N.notSent || 'Not sent') + ': ' + res.body.result) : errText(res));
              }
            });
        });
      }));
      panel.appendChild(actions);
    });
  }

  function shiftDate(days) {
    var step = (state.view === 'roster' && state.rosterWeek) ? days * 7 : days; // Week moves a week
    var d = new Date(state.date + 'T00:00:00');
    d.setDate(d.getDate() + step);
    state.date = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    reload();
  }

  // ── Prepaid passes panel (v1.5.0): look up / void / reissue + issue ───────────
  function openPasses() {
    openPanel(function (panel) {
      panelHead(panel, I18N.passes || 'Passes');
      var ini = initialsField(panel);
      var err = errBox(panel);

      // Look up a code → balance + void / reissue.
      panel.appendChild(el('p', 'mclb-panel__ctx', I18N.passLookupHint || 'Look up a pass to check its balance or void it.'));
      var lkCode = field(panel, I18N.passCode || 'Pass code', mkInput('text')); lkCode.placeholder = 'CC-XXXX-XXXX'; lkCode.style.textTransform = 'uppercase';
      var lkOut  = el('div', 'mclb-panel__passout'); panel.appendChild(lkOut);
      var lkBtn  = el('button', 'button mclb-panel__act', I18N.checkBalance || 'Check balance');
      panel.appendChild(lkBtn);
      lkBtn.onclick = function () {
        if (!lkCode.value.trim()) { showErr(err, I18N.passCodeNeeded || 'Enter a pass code.'); return; }
        lkOut.innerHTML = ''; setBusy(lkBtn, true);
        api('/pass/lookup?code=' + encodeURIComponent(lkCode.value.trim()) + '&actor=' + encodeURIComponent(ini.value.trim() || 'x')).then(function (res) {
          setBusy(lkBtn, false);
          if (!res.ok) { showErr(err, errText(res)); return; }
          var p = res.body;
          lkOut.appendChild(el('p', 'mclb-panel__ctx', p.code + ' · ' + (p.holder || '—') + ' · ' + p.balance + 'h · ' + p.status + (p.expires_at ? ' · ' + (I18N.expires || 'expires') + ' ' + p.expires_at.substr(0, 10) : '')));
          if (p.status === 'active') {
            var acts = el('div', 'mclb-panel__actions');
            acts.appendChild(actionBtn(I18N.voidPass || 'Void', function (btn) {
              if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; } initialsSet(ini.value.trim());
              confirmStep(acts, I18N.voidConfirm || 'Void this pass? The code stops working now.', function (yes) {
                setBusy(yes, true);
                api('/pass/' + p.id + '/void', { method: 'POST', body: { actor: ini.value.trim() } }).then(function (r) { setBusy(yes, false); if (r.ok) { lkOut.innerHTML = ''; lkOut.appendChild(el('p', 'mclb-panel__ok', I18N.voided || 'Voided.')); } else { showErr(err, errText(r)); } });
              });
            }, 'is-danger'));
            acts.appendChild(actionBtn(I18N.reissuePass || 'Void + reissue', function (btn) {
              if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; } initialsSet(ini.value.trim());
              setBusy(btn, true);
              api('/pass/' + p.id + '/reissue', { method: 'POST', body: { actor: ini.value.trim() } }).then(function (r) { setBusy(btn, false); if (r.ok) { lkOut.innerHTML = ''; lkOut.appendChild(el('p', 'mclb-panel__ok', (I18N.newCode || 'New code:') + ' ' + r.body.code)); } else { showErr(err, errText(r)); } });
            }));
            lkOut.appendChild(acts);
          }
          // p.id isn't in the lookup payload — fetch it: add below.
        });
      };

      // Issue a new pass.
      panel.appendChild(el('hr'));
      panel.appendChild(el('strong', null, I18N.issuePass || 'Issue a pass'));
      panel.appendChild(el('p', 'mclb-panel__ctx', I18N.issuePassHint || 'Take payment on Square first, then issue the pass and record the receipt.'));
      var iName = field(panel, I18N.name || 'Holder name', mkInput('text'));
      var iEmail = field(panel, I18N.email || 'Email', mkInput('email'));
      var iPhone = field(panel, I18N.phone || 'Phone', mkInput('tel'));
      var iHours = field(panel, I18N.hours || 'Hours', mkInput('number')); iHours.min = '1'; iHours.step = '1';
      var iExp = field(panel, I18N.expiryMonths || 'Expiry (months, blank = default)', mkInput('number')); iExp.min = '0'; iExp.step = '1';
      var iPos = field(panel, I18N.posRef || 'POS receipt / reference', mkInput('text'));
      var iNote = field(panel, I18N.notes || 'Note', mkInput('text'));
      var issueOut = el('div', 'mclb-panel__passout'); panel.appendChild(issueOut);
      var iBtn = el('button', 'button button-primary mclb-panel__save', I18N.issuePass || 'Issue a pass');
      panel.appendChild(iBtn);
      iBtn.onclick = function () {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        if (!iHours.value || parseInt(iHours.value, 10) < 1) { showErr(err, I18N.passHoursNeeded || 'Enter the hours.'); return; }
        if (!iPos.value.trim()) { showErr(err, I18N.posRefNeeded || 'A POS receipt / reference is required.'); return; }
        initialsSet(ini.value.trim());
        setBusy(iBtn, true); err.style.display = 'none';
        api('/pass/issue', { method: 'POST', body: {
          holder_name: iName.value, holder_email: iEmail.value, holder_phone: iPhone.value,
          hours: iHours.value, expiry_months: iExp.value, pos_ref: iPos.value, note: iNote.value, actor: ini.value.trim()
        } }).then(function (res) {
          setBusy(iBtn, false);
          if (!res.ok) { showErr(err, errText(res)); return; }
          issueOut.innerHTML = '';
          issueOut.appendChild(el('p', 'mclb-panel__ok', (I18N.passIssued || 'Pass issued:') + ' ' + res.body.code + ' · ' + res.body.balance + 'h'));
          var copy = el('button', 'button', I18N.copy || 'Copy code');
          copy.onclick = function () { try { navigator.clipboard.writeText(res.body.code); copy.textContent = I18N.copied || 'Copied'; } catch (e) {} };
          issueOut.appendChild(copy);
        });
      };
    });
  }

  // ── Coach roster (v1.5.1) ─────────────────────────────────────────────────────
  function fmtH(h) { return (Math.round((h || 0) * 100) / 100).toString().replace(/\.0+$/, '') + 'h'; }
  // Short lane label for the narrow roster block: "Running Net 5 (24m)" → "Net 5",
  // "Bowling Machine 2" → "BM2". Drops any trailing size note in parentheses.
  function shortLane(name) {
    var n = String(name || '').replace(/\s*\([^)]*\)\s*$/, '').trim();
    n = n.replace(/Running\s+Net\s*/i, 'Net ').replace(/Bowling\s+Machine\s*/i, 'BM');
    return n.replace(/\s+/g, ' ').trim();
  }
  function namesMode() { return (state.rosterWeek && state.week) ? (state.week.names_mode || 'full') : ((state.data && state.data.names_mode) || 'full'); }
  function sheetName(raw) {
    raw = (raw || '').trim(); var mode = namesMode();
    if (raw === '' || mode === 'none') { return ''; }
    if (mode === 'full') { return raw; }
    var p = raw.split(/\s+/);
    return p.length === 1 ? p[0] : (p[0] + ' ' + p[p.length - 1].charAt(0).toUpperCase() + '.');
  }

  function loadWeek(silent) {
    var seq = ++state.loadSeq;
    return api('/week?start=' + encodeURIComponent(weekStart(state.date))).then(function (res) {
      if (seq !== state.loadSeq) { return; } // a newer load started — ignore this stale response
      if (res.session || res.status === 401) { root.innerHTML = sessionHtml(); return; }
      if (res.ok) { state.week = res.body; if (state.view === 'roster' && state.rosterWeek) { render(); } }
      else if (!silent) { root.innerHTML = '<p class="mclb-manage__err">' + esc(errText(res)) + '</p>'; }
    }).catch(function () { if (seq === state.loadSeq && !silent) { root.innerHTML = '<p class="mclb-manage__err">Could not load the roster.</p>'; } });
  }
  function weekStart(dateStr) { var d = new Date(dateStr + 'T00:00:00'); var dow = (d.getDay() + 6) % 7; d.setDate(d.getDate() - dow); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

  function renderRoster() {
    if (state.rosterWeek) {
      if (!state.week) { root.appendChild(el('p', 'mclb-manage__loading', esc(I18N.loading || 'Loading…'))); loadWeek(); return; }
      renderWeek(state.week);
    } else {
      renderRosterDay();
    }
  }

  // Per-coach day stats from a day's blocks (day view; week gets them server-side).
  function dayStats(bookings, coaches) {
    var byId = {};
    (bookings || []).forEach(function (b) {
      if (b.held || b.cancelled || !b.coach_id) { return; }
      var s = (b.coach_start_min != null) ? b.coach_start_min : b.start_min;
      var e = (b.coach_end_min != null) ? b.coach_end_min : b.end_min;
      var st = byId[b.coach_id] || (byId[b.coach_id] = { hours: 0, fees: 0 });
      st.hours += (e - s) / 60;
      st.fees += (b.coach_fee || 0);
    });
    var withS = [], none = [];
    (coaches || []).forEach(function (c) {
      if (byId[c.id]) {
        withS.push({ id: c.id, name: c.name, rate: c.rate, has_rate: c.rate != null, hours: byId[c.id].hours, fees: byId[c.id].fees, sheet_sent_at: c.sheet_sent_at, sheet_changed: c.sheet_changed });
      } else {
        none.push(c.name);
      }
    });
    return { withS: withS, none: none };
  }

  function renderRosterDay() {
    var d = state.data;
    var open = d.grid.open_min, close = d.grid.close_min;
    if (open == null || close == null) { root.appendChild(el('p', 'mclb-manage__empty', I18N.noOpen || 'Closed this day.')); return; }
    var st = dayStats(d.bookings, d.coaches);
    var nowMin = (state.date === CFG.today && d.now) ? dtToMin(d.now) : null;
    var wrap = el('div', 'mclb-ros-wrap');
    wrap.appendChild(rosterDayGrid(open, close, d.bookings, st.withS, st.none, nowMin, state.date));
    root.appendChild(wrap);
  }

  function renderWeek(wk) {
    var open = wk.grid.open_min, close = wk.grid.close_min;
    var wrap = el('div', 'mclb-ros-wrap mclb-ros-week');
    if (wk.week_totals && wk.week_totals.length) {
      var sum = el('div', 'mclb-ros__summary');
      sum.appendChild(el('strong', null, (I18N.weekTotals || 'Week totals') + ': '));
      wk.week_totals.forEach(function (t) {
        sum.appendChild(el('span', 'mclb-ros__sumchip', t.name + ' ' + fmtH(t.hours) + (t.has_rate ? ' · ' + money(t.fees) : ' · —')));
      });
      wrap.appendChild(sum);
    }
    (wk.days || []).forEach(function (day) {
      var sec = el('div', 'mclb-ros-day');
      sec.appendChild(el('div', 'mclb-ros-day__head', day.label));
      var nowMin = (day.date === CFG.today && wk.now) ? dtToMin(wk.now) : null;
      sec.appendChild(rosterDayGrid(open, close, day.bookings, day.coaches, day.none, nowMin, day.date));
      wrap.appendChild(sec);
    });
    root.appendChild(wrap);
  }

  // One day's roster grid. coaches = [{id,name,rate,has_rate,hours,fees,sheet_*}]
  // with sessions; none = names with no sessions (collapsed unless Show all).
  function rosterDayGrid(open, close, bookings, coaches, none, nowMin, date) {
    var span = Math.max(close - open, 1); // minutes across; blocks/ticks positioned by %
    var grid = el('div', 'mclb-ros');
    // Stretch to fill the roster width; keep a legible minimum hour width (scrolls below it).
    grid.style.minWidth = (ROS_GUT + Math.ceil(span / 60 * ROS_MINHR)) + 'px';
    var head = el('div', 'mclb-ros__head');
    head.appendChild(el('div', 'mclb-ros__gut'));
    var ticks = el('div', 'mclb-ros__ticks');
    for (var m = open; m < close; m += INC) { var t = el('div', 'mclb-ros__tick', minToLabel(m)); t.style.left = ((m - open) / span * 100) + '%'; ticks.appendChild(t); }
    head.appendChild(ticks); grid.appendChild(head);

    var byCoach = {};
    (bookings || []).forEach(function (b) { if (!b.held && !b.cancelled && b.coach_id) { (byCoach[b.coach_id] = byCoach[b.coach_id] || []).push(b); } });

    function row(c, empty) {
      var r = el('div', 'mclb-ros__row');
      var lab = el('div', 'mclb-ros__gut mclb-ros__label');
      lab.appendChild(el('div', 'mclb-ros__name', c.name));
      if (!empty) {
        var tEl = el('div', 'mclb-ros__tot', fmtH(c.hours) + ' · ' + (c.has_rate === false ? '—' : money(c.fees || 0)));
        if (c.has_rate === false) { tEl.appendChild(el('span', 'mclb-ros__norate', ' (' + (I18N.noRate || 'no rate set') + ')')); }
        lab.appendChild(tEl);
        lab.appendChild(rosterSheet(c, date));
      }
      r.appendChild(lab);
      var track = el('div', 'mclb-ros__track');
      if (nowMin != null && nowMin >= open && nowMin <= close) { var nl = el('div', 'mclb-ros__now'); nl.style.left = ((nowMin - open) / span * 100) + '%'; track.appendChild(nl); }
      (byCoach[c.id] || []).forEach(function (b) { track.appendChild(rosterBlock(b, open, span)); });
      r.appendChild(track);
      return r;
    }

    coaches.forEach(function (c) { grid.appendChild(row(c, false)); });
    if (state.showAll && none && none.length) {
      none.forEach(function (nm) { grid.appendChild(row({ id: -1, name: nm }, true)); });
    } else if (none && none.length) {
      grid.appendChild(el('div', 'mclb-ros__none', (I18N.noSessions || 'No sessions') + ': ' + none.join(', ')));
    }
    return grid;
  }

  function rosterSheet(c, date) {
    var wrap = el('div', 'mclb-ros__sheet');
    var note = c.sheet_sent_at ? ((I18N.sheetSent || 'Sheet sent') + (c.sheet_changed ? ' · ' + (I18N.sheetChanged || 'changed') : '')) : (I18N.notSent || 'Not sent');
    wrap.appendChild(el('span', 'mclb-ros__sheetnote', note));
    var btn = el('button', 'button mclb-cal__btn mclb-ros__send', c.sheet_sent_at ? (I18N.resend || 'Resend') : (I18N.send || 'Send'));
    btn.onclick = function (e) { e.stopPropagation(); openSendSheet(c, date); };
    wrap.appendChild(btn);
    return wrap;
  }

  function rosterBlock(b, open, span) {
    var block = el('div', 'mclb-mblock mclb-rosblock mclb-mblock--' + b.source);
    var s = (b.coach_start_min != null) ? b.coach_start_min : b.start_min;
    var e = (b.coach_end_min != null) ? b.coach_end_min : b.end_min;
    block.style.left = ((s - open) / span * 100) + '%';
    block.style.width = 'calc(' + ((e - s) / span * 100) + '% - 2px)';
    if (b.price_original != null) { block.classList.add('mclb-mblock--overridden'); }
    if (nowActive(b)) { block.classList.add('mclb-mblock--now'); }

    var cust  = (b.customer || '').trim() || (I18N.guest || 'Guest');
    var short = shortLane(b.lane_name);
    // Line 1 = customer (bold, screen — staff-only). Print swaps in the day-sheet
    // name formatting via the hidden print span; the lane sub shows on both.
    block.appendChild(el('span', 'mclb-rosblock__name', cust));
    var pn = sheetName(b.customer);
    if (pn) { block.appendChild(el('span', 'mclb-rosblock__name mclb-print-only', pn)); }
    // Line 2 = short lane name.
    block.appendChild(el('span', 'mclb-rosblock__sub', short));

    var seriesLabel = (b.series_id && b.series_m > 1) ? (I18N.series || 'Series') + ' · ' + (I18N.ofN || '%n of %m').replace('%n', b.series_n).replace('%m', b.series_m) : '';
    if (seriesLabel) { block.appendChild(el('span', 'mclb-rosblock__sub', seriesLabel)); }
    if (b.coach_partial && (b.end_min - b.start_min) > (e - s)) {
      block.appendChild(el('span', 'mclb-rosblock__sub', (I18N.laneTime || 'lane') + ' ' + minToLabel(b.start_min) + '–' + minToLabel(b.end_min)));
    }
    var pill = b.badges.filter(function (x) { return x.kind === 'due' || x.kind === 'refund'; })[0]
            || b.badges.filter(function (x) { return x.kind === 'counter_paid' || x.kind === 'paid_online'; })[0];
    var badges = el('div', 'mclb-rosblock__badges');
    if (pill) { badges.appendChild(el('span', 'mclb-badge mclb-badge--' + pill.kind, pill.label)); }
    if (passHours(b)) { badges.appendChild(el('span', 'mclb-badge mclb-badge--pass', I18N.pass || 'Pass')); }
    if (badges.childNodes.length) { block.appendChild(badges); }

    block.title = (b.lane_name || '') + ' · ' + minToLabel(s) + '–' + minToLabel(e) + ' · ' + cust
      + (seriesLabel ? ' · ' + seriesLabel : '')
      + (pill ? ' · ' + pill.label : '')
      + (passHours(b) ? ' · ' + passChip(b) : '');
    block.onclick = function () { openDetail(b); };
    return block;
  }

  // "Send day sheets to all coaches" for a chosen day (defaults to tomorrow).
  function openSendAll() {
    openPanel(function (panel) {
      panelHead(panel, I18N.sendAll || 'Send day sheets to all');
      if (CFG.testRecipient) {
        panel.appendChild(el('p', 'mclb-panel__warn', (I18N.testMode || 'Test mode:') + ' ' + (I18N.allSheetsTo || 'every day sheet will go to') + ' ' + CFG.testRecipient + ' — ' + (I18N.notTheCoaches || 'not the coaches') + '.'));
      }
      var dsel = field(panel, I18N.day || 'Day', mkInput('date'));
      var tmr = new Date(); tmr.setDate(tmr.getDate() + 1);
      dsel.value = tmr.getFullYear() + '-' + pad(tmr.getMonth() + 1) + '-' + pad(tmr.getDate());
      var ini = initialsField(panel);
      var err = errBox(panel);
      var out = el('div', 'mclb-panel__passout'); panel.appendChild(out);
      var actions = el('div', 'mclb-panel__actions');
      actions.appendChild(actionBtn(I18N.sendAll || 'Send day sheets to all', function (btn) {
        if (!ini.value.trim()) { showErr(err, I18N.needInitials); return; }
        initialsSet(ini.value.trim());
        confirmStep(actions, (I18N.sendAllConfirm || 'Send day sheets to every coach with sessions that day?'), function (yes) {
          setBusy(yes, true); err.style.display = 'none';
          api('/daysheets/all', { method: 'POST', body: { date: dsel.value, actor: ini.value.trim() } }).then(function (res) {
            setBusy(yes, false);
            if (!res.ok || (res.body && res.body.busy)) { showErr(err, (res.body && res.body.message) || errText(res)); return; }
            var b = res.body;
            var msg = (I18N.sentToN || 'Sent to %n coaches').replace('%n', b.sent) + ' · ' + (I18N.skippedN || 'skipped %m (no sessions)').replace('%m', b.skipped);
            if (b.no_email && b.no_email.length) { msg += ' · ' + (I18N.noEmailList || 'no email:') + ' ' + b.no_email.join(', '); }
            if (b.failed) { msg += ' · ' + b.failed + ' ' + (I18N.failed || 'failed'); }
            out.innerHTML = ''; out.appendChild(el('p', 'mclb-panel__ok', msg));
            reload(true);
          });
        });
      }));
      panel.appendChild(actions);
    });
  }

  // ── boot ──────────────────────────────────────────────────────────────────────
  load();
  scheduleRefresh();
})();
