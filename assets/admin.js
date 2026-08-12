/* Admin — preset buttons + manual settings, calls the generator API. */
const API = 'generator/generate.php';

function log(msg){
  const el = document.getElementById('log');
  const t = new Date().toLocaleTimeString();
  el.textContent = `[${t}] ${msg}\n` + el.textContent;
}

async function api(params){
  const r = await fetch(API + '?' + new URLSearchParams({...params, _: Date.now()}).toString(), {cache:'no-store'});
  return r.json();
}

async function refreshTotal(){
  try{ const j = await api({action:'list'}); document.getElementById('total').textContent = (j.events||[]).length + ' в ленте'; }
  catch(e){ document.getElementById('total').textContent = 'нет связи'; }
}

/* живое превью выдачи прямо в админке (использует card() из app.js) */
async function refreshPreview(){
  try{
    const ev = (await api({action:'list'})).events || [];
    document.getElementById('pvcount').textContent = ev.length;
    document.getElementById('preview').innerHTML = ev.length
      ? ev.map(card).join('')
      : '<div class="empty">Пусто. Нажми пресет или «Сгенерировать» — вопросы появятся здесь и в ленте.</div>';
  }catch(e){}
}

async function loadPresets(){
  try{
    const j = await api({action:'presets'});
    const box = document.getElementById('presets');
    box.innerHTML = Object.entries(j.presets).map(([key,p]) =>
      `<button class="preset" onclick="genPreset('${key}')">
         <span class="t">${p.title}</span>
         <span class="s">${p.type==='open'?'открытые':'закрытые'} · ${p.count} шт</span>
       </button>`).join('');
  } catch(e){
    document.getElementById('presets').innerHTML =
      '<div class="empty">Нет связи с генератором. Запусти через веб-сервер (Docker или <code>php -S</code>) — см. README.</div>';
  }
}

async function genPreset(key){
  log(`генерирую пресет «${key}»...`);
  const j = await api({action:'generate', preset:key});
  if (j.ok) log(`✔ добавлено ${j.added} (${j.category}/${j.type}/${j.timeframe}). Всего: ${j.total}`);
  else log('✖ ошибка: ' + (j.error||''));
  refreshTotal(); refreshPreview();
}

async function genManual(){
  const p = {
    action:'generate',
    category: document.getElementById('category').value,
    type: document.getElementById('type').value,
    timeframe: document.getElementById('timeframe').value,
    count: document.getElementById('count').value,
  };
  log(`генерирую ${p.count}× ${p.category}/${p.type}/${p.timeframe}...`);
  const j = await api(p);
  if (j.ok) log(`✔ добавлено ${j.added}. Всего: ${j.total}`);
  else log('✖ ошибка: ' + (j.error||''));
  refreshTotal(); refreshPreview();
}

async function clearAll(){
  if (!confirm('Удалить ВСЕ события из ленты?')) return;
  await api({action:'clear'});
  log('🗑 лента очищена');
  refreshTotal(); refreshPreview();
}

/* подсказки таймфрейма для ручного поля (набор берём из meta) */
function renderTfChips(tfs){ document.getElementById('tfchips').innerHTML = chipHtml(tfs, 'timeframe'); }

/* строим оба списка категорий из одного источника (сервер) — чтобы не расходились */
function buildCats(cats){
  const groups = {};
  cats.forEach(c => { (groups[c.group] = groups[c.group] || []).push(c); });
  const html = Object.entries(groups).map(([g, items]) =>
    `<optgroup label="${g}">` + items.map(c => `<option value="${c.code}">${c.label}</option>`).join('') + `</optgroup>`
  ).join('');
  ['category','ag_category','dx_category'].forEach(id => { const el = document.getElementById(id); if (el) el.innerHTML = html; });
}

/* ---------- импорт вопросов из .docx ---------- */
function dxLog(msg){
  const el = document.getElementById('dx_log');
  if (!el) return;
  const t = new Date().toLocaleTimeString();
  el.textContent = `[${t}] ${msg}\n` + el.textContent;
}

async function dxLoad(){
  const box = document.getElementById('dx_list');
  box.textContent = 'Читаю пачки...';
  const j = await api({action:'docx_batches'});
  if (!j.ok){ box.textContent = 'Ошибка: ' + (j.error || 'не удалось прочитать'); return; }
  if (!j.batches.length){ box.textContent = 'В data/docx нет файлов .docx'; return; }

  const total = j.batches.reduce((s,b)=>s+b.count, 0);
  box.innerHTML = `<div class="muted" style="margin-bottom:8px">Пачек: ${j.batches.length} · вопросов: ${total}</div>` +
    j.batches.map(b =>
      `<div class="dx-row">
         <span class="dx-name">${b.name}</span>
         <span class="dx-count">${b.count} вопр.</span>
         <button class="btn" onclick="dxImport(this, '${encodeURIComponent(b.file)}')">Импортировать</button>
       </div>`
    ).join('');
  dxLog(`найдено пачек: ${j.batches.length}, вопросов: ${total}`);
}

async function dxImport(btn, fileEnc){
  const file = decodeURIComponent(fileEnc);
  btn.disabled = true; btn.textContent = 'Импортирую...';
  const j = await api({
    action:    'docx_import',
    file:      file,
    category:  document.getElementById('dx_category').value,
    ttl:       document.getElementById('dx_ttl').value,
    timeframe: document.getElementById('dx_ttl').selectedOptions[0].textContent,
  });
  if (j.ok){
    dxLog(`✔ ${file}: добавлено ${j.added}, пропущено дублей ${j.skipped}. Всего событий: ${j.total}`);
    btn.textContent = j.added ? `+${j.added}` : 'уже есть';
  } else {
    dxLog(`✖ ${file}: ${j.error || 'ошибка'}`);
    btn.textContent = 'ошибка'; btn.disabled = false;
  }
}

/* ---------- авто-генерация (бесконечно, на сервере) ---------- */
const IV_HINTS = [{v:'30s',l:'30 сек'},{v:'1m',l:'1 мин'},{v:'5m',l:'5 мин'},{v:'15m',l:'15 мин'},{v:'30m',l:'30 мин'},{v:'1h',l:'1 час'}];
function chipHtml(list, targetId){
  return list.map(t=>`<button type="button" class="chip" onclick="document.getElementById('${targetId}').value='${t.v}'">${t.l}</button>`).join('');
}
function parseTfClient(tf){
  tf=String(tf).trim(); if(/^\d+$/.test(tf)) return +tf;
  const u={s:1,m:60,h:3600,d:86400,w:604800,M:2592000,y:31536000}; let total=0,ok=false;
  tf.replace(/(\d+)\s*([smhdwMy])/g,(_,n,x)=>{total+=(+n)*u[x];ok=true;return '';});
  return ok?total:3600;
}
function fmtSec(v){
  let s=(typeof v==='number')?v:parseTfClient(v);
  if(s<60) return s+' сек';
  const u=[['год',31536000],['мес',2592000],['нед',604800],['дн',86400],['ч',3600],['мин',60],['сек',1]];
  let rem=s, parts=[];
  for(const [l,sec] of u){ if(rem>=sec){const q=Math.floor(rem/sec); rem-=q*sec; parts.push(q+' '+l);} if(parts.length>=2)break; }
  return parts.join(' ');
}
async function agStart(){
  const p = {
    action:'sched_add',
    category: document.getElementById('ag_category').value,
    type: document.getElementById('ag_type').value,
    timeframe: document.getElementById('ag_timeframe').value,
    interval: document.getElementById('ag_interval').value,
    count: document.getElementById('ag_count').value,
  };
  const j = await api(p);
  if (j.ok) log(`♾ авто-ген запущена: ${p.category}/${p.type}, событие ${p.timeframe}, раз в ${p.interval}`);
  else log('✖ ошибка запуска авто-ген');
  agRefresh();
}
async function agToggle(id){ await api({action:'sched_toggle', id}); agRefresh(); }
async function agDel(id){ await api({action:'sched_del', id}); agRefresh(); }
async function agRefresh(){
  try{
    const list = (await api({action:'sched_list'})).schedules || [];
    const box = document.getElementById('ag_list');
    if (!list.length){ box.innerHTML = '<div class="empty" style="padding:14px 0">Авто-генераций нет. Настрой выше и нажми «Запустить бесконечно».</div>'; return; }
    box.innerHTML = list.map(s => {
      const on = s.active;
      return `<div class="sched ${on?'on':''}">
        <div class="s-main"><b>${esc(catLabel(s.category))}</b> · ${s.type==='open'?'открытые':'закрытые'} · событие ${fmtSec(s.timeframe)} · <span class="s-iv">раз в ${fmtSec(s.interval)}</span> · ${s.count} шт
          <span class="s-state">${on?'● работает':'○ пауза'}</span></div>
        <div class="s-btns">
          <button class="btn" onclick="agToggle('${s.id}')">${on?'⏸ Стоп':'▶ Старт'}</button>
          <button class="btn danger" onclick="agDel('${s.id}')">✕</button>
        </div></div>`;
    }).join('');
  }catch(e){}
}

/* ---------- свои категории ---------- */
async function reloadCats(){
  const meta = await api({action:'meta'}).catch(()=>({}));
  buildCats(meta.categories || []);
}
async function catAdd(){
  const label = document.getElementById('cc_label').value.trim();
  if (!label){ alert('Впиши название категории'); return; }
  const j = await api({
    action:'cat_add', label,
    group: document.getElementById('cc_group').value.trim() || 'Свои',
    question: document.getElementById('cc_question').value.trim(),
    options: document.getElementById('cc_options').value.trim(),
  });
  if (j.ok){
    log('＋ категория добавлена: ' + label);
    document.getElementById('cc_label').value = '';
    document.getElementById('cc_question').value = '';
    document.getElementById('cc_options').value = '';
    await reloadCats(); catRefresh();
  } else log('✖ ' + (j.error || 'ошибка'));
}
async function catDel(code){ await api({action:'cat_del', code}); await reloadCats(); catRefresh(); }
async function catRefresh(){
  try{
    const list = (await api({action:'cat_list'})).categories || [];
    const box = document.getElementById('cc_list');
    box.innerHTML = list.length
      ? list.map(c => `<div class="sched"><div class="s-main"><b>${c.label}</b> · ${c.group||'Свои'} · ${(c.options&&c.options.length)?('варианты: '+c.options.join(', ')):'ДА/НЕТ'}</div><div class="s-btns"><button class="btn danger" onclick="catDel('${c.code}')">✕ удалить</button></div></div>`).join('')
      : '<div class="empty" style="padding:12px 0">Своих категорий нет. Добавь выше — появится в списках генерации.</div>';
  }catch(e){}
}

/* ---------- новости -> события ---------- */
async function newsApi(p){ const r = await fetch('generator/news.php?' + new URLSearchParams({...p, _: Date.now()}), {cache:'no-store'}); return r.json(); }

/* известные модели Gemini для fallback-цепочки (порядок = приоритет перебора) */
/* «-latest» алиасы: всегда указывают на актуальную модель Google и не отваливаются
   в 404, когда версии закрывают. Порядок = от самой лёгкой (макс. беспл. лимиты) к умной. */
const KNOWN_MODELS = [
  {id:'gemini-flash-lite-latest', note:'лёгкая · макс. беспл. лимиты'},
  {id:'gemini-flash-latest',      note:'быстрая · баланс'},
  {id:'gemini-pro-latest',        note:'самая умная · лимит низкий'},
];
let newsModels = [];   // выбранная цепочка (в порядке KNOWN_MODELS)
let lastCfg = {};

function showNewsErr(msg){
  const el = document.getElementById('news_err'); if(!el) return;
  if(msg){ el.style.display='block'; el.textContent = '⚠ ' + msg; }
  else   { el.style.display='none';  el.textContent = ''; }
}
function renderModels(c){
  const box = document.getElementById('n_models'); if(!box) return;
  const status = c.model_status || {};
  const selected = newsModels[0];   // выбранная = основная (первая в цепочке)
  // ФИКСИРОВАННЫЙ порядок показа: от самой бесплатной к более платной (как в KNOWN_MODELS)
  box.innerHTML = KNOWN_MODELS.map(m=>{
    const st = status[m.id] || {};
    let cls = 'chip model'; let mark = '';
    if (st.state==='limit')      { cls+=' err';  mark=' ⛔ лимит'; }        // реальная проблема -> красный
    else if (st.state==='error') { cls+=' err';  mark=' ✕ ошибка'; }
    else if (m.id===selected)    { cls+=' work'; mark=' ● выбрана'; }       // твой выбор -> зелёный
    const tip = st.msg ? ' title="'+esc(st.msg)+'"' : '';
    return `<button type="button" class="${cls}"${tip} onclick="pickModel('${m.id}')">
      <span class="ml">${m.id}${mark}</span>
      <span class="mn">${m.note}</span></button>`;
  }).join('');
}
/* клик = сделать модель ВЫБРАННОЙ (первой в цепочке) — сразу зелёная и используется в работе */
function pickModel(id){
  newsModels = [id, ...newsModels.filter(x => x !== id)];
  renderModels(lastCfg);
  newsCfgSave();
}
async function modelsReset(){
  const j = await newsApi({action:'models_reset'});
  if (j.config){ lastCfg = j.config; renderModels(j.config); showNewsErr(''); }
  log('↻ статусы моделей сброшены');
}

/* ---------- ключи Gemini: несколько штук с fallback ---------- */
function maskKey(k){
  k = String(k||'').trim();
  if (k.length <= 8) return k ? k.slice(0,2)+'…'+k.slice(-2) : '—';
  return k.slice(0,4) + '…' + k.slice(-4);
}
function renderKeys(c){
  const box = document.getElementById('n_keys'); if(!box) return;
  const keys   = c.gemini_keys || [];
  const status = c.key_status  || {};
  const active = c.active_key  || '';
  if (!keys.length){
    box.innerHTML = '<span class="empty" style="padding:6px 0;text-align:left">Ключей нет — вставь ключ выше и нажми «Добавить». Можно несколько.</span>';
    return;
  }
  box.innerHTML = keys.map((k, i) => {
    const tail = String(k||'').trim().slice(-4);
    const st   = status[tail] || {};
    let cls = 'chip key', mark = '';
    if (tail && tail === active)      { cls+=' work'; mark=' ● работает'; }
    else if (st.state === 'limit')    { cls+=' err';  mark=' ⛔ лимит'; }
    else if (st.state === 'error')    { cls+=' err';  mark=' ✕ ошибка'; }
    const tip = st.msg ? ' title="'+esc(st.msg)+'"' : '';
    return `<span class="${cls}"${tip}>
      <span class="ml">🔑 ${esc(maskKey(k))}${mark}</span>
      <span class="mn">ключ #${i+1}</span>
      <button type="button" class="chip-x" title="удалить ключ" onclick="keyDel(${i})">✕</button>
    </span>`;
  }).join('');
}
async function keyAdd(){
  const inp = document.getElementById('n_key'); const k = inp.value.trim();
  if (!k){ alert('Вставь Gemini API-ключ в поле'); return; }
  const j = await newsApi({action:'key_add', gemini_key:k});
  if (j.ok && j.config){
    lastCfg = j.config; renderKeys(j.config); showNewsErr(j.config.last_error||'');
    inp.value = ''; log('🔑 ключ добавлен (#' + (j.config.gemini_keys||[]).length + ')');
  } else log('✖ ' + (j.error || 'не удалось добавить ключ'));
}
async function keyDel(i){
  if (!confirm('Удалить этот ключ?')) return;
  const j = await newsApi({action:'key_del', idx:i});
  if (j.config){ lastCfg = j.config; renderKeys(j.config); }
  log('🔑 ключ удалён');
}
async function keysReset(){
  const j = await newsApi({action:'keys_reset'});
  if (j.config){ lastCfg = j.config; renderKeys(j.config); showNewsErr(''); }
  log('↻ статусы ключей сброшены');
}

let rankDefault = '';   // дефолтные критерии важности (для кнопки «по умолчанию»)
async function newsCfgLoad(){
  const resp = (await newsApi({action:'config_get'}).catch(()=>({}))) || {};
  const c = resp.config || {};
  rankDefault = resp.default_rank_prompt || '';
  lastCfg = c;
  const set = (id,v)=>{ const el=document.getElementById(id); if(el) el.value=v; };
  renderKeys(c);   // ключи показываем чипами ниже, поле ввода оставляем пустым (это поле «добавить»)
  set('n_rank', c.rank_prompt || rankDefault);
  set('n_gen', rulesToLines(c.gen_prompt || ''));
  set('img_prompt_tpl', c.img_prompt || '{q}, news editorial illustration, cinematic, digital art, dramatic lighting');
  set('logo_prompt_tpl', c.logo_prompt || '');
  if (typeof IMG_TPL !== 'undefined' && c.img_prompt) IMG_TPL = c.img_prompt;   // чтобы превью в дашборде брались из того же кэша, что и лента
  set('n_sources', (c.sources||[]).join('\n'));
  set('n_interval', (c.auto&&c.auto.interval)||120); set('n_perrun', (c.auto&&c.auto.per_run)||3);
  intervalWarn();
  set('n_keep', c.rank_keep||10);
  set('n_rpd', c.rpd_limit||500);
  set('n_news_keep', c.news_keep||300);
  set('n_feed_per_cat', c.feed_per_cat||6);
  set('n_market_options', c.market_options||5);
  // провайдеры картинок + ключи (несколько на каждого)
  renderMaster(c);
  renderLivePower(c);
  renderEstop(c);
  renderPlaceholder(c);
  await imgStatusLoad();
  renderProviders(c);
  renderImgKeys(c);
  { const na=document.getElementById('n_active'); if(na) na.checked = !!(c.auto&&c.auto.active); }
  document.getElementById('n_pub').checked = !!(c.auto&&c.auto.auto_publish);
  // все модели всегда в цепочке: сначала по сохранённому приоритету, затем недостающие
  const saved = ((c.gemini_models&&c.gemini_models.length) ? c.gemini_models : []).filter(id => KNOWN_MODELS.some(m=>m.id===id));
  newsModels = [...saved, ...KNOWN_MODELS.map(m=>m.id).filter(id => !saved.includes(id))];
  renderModels(c);
  showNewsErr(c.last_error||'');
}
/* лёгкое обновление статусов/ошибки без затирания того, что печатает пользователь */
async function newsStatusRefresh(){
  const c = (await newsApi({action:'config_get'}).catch(()=>({}))).config; if(!c) return;
  lastCfg = c; renderModels(c); renderKeys(c); showNewsErr(c.last_error||'');
  renderMaster(c); renderLivePower(c); renderEstop(c);
  await imgStatusLoad(); renderProviders(c); renderImgKeys(c);   // живая подсветка ключей картинок
  intervalWarn();   // предупреждение зависит от активного провайдера — обновляем
}
async function newsCfgSave(){
  const j = await newsApi({
    action:'config_save',
    models: newsModels.join(','),
    sources: document.getElementById('n_sources').value,
    interval: document.getElementById('n_interval').value,
    per_run: document.getElementById('n_perrun').value,
    rank_keep: document.getElementById('n_keep').value,
    rpd_limit: document.getElementById('n_rpd').value,
    news_keep: document.getElementById('n_news_keep').value,
    feed_per_cat: document.getElementById('n_feed_per_cat').value,
    market_options: document.getElementById('n_market_options').value,
    auto_publish: document.getElementById('n_pub').checked?'1':'0',
  });
  if (j.config){ lastCfg = j.config; renderModels(j.config); showNewsErr(j.config.last_error||''); }
  log(j.ok ? '💾 настройки новостей сохранены' : '✖ ошибка сохранения');
}
async function newsFetch(){ log('⬇ собираю новости…'); const j = await newsApi({action:'news_fetch'}); log(`📰 новостей: +${j.added||0}, всего ${j.total||0}`); newsRefresh(); }
async function news2event(id){
  log('🤖 генерирую событие из новости…');
  const j = await newsApi({action:'news_to_event', id});
  if (j.ok){ log('✔ ['+(j.model||'')+'] '+j.event.question); showNewsErr(''); refreshPreview(); }
  else     { log('✖ '+(j.error||'ошибка')); showNewsErr(j.error||'не удалось сгенерировать событие'); }
  newsStatusRefresh();   // подтянуть подсветку моделей
  newsRefresh();
}
async function newsReject(id){ await newsApi({action:'news_status', id, status:'rejected'}); newsRefresh(); }
async function newsClearAll(){
  if(!confirm('Очистить очередь новостей? (события в ленте останутся)')) return;
  await newsApi({action:'news_clear'});
  log('🗑 очередь новостей очищена');
  newsRefresh();
}

/* ---------- расход Gemini (реальные запросы и токены за сегодня) ---------- */
function kfmt(n){ n=+n||0; return n>=1000 ? (n/1000).toFixed(n>=100000?0:1)+'k' : n; }
async function usageRefresh(){
  const u = ((await newsApi({action:'usage_get'}).catch(()=>({}))).usage)||null;
  const el = document.getElementById('usage_line'); if(!el) return;
  if(!u){ el.textContent='📊 расход недоступен'; return; }
  const t = u.today||{};
  const lim = u.limit||500, used = t.requests||0, left = Math.max(0, lim-used);
  const pct = Math.min(100, Math.round(used/lim*100));
  const barcls = pct>=90 ? 'red' : (pct>=70 ? 'orange' : '');
  const reset = nextPtReset();
  const p = n => String(n).padStart(2,'0');
  const hrsLeft = Math.max(0, Math.round((reset - new Date())/3600000));
  const resetStr = `${p(reset.getDate())}.${p(reset.getMonth()+1)} ${p(reset.getHours())}:${p(reset.getMinutes())}`;
  el.innerHTML =
      `<div class="usage-head">📊 Запросов к Gemini за сутки: `
    + `<b>${used}</b> из <b>${lim}</b> · осталось <b class="${left<=0?'zero':''}">${left}</b></div>`
    + `<div class="usage-bar"><div class="uf ${barcls}" style="width:${pct}%"></div></div>`
    + `<div class="um" style="margin-top:5px">оценка новостей ${t.rank||0} · создано событий ${t.event||0} · ≈ ${kfmt(t.total)} токенов`
    + `  ·  🔄 сброс в 00:00 по тихоокеанскому (как у Google) — след. <b>${resetStr}</b> по твоему времени (через ~${hrsLeft} ч)</div>`;
}
/* момент следующего сброса дневного лимита Google = полночь по America/Los_Angeles, в реальном времени */
function nextPtReset(){
  const now = new Date();
  const la = new Date(now.toLocaleString('en-US', {timeZone:'America/Los_Angeles'}));
  const laMidnight = new Date(la); laMidnight.setHours(24,0,0,0);
  const offset = now.getTime() - la.getTime();   // разница между реальным и LA-представлением
  return new Date(laMidnight.getTime() + offset);
}

/* ---------- сроки событий по категориям (число + единица) ---------- */
let catList = [];
/* код категории -> человеческое название (для отображения в админке) */
function catLabel(code){
  const c = (catList||[]).find(x => x.code === code);
  return c ? c.label : code;
}
const TF_UNITS = [['m','минут'],['h','часов'],['d','дней'],['w','недель'],['M','месяцев'],['y','лет']];
function parseTf(code){ const m=String(code||'').match(/^(\d+)\s*([smhdwMy])$/); return m?{n:+m[1],u:(m[2]==='s'?'m':m[2])}:{n:1,u:'M'}; }
function tfRange(v, d){
  d = d || {min:'1w',max:'3M'};
  if(v && typeof v==='object') return {min:v.min||d.min, max:v.max||d.max};
  if(typeof v==='string' && v) return {min:v, max:v};   // старый формат
  return d;
}
function tfPicker(code, bound, sel){
  const {n,u} = parseTf(sel);
  const opts = TF_UNITS.map(([val,lbl])=>`<option value="${val}"${val===u?' selected':''}>${lbl}</option>`).join('');
  return `<input type="number" class="ctf-n" data-code="${esc(code)}" data-b="${bound}" value="${n}" min="1" max="999">`
       + `<select class="ctf-u" data-code="${esc(code)}" data-b="${bound}">${opts}</select>`;
}
async function renderCatTf(){
  const box = document.getElementById('cat_tf_list'); if(!box) return;
  const resp = (await newsApi({action:'config_get'}).catch(()=>({}))) || {};
  const cur = (resp.config && resp.config.cat_timeframe) || {};
  const def = resp.default_cat_timeframe || {};
  if(!catList.length){ catList = ((await api({action:'meta'}).catch(()=>({}))).categories) || []; }
  const aiCats = catList.filter(c=>(c.type||'ai')==='ai');   // сроки настраиваем только у ИИ-категорий
  box.innerHTML = aiCats.length ? aiCats.map(c=>{
    const r = tfRange(cur[c.code], def[c.code]);
    return `<div class="cat-tf-row">
      <div class="ctf-label"><b>${esc(c.label)}</b> <span class="um">${esc(c.group||'')}</span></div>
      <span class="ctf-lbl">от</span>${tfPicker(c.code,'min',r.min)}
      <span class="ctf-lbl">до</span>${tfPicker(c.code,'max',r.max)}
    </div>`;
  }).join('') : '<div class="empty" style="padding:10px 0">Категорий нет.</div>';
}
async function catTfSave(){
  const map = {};
  document.querySelectorAll('#cat_tf_list .cat-tf-row').forEach(row=>{
    const code = row.querySelector('.ctf-n')?.dataset.code; if(!code) return;
    const get = b => {
      const nEl = row.querySelector(`.ctf-n[data-b="${b}"]`), uEl = row.querySelector(`.ctf-u[data-b="${b}"]`);
      return (Math.max(1, parseInt(nEl.value,10)||1)) + uEl.value;
    };
    map[code] = {min:get('min'), max:get('max')};   // {min:"1w", max:"3M"}
  });
  const j = await newsApi({action:'config_save', cat_timeframe: JSON.stringify(map)});
  log(j.ok ? '💾 сроки по категориям сохранены' : '✖ ошибка сохранения сроков');
}
async function catTfReset(){
  if(!confirm('Вернуть сроки по категориям к значениям по умолчанию?')) return;
  await newsApi({action:'config_save', cat_timeframe: JSON.stringify({})});
  renderCatTf();
  log('↩ сроки по категориям сброшены к дефолтным');
}

/* ---------- Лайв-каналы (категория × интервал, именованные) ---------- */
const LIVE_IVLBL = {'5m':'5 минут','15m':'15 минут','30m':'30 минут','1h':'1 час','4h':'4 часа','1d':'1 день','1w':'неделя','1M':'месяц','1y':'год'};
let livePools = {};
async function renderLive(){
  const box = document.getElementById('live_block'); if(!box) return;
  if (!Object.keys(livePools).length){ livePools = ((await api({action:'pools'}).catch(()=>({}))).pools) || {}; }
  let list = ((await api({action:'sched_list'}).catch(()=>({}))).schedules) || [];
  let market = list.filter(s=>s.market);
  if (!market.length){   // авто-создание каналов при первом заходе
    await api({action:'markets_seed'});
    market = (((await api({action:'sched_list'}).catch(()=>({}))).schedules)||[]).filter(s=>s.market);
  }
  const byCat = {};
  market.forEach(s=>{ (byCat[s.category]=byCat[s.category]||[]).push(s); });
  box.innerHTML = Object.keys(byCat).map(cat=>{
    const assets = (livePools[cat]||[]);
    const assetsHtml = assets.length ? `<div class="live-assets">📦 активы: ${esc(assets.join(', '))}</div>` : '';
    const rows = byCat[cat].map(s=>{
      const mins = Math.max(1, Math.round((s.interval||3600)/60));
      const on = !!s.active;
      return `<div class="live-row${on?' on':''}" data-id="${s.id}">
        <button type="button" class="live-tgl" title="вкл/выкл" onclick="liveToggle('${s.id}')">${on?'🟢':'⚪'}</button>
        <div class="live-name">Лайв ${esc(catLabel(cat))} · ${esc(LIVE_IVLBL[s.timeframe]||s.timeframe)}</div>
        <label class="ag-f">выдача раз в <input type="number" class="live-every" value="${mins}" min="1" onchange="liveUpdate('${s.id}')"> мин</label>
        <label class="ag-f"><input type="number" class="live-count" value="${s.count||1}" min="1" max="20" onchange="liveUpdate('${s.id}')"> прогн.</label>
      </div>`;
    }).join('');
    return `<details class="ag-cat" open><summary>${esc(catLabel(cat))} <span class="um" style="font-weight:400;color:var(--muted)">(${byCat[cat].length})</span></summary>${assetsHtml}${rows}</details>`;
  }).join('');
  // чекбокс «Все категории» = отмечен, если ВСЕ каналы включены
  const allOn = market.length > 0 && market.every(s => !!s.active);
  const cb = document.getElementById('live_all_cb'); if(cb) cb.checked = allOn;
  renderLivePower(lastCfg);
}
async function liveAll(on){
  if(on && !confirm('Включить ВСЕ лайв-каналы? Короткие интервалы (5м/15м) дадут много событий.')) return;
  await api({action:'markets_active', on: on?'1':'0'});
  renderLive();
  log(on ? '▶ включены все лайв-каналы' : '⏸ выключены все лайв-каналы');
}

/* ---- предупреждение о слишком коротком интервале при медленном провайдере ---- */
function intervalWarn(){
  const el = document.getElementById('n_interval'); if(!el) return;
  const warn = document.getElementById('n_interval_warn'); if(!warn) return;
  const v = parseInt(el.value, 10) || 0;
  // «медленный» = сейчас реально генерит Pollinations (или он выбран активным и другого нет)
  const slow = (imgLastProvider === 'pollinations') || (!imgLastProvider && lastCfg && lastCfg.img_provider === 'pollinations');
  if (v < 120 && slow){
    warn.style.display = 'block';
    warn.innerHTML = '⚠ Интервал меньше 120с, а сейчас работает медленный провайдер картинок (Pollinations): изображения не будут успевать генерироваться, лента может тормозить и грузиться рывками. Рекомендуется ≥120с (или подключи/дождись Cloudflare).';
  } else {
    warn.style.display = 'none';
  }
}

/* ---- ЭКСТРЕННЫЙ СТОП: выключает всю генерацию везде ---- */
function renderEstop(c){
  const b = document.getElementById('estop_btn'); if(!b) return;
  const paused = !!(c && c.paused);
  b.textContent = paused ? '▶ Возобновить' : '⛔ Экстренный стоп';
  b.classList.toggle('primary', paused);
  b.classList.toggle('danger', !paused);
  const banner = document.getElementById('estop_banner'); if(banner) banner.style.display = paused ? '' : 'none';
}
async function emergencyToggle(){
  const paused = !(lastCfg && lastCfg.paused);   // включаем паузу
  // МГНОВЕННАЯ реакция интерфейса — не ждём сервер (он может доигрывать текущий запрос)
  if(!lastCfg) lastCfg = {};
  lastCfg.paused = paused;
  renderEstop(lastCfg);
  log(paused ? '⛔ СТОП отправлен… сервер завершит текущий запрос и остановит остальное' : '▶ возобновляю…');
  try {
    const j = await newsApi({action:'config_save', paused: paused ? '1' : '0'});
    if (j.config){ lastCfg = j.config; renderEstop(j.config); }
    log(paused ? '⛔ генерация остановлена' : '▶ генерация возобновлена');
  } catch(e){ log('✖ команда не дошла (сервер занят) — она применится, как только он освободится'); }
}

/* ---- MASTER Старт/Стоп (вкладка «Новости и прогнозы») ---- */
function renderMaster(c){
  const b = document.getElementById('master_btn'); if(!b) return;
  const on = !!(c && c.auto && c.auto.active);
  b.textContent = on ? '⏸ Стоп' : '▶ Старт';
  b.classList.toggle('on', on);
}
async function masterToggle(){
  const on = !(lastCfg && lastCfg.auto && lastCfg.auto.active);
  // при СТАРТЕ применяем то, что показано в полях (кол-во за прогон и интервал) — что видишь, то и работает
  const body = {action:'config_save', active: on ? '1' : '0'};
  if (on){
    const pr = document.getElementById('n_perrun'); if (pr && pr.value) body.per_run = pr.value;
    const iv = document.getElementById('n_interval'); if (iv && iv.value) body.interval = iv.value;
  }
  const j = await newsApi(body);
  if (j.config){ lastCfg = j.config; renderMaster(j.config); }
  const cb = document.getElementById('n_active'); if(cb) cb.checked = on;
  if (on){
    log('▶ СТАРТ — включена ИИ-генерация из новостей, выпускаю события…');
    newsApi({action:'rank_now'}).then(()=>newsRefresh());   // ТОЛЬКО ИИ: новости → события
    api({action:'resolve'});                                 // закрыть просроченные (безвредно)
  } else {
    log('⏸ СТОП — ИИ-генерация из новостей выключена');
  }
}

/* ---- Старт/Стоп блока «Лайв-каналы» ---- */
function renderLivePower(c){
  const b = document.getElementById('live_power_btn'); if(!b) return;
  const on = !c || c.live_active === undefined ? true : !!c.live_active;
  b.textContent = on ? '✅ Лайв-каналы включены (выключить)' : '⭕ Лайв-каналы выключены (включить)';
  b.classList.toggle('on', on);
}
async function livePowerToggle(){
  const on = !(lastCfg && lastCfg.live_active !== false);   // текущее вкл? -> выключаем
  const j = await newsApi({action:'config_save', live_active: on ? '1' : '0'});
  if (j.config){ lastCfg = j.config; renderLivePower(j.config); }
  if (on){ api({action:'tick'}).then(()=>{ refreshTotal(); log('▶ генерация лайв-прогнозов включена и запущена'); }); }
  else { log('⏸ генерация лайв-прогнозов выключена'); }
}
async function liveToggle(id){ await api({action:'sched_toggle', id}); renderLive(); }
async function liveUpdate(id){
  const row = document.querySelector('.live-row[data-id="'+id+'"]'); if(!row) return;
  const every = Math.max(1, parseInt(row.querySelector('.live-every').value,10)||60) * 60;
  const count = Math.max(1, parseInt(row.querySelector('.live-count').value,10)||1);
  await api({action:'sched_update', id, interval:every, count});
}

/* ---------- тест генерации картинок (Pollinations) ---------- */
async function renderImgUsage(){
  const j = (await api({action:'img_usage_get'}).catch(()=>({}))) || {};
  const u = j.usage || {ok:0,fail:0};
  const el = document.getElementById('img_usage_line'); if(!el) return;
  const prov = (lastCfg && lastCfg.img_provider) || 'pollinations';
  el.innerHTML = `🖼 Картинок сегодня: <b>${u.ok||0}</b> успешно · <b class="${u.fail?'zero':''}">${u.fail||0}</b> ошибок`
    + ` <span class="um">(${esc(j.day||'')}) · провайдер: ${esc(prov)}</span>`;
}
function imgTpl(){ return (document.getElementById('img_prompt_tpl').value || '{q}').trim(); }
function imgBuildPrompt(q){ const t=imgTpl(); return t.includes('{q}') ? t.replace('{q}', q) : (q + ', ' + t); }
async function imgPromptSave(){
  const j = await newsApi({action:'config_save', img_prompt: document.getElementById('img_prompt_tpl').value});
  if (j.config) lastCfg = j.config;
  log(j.ok ? '💾 промпт картинок сохранён' : '✖ ошибка сохранения');
}
/* ---------- провайдеры генерации картинок ---------- */
const IMG_PROVIDERS = [
  {id:'cloudflare',   name:'Cloudflare',   note:'много разом · день'},
  {id:'together',     name:'Together AI',  note:'быстрее всех · пост.'},
  {id:'pollinations', name:'Pollinations', note:'без ключа · запасной'},
];
function renderProviders(c){
  const box = document.getElementById('img_providers'); if(!box) return;
  const active = (c && c.img_provider) || 'pollinations';   // выбранный (старт цепочки)
  const eff = imgEffProvider || imgLastProvider || active;    // кто РЕАЛЬНО будет генерить (по доступности ключей)
  box.innerHTML = IMG_PROVIDERS.map(p=>{
    let cls = 'chip prov', mark = '';
    if (p.id === eff){ cls += ' work'; mark = ' ● работает'; }     // зелёный = фактический исполнитель
    else if (p.id === active){ mark = ' • выбран'; }               // выбранный (но сейчас не он работает)
    return `<button type="button" class="${cls}" onclick="pickProvider('${p.id}')" title="${esc(p.note)}">${esc(p.name)}${mark}</button>`;
  }).join('');
  const el = document.getElementById('img_effective');
  if (el){
    if (imgEffProvider && imgEffProvider !== active)
      el.innerHTML = `⚡ Выбран <b>${esc(active)}</b>, но он в лимите/без ключей → работать будет <b style="color:#a6f0c0">${esc(imgEffProvider)}</b> (авто-фолбэк). Зелёный вернётся к выбранному, когда тот оживёт.`;
    else el.textContent = '';
  }
}
async function pickProvider(id){
  const j = await newsApi({action:'config_save', img_provider:id});
  if (j.config){ lastCfg = j.config; renderProviders(j.config); }
  log('🔌 провайдер картинок: ' + id);
}
/* --- мультиключи провайдеров картинок (несколько на каждого, с фолбэком) --- */
let imgKeyStatus = {};
let imgKeyPref = {};
let imgLastProvider = '';
let imgEffProvider = '';
async function imgStatusLoad(){
  try { const j = await newsApi({action:'img_status_get'}); imgKeyStatus = (j && j.status) || {}; imgKeyPref = (j && j.pref) || {}; imgLastProvider = (j && j.last_provider) || ''; imgEffProvider = (j && j.effective_provider) || ''; }
  catch(e){ imgKeyStatus = {}; imgKeyPref = {}; imgLastProvider = ''; imgEffProvider = ''; }
}
function imgKeyChip(provider, key, i){
  const raw  = (key && typeof key==='object') ? (key.token||'') : String(key||'');
  const tail = raw.trim().slice(-6);
  const st = imgKeyStatus[tail] || {};
  const isActive = Number(imgKeyPref[provider]) === i;   // ключ, который сейчас в работе
  let cls='chip key', mark='';
  if (st.state==='limit'){ cls+=' err'; mark=' ⛔ лимит'; }               // упал по лимиту -> красный
  else if (st.state==='error'){ cls+=' err'; mark=' ✕ ошибка'; }         // ошибка -> красный
  else { cls+=' work'; mark = isActive ? ' ● активный' : ' ✓ готов'; }   // нет ошибки -> зелёный (рабочий)
  const label = (key && typeof key==='object')
    ? `${esc((key.account||'').slice(0,6))}… / ${esc(maskKey(raw))}`
    : esc(maskKey(raw));
  const tip = st.msg ? ' title="'+esc(st.msg)+'"' : '';
  return `<span class="${cls}"${tip}><span class="ml">🔑 ${label}${mark}</span><span class="mn">#${i+1}</span>`
    + `<button type="button" class="chip-x" title="удалить" onclick="imgKeyDel('${provider}',${i})">✕</button></span>`;
}
function renderImgKeys(c){
  const map = {cloudflare:['cf_keys','cf_chips'], together:['together_keys','together_chips']};
  for (const prov in map){
    const [field, boxId] = map[prov];
    const box = document.getElementById(boxId); if(!box) continue;
    const list = (c && c[field]) || [];
    box.innerHTML = list.length ? list.map((k,i)=>imgKeyChip(prov,k,i)).join('')
      : '<span class="um" style="font-size:11px">пока нет ключей</span>';
  }
}
async function imgKeyAdd(provider){
  const body = {action:'img_key_add', provider};
  if (provider==='cloudflare'){
    body.account = document.getElementById('cf_account').value.trim();
    body.key     = document.getElementById('cf_token').value.trim();
    if(!body.account || !body.key){ alert('Нужны Account ID и Token'); return; }
  } else {
    const el = document.getElementById(provider==='together'?'together_key':'segmind_key');
    body.key = el.value.trim();
    if(!body.key){ alert('Вставь ключ'); return; }
  }
  const j = await newsApi(body);
  if (j.ok && j.config){
    lastCfg = j.config; await imgStatusLoad(); renderImgKeys(j.config);
    if (provider==='cloudflare'){ document.getElementById('cf_account').value=''; document.getElementById('cf_token').value=''; }
    else document.getElementById(provider==='together'?'together_key':'segmind_key').value='';
    log('🔑 ключ добавлен: '+provider);
  } else log('✖ '+(j.error||'не удалось добавить ключ'));
}
async function imgKeyDel(provider, i){
  if(!confirm('Удалить этот ключ?')) return;
  const j = await newsApi({action:'img_key_del', provider, idx:i});
  if(j.config){ lastCfg=j.config; renderImgKeys(j.config); }
  log('🔑 ключ удалён: '+provider);
}
async function imgKeysReset(){
  await newsApi({action:'img_keys_reset'});
  imgKeyStatus = {}; renderImgKeys(lastCfg||{});
  log('↻ статусы ключей картинок сброшены');
}
function imgProg(html){ const el=document.getElementById('img_progress'); if(el){ el.style.display='block'; el.innerHTML=html; } }
function progBar(pct){ return `<div class="usage-bar" style="margin-top:6px"><div class="uf" style="width:${pct}%"></div></div>`; }
async function imgTest(){
  const q = (document.getElementById('img_prompt').value || 'переговоры лидеров стран').trim();
  const p = imgBuildPrompt(q);
  const box = document.getElementById('img_preview');
  const ph = document.createElement('div'); ph.className='gimg'; ph.textContent='⏳'; box.prepend(ph);
  const t0 = Date.now();
  const tick = setInterval(()=> imgProg(`⏳ рисую картинку… <b>${((Date.now()-t0)/1000).toFixed(1)} c</b>` + progBar(100)), 100);
  const j = await api({action:'img_gen', prompt:p});
  clearInterval(tick);
  const sec = ((Date.now()-t0)/1000).toFixed(1);
  const prov = j.provider ? ` · ${j.provider}` : '';
  if(j.ok){ ph.innerHTML = `<img src="${esc(j.url)}" title="${esc(p)}">`; imgProg(`✅ ${j.format||''} за <b>${sec} c</b>${prov} (сервер ${j.ms||'?'}мс)`); }
  else { ph.textContent='✖'; ph.title = j.error||''; imgProg(`✖ ошибка за ${sec} c${prov}: ${esc(j.error||'')}`); }
  renderImgUsage();
}
async function imgStress(n){
  if(!confirm(`Сгенерировать ${n} картинок подряд для замера? Может занять время.`)) return;
  const p0 = (document.getElementById('img_prompt').value || 'новость прогноз').trim();
  const box = document.getElementById('img_preview');
  let ok=0, fail=0; const T0=Date.now();
  log(`⚡ тест генерации ×${n}…`);
  for(let i=0;i<n;i++){
    const t0=Date.now();
    const tick=setInterval(()=> imgProg(`⏳ картинка <b>${i+1}/${n}</b> · эта ${((Date.now()-t0)/1000).toFixed(1)}c · всего ${Math.round((Date.now()-T0)/1000)}c · ✅${ok} ✖${fail}` + progBar(Math.round(i/n*100))), 100);
    const j = await api({action:'img_gen', prompt:imgBuildPrompt(p0+' '+(i+1))});
    clearInterval(tick);
    if(j.ok){ ok++; const d=document.createElement('div'); d.className='gimg'; d.innerHTML=`<img src="${esc(j.url)}">`; box.prepend(d); }
    else fail++;
    renderImgUsage();
  }
  const sec = Math.round((Date.now()-T0)/1000);
  imgProg(`✅ готово: <b>${ok}</b> ок, <b>${fail}</b> ошибок за <b>${sec}c</b> (${(ok/Math.max(1,sec)).toFixed(2)} карт/сек)` + progBar(100));
  log(`⚡ готово: ${ok} ок, ${fail} ошибок за ${sec}с`);
}

/* ---------- галерея логотипов ---------- */
let logosList = [];
async function renderLogos(){
  const box = document.getElementById('logos_grid'); if(!box) return;
  const j = await api({action:'logos_list'}).catch(()=>({}));
  logosList = (j && j.logos) || [];
  const cnt = document.getElementById('logos_count'); if(cnt) cnt.textContent = logosList.length;
  box.innerHTML = logosList.length
    ? logosList.map((l,i)=>`<div class="logo-card lm-click" onclick="openLogoFromList(${i})" title="нажми — заменить или удалить"><span class="logo-thumb"><img src="${esc(l.url)}" alt="" loading="lazy"></span><span class="logo-name">${esc(l.name)}</span></div>`).join('')
    : '<div class="empty" style="padding:12px 0">Логотипов нет.</div>';
  logoSuggest();
}
function openLogoFromList(i){ const l = logosList[i]; if(l) openLogoModal(l.name, l.url, false); }

/* ---- модалка логотипа: заменить (5 вариантов) / удалить / создать новый ---- */
let logoModalName = '';
let logoModalMode = 'logo';   // 'logo' | 'placeholder'
function openLogoModal(name, url, isNew){
  logoModalMode = 'logo';
  logoModalName = name;
  document.getElementById('lm_title').textContent = 'Логотип: ' + name;
  document.getElementById('lm_current').innerHTML = url
    ? `<div class="lm-cur"><img src="${esc(url)}" alt=""><span class="um">текущий логотип</span></div>`
    : '<div class="um" style="font-size:12px">логотипа ещё нет — сгенерируй варианты и выбери</div>';
  document.getElementById('lm_del').style.display = url ? '' : 'none';
  document.getElementById('lm_variants').innerHTML = '';
  const st = document.getElementById('lm_status'); st.style.display = 'none';
  document.getElementById('logo_modal').style.display = 'flex';
}
/* режим ЗАГЛУШКИ — та же модалка, но применяется как общая картинка-заглушка */
function openPlaceholderModal(){
  logoModalMode = 'placeholder';
  logoModalName = 'abstract neutral news image placeholder, soft gradient, minimal, no text';
  const cur = (lastCfg && lastCfg.placeholder_img) || '';
  document.getElementById('lm_title').textContent = 'Заглушка (пока грузится картинка)';
  document.getElementById('lm_current').innerHTML = cur
    ? `<div class="lm-cur"><img src="${esc(cur)}" alt=""><span class="um">текущая заглушка</span></div>`
    : '<div class="um" style="font-size:12px">заглушки нет — сгенерируй варианты и выбери</div>';
  document.getElementById('lm_del').style.display = 'none';
  document.getElementById('lm_variants').innerHTML = '';
  document.getElementById('lm_status').style.display = 'none';
  document.getElementById('logo_modal').style.display = 'flex';
  logoVariants();
}
function closeLogoModal(){ document.getElementById('logo_modal').style.display = 'none'; logoModalMode='logo'; renderLogos(); }
async function logoVariants(){
  const st = document.getElementById('lm_status'); st.style.display = 'block'; st.textContent = '⏳ генерирую 5 вариантов… (несколько секунд)';
  const box = document.getElementById('lm_variants'); box.innerHTML = '';
  const j = await api({action:'logo_variants', name: logoModalName, count: 5});
  if(!j.ok){ st.textContent = '✖ ' + (j.error || 'не удалось сгенерировать'); return; }
  st.style.display = 'none';
  box.innerHTML = (j.variants||[]).map(u=>
    `<div class="logo-card lm-pick" onclick="logoApply('${esc(u)}')" title="выбрать этот"><span class="logo-thumb"><img src="${esc(u)}" alt=""></span><span class="logo-name">выбрать ✓</span></div>`
  ).join('');
}
async function logoApply(url){
  const file = url.split('/').pop();
  if (logoModalMode === 'placeholder'){
    const j = await api({action:'placeholder_apply', file});
    if(j.ok){ if(lastCfg) lastCfg.placeholder_img = 'data/logos/_placeholder.webp'; log('🖼 заглушка сохранена'); closeLogoModal(); renderPlaceholder(lastCfg); }
    else log('✖ ' + (j.error || 'ошибка сохранения заглушки'));
    return;
  }
  const j = await api({action:'logo_apply', name: logoModalName, file});
  if(j.ok){ log('🎨 логотип сохранён: ' + logoModalName); closeLogoModal(); }
  else log('✖ ' + (j.error || 'ошибка сохранения логотипа'));
}
/* превью текущей заглушки в блоке логотипов */
function renderPlaceholder(c){
  const img = document.getElementById('ph_preview'); const none = document.getElementById('ph_none');
  if(!img) return;
  const url = (c && c.placeholder_img) || '';
  if(url){ img.src = url + '?t=' + Date.now(); img.style.display = ''; if(none) none.style.display='none'; }
  else { img.style.display='none'; if(none) none.style.display=''; }
}
async function logoDelCurrent(){
  if(!confirm('Удалить логотип «' + logoModalName + '»?')) return;
  await api({action:'logo_del', name: logoModalName});
  log('🗑 логотип удалён: ' + logoModalName);
  closeLogoModal();
}
function logoAddNew(){
  const inp = document.getElementById('logo_new_name'); const name = (inp.value||'').trim();
  if(!name){ alert('Впиши название (например: Пшеница)'); return; }
  inp.value = '';
  openLogoModal(name, '', true);
  logoVariants();   // сразу генерим варианты
}
/* подсказки: слова из ответов без логотипа */
let logoSuggestions = [];
async function logoSuggest(){
  const box = document.getElementById('logo_suggest'); if(!box) return;
  const j = await api({action:'logo_suggest'}).catch(()=>({}));
  logoSuggestions = (j && j.suggestions) || [];
  box.innerHTML = logoSuggestions.length
    ? logoSuggestions.map((x,i)=>`<button type="button" class="chip" onclick="logoFromSuggest(${i})">➕ ${esc(x.label)} <span class="um">×${x.count}</span></button>`).join('')
    : '<span class="um" style="font-size:12px">нет предложений (нужны события с текстовыми ответами)</span>';
}
function logoFromSuggest(i){ const x = logoSuggestions[i]; if(!x) return; openLogoModal(x.label, '', true); logoVariants(); }
async function logoPromptSave(){
  const j = await newsApi({action:'config_save', logo_prompt: document.getElementById('logo_prompt_tpl').value});
  if (j.config) lastCfg = j.config;
  log(j.ok ? '💾 промпт логотипов сохранён' : '✖ ошибка сохранения');
}

/* ---------- рынки (код-генерация) ---------- */
const MARKET_IVS = [['5m','5 мин'],['15m','15 мин'],['30m','30 мин'],['1h','1 час'],['4h','4 часа'],['1d','1 день'],['1w','неделя'],['1M','месяц']];
async function renderMarketOptsIv(){
  const box = document.getElementById('market_opts_iv'); if(!box) return;
  const c = ((await newsApi({action:'config_get'}).catch(()=>({}))).config) || {};
  const iv = c.market_opts_iv || {};
  box.innerHTML = MARKET_IVS.map(([k,l])=>{
    const v = (iv[k]!==undefined) ? iv[k] : 0;
    return `<label style="display:flex;flex-direction:column;gap:3px;font-size:11px;color:var(--muted);align-items:center">${l}
      <input type="number" class="mo-iv" data-iv="${k}" value="${v}" min="0" max="9" style="width:64px;padding:6px 8px;border-radius:8px;border:1px solid var(--line);background:var(--panel2);color:var(--text);text-align:center"></label>`;
  }).join('');
}
async function marketOptsIvSave(){
  const map = {};
  document.querySelectorAll('#market_opts_iv .mo-iv').forEach(inp=>{ const n=parseInt(inp.value,10)||0; if(n>=2) map[inp.dataset.iv]=n; });
  const j = await newsApi({action:'config_save', market_opts_iv: JSON.stringify(map)});
  log(j.ok ? '💾 варианты по интервалам сохранены' : '✖ ошибка');
}
async function renderMarketsCats(){
  const box = document.getElementById('markets_cats'); if(!box) return;
  if(!catList.length){ catList = ((await api({action:'meta'}).catch(()=>({}))).categories) || []; }
  const codeCats = catList.filter(c=>(c.type||'ai')==='code');
  box.innerHTML = codeCats.map(c=>`<span class="chip" style="cursor:default">${esc(c.label)}</span>`).join('')
    || '<span class="um">нет код-категорий</span>';
}
async function marketsSeed(){
  const j = await api({action:'markets_seed'});
  log(j.ok ? `⚙️ рынки заведены: ${j.market} расписаний (выключены). Нажми «▶ Включить все».` : '✖ ошибка');
  agRefresh();
}
async function marketsActive(on){
  if(on && !confirm('Включить ВСЕ рыночные расписания? Короткие интервалы дают много событий.')) return;
  const j = await api({action:'markets_active', on: on?'1':'0'});
  log(on ? `▶ рынки включены (${j.count||0} расписаний)` : `⏸ рынки выключены (${j.count||0})`);
  agRefresh();
}

/* ---------- критерии важности + ранжирование ---------- */
async function rankSave(){
  const j = await newsApi({action:'config_save', rank_prompt: document.getElementById('n_rank').value});
  if (j.config) lastCfg = j.config;
  log(j.ok ? '💾 критерии важности сохранены' : '✖ ошибка сохранения критериев');
}
/* каждое правило — с новой строки (после точки), чтобы удобно править */
function rulesToLines(txt){
  return String(txt||'').replace(/\r/g,'').replace(/\n+/g,' ').replace(/\.\s+/g,'.\n').trim();
}
async function genRulesSave(){
  const el = document.getElementById('n_gen');
  el.value = rulesToLines(el.value);   // причёсываем перед сохранением
  const j = await newsApi({action:'config_save', gen_prompt: el.value});
  if (j.config) lastCfg = j.config;
  log(j.ok ? '💾 правила генерации сохранены' : '✖ ошибка сохранения');
}
function rankReset(){
  if(!rankDefault){ return; }
  if(!confirm('Вернуть критерии важности по умолчанию?')) return;
  document.getElementById('n_rank').value = rankDefault;
  rankSave();
}
async function rankNow(){
  log('🔎 оцениваю важность и отбираю…');
  const j = await newsApi({action:'rank_now'});
  if (j.rank){
    renderRankNums(j.rank);
    log(`✔ отобрано важных: ${j.rank.important}, стало событиями: ${j.rank.made}`);
    openRankBox('important', true);   // сразу показать, что отобрал
    refreshPreview();
  } else log('✖ ошибка прогона');
}
function rankTagLabel(s){
  return s==='event' ? ['ev','✅ в событие']
       : s==='skipped' ? ['q','⊘ пропущено']
       : s==='error' ? ['q','⚠ ошибка']
       : ['q','⏳ в очереди'];
}
/* только цифры в окнах + баннер ошибки (список — по клику на окно) */
function renderRankNums(r){
  r = r || {};
  const g = (id,v)=>{ const el=document.getElementById(id); if(el) el.textContent=v; };
  g('rb_found', r.found||0); g('rb_important', r.important||0); g('rb_made', r.made||0);
  const err = document.getElementById('rank_err');
  if(err){ if(r.error){ err.style.display='block'; err.textContent='⚠ '+r.error; } else { err.style.display='none'; } }
}
/* ---- клик по окну открывает соответствующий список (тумблер) ---- */
let rankOpen = null;
function markActiveBox(){
  ['found','important','pending','events'].forEach(k=>{
    const b=document.getElementById('box_'+k); if(b) b.classList.toggle('active', rankOpen===k);
  });
}
async function openRankBox(kind, force){
  const box = document.getElementById('rank_detail'); if(!box) return;
  if(rankOpen===kind && !force){ rankOpen=null; box.style.display='none'; box.innerHTML=''; markActiveBox(); return; }
  rankOpen = kind; markActiveBox();
  box.style.display='block';
  box.innerHTML = '<div class="empty" style="padding:12px 0">⏳ загрузка…</div>';
  box.innerHTML = await renderRankDetail(kind);
}
async function renderRankDetail(kind){
  if(kind==='found'){
    const list = (await newsApi({action:'news_list'}).catch(()=>({}))).news || [];
    if(!list.length) return '<div class="empty" style="padding:12px 0">Очередь пуста — нажми «⬇ Собрать новости».</div>';
    const rows = list.map((n,i)=>`<div class="rd-row"><span class="rd-i">${i+1}</span>
      <span style="flex:1;min-width:0">${esc(n.title||'')}</span>
      <span class="rd-st">${esc(n.source||'')} · ${esc(n.status||'')}</span></div>`).join('');
    return `<div class="rank-detail-wrap"><div class="rd-title">Все собранные новости (сырьё): ${list.length}</div>${rows}</div>`;
  }
  if(kind==='important'){
    const r = (await newsApi({action:'rank_get'}).catch(()=>({}))).rank || {};
    const sel = r.selected || [];
    if(!sel.length) return '<div class="empty" style="padding:12px 0">Пока пусто. Нажми «🔎 Прогнать сейчас» или дождись авто-прогона.</div>';
    const rows = sel.map(x=>{
      const sc=x.score||0, cls=sc>=8?'hi':(sc<=4?'lo':''), [tcls,tlabel]=rankTagLabel(x.status);
      const src=x.source?` · ${esc(x.source)}`:'';
      return `<div class="rank-row ${cls}"><div class="rk-score">${sc}</div>
        <div class="rk-main"><div class="rk-title">${esc(x.title||'')}</div>
        <div class="rk-meta">${esc(x.reason||'')}${src}</div></div>
        <span class="rk-tag ${tcls}">${tlabel}</span></div>`;
    }).join('');
    return `<div class="rank-detail-wrap"><div class="rd-title">Отобрано по важности (${sel.length}), от самой важной:</div>${rows}</div>`;
  }
  if(kind==='pending'){   // «будет событием» — само сгенерированное событие, ждёт картинки
    const list = (await api({action:'pending_list'}).catch(()=>({}))).pending || [];
    if(!list.length) return '<div class="empty" style="padding:12px 0">Пусто — все готовые прогнозы уже с картинками и в ленте.</div>';
    const rows = list.map(e=>{
      const catl = esc(e.category_label||catLabel(e.category)||'');
      const opts = (e.options||[]).map(o=>esc(o.label)).join(' · ') || 'ДА / НЕТ';
      const rd = e.img_ready||0, tot = e.img_total||0;
      // если картинка-заголовок уже готова — показываем её, иначе заглушку
      const thumb = e.thumb
        ? `<span class="ev-thumb"><img src="${esc(e.thumb)}" loading="lazy" alt="" onerror="this.replaceWith(document.createTextNode('🖼'))"></span>`
        : `<span class="ev-thumb ph">🖼</span>`;
      // картинки ВАРИАНТОВ ответа (для открытых вопросов): готовые — миниатюрой, ещё рисуются — ⏳.
      // Видно, что именно дорисовывается, а не только счётчик.
      const optImgs = (e.options||[]).filter(o=>o && (o.image_en || o.logo));
      const optStrip = optImgs.length
        ? `<div class="opt-thumbs">` + optImgs.map(o=>{
            const rdy = o.logo || o.thumb;
            const cell = o.logo
              ? `<img src="${esc(o.logo)}" loading="lazy" alt="" onerror="this.replaceWith(document.createTextNode('🖼'))">`
              : (o.thumb
                  ? `<img src="${esc(o.thumb)}" loading="lazy" alt="" onerror="this.replaceWith(document.createTextNode('⏳'))">`
                  : `<span class="ot-ph" title="рисуется…">⏳</span>`);
            return `<span class="ev-opt-thumb${rdy?' rdy':''}" title="${esc(o.label||'')}">${cell}<em>${esc(o.label||'')}</em></span>`;
          }).join('') + `</div>`
        : '';
      return `<div class="rank-row">
        ${thumb}
        <div class="rk-main"><div class="rk-title">${esc(e.question||'')}</div><div class="rk-meta">${catl} · ${opts}</div>${optStrip}</div>
        <span class="rk-tag q">⏳ картинки ${rd}/${tot}</span></div>`;
    }).join('');
    return `<div class="rank-detail-wrap"><div class="rd-title">Будет событием — ждут генерации картинок (${list.length}). Уйдут в ленту, когда картинки готовы 100%:</div>${rows}</div>`;
  }
  // events — реально опубликованные СОБЫТИЯ-прогнозы (из ленты), с превью картинки
  const evs = (((await api({action:'list'}).catch(()=>({}))).events)||[]).filter(e=>e.source==='news').slice(0,30);
  if(!evs.length) return '<div class="empty" style="padding:12px 0">Событий-прогнозов пока нет в ленте.</div>';
  const rows = evs.map(e=>{
    const catl = esc(e.category_label||catLabel(e.category)||'');
    const opts = (e.options||[]).map(o=>esc(o.label)).join(' · ') || 'ДА / НЕТ';
    return `<div class="rank-row hi">
      <span class="ev-thumb">${eventThumb(e)}</span>
      <div class="rk-main"><div class="rk-title">${esc(e.question||'')}</div><div class="rk-meta">${catl} · ${opts}</div></div>
      <span class="rk-tag ev">✅ в ленте</span></div>`;
  }).join('');
  return `<div class="rank-detail-wrap"><div class="rd-title">Стало событием — в ленте (${evs.length}). <a href="index.html" target="_blank" style="color:#a9c7ff">👁 открыть ленту</a></div>${rows}</div>`;
}
/* превью картинки события (как на ленте): лого -> фото по image_en -> бейдж */
function eventThumb(e){
  let src = '';
  if (e.logo) src = e.logo;
  else if (e.image_en && typeof pollUrl === 'function') src = pollUrl(String(e.image_en).trim(), e.id, 128);
  else src = e.image || '';
  if (!src) return '🖼';
  return `<img src="${esc(src)}" loading="lazy" alt="" onerror="this.replaceWith(document.createTextNode('🖼'))">`;
}
async function newsRefresh(){
  const j = (await newsApi({action:'rank_get'}).catch(()=>({}))) || {};
  renderRankNums(j.rank || {});   // найдено/важных/стало событиями — всё из последнего прогона
  // живой счётчик «будет событием» (ждут генерации картинки)
  try { const p = await api({action:'pending_list'}); const el=document.getElementById('rb_pending'); if(el) el.textContent = (p && p.count) || 0; } catch(e){}
  if(rankOpen){ const box=document.getElementById('rank_detail'); if(box && box.style.display!=='none') box.innerHTML = await renderRankDetail(rankOpen); }
}

/* ---------- init ---------- */
(async () => {
  const meta = await api({action:'meta'}).catch(()=>({}));
  if (meta.categories && meta.categories.length) catList = meta.categories;   // для catLabel()
  buildCats(meta.categories || []);
  const tfs = meta.timeframes || [];
  renderTfChips(tfs);
})();
refreshTotal();
catRefresh();
/* ---- боковое меню: переключение секций ---- */
function showSection(id){
  document.querySelectorAll('.admin-section').forEach(s => s.classList.toggle('active', s.id === id));
  document.querySelectorAll('.nav-item').forEach(b => b.classList.toggle('active', b.dataset.sec === id));
  try { localStorage.setItem('adminSection', id); } catch(e){}
  window.scrollTo(0, 0);
}
function initSections(){
  let id = 'sec-news';
  try { const saved = localStorage.getItem('adminSection'); if (saved && document.getElementById(saved)) id = saved; } catch(e){}
  showSection(id);
}

initSections();
newsCfgLoad();
newsRefresh();
usageRefresh();
renderCatTf();
renderLive();
renderImgUsage();
renderLogos();
setInterval(() => { refreshTotal(); newsRefresh(); newsStatusRefresh(); usageRefresh(); }, 4000);
