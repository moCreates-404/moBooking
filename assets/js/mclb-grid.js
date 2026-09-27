/*
 * moBooking front-end grid interactions (vanilla JS, no framework, no build step).
 *
 * Responsibilities (Phase 3): drag-select (mouse) / tap-start-tap-end (touch)
 * over increment-aligned available cells — plus click/drag-to-deselect on
 * already-selected cells to shrink or clear a selection — a per-day multi-lane
 * selection list,
 * date navigation via the availability REST endpoint (single-date-per-order —
 * selections reset on date change), live price preview, and assembling +
 * POSTing the selection payload to the Phase 4 cart route. It never writes a
 * hold or a cart item itself; it reads all slot data from cell data-* attributes.
 *
 * Supports multiple [mclb_grid] instances on one page (each wrap is independent).
 */
(function () {
  'use strict';

  var CFG = window.mclbGrid || {};
  var fmtMoney = function (n) {
    return (CFG.currency || '$') + Number(n || 0).toFixed(2);
  };

  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); }
    else { document.addEventListener('DOMContentLoaded', fn); }
  }

  ready(function () {
    document.querySelectorAll('.mclb-grid-wrap').forEach(function (wrap) {
      new MclbGrid(wrap);
    });
  });

  function MclbGrid(wrap) {
    this.wrap = wrap;
    this.type = wrap.getAttribute('data-type') || '';
    this.grid = wrap.querySelector('.mclb-grid');
    this.selectionBox = wrap.querySelector('.mclb-selection');
    this.list = wrap.querySelector('.mclb-selection__list');
    this.totalEl = wrap.querySelector('.mclb-selection__total');
    this.addBtn = wrap.querySelector('.mclb-add-to-cart');
    this.msg = wrap.querySelector('.mclb-selection__msg');
    this.dateInput = wrap.querySelector('.mclb-grid__date');
    this.selections = [];      // committed selections for the current day
    this.drag = null;          // active mouse drag
    this.tapAnchor = null;     // pending touch tap-start cell
    this.bind();
    this.render();
  }

  MclbGrid.prototype.bind = function () {
    var self = this;

    // Pointer interactions are delegated on the grid so they survive re-renders.
    this.grid.addEventListener('pointerdown', function (e) { self.onPointerDown(e); });
    this.grid.addEventListener('pointermove', function (e) { self.onPointerMove(e); });
    // pointerup can land outside a cell — listen on the document.
    document.addEventListener('pointerup', function (e) { self.onPointerUp(e); });

    // Date navigation.
    var prev = this.wrap.querySelector('.mclb-nav--prev');
    var next = this.wrap.querySelector('.mclb-nav--next');
    if (prev) { prev.addEventListener('click', function () { self.shiftDate(-1); }); }
    if (next) { next.addEventListener('click', function () { self.shiftDate(1); }); }
    if (this.dateInput) {
      this.dateInput.addEventListener('change', function () { self.loadDate(self.dateInput.value); });
    }

    // Coach note reveal.
    var coachToggle = this.wrap.querySelector('.mclb-coach__toggle');
    var coachNote = this.wrap.querySelector('.mclb-coach__note');
    if (coachToggle && coachNote) {
      coachToggle.addEventListener('change', function () { coachNote.hidden = !coachToggle.checked; });
    }

    if (this.addBtn) {
      this.addBtn.addEventListener('click', function () { self.addToCart(); });
    }
  };

  // ── Cell helpers ──────────────────────────────────────────────────────────

  function cellFrom(e) {
    var el = e.target && e.target.closest ? e.target.closest('.mclb-cell') : null;
    return el;
  }
  MclbGrid.prototype.isAvailable = function (cell) {
    return cell && cell.getAttribute('data-state') === 'available';
  };
  // Available cells in one lane, sorted by start-minute.
  MclbGrid.prototype.laneCells = function (laneId) {
    var cells = this.grid.querySelectorAll('.mclb-cell--available[data-lane="' + laneId + '"]');
    return Array.prototype.slice.call(cells).sort(function (a, b) {
      return (+a.getAttribute('data-start-min')) - (+b.getAttribute('data-start-min'));
    });
  };
  // Contiguous run of available cells from anchor toward target (inclusive),
  // stopping at the first gap/blocked slot so a selection is never split.
  MclbGrid.prototype.run = function (anchor, target) {
    if (anchor.getAttribute('data-lane') !== target.getAttribute('data-lane')) { return [anchor]; }
    var cells = this.laneCells(anchor.getAttribute('data-lane'));
    var ai = cells.indexOf(anchor), ti = cells.indexOf(target);
    if (ai === -1 || ti === -1) { return [anchor]; }
    var lo = Math.min(ai, ti), hi = Math.max(ai, ti);
    var out = [];
    for (var i = lo; i <= hi; i++) {
      if (out.length) {
        var prevEnd = +out[out.length - 1].getAttribute('data-end-min');
        if (+cells[i].getAttribute('data-start-min') !== prevEnd) { break; } // gap → stop
      }
      out.push(cells[i]);
    }
    // If the target sat past a gap, the run above stopped early — that's intended.
    return out;
  };

  MclbGrid.prototype.clearSelecting = function () {
    this.grid.querySelectorAll('.mclb-cell--selecting').forEach(function (c) {
      c.classList.remove('mclb-cell--selecting');
    });
  };
  MclbGrid.prototype.paint = function (cells, cls) {
    cells.forEach(function (c) { c.classList.add(cls); });
  };

  // ── Pointer (mouse drag / touch tap) ────────────────────────────────────────
  //
  // A gesture's action is set by the cell it STARTS on: an available (unselected)
  // cell begins a SELECT gesture; an already-selected cell begins a DESELECT
  // gesture. So a customer clicks a selected slot to drop it, or drags back over
  // a selected run to shrink/clear it — no reload needed. The committed selection
  // is always re-derived from which cells carry --selected (see rebuild), so
  // removing a slot mid-run correctly splits the block.

  MclbGrid.prototype.onPointerDown = function (e) {
    var cell = cellFrom(e);
    if (!cell) { return; }
    var selected = this.isSelected(cell);
    var available = this.isAvailable(cell);

    if (e.pointerType === 'touch' || e.pointerType === 'pen') {
      // Touch: per-cell tap. Tapping a selected cell removes it; otherwise
      // tap-start / tap-end selects a range.
      e.preventDefault();
      if (selected) {
        cell.classList.remove('mclb-cell--selected');
        this.tapAnchor = null;
        this.clearSelecting();
        this.rebuild();
        return;
      }
      if (!available) { return; }
      if (!this.tapAnchor) {
        this.tapAnchor = cell;
        this.clearSelecting();
        this.paint([cell], 'mclb-cell--selecting');
      } else {
        this.commit(this.run(this.tapAnchor, cell));
        this.tapAnchor = null;
      }
      return;
    }

    // Mouse: deselect gesture if it starts on a selected cell.
    if (selected) {
      e.preventDefault();
      this.drag = { mode: 'deselect' };
      cell.classList.remove('mclb-cell--selected');
      this.rebuild();
      return;
    }
    // Otherwise a select gesture, only from an available cell.
    if (!available) { return; }
    e.preventDefault();
    this.drag = { mode: 'select', anchor: cell };
    this.clearSelecting();
    this.paint([cell], 'mclb-cell--selecting');
  };

  MclbGrid.prototype.onPointerMove = function (e) {
    if (!this.drag) { return; }
    var cell = cellFrom(e);
    if (!cell) { return; }

    if (this.drag.mode === 'deselect') {
      // Drag across selected cells to remove them live.
      if (this.isSelected(cell)) {
        cell.classList.remove('mclb-cell--selected');
        this.rebuild();
      }
      return;
    }

    // select
    if (!this.isAvailable(cell)) { return; }
    this.clearSelecting();
    this.paint(this.run(this.drag.anchor, cell), 'mclb-cell--selecting');
  };

  MclbGrid.prototype.onPointerUp = function () {
    if (!this.drag) { return; }
    var mode = this.drag.mode;
    this.drag = null;
    if (mode === 'select') {
      var cells = Array.prototype.slice.call(this.grid.querySelectorAll('.mclb-cell--selecting'));
      if (cells.length) { this.commit(cells); }
      else { this.clearSelecting(); }
    }
    // deselect is applied live during the drag — nothing to finalise.
  };

  // ── Selection: grid is the source of truth ──────────────────────────────────

  MclbGrid.prototype.isSelected = function (cell) {
    return cell && cell.classList.contains('mclb-cell--selected');
  };

  // Commit a drag/tap: mark those cells selected, then re-derive the list.
  MclbGrid.prototype.commit = function (cells) {
    this.clearSelecting();
    if (!cells || !cells.length) { return; }
    this.paint(cells, 'mclb-cell--selected');
    this.rebuild();
  };

  // Rebuild this.selections from the cells currently marked --selected, grouped
  // per lane and split into contiguous runs (so a gap = two separate bookings).
  MclbGrid.prototype.rebuild = function () {
    var self = this;
    var byLane = {};
    this.grid.querySelectorAll('.mclb-cell--selected').forEach(function (c) {
      var lane = c.getAttribute('data-lane');
      (byLane[lane] = byLane[lane] || []).push(c);
    });

    var selections = [];
    Object.keys(byLane).forEach(function (lane) {
      var cells = byLane[lane].sort(function (a, b) {
        return (+a.getAttribute('data-start-min')) - (+b.getAttribute('data-start-min'));
      });
      var run = [];
      var flush = function () {
        if (!run.length) { return; }
        var first = run[0], last = run[run.length - 1];
        var price = parseFloat(first.getAttribute('data-price')) || 0;
        selections.push({
          laneId: parseInt(lane, 10),
          laneName: first.getAttribute('data-lane-name') || '',
          date: self.wrap.getAttribute('data-date'),
          startMin: +first.getAttribute('data-start-min'),
          endMin: +last.getAttribute('data-end-min'),
          start: first.getAttribute('data-start'),
          end: last.getAttribute('data-end'),
          slots: run.length,
          price: price,
          total: price * run.length,
          cells: run.slice()
        });
        run = [];
      };
      cells.forEach(function (c) {
        if (run.length && +c.getAttribute('data-start-min') !== +run[run.length - 1].getAttribute('data-end-min')) {
          flush();
        }
        run.push(c);
      });
      flush();
    });

    this.selections = selections;
    this.renderList();
  };

  MclbGrid.prototype.removeSelection = function (index) {
    var sel = this.selections[index];
    if (!sel) { return; }
    sel.cells.forEach(function (c) { c.classList.remove('mclb-cell--selected'); });
    this.rebuild();
  };

  MclbGrid.prototype.renderList = function () {
    var self = this;
    this.list.innerHTML = '';
    var grand = 0;

    this.selections.forEach(function (sel, i) {
      grand += sel.total;
      var li = document.createElement('li');
      var label = document.createElement('span');
      label.textContent = sel.laneName + ' · ' + hm(sel.startMin) + '–' + hm(sel.endMin) + ' · ' + fmtMoney(sel.total);
      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'mclb-selection__remove';
      rm.textContent = (CFG.i18n && CFG.i18n.remove) || 'Remove';
      rm.addEventListener('click', function () { self.removeSelection(i); });
      li.appendChild(label);
      li.appendChild(rm);
      self.list.appendChild(li);
    });

    var has = this.selections.length > 0;
    this.selectionBox.hidden = !has;
    this.totalEl.textContent = has ? ((CFG.i18n && CFG.i18n.total || 'Total') + ': ' + fmtMoney(grand)) : '';
    if (this.addBtn) { this.addBtn.disabled = !has; }
    if (this.msg) { this.msg.textContent = ''; this.msg.className = 'mclb-selection__msg'; }
  };

  // ── Date navigation ─────────────────────────────────────────────────────────

  MclbGrid.prototype.shiftDate = function (days) {
    var d = new Date(this.wrap.getAttribute('data-date') + 'T00:00:00');
    d.setDate(d.getDate() + days);
    this.loadDate(iso(d));
  };

  MclbGrid.prototype.loadDate = function (date) {
    if (!date) { return; }
    var self = this;
    var url = CFG.restUrl + '?date=' + encodeURIComponent(date) + '&type=' + encodeURIComponent(this.type);
    fetch(url, { headers: { 'X-WP-Nonce': CFG.nonce || '' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
      .then(function (data) {
        self.wrap.setAttribute('data-date', data.date);
        if (self.dateInput) { self.dateInput.value = data.date; }
        self.grid.innerHTML = data.html;
        // Single-date-per-order: the fresh grid has no --selected cells, so
        // rebuild derives an empty selection and hides the summary.
        self.tapAnchor = null;
        self.rebuild();
        self.render();
      })
      .catch(function () {
        if (self.msg) { self.msg.textContent = 'Could not load that date.'; self.msg.className = 'mclb-selection__msg mclb-selection__msg--err'; }
      });
  };

  // Re-assert prev-button disabled state at "today".
  MclbGrid.prototype.render = function () {
    var prev = this.wrap.querySelector('.mclb-nav--prev');
    if (prev && this.dateInput && this.dateInput.min) {
      prev.disabled = this.wrap.getAttribute('data-date') <= this.dateInput.min;
    }
  };

  // ── Add to cart (Phase 3 → Phase 4 handoff) ─────────────────────────────────

  MclbGrid.prototype.addToCart = function () {
    var self = this;
    if (!this.selections.length) { return; }

    var coachToggle = this.wrap.querySelector('.mclb-coach__toggle');
    var coachNote = this.wrap.querySelector('.mclb-coach__note');
    var payload = {
      selections: this.selections.map(function (s) {
        return { lane_id: s.laneId, starts_at: s.start, ends_at: s.end };
      }),
      coach_requested: !!(coachToggle && coachToggle.checked),
      coach_note: (coachToggle && coachToggle.checked && coachNote) ? coachNote.value : ''
    };

    // Phase 4 implements the cart route. Announce the assembled payload for any
    // integration/testing, then POST it; a missing handler is reported cleanly.
    this.wrap.dispatchEvent(new CustomEvent('mclb:addtocart', { bubbles: true, detail: payload }));
    this.addBtn.disabled = true;

    fetch(CFG.cartUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce || '' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (res.ok && res.body && res.body.redirect) { window.location = res.body.redirect; return; }
        if (res.ok) { self.setMsg((res.body && res.body.message) || 'Added.', true); }
        else { self.setMsg((CFG.i18n && CFG.i18n.cartSoon) || 'Checkout not available yet.', false); self.addBtn.disabled = false; }
      })
      .catch(function () {
        self.setMsg((CFG.i18n && CFG.i18n.cartSoon) || 'Checkout not available yet.', false);
        self.addBtn.disabled = false;
      });
  };

  MclbGrid.prototype.setMsg = function (text, ok) {
    if (!this.msg) { return; }
    this.msg.textContent = text;
    this.msg.className = 'mclb-selection__msg ' + (ok ? 'mclb-selection__msg--ok' : 'mclb-selection__msg--err');
  };

  // ── small utils ─────────────────────────────────────────────────────────────

  function hm(mins) {
    var h = Math.floor(mins / 60), m = mins % 60;
    var ampm = h >= 12 ? 'pm' : 'am';
    var hh = h % 12; if (hh === 0) { hh = 12; }
    return hh + (m ? ':' + (m < 10 ? '0' + m : m) : '') + ampm;
  }
  function iso(d) {
    var mo = d.getMonth() + 1, da = d.getDate();
    return d.getFullYear() + '-' + (mo < 10 ? '0' + mo : mo) + '-' + (da < 10 ? '0' + da : da);
  }
})();
