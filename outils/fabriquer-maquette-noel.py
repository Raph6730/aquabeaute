# Fabrique maquette-noel.html à partir de index.html (le site normal n'est pas modifié).
# Usage : python3 outils/fabriquer-maquette-noel.py
import pathlib
root = pathlib.Path(__file__).resolve().parent.parent
s = (root / 'index.html').read_text(encoding='utf-8')
def rep(a, b):
    global s
    assert s.count(a) == 1, a[:70]
    s = s.replace(a, b)

CSS = r'''
<style id="noel">
/* ===== Habillage de Noël (léger) ===== */
:root{--sapin:#1F5C46;--sapin-soft:#E6F1EC;--or:#C9A24A;--or-soft:#F7EEDB;--rouge:#B3263E}
@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){--sapin:#7CC4A4;--sapin-soft:#16261F;--or:#E3C27A;--or-soft:#2A2416}}
:root[data-theme="dark"]{--sapin:#7CC4A4;--sapin-soft:#16261F;--or:#E3C27A;--or-soft:#2A2416}
.giftbar{background:var(--sapin);color:#FFF6E3}
.giftbar u{color:#F3D58E}
/* guirlande lumineuse sous l'en-tête */
header.top{border-bottom:0}
header.top::after{content:"";position:absolute;left:0;right:0;bottom:-7px;height:14px;pointer-events:none;
  background:radial-gradient(circle at 10px 9px,#F3C44F 0 3.2px,transparent 4px) 0 0/80px 14px repeat-x,
             radial-gradient(circle at 30px 9px,#E0559C 0 3.2px,transparent 4px) 0 0/80px 14px repeat-x,
             radial-gradient(circle at 50px 9px,#7FCDEC 0 3.2px,transparent 4px) 0 0/80px 14px repeat-x,
             radial-gradient(circle at 70px 9px,#9FD6B5 0 3.2px,transparent 4px) 0 0/80px 14px repeat-x;
  -webkit-mask:linear-gradient(#000,#000);animation:twinkle 3.5s ease-in-out infinite}
header.top::before{content:"";position:absolute;left:0;right:0;bottom:-3px;height:8px;pointer-events:none;
  background:repeating-radial-gradient(ellipse 40px 8px at 40px 0,transparent 0 6px,rgba(31,92,70,.35) 6.5px 7.5px,transparent 8px);background-size:80px 8px}
@keyframes twinkle{0%,100%{opacity:1}50%{opacity:.55}}
@media (prefers-reduced-motion: reduce){header.top::after{animation:none}}
/* branche de houx près du logo */
.brand{position:relative}
.brand .houx{position:absolute;left:-14px;top:-10px;width:30px;height:30px;transform:rotate(-18deg)}
/* titres et touches dorées */
.kicker{color:var(--sapin)}
.hero{background:linear-gradient(180deg,var(--or-soft) 0%,var(--bg) 100%)}
.hero-pics .main{box-shadow:0 0 0 6px var(--or-soft),var(--shadow)}
.hero-pics .tag b{color:var(--sapin)}
.promo-sec{background:linear-gradient(135deg,var(--sapin-soft),var(--or-soft))}
.promo-dates{color:var(--sapin)}
.badge-promo{background:var(--sapin)}
.bg-pink{background:
  radial-gradient(circle at 12% 20%,rgba(201,162,74,.18) 0 2px,transparent 3px) 0 0/90px 90px,
  radial-gradient(circle at 60% 70%,rgba(31,92,70,.12) 0 2px,transparent 3px) 0 0/120px 120px,var(--soft-2)}
footer.site{background:var(--sapin-soft)}
/* bon cadeau de Noël */
.giftcard.noel{background:
  radial-gradient(circle at 15% 25%,rgba(255,255,255,.85) 0 2px,transparent 3px),radial-gradient(circle at 40% 80%,rgba(255,255,255,.7) 0 1.5px,transparent 2.5px),
  radial-gradient(circle at 70% 18%,rgba(255,255,255,.8) 0 2px,transparent 3px),radial-gradient(circle at 85% 60%,rgba(255,255,255,.6) 0 1.5px,transparent 2.5px),
  radial-gradient(circle at 55% 45%,rgba(243,213,142,.9) 0 1.8px,transparent 2.8px),
  linear-gradient(135deg,#123D2F 0%,#1F5C46 60%,#2E7A5E 100%);border:0;color:#fff}
.giftcard.noel .gc-brand{color:#fff}.giftcard.noel .gc-title{color:#F3D58E;font-size:1.9rem}.giftcard.noel .gc-amt{color:#fff}
.giftcard.noel small,.giftcard.noel .gc-for,.giftcard.noel .gc-msg{color:rgba(255,255,255,.9)}
.sw-n{background:radial-gradient(circle at 25% 35%,#fff 0 2px,transparent 3px),radial-gradient(circle at 70% 60%,#F3D58E 0 2px,transparent 3px),linear-gradient(135deg,#123D2F,#2E7A5E)}
.seg-designs{grid-template-columns:repeat(3,1fr)}
.maquette-flag{position:fixed;right:12px;bottom:86px;z-index:55;background:var(--sapin);color:#fff;font-size:.78rem;padding:6px 12px;border-radius:999px;box-shadow:var(--shadow)}
@media(min-width:900px){.maquette-flag{bottom:16px}}
#neige{position:fixed;inset:0;pointer-events:none;z-index:39}
</style>
'''
HOUX = '<svg class="houx" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 22c-6-1-10-6-11-12 4 2 6 1 7-2 1 4 4 5 7 4-1 3 0 6 3 7-3 2-4 3-6 3z" fill="#1F5C46"/><path d="M21 22c4-4 10-5 15-3-3 2-3 4-2 7-4-1-6 1-7 4-2-3-4-4-7-4 2-1 2-3 1-4z" fill="#2E7A5E"/><circle cx="19" cy="24" r="3" fill="#B3263E"/><circle cx="24" cy="25" r="2.6" fill="#C92F49"/><circle cx="21" cy="28.5" r="2.4" fill="#A3172D"/></svg>'
NEIGE = r'''
<canvas id="neige" aria-hidden="true"></canvas>
<script>
/* neige discrète : ~40 flocons lents, désactivée si l'utilisateur limite les animations */
(function(){
  if(matchMedia('(prefers-reduced-motion: reduce)').matches)return;
  const c=document.getElementById('neige'),x=c.getContext('2d');let W,H,F=[];
  const size=()=>{W=c.width=innerWidth*devicePixelRatio;H=c.height=innerHeight*devicePixelRatio;c.style.width=innerWidth+'px';c.style.height=innerHeight+'px';};
  size();addEventListener('resize',size);
  const n=innerWidth<700?22:40;
  for(let i=0;i<n;i++)F.push({x:Math.random()*W,y:Math.random()*H,r:(1+Math.random()*2.2)*devicePixelRatio,s:(.25+Math.random()*.6)*devicePixelRatio,d:Math.random()*6.28});
  (function loop(){x.clearRect(0,0,W,H);const dark=matchMedia('(prefers-color-scheme: dark)').matches||document.documentElement.dataset.theme==='dark';
    x.fillStyle=dark?'rgba(255,255,255,.75)':'rgba(140,175,200,.55)';
    for(const f of F){f.y+=f.s;f.d+=.01;f.x+=Math.sin(f.d)*.3*devicePixelRatio;if(f.y>H){f.y=-5;f.x=Math.random()*W;}x.beginPath();x.arc(f.x,f.y,f.r,0,6.28);x.fill();}
    requestAnimationFrame(loop);})();
})();
</script>
<div class="maquette-flag">🎄 Maquette de Noël · non mise en ligne</div>
'''
rep('</head>', CSS + '</head>')
rep('<title>Aqua Beauté Mutzig</title>', '<title>Aqua Beauté Mutzig · maquette Noël</title>')
rep('<div class="demo">Proposition de nouveau site · Démonstration : aucun paiement n\'est réellement effectué</div>',
    '<div class="demo">Maquette de Noël · proposition, non mise en ligne</div>')
rep('<span class="fallback">Aqua Beauté</span>', '<span class="fallback">Aqua Beauté</span>' + HOUX)
rep('<div class="kicker" style="margin-top:18px">Bienvenue chez Christelle</div>', '<div class="kicker" style="margin-top:18px">Joyeuses fêtes chez Christelle</div>')
rep('<p class="sub">Aqua Beauté, spécialisé dans les soins beauté et bien-être. Évadez-vous au sein d\'un salon de beauté chaleureux.</p>',
    '<p class="sub">Pour les fêtes, offrez un moment de douceur : bons cadeaux à imprimer ou à glisser sous le sapin, et offres de Noël jusqu\'au 24 décembre.</p>')
rep('<a class="btn btn-gift" href="#bons-cadeaux">🎁 Offrir un bon cadeau</a>', '<a class="btn btn-gift" href="#bons-cadeaux">🎄 Offrir un bon cadeau de Noël</a>')
# 3e design « Noël » pour les bons
rep('<div class="seg">\n            <button class="chip design-chip on" data-gcdesign="classique">', '<div class="seg seg-designs">\n            <button class="chip design-chip on" data-gcdesign="classique">')
rep('<button class="chip design-chip" data-gcdesign="fete"><span class="sw sw-f"></span><b>Anniversaire / Fête</b>Ballons et confettis</button>',
    '<button class="chip design-chip" data-gcdesign="fete"><span class="sw sw-f"></span><b>Anniversaire / Fête</b>Ballons et confettis</button>\n            <button class="chip design-chip" data-gcdesign="noel"><span class="sw sw-n"></span><b>Noël</b>Sapin, flocons et or</button>')
rep("const fete=o.design==='fete';", "const fete=o.design==='fete', noel=o.design==='noel';")
rep('return `<div class="giftcard ${fete?\'festif\':\'\'}">', 'return `<div class="giftcard ${fete?\'festif\':noel?\'noel\':\'\'}">')
rep("<div class=\"gc-title\">${fete?esc(o.occasion):'Bon cadeau'}</div>", "<div class=\"gc-title\">${fete?esc(o.occasion):noel?'Joyeux Noël':'Bon cadeau'}</div>")
rep("const gc={type:'montant',amount:50,soin:GIFTABLE[0].n,name:'',msg:'',format:'e',design:'classique',occasion:'Joyeux anniversaire'};",
    "const gc={type:'montant',amount:50,soin:GIFTABLE[0].n,name:'',msg:'',format:'e',design:'noel',occasion:'Joyeux anniversaire'};")
# promo de Noël toujours affichée dans la maquette
rep("const q=new URLSearchParams(location.search).get('promo');", "const q=new URLSearchParams(location.search).get('promo')||'noel';")
rep("if(PROMO.apercu)document.body.insertAdjacentHTML", "if(false)document.body.insertAdjacentHTML")
rep('<div class="legal">© Aqua Beauté', '<div class="legal">🎄 Joyeuses fêtes · © Aqua Beauté')
rep('</body>', NEIGE + '</body>')
(root / 'maquette-noel.html').write_text(s, encoding='utf-8')
print('maquette-noel.html créée')
