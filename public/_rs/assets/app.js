// Arena Bakarni — sistem rezervacija (bez build koraka, bez vanjskih biblioteka)
const API = '/rezervacije/api/';
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const km = (c) => { const n = Number(c || 0); const neg = n < 0; const a = Math.abs(n); return (neg ? '−' : '') + Math.floor(a / 100).toLocaleString('de-DE') + ',' + String(a % 100).padStart(2, '0') + ' KM'; };
const hrs = (m) => { m = Number(m || 0); const h = Math.floor(m / 60), r = m % 60; return (h ? h + ' h' : '') + (r ? (h ? ' ' : '') + r + ' min' : '') || '0 h'; };
const DAYS = ['nedjelja', 'ponedjeljak', 'utorak', 'srijeda', 'četvrtak', 'petak', 'subota'];
const WD = ['', 'pon', 'uto', 'sri', 'čet', 'pet', 'sub', 'ned'];
const pad = (n) => String(n).padStart(2, '0');
const dateObj = (s) => { const [y, m, d] = s.slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d, 12); };
const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
const addDays = (s, n) => { const d = dateObj(s); d.setDate(d.getDate() + n); return ymd(d); };
const hDate = (s) => { const d = dateObj(s); return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + '.'; };
const hm = (s) => s.slice(11, 16);
const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
const fromMin = (m) => pad(Math.floor(m / 60)) + ':' + pad(m % 60);
const STATE = { pending: ['⏳', 'Čeka'], arrived: ['●', 'Došao'], done: ['✓', 'Zaključeno'], no_show: ['✕', 'Nije došao'], cancelled: ['⊘', 'Otkazano'] };

const S = { me: null, perms: [], staff: false, club: null, date: null, view: 'day', showCancelled: false, cal: null };
const can = (p) => S.me && (S.me.role === 'owner' || S.perms.includes(p));

async function api(method, path, body) {
  const opt = { method, headers: { 'X-RS': '1' }, credentials: 'same-origin' };
  if (body !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  let res;
  try { res = await fetch(API + path, opt); } catch { throw Object.assign(new Error('Nema veze sa serverom.'), { data: {} }); }
  let j = null; try { j = await res.json(); } catch { /* */ }
  if (!res.ok || !j || !j.ok) throw Object.assign(new Error((j && j.error) || 'Greška ' + res.status), { status: res.status, data: (j && j.data) || {} });
  return j.data;
}
function toast(msg, ms = 3500) { const t = $('#rs-toast'); t.textContent = msg; t.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), ms); }
function modal(html, bind) {
  const m = $('#rs-modal'); m.innerHTML = '<div class="box"><button class="btn sm close" data-x>✕</button>' + html + '</div>'; m.hidden = false;
  const close = () => { m.hidden = true; m.innerHTML = ''; };
  m.onclick = (e) => { if (e.target === m || e.target.closest('[data-x]')) close(); };
  if (bind) bind($('.box', m), close);
  return close;
}
function panel(html, bind) {
  const p = $('#rs-panel'); p.innerHTML = '<button class="btn sm close" data-x>✕</button>' + html; p.hidden = false;
  const close = () => { p.hidden = true; p.innerHTML = ''; };
  p.onclick = (e) => { if (e.target.closest('[data-x]')) close(); };
  if (bind) bind(p, close);
  return close;
}
const closePanel = () => { const p = $('#rs-panel'); p.hidden = true; p.innerHTML = ''; };
function errBox(el, e) {
  let h = '<div class="err">' + esc(e.message) + '</div>';
  const c = e.data && (e.data.conflicts || (e.data.blockers ? null : null));
  if (Array.isArray(c) && c.length) h += '<ul class="rs-small">' + c.map((x) => '<li>' + esc(x.label) + ' · ' + hDate(x.start_at) + ' ' + hm(x.start_at) + '–' + hm(x.end_at) + (x.type === 'reservation' ? ' <a href="#" data-open-res="' + x.id + '">otvori</a>' : '') + '</li>').join('') + '</ul>';
  el.innerHTML = h;
  $$('[data-open-res]', el).forEach((a) => (a.onclick = (ev) => { ev.preventDefault(); openReservation(+a.dataset.openRes); }));
}
// Vlastiti dijalozi umjesto confirm()/prompt() (rade isto na telefonu, ne blokiraju stranicu).
function dialog(inner) {
  return new Promise((resolve) => {
    const d = document.createElement('div'); d.className = 'rs-modal'; d.style.zIndex = 70;
    d.innerHTML = '<div class="box">' + inner + '</div>'; document.body.appendChild(d);
    const done = (v) => { d.remove(); resolve(v); };
    d.addEventListener('click', (e) => { if (e.target === d || e.target.closest('[data-no]')) done(null); if (e.target.closest('[data-yes]')) { const i = $('input,textarea', d); if (i && i.required && !i.value.trim()) { i.focus(); return; } done(i ? i.value.trim() : true); } });
    d.addEventListener('keydown', (e) => { if (e.key === 'Escape') done(null); if (e.key === 'Enter' && e.target.tagName === 'INPUT') $('[data-yes]', d).click(); });
    setTimeout(() => ($('input', d) || $('[data-yes]', d)).focus(), 30);
  });
}
const ask = (text, yes = 'Da') => dialog('<p style="white-space:pre-line">' + esc(text) + '</p><div class="btns"><button class="btn primary" data-yes>' + esc(yes) + '</button><button class="btn" data-no>Odustani</button></div>').then((v) => v === true);
function askText(title, value = '') {
  const isDate = /GGGG-MM-DD/.test(title), optional = /opciono/i.test(title);
  const label = title.replace(/\s*\(GGGG-MM-DD[^)]*\)/, '').replace(/\s*\(opciono\)/i, '');
  return dialog('<div class="field"><label>' + esc(label) + (optional ? ' <span class="rs-muted">(opciono)</span>' : '') + '</label><input ' + (isDate ? 'type="date" ' : '') + (optional ? '' : 'required ') + 'value="' + esc(value) + '"></div><div class="btns"><button class="btn primary" data-yes>U redu</button><button class="btn" data-no>Odustani</button></div>');
}
const val = (root, name) => { const el = $('[name="' + name + '"]', root); if (!el) return undefined; if (el.type === 'checkbox') return el.checked; return el.value; };

// ============================================================ kalendar
function rowH() { return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--row')) || 14; }

function renderCalendar(el, data, opts) {
  const R = rowH(), START = toMin(data.cal_start), END = toMin(data.close), rows = (END - START) / 15, H = rows * R;
  const staffMode = opts.mode === 'staff';
  let h = '<div class="cal"><div class="cal-head"></div>';
  for (const l of data.lanes) h += '<div class="cal-head"><span class="short">' + esc(l.short) + '</span><span class="long">' + esc(l.name) + '</span></div>';
  h += '<div class="cal-times" style="height:' + H + 'px">';
  for (let m = START; m < END; m += 60) h += '<div class="cal-time" style="top:' + ((m - START) / 15) * R + 'px">' + fromMin(m) + '</div>';
  h += '</div>';
  const openTop = ((toMin(data.open) - START) / 15) * R;
  for (const l of data.lanes) {
    h += '<div class="cal-col" data-lane="' + l.id + '" style="height:' + H + 'px">';
    if (!staffMode) h += '<div class="cal-closed" style="top:0;height:' + openTop + 'px"></div>';
    for (let m = START; m < END; m += 15) h += '<div class="cal-line' + (m % 60 === 0 ? ' hour' : '') + '" style="top:' + ((m - START) / 15) * R + 'px"></div>';
    h += '</div>';
  }
  h += '</div>';
  el.innerHTML = h;
  const cols = {}; $$('.cal-col', el).forEach((c) => (cols[c.dataset.lane] = c));
  const y = (t) => ((toMin(hm(t)) - START) / 15) * R;
  const blocks = data.blocks.filter((b) => S.showCancelled || b.status !== 'cancelled');
  for (const b of blocks) {
    for (const lane of b.lanes) {
      const col = cols[lane]; if (!col) continue;
      const top = y(b.start_at), ht = Math.max(R, y(b.end_at) - top) - 1;
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'blk' + (b.type === 'event' ? ' ev' : '') + (b.mine ? ' mine' : '') + (b.state ? ' st-' + b.state : '');
      btn.style.cssText = 'top:' + top + 'px;height:' + ht + 'px;background:' + b.color;
      const narrow = innerWidth < 760;
      const l1 = narrow ? (b.short + ' ' + hm(b.start_at)) : b.label;
      const l2 = narrow ? (b.who ? b.who.split(' ')[0] : (b.mine ? 'Vaše' : '')) : (hm(b.start_at) + '–' + hm(b.end_at) + (b.who ? ' · ' + b.who : '') + (b.sub ? ' · ' + b.sub : '') + (b.mine ? ' · Vaša rezervacija' : ''));
      if (b.positions) btn.innerHTML = ''; else btn.innerHTML = '<span class="l1">' + esc(l1) + '</span>' + (ht > 2 * R ? '<span class="l2">' + esc(l2) + '</span>' : '') + (b.state && staffMode ? '<span class="badge" title="' + STATE[b.state][1] + '">' + STATE[b.state][0] + '</span>' : '');
      btn.title = b.label + ' ' + hm(b.start_at) + '–' + hm(b.end_at);
      btn.onclick = (e) => { e.stopPropagation(); opts.onBlock && opts.onBlock(b); };
      col.appendChild(btn);
      // nastupi (kvalifikacije: sve pozicije; turnir: upisani igrači)
      const items = b.positions || b.entries || [];
      for (const p of items) {
        if (+p.lane_id !== +lane) continue;
        const d = document.createElement('div');
        d.className = 'pos' + (p.player_name ? '' : ' free');
        const pt = y(p.start_at); d.style.cssText = 'top:' + (pt + 1) + 'px;height:' + Math.max(R, y(p.end_at) - pt - 2) + 'px';
        d.textContent = p.player_name || 'slobodno';
        d.onclick = (e) => { e.stopPropagation(); opts.onBlock && opts.onBlock(b); };
        col.appendChild(d);
      }
    }
  }
  if (data.date === data.today) {
    const nm = toMin(hm(data.now));
    if (nm >= START && nm <= END) for (const c of Object.values(cols)) { const n = document.createElement('div'); n.className = 'cal-now'; n.style.top = ((nm - START) / 15) * R + 'px'; c.appendChild(n); }
  }
  for (const [lane, col] of Object.entries(cols)) {
    col.onclick = (e) => {
      if (!opts.onFree) return;
      const r = col.getBoundingClientRect();
      const m = START + Math.floor((e.clientY - r.top) / R) * 15;
      const hour = Math.floor(m / 60) * 60;
      opts.onFree(+lane, opts.mode === 'staff' && !opts.fullHour ? m : hour);
    };
  }
}

function renderWeek(el, data, onDay) {
  const hours = []; for (let h = 8; h < 23; h++) hours.push(pad(h));
  let h = '<div class="week"><div class="wh"></div>';
  data.days.forEach((d) => { const o = dateObj(d.date); h += '<div class="wh' + (d.date === data.today ? ' today' : '') + '" data-day="' + d.date + '">' + WD[(o.getDay() + 6) % 7 + 1] + ' ' + o.getDate() + '.</div>'; });
  const color = Object.fromEntries(data.categories.map((c) => [c.code, c.color]));
  for (const hr of hours) {
    h += '<div class="wt">' + hr + '</div>';
    for (const d of data.days) {
      const g = d.grid[hr] || {};
      h += '<div class="wc" data-day="' + d.date + '">';
      for (let l = 1; l <= 6; l++) { const c = g[l]; h += '<span' + (c ? ' style="background:' + (color[c.c] || '#888') + '"' + (c.m ? ' class="m"' : '') : '') + '></span>'; }
      h += '</div>';
    }
  }
  el.innerHTML = h + '</div>';
  $$('[data-day]', el).forEach((x) => (x.onclick = () => onDay(x.dataset.day)));
}

function legend(cats) { return '<div class="legend">' + cats.map((c) => '<span><i style="background:' + c.color + '"></i>' + esc(c.label) + '</span>').join('') + '</div>'; }

async function calendarView(root, mode) {
  const params = new URLSearchParams(location.search);
  S.date = S.date || params.get('datum') || null;
  root.innerHTML = '<div class="cal-bar"><span class="date-title" id="cal-title"></span>' +
    '<button class="btn sm" id="cal-prev" aria-label="Prethodni dan">‹</button><button class="btn sm" id="cal-today">Danas</button><button class="btn sm" id="cal-next" aria-label="Sljedeći dan">›</button>' +
    '<input type="date" id="cal-date" aria-label="Izbor datuma"><div class="tabs" style="margin:0"><button data-v="day">Dan</button><button data-v="week">Sedmica</button></div>' +
    (mode === 'staff' ? '<label class="rs-small"><input type="checkbox" id="cal-cx"> otkazane</label>' : '') + '</div><div id="cal-body"></div><div id="cal-legend"></div><div id="cal-info"></div>';
  const load = async () => {
    $$('.tabs [data-v]', root).forEach((b) => b.classList.toggle('active', b.dataset.v === S.view));
    const body = $('#cal-body', root);
    try {
      if (S.view === 'week') {
        const w = await api('GET', 'calendar/week?date=' + (S.date || ''));
        S.date = S.date || w.today;
        $('#cal-title', root).textContent = 'Sedmica od ' + hDate(w.week_start);
        $('#cal-date', root).value = S.date;
        renderWeek(body, w, (d) => { S.date = d; S.view = 'day'; load(); });
        $('#cal-legend', root).innerHTML = legend(w.categories);
        return;
      }
      const d = await api('GET', 'calendar/day?date=' + (S.date || ''));
      S.date = d.date; S.cal = d;
      const o = dateObj(d.date);
      $('#cal-title', root).textContent = DAYS[o.getDay()].replace(/^./, (c) => c.toUpperCase()) + ', ' + hDate(d.date) + (d.closed && mode === 'staff' ? ' · zaključen' : '');
      $('#cal-date', root).value = d.date;
      renderCalendar(body, d, {
        mode,
        onBlock: (b) => (b.type === 'event' ? openEvent(b.id) : (mode !== 'staff' && !b.mine ? publicDetail(b) : openReservation(b.id))),
        onFree: (lane, minute) => onFreeSlot(mode, d, lane, minute),
      });
      $('#cal-legend', root).innerHTML = legend(d.categories);
      const evs = d.blocks.filter((b) => b.type === 'event');
      $('#cal-info', root).innerHTML = evs.map((e) => '<div class="card"><b>' + esc(e.label) + '</b> · ' + hm(e.start_at) + '–' + hm(e.end_at) + (e.signup_phone ? ' · Telefon za prijave: <a href="tel:' + esc(e.signup_phone) + '">' + esc(e.signup_phone) + '</a>' : '') + (e.public_note ? '<div class="rs-small">' + esc(e.public_note) + '</div>' : '') + ' <button class="btn sm" data-ev="' + e.id + '">Raspored</button></div>').join('');
      $$('[data-ev]', root).forEach((b) => (b.onclick = () => openEvent(+b.dataset.ev)));
    } catch (e) { errBox(body, e); }
  };
  $('#cal-prev', root).onclick = () => { S.date = addDays(S.date, S.view === 'week' ? -7 : -1); load(); };
  $('#cal-next', root).onclick = () => { S.date = addDays(S.date, S.view === 'week' ? 7 : 1); load(); };
  $('#cal-today', root).onclick = () => { S.date = null; load(); };
  $('#cal-date', root).onchange = (e) => { if (e.target.value) { S.date = e.target.value; load(); } };
  $$('.tabs [data-v]', root).forEach((b) => (b.onclick = () => { S.view = b.dataset.v; load(); }));
  if ($('#cal-cx', root)) $('#cal-cx', root).onchange = (e) => { S.showCancelled = e.target.checked; load(); };
  let rt; addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => S.view === 'day' && S.cal && load(), 250); });
  S.reloadCal = load;
  await load();
}

// Javni detail tuđeg termina: samo ono što je javno (kategorija, klubovi/liga kod utakmice, vrijeme, staze).
function publicDetail(b) {
  panel('<h3><span class="tag" style="background:' + b.color + ';color:#fff">' + esc(b.short === 'UT' ? 'Utakmica' : b.label) + '</span></h3><div class="kv">' +
    (b.short === 'UT' ? '<div>Utakmica</div><div>' + esc(b.label) + (b.sub ? '<br>' + esc(b.sub) : '') + '</div>' : '') +
    '<div>Vrijeme</div><div>' + hDate(b.start_at) + ' ' + hm(b.start_at) + '–' + hm(b.end_at) + '</div><div>Staze</div><div>' + b.lanes.join(', ') + '</div></div><p class="rs-small rs-muted">Termin je zauzet.</p>');
}

function onFreeSlot(mode, d, lane, minute) {
  if (mode === 'public') { if (!S.me) return modal('<h3>Rezervacija termina</h3><p>Za rezervaciju se prijavite ili napravite nalog.</p><div class="btns"><a class="btn primary" href="/nalog/?povratak=' + encodeURIComponent('/rezervacije/?datum=' + d.date) + '">Prijava / registracija</a></div>'); }
  if (mode === 'staff') return staffBooking({ date: d.date, time: fromMin(minute), lane });
  return guestBooking(d.date, fromMin(minute), lane);
}

// ============================================================ rezervisanje (gost / član)
function laneChips(selected) { return '<div class="chips" id="lane-chips">' + [1, 2, 3, 4, 5, 6].map((l) => '<button type="button" class="chip' + (selected.includes(l) ? ' on' : '') + '" data-l="' + l + '">S' + l + '</button>').join('') + '</div>'; }
function bindChips(box, onChange) { $$('#lane-chips .chip', box).forEach((c) => (c.onclick = () => { if (c.disabled) return; c.classList.toggle('on'); onChange(); })); }
function markLanes(box, free) { if (!free) return; $$('#lane-chips .chip', box).forEach((c) => { const busy = !free.includes(+c.dataset.l); c.disabled = busy && !c.classList.contains('on'); c.title = busy ? 'Zauzeto' : ''; c.classList.toggle('busy', busy); }); }
const chosenLanes = (box) => $$('#lane-chips .chip.on', box).map((c) => +c.dataset.l);

function coverageHtml(q) {
  if (q.training) return '<div class="note ok">Trening na račun kluba' + (q.club_amount_cents != null ? ' · vrijednost ' + km(q.club_amount_cents) : '') + '.</div>';
  let h = '';
  if (q.regular) h += '<div class="sum"><span>Iz mjesečne</span><span>' + hrs(q.regular) + '</span></div>';
  if (q.bonus) h += '<div class="sum"><span>Bonus sati</span><span>' + hrs(q.bonus) + '</span></div>';
  if (q.lanes) q.lanes.forEach((l, i) => { if (l.cash) h += '<div class="sum"><span>Staza ' + (i + 1) + ' (' + hrs(l.cash_minutes) + ')</span><span>' + km(l.cash) + '</span></div>'; });
  h += '<div class="sum total"><span>Za platiti na licu mjesta (gotovina)</span><span>' + km(q.cash_total || 0) + '</span></div>';
  if (q.regular_left != null) h += '<div class="rs-small rs-muted">Preostalo redovnih sati te sedmice nakon ove rezervacije: ' + hrs(q.regular_left) + '</div>';
  if (q.debt_cents) h += '<div class="note bad">Imate evidentiran dug (' + km(q.debt_cents) + '). Provjerite ga s osobljem prilikom dolaska.</div>';
  h += '<div class="rs-small rs-muted">Patike se naplaćuju posebno (po paru). Rekreacija se plaća nakon termina.</div>';
  return h;
}

function guestBooking(date, time, lane) {
  if (S.me.blocked) return modal('<h3>Rezervacija</h3><div class="note bad">Vaš nalog je blokiran za nove rezervacije. Obratite se osoblju.</div>');
  const member = !!S.me.club_id;
  const hours = []; for (let h = 10; h <= 22; h++) hours.push(pad(h) + ':00');
  modal('<h3>Nova rezervacija</h3><div class="row"><div class="field"><label>Datum</label><input type="date" name="date" value="' + date + '"></div><div class="field"><label>Početak</label><select name="time">' + hours.map((h) => '<option' + (h === time ? ' selected' : '') + '>' + h + '</option>').join('') + '</select></div>' +
    '<div class="field"><label>Trajanje</label><select name="minutes"><option value="60">60 min</option><option value="90">90 min</option><option value="120">120 min</option></select></div></div>' +
    '<div class="field"><label>Staze (izaberite jednu ili više)</label>' + laneChips([lane]) + '<label class="rs-small"><input type="checkbox" name="auto"> Neka sistem dodijeli staze — broj: <select name="count">' + [1, 2, 3, 4, 5, 6].map((n) => '<option>' + n + '</option>').join('') + '</select></label></div>' +
    (member ? '<div class="field"><label>Vrsta</label><select name="personal"><option value="">Trening (na račun kluba ' + esc(S.club?.name || '') + ')</option><option value="1">Nije trening — plaćam lično</option></select></div>' : '') +
    '<div id="q"></div><div id="e"></div><div class="btns"><button class="btn accent" id="ok">Potvrdi rezervaciju</button></div>', (box, close) => {
    const body = () => {
      const auto = val(box, 'auto');
      return { start: val(box, 'date') + ' ' + val(box, 'time'), minutes: +val(box, 'minutes'), ...(auto ? { lane_count: +val(box, 'count') } : { lanes: chosenLanes(box) }), personal: !!val(box, 'personal') };
    };
    const quote = async () => {
      $('#e', box).innerHTML = '';
      try { const q = await api('POST', 'reservations/quote', body()); markLanes(box, q.free_lanes); $('#q', box).innerHTML = '<div class="note">Staze: ' + q.lanes.join(', ') + ' · ' + hm(q.start) + '–' + hm(q.end) + '</div>' + coverageHtml(q); $('#ok', box).disabled = false; }
      catch (e) { $('#q', box).innerHTML = ''; markLanes(box, e.data && e.data.free); errBox($('#e', box), e); $('#ok', box).disabled = true; }
    };
    bindChips(box, quote);
    $$('select,input', box).forEach((x) => (x.onchange = quote));
    $('#ok', box).onclick = async () => {
      $('#ok', box).disabled = true;
      try { const r = await api('POST', 'reservations', body()); close(); toast('Rezervacija potvrđena: ' + hDate(r.start_at) + ' ' + hm(r.start_at) + ', staza ' + r.lanes.join(', ')); S.reloadCal && S.reloadCal(); }
      catch (e) { errBox($('#e', box), e); $('#ok', box).disabled = false; }
    };
    quote();
  });
}

// ============================================================ unos osoblja
function staffBooking(pre) {
  const cats = [['recreation', 'Rekreacija / mjesečna'], ['training', 'Trening (član kluba)']];
  if (can('events.birthday')) cats.push(['birthday', 'Rođendan']);
  if (can('events.manage')) cats.push(['match', 'Utakmica']);
  const preCat = pre.category || 'recreation';
  modal('<h3>Unos rezervacije</h3>' +
    '<div class="field"><label>Kategorija</label><select name="category">' + cats.map(([k, l]) => '<option value="' + k + '"' + (k === preCat ? ' selected' : '') + '>' + l + '</option>').join('') + '</select></div>' +
    '<div id="guestbox"><div class="field"><label>Gost — telefon ili ime (pretraga postojećih)</label><input name="q" autocomplete="off" placeholder="npr. 066 123 456"></div><div id="found"></div>' +
    '<div class="row"><div class="field"><label>Ime *</label><input name="first_name"></div><div class="field"><label>Prezime</label><input name="last_name"></div><div class="field"><label>Telefon *</label><input name="phone" inputmode="tel"></div></div><input type="hidden" name="user_id"></div>' +
    '<div id="matchbox" hidden><div class="field"><label>Klub koji se obračunava</label><select name="club_id"></select></div><div class="row"><div class="field"><label>Domaćin *</label><input name="match_home"></div><div class="field"><label>Gost *</label><input name="match_guest"></div></div><div class="field"><label>Takmičenje / liga</label><input name="match_league"></div></div>' +
    '<div class="row"><div class="field"><label>Datum</label><input type="date" name="date" value="' + pre.date + '"></div><div class="field"><label>Početak</label><input type="time" step="900" name="time" value="' + pre.time + '"></div>' +
    '<div class="field"><label>Trajanje (min)</label><input type="number" name="minutes" min="30" step="30" value="60"></div></div>' +
    '<div class="field" id="lanesbox"><label>Staze</label>' + laneChips([pre.lane]) + '</div>' +
    '<div class="field"><label>Napomena</label><input name="notes"></div><div id="q"></div><div id="e"></div><div class="btns"><button class="btn accent" id="ok">Upiši</button></div>', async (box, close) => {
    const clubs = await api('GET', 'clubs').catch(() => []);
    $('[name=club_id]', box).innerHTML = clubs.map((c) => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('');
    const isMatch = () => val(box, 'category') === 'match';
    const sync = () => {
      $('#matchbox', box).hidden = !isMatch(); $('#guestbox', box).hidden = isMatch(); $('#lanesbox', box).hidden = isMatch();
      if (isMatch()) $('[name=minutes]', box).value = 150;
      else if (val(box, 'category') === 'birthday' && +val(box, 'minutes') < 60) $('[name=minutes]', box).value = 60;
      quote();
    };
    let st;
    $('[name=q]', box).oninput = (e) => { clearTimeout(st); st = setTimeout(async () => {
      const q = e.target.value.trim(); if (q.length < 3) { $('#found', box).innerHTML = ''; return; }
      const list = await api('GET', 'users?q=' + encodeURIComponent(q)).catch(() => []);
      $('#found', box).innerHTML = list.length ? '<ul class="list-plain">' + list.map((u) => '<li><button type="button" class="btn sm" data-u=\'' + esc(JSON.stringify(u)) + '\'>Izaberi</button> ' + esc(u.first_name + ' ' + u.last_name) + ' · ' + esc(u.phone_pretty) + (u.debt_cents ? ' <span class="tag bad">dug ' + km(u.debt_cents) + '</span>' : '') + (u.club_id ? ' <span class="tag">klub</span>' : '') + (u.blocked ? ' <span class="tag bad">blokiran</span>' : '') + '</li>').join('') + '</ul>' : '<div class="rs-small rs-muted">Nema postojećeg — unesite novog gosta ispod.</div>';
      $$('[data-u]', box).forEach((b) => (b.onclick = () => { const u = JSON.parse(b.dataset.u); $('[name=user_id]', box).value = u.id; $('[name=first_name]', box).value = u.first_name; $('[name=last_name]', box).value = u.last_name; $('[name=phone]', box).value = u.phone_pretty; $('#found', box).innerHTML = '<div class="note ok">Izabran: ' + esc(u.first_name + ' ' + u.last_name) + '</div>'; quote(); }));
    }, 250); };
    $('[name=phone]', box).oninput = () => { $('[name=user_id]', box).value = ''; };
    const body = () => {
      const b = { as_staff: true, start: val(box, 'date') + ' ' + val(box, 'time'), minutes: +val(box, 'minutes'), notes: val(box, 'notes') };
      if (isMatch()) { const club = clubs.find((c) => +c.id === +val(box, 'club_id')); return { ...b, category: club ? club.match_category : 'match_other', club_id: +val(box, 'club_id'), match_home: val(box, 'match_home'), match_guest: val(box, 'match_guest'), match_league: val(box, 'match_league') }; }
      b.category = val(box, 'category'); b.lanes = chosenLanes(box);
      if (val(box, 'user_id')) b.user_id = +val(box, 'user_id'); else b.guest = { first_name: val(box, 'first_name'), last_name: val(box, 'last_name'), phone: val(box, 'phone') };
      return b;
    };
    const quote = async () => {
      $('#e', box).innerHTML = ''; $('#q', box).innerHTML = '';
      if (isMatch()) return;
      try { const q = await api('POST', 'reservations/quote', body()); markLanes(box, q.free_lanes); $('#q', box).innerHTML = (q.cash_total != null || q.training ? coverageHtml(q) : ''); } catch (e) { markLanes(box, e.data && e.data.free); errBox($('#e', box), e); }
    };
    bindChips(box, quote);
    $('[name=category]', box).onchange = sync;
    ['date', 'time', 'minutes'].forEach((n) => ($('[name=' + n + ']', box).onchange = quote));
    $('#ok', box).onclick = async () => {
      $('#ok', box).disabled = true;
      try { const r = await api('POST', 'reservations', body()); close(); toast('Upisano: ' + r.category_label + ' ' + hm(r.start_at) + '–' + hm(r.end_at)); S.reloadCal && S.reloadCal(); }
      catch (e) { errBox($('#e', box), e); $('#ok', box).disabled = false; }
    };
    sync();
  });
}

// ============================================================ bočni panel rezervacije
async function openReservation(id) {
  let r;
  try { r = await api('GET', 'reservations/' + id); } catch (e) { return toast(e.message); }
  const staff = can('res.read');
  const st = r.status === 'cancelled' ? 'cancelled' : r.attendance === 'no_show' ? 'no_show' : r.processed ? 'done' : r.attendance === 'arrived' ? 'arrived' : 'pending';
  let h = '<h3><span class="tag" style="background:' + r.color + ';color:#fff">' + esc(r.category_label) + '</span> #' + r.id + '</h3>';
  h += '<div class="kv"><div>Termin</div><div>' + hDate(r.start_at) + ' ' + hm(r.start_at) + '–' + hm(r.end_at) + '</div><div>Staze</div><div>' + r.lanes.join(', ') + '</div>';
  if (r.user) h += '<div>Gost</div><div>' + esc(r.user.name) + (staff ? '<br><a href="tel:' + esc(r.user.phone) + '">' + esc(r.user.phone) + '</a>' + (r.user.has_account ? '' : ' <span class="tag">bez naloga</span>') : '') + '</div>';
  if (r.club) h += '<div>Klub</div><div>' + esc(r.club.name) + '</div>';
  if (r.match) h += '<div>Utakmica</div><div>' + esc(r.match.home) + ' – ' + esc(r.match.guest) + (r.match.league ? '<br>' + esc(r.match.league) : '') + '</div>';
  h += '<div>Rezervacija</div><div>' + (r.status === 'active' ? '<span class="tag ok">aktivna</span>' : '<span class="tag bad">otkazana' + (r.cancel_kind === 'arena' ? ' (Arena)' : r.cancel_kind === 'guest_late' ? ' (kasno)' : '') + '</span>' + (r.cancel_reason ? ' ' + esc(r.cancel_reason) : '')) + '</div>';
  if (r.coverage !== undefined || r.charges) h += '<div>Dolazak</div><div>' + STATE[st][0] + ' ' + STATE[st][1] + (r.released_at ? ' · staza oslobođena od ' + hm(r.released_at) : '') + '</div>';
  if (r.billing) h += '<div>Klupski obračun</div><div><span class="tag ' + (r.billing.state === 'Obračunato' ? 'ok' : 'warn') + '">' + r.billing.state + '</span>' + (r.billing.statement ? ' ' + esc(r.billing.statement.number) : '') + (r.club_amount_cents != null ? ' · ' + km(r.club_amount_cents) : '') + '</div>';
  if (r.notes && staff) h += '<div>Napomena</div><div>' + esc(r.notes) + '</div>';
  h += '</div>';
  for (const w of r.warnings || []) h += '<div class="note ' + (w.kind === 'debt' || w.kind === 'blocked' ? 'bad' : '') + '">' + esc(w.text) + '</div>';
  if (r.coverage) h += '<h3>Pokriće i cijena</h3>' + coverageHtml(r.coverage);
  if (r.charges && r.charges.length) h += '<h3>Naplaćeno</h3>' + r.charges.map((c) => '<div class="sum"><span>' + (c.kind === 'shoes' ? 'Patike × ' + c.qty : 'Kuglanje' + (c.lane_id ? ' S' + c.lane_id : '') + (c.note ? ' (' + c.note + ')' : '')) + '</span><span>' + km(c.amount_cents) + '</span></div>').join('') + '<div class="sum total"><span>Ukupno gotovina</span><span>' + km(r.paid_cents) + '</span></div>';
  const A = r.actions;
  const btn = (k, l, cls = '') => A.includes(k) ? '<button class="btn ' + cls + '" data-a="' + k + '">' + l + '</button>' : '';
  h += '<div class="btns" style="margin-top:12px">' + btn('arrived', 'Došao') + btn('no_show', 'Nije došao') + btn('settle', 'Naplati', 'accent') + btn('edit', 'Izmijeni') + btn('extend', 'Produži') + btn('cancel', 'Otkaži', 'danger') + btn('cancel_arena', 'Otkaz Arene', 'danger') + (staff ? '<button class="btn sm" data-a="history">Historija</button>' : '') + '</div><div id="pe"></div><div id="ph"></div>';
  panel(h, (p) => {
    const done = (msg) => { toast(msg); S.reloadCal && S.reloadCal(); S.reloadAdmin && S.reloadAdmin(); openReservation(id); };
    const act = async (fn, msg) => { try { await fn(); done(msg); } catch (e) { errBox($('#pe', p), e); } };
    $$('[data-a]', p).forEach((b) => (b.onclick = () => {
      const a = b.dataset.a;
      if (a === 'arrived') act(() => api('POST', 'reservations/' + id + '/arrived', {}), 'Označen dolazak');
      if (a === 'no_show') modal('<h3>Nedolazak</h3><p>Gost kasni više od 10 minuta. Originalni zapis ostaje sačuvan.</p><label><input type="checkbox" name="release" checked> Oslobodi stazu za novu rezervaciju</label><div id="e"></div><div class="btns" style="margin-top:10px"><button class="btn danger" id="ok">Potvrdi nedolazak</button></div>', (box, close) => {
        $('#ok', box).onclick = async () => { try { const x = await api('POST', 'reservations/' + id + '/no-show', { release: val(box, 'release') }); close(); const res = x.result || {}; done(res.debt ? 'Nedolazak — evidentiran dug ' + km(res.debt.amount_cents) : res.warning ? 'Nedolazak — prvo upozorenje (bez duga)' : 'Nedolazak evidentiran'); } catch (e) { errBox($('#e', box), e); } };
      });
      if (a === 'settle') settleModal(r, done);
      if (a === 'edit') editModal(r, done);
      if (a === 'extend') modal('<h3>Produženje</h3><p>Produženje je moguće samo ako je staza slobodna.</p><div class="btns"><button class="btn" data-m="30">+30 min</button><button class="btn" data-m="60">+60 min</button></div><div id="e"></div>', (box, close) => {
        $$('[data-m]', box).forEach((x) => (x.onclick = async () => { try { await api('POST', 'reservations/' + id + '/extend', { add_minutes: +x.dataset.m }); close(); done('Termin produžen'); } catch (e) { errBox($('#e', box), e); } }));
      });
      if (a === 'cancel') (async () => { if (await ask('Otkazati rezervaciju?' + (new Date(r.start_at.replace(' ', 'T')) - new Date() < 7200000 ? '\nNapomena: manje od 2 h prije početka — evidentira se kao kasno otkazivanje.' : ''))) act(() => api('POST', 'reservations/' + id + '/cancel', {}), 'Rezervacija otkazana'); })();
      if (a === 'cancel_arena') modal('<h3>Otkaz od strane Arene</h3><p>Gost obavezno dobija obavijest. Korisnik mjesečne dobija 2× bonus sati za otkazane sate.</p><div class="field"><label>Razlog *</label><input name="reason" placeholder="npr. kvar staze, turnir"></div><div id="e"></div><button class="btn danger" id="ok">Otkaži termin</button>', (box, close) => {
        $('#ok', box).onclick = async () => { try { await api('POST', 'reservations/' + id + '/cancel', { kind: 'arena', reason: val(box, 'reason') }); close(); done('Otkazano od strane Arene'); } catch (e) { errBox($('#e', box), e); } };
      });
      if (a === 'history') api('GET', 'reservations/' + id + '/history').then((list) => { $('#ph', p).innerHTML = '<h3>Historija</h3><ul class="list-plain rs-small">' + list.map((x) => '<li>' + esc(x.at_utc) + ' UTC · <b>' + esc(x.actor_label) + '</b> · ' + esc(x.action) + (x.reason ? ' · ' + esc(x.reason) : '') + '</li>').join('') + '</ul>'; });
    }));
  });
}

function settleModal(r, done) {
  const id = r.id;
  const debts = r.open_debts || [];
  modal('<h3>Naplata termina</h3><div class="big-q">Jesu li korištene patike?</div><div class="btns"><button class="btn" data-sh="1">Da</button><button class="btn" data-sh="0">Ne</button></div>' +
    '<div class="field" id="pairs" hidden><label>Broj pari</label><input type="number" name="pairs" min="1" max="60" value="1"></div>' +
    '<details><summary class="rs-small">Kraće korištenje</summary><div class="field"><label>Stvarno korišteno (min, minimum naplate 15 KM po stazi)</label><input type="number" name="used" min="15" step="15" placeholder="' + ((new Date(r.end_at.replace(' ', 'T')) - new Date(r.start_at.replace(' ', 'T'))) / 60000) + '"></div></details>' +
    (debts.length ? '<h3>Prethodni dug (odvojene stavke)</h3>' + debts.map((d) => '<label class="rs-small" style="display:block"><input type="checkbox" name="debt" value="' + d.id + '"> ' + esc(d.reason) + ' — ' + km(d.amount_cents) + '</label>').join('') : '') +
    '<div id="sum" style="margin-top:10px"></div><div id="e"></div><div class="btns" style="margin-top:10px"><button class="btn accent" id="ok" disabled>Naplaćeno gotovinom — zaključi</button></div>', (box, close) => {
    let shoes = null;
    const recalc = async () => {
      const used = val(box, 'used');
      try {
        const q = await api('GET', 'reservations/' + id + '/settle-quote' + (used ? '?used=' + used : ''));
        const pairs = shoes ? +val(box, 'pairs') || 0 : 0;
        const dsum = $$('[name=debt]:checked', box).reduce((s, c) => s + (debts.find((d) => +d.id === +c.value)?.amount_cents || 0), 0);
        const sh = pairs * q.shoes_cents;
        $('#sum', box).innerHTML = (q.regular ? '<div class="sum"><span>Iz mjesečne</span><span>' + hrs(q.regular) + '</span></div>' : '') + (q.bonus ? '<div class="sum"><span>Bonus</span><span>' + hrs(q.bonus) + '</span></div>' : '') +
          '<div class="sum"><span>Kuglanje</span><span>' + km(q.cash_total) + '</span></div><div class="sum"><span>Patike' + (pairs ? ' × ' + pairs : '') + '</span><span>' + km(sh) + '</span></div>' + (dsum ? '<div class="sum"><span>Prethodni dug</span><span>' + km(dsum) + '</span></div>' : '') +
          '<div class="sum total"><span>Ukupno gotovina</span><span>' + km(q.cash_total + sh + dsum) + '</span></div>';
      } catch (e) { errBox($('#e', box), e); }
    };
    $$('[data-sh]', box).forEach((b) => (b.onclick = () => { shoes = b.dataset.sh === '1'; $$('[data-sh]', box).forEach((x) => x.classList.toggle('primary', x === b)); $('#pairs', box).hidden = !shoes; $('#ok', box).disabled = false; recalc(); }));
    $$('input', box).forEach((x) => (x.onchange = recalc));
    $('#ok', box).onclick = async () => {
      if (shoes === null) return;
      $('#ok', box).disabled = true;
      try {
        const used = val(box, 'used');
        const rc = await api('POST', 'reservations/' + id + '/settle', { shoes_used: shoes, shoe_pairs: shoes ? +val(box, 'pairs') : 0, used_minutes: used ? +used : null, debt_ids: $$('[name=debt]:checked', box).map((c) => +c.value) });
        close(); done('Zaključeno · gotovina ' + km(rc.receipt.total_cents));
      } catch (e) { errBox($('#e', box), e); $('#ok', box).disabled = false; }
    };
    recalc();
  });
}

function editModal(r, done) {
  const staff = can('res.write');
  const min = (new Date(r.end_at.replace(' ', 'T')) - new Date(r.start_at.replace(' ', 'T'))) / 60000;
  modal('<h3>Izmjena rezervacije</h3><div class="row"><div class="field"><label>Datum</label><input type="date" name="date" value="' + r.start_at.slice(0, 10) + '"></div><div class="field"><label>Početak</label><input type="time" step="' + (staff ? 900 : 3600) + '" name="time" value="' + hm(r.start_at) + '"></div>' +
    '<div class="field"><label>Trajanje</label>' + (staff ? '<input type="number" name="minutes" step="30" min="30" value="' + min + '">' : '<select name="minutes">' + [60, 90, 120].map((m) => '<option' + (m === min ? ' selected' : '') + '>' + m + '</option>').join('') + '</select>') + '</div></div>' +
    '<div class="field"><label>Staze</label>' + laneChips(r.lanes) + '</div><p class="rs-small rs-muted">Izmjena ponovo provjerava dostupnost; ako termin nije slobodan, ostaje stara rezervacija.</p><div id="e"></div><button class="btn primary" id="ok">Sačuvaj izmjenu</button>', (box, close) => {
    bindChips(box, () => {});
    $('#ok', box).onclick = async () => { try { await api('POST', 'reservations/' + r.id + '/change', { start: val(box, 'date') + ' ' + val(box, 'time'), minutes: +val(box, 'minutes'), lanes: chosenLanes(box) }); close(); done('Rezervacija izmijenjena'); } catch (e) { errBox($('#e', box), e); } };
  });
}

// ============================================================ događaji
async function openEvent(id) {
  let e; try { e = await api('GET', 'events/' + id); } catch (er) { return toast(er.message); }
  const staffE = can('events.entries'), mgr = can('events.manage');
  const items = e.positions || e.entries;
  let h = '<h3>' + esc(e.title) + '</h3><div class="kv"><div>Vrsta</div><div>' + (e.kind === 'qualification' ? 'Kvalifikacije (nastup 75 min)' : 'Turnir') + '</div><div>Vrijeme</div><div>' + hDate(e.start_at) + ' ' + hm(e.start_at) + '–' + hm(e.end_at) + '</div><div>Staze</div><div>' + e.lanes.join(', ') + '</div>' +
    (e.signup_phone ? '<div>Telefon za prijave</div><div><a href="tel:' + esc(e.signup_phone) + '">' + esc(e.signup_phone) + '</a></div>' : '') + '</div>' + (e.public_note ? '<p>' + esc(e.public_note) + '</p>' : '');
  if (e.kind === 'qualification') h += '<p class="rs-small rs-muted">Slobodna mjesta se ne rezervišu online — prijava telefonom, osoblje upisuje igrača.</p>';
  h += '<table class="t"><tr><th>Vrijeme</th><th>Staza</th><th>Igrač</th>' + (staffE ? '<th></th>' : '') + '</tr>' + (items || []).map((p) => '<tr><td>' + hm(p.start_at) + '–' + hm(p.end_at) + '</td><td>' + p.lane_id + '</td><td>' + (p.player_name ? esc(p.player_name) : '<span class="rs-muted">slobodno</span>') + '</td>' + (staffE ? '<td>' + (p.player_name ? (p.id || e.entries.find((x) => +x.lane_id === +p.lane_id && x.start_at === p.start_at)?.id ? '<button class="btn sm danger" data-del="' + (p.id || e.entries.find((x) => +x.lane_id === +p.lane_id && x.start_at === p.start_at).id) + '">Ukloni</button>' : '') : '<button class="btn sm" data-add="' + p.lane_id + '|' + p.start_at + '">Upiši</button>') + '</td>' : '') + '</tr>').join('') + '</table>';
  if (staffE && e.kind === 'tournament') h += '<h3>Upis nastupa</h3><div class="row"><div class="field"><label>Staza</label><select name="lane">' + e.lanes.map((l) => '<option>' + l + '</option>').join('') + '</select></div><div class="field"><label>Od</label><input type="time" step="900" name="from" value="' + hm(e.start_at) + '"></div><div class="field"><label>Do</label><input type="time" step="900" name="to"></div></div><div class="field"><label>Igrač</label><input name="player"></div><button class="btn" id="add-t">Dodaj</button>';
  if (mgr) h += '<div class="btns" style="margin-top:14px"><button class="btn danger" id="ev-cancel">Otkaži događaj</button></div>';
  h += '<div id="pe"></div>';
  panel(h, (p) => {
    const reload = () => { S.reloadCal && S.reloadCal(); openEvent(id); };
    $$('[data-add]', p).forEach((b) => (b.onclick = async () => { const [lane, start] = b.dataset.add.split('|'); const name = await askText('Ime i prezime igrača:'); if (!name) return; try { await api('POST', 'events/' + id + '/entries', { lane_id: +lane, start, player_name: name }); reload(); } catch (er) { errBox($('#pe', p), er); } }));
    $$('[data-del]', p).forEach((b) => (b.onclick = async () => { if (!await ask('Ukloniti igrača?')) return; try { await api('POST', 'entries/' + b.dataset.del + '/delete', {}); reload(); } catch (er) { errBox($('#pe', p), er); } }));
    if ($('#add-t', p)) $('#add-t', p).onclick = async () => { const d = e.start_at.slice(0, 10); try { await api('POST', 'events/' + id + '/entries', { lane_id: +val(p, 'lane'), start: d + ' ' + val(p, 'from'), end: val(p, 'to') ? d + ' ' + val(p, 'to') : '', player_name: val(p, 'player') }); reload(); } catch (er) { errBox($('#pe', p), er); } };
    if ($('#ev-cancel', p)) $('#ev-cancel', p).onclick = async () => { const why = await askText('Razlog otkazivanja događaja:'); if (!why) return; try { await api('POST', 'events/' + id + '/cancel', { reason: why }); closePanel(); S.reloadCal && S.reloadCal(); } catch (er) { errBox($('#pe', p), er); } };
  });
}

// ============================================================ nalog
async function accountPage(root) {
  if (!S.me) return authForms(root);
  const o = await api('GET', 'me/overview');
  const m = o.monthly; const u = o.user;
  const pass = m.pass;
  let h = '<h1>' + esc(u.first_name + ' ' + u.last_name) + '</h1>' + (u.blocked ? '<div class="note bad">Nalog je blokiran za nove rezervacije' + (o.user.blocked_reason ? ': ' + esc(o.user.blocked_reason) : '') + '. Postojeće rezervacije ostaju.</div>' : '');
  if (o.debt_open_cents) h += '<div class="note bad">Imate evidentiran dug od ' + km(o.debt_open_cents) + '. Provjerite ga s osobljem prilikom dolaska.</div>';
  for (const w of o.warnings) h += '<div class="note">' + esc(w.text) + '</div>';
  h += '<div class="grid2"><div class="card"><h3>Mjesečna</h3>' + (pass ? '<div class="kpi">' + (pass.kind === 'vip' ? 'VIP ' : '') + 'aktivna</div><div>Važi do ' + hDate(addDays(pass.end_date, -1)) + ' · ' + hrs(pass.weekly_minutes) + ' sedmično</div><div>Preostalo ove sedmice: <b>' + hrs(m.regular_left) + '</b></div>' : '<div class="rs-muted">' + (m.latest_pass ? 'Istekla ' + hDate(addDays(m.latest_pass.end_date, -1)) + '.' : 'Nemate mjesečnu.') + '</div>') +
    '<div class="btns" style="margin-top:8px">' + (m.pending_request ? '<span class="tag warn">Zahtjev poslan — osoblje aktivira nakon uplate</span>' : '<button class="btn" data-mr="' + (m.latest_pass ? 'renew' : 'new') + '">' + (m.latest_pass ? 'Želim produžiti' : 'Želim mjesečnu') + '</button>') + '</div><p class="rs-small rs-muted">Zahtjev nije uplata. Mjesečna se aktivira na šanku nakon gotovinske uplate; novi period počinje danom uplate.</p></div>' +
    '<div class="card"><h3>Bonus sati</h3><div class="kpi">' + hrs(m.bonus_balance) + '</div><div class="rs-small rs-muted">Bez roka isteka; troše se kada nema redovnih sati.' + (m.bonus_planned ? ' Planirano za buduće termine: ' + hrs(m.bonus_planned) + '.' : '') + '</div>' +
    (m.bonus_grants.length ? '<ul class="list-plain rs-small">' + m.bonus_grants.map((g) => '<li>+' + hrs(g.minutes) + ' · ' + esc(g.reason) + '</li>').join('') + m.bonus_usage.map((x) => '<li>−' + hrs(x.minutes) + ' · ' + hDate(x.start_at) + ' (' + ({ planned: 'planirano', used: 'iskorišteno', forfeited: 'propalo', compensated: 'nadoknađeno' }[x.state]) + ')</li>').join('') + '</ul>' : '') + '</div></div>';
  h += '<div class="btns"><a class="btn accent" href="/rezervacije/">Nova rezervacija</a></div>';
  h += '<h2>Buduće rezervacije</h2>' + resTable(o.upcoming, true) + '<h2>Prethodne</h2>' + resTable(o.past.slice(0, 40), false);
  if (o.club) {
    h += '<h2>Klub: ' + esc(o.club.name) + '</h2><div class="card"><h3>Klupski treninzi</h3>' + (o.club.trainings.length ? '<ul class="list-plain">' + o.club.trainings.map((t) => '<li>' + hDate(t.start_at) + ' ' + hm(t.start_at) + '–' + hm(t.end_at) + ' · staza ' + esc(t.lanes) + '</li>').join('') + '</ul>' : '<p class="rs-muted">Nema zakazanih treninga.</p>') +
      '<h3>Stalni termini</h3>' + (o.club.series.length ? '<ul class="list-plain">' + o.club.series.map((s) => '<li>' + WD[s.weekday] + ' ' + s.start_time + ' · ' + hrs(s.minutes) + ' · ' + s.lane_count + ' staza · <span class="tag ' + (s.status === 'approved' ? 'ok' : 'warn') + '">' + (s.status === 'approved' ? 'odobren' : 'čeka odobrenje') + '</span></li>').join('') + '</ul>' : '') +
      '<details><summary>Zahtjev za stalni termin</summary><div class="row"><div class="field"><label>Dan</label><select name="weekday">' + [1, 2, 3, 4, 5, 6, 7].map((d) => '<option value="' + d + '">' + WD[d] + '</option>').join('') + '</select></div><div class="field"><label>Početak</label><input type="time" step="3600" name="start_time" value="19:00"></div><div class="field"><label>Trajanje (min)</label><input type="number" name="minutes" value="120" step="30"></div><div class="field"><label>Broj staza</label><input type="number" name="lane_count" value="2" min="1" max="6"></div><div class="field"><label>Od datuma</label><input type="date" name="valid_from" value="' + addDays(new Date().toISOString().slice(0, 10), 1) + '"></div></div><div id="se"></div><button class="btn" id="sreq">Pošalji zahtjev</button><p class="rs-small rs-muted">Zahtjev čeka odobrenje i ne čuva termin dok nije odobren.</p></details></div>';
  }
  h += '<h2>Podaci</h2><div class="card"><div class="kv"><div>Telefon</div><div>' + esc(u.phone_pretty) + '</div><div>Email</div><div>' + esc(u.email || '') + '</div></div><button class="btn" id="logout">Odjava</button></div>';
  root.innerHTML = h;
  $$('[data-mr]', root).forEach((b) => (b.onclick = async () => { try { await api('POST', 'me/monthly-request', { kind: b.dataset.mr }); toast('Zahtjev poslan'); accountPage(root); } catch (e) { toast(e.message); } }));
  $$('[data-res]', root).forEach((b) => (b.onclick = () => openReservation(+b.dataset.res)));
  if ($('#sreq', root)) $('#sreq', root).onclick = async () => { try { await api('POST', 'me/series-request', { weekday: +val(root, 'weekday'), start_time: val(root, 'start_time'), minutes: +val(root, 'minutes'), lane_count: +val(root, 'lane_count'), valid_from: val(root, 'valid_from') }); toast('Zahtjev poslan na odobrenje'); accountPage(root); } catch (e) { errBox($('#se', root), e); } };
  $('#logout', root).onclick = async () => { await api('POST', 'auth/logout', {}); location.href = '/nalog/'; };
  S.reloadAdmin = () => accountPage(root);
}
function resTable(list, upcoming) {
  if (!list.length) return '<p class="rs-muted">Nema.</p>';
  return '<div class="tablewrap"><table class="t"><tr><th>Termin</th><th>Vrsta</th><th>Staze</th><th>Stanje</th><th></th></tr>' + list.map((r) => {
    const st = r.status === 'cancelled' ? 'cancelled' : r.attendance === 'no_show' ? 'no_show' : +r.processed ? 'done' : r.attendance === 'arrived' ? 'arrived' : 'pending';
    return '<tr><td>' + hDate(r.start_at) + ' ' + hm(r.start_at) + '–' + hm(r.end_at) + '</td><td><span class="tag" style="background:' + r.color + ';color:#fff">' + esc(r.category_label) + '</span></td><td>' + esc(r.lanes || '') + '</td><td>' + STATE[st][1] + '</td><td><button class="btn sm" data-res="' + r.id + '">' + (upcoming ? 'Detalji / izmjena' : 'Detalji') + '</button></td></tr>';
  }).join('') + '</table></div>';
}

function authForms(root) {
  const back = new URLSearchParams(location.search).get('povratak') || '/nalog/';
  const go = () => { location.href = back.startsWith('/') ? back : '/nalog/'; };
  root.innerHTML = '<div class="card" style="max-width:520px;margin:10px auto"><div class="tabs"><button data-t="login" class="active">Prijava</button>' + (S.registration ? '<button data-t="reg">Registracija</button><button data-t="reset">Zaboravljena lozinka</button>' : '') + '</div>' + (S.registration ? '' : '<div class="note">Registracija i oporavak lozinke putem telefona još nisu uključeni. Za rezervaciju se obratite Areni.</div>') + '<div id="f"></div></div>';
  const f = $('#f', root);
  // Nikad ne tvrditi da je SMS poslan ako nije (status dolazi sa servera nakon stvarnog pokušaja slanja).
  const smsNote = (r, okText) => r.sms === 'sent' || (r.sms == null && !r.dev_code) ? '<p>' + okText + '</p>' : r.sms === 'not_configured' ? '<p class="rs-muted">SMS servis još nije povezan — poruka nije poslana.</p>' : r.sms === 'failed' ? '<div class="note bad">Slanje SMS-a trenutno nije uspjelo. Pokušajte ponovo za minut.</div>' : '';
  const devNote = (code) => code ? '<div class="note"><b>PROBNI KOD</b> (samo probno okruženje — u pravom sistemu stiže SMS-om): <b>' + esc(code) + '</b></div>' : '';
  const show = (t) => {
    $$('[data-t]', root).forEach((b) => b.classList.toggle('active', b.dataset.t === t));
    if (t === 'login') {
      f.innerHTML = '<div class="field"><label>Broj telefona</label><input name="login" inputmode="tel" autocomplete="username"></div><div class="field"><label>Lozinka</label><input type="password" name="password" autocomplete="current-password"></div><div id="e"></div><button class="btn primary" id="go">Prijava</button>';
      $('#go', f).onclick = async () => { try { await api('POST', 'auth/login', { login: val(f, 'login'), password: val(f, 'password') }); go(); } catch (e) { errBox($('#e', f), e); } };
      $('[name=password]', f).onkeydown = (e) => e.key === 'Enter' && $('#go', f).click();
    }
    if (t === 'reg') {
      f.innerHTML = '<div class="row"><div class="field"><label>Ime *</label><input name="first_name" autocomplete="given-name"></div><div class="field"><label>Prezime *</label><input name="last_name" autocomplete="family-name"></div></div><div class="field"><label>Telefon *</label><input name="phone" inputmode="tel" autocomplete="tel"></div><div class="field"><label>Email *</label><input type="email" name="email" autocomplete="email"></div><div class="field"><label>Lozinka * (najmanje 8 znakova)</label><input type="password" name="password" autocomplete="new-password"></div><div id="e"></div><button class="btn primary" id="go">Pošalji kod na telefon</button>';
      $('#go', f).onclick = async () => {
        try {
          const r = await api('POST', 'auth/register', { first_name: val(f, 'first_name'), last_name: val(f, 'last_name'), phone: val(f, 'phone'), email: val(f, 'email'), password: val(f, 'password') });
          f.innerHTML = smsNote(r, 'Kod je poslan SMS-om na ' + esc(r.phone) + '.') + devNote(r.dev_code) + '<div class="field"><label>Kod</label><input name="code" inputmode="numeric" autocomplete="one-time-code"></div><div id="e"></div><button class="btn primary" id="ok">Potvrdi telefon</button>';
          $('#ok', f).onclick = async () => { try { await api('POST', 'auth/confirm', { phone: r.phone, code: val(f, 'code') }); go(); } catch (e) { errBox($('#e', f), e); } };
        } catch (e) { errBox($('#e', f), e); }
      };
    }
    if (t === 'reset') {
      f.innerHTML = '<div class="field"><label>Potvrđeni broj telefona</label><input name="phone" inputmode="tel"></div><div id="e"></div><button class="btn primary" id="go">Pošalji kod</button>';
      $('#go', f).onclick = async () => {
        try {
          const r = await api('POST', 'auth/reset', { phone: val(f, 'phone') });
          f.innerHTML = smsNote(r, 'Ako je broj registrovan, kod je poslan SMS-om.') + devNote(r.dev_code) + '<div class="field"><label>Kod</label><input name="code" inputmode="numeric"></div><div class="field"><label>Nova lozinka</label><input type="password" name="password" autocomplete="new-password"></div><div id="e"></div><button class="btn primary" id="ok">Postavi lozinku</button>';
          $('#ok', f).onclick = async () => { try { await api('POST', 'auth/reset-confirm', { phone: r.phone, code: val(f, 'code'), password: val(f, 'password') }); go(); } catch (e) { errBox($('#e', f), e); } };
        } catch (e) { errBox($('#e', f), e); }
      };
    }
  };
  $$('[data-t]', root).forEach((b) => (b.onclick = () => show(b.dataset.t)));
  show('login');
}

// ============================================================ administracija
const ADMIN_TABS = [
  ['kalendar', 'Kalendar', 'res.read'], ['dan', 'Dnevni obračun', 'day.view'], ['zahtjevi', 'Zahtjevi', 'res.read'], ['korisnici', 'Korisnici', 'res.write'],
  ['mjesecne', 'Mjesečne', 'res.read'], ['klubovi', 'Klubovi', 'res.read'], ['serije', 'Stalni termini', 'clubs.manage'], ['obracuni', 'Obračuni klubova', 'clubs.billing'],
  ['dogadjaji', 'Događaji', 'res.read'], ['statistika', 'Statistika', 'stats.view'], ['cijene', 'Cijene', 'res.read'], ['ovlastenja', 'Ovlaštenja', '__owner'],
  ['obavijesti', 'Obavijesti', 'settings.manage'], ['historija', 'Historija', 'audit.view'],
];
async function adminPage(root) {
  if (!S.me) { location.href = '/nalog/?povratak=/administracija/'; return; }
  if (!S.staff) { root.innerHTML = '<div class="note bad">Ovaj dio je samo za osoblje.</div>'; return; }
  const tabs = ADMIN_TABS.filter(([, , p]) => (p === '__owner' ? S.me.role === 'owner' : can(p)));
  const cur = () => (location.hash.slice(1) || 'kalendar');
  root.innerHTML = '<div class="tabs" id="atabs">' + tabs.map(([k, l]) => '<button data-k="' + k + '">' + l + '</button>').join('') + '</div><div id="aview"></div>';
  const show = async () => {
    const k = cur(); $$('#atabs button', root).forEach((b) => b.classList.toggle('active', b.dataset.k === k));
    const v = $('#aview', root); v.innerHTML = '<p class="rs-muted">Učitavanje…</p>';
    S.reloadAdmin = show; S.reloadCal = null;
    try { await (ADMIN[k] || ADMIN.kalendar)(v); } catch (e) { errBox(v, e); }
  };
  $$('#atabs button', root).forEach((b) => (b.onclick = () => { location.hash = b.dataset.k; }));
  addEventListener('hashchange', show);
  show();
}

const ADMIN = {
  async kalendar(v) {
    const d = await api('GET', 'dashboard');
    v.innerHTML = '<div class="dash">' + (d.cash_today != null ? '<div class="card"><div class="lbl">Današnji promet (gotovina)</div><div class="kpi">' + km(d.cash_today) + '</div></div>' : '') +
      '<div class="card"><div class="lbl">Neobrađeni / nenaplaćeni</div><div class="kpi">' + d.unprocessed.length + '</div></div><div class="card"><div class="lbl">Zahtjevi na čekanju</div><div class="kpi"><a href="#zahtjevi">' + d.pending_requests + '</a></div></div>' +
      (d.unsent_messages != null ? '<div class="card"><div class="lbl">Neposlane poruke</div><div class="kpi"><a href="#obavijesti">' + d.unsent_messages + '</a></div></div>' : '') + '</div>' +
      (d.cron ? (d.cron.last_utc ? '<div class="rs-small rs-muted" style="margin-bottom:8px">Pozadinski poslovi (cron) zadnji put: ' + esc(d.cron.last_utc) + ' UTC' + (d.cron.error ? ' · <span class="tag bad">greška: ' + esc(d.cron.error) + '</span>' : '') + '</div>' : '<div class="note bad">Pozadinski poslovi (cron) se još nisu pokrenuli — podsjetnici, obavijesti i backup ne rade.</div>') : '') +
      (d.unprocessed.length ? '<div class="note bad"><b>Neobrađeni termini:</b> ' + d.unprocessed.map((r) => '<a href="#" data-r="' + r.id + '">' + hDate(r.start_at) + ' ' + hm(r.start_at) + ' ' + esc(r.who || '') + '</a>').join(' · ') + '</div>' : '') +
      '<div class="btns" style="margin-bottom:8px">' + (can('res.write') ? '<button class="btn accent" id="newres">+ Rezervacija</button>' : '') + (can('events.birthday') ? '<button class="btn" id="newbd">+ Rođendan</button>' : '') + (can('events.manage') ? '<button class="btn" id="newm">+ Utakmica</button>' : '') + '</div><div id="calwrap"></div>';
    $$('[data-r]', v).forEach((a) => (a.onclick = (e) => { e.preventDefault(); openReservation(+a.dataset.r); }));
    // podrazumijevano: sljedeći puni sat danas (najkasnije 22:00), ili 18:00 za drugi dan
    const nextHour = () => { const h = new Date().getHours() + 1; return pad(Math.min(22, Math.max(10, h))) + ':00'; };
    const pre = (cat) => { const day = S.date || d.today; return { date: day, time: day === d.today ? nextHour() : '18:00', lane: 1, category: cat }; };
    if ($('#newres', v)) $('#newres', v).onclick = () => staffBooking(pre('recreation'));
    if ($('#newbd', v)) $('#newbd', v).onclick = () => staffBooking(pre('birthday'));
    if ($('#newm', v)) $('#newm', v).onclick = () => staffBooking(pre('match'));
    await calendarView($('#calwrap', v), 'staff');
  },

  async dan(v) {
    const date = S.dayDate || new Date().toISOString().slice(0, 10);
    const d = await api('GET', 'day?date=' + date);
    S.dayDate = d.date;
    const t = d.totals;
    let h = '<div class="cal-bar"><span class="date-title">Dnevni obračun ' + hDate(d.date) + '</span><input type="date" id="dd" value="' + d.date + '"></div>';
    h += d.closed ? '<div class="note ok">Dan je zaključen ' + esc(d.closed.closed_utc) + ' UTC · ' + esc(d.closed.by_name || '') + '</div>' : '';
    h += '<div class="grid2"><div class="card"><h3>Gotovina</h3><div class="sum"><span>Kuglanje</span><span>' + km(t.lane) + '</span></div><div class="sum"><span>Patike (' + t.shoe_pairs + ' pari)</span><span>' + km(t.shoes) + '</span></div><div class="sum"><span>Mjesečne</span><span>' + km(t.monthly) + '</span></div><div class="sum"><span>Naplaćeni dugovi</span><span>' + km(t.debt) + '</span></div>' + (t.corrections ? '<div class="sum"><span>od toga ispravke</span><span>' + km(t.corrections) + '</span></div>' : '') + '<div class="sum total"><span>Ukupno gotovina</span><span>' + km(t.total) + '</span></div></div>' +
      '<div class="card"><h3>Klupske vrijednosti (nije gotovina)</h3>' + (t.club_value.length ? t.club_value.map((c) => '<div class="sum"><span>' + esc(c.club) + ' · ' + esc(c.category) + ' × ' + c.count + '</span><span>' + km(c.value_cents) + '</span></div>').join('') : '<p class="rs-muted">Nema.</p>') + '<p class="rs-small rs-muted">Klupski obračun nije dokaz primljenog novca.</p></div></div>';
    h += '<h3>Prepreke za zaključenje</h3>' + (d.blockers.length ? '<ul class="list-plain">' + d.blockers.map((b) => '<li>' + b.time + ' · ' + esc(b.who) + ' · ' + esc(b.category) + ' — <b>' + esc(b.reason) + '</b> <button class="btn sm" data-r="' + b.reservation_id + '">Otvori</button></li>').join('') + '</ul>' : '<p class="rs-muted">Nema prepreka.</p>');
    if (d.open_later && d.open_later.length) h += '<p class="rs-small rs-muted">Ne blokiraju zaključenje (još nisu završeni): ' + d.open_later.map((x) => x.time + ' ' + esc(x.who || x.category)).join(' · ') + '. Njihova naplata nakon zaključenja ulazi u obračun sljedećeg dana.</p>';
    if (d.closed && d.payment_day !== d.date) h += '<p class="rs-small">Nove uplate se upisuju u dan <b>' + hDate(d.payment_day) + '</b> (ovaj dan je zaključen i ne mijenja se).</p>';
    if (!d.closed && can('day.close')) h += '<button class="btn primary" id="close"' + (d.blockers.length ? ' disabled' : '') + '>Zaključi dan</button><div id="e"></div>';
    h += '<h3>Stavke naplate</h3><div class="tablewrap"><table class="t"><tr><th>#</th><th>Vrsta</th><th>Gost</th><th class="num">Iznos</th><th>Unio</th><th></th></tr>' + t.payments.map((p) => '<tr><td>' + p.id + '</td><td>' + ({ lane: 'Kuglanje', shoes: 'Patike', monthly: 'Mjesečna', debt: 'Dug' }[p.kind]) + (p.reverses_payment_id ? ' (storno #' + p.reverses_payment_id + ')' : '') + (p.reason ? '<div class="rs-small rs-muted">' + esc(p.reason) + '</div>' : '') + '</td><td>' + esc(p.who || '') + '</td><td class="num">' + km(p.amount_cents) + '</td><td class="rs-small">' + esc(p.by_name || '') + '</td><td>' + (can('cash.correct') && !p.reverses_payment_id && !d.closed ? '<button class="btn sm" data-fix="' + p.id + '">Ispravi</button>' : '') + (p.reservation_id ? ' <button class="btn sm" data-r="' + p.reservation_id + '">Termin</button>' : '') + '</td></tr>').join('') + '</table></div>';
    v.innerHTML = h;
    $('#dd', v).onchange = (e) => { S.dayDate = e.target.value; ADMIN.dan(v); };
    $$('[data-r]', v).forEach((b) => (b.onclick = () => openReservation(+b.dataset.r)));
    $$('[data-fix]', v).forEach((b) => (b.onclick = () => modal('<h3>Ispravka uplate #' + b.dataset.fix + '</h3><p class="rs-small">Original ostaje; pravi se storno i (opciono) nova stavka. Autor i razlog se bilježe i šalju primaocima.</p><div class="field"><label>Ispravan iznos (KM, prazno = samo storno)</label><input name="amt"></div><div class="field"><label>Razlog *</label><input name="reason"></div><div id="e"></div><button class="btn primary" id="ok">Sačuvaj ispravku</button>', (box, close) => {
      $('#ok', box).onclick = async () => { try { await api('POST', 'payments/' + b.dataset.fix + '/correct', { new_amount: val(box, 'amt'), reason: val(box, 'reason') }); close(); ADMIN.dan(v); } catch (e) { errBox($('#e', box), e); } };
    })));
    if ($('#close', v)) $('#close', v).onclick = async () => { if (!await ask('Zaključiti dan ' + hDate(d.date) + '?')) return; try { await api('POST', 'day/close', { date: d.date }); toast('Dan zaključen'); ADMIN.dan(v); } catch (e) { errBox($('#e', v), e); } };
  },

  async zahtjevi(v) {
    const r = await api('GET', 'requests');
    v.innerHTML = '<h2>Mjesečne</h2>' + (r.monthly.length ? '<ul class="list-plain">' + r.monthly.map((m) => '<li>' + esc(m.name) + ' · ' + esc(m.phone) + ' · ' + (m.kind === 'renew' ? 'produženje' : 'nova') + ' · <button class="btn sm" data-u="' + m.user_id + '">Otvori profil / aktiviraj</button> ' + (can('monthly.manage') ? '<button class="btn sm danger" data-rej="' + m.id + '">Odbij</button>' : '') + '</li>').join('') + '</ul>' : '<p class="rs-muted">Nema.</p>') +
      '<h2>Stalni termini</h2>' + (r.series.length ? '<ul class="list-plain">' + r.series.map((s) => '<li>' + esc(s.club_name) + ' · ' + WD[s.weekday] + ' ' + s.start_time + ' · ' + hrs(s.minutes) + ' · ' + s.lane_count + ' staza · od ' + hDate(s.valid_from) + ' · ' + esc(s.requester || '') + ' <button class="btn sm" data-s="' + s.id + '">Provjeri i odluči</button></li>').join('') + '</ul>' : '<p class="rs-muted">Nema.</p>');
    $$('[data-u]', v).forEach((b) => (b.onclick = () => userCard(+b.dataset.u)));
    $$('[data-rej]', v).forEach((b) => (b.onclick = async () => { await api('POST', 'monthly-requests/' + b.dataset.rej + '/reject', {}); ADMIN.zahtjevi(v); }));
    $$('[data-s]', v).forEach((b) => (b.onclick = () => seriesDecide(+b.dataset.s, () => ADMIN.zahtjevi(v))));
  },

  async korisnici(v) {
    v.innerHTML = '<div class="row"><div class="field"><label>Pretraga (telefon ili ime)</label><input id="uq" placeholder="066…"></div>' + (can('users.manage') ? '<button class="btn" id="unew">+ Novi profil</button>' : '') + '</div><div id="ul"></div>';
    let t;
    $('#uq', v).oninput = (e) => { clearTimeout(t); t = setTimeout(async () => { const list = await api('GET', 'users?q=' + encodeURIComponent(e.target.value)).catch(() => []); $('#ul', v).innerHTML = '<ul class="list-plain">' + list.map((u) => '<li><a href="#korisnici" data-u="' + u.id + '">' + esc(u.first_name + ' ' + u.last_name) + '</a> · ' + esc(u.phone_pretty) + (u.has_account ? '' : ' <span class="tag">profil bez naloga</span>') + (u.debt_cents ? ' <span class="tag bad">dug ' + km(u.debt_cents) + '</span>' : '') + (u.blocked ? ' <span class="tag bad">blokiran</span>' : '') + '</li>').join('') + '</ul>'; $$('[data-u]', v).forEach((a) => (a.onclick = (ev) => { ev.preventDefault(); userCard(+a.dataset.u); })); }, 250); };
    if ($('#unew', v)) $('#unew', v).onclick = () => modal('<h3>Novi profil</h3><p class="rs-small">Kada korisnik kasnije potvrdi isti telefon, nalog se povezuje s ovim profilom.</p><div class="row"><div class="field"><label>Ime *</label><input name="first_name"></div><div class="field"><label>Prezime</label><input name="last_name"></div></div><div class="field"><label>Telefon *</label><input name="phone"></div><div class="field"><label>Email</label><input name="email"></div><div id="e"></div><button class="btn primary" id="ok">Sačuvaj</button>', (box, close) => {
      $('#ok', box).onclick = async () => { try { const u = await api('POST', 'users', { first_name: val(box, 'first_name'), last_name: val(box, 'last_name'), phone: val(box, 'phone'), email: val(box, 'email') }); close(); userCard(u.id); } catch (e) { errBox($('#e', box), e); } };
    });
  },

  async mjesecne(v) {
    const list = await api('GET', 'monthly');
    v.innerHTML = '<h2>Mjesečne (aktivne i nedavno istekle)</h2><div class="tablewrap"><table class="t"><tr><th>Korisnik</th><th>Vrsta</th><th>Sedmično</th><th>Od</th><th>Važi do</th><th></th></tr>' + list.map((p) => '<tr><td>' + esc(p.name) + '</td><td>' + (p.kind === 'vip' ? 'VIP' : 'Standardna') + '</td><td>' + hrs(p.weekly_minutes) + '</td><td>' + hDate(p.start_date) + '</td><td>' + hDate(addDays(p.end_date, -1)) + (p.status === 'ended' ? ' <span class="tag">završena</span>' : '') + '</td><td><button class="btn sm" data-u="' + p.user_id + '">Profil</button></td></tr>').join('') + '</table></div>';
    $$('[data-u]', v).forEach((b) => (b.onclick = () => userCard(+b.dataset.u)));
  },

  async klubovi(v) {
    const clubs = await api('GET', 'clubs');
    v.innerHTML = '<h2>Klubovi</h2>' + clubs.map((c) => '<div class="card"><b>' + esc(c.name) + '</b> · članova: ' + c.members + ' · email za obračune: ' + esc(c.email || '— nije unesen') + '<br><span class="rs-small">Utakmica: ' + (c.match_price_mode === 'fixed' ? 'fiksno ' + km(c.match_fixed_cents) : km(c.match_lane_hour_cents) + ' po stazi i satu') + '</span>' + (can('clubs.manage') ? ' <div class="btns" style="margin-top:6px"><button class="btn sm" data-c="' + c.id + '">Uredi</button><button class="btn sm" data-m="' + c.id + '">Članovi</button></div>' : '') + '</div>').join('') + (can('clubs.manage') ? '<button class="btn" data-c="0">+ Novi klub</button>' : '') + '<div id="cm"></div>';
    $$('[data-c]', v).forEach((b) => (b.onclick = () => {
      const c = clubs.find((x) => +x.id === +b.dataset.c) || { match_price_mode: 'lane_hour', match_fixed_cents: 0, match_lane_hour_cents: 1000 };
      modal('<h3>' + (c.id ? 'Uredi klub' : 'Novi klub') + '</h3><div class="row"><div class="field"><label>Naziv</label><input name="name" value="' + esc(c.name || '') + '"></div><div class="field"><label>Kratko</label><input name="short" value="' + esc(c.short || '') + '"></div></div><div class="field"><label>Email za obračune</label><input type="email" name="email" value="' + esc(c.email || '') + '"></div>' +
        '<div class="field"><label>Način cijene utakmice</label><select name="mode"><option value="lane_hour"' + (c.match_price_mode === 'lane_hour' ? ' selected' : '') + '>Cijena po stazi i satu</option><option value="fixed"' + (c.match_price_mode === 'fixed' ? ' selected' : '') + '>Fiksni iznos cijele utakmice</option></select></div><div class="row"><div class="field"><label>Fiksno (KM)</label><input name="fixed" value="' + (c.match_fixed_cents / 100).toFixed(2).replace('.', ',') + '"></div><div class="field"><label>Po stazi i satu (KM)</label><input name="lh" value="' + (c.match_lane_hour_cents / 100).toFixed(2).replace('.', ',') + '"></div></div><div id="e"></div><button class="btn primary" id="ok">Sačuvaj</button>', (box, close) => {
        $('#ok', box).onclick = async () => { try { await api('POST', 'clubs', { id: c.id || null, name: val(box, 'name'), short: val(box, 'short'), email: val(box, 'email'), match_price_mode: val(box, 'mode'), match_fixed: val(box, 'fixed'), match_lane_hour: val(box, 'lh') }); close(); ADMIN.klubovi(v); } catch (e) { errBox($('#e', box), e); } };
      });
    }));
    $$('[data-m]', v).forEach((b) => (b.onclick = async () => { const m = await api('GET', 'clubs/' + b.dataset.m + '/members'); $('#cm', v).innerHTML = '<h3>Članovi</h3><ul class="list-plain">' + m.map((u) => '<li><a href="#" data-u="' + u.id + '">' + esc(u.first_name + ' ' + u.last_name) + '</a> · ' + esc(u.phone) + '</li>').join('') + '</ul><p class="rs-small rs-muted">Članstvo se dodjeljuje u profilu korisnika (Korisnici → profil → Klub).</p>'; $$('[data-u]', v).forEach((a) => (a.onclick = (e) => { e.preventDefault(); userCard(+a.dataset.u); })); }));
  },

  async serije(v) {
    const [list, clubs] = await Promise.all([api('GET', 'series'), api('GET', 'clubs')]);
    const lbl = { pending: ['warn', 'čeka'], approved: ['ok', 'odobren'], ended: ['', 'ukinut'], rejected: ['bad', 'odbijen'] };
    v.innerHTML = '<h2>Stalni klupski termini</h2><div class="tablewrap"><table class="t"><tr><th>Klub</th><th>Pravilo</th><th>Važi</th><th>Stanje</th><th></th></tr>' + list.map((s) => '<tr><td>' + esc(s.club_name) + '</td><td>' + WD[s.weekday] + ' ' + s.start_time + ' · ' + hrs(s.minutes) + ' · ' + s.lane_count + ' staza' + (s.preferred_lanes ? ' (željene ' + s.preferred_lanes + ')' : '') + '</td><td>od ' + hDate(s.valid_from) + (s.valid_to ? ' do ' + hDate(s.valid_to) : ' · dok se ne ukine') + '</td><td><span class="tag ' + lbl[s.status][0] + '">' + lbl[s.status][1] + '</span></td><td>' + (s.status === 'pending' ? '<button class="btn sm" data-d="' + s.id + '">Provjeri i odluči</button>' : s.status === 'approved' || s.status === 'ended' ? '<button class="btn sm" data-det="' + s.id + '">Pregled</button> ' + (s.status === 'ended' ? '' : '<button class="btn sm" data-skip="' + s.id + '">Preskoči datum</button> <button class="btn sm danger" data-end="' + s.id + '">Ukini</button>') : '') + '</td></tr>').join('') + '</table></div>' +
      '<div class="card"><h3>Novi stalni termin</h3><div class="row"><div class="field"><label>Klub</label><select name="club_id">' + clubs.map((c) => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('') + '</select></div><div class="field"><label>Dan</label><select name="weekday">' + [1, 2, 3, 4, 5, 6, 7].map((d) => '<option value="' + d + '">' + WD[d] + '</option>').join('') + '</select></div><div class="field"><label>Početak</label><input type="time" step="3600" name="start_time" value="19:00"></div><div class="field"><label>Trajanje (min)</label><input type="number" step="30" name="minutes" value="120"></div><div class="field"><label>Broj staza</label><input type="number" name="lane_count" value="2" min="1" max="6"></div><div class="field"><label>Željene staze (npr. 1,2)</label><input name="pref"></div><div class="field"><label>Od</label><input type="date" name="valid_from" value="' + new Date().toISOString().slice(0, 10) + '"></div><div class="field"><label>Do (prazno = dok se ne ukine)</label><input type="date" name="valid_to"></div></div><div id="e"></div><button class="btn primary" id="add">Dodaj i provjeri konflikte</button></div>';
    $('#add', v).onclick = async () => { try { const s = await api('POST', 'series', { club_id: +val(v, 'club_id'), weekday: +val(v, 'weekday'), start_time: val(v, 'start_time'), minutes: +val(v, 'minutes'), lane_count: +val(v, 'lane_count'), preferred_lanes: (val(v, 'pref') || '').split(',').map((x) => +x).filter(Boolean), valid_from: val(v, 'valid_from'), valid_to: val(v, 'valid_to') }); seriesDecide(+s.id, () => ADMIN.serije(v)); } catch (e) { errBox($('#e', v), e); } };
    $$('[data-d]', v).forEach((b) => (b.onclick = () => seriesDecide(+b.dataset.d, () => ADMIN.serije(v))));
    $$('[data-det]', v).forEach((b) => (b.onclick = async () => {
      const d = await api('GET', 'series/' + b.dataset.det + '/detail');
      const st = { active: '', cancelled: ' <span class="tag bad">otkazano</span>' };
      modal('<h3>Stalni termin — ' + esc(d.series.club_name) + '</h3><p>' + WD[d.series.weekday] + ' ' + d.series.start_time + ' · ' + hrs(d.series.minutes) + ' · ' + d.series.lane_count + ' staza' + (d.preferred.length ? ' · željene staze ' + d.preferred.join(', ') : '') + '</p>' +
        '<h3>Upisani termini</h3><table class="t"><tr><th>Datum</th><th>Staze</th><th></th></tr>' + d.occurrences.map((o) => '<tr><td>' + hDate(o.date) + '</td><td>' + (o.changed ? '<b>' + o.lanes.join(', ') + '</b> <span class="tag warn">zamjenska staza</span>' : o.lanes.join(', ')) + '</td><td>' + (st[o.status] || '') + '</td></tr>').join('') + '</table>' +
        '<h3>Preskočeni datumi</h3>' + (d.exceptions.length ? '<ul class="list-plain rs-small">' + d.exceptions.map((x) => '<li>' + hDate(x.date) + ' — ' + esc(x.reason || '') + '</li>').join('') + '</ul>' : '<p class="rs-muted">Nema.</p>') +
        '<p class="rs-small rs-muted">' + esc(d.note) + '</p>');
    }));
    $$('[data-end]', v).forEach((b) => (b.onclick = async () => { const d = await askText('Ukinuti od datuma (GGGG-MM-DD, uključivo):', new Date().toISOString().slice(0, 10)); if (!d) return; try { await api('POST', 'series/' + b.dataset.end + '/end', { from: d }); ADMIN.serije(v); } catch (e) { toast(e.message); } }));
    $$('[data-skip]', v).forEach((b) => (b.onclick = async () => { const d = await askText('Preskočiti datum (GGGG-MM-DD):'); if (!d) return; try { await api('POST', 'series/' + b.dataset.skip + '/skip', { date: d, reason: 'Preskočeno' }); toast('Datum preskočen'); } catch (e) { toast(e.message); } }));
  },

  async obracuni(v) {
    const clubs = await api('GET', 'clubs');
    const club = S.billClub || clubs[0]?.id;
    S.billClub = club;
    const [items, arch] = await Promise.all([api('GET', 'statements/unbilled?club=' + club), api('GET', 'statements?club=' + club)]);
    const total = items.reduce((s, i) => s + i.amount_cents, 0);
    v.innerHTML = '<div class="row"><div class="field"><label>Klub</label><select id="bc">' + clubs.map((c) => '<option value="' + c.id + '"' + (+c.id === +club ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select></div></div>' +
      '<div class="note">Program ne prati uplate klubova. Oznake: „Obračunato“ / „Neobračunato“.</div><h2>Neobračunato (održano)</h2><div class="tablewrap"><table class="t"><tr><th>Datum</th><th>Stavka</th><th>Staze</th><th>Trajanje</th><th class="num">Iznos</th><th></th></tr>' + items.map((i) => '<tr><td>' + hDate(i.start_at) + ' ' + hm(i.start_at) + '</td><td>' + esc(i.description) + '</td><td>' + i.lanes + '</td><td>' + hrs(i.minutes) + '</td><td class="num">' + km(i.amount_cents) + '</td><td>' + (i.category !== 'training' ? '<button class="btn sm" data-one="' + i.id + '">Obračun samo ove utakmice</button>' : '') + '</td></tr>').join('') + '<tr><td colspan="4"><b>Ukupno neobračunato</b></td><td class="num"><b>' + km(total) + '</b></td><td></td></tr></table></div>' +
      '<div id="e"></div><button class="btn primary" id="mk"' + (items.length ? '' : ' disabled') + '>Napravi obračun</button><p class="rs-small rs-muted">Obuhvata sve održano i neobračunato do ovog trenutka; termin koji još traje ulazi tek nakon završetka. PDF se odmah šalje na email kluba.</p>' +
      '<h2>Arhiva obračuna</h2><ul class="list-plain">' + arch.map((s) => '<li>' + esc(s.number) + ' · ' + (s.kind === 'single_match' ? 'utakmica' : 'puni') + ' · ' + hDate(s.period_from) + '–' + hDate(s.period_to) + ' · <b>' + km(s.total_cents) + '</b> · <a href="/rezervacije/api/statements/' + s.id + '/pdf" target="_blank">PDF</a> <button class="btn sm" data-st="' + s.id + '">Detalji</button></li>').join('') + '</ul><div id="sd"></div>';
    $('#bc', v).onchange = (e) => { S.billClub = +e.target.value; ADMIN.obracuni(v); };
    const mk = async (rid) => { if (!await ask(rid ? 'Napraviti izdvojeni obračun ove utakmice?' : 'Napraviti obračun za ' + km(total) + '?')) return; try { const s = await api('POST', 'statements', { club_id: club, reservation_id: rid || null }); toast('Obračun ' + s.number + ' napravljen' + (s.club_email ? ' · email u redu slanja' : ' · klub nema email')); ADMIN.obracuni(v); } catch (e) { errBox($('#e', v), e); } };
    $('#mk', v).onclick = () => mk(null);
    $$('[data-one]', v).forEach((b) => (b.onclick = () => mk(+b.dataset.one)));
    $$('[data-st]', v).forEach((b) => (b.onclick = async () => { const s = await api('GET', 'statements/' + b.dataset.st); $('#sd', v).innerHTML = '<div class="card"><h3>' + esc(s.number) + '</h3>' + s.items.map((i) => '<div class="sum"><span>' + hDate(i.item_date) + ' · ' + esc(i.description) + ' · ' + esc(i.unit_label) + '</span><span>' + km(i.amount_cents) + '</span></div>').join('') + '<div class="sum total"><span>Ukupno</span><span>' + km(s.total_cents) + '</span></div><p class="rs-small">Email: ' + (s.email_status ? esc({ pending: 'čeka slanje', sending: 'šalje se', sent: 'poslano ' + (s.email_status.sent_utc || ''), failed: 'neuspjelo', not_configured: 'NIJE poslano — kanal nije povezan' }[s.email_status.status]) : 'klub nema email') + '</p></div>'; }));
  },

  async dogadjaji(v) {
    const list = await api('GET', 'events');
    v.innerHTML = '<h2>Turniri i kvalifikacije</h2><ul class="list-plain">' + list.map((e) => '<li>' + hDate(e.start_at) + ' ' + hm(e.start_at) + '–' + hm(e.end_at) + ' · ' + (e.kind === 'qualification' ? 'Kvalifikacije' : 'Turnir') + ' · <b>' + esc(e.title) + '</b>' + (e.status === 'cancelled' ? ' <span class="tag bad">otkazan</span>' : '') + ' <button class="btn sm" data-e="' + e.id + '">Otvori</button></li>').join('') + '</ul>' +
      (can('events.manage') ? '<div class="card"><h3>Novi događaj</h3><div class="row"><div class="field"><label>Vrsta</label><select name="kind"><option value="tournament">Turnir (svih 6 staza)</option><option value="qualification">Kvalifikacije (nastupi 75 min)</option></select></div><div class="field"><label>Naziv</label><input name="title"></div></div><div class="row"><div class="field"><label>Datum</label><input type="date" name="date" value="' + new Date().toISOString().slice(0, 10) + '"></div><div class="field"><label>Početak</label><input type="time" step="900" name="start" value="08:00"></div><div class="field"><label>Kraj</label><input type="time" step="900" name="end" value="23:00"></div></div><div class="field" id="ql" hidden><label>Staze (kvalifikacije)</label>' + laneChips([1, 2, 3, 4, 5, 6]) + '</div><div class="field"><label>Telefon za prijave (javno)</label><input name="phone"></div><div class="field"><label>Javna napomena</label><textarea name="note"></textarea></div><div id="e"></div><div class="btns"><button class="btn" id="pv">Provjeri konflikte</button><button class="btn primary" id="cr">Potvrdi događaj</button></div></div>' : '');
    $$('[data-e]', v).forEach((b) => (b.onclick = () => openEvent(+b.dataset.e)));
    if (!can('events.manage')) return;
    bindChips(v, () => {});
    $('[name=kind]', v).onchange = () => ($('#ql', v).hidden = val(v, 'kind') !== 'qualification');
    const body = () => ({ kind: val(v, 'kind'), title: val(v, 'title'), start: val(v, 'date') + ' ' + val(v, 'start'), end: val(v, 'date') + ' ' + val(v, 'end'), lanes: chosenLanes(v), signup_phone: val(v, 'phone'), public_note: val(v, 'note') });
    $('#pv', v).onclick = async () => { try { const r = await api('POST', 'events/preview', body()); if (!r.conflicts.length) $('#e', v).innerHTML = '<div class="note ok">Nema konflikata.</div>'; else errBox($('#e', v), Object.assign(new Error('Konflikti (riješite ih prije potvrde):'), { data: { conflicts: r.conflicts } })); } catch (e) { errBox($('#e', v), e); } };
    $('#cr', v).onclick = async () => { try { const e = await api('POST', 'events', body()); toast('Događaj potvrđen'); ADMIN.dogadjaji(v); openEvent(e.id); } catch (e) { errBox($('#e', v), e); } };
  },

  async statistika(v) {
    const to = S.statTo || new Date().toISOString().slice(0, 10), from = S.statFrom || addDays(to, -29);
    const s = await api('GET', 'stats?from=' + from + '&to=' + to);
    const c = s.current, p = s.previous;
    const diff = (a, b) => (b ? ' <span class="rs-small ' + (a >= b ? '' : 'rs-muted') + '">(' + (a >= b ? '+' : '') + Math.round(((a - b) * 100) / b) + '%)</span>' : '');
    const cats = Object.fromEntries((S.cal?.categories || []).map((x) => [x.code, x.label]));
    v.innerHTML = '<div class="row"><div class="field"><label>Od</label><input type="date" id="sf" value="' + from + '"></div><div class="field"><label>Do</label><input type="date" id="stt" value="' + to + '"></div></div>' +
      '<div class="dash"><div class="card"><div class="lbl">Gotovinski prihod</div><div class="kpi">' + km(c.cash.total) + '</div><div class="rs-small">prethodno ' + km(p.cash.total) + diff(c.cash.total, p.cash.total) + '</div></div><div class="card"><div class="lbl">Popunjenost</div><div class="kpi">' + c.occupancy_pct + '%</div><div class="rs-small">' + c.lane_hours_total + ' staza-sati · prethodno ' + p.occupancy_pct + '%</div></div><div class="card"><div class="lbl">Patike (pari)</div><div class="kpi">' + c.shoe_pairs + '</div><div class="rs-small">prethodno ' + p.shoe_pairs + '</div></div><div class="card"><div class="lbl">Nedolasci</div><div class="kpi">' + (c.no_shows.n || 0) + '</div><div class="rs-small">prethodno ' + (p.no_shows.n || 0) + '</div></div></div>' +
      '<div class="grid2"><div class="card"><h3>Prihod po kategoriji (gotovina)</h3>' + Object.entries(c.cash.by_category).map(([k, a]) => '<div class="sum"><span>' + esc(cats[k] || { shoes: 'Patike', monthly: 'Mjesečne', debt: 'Dugovi' }[k] || k) + '</span><span>' + km(a) + '</span></div>').join('') + '</div><div class="card"><h3>Staza-sati po kategoriji</h3>' + Object.entries(c.lane_hours).map(([k, a]) => '<div class="sum"><span>' + esc(cats[k] || k) + '</span><span>' + a + '</span></div>').join('') + '<p class="rs-small rs-muted">Kapacitet ' + c.capacity_lane_hours + ' staza-sati (10–23 h).</p></div>' +
      '<div class="card"><h3>Klubovi (vrijednost, nije gotovina)</h3>' + c.clubs.map((k) => '<div class="sum"><span>' + esc(k.name) + ': ' + k.trainings + ' treninga, ' + k.matches + ' utakmica</span><span>' + km(k.value_cents) + '<br><span class="rs-small">obračunato ' + km(k.billed_cents) + '</span></span></div>').join('') + '</div><div class="card"><h3>Mjesečne</h3><div class="sum"><span>Nove/produžene u periodu</span><span>' + (c.passes.total || 0) + '</span></div><div class="sum"><span>od toga VIP</span><span>' + (c.passes.vip || 0) + '</span></div><div class="sum"><span>Aktivne na kraju perioda</span><span>' + c.passes.active_at_end + '</span></div><div class="sum"><span>Otkazivanja (pravovremena / kasna / Arena)</span><span>' + (c.cancellations.timely || 0) + ' / ' + (c.cancellations.late || 0) + ' / ' + (c.cancellations.arena || 0) + '</span></div></div></div>';
    $('#sf', v).onchange = (e) => { S.statFrom = e.target.value; ADMIN.statistika(v); };
    $('#stt', v).onchange = (e) => { S.statTo = e.target.value; ADMIN.statistika(v); };
  },

  async cijene(v) {
    const p = await api('GET', 'prices');
    const c = p.current, f = (x) => (x / 100).toFixed(2).replace('.', ',');
    v.innerHTML = '<h2>Cjenovnik</h2><div class="card"><div class="sum"><span>Rekreacija / rođendan: po započetih 30 min po stazi</span><span>' + km(c.recreation_30_cents) + '</span></div><div class="sum"><span>Minimum po stazi</span><span>' + km(c.recreation_min_cents) + '</span></div><div class="sum"><span>Patike (par)</span><span>' + km(c.shoes_cents) + '</span></div><div class="sum"><span>Trening (staza × sat)</span><span>' + km(c.training_lane_hour_cents) + '</span></div><div class="sum"><span>Mjesečna / VIP</span><span>' + km(c.monthly_cents) + '</span></div><div class="sum"><span>Dio termina nepokriven mjesečnom/bonusom: po započetih 30 min <span class="tag warn">otvorena odluka</span></span><span>' + km(c.monthly_remainder_30_cents) + (c.monthly_remainder_min_cents ? ', najmanje ' + km(c.monthly_remainder_min_cents) : ', bez minimuma') + '</span></div><p class="rs-small rs-muted">Primjeri (trenutno pravilo): 2 h termin, 1,5 h iz mjesečne → nepokrivenih 30 min = ' + km(c.monthly_remainder_30_cents) + '. 1,5 h termin, 30 min iz mjesečne → nepokrivenih 60 min = ' + km(2 * c.monthly_remainder_30_cents) + '. Staza bez ikakvog pokrića = obična rekreacija (najmanje ' + km(c.recreation_min_cents) + ').</p><p class="rs-small rs-muted">Verzija #' + c.id + '. Svaka rezervacija pamti verziju cijene — promjena ne mijenja stare stavke.</p></div>' +
      (can('prices.manage') ? '<div class="card"><h3>Nova verzija cijena</h3><div class="row"><div class="field"><label>30 min po stazi</label><input name="r30" value="' + f(c.recreation_30_cents) + '"></div><div class="field"><label>Minimum</label><input name="rmin" value="' + f(c.recreation_min_cents) + '"></div><div class="field"><label>Patike</label><input name="sh" value="' + f(c.shoes_cents) + '"></div><div class="field"><label>Trening</label><input name="tr" value="' + f(c.training_lane_hour_cents) + '"></div><div class="field"><label>Mjesečna</label><input name="mo" value="' + f(c.monthly_cents) + '"></div><div class="field"><label>Nepokriveni dio: po 30 min</label><input name="rem" value="' + f(c.monthly_remainder_30_cents) + '"></div><div class="field"><label>Nepokriveni dio: minimum (0 = bez)</label><input name="remmin" value="' + f(c.monthly_remainder_min_cents) + '"></div></div><div class="field"><label>Napomena</label><input name="note"></div><div id="e"></div><button class="btn primary" id="ok">Sačuvaj novu verziju</button></div>' : '');
    if ($('#ok', v)) $('#ok', v).onclick = async () => { try { await api('POST', 'prices', { recreation_30: val(v, 'r30'), recreation_min: val(v, 'rmin'), shoes: val(v, 'sh'), training_lane_hour: val(v, 'tr'), monthly: val(v, 'mo'), monthly_remainder_30: val(v, 'rem'), monthly_remainder_min: val(v, 'remmin'), note: val(v, 'note') }); toast('Nova verzija cijena'); ADMIN.cijene(v); } catch (e) { errBox($('#e', v), e); } };
  },

  async ovlastenja(v) {
    const [list, P] = await Promise.all([api('GET', 'staff-accounts'), api('GET', 'perms')]);
    v.innerHTML = '<h2>Nalozi osoblja i ovlaštenja</h2><p class="rs-small rs-muted">Historija bilježi nalog koji je radnju napravio. Zajednički nalog „Šank Arena“ se u historiji vodi pod tim imenom.</p>' + list.map((u) => '<div class="card"><b>' + esc(u.first_name + ' ' + u.last_name) + '</b> · ' + esc(u.username || '') + ' · ' + ({ owner: 'vlasnik', manager: 'menadžer', staff: 'osoblje' }[u.role]) +
      (u.role === 'owner' ? '<p class="rs-small rs-muted">Vlasnik ima sva ovlaštenja.</p>' : '<div class="grid2" style="margin-top:8px">' + Object.entries(P.all).map(([k, l]) => '<label class="rs-small"><input type="checkbox" data-u="' + u.id + '" value="' + k + '"' + (u.perms.includes(k) ? ' checked' : '') + '> ' + esc(l) + '</label>').join('') + '</div><div class="btns" style="margin-top:8px"><button class="btn sm primary" data-save="' + u.id + '">Sačuvaj ovlaštenja</button><button class="btn sm" data-pw="' + u.id + '">Nova lozinka</button></div>') + '</div>').join('') +
      '<div class="card"><h3>Novi nalog osoblja</h3><div class="row"><div class="field"><label>Ime (npr. Šank Arena)</label><input name="first_name"></div><div class="field"><label>Prezime</label><input name="last_name"></div><div class="field"><label>Korisničko ime</label><input name="username" placeholder="sank"></div><div class="field"><label>Uloga</label><select name="role"><option value="staff">Osoblje (šank)</option><option value="manager">Menadžer</option></select></div><div class="field"><label>Lozinka (min. 8)</label><input type="password" name="password" autocomplete="new-password"></div></div><div id="e"></div><button class="btn primary" id="add">Napravi nalog</button></div>';
    $$('[data-save]', v).forEach((b) => (b.onclick = async () => { const id = b.dataset.save; try { await api('POST', 'perms/' + id, { perms: $$('input[data-u="' + id + '"]:checked', v).map((c) => c.value) }); toast('Ovlaštenja sačuvana'); } catch (e) { toast(e.message); } }));
    $$('[data-pw]', v).forEach((b) => (b.onclick = () => { const u = list.find((x) => +x.id === +b.dataset.pw); modal('<h3>Nova lozinka — ' + esc(u.first_name) + '</h3><div class="field"><label>Lozinka</label><input type="password" name="p" autocomplete="new-password"></div><div id="e"></div><button class="btn primary" id="ok">Sačuvaj</button>', (box, close) => { $('#ok', box).onclick = async () => { try { await api('POST', 'staff-accounts', { id: u.id, first_name: u.first_name, last_name: u.last_name, username: u.username, role: u.role, password: val(box, 'p') }); close(); toast('Lozinka promijenjena'); } catch (e) { errBox($('#e', box), e); } }; }); }));
    $('#add', v).onclick = async () => { try { await api('POST', 'staff-accounts', { first_name: val(v, 'first_name'), last_name: val(v, 'last_name'), username: val(v, 'username'), role: val(v, 'role'), password: val(v, 'password') }); ADMIN.ovlastenja(v); } catch (e) { errBox($('#e', v), e); } };
  },

  async obavijesti(v) {
    const [r, out] = await Promise.all([api('GET', 'recipients'), api('GET', 'outbox')]);
    const stl = { pending: ['warn', 'čeka slanje'], sending: ['warn', 'šalje se'], sent: ['ok', 'poslano'], failed: ['bad', 'neuspjelo'], not_configured: ['bad', 'NIJE poslano — kanal nije povezan'] };
    v.innerHTML = '<div class="note">Kanali (SMS / Viber / WhatsApp / email) još se biraju. Dok kanal nije povezan, poruke se pripremaju ovdje i <b>nisu poslane</b>. Povezani kanali: ' + (r.channels.length ? r.channels.join(', ') : 'nijedan') + '.</div>' +
      '<h2>Primaoci obavijesti</h2>' + r.list.map((x) => { const t = JSON.parse(x.types || '[]'); return '<div class="card"><b>' + esc(x.name) + '</b> · ' + esc(x.channel) + ' · ' + esc(x.phone || x.email || '') + (x.active ? '' : ' <span class="tag">neaktivan</span>') + '<div class="rs-small">' + t.map((k) => esc(r.types[k] || k)).join(', ') + '</div><button class="btn sm" data-ed="' + x.id + '">Uredi</button></div>'; }).join('') + '<button class="btn" data-ed="0">+ Novi primalac</button>' +
      '<h2>Red poruka</h2><button class="btn sm" id="run">Obradi red sada</button><div class="tablewrap"><table class="t"><tr><th>Vrijeme (UTC)</th><th>Tip</th><th>Kome</th><th>Poruka</th><th>Status</th><th></th></tr>' + out.map((m) => '<tr><td class="rs-small">' + esc(m.created_utc) + '</td><td class="rs-small">' + esc(m.type) + '</td><td class="rs-small">' + esc(m.channel) + ': ' + esc(m.to_addr) + '</td><td class="rs-small">' + esc(m.type === 'verify_code' ? m.body : m.body.slice(0, 160)) + (+m.has_attachment ? ' 📎' : '') + '</td><td><span class="tag ' + stl[m.status][0] + '">' + stl[m.status][1] + '</span>' + (m.attempts ? '<div class="rs-small">pokušaja: ' + m.attempts + '</div>' : '') + (m.last_error && m.status !== 'not_configured' ? '<div class="rs-small rs-muted">' + esc(m.last_error) + '</div>' : '') + '</td><td>' + (['failed', 'not_configured'].includes(m.status) ? '<button class="btn sm" data-rq="' + m.id + '">Ponovo</button>' : '') + '</td></tr>').join('') + '</table></div>';
    $('#run', v).onclick = async () => { const s = await api('POST', 'outbox/run', {}); toast('Poslano ' + s.sent + ', neuspjelo ' + s.failed + ', bez kanala ' + s.not_configured); ADMIN.obavijesti(v); };
    $$('[data-rq]', v).forEach((b) => (b.onclick = async () => { await api('POST', 'outbox/' + b.dataset.rq + '/requeue', {}); ADMIN.obavijesti(v); }));
    $$('[data-ed]', v).forEach((b) => (b.onclick = () => {
      const x = r.list.find((y) => +y.id === +b.dataset.ed) || { types: '[]', channel: 'sms', active: 1 };
      const t = JSON.parse(x.types || '[]');
      modal('<h3>Primalac</h3><div class="row"><div class="field"><label>Ime</label><input name="name" value="' + esc(x.name || '') + '"></div><div class="field"><label>Kanal</label><select name="channel">' + ['sms', 'viber', 'whatsapp', 'email'].map((c) => '<option' + (c === x.channel ? ' selected' : '') + '>' + c + '</option>').join('') + '</select></div></div><div class="row"><div class="field"><label>Telefon</label><input name="phone" value="' + esc(x.phone || '') + '"></div><div class="field"><label>Email</label><input name="email" value="' + esc(x.email || '') + '"></div></div>' +
        '<div class="field"><label>Prima obavijesti</label>' + Object.entries(r.types).map(([k, l]) => '<label class="rs-small" style="display:block"><input type="checkbox" name="t" value="' + k + '"' + (t.includes(k) ? ' checked' : '') + '> ' + esc(l) + '</label>').join('') + '</div><label class="rs-small"><input type="checkbox" name="inactive"' + (x.active ? '' : ' checked') + '> neaktivan</label><div id="e"></div><button class="btn primary" id="ok">Sačuvaj</button>', (box, close) => {
        $('#ok', box).onclick = async () => { try { await api('POST', 'recipients', { id: x.id || null, name: val(box, 'name'), channel: val(box, 'channel'), phone: val(box, 'phone'), email: val(box, 'email'), types: $$('[name=t]:checked', box).map((c) => c.value), inactive: val(box, 'inactive') }); close(); ADMIN.obavijesti(v); } catch (e) { errBox($('#e', box), e); } };
      });
    }));
  },

  async historija(v) {
    const list = await api('GET', 'audit');
    v.innerHTML = '<h2>Historija promjena (zadnjih 300)</h2><div class="tablewrap"><table class="t"><tr><th>UTC</th><th>Ko</th><th>Radnja</th><th>Objekat</th><th>Razlog</th></tr>' + list.map((a) => '<tr><td class="rs-small">' + esc(a.at_utc) + '</td><td class="rs-small">' + esc(a.actor_label) + '</td><td class="rs-small">' + esc(a.action) + '</td><td class="rs-small">' + esc(a.entity) + ' ' + esc(a.entity_id || '') + '</td><td class="rs-small">' + esc(a.reason || '') + '</td></tr>').join('') + '</table></div>';
  },
};

async function seriesDecide(id, after) {
  const r = await api('GET', 'series/' + id + '/check');
  const s = r.series, c = r.conflicts;
  modal('<h3>Stalni termin — ' + esc(s.club_name) + '</h3><p>' + WD[s.weekday] + ' ' + s.start_time + ' · ' + hrs(s.minutes) + ' · ' + s.lane_count + ' staza · od ' + hDate(s.valid_from) + (s.valid_to ? ' do ' + hDate(s.valid_to) : ' · dok se ne ukine') + '</p>' +
    (c.rule.length ? '<div class="note bad">' + c.rule.map((x) => esc(x.text)).join('<br>') + '</div>' : '') +
    (c.reassigned && c.reassigned.length ? '<div class="note ok">Na ovim datumima željena staza je zauzeta — sistem automatski dodjeljuje drugu slobodnu:</div><ul class="list-plain rs-small">' + c.reassigned.map((x) => '<li>' + hDate(x.date) + ': staza ' + x.preferred.join(', ') + ' → <b>' + x.assigned.join(', ') + '</b></li>').join('') + '</ul>' : '') +
    (c.dates.length ? '<div class="note">Datumi s konfliktom (nijedna druga staza nije slobodna). Postojeće rezervacije se nikad ne prepisuju — možete prihvatiti seriju uz preskakanje ovih datuma:</div><ul class="list-plain">' + c.dates.map((d) => '<li><label><input type="checkbox" name="skip" value="' + d.date + '" checked> ' + hDate(d.date) + '</label> <span class="rs-small rs-muted">' + esc(d.conflicts.join(', ')) + '</span></li>').join('') + '</ul>' : '<div class="note ok">Nema konflikata s postojećim rezervacijama.</div>') +
    '<div id="e"></div><div class="btns"><button class="btn primary" id="ap"' + (c.rule.length ? ' disabled' : '') + '>Odobri' + (c.dates.length ? ' uz preskakanje' : '') + '</button><button class="btn danger" id="rj">Odbij</button></div>', (box, close) => {
    $('#ap', box).onclick = async () => { try { await api('POST', 'series/' + id + '/approve', { skip_dates: $$('[name=skip]:checked', box).map((x) => x.value) }); close(); toast('Stalni termin odobren'); after && after(); } catch (e) { errBox($('#e', box), e); } };
    $('#rj', box).onclick = async () => { const why = (await askText('Razlog (opciono):')) ?? ''; try { await api('POST', 'series/' + id + '/reject', { reason: why }); close(); after && after(); } catch (e) { errBox($('#e', box), e); } };
  });
}

async function userCard(id) {
  const o = await api('GET', 'users/' + id);
  const u = o.user, m = o.monthly, pass = m.pass;
  const clubs = await api('GET', 'clubs').catch(() => []);
  let h = '<h3>' + esc(u.first_name + ' ' + u.last_name) + '</h3><div class="kv"><div>Telefon</div><div>' + esc(u.phone_pretty) + (u.verified ? ' <span class="tag ok">potvrđen</span>' : ' <span class="tag">nepotvrđen</span>') + '</div><div>Email</div><div>' + esc(u.email || '') + '</div><div>Nalog</div><div>' + (u.has_account ? 'registrovan' : 'profil bez naloga') + (u.blocked ? ' <span class="tag bad">blokiran</span>' : '') + '</div>' +
    '<div>Klub</div><div>' + (can('clubs.manage') ? '<select id="uclub"><option value="">— nije član —</option>' + clubs.map((c) => '<option value="' + c.id + '"' + (+c.id === +u.club_id ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select>' : esc(clubs.find((c) => +c.id === +u.club_id)?.name || '—')) + '</div>' +
    '<div>Mjesečna</div><div>' + (pass ? (pass.kind === 'vip' ? 'VIP ' : '') + 'do ' + hDate(addDays(pass.end_date, -1)) + ' · ' + hrs(pass.weekly_minutes) + '/sed · ostalo ove sedmice ' + hrs(m.regular_left) : 'nema aktivne') + (m.pending_request ? ' <span class="tag warn">zahtjev</span>' : '') + '</div><div>Bonus</div><div>' + hrs(m.bonus_balance) + '</div></div>';
  for (const w of o.warnings) h += '<div class="note">' + esc(w.text) + '</div>';
  h += '<div class="btns">' + (can('monthly.manage') ? '<button class="btn accent" id="act">Primljena gotovina — aktiviraj mjesečnu</button>' : '') + (can('bonus.manage') ? '<button class="btn" id="bon">Bonus sati</button>' : '') + (can('users.block') ? (u.blocked ? '<button class="btn" id="unb">Odblokiraj</button>' : '<button class="btn danger" id="blk">Blokiraj</button>') : '') + (can('users.manage') ? '<button class="btn" id="edu">Uredi podatke</button>' : '') + '</div>';
  h += '<h3>Dugovi</h3>' + (o.debts.length ? '<ul class="list-plain">' + o.debts.map((d) => '<li>' + esc(d.reason) + ' · ' + km(d.amount_cents) + ' · <span class="tag ' + (d.status === 'open' ? 'bad' : 'ok') + '">' + ({ open: 'otvoren', paid: 'naplaćen', forgiven: 'oprošten' }[d.status]) + '</span>' + (d.status === 'open' ? (can('cash.collect') ? ' <button class="btn sm" data-pay="' + d.id + '">Naplati</button>' : '') + (can('debt.forgive') ? ' <button class="btn sm" data-fg="' + d.id + '">Oprosti</button>' : '') : '') + '</li>').join('') + '</ul>' : '<p class="rs-muted">Nema.</p>');
  h += '<h3>Rezervacije</h3>' + resTable(o.upcoming.concat(o.past).slice(0, 30), false) + '<div id="pe"></div>';
  panel(h, (p) => {
    const re = () => userCard(id);
    if ($('#uclub', p)) $('#uclub', p).onchange = async (e) => { try { await api('POST', 'users/' + id + '/club', { club_id: e.target.value || null }); toast('Članstvo sačuvano'); } catch (er) { errBox($('#pe', p), er); } };
    if ($('#act', p)) $('#act', p).onclick = async () => { const pv = await api('GET', 'users/' + id + '/monthly-preview'); modal('<h3>Aktivacija mjesečne (' + km(pv.amount_cents) + ' gotovina)</h3><div class="note' + (pv.current ? ' bad' : ' ok') + '">Novi period: <b>' + hDate(pv.start) + ' – ' + hDate(pv.last_day) + '</b> (počinje danom uplate).' + (pv.current ? '<br><b>Upozorenje:</b> postojeća mjesečna važi do ' + hDate(pv.current.last_day) + ' — preostalih <b>' + pv.current.lost_days + ' dana NE prelazi</b> u novi period.' : '') + '</div>' + (pv.current ? '<label class="rs-small"><input type="checkbox" name="early"> Razumijem — preostali dani prethodnog perioda propadaju</label>' : '') + '<div class="field"><label>Vrsta</label><select name="kind"><option value="standard">Standardna — 2 h sedmično</option>' + (can('monthly.vip') ? '<option value="vip">VIP — sati po dogovoru</option>' : '') + '</select></div><div class="field" id="vh" hidden><label>VIP sedmični sati (min)</label><input type="number" step="15" name="wm" value="180"></div><div id="e"></div><button class="btn accent" id="ok">Gotovina primljena — aktiviraj</button>', (box, close) => {
      $('[name=kind]', box).onchange = () => ($('#vh', box).hidden = val(box, 'kind') !== 'vip');
      $('#ok', box).onclick = async () => { if (pv.current && !val(box, 'early')) { $('#e', box).innerHTML = '<div class="err">Potvrdite da ste upozorili korisnika da preostali dani propadaju.</div>'; return; } try { await api('POST', 'users/' + id + '/monthly', { kind: val(box, 'kind'), weekly_minutes: +val(box, 'wm'), confirm_early: !!val(box, 'early') }); close(); toast('Mjesečna aktivirana do ' + hDate(pv.last_day)); re(); } catch (er) { errBox($('#e', box), er); } };
    }); };
    if ($('#bon', p)) $('#bon', p).onclick = () => modal('<h3>Bonus sati</h3><div class="field"><label>Minuta (+ dodjela, − oduzimanje; koraci 15)</label><input type="number" step="15" name="min" value="60"></div><div class="field"><label>Razlog *</label><input name="r"></div><div id="e"></div><button class="btn primary" id="ok">Sačuvaj</button>', (box, close) => {
      $('#ok', box).onclick = async () => { try { await api('POST', 'users/' + id + '/bonus', { minutes: +val(box, 'min'), reason: val(box, 'r') }); close(); re(); } catch (er) { errBox($('#e', box), er); } };
    });
    if ($('#blk', p)) $('#blk', p).onclick = async () => { const r = await askText('Razlog blokade:'); if (!r) return; try { await api('POST', 'users/' + id + '/block', { reason: r }); re(); } catch (er) { errBox($('#pe', p), er); } };
    if ($('#unb', p)) $('#unb', p).onclick = async () => { await api('POST', 'users/' + id + '/unblock', {}); re(); };
    if ($('#edu', p)) $('#edu', p).onclick = () => modal('<h3>Podaci</h3><div class="row"><div class="field"><label>Ime</label><input name="f" value="' + esc(u.first_name) + '"></div><div class="field"><label>Prezime</label><input name="l" value="' + esc(u.last_name) + '"></div></div><div class="field"><label>Telefon</label><input name="ph" value="' + esc(u.phone_pretty) + '"></div><div class="field"><label>Email</label><input name="em" value="' + esc(u.email || '') + '"></div><div id="e"></div><button class="btn primary" id="ok">Sačuvaj</button>', (box, close) => {
      $('#ok', box).onclick = async () => { try { await api('POST', 'users/' + id + '/update', { first_name: val(box, 'f'), last_name: val(box, 'l'), phone: val(box, 'ph'), email: val(box, 'em') }); close(); re(); } catch (er) { errBox($('#e', box), er); } };
    });
    $$('[data-pay]', p).forEach((b) => (b.onclick = async () => { if (!await ask('Naplatiti dug gotovinom?')) return; try { await api('POST', 'debts/' + b.dataset.pay + '/pay', {}); re(); } catch (er) { errBox($('#pe', p), er); } }));
    $$('[data-fg]', p).forEach((b) => (b.onclick = async () => { const r = await askText('Razlog oprosta:'); if (!r) return; try { await api('POST', 'debts/' + b.dataset.fg + '/forgive', { reason: r }); re(); } catch (er) { errBox($('#pe', p), er); } }));
    $$('[data-res]', p).forEach((b) => (b.onclick = () => openReservation(+b.dataset.res)));
  });
}

// ============================================================ start
// labele polja → pristupačna imena (čitači ekrana)
let fid = 0;
new MutationObserver(() => $$('.field').forEach((f) => { const l = $(':scope > label', f), i = $(':scope > input, :scope > select, :scope > textarea', f); if (l && i && !i.id) { i.id = 'f' + ++fid; l.htmlFor = i.id; } })).observe(document.body, { childList: true, subtree: true });
const setTop = () => document.documentElement.style.setProperty('--top-h', $('.rs-top').offsetHeight + 'px');
addEventListener('resize', setTop);

(async function main() {
  setTop();
  const page = document.body.dataset.page;
  $$('.rs-nav a').forEach((a) => a.classList.toggle('active', a.dataset.nav === page));
  try { const me = await api('GET', 'me'); S.registration = me.registration !== false; S.me = me.user; S.perms = me.perms || []; S.staff = !!me.staff; S.club = me.club; } catch { /* javno */ }
  if (S.me) { $('#rs-nav-account').textContent = 'Moj nalog'; if (S.staff) $('#rs-nav-admin').hidden = false; }
  const root = $('#app');
  try {
    if (page === 'public') { root.innerHTML = '<h1>Kalendar staza</h1><p class="rs-muted rs-small">' + (S.me ? 'Kliknite na slobodan termin za rezervaciju. Online rezervacije: 10:00–23:00, početak na puni sat.' : 'Prikaz zauzetosti svih šest staza. Za rezervaciju se <a href="/nalog/">prijavite</a>.') + '</p><div id="calroot"></div>'; await calendarView($('#calroot'), S.me ? 'user' : 'public'); }
    else if (page === 'account') await accountPage(root);
    else if (page === 'admin') await adminPage(root);
  } catch (e) { errBox(root, e); }
})();
