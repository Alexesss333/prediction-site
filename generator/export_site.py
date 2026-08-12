#!/usr/bin/env python3
"""Выгрузка для сайта: картинки 70×70 и список, что к чему относится.

Разработчику нужны файлы и соответствие «вопрос — картинка», а не документ
Word, из которого их пришлось бы вытаскивать руками.

Запуск: python3 generator/export_site.py
"""

import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
STORE = ROOT / 'data' / 'imported.json'
OUT = ROOT / 'export_site'
SIZE = 70


def slug(name):
    """Имя папки как у присланного файла — заменяем только запрещённые символы."""
    name = name[:-5] if name.lower().endswith('.docx') else name
    return ''.join('_' if c in '/\\:*?"<>|' else c for c in name).strip()


def save(src_url, dest):
    src = ROOT / src_url
    if not src.is_file():
        return False
    im = Image.open(src).convert('RGB')
    # Исходники квадратные, но на всякий случай приводим к квадрату по центру.
    side = min(im.size)
    left, top = (im.width - side) // 2, (im.height - side) // 2
    im.crop((left, top, left + side, top + side)) \
      .resize((SIZE, SIZE), Image.LANCZOS) \
      .save(dest, 'WEBP', quality=88)
    return True


def esc(s):
    return (str(s).replace('&', '&amp;').replace('<', '&lt;')
            .replace('>', '&gt;').replace('"', '&quot;'))


def write_page(name, entries, dest):
    """Страница, где видно, какая картинка к какому вопросу и ответу.

    Имя файла подписано под каждой картинкой: по списку в коде разработчик
    находит нужную, не открывая json.
    """
    h = ['<!doctype html><html lang="ru"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1">',
         f'<title>{esc(name)}</title><style>',
         'body{margin:0;padding:24px;background:#eef1f5;color:#111;'
         'font:15px/1.45 -apple-system,Segoe UI,Roboto,sans-serif}',
         'h1{font-size:20px;margin:0 0 16px}',
         '.q{background:#fff;border-radius:12px;padding:16px;margin-bottom:14px;'
         'box-shadow:0 1px 3px rgba(0,0,0,.1)}',
         '.head{display:flex;gap:14px;align-items:center}',
         '.pic{text-align:center;flex:0 0 auto}',
         '.pic img{width:70px;height:70px;border-radius:8px;display:block;background:#dde3ea}',
         '.pic code{font-size:10px;color:#8a93a0;display:block;margin-top:3px}',
         '.head b{font-size:16px}',
         '.opts{display:flex;flex-wrap:wrap;gap:16px;margin:14px 0 0 0;padding-top:12px;'
         'border-top:1px solid #eceff3}',
         '.opt{width:118px;text-align:center;font-size:12px}',
         '.opt img{width:70px;height:70px;border-radius:8px;background:#dde3ea}',
         '.opt .none{width:70px;height:70px;border-radius:8px;background:#dde3ea;'
         'display:inline-flex;align-items:center;justify-content:center;color:#98a1ad;font-size:10px}',
         '.opt span{display:block;margin-top:5px}',
         '.opt code{font-size:10px;color:#8a93a0}',
         '</style></head><body>',
         f'<h1>{esc(name)} — вопросов: {len(entries)}</h1>']

    for n, it in enumerate(entries, 1):
        h.append('<div class="q"><div class="head">')
        if it['картинка']:
            h.append(f'<div class="pic"><img src="{it["картинка"]}" alt="">'
                     f'<code>{it["картинка"]}</code></div>')
        h.append(f'<b>{n}. {esc(it["вопрос"])}</b></div>')

        h.append('<div class="opts">')
        for oi in it['ответы']:
            h.append('<div class="opt">')
            if oi['картинка']:
                h.append(f'<img src="{oi["картинка"]}" alt="">')
            else:
                h.append('<div class="none">нет</div>')
            h.append(f'<span>{esc(oi["ответ"])}</span>')
            if oi['картинка']:
                h.append(f'<code>{oi["картинка"]}</code>')
            h.append('</div>')
        h.append('</div></div>')

    h.append('</body></html>')
    dest.write_text('\n'.join(h), encoding='utf-8')


def main():
    store = json.loads(STORE.read_text(encoding='utf-8'))

    batches = {}
    for e in store:
        batches.setdefault(e.get('batch_name') or e.get('batch') or '—', []).append(e)

    OUT.mkdir(exist_ok=True)
    total_pics = 0
    manifest = []

    for name, rows in batches.items():
        if any(not e.get('image_url') for e in rows):
            continue

        folder = OUT / slug(name)
        folder.mkdir(parents=True, exist_ok=True)
        entries = []

        for n, e in enumerate(rows, 1):
            item = {'вопрос': e['question'], 'картинка': None, 'ответы': []}

            fn = f'{n:02d}.webp'
            if save(e['image_url'], folder / fn):
                item['картинка'] = fn
                total_pics += 1

            for i, o in enumerate(e.get('options', []), 1):
                oi = {'ответ': o['label'], 'картинка': None}
                if o.get('image_url'):
                    ofn = f'{n:02d}_{i}.webp'
                    if save(o['image_url'], folder / ofn):
                        oi['картинка'] = ofn
                        total_pics += 1
                item['ответы'].append(oi)

            entries.append(item)

        (folder / 'список.json').write_text(
            json.dumps(entries, ensure_ascii=False, indent=1), encoding='utf-8')

        # Тот же список простым текстом — чтобы можно было просто прочитать.
        lines = [f'{name}', '=' * len(name), '']
        for n, it in enumerate(entries, 1):
            lines.append(f'{n}. {it["вопрос"]}')
            lines.append(f'   картинка: {it["картинка"] or "нет"}')
            for oi in it['ответы']:
                lines.append(f'   - {oi["ответ"]}  →  {oi["картинка"] or "нет"}')
            lines.append('')
        (folder / 'список.txt').write_text('\n'.join(lines), encoding='utf-8')

        write_page(name, entries, folder / 'СМОТРЕТЬ.html')

        pics = sum(1 for it in entries if it['картинка']) + \
               sum(1 for it in entries for oi in it['ответы'] if oi['картинка'])
        print(f'{name[:24]:<26} вопросов: {len(entries):<4} картинок: {pics}')
        manifest.append({'пачка': name, 'папка': slug(name), 'вопросов': len(entries)})

    (OUT / 'пачки.json').write_text(
        json.dumps(manifest, ensure_ascii=False, indent=1), encoding='utf-8')

    # Общая страница, чтобы не открывать каждую пачку по отдельности.
    idx = ['<!doctype html><html lang="ru"><head><meta charset="utf-8">',
           '<title>Вопросы с картинками</title><style>',
           'body{margin:0;padding:28px;background:#eef1f5;color:#111;'
           'font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif}',
           'h1{font-size:22px;margin:0 0 6px}',
           'p{color:#69707a;margin:0 0 20px}',
           'a{display:block;background:#fff;border-radius:10px;padding:14px 16px;margin-bottom:9px;'
           'text-decoration:none;color:#111;box-shadow:0 1px 3px rgba(0,0,0,.1)}',
           'a:hover{background:#f6f9ff}',
           'a b{font-size:16px}a span{color:#69707a;font-size:13px;margin-left:8px}',
           '</style></head><body>',
           '<h1>Вопросы с картинками</h1>',
           f'<p>Пачек: {len(manifest)} · картинок: {total_pics} · размер 70×70</p>']
    for m in manifest:
        idx.append(f'<a href="{m["папка"]}/СМОТРЕТЬ.html"><b>{esc(m["пачка"])}</b>'
                   f'<span>вопросов: {m["вопросов"]}</span></a>')
    idx.append('</body></html>')
    (OUT / 'НАЧАТЬ ОТСЮДА.html').write_text('\n'.join(idx), encoding='utf-8')

    print(f'\nготово: {OUT}   картинок: {total_pics}')


if __name__ == '__main__':
    main()
