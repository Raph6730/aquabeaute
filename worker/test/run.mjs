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
