// Génère les fichiers pour les moteurs de recherche et les assistants IA, à partir des données de index.html :
//   - llms.txt      : description du site en clair, avec la carte des soins et les prix
//   - sitemap.xml   : la page du site (les rubriques en # ne sont pas des adresses séparées)
//   - robots.txt    : tout est ouvert, sauf le moteur des bons (api/)
//   - index.html    : la carte des soins écrite en dur dans la page (lisible sans JavaScript)
//                     et les données structurées JSON-LD (institut de beauté, horaires, adresse)
// Lancement, après chaque changement de soins, de prix ou d'horaires :  node outils/generer-fichiers-ia.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import vm from 'node:vm';

const SITE = 'https://www.aquabeaute-esthetique.fr/';   // adresse définitive du site (à changer si le domaine change)
const root = new URL('..', import.meta.url);
const idxPath = new URL('index.html', root);
let html = readFileSync(idxPath, 'utf8');

/* ---- lecture des données de la page ---- */
const grab = (re, what) => { const m = html.match(re); if (!m) throw new Error('introuvable dans index.html : ' + what); return m[0]; };
const ctx = {};
vm.createContext(ctx);
vm.runInContext(grab(/const S=\(n,p,t,d\)=>\(\{n,p,t,d\}\);/, 'S()') + '\n' + grab(/const CATS=\[[\s\S]*?\n\];/, 'CATS') + '\n' + grab(/const HOURS=\{.*?\};/, 'HOURS') + '\nthis.CATS=CATS;this.HOURS=HOURS;', ctx);
const { CATS, HOURS } = ctx;

const eur = p => (Number.isInteger(p) ? p : p.toFixed(2).replace('.', ',')) + ' €';
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const strip = s => String(s ?? '').replace(/<[^>]+>/g, '');
const pad = n => String(n).padStart(2, '0');
const hm = ([h, m]) => `${pad(h)}:${pad(m)}`;
const JOURS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const order = [1, 2, 3, 4, 5, 6, 0];

/* ---- llms.txt ---- */
const L = [];
L.push('# Aqua Beauté — institut de beauté à Mutzig (Bas-Rhin)', '');
L.push('> Institut de beauté et spa ouvert depuis 2013 : soins du visage et du corps, épilation, lumière pulsée, méthodes amincissantes, beauté des mains et des pieds, solarium. Bons cadeaux en vente en ligne (paiement PayPal ou carte). Rendez-vous uniquement par téléphone.', '');
L.push('## Coordonnées',
  '- Adresse : 8 rue du Maréchal Foch, 67190 Mutzig, France',
  '- Téléphone : 03 88 23 00 98 (ou 06 59 53 28 64)',
  '- E-mail : contact@aquabeaute-esthetique.fr',
  '- Société : SARL AQUA BEAUTE, SIRET 798 632 329 00016', '');
L.push('## Horaires');
for (const d of order) L.push(`- ${JOURS[d]} : ${HOURS[d] ? hm(HOURS[d][0]) + ' – ' + hm(HOURS[d][1]) : 'fermé'}`);
L.push('');
L.push('## Pages principales',
  `- [Accueil](${SITE}) : présentation de l'institut, avis, accès`,
  `- [Carte des soins et tarifs](${SITE}#soins)`,
  `- [Bons cadeaux en ligne](${SITE}#bons-cadeaux) : montant libre, soin précis ou accessoires, valables 12 mois`,
  `- [Photos de l'institut](${SITE}#galerie)`,
  `- [Contact et plan d'accès](${SITE}#contact)`,
  `- [Conditions générales de vente](${SITE}#cgv)`,
  `- [Mentions légales](${SITE}#mentions-legales)`,
  `- [Politique de confidentialité](${SITE}#confidentialite)`, '');
L.push('## Carte des soins (prix TTC, à titre indicatif ; le tarif affiché à l\'institut fait foi)');
for (const c of CATS) {
  L.push('', `### ${c.name}`);
  if (c.intro) L.push(strip(c.intro));
  for (const g of c.groups) {
    if (g.g !== c.name) L.push(`- **${g.g}**`);
    for (const it of g.items) L.push(`${g.g !== c.name ? '  ' : ''}- ${it.n}${it.t ? ' (' + it.t + ')' : ''} : ${eur(it.p)}${it.d ? ' — ' + strip(it.d) : ''}`);
  }
}
writeFileSync(new URL('llms.txt', root), L.join('\n') + '\n');

/* ---- sitemap.xml et robots.txt ---- */
const today = new Date().toISOString().slice(0, 10);
writeFileSync(new URL('sitemap.xml', root), `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>${SITE}</loc><lastmod>${today}</lastmod></url>
</urlset>
`);
writeFileSync(new URL('robots.txt', root), `# Site de l'institut Aqua Beauté : ouvert aux moteurs de recherche et aux assistants IA.
User-agent: *
Disallow: /api/
Allow: /

Sitemap: ${SITE}sitemap.xml
`);

/* ---- carte des soins écrite en dur (remplacée par la version interactive quand JavaScript fonctionne) ---- */
const svc = CATS.map(c => `<div class="svc-group"><h3>${esc(c.name)}</h3>` +
  c.groups.map(g => (g.g !== c.name ? `<h4 class="svc-sub">${esc(g.g)}</h4>` : '') +
    g.items.map(it => `<div class="svc"><div class="n">${esc(it.n)}${it.t ? ` <span class="t">· ${esc(it.t)}</span>` : ''}</div><div class="meta"><span class="p">${eur(it.p)}</span></div></div>`).join('')).join('') +
  '</div>').join('');
const S1 = '<!-- CARTE STATIQUE (générée par outils/generer-fichiers-ia.mjs) -->', S2 = '<!-- FIN CARTE STATIQUE -->';
const panel = `<div id="svcPanel">${S1}${svc}${S2}</div>`;
if (html.includes(S1)) html = html.replace(new RegExp('<div id="svcPanel">' + S1 + '[\\s\\S]*?' + S2 + '</div>'), () => panel);
else { if (!html.includes('<div id="svcPanel"></div>')) throw new Error('svcPanel introuvable'); html = html.replace('<div id="svcPanel"></div>', () => panel); }

/* ---- données structurées ---- */
const ld = {
  '@context': 'https://schema.org', '@type': 'BeautySalon', '@id': SITE + '#institut',
  name: 'Aqua Beauté', legalName: 'SARL AQUA BEAUTE', url: SITE, image: SITE + 'images/2023/08/IMG_9330-1.jpg', logo: SITE + 'images/2022/12/LOGO-2.png',
  description: 'Institut de beauté et spa à Mutzig : soins visage et corps, épilation, lumière pulsée, minceur, mains et pieds. Bons cadeaux en ligne.',
  telephone: '+33388230098', email: 'contact@aquabeaute-esthetique.fr', foundingDate: '2013', priceRange: '€€', vatID: 'FR06798632329', taxID: '79863232900016',
  address: { '@type': 'PostalAddress', streetAddress: '8 rue du Maréchal Foch', postalCode: '67190', addressLocality: 'Mutzig', addressRegion: 'Grand Est', addressCountry: 'FR' },
  geo: { '@type': 'GeoCoordinates', latitude: 48.5384, longitude: 7.4574 },
  openingHoursSpecification: order.filter(d => HOURS[d]).map(d => ({ '@type': 'OpeningHoursSpecification', dayOfWeek: DAYS[d], opens: hm(HOURS[d][0]), closes: hm(HOURS[d][1]) })),
  sameAs: ['https://www.facebook.com/people/Aqua-Beaut%C3%A9-Esthetique/100063663006272/'],
  hasOfferCatalog: { '@type': 'OfferCatalog', name: 'Carte des soins', itemListElement: CATS.map(c => ({ '@type': 'OfferCatalog', name: c.name,
    itemListElement: c.groups.flatMap(g => g.items).map(it => ({ '@type': 'Offer', price: it.p, priceCurrency: 'EUR', itemOffered: { '@type': 'Service', name: it.n } })) })) }
};
const J1 = '<script type="application/ld+json" id="ld-institut">', J2 = '</script>';
const block = J1 + JSON.stringify(ld).replace(/</g, '\\u003c') + J2;
if (html.includes(J1)) html = html.replace(new RegExp(J1.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[\\s\\S]*?' + J2), () => block);
else html = html.replace('</head>', () => block + '\n</head>');

writeFileSync(idxPath, html);
console.log(`llms.txt, sitemap.xml, robots.txt, carte statique (${CATS.reduce((n, c) => n + c.groups.reduce((m, g) => m + g.items.length, 0), 0)} soins) et JSON-LD à jour.`);
