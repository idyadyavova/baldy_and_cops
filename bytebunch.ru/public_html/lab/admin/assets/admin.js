/* Панель ByteBunch • Lab. Без внешних библиотек, кроме qrcode.js (лежит рядом).
   Политика безопасности панели запрещает встроенные скрипты, поэтому всё поведение — здесь, через data-атрибуты. */
(() => {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const csrf = () => ($('input[name="_csrf"]') || {}).value || '';

  /* загрузка файла с прогрессом; сервер отвечает JSON */
  function upload(url, data, onProgress) {
    return new Promise((resolve, reject) => {
      const x = new XMLHttpRequest();
      x.open('POST', url);
      x.setRequestHeader('X-CSRF-Token', csrf());
      x.setRequestHeader('X-Lab-Ajax', '1');
      x.responseType = 'json';
      if (onProgress) x.upload.onprogress = e => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
      x.onload = () => {
        const r = x.response || {};
        if (x.status >= 200 && x.status < 300 && !r.error) resolve(r); else reject(new Error(r.error || ('Ошибка ' + x.status)));
      };
      x.onerror = () => reject(new Error('Нет связи с сервером'));
      x.send(data);
    });
  }

  /* на телефоне вкладки прокручиваются — показываем текущую */
  const cur = $('.tabs [aria-current="page"]');
  if (cur && cur.parentElement.scrollWidth > cur.parentElement.clientWidth) cur.parentElement.scrollLeft = cur.offsetLeft - 16;

  /* подтверждение опасных действий */
  $$('form[data-confirm]').forEach(f => f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));

  /* не потерять несохранённый пост */
  $$('form[data-dirty-guard]').forEach(f => {
    let dirty = false;
    f.addEventListener('input', () => { dirty = true; });
    f.addEventListener('submit', () => { dirty = false; });
    addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  });

  /* кнопки «Копировать» */
  $$('[data-copy]').forEach(b => b.addEventListener('click', async () => {
    const src = $(b.dataset.copy);
    const text = src ? (src.value || src.textContent) : '';
    try { await navigator.clipboard.writeText(text); } catch (e) { if (src && src.select) { src.select(); document.execCommand('copy'); } }
    const was = b.textContent; b.textContent = 'Скопировано'; setTimeout(() => { b.textContent = was; }, 1400);
  }));
  $$('input[data-select-all]').forEach(i => i.addEventListener('focus', () => i.select()));

  /* выбор файла по кнопке */
  $$('[data-pick]').forEach(b => b.addEventListener('click', () => { const i = document.getElementById(b.dataset.pick); if (i) i.click(); }));

  /* редактор: разметка по кнопкам */
  const body = $('#body');
  function wrap(before, after, placeholder) {
    if (!body) return;
    const s = body.selectionStart, e = body.selectionEnd, v = body.value;
    const sel = v.slice(s, e) || placeholder || '';
    body.setRangeText(before + sel + after, s, e, 'end');
    if (!v.slice(s, e) && placeholder) body.setSelectionRange(s + before.length, s + before.length + sel.length);
    body.focus();
    body.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function linePrefix(prefix) {
    if (!body) return;
    const v = body.value, s = body.selectionStart, e = body.selectionEnd;
    const ls = v.lastIndexOf('\n', s - 1) + 1, le = v.indexOf('\n', e) === -1 ? v.length : v.indexOf('\n', e);
    const lines = v.slice(ls, le).split('\n').map(l => prefix + l).join('\n');
    body.setRangeText(lines, ls, le, 'end');
    body.focus();
    body.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function insertBlock(text) {
    if (!body) return;
    const v = body.value, s = body.selectionStart;
    const pre = s > 0 && v[s - 1] !== '\n' ? '\n\n' : (s > 1 && v[s - 2] !== '\n' ? '\n' : '');
    body.setRangeText(pre + text + '\n', s, body.selectionEnd, 'end');
    body.focus();
    body.dispatchEvent(new Event('input', { bubbles: true }));
  }
  const imgInput = $('#body-image');
  $$('[data-md]').forEach(b => b.addEventListener('click', () => {
    switch (b.dataset.md) {
      case 'h': linePrefix('## '); break;
      case 'b': wrap('**', '**', 'жирный'); break;
      case 'i': wrap('*', '*', 'курсив'); break;
      case 'link': { const u = prompt('Адрес ссылки', 'https://'); if (u) wrap('[', '](' + u.trim() + ')', 'текст ссылки'); break; }
      case 'ul': linePrefix('- '); break;
      case 'quote': linePrefix('> '); break;
      case 'code': wrap('`', '`', 'код'); break;
      case 'img': if (imgInput) imgInput.click(); break;
      case 'project': { const s = prompt('Адрес открытого проекта (как в разделе «Проекты»)'); if (s) insertBlock('{{project:' + s.trim() + '}}'); break; }
    }
  }));
  if (imgInput) imgInput.addEventListener('change', async () => {
    const f = imgInput.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('file', f);
    const mark = '![Загрузка ' + f.name + '…]()';
    insertBlock(mark);
    try {
      const r = await upload('media.php', fd);
      body.value = body.value.replace(mark, '![](' + r.url + ')');
    } catch (e) {
      body.value = body.value.replace(mark + '\n', '');
      alert(e.message);
    }
    imgInput.value = '';
    body.dispatchEvent(new Event('input', { bubbles: true }));
  });

  /* обложка поста */
  const coverFile = $('#cover-file'), cover = $('#cover'), coverPrev = $('#cover-prev');
  if (coverFile) coverFile.addEventListener('change', async () => {
    const f = coverFile.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('file', f);
    try {
      const r = await upload('media.php', fd);
      cover.value = r.url;
      coverPrev.hidden = false;
      coverPrev.querySelector('img').src = r.src;
      cover.dispatchEvent(new Event('input', { bubbles: true }));
    } catch (e) { alert(e.message); }
    coverFile.value = '';
  });
  $$('[data-cover-clear]').forEach(b => b.addEventListener('click', () => {
    cover.value = ''; coverPrev.hidden = true;
    cover.dispatchEvent(new Event('input', { bubbles: true }));
  }));

  /* загрузка проекта с прогрессом: форма отправляется фоном, сервер отвечает JSON {redirect} или {error} */
  $$('form[data-upload]').forEach(f => f.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = $('button[type="submit"]', f), bar = $('[data-progress]', f), out = $('[data-upload-error]', f);
    const fileInput = $('input[type="file"]', f);
    if (fileInput && fileInput.required && !fileInput.files.length) { fileInput.focus(); return; }
    btn.disabled = true; out.hidden = true;
    if (bar) { bar.hidden = false; bar.value = 0; }
    try {
      const r = await upload(f.getAttribute('action') || location.href, new FormData(f), p => { if (bar) bar.value = p; });
      location.href = r.redirect;
    } catch (err) {
      out.textContent = err.message; out.hidden = false;
      btn.disabled = false; if (bar) bar.hidden = true;
    }
  }));

  /* адрес проекта из названия, пока его не правили руками */
  const titleIn = $('input[data-slug-source]'), slugIn = $('input[data-slug-target]');
  if (titleIn && slugIn) {
    const tr = { а:'a',б:'b',в:'v',г:'g',д:'d',е:'e',ё:'e',ж:'zh',з:'z',и:'i',й:'y',к:'k',л:'l',м:'m',н:'n',о:'o',п:'p',р:'r',с:'s',т:'t',у:'u',ф:'f',х:'h',ц:'ts',ч:'ch',ш:'sh',щ:'sch',ъ:'',ы:'y',ь:'',э:'e',ю:'yu',я:'ya' };
    let touched = slugIn.value !== '';
    slugIn.addEventListener('input', () => { touched = slugIn.value !== ''; });
    titleIn.addEventListener('input', () => {
      if (touched) return;
      slugIn.value = titleIn.value.toLowerCase().split('').map(c => tr[c] ?? c).join('').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
    });
  }

  /* ширина столбиков статистики (встроенные style запрещены политикой безопасности) */
  $$('[data-w]').forEach(el => { el.style.width = Math.max(0, Math.min(100, parseFloat(el.dataset.w) || 0)) + '%'; });

  /* QR-код для приложения-аутентификатора */
  $$('[data-qr]').forEach(el => {
    if (typeof qrcode !== 'function') return;
    const q = qrcode(0, 'M'); q.addData(el.dataset.qr); q.make();
    el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
  });
})();
