# Fabrique essai-epure/index.html : une variante épurée du site, À PART de la version livrée.
# La version d'origine (index.html à la racine) n'est jamais modifiée par ce script.
# Usage : python3 outils/fabriquer-essai-epure.py
import pathlib, re
root = pathlib.Path(__file__).resolve().parent.parent
s = (root / 'index.html').read_text(encoding='utf-8')
out = root / 'essai-epure'
out.mkdir(exist_ok=True)

def rep(a, b, n=1):
    global s
    assert s.count(a) == n, (a[:80], s.count(a))
    s = s.replace(a, b)

def cut(start, end, new):
    """Remplace tout ce qui va de `start` (inclus) à `end` (exclu) par `new`."""
    global s
    i = s.index(start); j = s.index(end, i)
    s = s[:i] + new + s[j:]

# ---------- 1. chemins vers les fichiers partagés (images, polices, scripts) ----------
s = s.replace('src="images/', 'src="../images/').replace('url(fonts/', 'url(../fonts/').replace('href="fonts/', 'href="../fonts/')
rep("const IMG = 'images/';", "const IMG = '../images/';")
rep("fetch('promos.json'", "fetch('../promos.json'")
rep("import('./js/bon-pdf.js')", "import('../js/bon-pdf.js')")
s = s.replace("s.src='js/qrcode.js'", "s.src='../js/qrcode.js'")
rep("new URL('verifier.html?d='", "new URL('../verifier.html?d='")
s = s.replace('href="verifier.html"', 'href="../verifier.html"')
# panier séparé de la version d'origine
s = s.replace("'ab-cart'", "'abe-cart'")
rep('<title>Aqua Beauté · Institut de beauté et spa à Mutzig</title>', '<title>Aqua Beauté · essai épuré</title>')

# ---------- 2. en-tête épuré ----------
cut('<div class="demo">', '<main id="contenu"', '''<div class="demo">Version d'essai épurée · démonstration, aucun paiement réel</div>

<header class="top">
  <div class="wrap">
    <a class="brand" id="brandLink" href="#accueil" aria-label="Aqua Beauté, accueil">
      <img class="logo-img" src="../images/2022/12/LOGO-2.png" alt="Aqua Beauté" onerror="document.getElementById('brandLink').classList.add('nologo');this.remove()">
      <span class="fallback">Aqua Beauté</span>
    </a>
    <nav class="main" aria-label="Menu principal">
      <a href="#soins" data-nav="soins">Soins et tarifs</a>
      <a href="#bons-cadeaux" data-nav="bons-cadeaux">Bons cadeaux</a>
      <div class="dd" id="ddPhotos">
        <button type="button" aria-expanded="false" aria-haspopup="true" data-navbtn="galerie">Photos <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg></button>
        <div class="dd-menu" id="ddMenu"></div>
      </div>
      <a href="#contact" data-nav="contact">Contact</a>
    </nav>
    <div class="top-actions">
      <a class="top-tel" href="tel:0388230098">03 88 23 00 98</a>
      <button class="icon-btn" id="cartBtn" aria-label="Ouvrir le panier"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 8h14l-1.2 11.2a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg><span class="badge" id="cartCount" hidden>0</span></button>
      <button class="icon-btn menu-btn" id="menuBtn" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 8h16M4 16h16"/></svg></button>
    </div>
  </div>
  <div class="mobile-nav" id="mobileNav">
    <a href="#accueil">Accueil</a>
    <a href="#soins">Soins et tarifs</a>
    <a href="#bons-cadeaux">Bons cadeaux</a>
    <details class="m-dd"><summary>Photos</summary><div id="mMenu" style="display:grid"></div></details>
    <a href="#contact">Contact et horaires</a>
  </div>
</header>
<a class="giftbar" id="giftbar" href="#bons-cadeaux" hidden></a>

''')

# ---------- 3. accueil épuré ----------
cut('<div class="page" id="p-accueil">', '<div class="page" id="p-soins">', '''<div class="page" id="p-accueil">
  <section class="e-hero">
    <div class="wrap e-hero-grid">
      <div class="e-hero-text">
        <p class="e-status"><span class="dot" id="dotHero"></span><span id="statusHero">…</span></p>
        <h1>Institut de beauté à Mutzig</h1>
        <p class="e-lead">Soins du visage et du corps, épilation, lumière pulsée et minceur. Christelle vous reçoit sur rendez-vous depuis 2013, au 8 rue du Maréchal Foch.</p>
        <div class="ctas">
          <a class="btn btn-gift" href="#bons-cadeaux">Offrir un bon cadeau</a>
          <a class="btn btn-ghost" href="tel:0388230098">Prendre rendez-vous</a>
        </div>
      </div>
      <img class="e-hero-img" src="../images/2023/08/IMG_9330-1.jpg" alt="La façade de l'institut Aqua Beauté, rue du Maréchal Foch à Mutzig" fetchpriority="high">
    </div>
  </section>

  <section id="promoSection" class="promo-sec" hidden></section>

  <section class="e-sec">
    <div class="wrap e-split">
      <div class="e-split-head">
        <h2>Les soins</h2>
        <p>Toute la carte, avec les durées et les tarifs.</p>
        <a class="e-link" href="#soins">Voir la carte complète</a>
      </div>
      <div class="e-cats" id="catCards"></div>
    </div>
  </section>

  <section class="e-sec e-gift">
    <div class="wrap e-gift-grid">
      <img class="e-gift-img" id="giftPhoto" alt="Bon cadeau Aqua Beauté remis dans son enveloppe" loading="lazy">
      <div>
        <h2>Un bon cadeau pour toutes les occasions</h2>
        <p class="e-lead">Un montant libre, un soin précis ou des accessoires, valable un an.</p>
        <ol class="e-steps">
          <li>Choisissez le bon et ajoutez un petit mot.</li>
          <li>Payez avec PayPal ou par carte bancaire.</li>
          <li>Recevez-le par e-mail, ou retirez-le à l'institut.</li>
        </ol>
        <div class="quick-amts" aria-label="Montants rapides">
          <button class="qa" data-qa="30">30 €</button><button class="qa" data-qa="50">50 €</button><button class="qa" data-qa="80">80 €</button><button class="qa" data-qa="100">100 €</button>
        </div>
        <a class="btn btn-gift" href="#bons-cadeaux">Créer un bon cadeau</a>
      </div>
    </div>
  </section>

  <section class="e-sec">
    <div class="wrap">
      <div class="e-row-head"><h2>L'institut en photos</h2><a class="e-link" href="#galerie">Toutes les photos</a></div>
      <div class="carousel">
        <button class="car-btn prev" id="carPrev" aria-label="Photo précédente"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 6l-6 6 6 6"/></svg></button>
        <div class="car-track" id="carTrack"></div>
        <button class="car-btn next" id="carNext" aria-label="Photo suivante"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg></button>
      </div>
      <div class="car-dots" id="carDots" hidden></div>
    </div>
  </section>

  <section class="e-sec">
    <div class="wrap e-quote">
      <blockquote>« Esthéticienne très pro et de bon conseil. Utilise d'excellents produits. »</blockquote>
      <p>Extrait d'un avis publié sur Google, où l'institut est noté 4,6 sur 5 (plus de 50 avis). Produits Institut Esthederm, Bernard Cassière, Acorelle et Hérôme.</p>
    </div>
  </section>

  <section class="e-sec e-info">
    <div class="wrap e-info-grid">
      <div>
        <h2>Horaires</h2>
        <p class="e-status"><span class="dot" id="dotInfo"></span><span id="statusInfo">…</span></p>
        <table class="hours" id="hoursHome"></table>
      </div>
      <div>
        <h2>Rendez-vous</h2>
        <p>Uniquement par téléphone.</p>
        <a class="big-tel" href="tel:0388230098">03 88 23 00 98</a>
        <p class="e-muted">ou 06 59 53 28 64</p>
      </div>
      <div>
        <h2>Adresse</h2>
        <p>8 rue du Maréchal Foch<br>67190 Mutzig</p>
        <p class="e-muted">Accès de plain-pied, stationnement à proximité.</p>
        <a class="e-link" href="#contact">Plan et contact</a>
      </div>
    </div>
  </section>
</div>

''')

# ---------- 4. pied de page épuré ----------
cut('<footer class="site">', '<div class="bbar">', '''<footer class="site">
  <div class="wrap e-foot">
    <p><b>Aqua Beauté</b> · 8 rue du Maréchal Foch, 67190 Mutzig · <a href="tel:0388230098">03 88 23 00 98</a> · <a href="mailto:contact@aquabeaute-esthetique.fr">contact@aquabeaute-esthetique.fr</a></p>
    <p class="e-muted"><a href="#mentions-legales">Mentions légales</a> · <a href="#cgv">Conditions de vente</a> · <a href="#confidentialite">Confidentialité</a> · <a href="https://www.facebook.com/people/Aqua-Beaut%C3%A9-Esthetique/100063663006272/" target="_blank" rel="noopener">Facebook</a></p>
  </div>
</footer>

''')
s = s.replace('<a class="btn btn-gift" href="#bons-cadeaux">🎁 Bon cadeau</a>', '<a class="btn btn-gift" href="#bons-cadeaux">Bon cadeau</a>')

# ---------- 5. textes des autres pages, plus sobres ----------
s = s.replace('<h2>Créez votre <em>bon cadeau</em></h2>', '<h2>Créer un bon cadeau</h2>')
s = s.replace('<h2>Toute la carte de l\'institut</h2>', '<h2>Soins et tarifs</h2>')

# ---------- 6. code : retirer ce qui n'existe plus dans la version épurée ----------
rep("function bg(el,url){", "function bg(el,url){if(!el)return;")
rep("function img(el,url,onFail){", "function img(el,url,onFail){if(!el)return;")
rep("$('#brandLogos').innerHTML=", "if($('#brandLogos'))$('#brandLogos').innerHTML=")
s = re.sub(r"try\{const t=localStorage\.getItem\('ab-theme'\);if\(t\)root\.dataset\.theme=t;\}catch\(e\)\{\}\n\$\('#themeBtn'\)\.onclick=[^\n]*\n", "", s, count=1)
assert "$('#themeBtn')" not in s
rep("setTimeout(()=>{paintDots();startAuto();},300);", "setTimeout(()=>{paintDots();},300);")
rep("  $('#giftbar').innerHTML=esc(PROMO.bandeau)+' · <u>Offrir</u>';", "  $('#giftbar').innerHTML=esc(PROMO.bandeau)+' · <u>Offrir</u>';$('#giftbar').hidden=false;")
i = s.index("$('#catCards').innerHTML="); j = s.index(";\n", s.index("gift-card-cta", i)) + 2
s = s[:i] + """$('#catCards').innerHTML=CATS.map(c=>{const ps=c.groups.flatMap(g=>g.items.map(i=>i.p)).filter(p=>p>=5);const min=Math.min(...ps);
  return `<a class="e-cat" href="#soins/${c.id}"><span class="e-cat-name">${c.name}</span><span class="e-cat-desc">${c.short}</span><span class="e-cat-price">dès ${eur(min)}</span></a>`;}).join('');
""" + s[j:]

# ---------- 7. couche de style épurée (après le style d'origine) ----------
EPURE = r'''
<style id="epure">
/* ====== Variante épurée : mêmes composants, palette resserrée, moins d'ornements ====== */
:root{
  --bg:#FFFFFF;--surface:#FFFFFF;--soft:#F4F5F7;--soft-2:#F6F1F5;
  --ink:#2B1E2B;--ink-soft:#5E5560;--muted:#8C838D;
  --blue:#7CC3E0;--blue-deep:#2F7FA6;--blue-soft:#EEF6FA;
  --magenta:#7A1F63;--magenta-deep:#5E1650;--plum:#2B1E2B;--pink-soft:#F4EAF1;
  --line:#E7E4E8;--shadow:0 1px 2px rgba(43,30,43,.06);--r:10px;
}
@media (prefers-color-scheme: dark){:root,:root:not([data-theme="light"]){
  --bg:#161217;--surface:#1E191F;--soft:#211B22;--soft-2:#241B23;
  --ink:#F1ECF1;--ink-soft:#C3B9C3;--muted:#958B96;
  --blue:#8CCDE6;--blue-deep:#8CCDE6;--blue-soft:#1C2A31;
  --magenta:#E7A3D3;--magenta-deep:#F0BCE0;--plum:#F1ECF1;--pink-soft:#2C1F2A;
  --line:#332B34;--shadow:none;}}
body{font-size:16px;line-height:1.7;letter-spacing:.005em}
h1,h2,h3{color:var(--ink);letter-spacing:-.015em}
h1{font-weight:300}
h2{font-weight:400}
h2 em,h1 em{color:inherit}
.kicker{display:none}
.wrap{max-width:1080px;padding:0 20px}
section{padding:72px 0}
.sec-head h2{font-size:clamp(1.8rem,4.6vw,2.6rem);font-weight:300}
.sec-head p{max-width:60ch}
.bg-pink,.bg-soft{background:var(--soft)}
.demo{background:var(--soft);color:var(--muted);font-size:.74rem;padding:5px 16px}
/* boutons : sobres, un seul accent */
.btn{border-radius:8px;font-weight:500;padding:13px 22px}
.btn-gift{background:var(--magenta);box-shadow:none}
.btn-gift:hover{background:var(--magenta-deep)}
.btn-blue{background:var(--ink);color:var(--bg)}
.btn-blue:hover{background:var(--ink-soft)}
.btn-ghost{border:1px solid var(--ink);background:transparent}
.btn-ghost:hover{background:var(--soft);border-color:var(--ink)}
:root .btn-gift,:root .btn-blue{color:#fff}
@media (prefers-color-scheme: dark){.btn-gift,.btn-blue{color:#161217!important}}
/* cartes : bordure fine, pas d'ombre */
.card{border-radius:var(--r);box-shadow:none}
.tab{border-radius:8px}
.tab.on{background:var(--ink);border-color:var(--ink);color:var(--bg)}
.chip{border-radius:8px}
.chip.on{border-color:var(--magenta);background:var(--pink-soft);color:var(--magenta)}
.status{border:0;background:none;padding:0}
/* en-tête */
header.top{background:var(--bg);backdrop-filter:none}
.top .wrap{height:76px}
nav.main a,.dd>button{font-weight:400;color:var(--ink-soft);border-radius:8px}
nav.main a.on,.dd>button.on{background:none;color:var(--ink);text-decoration:underline;text-underline-offset:6px;text-decoration-thickness:1px}
.icon-btn{border:0;background:none}
.top-tel{display:none;text-decoration:none;font-size:.9rem;color:var(--ink);margin-right:6px;white-space:nowrap}
@media(min-width:980px){.top-tel{display:inline}}
.badge{background:var(--magenta)}
.giftbar{background:var(--pink-soft);color:var(--magenta);font-weight:400}
/* accueil */
.e-hero{padding:40px 0 64px}
.e-hero-grid{display:grid;gap:36px;align-items:center}
@media(min-width:860px){.e-hero-grid{grid-template-columns:1fr 1fr;gap:56px}.e-hero{padding:64px 0 88px}}
.e-hero h1{font-size:clamp(2.4rem,7vw,4rem);line-height:1.05;margin:10px 0 18px;max-width:12ch}
.e-lead{font-size:1.08rem;color:var(--ink-soft);max-width:46ch;margin:0 0 26px}
.e-status{display:flex;align-items:center;gap:8px;font-size:.88rem;color:var(--ink-soft);margin:0}
.e-hero-img{width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:var(--r);background:var(--soft)}
@media(min-width:860px){.e-hero-img{aspect-ratio:502/560}}
.e-sec{border-top:1px solid var(--line)}
.e-split{display:grid;gap:28px}
@media(min-width:860px){.e-split{grid-template-columns:1fr 2fr;gap:56px}}
.e-split-head h2,.e-gift h2,.e-row-head h2,.e-info h2{font-size:clamp(1.6rem,4vw,2.2rem);font-weight:300;margin:0 0 10px}
.e-split-head p{color:var(--ink-soft);margin:0 0 14px}
.e-link{color:var(--ink);font-weight:500;text-decoration:underline;text-underline-offset:5px;text-decoration-thickness:1px}
.e-link:hover{color:var(--magenta)}
.e-cats{display:grid;border-top:1px solid var(--line)}
.e-cat{display:grid;grid-template-columns:1fr auto;gap:2px 16px;padding:18px 4px;border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink)}
.e-cat:hover .e-cat-name{color:var(--magenta)}
.e-cat-name{font-size:1.12rem}
.e-cat-desc{grid-column:1;color:var(--muted);font-size:.9rem}
.e-cat-price{grid-column:2;grid-row:1/span 2;align-self:center;color:var(--ink-soft);font-size:.92rem;white-space:nowrap}
.e-gift{background:var(--soft)}
.e-gift-grid{display:grid;gap:36px;align-items:center}
@media(min-width:860px){.e-gift-grid{grid-template-columns:1fr 1fr;gap:56px}}
.e-gift-img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:var(--r);background:var(--surface)}
.e-steps{margin:0 0 22px;padding-left:22px;color:var(--ink-soft)}
.e-steps li{margin-bottom:6px;padding-left:4px}
.qa{border:1px solid var(--line);color:var(--ink);border-radius:8px;font-weight:500;background:var(--surface)}
.qa:hover{background:var(--magenta);border-color:var(--magenta);color:#fff}
.e-row-head{display:flex;justify-content:space-between;align-items:baseline;gap:16px;margin-bottom:22px;flex-wrap:wrap}
.car-item{border-radius:var(--r)}
.car-item span{background:rgba(255,255,255,.94);border-radius:6px}
.car-btn{border-radius:8px;box-shadow:none}
.e-quote{max-width:760px}
.e-quote blockquote{margin:0;font-size:clamp(1.4rem,3.6vw,2rem);font-weight:300;line-height:1.35;color:var(--ink)}
.e-quote p{color:var(--muted);margin:16px 0 0}
.e-info-grid{display:grid;gap:36px}
@media(min-width:860px){.e-info-grid{grid-template-columns:repeat(3,1fr)}}
.e-info p{color:var(--ink-soft);margin:0 0 8px}
.e-muted{color:var(--muted)!important;font-size:.9rem}
.big-tel{color:var(--ink);font-weight:400;font-size:1.7rem}
.hours td{padding:6px 0}
.hours tr.today td{color:var(--magenta)}
/* bons cadeaux, soins, galerie, contact : même logique, moins de couleur */
.gc-head-pic{border-radius:var(--r);box-shadow:none}
.giftcard{box-shadow:none}
.svc .p{color:var(--ink)}
.svc .gift{border-color:var(--line);color:var(--magenta);border-radius:6px}
.svc-group h3{font-weight:400;font-size:1.3rem}
.note{background:var(--soft)}
.promo-sec{background:var(--pink-soft)}
.promo-card{box-shadow:none;border:1px solid var(--line)}
.promo-dates{color:var(--magenta)}
.pp-note{color:var(--muted)}
/* pied de page */
footer.site{background:var(--bg);border-top:1px solid var(--line);padding:32px 0 96px}
@media(min-width:900px){footer.site{padding-bottom:32px}}
.e-foot{display:block!important}
.e-foot p{margin:0 0 6px;color:var(--ink-soft);font-size:.9rem}
.e-foot a{display:inline!important;color:inherit}
.bbar .btn{border-radius:8px}
</style>
'''
rep('</head>', EPURE + '</head>')
(out / 'index.html').write_text(s, encoding='utf-8')
print('essai-epure/index.html créé')
