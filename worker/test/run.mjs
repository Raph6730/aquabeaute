// Test complet du moteur, avec PayPal et Brevo simulés (aucun appel réel).
// Lancement : npm test   → crée test/out/*.pdf pour vérifier le rendu des bons.
import fs from 'fs';
import assert from 'assert/strict';
import worker from '../src/index.js';

/* --- faux registre KV --- */
const store = new Map();
const KV = {
  async get(k) { return store.has(k) ? store.get(k).v : null; },
  async put(k, v, o = {}) { store.set(k, { v, m: o.metadata }); },
  async list({ prefix }) { return { keys: [...store].filter(([k]) => k.startsWith(prefix)).map(([name, x]) => ({ name, metadata: x.m })), list_complete: true }; }
};
const env = { BONS: KV, PAYPAL_ENV: 'sandbox', PAYPAL_CLIENT_ID: 'id', PAYPAL_SECRET: 'secret', BREVO_API_KEY: 'k', STAFF_PIN: '2468', ALLOWED_ORIGIN: 'https://raph6730.github.io', SENDER_EMAIL: 'contact@aquabeaute-esthetique.fr', SHOP_EMAIL: 'contact@aquabeaute-esthetique.fr' };

/* --- faux PayPal + Brevo --- */
const orders = {}; const mails = []; let tamper = null;
globalThis.fetch = async (url, opt = {}) => {
  const u = String(url); const body = opt.body && opt.body[0] === '{' ? JSON.parse(opt.body) : null;
  const res = (d, s = 200) => new Response(JSON.stringify(d), { status: s, headers: { 'content-type': 'application/json' } });
  if (u.endsWith('/v1/oauth2/token')) return res({ access_token: 'tok' });
  if (u.endsWith('/v2/checkout/orders') && opt.method === 'POST') { const id = 'PP' + (Object.keys(orders).length + 1); orders[id] = body; return res({ id, status: 'CREATED' }, 201); }
  let m = u.match(/\/v2\/checkout\/orders\/(\w+)\/capture$/);
  if (m) { const o = orders[m[1]]; const value = tamper || o.purchase_units[0].amount.value; return res({ status: 'COMPLETED', purchase_units: [{ payments: { captures: [{ id: 'CAP-' + m[1], status: 'COMPLETED', amount: { currency_code: 'EUR', value } }] } }] }, 201); }
  if (u === 'https://api.brevo.com/v3/smtp/email') { mails.push(body); return res({ messageId: 'x' }, 201); }
  throw new Error('fetch inattendu ' + u);
};

const call = async (method, path, body, headers = {}) => {
  const r = await worker.fetch(new Request('https://bons.example.workers.dev' + path, { method, headers: { 'content-type': 'application/json', origin: 'https://raph6730.github.io', ...headers }, body: body ? JSON.stringify(body) : undefined }), env, {});
  return { status: r.status, data: await r.json().catch(() => null), headers: r.headers };
};
const buyer = { name: 'Marc Test', email: 'marc@test.fr', tel: '0600000000' };

/* 1. Le prix est recalculé côté moteur (le site ne peut pas tricher) */
let r = await call('POST', '/api/orders', { buyer, items: [
  { type: 'montant', amount: 50, design: 'fete', occasion: 'Joyeux anniversaire', to: 'Julie', msg: 'Joyeux anniversaire ma belle ! 🎉', format: 'e', price: 1 },
  { type: 'soin', soin: 'Escapade au pays des sens', design: 'classique', to: 'Paul', format: 'papier', price: 1 }
] });
assert.equal(r.status, 200, JSON.stringify(r.data));
assert.equal(r.headers.get('access-control-allow-origin'), 'https://raph6730.github.io');
assert.equal(orders[r.data.id].purchase_units[0].amount.value, '115.00'); // 50 + 65, pas 1 + 1
console.log('✓ commande créée, total recalculé : 115,00 €');

/* 2. Montants et soins invalides refusés */
assert.equal((await call('POST', '/api/orders', { buyer, items: [{ type: 'montant', amount: 5 }] })).status, 400);
assert.equal((await call('POST', '/api/orders', { buyer, items: [{ type: 'soin', soin: 'Soin imaginaire' }] })).status, 400);
assert.equal((await call('POST', '/api/orders', { buyer: { name: 'x', email: 'pas-un-email' }, items: [{ type: 'montant', amount: 50 }] })).status, 400);
console.log('✓ montants, soins et e-mails invalides refusés');

/* 3. Encaissement → bons, PDF, e-mails */
const id = r.data.id;
r = await call('POST', `/api/orders/${id}/capture`);
assert.equal(r.status, 200, JSON.stringify(r.data));
assert.equal(r.data.vouchers.length, 2); assert.equal(r.data.emailed, true);
const [v1, v2] = r.data.vouchers;
assert.match(v1.code, /^AQB-[A-Z2-9]{4}-[A-Z2-9]{4}$/);
assert.equal(mails.length, 2);
assert.equal(mails[0].to[0].email, 'marc@test.fr'); assert.equal(mails[0].attachment.length, 1); // seul le bon "par e-mail" est joint à l'acheteur
assert.equal(mails[1].to[0].email, 'contact@aquabeaute-esthetique.fr'); assert.equal(mails[1].attachment.length, 2); // l'institut reçoit les deux
fs.mkdirSync(new URL('./out/', import.meta.url), { recursive: true });
mails[1].attachment.forEach((a, i) => fs.writeFileSync(new URL(`./out/bon-${i + 1}.pdf`, import.meta.url), Buffer.from(a.content, 'base64')));
fs.writeFileSync(new URL('./out/mail-client.html', import.meta.url), mails[0].htmlContent);
console.log('✓ paiement encaissé, 2 bons créés, PDF et e-mails envoyés :', v1.code, v2.code);

/* 4. Double clic sur "payer" : pas de bons en double */
r = await call('POST', `/api/orders/${id}/capture`);
assert.deepEqual(r.data.vouchers.map(v => v.code), [v1.code, v2.code]); assert.equal(mails.length, 2);
console.log('✓ second encaissement ignoré (pas de doublon)');

/* 5. Montant encaissé différent → refus */
r = await call('POST', '/api/orders', { buyer, items: [{ type: 'montant', amount: 80 }] });
tamper = '1.00'; const bad = await call('POST', `/api/orders/${r.data.id}/capture`); tamper = null;
assert.equal(bad.status, 402);
console.log('✓ montant PayPal incohérent refusé');

/* 6. Vérification en magasin */
assert.equal((await call('GET', `/api/vouchers/${v1.code}`)).status, 401);
assert.equal((await call('GET', `/api/vouchers/${v1.code}`, null, { 'x-staff-pin': '0000' })).status, 401);
const pin = { 'x-staff-pin': '2468' };
r = await call('GET', `/api/vouchers/${v1.code}`, null, pin);
assert.equal(r.data.status, 'valide'); assert.equal(r.data.price, 50); assert.equal(r.data.to, 'Julie');
r = await call('POST', `/api/vouchers/${v1.code}/use`, null, pin); assert.equal(r.data.status, 'utilisé');
r = await call('POST', `/api/vouchers/${v1.code}/use`, null, pin); assert.equal(r.status, 409); assert.match(r.data.error, /déjà été utilisé/);
r = await call('POST', `/api/vouchers/${v1.code}/unuse`, null, pin); assert.equal(r.data.status, 'valide');
assert.equal((await call('GET', '/api/vouchers/AQB-XXXX-XXXX', null, pin)).status, 404);
r = await call('GET', '/api/vouchers', null, pin); assert.equal(r.data.vouchers.length, 2);
console.log('✓ PIN obligatoire, bon validé une seule fois, annulation possible, liste OK');

/* 7. Trop d'essais de PIN → blocage */
for (let i = 0; i < 8; i++) await worker.fetch(new Request('https://x/api/vouchers', { headers: { 'x-staff-pin': '1111', 'cf-connecting-ip': '9.9.9.9' } }), env);
const blocked = await worker.fetch(new Request('https://x/api/vouchers', { headers: { 'x-staff-pin': '2468', 'cf-connecting-ip': '9.9.9.9' } }), env);
assert.equal(blocked.status, 429);
console.log('✓ blocage après 8 PIN erronés');

/* 8. Page de vérification */
const page = await worker.fetch(new Request('https://x/verifier?code=' + v1.code), env);
assert.equal(page.status, 200); assert.match(await page.text(), /Vérifier un bon/);
console.log('✓ page /verifier servie');
console.log('\nTous les tests passent.');

/* ===== Nouveaux scénarios : types de bons, plafond 600 €, sécurité du paiement ===== */
r = await call('POST', '/api/orders', { buyer, items: [{ type: 'accessoire', amount: 600, design: 'classique', format: 'e' }] });
assert.equal(r.status, 200); assert.equal(orders[r.data.id].purchase_units[0].amount.value, '600.00');
assert.match(orders[r.data.id].purchase_units[0].items[0].description, /\[Accessoires\]/);
assert.equal((await call('POST', '/api/orders', { buyer, items: [{ type: 'montant', amount: 601 }] })).status, 400);
const accId = r.data.id;
console.log('✓ bon accessoires 600 € accepté, 601 € refusé, type indiqué à PayPal');

// deux encaissements simultanés → mêmes codes, pas de doublon
const [c1, c2] = await Promise.all([call('POST', `/api/orders/${accId}/capture`), call('POST', `/api/orders/${accId}/capture`)]);
assert.deepEqual(c1.data.vouchers.map(v => v.code), c2.data.vouchers.map(v => v.code));
const accCode = c1.data.vouchers[0].code;
const vAcc = await call('GET', `/api/vouchers/${accCode}`, null, { 'x-staff-pin': '2468' });
assert.equal(vAcc.data.typeLabel, 'Accessoires');
console.log('✓ deux encaissements simultanés : un seul bon', accCode);

// carte refusée → le site doit relancer PayPal
r = await call('POST', '/api/orders', { buyer, items: [{ type: 'montant', amount: 40 }] });
const realFetch = globalThis.fetch;
globalThis.fetch = async (u, o) => String(u).endsWith('/capture') ? new Response(JSON.stringify({ name: 'UNPROCESSABLE_ENTITY', details: [{ issue: 'INSTRUMENT_DECLINED' }] }), { status: 422 }) : realFetch(u, o);
let dec = await call('POST', `/api/orders/${r.data.id}/capture`);
assert.equal(dec.status, 422); assert.equal(dec.data.restart, true);
console.log('✓ carte refusée : le client peut choisir un autre moyen de paiement');

// paiement en attente puis confirmé par la notification PayPal
globalThis.fetch = async (u, o) => {
  if (String(u).endsWith('/capture')) return new Response(JSON.stringify({ status: 'COMPLETED', purchase_units: [{ payments: { captures: [{ id: 'CAP-PEND', status: 'PENDING', amount: { currency_code: 'EUR', value: '40.00' } }] } }] }), { status: 201 });
  if (String(u).endsWith('/verify-webhook-signature')) return new Response(JSON.stringify({ verification_status: 'SUCCESS' }), { status: 200 });
  return realFetch(u, o);
};
const pendId = r.data.id; const mailsBefore = mails.length;
let pend = await call('POST', `/api/orders/${pendId}/capture`);
assert.equal(pend.data.pending, true); assert.equal(pend.data.vouchers.length, 0);
assert.equal((await call('POST', '/api/paypal/webhook', { event_type: 'PAYMENT.CAPTURE.COMPLETED', resource: { id: 'CAP-PEND', status: 'COMPLETED', amount: { currency_code: 'EUR', value: '40.00' }, supplementary_data: { related_ids: { order_id: pendId } } } })).status, 503); // pas encore configuré
env.PAYPAL_WEBHOOK_ID = 'WH-1';
let wh = await call('POST', '/api/paypal/webhook', { event_type: 'PAYMENT.CAPTURE.COMPLETED', resource: { id: 'CAP-PEND', status: 'COMPLETED', amount: { currency_code: 'EUR', value: '40.00' }, supplementary_data: { related_ids: { order_id: pendId } } } });
assert.equal(wh.status, 200);
const pendOrder = JSON.parse(await KV.get('o:' + pendId)); assert.equal(pendOrder.status, 'payée'); assert.equal(pendOrder.codes.length, 1);
assert.ok(mails.length > mailsBefore + 1);
console.log('✓ paiement en attente : bons créés et envoyés automatiquement à la confirmation PayPal');

// remboursement depuis PayPal → bon annulé
wh = await call('POST', '/api/paypal/webhook', { event_type: 'PAYMENT.CAPTURE.REFUNDED', resource: { id: 'REF-1', links: [{ rel: 'up', href: 'https://api-m.paypal.com/v2/payments/captures/CAP-PEND' }] } });
assert.equal(wh.status, 200);
const refunded = await call('GET', `/api/vouchers/${pendOrder.codes[0]}`, null, { 'x-staff-pin': '2468' });
assert.equal(refunded.data.status, 'remboursé');
assert.equal((await call('POST', `/api/vouchers/${pendOrder.codes[0]}/use`, null, { 'x-staff-pin': '2468' })).status, 409);
console.log('✓ bon remboursé sur PayPal : refusé à l\'institut');

// signature de notification invalide → ignorée
globalThis.fetch = async (u, o) => String(u).endsWith('/verify-webhook-signature') ? new Response(JSON.stringify({ verification_status: 'FAILURE' }), { status: 200 }) : realFetch(u, o);
assert.equal((await call('POST', '/api/paypal/webhook', { event_type: 'PAYMENT.CAPTURE.REFUNDED', resource: {} })).status, 401);
globalThis.fetch = realFetch;
console.log('✓ fausse notification PayPal rejetée');

// PDF bon accessoires
const accV = JSON.parse(await KV.get('v:' + accCode));
fs.writeFileSync(new URL('./out/bon-accessoires.pdf', import.meta.url), await (await import('../src/pdf.js')).voucherPDF(accV, 'https://x/verifier?code=' + accCode));
console.log('\nTous les nouveaux tests passent.');

/* ===== Promotions de fêtes ===== */
const today = new Intl.DateTimeFormat('fr-CA', { timeZone: 'Europe/Paris' }).format(new Date());
env.SITE_URL = 'https://site.test/';
const promosFile = { promos: [{ id: 'test', nom: 'Test', du: today, au: today, soins: { 'Escapade au pays des sens': 50, 'Soin du dos': 999 } }] };
globalThis.fetch = async (u, o) => String(u) === 'https://site.test/promos.json' ? new Response(JSON.stringify(promosFile)) : realFetch(u, o);
r = await call('POST', '/api/orders', { buyer, items: [{ type: 'soin', soin: 'Escapade au pays des sens' }, { type: 'soin', soin: 'Soin du dos' }] });
assert.equal(orders[r.data.id].purchase_units[0].amount.value, '100.00'); // 50 (promo) + 50 (prix normal : une "promo" plus chère est ignorée)
promosFile.promos[0].du = '2000-01-01'; promosFile.promos[0].au = '2000-01-02';
r = await call('POST', '/api/orders', { buyer, items: [{ type: 'soin', soin: 'Escapade au pays des sens' }] });
assert.equal(orders[r.data.id].purchase_units[0].amount.value, '65.00');
globalThis.fetch = realFetch;
console.log('✓ prix promotionnel appliqué uniquement pendant la période, jamais plus cher que le prix normal');
