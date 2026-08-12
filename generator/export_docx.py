#!/usr/bin/env python3
"""Выгрузка готовых пачек в .docx — в том же виде, в каком вопросы прислали.

Файл .docx — это zip с XML внутри, поэтому собираем его сами: сторонних
библиотек в системе нет, а zipfile и Pillow есть. Картинки переводим из
webp в png: webp Word не открывает.

Запуск: python3 generator/export_docx.py
"""

import json
import zipfile
from io import BytesIO
from pathlib import Path
from xml.sax.saxutils import escape

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
STORE = ROOT / 'data' / 'imported.json'
OUT = ROOT / 'export_docx'

EMU = 9525                     # один пиксель в единицах Word
QUESTION_W, OPTION_W = 70, 70  # все картинки одним размером, как на сайте


def para(runs, style=None, spacing_after=120):
    pr = f'<w:pStyle w:val="{style}"/>' if style else ''
    return (f'<w:p><w:pPr>{pr}<w:spacing w:after="{spacing_after}"/></w:pPr>'
            f'{"".join(runs)}</w:p>')


def text_run(s, bold=False, size=22, color=None):
    rpr = '<w:b/>' if bold else ''
    if color:
        rpr += f'<w:color w:val="{color}"/>'
    rpr += f'<w:sz w:val="{size}"/>'
    return (f'<w:r><w:rPr>{rpr}</w:rPr>'
            f'<w:t xml:space="preserve">{escape(s)}</w:t></w:r>')


def image_run(rid, w, h, name):
    cx, cy = w * EMU, h * EMU
    return (
        '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        f'<wp:extent cx="{cx}" cy="{cy}"/>'
        f'<wp:docPr id="{rid[3:]}" name="{escape(name)}"/>'
        '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        f'<pic:nvPicPr><pic:cNvPr id="{rid[3:]}" name="{escape(name)}"/><pic:cNvPicPr/></pic:nvPicPr>'
        f'<pic:blipFill><a:blip r:embed="{rid}"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        '<pic:spPr><a:xfrm><a:off x="0" y="0"/>'
        f'<a:ext cx="{cx}" cy="{cy}"/></a:xfrm>'
        '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
        '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>')


PER_ROW = 5          # столько вариантов помещается в строку по ширине листа
CELL_W = 1850        # ширина ячейки в двадцатых долях пункта (~3.3 см)


def table(cells):
    """Варианты в ряд: в Word строка из картинок с подписями — это таблица.

    Ячейки без видимых границ, поэтому выглядит просто как ряд картинок.
    """
    out = ['<w:tbl><w:tblPr>'
           '<w:tblBorders>' + ''.join(
               f'<w:{s} w:val="none" w:sz="0" w:space="0"/>'
               for s in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV')) +
           '</w:tblBorders>'
           # поля внутри ячейки, иначе длинная подпись притирается к соседней
           '<w:tblCellMar>'
           '<w:left w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/>'
           '<w:top w:w="40" w:type="dxa"/><w:bottom w:w="120" w:type="dxa"/>'
           '</w:tblCellMar>'
           '<w:tblLayout w:type="fixed"/></w:tblPr>']

    for start in range(0, len(cells), PER_ROW):
        chunk = cells[start:start + PER_ROW]
        out.append('<w:tr>')
        for body in chunk:
            out.append(f'<w:tc><w:tcPr><w:tcW w:w="{CELL_W}" w:type="dxa"/></w:tcPr>{body}</w:tc>')
        # добиваем строку пустыми ячейками, иначе последняя растянется на весь лист
        for _ in range(PER_ROW - len(chunk)):
            out.append(f'<w:tc><w:tcPr><w:tcW w:w="{CELL_W}" w:type="dxa"/></w:tcPr>'
                       f'{para([])}</w:tc>')
        out.append('</w:tr>')

    out.append('</w:tbl>')
    return ''.join(out)


def to_png(path, size):
    """webp → квадратный png: Word показывает png, webp — нет.

    Кадр обрезается по центру, а не сжимается: иначе портреты вытягиваются.
    """
    im = Image.open(path).convert('RGB')
    side = min(im.width, im.height)
    left = (im.width - side) // 2
    top = (im.height - side) // 2
    im = im.crop((left, top, left + side, top + side)).resize((size, size), Image.LANCZOS)
    buf = BytesIO()
    im.save(buf, 'PNG', optimize=True)
    return buf.getvalue(), size, size


def build(name, rows, dest):
    body, rels, media = [], [], []
    body.append(para([text_run(name, bold=True, size=34)]))

    for n, e in enumerate(rows, 1):
        # вопрос: номер, текст, под ним картинка
        body.append(para([text_run(f'{n}. {e["question"]}', bold=True, size=26)],
                         spacing_after=80))

        src = ROOT / (e.get('image_url') or '')
        if e.get('image_url') and src.is_file():
            rid = f'rId{len(rels) + 10}'
            data, w, h = to_png(src, QUESTION_W)
            fn = f'media/q{n}.png'
            media.append((fn, data))
            rels.append((rid, fn))
            body.append(para([image_run(rid, w, h, f'вопрос {n}')], spacing_after=100))

        # варианты идут в ряд, поэтому каждый — ячейка: картинка, под ней подпись
        cells = []
        for i, o in enumerate(e.get('options', []), 1):
            runs = []
            osrc = ROOT / (o.get('image_url') or '')
            if o.get('image_url') and osrc.is_file():
                rid = f'rId{len(rels) + 10}'
                data, w, h = to_png(osrc, OPTION_W)
                fn = f'media/q{n}_{i}.png'
                media.append((fn, data))
                rels.append((rid, fn))
                runs.append(image_run(rid, w, h, f'вариант {n}.{i}'))

            cells.append(para(runs, spacing_after=20) +
                         para([text_run(o['label'], size=18, color='444444')], spacing_after=60))

        if cells:
            body.append(table(cells))

        body.append(para([text_run('')], spacing_after=200))

    doc = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
        ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
        f'<w:body>{"".join(body)}</w:body></w:document>')

    rel_xml = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
               + ''.join(
                   f'<Relationship Id="{rid}" Target="{t}" '
                   'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image"/>'
                   for rid, t in rels)
               + '</Relationships>')

    content_types = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                     '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                     '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                     '<Default Extension="png" ContentType="image/png"/>'
                     '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                     '</Types>')

    root_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                 '<Relationship Id="rId1" Target="word/document.xml" '
                 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"/>'
                 '</Relationships>')

    with zipfile.ZipFile(dest, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', content_types)
        z.writestr('_rels/.rels', root_rels)
        z.writestr('word/document.xml', doc)
        z.writestr('word/_rels/document.xml.rels', rel_xml)
        for fn, data in media:
            z.writestr('word/' + fn, data)


def main():
    store = json.loads(STORE.read_text(encoding='utf-8'))

    batches = {}
    for e in store:
        batches.setdefault(e.get('batch_name') or e.get('batch') or '—', []).append(e)

    OUT.mkdir(exist_ok=True)
    made = 0
    for name, rows in batches.items():
        if any(not e.get('image_url') for e in rows):
            continue
        fname = name if name.lower().endswith('.docx') else name + '.docx'
        build(name, rows, OUT / fname)
        pics = sum(1 for e in rows if e.get('image_url')) + \
               sum(1 for e in rows for o in e.get('options', []) if o.get('image_url'))
        print(f'{name[:24]:<26} вопросов: {len(rows):<4} картинок: {pics}')
        made += 1

    print(f'\nготово: {OUT}  файлов: {made}')


if __name__ == '__main__':
    main()
