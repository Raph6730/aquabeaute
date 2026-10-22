# Insère (ou remplace) les pages légales de outils/pages-legales.html dans index.html.
# Usage : python3 outils/inserer-pages-legales.py
import pathlib, re
root = pathlib.Path(__file__).resolve().parent.parent
idx = root / 'index.html'
s = idx.read_text(encoding='utf-8')
block = (root / 'outils' / 'pages-legales.html').read_text(encoding='utf-8').strip()
start, end = '<!-- ===================== PAGES LÉGALES', '<!-- =================== FIN PAGES LÉGALES =================== -->'
if start in s:
    s = re.sub(re.escape(start) + r'.*?' + re.escape(end), lambda m: block, s, count=1, flags=re.S)
else:
    anchor = '<div class="page" id="p-contact">'
    assert s.count(anchor) == 1
    s = s.replace(anchor, block + '\n\n' + anchor)
idx.write_text(s, encoding='utf-8')
print('pages légales insérées')
