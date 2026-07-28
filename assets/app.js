/* Main feed — loads events from the generator API and renders cards.
   NOTE: shares global scope with admin.js when both are loaded (admin preview),
   so this uses FEED_API (admin.js keeps its own API) to avoid a redeclaration clash. */
const FEED_API = 'generator/generate.php';

function esc(s){ return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

/* реалистичная картинка по английскому описанию сцены (Pollinations, прямо в браузере) */
let IMG_TPL = '{q}, realistic news photograph, photojournalism, main subject large and centered, close-up framing, subject fills the frame, simple clean uncluttered background, few small details, natural daylight, true-to-life, no cinematic style, no dramatic lighting';
function hashN(s){ let h=0; s=String(s); for(let i=0;i<s.length;i++){ h=(h*31 + s.charCodeAt(i))|0; } return Math.abs(h); }
function pollUrl(subject, id, size){
  const prompt = IMG_TPL.includes('{q}') ? IMG_TPL.replace('{q}', subject) : (subject + ', ' + IMG_TPL);
  const seed = hashN(id || subject) % 100000;
  // через наш скрипт: он тянет картинку у Pollinations ОДИН раз, конвертирует в WebP и кэширует
  return 'generator/img.php?q=' + encodeURIComponent(prompt) + '&w=' + (size||512) + '&seed=' + seed;
}
/* картинка заголовка: 1) лого компании из вопроса → 2) реалистичное фото по image_en → 3) лого/бейдж */
function headImg(e){
  if (e.logo) return e.logo;
  if (e.image_en && String(e.image_en).trim()) return pollUrl(e.image_en.trim(), e.id, 512);
  return e.image;
}

/* подвал карточки: для новостных событий — кликабельная ссылка на реальный источник */
function srcTag(e){
  if (e && e.news_link){
    const name = e.news_source || 'источник';
    return `🔗 <a class="src-link" href="${esc(e.news_link)}" target="_blank" rel="noopener">${esc(name)}</a>`;
  }
  return esc(e ? e.source : '');
}

/* время публикации прогноза — из created_at, в местном времени зрителя */
function pubTime(iso){
  const d = new Date(iso);
  if (isNaN(d)) return '';
  const p = n => String(n).padStart(2,'0');
  return `${p(d.getDate())}.${p(d.getMonth()+1)}.${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

function timeLeft(iso){
  const d = new Date(iso) - new Date();
  if (isNaN(d)) return '';
  if (d <= 0) return 'завершено';
  const m = Math.floor(d/60000), h = Math.floor(m/60), days = Math.floor(h/24);
  if (days > 0) return `${days} дн`;
  if (h > 0) return `${h} ч ${m%60} мин`;
  return `${m} мин`;
}

function optClosed(o){
  const cls = /да|вверх|yes|up/i.test(o.label) ? 'yes' : (/нет|вниз|no|down/i.test(o.label) ? 'no' : '');
  return `<div class="opt ${cls}"><span class="lbl">${esc(o.label)}</span><span class="price">${o.price}¢</span></div>`;
}
function optOpen(o){
  let img = '';
  if (o.logo){                                    // лого страны/компании из ответа («Китай» → лого)
    img = `<img class="o-img" src="${o.logo}" loading="lazy" alt="">`;
  } else if (o.image_en && String(o.image_en).trim()){
    // фото под КОНКРЕТНЫЙ ответ (через наш кэширующий WebP-прокси); пока грузится — заглушка
    const src = pollUrl(o.image_en.trim(), o.label, 128);
    const ph = phBg(o.image);
    const bg = ph ? ` style="background:#2b3550 center/cover no-repeat url('${ph}')"` : '';
    img = `<img class="o-img" src="${src}" loading="lazy" alt="" data-ph="${ph}"${bg} onerror="imgErr(this)">`;
  } else if (o.image){
    img = `<img class="o-img" src="${o.image}" alt="">`;
  }
  return `<div class="opt">${img}<span class="lbl">${esc(o.label)}</span><span class="price">${o.price}¢</span></div>`;
}

function fmtP(p){ p=+p; if(p>=1000) return '$'+p.toLocaleString('ru-RU',{maximumFractionDigits:0}); if(p>=1) return '$'+p.toFixed(2); return '$'+p.toPrecision(3); }

function card(e){
  const isOpen = e.type === 'open';
  const opts = e.options.map(isOpen ? optOpen : optClosed).join('');
  const binary = (!isOpen && e.options.length === 2) ? 'binary' : '';
  const resBadge = e.result
    ? `<span class="badge res ${e.result==='ДА'?'win':'lose'}">${e.result==='ДА'?'✅':'❌'} Итог: ${esc(e.result)}${e.final_price?' · '+fmtP(e.final_price):''}</span>`
    : '';
  return `<div class="card${e.result?' resolved':''}" data-id="${esc(e.id)}">
    <div class="card-head">
      <img class="q-img" src="${headImg(e)}" loading="lazy" alt="" data-ph="${phBg(e.image)}" style="background:#243b53 center/cover no-repeat url('${phBg(e.image)}')" onerror="imgErr(this)">
      <div class="q-main">
        <p class="q-text">${esc(e.question)}</p>
        <div class="badges">
          <span class="badge cat">${esc(e.category_label || e.category)}</span>
          <span class="badge ${e.type}">${isOpen ? 'открытый' : 'закрытый'}</span>
          <span class="badge">⏱ ${esc(e.timeframe_label || e.timeframe)}</span>
          ${resBadge}
        </div>
      </div>
    </div>
    <div class="opts ${binary}">${opts}</div>
    <div class="foot"><span>🕐 ${pubTime(e.created_at)} · ${srcTag(e)}</span><span>до конца: ${timeLeft(e.resolves_at)}</span></div>
  </div>`;
}

/* ---------- вкладки (как на референс-сайте) + лимит на категорию ---------- */
let ALL_EVENTS = [];
let ACTIVE_TAB = '__all__';
let PER_CAT = 6;          // сколько прогнозов на категорию

/* фикс. набор вкладок: cats — коды категорий, kinds — типы вопросов (versus/race) */
const TAB_DEFS = [
  {key:'__all__',     label:'Все'},
  {key:'crypto',      label:'Криптовалюты',  cats:['crypto','crypto_news'], live:true},
  {key:'forex',       label:'Форекс',        cats:['currency'], live:true},
  {key:'stocks',      label:'Акции',         cats:['stocks'], live:true},
  {key:'commodities', label:'Сырьё',         cats:['commodities','metals'], live:true},
  {key:'indexes',     label:'Индексы',       cats:['indexes'], live:true},
  {key:'battles',     label:'Битвы активов', kinds:['versus'], cats:['battles_global','battles_ru_world'], live:true},
  {key:'races',       label:'Гонки',         kinds:['race'], live:true},
  {key:'econ',        label:'Экономика',     cats:['world_econ','ru_econ']},
  {key:'geo',         label:'Геополитика',   cats:['world_geo','ru_geo']},
  {key:'war',         label:'Война',         cats:['war_ru_ua','frontline']},
  {key:'tech',        label:'Технологии',    cats:['world_tech','ru_tech']},
  {key:'politics',    label:'Политика',      cats:['ru_internal','putin']},
];

let PLACEHOLDER = '';   // картинка-заглушка (пока настоящая не готова)
async function loadMeta(){
  try{
    const cfg = await (await fetch('generator/news.php?action=config_get')).json();
    PER_CAT = (cfg.config && cfg.config.feed_per_cat) || 6;
    if (cfg.config && cfg.config.img_prompt) IMG_TPL = cfg.config.img_prompt;
    PLACEHOLDER = (cfg.config && cfg.config.placeholder_img) || '';
  }catch(e){}
}
/* фон-заглушка для картинки, пока грузится (заглушка из логотипов, иначе бейдж) */
function phBg(fallback){ return (PLACEHOLDER || fallback || ''); }
/* картинка не загрузилась (обычно сервер занят генерацией другой) — пробуем ещё пару раз,
   и только потом заглушка. Иначе одна осечка навсегда вешала серую заглушку. */
function imgErr(img){
  const n = (+img.dataset.try || 0);
  if (n < 3){
    img.dataset.try = n + 1;
    const base = img.src.replace(/[?&]r=\d+/, '');   // r= сервер игнорирует, но браузер пере-запросит
    const sep = base.includes('?') ? '&' : '?';
    setTimeout(() => { img.src = base + sep + 'r=' + (n + 1); }, 1200 * (n + 1));
  } else {
    img.onerror = null;
    const ph = img.dataset.ph || '';
    if (ph && img.src !== ph) img.src = ph;
  }
}

const PAGE_SIZE = 24;   // событий на страницу
let CURRENT_PAGE = 0;
function setPage(n){ CURRENT_PAGE = n; renderGrid(true); const g=document.getElementById('grid'); if(g) g.scrollIntoView({behavior:'smooth',block:'start'}); }
function setTab(k){ ACTIVE_TAB = k; CURRENT_PAGE = 0; renderTabs(); renderSide(); renderGrid(); }
function renderTabs(){
  const box = document.getElementById('tabs'); if(!box) return;
  // верхние вкладки — новости/аналитика (не лайв)
  box.innerHTML = TAB_DEFS.filter(t=>!t.live).map(t =>
    `<button class="tab${t.key===ACTIVE_TAB?' active':''}" onclick="setTab('${t.key}')">${esc(t.label)}</button>`
  ).join('');
}
function renderSide(){
  const box = document.getElementById('side_tabs'); if(!box) return;
  // боковое меню — лайв-каналы (рынки/битвы/гонки)
  box.innerHTML = TAB_DEFS.filter(t=>t.live).map(t =>
    `<button class="tab side${t.key===ACTIVE_TAB?' active':''}" onclick="setTab('${t.key}')">${esc(t.label)}</button>`
  ).join('');
}
function tabMatch(e, tab){
  if (tab.key === '__all__') return true;
  if (tab.cats  && tab.cats.includes(e.category)) return true;
  if (tab.kinds && e.kind && tab.kinds.includes(e.kind)) return true;
  return false;
}
/* фильтр по вкладке + не больше PER_CAT на каждую категорию (без хаоса) */
function visibleEvents(){
  const tab = TAB_DEFS.find(t => t.key === ACTIVE_TAB) || TAB_DEFS[0];
  const cnt = {}, out = [];
  for (const e of ALL_EVENTS){
    if (!tabMatch(e, tab)) continue;
    const c = e.category; cnt[c] = cnt[c]||0;
    if (cnt[c] < PER_CAT){ out.push(e); cnt[c]++; }
  }
  return out;
}
let LAST_SIG = '';
/* динамическая часть карточки (цены/итог) — меняется, значит эту карточку надо перерисовать */
function cardSig(e){ return (e.result||'') + '|' + (e.options||[]).map(o=>o.price).join(','); }
function buildCard(e){
  const tmp = document.createElement('div');
  tmp.innerHTML = card(e);
  const el = tmp.firstElementChild;
  el.dataset.sig = cardSig(e);
  return el;
}
/* ИНКРЕМЕНТАЛЬНЫЙ рендер: существующие карточки и их уже загруженные <img> НЕ трогаем.
   Появилось новое событие → добавляем одну карточку (грузится 1 картинка, а не все 24).
   Раньше любой апдейт пересоздавал весь innerHTML → все картинки перезапрашивались разом,
   и на одно-поточном сервере готовые «пропадали», пока догенеривалась чья-то одна. */
function renderGrid(force){
  const vis = visibleEvents();
  const pages = Math.max(1, Math.ceil(vis.length / PAGE_SIZE));
  if (CURRENT_PAGE >= pages) CURRENT_PAGE = pages - 1;
  if (CURRENT_PAGE < 0) CURRENT_PAGE = 0;
  const page = vis.slice(CURRENT_PAGE * PAGE_SIZE, CURRENT_PAGE * PAGE_SIZE + PAGE_SIZE);
  const sig = ACTIVE_TAB + '#p' + CURRENT_PAGE + '#' + page.map(e =>
    e.id + ':' + cardSig(e)
  ).join('|') + '#n' + vis.length;
  if (!force && sig === LAST_SIG) return;   // ничего не поменялось — DOM не трогаем вовсе
  LAST_SIG = sig;
  const grid = document.getElementById('grid'); if (!grid) return;
  document.getElementById('empty').style.display = vis.length ? 'none' : 'block';

  // карта существующих карточек по id
  const existing = new Map();
  for (const el of Array.from(grid.children)){ if (el.dataset && el.dataset.id) existing.set(el.dataset.id, el); }
  const wanted = new Set(page.map(e => e.id));
  for (const [id, el] of existing){ if (!wanted.has(id)){ el.remove(); existing.delete(id); } }  // ушедшие — убрать

  let prev = null;
  for (const e of page){
    let el = existing.get(e.id);
    if (!el){ el = buildCard(e); }                                   // новая карточка
    else if (el.dataset.sig !== cardSig(e)){ const n = buildCard(e); el.replaceWith(n); el = n; } // изменились цены/итог — только эта
    const ref = prev ? prev.nextSibling : grid.firstChild;           // поставить в правильный порядок
    if (el !== ref) grid.insertBefore(el, ref);
    prev = el;
  }
  renderPager(pages);
}
/* постраничные переключатели */
function renderPager(pages){
  const box = document.getElementById('pager'); if(!box) return;
  if (pages <= 1){ box.innerHTML = ''; return; }
  const cur = CURRENT_PAGE;
  const btn = (n,label,dis,act)=>`<button class="pg-btn${act?' active':''}" ${dis?'disabled':''} onclick="setPage(${n})">${label}</button>`;
  let nums = [];
  for (let i=0;i<pages;i++){
    if (i===0 || i===pages-1 || Math.abs(i-cur)<=2){ nums.push(btn(i, i+1, false, i===cur)); }
    else if (nums[nums.length-1] !== '…'){ nums.push('…'); }
  }
  box.innerHTML = btn(cur-1,'‹',cur===0,false) + nums.map(x=>x==='…'?'<span class="pg-dots">…</span>':x).join('') + btn(cur+1,'›',cur===pages-1,false);
}

async function load(){
  try{
    const j = await (await fetch(FEED_API + '?action=list&_=' + Date.now())).json();
    ALL_EVENTS = j.events || [];
    document.getElementById('count').textContent = ALL_EVENTS.length + ' событий';
    const byCat = {};
    ALL_EVENTS.forEach(e => { const name = e.category_label || e.category; byCat[name] = (byCat[name]||0)+1; });
    document.getElementById('stats').textContent = Object.entries(byCat).map(([k,v]) => `${k}: ${v}`).join('  ·  ');
    if (!TAB_DEFS.some(t => t.key === ACTIVE_TAB)) ACTIVE_TAB = '__all__';
    renderTabs(); renderSide(); renderGrid();
  } catch(err){
    document.getElementById('empty').style.display = 'block';
    document.getElementById('empty').innerHTML = 'Не удалось загрузить данные.<br>Запусти сайт через веб-сервер (Docker или <code>php -S</code>) — см. README.';
  }
}
// авто-старт только на главной (где есть #grid). В админке этот же файл даёт функцию card() для превью.
if (document.getElementById('grid')) {
  (async () => { await loadMeta(); load(); })();
  setInterval(load, 4000);   // лента сама подхватывает новое; активная вкладка сохраняется
}
