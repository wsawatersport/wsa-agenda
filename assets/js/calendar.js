/* WSA Agenda – calendar.js (plain JS, no jQuery) */
/* global wsaAgenda */
(function () {
'use strict';

const cfg = wsaAgenda;
const s   = cfg.strings;
const DOW      = [s.mon, s.tue, s.wed, s.thu, s.fri, s.sat, s.sun]; // Mon-first (for grid headers)
const DOW_SHORT = ['zo','ma','di','wo','do','vr','za'];               // Sun=0 (JS getDay())
const MON_SHORT = ['jan','feb','mrt','apr','mei','jun','jul','aug','sep','okt','nov','dec'];

/* ── State ────────────────────────────────────────────────────────── */
const state = {
  view: 'month',   // 'month' | 'year' | 'list'
  date: new Date(),
  events: [],
  publicBlocks: [],
  categories: cfg.categories || [],
  showPublic: true,
  loading: false,
};

/* ── Utilities ────────────────────────────────────────────────────── */
const fmt = {
  ymd:      d => `${d.getFullYear()}-${p2(d.getMonth()+1)}-${p2(d.getDate())}`,
  nice:     d => `${p2(d.getDate())} ${s.months[d.getMonth()]} ${d.getFullYear()} ${p2(d.getHours())}:${p2(d.getMinutes())}`,
  niceDate: d => `${p2(d.getDate())} ${s.months[d.getMonth()]} ${d.getFullYear()}`,
  time:     d => `${p2(d.getHours())}:${p2(d.getMinutes())}`,
};
function p2(n)        { return String(n).padStart(2,'0'); }
function parseDate(str){ if(!str)return null; return new Date(str.length===10?str+'T00:00:00':str); }
function sameDay(a,b) { return a.getFullYear()===b.getFullYear()&&a.getMonth()===b.getMonth()&&a.getDate()===b.getDate(); }
function addDays(d,n) { const r=new Date(d); r.setDate(r.getDate()+n); return r; }
function el(tag,cls,txt){ const e=document.createElement(tag); if(cls)e.className=cls; if(txt!==undefined)e.textContent=txt; return e; }
/** Mark an element so GTranslate / Google Translate never touches its text. */
function notr(e){ e.classList.add('notranslate'); e.setAttribute('translate','no'); return e; }
function fmtBytes(b)  { if(b<1024)return b+' B'; if(b<1048576)return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(1)+' MB'; }
function isMobile()   { return window.matchMedia('(max-width:600px)').matches; }

/* ── API ──────────────────────────────────────────────────────────── */
const api = {
  headers: { 'Content-Type':'application/json', 'X-WP-Nonce': cfg.nonce },
  async get(path){
    const r = await fetch(cfg.apiBase+path, {headers:{'X-WP-Nonce':cfg.nonce}});
    if(!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
  },
  async post(path,body){ const r=await fetch(cfg.apiBase+path,{method:'POST',headers:api.headers,body:JSON.stringify(body)}); return r.json(); },
  async patch(path,body){ const r=await fetch(cfg.apiBase+path,{method:'PATCH',headers:api.headers,body:JSON.stringify(body)}); return r.json(); },
  async del(path){ const r=await fetch(cfg.apiBase+path,{method:'DELETE',headers:api.headers}); return r.json(); },
};

/* ── Date range for current view ──────────────────────────────────── */
function rangeForState(){
  const d = state.date;
  if(state.view==='year' || state.view==='list'){
    return { start:`${d.getFullYear()}-01-01`, end:`${d.getFullYear()}-12-31` };
  }
  if(state.view==='month'){
    const first     = new Date(d.getFullYear(), d.getMonth(), 1);
    const startGrid = addDays(first, -((first.getDay()+6)%7));
    return { start:fmt.ymd(startGrid), end:fmt.ymd(addDays(startGrid,41)) };
  }
  // list / year — full calendar year
  return { start:`${d.getFullYear()}-01-01`, end:`${d.getFullYear()}-12-31` };
}

/* ── Load data from REST ──────────────────────────────────────────── */
async function loadData(){
  state.loading = true; render();
  const { start, end } = rangeForState();

  // List view never shows public blocks; skip that fetch.
  const fetchPub = state.showPublic && state.view !== 'list';

  try {
    const [evts, pubs] = await Promise.all([
      api.get(`/events?start=${start}&end=${end}`),
      fetchPub ? api.get(`/public-blocks?start=${start}&end=${end}`) : Promise.resolve([]),
    ]);
    state.events       = Array.isArray(evts) ? evts : [];
    state.publicBlocks = Array.isArray(pubs) ? pubs : [];
  } catch(e) {
    // Keep existing data on network failure; don't blank the calendar.
    console.warn('[WSA] Data fetch failed:', e.message);
  }
  state.loading = false; render();
}

/* ── Root render ──────────────────────────────────────────────────── */
function render(){
  const app = document.getElementById('wsa-calendar-app');
  if(!app) return;
  app.innerHTML = '';
  app.appendChild(buildToolbar());
  if(state.loading){
    const l = el('div','',s.loading);
    l.style.cssText = 'padding:40px;text-align:center';
    app.appendChild(l);
    return;
  }
  if     (state.view==='month') app.appendChild(buildMonth());
  else if(state.view==='year')  app.appendChild(buildYear());
  else                          app.appendChild(buildList());
}

/* ── Toolbar ──────────────────────────────────────────────────────── */
function buildToolbar(){
  const bar = el('div','wsa-toolbar');

  // View switcher — wrapped in .wsa-view-tabs so CSS can make it a full-width
  // tab bar on mobile without touching the navigation row.
  const views = [
    ['list',  s.listView],
    ['month', s.monthView],
    ['year',  s.yearView],
  ];
  const tabs = el('div','wsa-view-tabs');
  views.forEach(([v, label])=>{
    const b = el('button', 'wsa-btn'+(state.view===v?' active':''), label);
    b.addEventListener('click', ()=>{ state.view=v; loadData(); });
    tabs.appendChild(b);
  });
  bar.appendChild(tabs);

  // Navigation
  const nav  = el('div','wsa-toolbar-nav');
  const prev = el('button','wsa-btn wsa-btn-icon','‹');
  const head = el('span','wsa-heading', headingText());
  const next = el('button','wsa-btn wsa-btn-icon','›');
  const tod  = el('button','wsa-btn', s.today);
  prev.addEventListener('click', ()=>navigate(-1));
  next.addEventListener('click', ()=>navigate( 1));
  tod.addEventListener('click',  ()=>{ state.date=new Date(); loadData(); });
  nav.append(prev, head, next, tod);
  bar.appendChild(nav);

  // Right: public-blocks toggle + login button (for non-board users on desktop)
  {
    const right = el('div','wsa-toolbar-right');

    // "Toon publieke agenda" toggle — only meaningful in views that show public blocks.
    if(state.view !== 'list'){
      const lbl = el('label','wsa-toggle-label');
      const chk = document.createElement('input');
      chk.type = 'checkbox'; chk.checked = state.showPublic;
      chk.addEventListener('change', ()=>{ state.showPublic=chk.checked; loadData(); });
      lbl.appendChild(chk);
      lbl.appendChild(document.createTextNode(s.showPublic));
      right.appendChild(lbl);
    }

    // Login is triggered contextually from inside the event modal — no toolbar button needed.

    bar.appendChild(right);
  }

  return bar;
}

function headingText(){
  const d = state.date;
  if(state.view==='year' || state.view==='list') return String(d.getFullYear());
  return `${s.months[d.getMonth()]} ${d.getFullYear()}`;
}

function navigate(dir){
  const d = state.date;
  if(state.view==='year' || state.view==='list')
    state.date = new Date(d.getFullYear()+dir, d.getMonth(), 1);
  else
    state.date = new Date(d.getFullYear(), d.getMonth()+dir, 1);
  loadData();
}

/* ── Month view ───────────────────────────────────────────────────── */
function buildMonth(){
  const grid  = el('div','wsa-month-grid');
  DOW.forEach(d => grid.appendChild(notr(el('div','wsa-month-dow',d))));

  const first     = new Date(state.date.getFullYear(), state.date.getMonth(), 1);
  const startGrid = addDays(first, -((first.getDay()+6)%7));
  const today     = new Date(); today.setHours(0,0,0,0);
  const curM      = state.date.getMonth();

  for(let i=0; i<42; i++){
    const day  = addDays(startGrid, i);
    const dayS = fmt.ymd(day);
    const cell = el('div','wsa-day');
    if(day.getMonth() !== curM) cell.classList.add('other-month');
    if(sameDay(day, today))    cell.classList.add('today');

    // 1. Public block handling — mobile and desktop render differently
    const pbs = state.showPublic
      ? state.publicBlocks.filter(b => b.start_date <= dayS && b.end_date >= dayS)
      : [];
    if(pbs.length){
      if(isMobile()){
        // Mobile: subtle tint on the cell itself — no overlay element, no text label.
        cell.classList.add('wsa-day-pub-mobile');
      } else {
        // Desktop: absolute colour band + text label below day number.
        cell.appendChild(el('div','wsa-public-block'));
      }
    }

    // 2. Day number (in normal flow; sits on top of any tint via z-index)
    cell.appendChild(notr(el('div','wsa-day-num', String(day.getDate()))));

    // 3. Public block text labels — desktop only
    if(!isMobile()){
      pbs.slice(0,2).forEach(b => cell.appendChild(el('div','wsa-public-label', b.label)));
    }

    // 4. Event chips — mobile max 2 chips + "+N meer"; desktop max 3 chips + "+N"
    const dayEvts = state.events.filter(e => {
      const es = parseDate(e.start), ee = parseDate(e.end || e.start);
      return es && dayS >= fmt.ymd(es) && dayS <= fmt.ymd(ee || es);
    });
    if(isMobile()){
      // Mobile: coloured chips with truncated name; tap opens detail modal directly.
      // If more than 2 events, show "+N meer" which opens the day bottom-sheet.
      const limit = 2;
      dayEvts.slice(0, limit).forEach(ev => {
        const chip = el('div','wsa-chip', ev.title);
        chip.style.background = ev.category?.color || '#888780';
        chip.setAttribute('title', ev.title);
        chip.addEventListener('click', e => { e.stopPropagation(); openViewModal(ev.id); });
        cell.appendChild(chip);
      });
      if(dayEvts.length > limit){
        const more = el('div','wsa-more', `+${dayEvts.length - limit} meer`);
        more.addEventListener('click', e => { e.stopPropagation(); openDaySheet(day, dayEvts); });
        cell.appendChild(more);
      }
    } else {
      // Desktop: full event chips, max 3; overflow opens day sheet.
      dayEvts.slice(0,3).forEach(ev => {
        const chip = el('div','wsa-chip', ev.title);
        chip.style.background = ev.category?.color || '#888780';
        chip.setAttribute('title', ev.title);
        chip.addEventListener('click', e => { e.stopPropagation(); openViewModal(ev.id); });
        cell.appendChild(chip);
      });
      if(dayEvts.length > 3){
        const more = el('div','wsa-more', `+${dayEvts.length-3}`);
        more.addEventListener('click', e => { e.stopPropagation(); openDaySheet(day, dayEvts); });
        cell.appendChild(more);
      }
    }

    // Board tap/click on empty cell space → open new-event modal with date pre-filled.
    //
    // Desktop: chips/more call stopPropagation on 'click', so the cell listener
    //          only fires on blank space — a simple guard is enough.
    // Mobile:  'click' can be swallowed by scroll detection; touchend is more
    //          reliable.  We track movement so a scroll doesn't open the modal,
    //          and we explicitly bail out when the touch originated on a chip or
    //          "+N" button (touch events don't bubble stopPropagation from click).
    if(cfg.isBoard){
      cell.classList.add('wsa-day-clickable');

      let _txStart = 0, _tyStart = 0;

      cell.addEventListener('touchstart', e => {
        _txStart = e.touches[0].clientX;
        _tyStart = e.touches[0].clientY;
      }, { passive: true });

      cell.addEventListener('touchend', e => {
        // If the tap was on a chip or overflow button let those handlers run.
        if(e.target.closest('.wsa-chip, .wsa-more')) return;
        // If the finger drifted > 10 px in any direction treat it as a scroll.
        const dx = e.changedTouches[0].clientX - _txStart;
        const dy = e.changedTouches[0].clientY - _tyStart;
        if(Math.abs(dx) > 10 || Math.abs(dy) > 10) return;
        e.preventDefault(); // suppress the subsequent ghost click
        openEditModal(null, dayS);
      });

      // Desktop fallback — chips already call stopPropagation on 'click'.
      cell.addEventListener('click', e => {
        if(e.target.closest('.wsa-chip, .wsa-more')) return;
        openEditModal(null, dayS);
      });
    }

    grid.appendChild(cell);
  }
  return grid;
}

/* ── Day sheet – mobile month view: events for one tapped day ────── */
function openDaySheet(day, evts){
  const wrap     = document.createElement('div');
  const hdr      = el('div','wsa-modal-header');
  const ht       = el('h2','wsa-modal-title', fmt.niceDate(day));
  const closeBtn = el('button','wsa-modal-close','×');
  closeBtn.setAttribute('aria-label', s.close);
  closeBtn.addEventListener('click', closeModal);
  hdr.append(ht, closeBtn);
  wrap.appendChild(hdr);

  const body = el('div','wsa-modal-body');
  if(!evts.length){
    body.appendChild(el('p','', s.noEvents));
  } else {
    evts.forEach(ev => {
      const row   = el('div','wsa-day-sheet-row');
      const dot   = el('span','wsa-day-sheet-dot');
      dot.style.background = ev.category?.color || '#888780';
      const tStr  = (!ev.allday && ev.start && ev.start.includes('T'))
        ? fmt.time(parseDate(ev.start))
        : (ev.allday ? s.allDay : '');
      const time  = el('span','wsa-day-sheet-time', tStr);
      const title = el('span','wsa-day-sheet-title', ev.title);
      row.append(dot, time, title);
      row.addEventListener('click', () => {
        closeModal();
        // Small delay lets the close complete before the detail modal opens.
        setTimeout(() => openViewModal(ev.id), 30);
      });
      body.appendChild(row);
    });
  }
  wrap.appendChild(body);
  openModal(wrap);
}

/* ── Year view ────────────────────────────────────────────────────── */
function buildYear(){
  const grid  = el('div','wsa-year-grid');
  const year  = state.date.getFullYear();
  const today = new Date(); today.setHours(0,0,0,0);

  for(let m=0; m<12; m++){
    const mm    = el('div','wsa-mini-month');
    const title = el('div','wsa-mini-month-title', `${s.months[m]} ${year}`);
    title.addEventListener('click', ()=>{ state.view='month'; state.date=new Date(year,m,1); loadData(); });
    mm.appendChild(title);

    const mg    = el('div','wsa-mini-grid');
    DOW.forEach(d => mg.appendChild(el('div','wsa-mini-dow', d[0])));

    const first = new Date(year, m, 1);
    const start = addDays(first, -((first.getDay()+6)%7));

    for(let i=0; i<35; i++){
      const day  = addDays(start, i);
      const dayS = fmt.ymd(day);
      const cell = el('div','wsa-mini-day', day.getMonth()===m ? String(day.getDate()) : '');
      if(day.getMonth() !== m){ cell.style.opacity='0'; mg.appendChild(cell); continue; }
      if(sameDay(day, today)) cell.classList.add('today-mini');

      const dayEvts = state.events.filter(e => {
        const es=parseDate(e.start), ee=parseDate(e.end||e.start);
        return es && dayS>=fmt.ymd(es) && dayS<=fmt.ymd(ee||es);
      });
      const hasPub = state.showPublic && state.publicBlocks.some(b => b.start_date<=dayS && b.end_date>=dayS);

      if(hasPub && !sameDay(day,today)) cell.classList.add('has-block');
      if(dayEvts.length){
        cell.classList.add('has-event');
        const dots = el('div','wsa-dots');
        dayEvts.slice(0,3).forEach(ev => {
          const dot = el('div','wsa-dot');
          dot.style.background = ev.category?.color || '#888780';
          dots.appendChild(dot);
        });
        cell.appendChild(dots);
        cell.addEventListener('click', ()=>{ state.view='month'; state.date=new Date(year,m,1); loadData(); });
      }
      mg.appendChild(cell);
    }
    mm.appendChild(mg); grid.appendChild(mm);
  }
  return grid;
}

/* ── List view ────────────────────────────────────────────────────── */
function buildList(){
  const wrap  = el('div','wsa-list');
  const today = new Date(); today.setHours(0,0,0,0);

  // Only show events whose start date falls in the displayed year,
  // then sort ascending. (Range query uses overlap, so Dec-previous-year
  // multi-day events could otherwise appear at the top.)
  const year   = state.date.getFullYear();
  const sorted = state.events
    .filter(ev => { const d=parseDate(ev.start); return d && d.getFullYear()===year; })
    .slice()
    .sort((a,b) => { const ad=parseDate(a.start),bd=parseDate(b.start); return (ad||0)-(bd||0); });

  if(!sorted.length){
    wrap.appendChild(el('p','wsa-list-empty', s.noEvents));
    return wrap;
  }

  // Group by month key "YYYY-MM"
  const groups = new Map();
  sorted.forEach(ev => {
    const d = parseDate(ev.start);
    if(!d) return;
    const key = `${d.getFullYear()}-${p2(d.getMonth()+1)}`;
    if(!groups.has(key)) groups.set(key, { month: d.getMonth(), year: d.getFullYear(), events: [] });
    groups.get(key).events.push(ev);
  });

  groups.forEach(group => {
    // Month heading — clickable: switches to month view for that month
    const hdr = el('div','wsa-list-month-hdr');
    const monthTitle = notr(el('button','wsa-list-month-title', `${s.months[group.month]} ${group.year}`));
    monthTitle.addEventListener('click', () => {
      state.view = 'month';
      state.date = new Date(group.year, group.month, 1);
      loadData();
      setTimeout(() => {
        const app = document.getElementById('wsa-calendar-app');
        if(app) app.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 0);
    });
    hdr.appendChild(monthTitle);
    hdr.appendChild(el('hr','wsa-list-divider'));
    wrap.appendChild(hdr);

    // Event rows
    group.events.forEach(ev => {
      const d      = parseDate(ev.start);
      const isPast = d < today;
      const row    = el('div','wsa-list-row' + (isPast ? ' wsa-list-past' : ''));

      // Category colour bar
      const bar = el('span','wsa-list-bar');
      bar.style.background = ev.category?.color || '#888780';
      row.appendChild(bar);

      // Date: DD mmm
      row.appendChild(notr(el('span','wsa-list-date', `${p2(d.getDate())} ${MON_SHORT[d.getMonth()]}`)));

      // Day of week abbreviation (zo/ma/di/…)
      row.appendChild(notr(el('span','wsa-list-dow', DOW_SHORT[d.getDay()])));

      // Time (only if event has a time component)
      const timeStr = !ev.allday && ev.start && ev.start.includes('T') ? fmt.time(d) : '';
      row.appendChild(notr(el('span','wsa-list-time', timeStr)));

      // Title
      row.appendChild(el('span','wsa-list-title', ev.title));

      // Description excerpt (stripped, max 100 chars — comes from PHP excerpt field)
      if(ev.excerpt){
        row.appendChild(el('span','wsa-list-desc', ev.excerpt));
      }

      row.addEventListener('click', () => openViewModal(ev.id));
      wrap.appendChild(row);
    });
  });

  return wrap;
}

/* ── Modal helpers ────────────────────────────────────────────────── */
function getModalRoot(){ return document.getElementById('wsa-modal-root'); }

function openModal(content){
  const root = getModalRoot(); root.innerHTML = '';
  document.getElementById('wsa-calendar-root').style.position = 'relative';

  const backdrop = el('div','wsa-modal-backdrop');
  const modal    = el('div','wsa-modal');
  modal.setAttribute('role','dialog'); modal.setAttribute('aria-modal','true'); modal.tabIndex = -1;
  // On mobile the modal is a bottom sheet — add a visible drag handle at the top.
  if(isMobile()) modal.appendChild(el('div','wsa-sheet-handle'));
  modal.appendChild(content);
  backdrop.appendChild(modal);
  root.appendChild(backdrop);

  modal.focus();

  // Focus trap
  const focusable = () => [...modal.querySelectorAll(
    'button,input,select,textarea,[href],[tabindex]:not([tabindex="-1"])'
  )];
  modal.addEventListener('keydown', e => {
    if(e.key === 'Escape'){ closeModal(); return; }
    if(e.key !== 'Tab') return;
    const els = focusable(); if(!els.length) return;
    const [first, last] = [els[0], els[els.length-1]];
    if(e.shiftKey){ if(document.activeElement===first){ e.preventDefault(); last.focus(); } }
    else          { if(document.activeElement===last) { e.preventDefault(); first.focus(); } }
  });
  backdrop.addEventListener('click', e => { if(e.target===backdrop) closeModal(); });
}
function closeModal(){ const r=getModalRoot(); if(r) r.innerHTML=''; }

/* ── View modal ───────────────────────────────────────────────────── */
async function openViewModal(id){
  let ev;
  try { ev = await api.get(`/events/${id}`); } catch(e){ return; }
  if(ev.code) return;

  const frag = document.createDocumentFragment();

  // Header
  const hdr = el('div','wsa-modal-header');
  const ht  = el('h2','wsa-modal-title', ev.title);
  if(ev.category){
    const b = el('span','wsa-cat-badge', ev.category.name);
    b.style.background = ev.category.color;
    ht.appendChild(b);
  }
  const closeBtn = el('button','wsa-modal-close','×');
  closeBtn.setAttribute('aria-label', s.close);
  closeBtn.addEventListener('click', closeModal);
  hdr.append(ht, closeBtn);
  frag.appendChild(hdr);

  // Body
  const body = el('div','wsa-modal-body');
  const meta = el('div','wsa-modal-meta');
  const es   = parseDate(ev.start), ee = parseDate(ev.end);
  meta.textContent = es
    ? ev.allday
      ? (ee && !sameDay(es,ee) ? `${fmt.niceDate(es)} – ${fmt.niceDate(ee)}` : fmt.niceDate(es))
      : (ee && !sameDay(es,ee) ? `${fmt.nice(es)} – ${fmt.nice(ee)}` : fmt.nice(es))
    : '';
  body.appendChild(meta);

  if(ev.description){
    const desc = el('div','wsa-modal-desc'); desc.innerHTML = ev.description; body.appendChild(desc);
  }

  // Attachments
  if(ev.attachments?.length){
    const sec = el('div','wsa-modal-section');
    sec.appendChild(el('h4','', s.attachments));
    const ul = el('ul','wsa-att-list');
    ev.attachments.forEach(a => {
      const li = el('li','wsa-att-item');
      if(a.thumbnail_url){
        const img = document.createElement('img');
        img.src = a.thumbnail_url; img.className = 'wsa-att-thumb'; img.alt = a.filename;
        li.appendChild(img);
      } else {
        li.appendChild(el('span','wsa-att-icon','📄'));
      }
      const inf = el('div','wsa-att-info');
      const dl  = document.createElement('a');
      dl.href = a.url; dl.textContent = 'Download'; dl.download = a.filename; dl.target = '_blank'; dl.rel = 'noopener';
      inf.append(el('div','wsa-att-name',a.filename), el('div','wsa-att-size',fmtBytes(a.filesize)), dl);
      li.appendChild(inf); ul.appendChild(li);
    });
    sec.appendChild(ul); body.appendChild(sec);
  }

  // RSVP
  if(ev.rsvp_enabled) body.appendChild(buildRsvpSection(ev));
  if(cfg.isBoard && ev.rsvp_enabled) body.appendChild(await buildRsvpListSection(id));

  frag.appendChild(body);

  // Footer: Sluiten (left) | Aanmelden · Bewerken (right)
  const foot    = el('div','wsa-modal-footer');
  const clsBtn  = el('button','wsa-btn', s.close);
  clsBtn.addEventListener('click', closeModal);
  foot.appendChild(clsBtn);

  const actions = el('div','wsa-modal-footer-actions');
  const aanBtn  = el('button','wsa-footer-link', s.aanmelden);
  aanBtn.addEventListener('click', () => openSubscriptionPanel(ev));
  const divider = el('span','wsa-footer-divider','|');
  const edtBtn  = el('button','wsa-footer-link', s.edit);
  edtBtn.addEventListener('click', () => cfg.isBoard ? openEditModal(ev) : openLoginChooser(ev));
  actions.append(aanBtn, divider, edtBtn);
  foot.appendChild(actions);
  frag.appendChild(foot);

  const wrap = document.createElement('div');
  wrap.appendChild(frag);
  openModal(wrap);
}

/* ── Subscription panel (replaces modal content in-place) ─────────── */

/**
 * Replaces the current modal content with the subscription panel.
 * Two sections: ICS invite (email + button inline) and email reminders.
 * A "← Terug naar evenement" back link restores the event detail view.
 */
function openSubscriptionPanel(ev){
  const wrap = document.createElement('div');

  // ── Header: back link left, close button right ──────────────────
  const hdr     = el('div','wsa-modal-header');
  const back    = el('button','wsa-login-back','← Terug naar evenement');
  back.addEventListener('click', () => openViewModal(ev.id));
  const closeBtn = el('button','wsa-modal-close','×');
  closeBtn.setAttribute('aria-label', s.close);
  closeBtn.addEventListener('click', closeModal);
  hdr.append(back, closeBtn);
  wrap.appendChild(hdr);

  // ── Body ────────────────────────────────────────────────────────
  const body = el('div','wsa-modal-body');

  // Panel title
  body.appendChild(el('p','wsa-sub-panel-title', s.subPanelPrefix + ' ' + ev.title));

  // ── Option 1: ICS invite ────────────────────────────────────────
  const icsSection = el('div','wsa-sub-section');
  icsSection.appendChild(el('p','wsa-sub-opt-desc', s.subIcsDesc));

  const icsRow   = el('div','wsa-sub-ics-row');
  const icsEmail = document.createElement('input');
  icsEmail.type = 'email'; icsEmail.placeholder = s.emailLabel;
  icsEmail.className = 'wsa-sub-input';
  const icsBtn   = el('button','wsa-btn wsa-btn-primary', s.icsSend);
  icsRow.append(icsEmail, icsBtn);
  icsSection.appendChild(icsRow);

  const icsNotif = el('div','wsa-sub-notif');
  icsSection.appendChild(icsNotif);

  icsBtn.addEventListener('click', async () => {
    icsNotif.textContent = ''; icsEmail.classList.remove('error');
    if(!icsEmail.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)){
      icsEmail.classList.add('error'); return;
    }
    icsBtn.disabled = true;
    try {
      const res = await api.post(`/events/${ev.id}/ics`, {email: icsEmail.value.trim()});
      icsBtn.disabled = false;
      if(res.sent){
        icsRow.style.display  = 'none';
        icsNotif.textContent  = s.icsSent;
        icsNotif.className    = 'wsa-sub-notif wsa-sub-ok';
      } else {
        icsNotif.textContent = res.message || s.errorSend;
        icsNotif.className   = 'wsa-sub-notif wsa-sub-err';
      }
    } catch(e){
      icsBtn.disabled = false;
      icsNotif.textContent = s.errorSend;
      icsNotif.className   = 'wsa-sub-notif wsa-sub-err';
    }
  });

  body.appendChild(icsSection);

  // Divider between the two options
  const hr = document.createElement('hr'); hr.className = 'wsa-sub-hr';
  body.appendChild(hr);

  // ── Option 2: email reminders ───────────────────────────────────
  const remSection = el('div','wsa-sub-section');
  remSection.appendChild(el('p','wsa-sub-opt-desc', s.subRemDesc));

  const sk = `wsa_sub_${ev.id}`;

  if(sessionStorage.getItem(sk)){
    remSection.appendChild(el('div','wsa-sub-done', s.reminderDone));
  } else {
    const remForm  = el('div','wsa-sub-form');
    const remName  = document.createElement('input');
    remName.type = 'text'; remName.placeholder = s.nameLabel; remName.className = 'wsa-sub-input';
    const remEmail = document.createElement('input');
    remEmail.type = 'email'; remEmail.placeholder = s.emailLabel; remEmail.className = 'wsa-sub-input';
    const remBtn   = el('button','wsa-btn wsa-btn-primary wsa-sub-full', s.reminderSubmit);
    const remNotif = el('div','wsa-sub-notif');
    remForm.append(remName, remEmail, remBtn, remNotif);

    remBtn.addEventListener('click', async () => {
      remNotif.textContent = '';
      remName.classList.remove('error'); remEmail.classList.remove('error');
      let ok = true;
      if(!remName.value.trim())                                { remName.classList.add('error');  ok = false; }
      if(!remEmail.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)){ remEmail.classList.add('error'); ok = false; }
      if(!ok) return;

      remBtn.disabled = true;
      try {
        const res = await api.post(`/events/${ev.id}/subscribe`, {
          name:  remName.value.trim(),
          email: remEmail.value.trim(),
        });
        remBtn.disabled = false;
        if(res.subscribed){
          sessionStorage.setItem(sk,'1');
          remForm.innerHTML = '';
          remForm.appendChild(el('div','wsa-sub-done', s.reminderDone));
          if(res.unsubscribe){
            const a = document.createElement('a');
            a.href = res.unsubscribe; a.textContent = s.unsubscribeLabel;
            a.className = 'wsa-sub-unsub-link'; a.target = '_blank'; a.rel = 'noopener';
            remForm.appendChild(a);
          }
        } else {
          const msg = res.code === 'already_subscribed' ? s.reminderAlready : (res.message || s.errorSend);
          remNotif.textContent = msg;
          remNotif.className   = 'wsa-sub-notif wsa-sub-err';
        }
      } catch(e){
        remBtn.disabled = false;
        remNotif.textContent = s.errorSend;
        remNotif.className   = 'wsa-sub-notif wsa-sub-err';
      }
    });

    remSection.appendChild(remForm);
  }

  body.appendChild(remSection);
  wrap.appendChild(body);
  replaceModalContent(wrap);
}

function buildRsvpSection(ev){
  const sec = el('div','wsa-modal-section');
  sec.appendChild(el('h4','', s.rsvpLabel));
  const sk = `wsa_rsvp_${ev.id}`;
  if(sessionStorage.getItem(sk)){ sec.appendChild(el('div','wsa-rsvp-done', s.rsvpDone)); return sec; }
  const full = ev.rsvp_limit > 0 && ev.rsvp_count >= ev.rsvp_limit;
  if(full){ sec.appendChild(el('div','wsa-rsvp-full', s.rsvpFull)); return sec; }
  if(ev.rsvp_limit > 0)
    sec.appendChild(el('div','wsa-rsvp-count', `${ev.rsvp_count} ${s.spotsOf} ${ev.rsvp_limit} ${s.spotsOccupied}`));

  const form  = el('div','wsa-rsvp-form');
  const name  = document.createElement('input'); name.type='text';  name.placeholder=s.nameLabel;
  const email = document.createElement('input'); email.type='email'; email.placeholder=s.emailLabel;
  const btn   = el('button','wsa-btn wsa-btn-primary', s.rsvpSubmit);
  const notif = el('div','');
  form.append(name, email, btn, notif);

  btn.addEventListener('click', async () => {
    notif.textContent=''; name.classList.remove('error'); email.classList.remove('error');
    let ok = true;
    if(!name.value.trim())                              { name.classList.add('error');  ok=false; }
    if(!email.value.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)){ email.classList.add('error'); ok=false; }
    if(!ok) return;
    btn.disabled = true;
    const res = await api.post(`/events/${ev.id}/rsvp`, {name:name.value.trim(), email:email.value.trim()});
    btn.disabled = false;
    if(res.id){
      sessionStorage.setItem(sk,'1');
      form.innerHTML=''; form.appendChild(el('div','wsa-rsvp-done', s.rsvpDone));
    } else {
      notif.className='wsa-notice error'; notif.textContent=res.message||s.errorSave;
    }
  });
  sec.appendChild(form);
  return sec;
}

async function buildRsvpListSection(id){
  const sec  = el('div','wsa-modal-section');
  sec.appendChild(el('h4','', s.rsvpList));
  let rows;
  try { rows = await api.get(`/events/${id}/rsvp`); } catch(e){ rows=[]; }
  if(!rows.length){ sec.appendChild(el('p','', s.noEvents)); return sec; }
  const tbl = el('table','wsa-rsvp-table');
  const htr = el('tr','');
  ['#','Naam','E-mail','Datum'].forEach(h => htr.appendChild(el('th','',h)));
  tbl.appendChild(htr);
  rows.forEach((r,i) => {
    const tr = el('tr','');
    [i+1, r.name, r.email, r.created_at].forEach(v => tr.appendChild(el('td','',String(v))));
    tbl.appendChild(tr);
  });
  sec.appendChild(tbl);
  const dl = document.createElement('a');
  dl.href = `${cfg.apiBase}/events/${id}/rsvp/csv?_wpnonce=${cfg.nonce}`;
  dl.textContent = s.downloadCsv; dl.target='_blank';
  dl.className='wsa-btn'; dl.style.cssText='margin-top:8px;display:inline-block';
  sec.appendChild(dl);
  return sec;
}

/* ── Time-select helper — 30-minute steps 00:00 … 23:30 ──────────── */
function makeTimeSelect(val){
  const sel = el('select','');
  for(let h=0;h<24;h++){
    for(const m of [0,30]){
      const v=`${p2(h)}:${p2(m)}`;
      const o=el('option','',v); o.value=v;
      if(v===val) o.selected=true;
      sel.appendChild(o);
    }
  }
  return sel;
}

/* Round an "HH:MM" string down to the nearest 30-minute slot. */
function roundTo30(t){
  if(!t) return '10:00';
  const [h,m] = t.split(':').map(Number);
  return `${p2(h)}:${m<30?'00':'30'}`;
}

/* ── Edit / Create modal ──────────────────────────────────────────── */
function openEditModal(ev, prefillDate){
  const isNew = !ev;
  const frag  = document.createDocumentFragment();

  // Extract attachment IDs from the fully-expanded attachments array.
  let attachIds  = ev ? (ev.attachments || []).map(a => a.id) : [];
  let attachData = ev ? [...(ev.attachments || [])]           : [];

  // Parse existing start / end into separate date and time parts.
  const startDate0 = ev?.start ? ev.start.slice(0,10) : (prefillDate || '');
  const endDate0   = ev?.end   ? ev.end.slice(0,10)   : (prefillDate || '');
  const startTime0 = ev?.start?.length > 10 ? roundTo30(ev.start.slice(11,16)) : '10:00';
  const endTime0   = ev?.end?.length   > 10 ? roundTo30(ev.end.slice(11,16))   : '11:00';
  const isAllday0  = ev?.allday || false;

  // Once the user manually touches either end field, stop auto-filling.
  let endEdited = !isNew;

  // ── Header ──────────────────────────────────────────────────────
  const hdr = el('div','wsa-modal-header');
  const ht  = el('h2','wsa-modal-title', isNew ? s.addEvent : `${s.edit}: ${ev.title}`);
  const cls = el('button','wsa-modal-close','×');
  cls.addEventListener('click', closeModal);
  hdr.append(ht, cls); frag.appendChild(hdr);

  const body = el('div','wsa-modal-body');
  const form = el('div','wsa-edit-form');

  // ── Title ────────────────────────────────────────────────────────
  const titleF = makeField(s.title, 'text', ev?.title || '');

  // ── Category ─────────────────────────────────────────────────────
  const catF = el('div','wsa-field');
  catF.appendChild(el('label','', s.category));
  const catSel = el('select','');
  state.categories.forEach(c => {
    const o = el('option','',c.name); o.value=c.slug;
    if(ev?.category?.slug === c.slug) o.selected=true;
    catSel.appendChild(o);
  });
  catF.appendChild(catSel);

  // ── All-day checkbox ─────────────────────────────────────────────
  const alldayF   = el('div','wsa-field');
  const alldayLbl = el('label','wsa-toggle-label');
  const alldayChk = document.createElement('input');
  alldayChk.type='checkbox'; alldayChk.checked=isAllday0;
  alldayLbl.append(alldayChk, document.createTextNode('\u00a0'+s.allDay));
  alldayF.appendChild(alldayLbl);

  // ── Start row: date + time select ────────────────────────────────
  const rowStart     = el('div','wsa-field-row');
  const startDateF   = makeField(s.startDate, 'date', startDate0);
  const startTimeSel = makeTimeSelect(startTime0);
  const startTimeF   = el('div','wsa-field');
  startTimeF.appendChild(el('label','', s.startTime));
  startTimeF.appendChild(startTimeSel);
  rowStart.append(startDateF, startTimeF);

  // ── End row: date + time select ──────────────────────────────────
  const rowEnd     = el('div','wsa-field-row');
  const endDateF   = makeField(s.endDate, 'date', endDate0);
  const endTimeSel = makeTimeSelect(endTime0);
  const endTimeF   = el('div','wsa-field');
  endTimeF.appendChild(el('label','', s.endTime));
  endTimeF.appendChild(endTimeSel);
  rowEnd.append(endDateF, endTimeF);

  const startDateIn = startDateF.querySelector('input');
  const endDateIn   = endDateF.querySelector('input');

  // Auto-fill end date+time when start changes (only while end is untouched).
  const autoEnd = () => {
    if(endEdited) return;
    endDateIn.value = startDateIn.value;
    const [h,m] = startTimeSel.value.split(':').map(Number);
    endTimeSel.value = h < 23 ? `${p2(h+1)}:${p2(m)}` : '23:30';
  };
  startDateIn.addEventListener('change', autoEnd);
  startTimeSel.addEventListener('change', () => { if(!endEdited) autoEnd(); });
  endDateIn.addEventListener('change',   () => { endEdited = true; });
  endTimeSel.addEventListener('change',  () => { endEdited = true; });

  // Show/hide time selects based on the all-day checkbox.
  const syncAlldayUI = () => {
    const hide = alldayChk.checked;
    startTimeF.style.display = hide ? 'none' : '';
    endTimeF.style.display   = hide ? 'none' : '';
    // If end date is empty when all-day is ticked, copy start date.
    if(hide && !endDateIn.value && startDateIn.value){
      endDateIn.value = startDateIn.value;
    }
  };
  alldayChk.addEventListener('change', syncAlldayUI);
  syncAlldayUI(); // apply immediately on open

  // ── Description ──────────────────────────────────────────────────
  const descF  = el('div','wsa-field');
  descF.appendChild(el('label','', s.description));
  const descTA = el('textarea','');
  descTA.innerHTML = ev?.description || '';
  descF.appendChild(descTA);

  // ── Attachments ──────────────────────────────────────────────────
  const attF  = el('div','wsa-field');
  attF.appendChild(el('label','', s.attachments));
  const chips = el('div','wsa-att-chips');
  const renderChips = () => {
    chips.innerHTML = '';
    attachData.forEach((a,i) => {
      const chip = el('div','wsa-att-chip', a.filename||a.url||String(a.id));
      const rm   = el('button','','×');
      rm.setAttribute('aria-label','Verwijderen');
      rm.addEventListener('click', () => {
        attachIds.splice(i,1); attachData.splice(i,1); renderChips();
      });
      chip.appendChild(rm); chips.appendChild(chip);
    });
  };
  renderChips();

  const attBtn = el('button','wsa-btn', s.addFile); attBtn.type='button';
  if(window.wp?.media){
    attBtn.addEventListener('click', () => {
      const frame = wp.media({
        title:    cfg.mediaTitle,
        multiple: true,
        library:  { type: ['image','application/pdf'] },
        button:   { text: cfg.mediaButton },
      });
      frame.on('select', () => {
        frame.state().get('selection').each(att => {
          const j = att.toJSON();
          if(!attachIds.includes(j.id)){
            attachIds.push(j.id);
            attachData.push({ id:j.id, filename:j.filename||j.title||String(j.id), url:j.url });
          }
        });
        renderChips();
      });
      frame.open();
    });
  } else {
    // wp.media not loaded — disable with a tooltip so board members see why.
    attBtn.disabled = true;
    attBtn.title = 'Mediabeheer niet beschikbaar op deze pagina';
  }
  attF.append(chips, attBtn);

  // ── Notification area ────────────────────────────────────────────
  const notif = el('div','');

  // No RSVP fields — removed per CR.
  form.append(titleF, catF, alldayF, rowStart, rowEnd, descF, attF, notif);
  body.appendChild(form); frag.appendChild(body);

  // ── Footer ───────────────────────────────────────────────────────
  const foot   = el('div','wsa-modal-footer');
  const canBtn = el('button','wsa-btn', s.cancel); canBtn.addEventListener('click', closeModal);
  const savBtn = el('button','wsa-btn wsa-btn-primary', s.save);
  savBtn.addEventListener('click', async () => {
    notif.textContent = '';
    const titleVal = titleF.querySelector('input').value.trim();
    if(!titleVal){ titleF.querySelector('input').classList.add('error'); return; }
    savBtn.disabled = true;
    const allday  = alldayChk.checked;
    const payload = {
      title:         titleVal,
      category_slug: catSel.value,
      start:         allday ? startDateIn.value : `${startDateIn.value}T${startTimeSel.value}:00`,
      end:           allday ? endDateIn.value   : `${endDateIn.value}T${endTimeSel.value}:00`,
      description:   descTA.value,
      allday:        allday,
      attachments:   attachIds,
    };
    const res = isNew ? await api.post('/events',payload) : await api.patch(`/events/${ev.id}`,payload);
    savBtn.disabled = false;
    if(res.id){ closeModal(); await loadData(); }
    else { notif.className='wsa-notice error'; notif.textContent=res.message||s.errorSave; }
  });
  foot.append(canBtn, savBtn);
  if(!isNew && cfg.isBoard){
    const del = el('button','wsa-btn wsa-btn-danger', s.delete);
    del.addEventListener('click', async () => {
      if(!confirm(s.confirmDelete)) return;
      await api.del(`/events/${ev.id}`); closeModal(); await loadData();
    });
    foot.insertBefore(del, canBtn);
  }
  frag.appendChild(foot);

  const wrap = document.createElement('div'); wrap.appendChild(frag);
  openModal(wrap);
}

function makeField(label, type, val){
  const f = el('div','wsa-field');
  f.appendChild(el('label','', label));
  const i = document.createElement('input'); i.type=type; i.value=val||'';
  f.appendChild(i); return f;
}

/* ── In-modal login chooser ───────────────────────────────────────── */

/* Inline SVGs — no external requests. */
const SVG_GOOGLE = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
  + '<path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>'
  + '<path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>'
  + '<path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>'
  + '<path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>'
  + '</svg>';

const SVG_APPLE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false">'
  + '<path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.7 9.05 7.42c1.27.07 2.09.74 2.93.8 1.11-.23 2.17-.93 3.35-.84 1.4.12 2.47.71 3.16 1.85-2.89 1.71-2.2 5.49.41 6.62-.37 1.12-.87 2.22-1.85 3.43z"/>'
  + '<path d="M12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/>'
  + '</svg>';

const SVG_WP = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">'
  + '<path fill="#00669b" d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zM3.521 12c0-1.176.253-2.293.702-3.305l3.863 10.583A8.491 8.491 0 013.521 12zm8.479 8.479c-.795 0-1.561-.109-2.287-.311l2.428-7.056 2.487 6.815.057.114a8.48 8.48 0 01-2.685.438zm1.17-12.479.903-.083c.425-.05.376-.675-.05-.65 0 0-1.278.099-2.103.099-.776 0-2.079-.099-2.079-.099-.426-.025-.476.624-.051.65l.878.083 1.304 3.573-1.831 5.487-3.048-9.06.877-.083c.426-.05.376-.675-.05-.65 0 0-1.278.099-2.103.099-.148 0-.309-.004-.479-.01A8.502 8.502 0 0120.479 12a8.48 8.48 0 01-.932 3.877l-1.277-3.648-5.1-4.229zm2.056 9.685 2.461-7.121A8.497 8.497 0 0120.479 12a8.48 8.48 0 01-5.253 7.685z"/>'
  + '</svg>';

/**
 * Replaces the content of the currently open .wsa-modal in-place,
 * keeping the backdrop, focus trap and Escape/click-outside handlers alive.
 */
function replaceModalContent(newContent){
  const modal = getModalRoot()?.querySelector('.wsa-modal');
  if(!modal) return;
  const handle = modal.querySelector('.wsa-sheet-handle');
  while(modal.lastChild) modal.removeChild(modal.lastChild);
  if(handle) modal.appendChild(handle);
  modal.appendChild(newContent);
  modal.focus();
}

/**
 * Replaces the current modal view with the login chooser panel.
 * @param {object} ev  The event object — used by the "← Terug" back link.
 */
function openLoginChooser(ev){
  const wrap = document.createElement('div');

  // Header: back link left, close button right
  const hdr  = el('div','wsa-modal-header');
  const back = el('button','wsa-login-back','← Terug naar evenement');
  back.addEventListener('click', () => openViewModal(ev.id));
  const closeBtn = el('button','wsa-modal-close','×');
  closeBtn.setAttribute('aria-label', s.close);
  closeBtn.addEventListener('click', closeModal);
  hdr.append(back, closeBtn);
  wrap.appendChild(hdr);

  // Body: title + stacked options
  const body = el('div','wsa-modal-body');
  body.appendChild(el('h3','wsa-login-chooser-title','Inloggen als bestuurslid'));

  const opts = el('div','wsa-login-chooser-opts');

  // Google
  const gBtn = document.createElement('button');
  gBtn.className = 'wsa-login-sso-btn wsa-login-sso-google';
  gBtn.innerHTML = SVG_GOOGLE + '<span>Inloggen met Google</span>';
  gBtn.addEventListener('click', () => { window.location.href = cfg.ssoGoogleUrl + '?event_id=' + encodeURIComponent(ev.id); });
  opts.appendChild(gBtn);

  // Apple
  const aBtn = document.createElement('button');
  aBtn.className = 'wsa-login-sso-btn wsa-login-sso-apple';
  aBtn.innerHTML = SVG_APPLE + '<span>Inloggen met Apple</span>';
  aBtn.addEventListener('click', () => { window.location.href = cfg.ssoAppleUrl + '?event_id=' + encodeURIComponent(ev.id); });
  opts.appendChild(aBtn);

  // WordPress — full-width button matching Google and Apple style
  const wpBtn = document.createElement('button');
  wpBtn.className = 'wsa-login-sso-btn wsa-login-sso-wp';
  wpBtn.innerHTML = SVG_WP + '<span>Inloggen met WordPress</span>';
  wpBtn.addEventListener('click', () => {
    const redirect = cfg.siteUrl + '/agenda/?wsa_edit_event=' + ev.id;
    window.location.href = cfg.siteUrl + '/wp-login.php?redirect_to=' + encodeURIComponent(redirect);
  });
  opts.appendChild(wpBtn);

  body.appendChild(opts);
  wrap.appendChild(body);

  replaceModalContent(wrap);
}

/* ── Init ─────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  if(!document.getElementById('wsa-calendar-app')) return;

  // After SSO redirect: auto-open the edit modal for the event that was being edited.
  const _up = new URLSearchParams(window.location.search);
  const _editId = parseInt(_up.get('wsa_edit_event'), 10);
  console.log('[WSA] wsa_edit_event:', _up.get('wsa_edit_event'), '| isBoard:', cfg.isBoard);
  if (_editId > 0 && cfg.isBoard) {
    _up.delete('wsa_edit_event');
    const _qs = _up.toString();
    history.replaceState(null, '', window.location.pathname + (_qs ? '?' + _qs : ''));
    // Start event fetch concurrently with calendar load; open modal only after
    // loadData() has rendered the calendar so the modal opens over a live calendar.
    const _editFetch = api.get('/events/' + _editId).catch(() => null);
    loadData().then(() => {
      _editFetch.then(ev => {
        if (ev && ev.id && !ev.code) openEditModal(ev);
      });
    });
  } else {
    loadData();
  }

  // Re-render on resize / orientation change so mobile vs desktop layout
  // switches correctly (e.g. when rotating a phone).
  let _resizeT;
  window.addEventListener('resize', () => {
    clearTimeout(_resizeT);
    _resizeT = setTimeout(render, 200);
  });
});

}());
