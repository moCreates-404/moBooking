/**
 * Prepaid-pass code box on the WC cart + checkout (Phase 1.5.0). The PHP renders
 * only a kses-safe <div class="mclb-passbox" data-...>; this builds the input /
 * buttons inside it, so nothing can be stripped by a theme that runs wp_kses on
 * cart hook output. Apply/remove POST to the REST endpoint and reload (WC then
 * recalculates the discounted lines).
 */
(function () {
  function init(box) {
    var url = box.getAttribute('data-url'), nonce = box.getAttribute('data-nonce');
    var code = box.getAttribute('data-code') || '';
    var covered = box.getAttribute('data-covered') || '0';
    var balance = box.getAttribute('data-balance') || '';
    var L = {};
    try { L = JSON.parse(box.getAttribute('data-i18n') || '{}'); } catch (e) {}
    box.innerHTML = '';
    var msg = document.createElement('p'); msg.className = 'mclb-pass-msg'; msg.style.margin = '6px 0 0'; msg.style.color = '#b32d2e';
    function post(op, c) {
      msg.textContent = L.working || 'Working…';
      fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce }, body: JSON.stringify({ op: op, code: c }) })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) { if (res.ok) { location.reload(); } else { msg.textContent = (res.b && res.b.message) || L.invalid || 'That code is invalid or expired.'; } })
        .catch(function () { msg.textContent = L.err || 'Could not reach the server — try again.'; });
    }
    if (code) {
      var p = document.createElement('p'); p.style.margin = '0 0 6px';
      var strong = document.createElement('strong'); strong.textContent = (L.applied || 'Prepaid pass %s applied').replace('%s', code); p.appendChild(strong);
      var extra = '';
      if (parseFloat(covered) > 0) { extra += ' — ' + (L.covered || '%h covered').replace('%h', covered); }
      if (balance !== '') { extra += (extra ? ' · ' : ' — ') + (L.left || '%h left on this pass').replace('%h', balance); }
      if (extra) { p.appendChild(document.createTextNode(extra)); }
      box.appendChild(p);
      var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'button'; rm.textContent = L.remove || 'Remove pass';
      rm.addEventListener('click', function () { post('remove', ''); });
      box.appendChild(rm);
    } else {
      var lab = document.createElement('label'); lab.setAttribute('for', 'mclb-pass-code'); lab.textContent = L.prompt || 'Have a prepaid pass? Enter the code:'; lab.style.display = 'block'; lab.style.margin = '0 0 6px';
      box.appendChild(lab);
      var inp = document.createElement('input'); inp.type = 'text'; inp.id = 'mclb-pass-code'; inp.placeholder = 'CC-XXXX-XXXX'; inp.autocomplete = 'off'; inp.style.textTransform = 'uppercase'; inp.style.maxWidth = '180px';
      box.appendChild(inp);
      var ap = document.createElement('button'); ap.type = 'button'; ap.className = 'button'; ap.textContent = L.apply || 'Apply'; ap.style.marginLeft = '6px';
      ap.addEventListener('click', function () { if (inp.value.trim()) { post('apply', inp.value.trim()); } });
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (inp.value.trim()) { post('apply', inp.value.trim()); } } });
      box.appendChild(inp === inp ? ap : ap);
    }
    box.appendChild(msg);
  }
  function boot() {
    var bs = document.querySelectorAll('.mclb-passbox');
    for (var i = 0; i < bs.length; i++) { if (!bs[i].getAttribute('data-done')) { bs[i].setAttribute('data-done', '1'); init(bs[i]); } }
  }
  if (document.readyState !== 'loading') { boot(); } else { document.addEventListener('DOMContentLoaded', boot); }
  if (window.jQuery) { window.jQuery(document.body).on('updated_cart_totals updated_checkout', boot); }
})();
