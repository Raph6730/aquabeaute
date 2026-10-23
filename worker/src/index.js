// Moteur des bons cadeaux Aqua Beauté (Cloudflare Worker)
//
//  POST /api/orders                 le site envoie le panier → le moteur calcule le prix lui-même et crée la commande PayPal
//  POST /api/orders/:id/capture     après validation PayPal → encaissement vérifié, création des bons, PDF, e-mails
//  POST /api/paypal/webhook         notifications PayPal (paiement confirmé plus tard, page fermée, remboursement)
//  GET  /verifier                   page privée de l'institut (code PIN) pour scanner et valider les bons
//  GET  /api/vouchers               liste des bons (PIN)
//  GET  /api/vouchers/:code         détail d'un bon (PIN)
//  POST /api/vouchers/:code/use     marquer un bon comme utilisé (PIN)
//  POST /api/vouchers/:code/unuse   annuler une utilisation par erreur (PIN)
import { SOINS, MONTANT_MIN, MONTANT_MAX, TYPES } from './catalog.js';
import { voucherPDF, toBase64 } from './pdf.js';
import { VERIFIER_HTML } from './verifier.js';

const ALPH = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const OCCASIONS = ['Joyeux anniversaire', 'Bonne fête'];
const json = (data, status = 200, headers = {}) => new Response(JSON.stringify(data), { status, headers: { 'content-type': 'application/json; charset=utf-8', ...headers } });
const clean = (s, n) => String(s ?? '').replace(/[\u0000-\u001f<>]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, n);
const euros = n => (Math.round(n * 100) / 100).toFixed(2);
const frDate = d => new Date(d).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Europe/Paris' });

export default {
  async fetch(req, env, ctx) {
    const url = new URL(req.url);
    const cors = corsHeaders(req, env);
    if (req.method === 'OPTIONS') return new Response(null, { status: 204, headers: cors });
    try {
      const p = url.pathname.replace(/\/+$/, '') || '/';
      if (p === '/' ) return new Response('Moteur des bons cadeaux Aqua Beauté : OK', { headers: { 'content-type': 'text/plain; charset=utf-8' } });
      if ((p === '/verifier' || p === '/api/verifier') && req.method === 'GET') return new Response(VERIFIER_HTML, { headers: { 'content-type': 'text/html; charset=utf-8', 'x-robots-tag': 'noindex', 'cache-control': 'no-store' } });
      if (p === '/api/orders' && req.method === 'POST') return withCors(await createOrder(req, env), cors);
      let m;
      if ((m = p.match(/^\/api\/orders\/([A-Z0-9]+)\/capture$/)) && req.method === 'POST') return withCors(await captureOrder(m[1], env, url), cors);
      if (p === '/api/paypal/webhook' && req.method === 'POST') return await webhook(req, env, url);
      if (p.startsWith('/api/vouchers')) {
        const auth = await checkPin(req, env); if (auth) return auth;
        if (p === '/api/vouchers' && req.method === 'GET') return listVouchers(env, url);
        if (p === '/api/vouchers/export.csv' && req.method === 'GET') return exportCsv(env);
        if ((m = p.match(/^\/api\/vouchers\/([A-Z0-9-]+)$/)) && req.method === 'GET') return getVoucher(m[1], env);
        if ((m = p.match(/^\/api\/vouchers\/([A-Z0-9-]+)\/(use|unuse)$/)) && req.method === 'POST') return useVoucher(m[1], m[2] === 'use', env);
      }
      return json({ error: 'Introuvable' }, 404);
    } catch (e) {
      console.error(e);
      return withCors(json({ error: e.publicMessage || 'Erreur interne, réessayez ou appelez l\'institut au 03 88 23 00 98.' }, e.status || 500), cors);
    }
  }
};

/* ---------------- CORS ---------------- */
function corsHeaders(req, env) {
  const o = req.headers.get('origin') || '';
  const allowed = (env.ALLOWED_ORIGIN || '').split(',').map(s => s.trim()).filter(Boolean);
  if (!allowed.includes(o)) return {};
  return { 'access-control-allow-origin': o, 'access-control-allow-methods': 'POST, GET, OPTIONS', 'access-control-allow-headers': 'content-type', 'vary': 'origin' };
}
function withCors(res, cors) { for (const [k, v] of Object.entries(cors)) res.headers.set(k, v); return res; }
function fail(msg, status = 400) { const e = new Error(msg); e.publicMessage = msg; e.status = status; return e; }

/* ---------------- PayPal ---------------- */
const ppBase = env => env.PAYPAL_ENV === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
async function ppToken(env) {
  const r = await fetch(ppBase(env) + '/v1/oauth2/token', { method: 'POST', headers: { authorization: 'Basic ' + btoa(env.PAYPAL_CLIENT_ID + ':' + env.PAYPAL_SECRET), 'content-type': 'application/x-www-form-urlencoded' }, body: 'grant_type=client_credentials' });
  if (!r.ok) throw fail('Connexion à PayPal impossible pour le moment.', 502);
  return (await r.json()).access_token;
}
async function pp(env, path, body, extraHeaders = {}) {
  const r = await fetch(ppBase(env) + path, { method: 'POST', headers: { authorization: 'Bearer ' + await ppToken(env), 'content-type': 'application/json', ...extraHeaders }, body: body ? JSON.stringify(body) : undefined });
  const data = await r.json().catch(() => ({}));
  return { ok: r.ok, status: r.status, data };
}

/* ---------------- Commande ---------------- */
/* Promotions de fêtes : lues dans promos.json sur le site (même fichier que l'affichage), dates en heure de Paris */
async function activePromo(env) {
  if (!env.SITE_URL) return null;
  try {
    const r = await fetch(env.SITE_URL.replace(/\/?$/, '/') + 'promos.json', { cf: { cacheTtl: 300 } });
    if (!r.ok) return null;
    const j = await r.json();
    const today = new Intl.DateTimeFormat('fr-CA', { timeZone: 'Europe/Paris' }).format(new Date());
    return (j.promos || []).find(p => p.du <= today && today <= p.au) || null;
  } catch { return null; }
}

function normalizeCart(body, promo) {
  if (body?.cgv !== true) throw fail('Merci d\'accepter les conditions générales de vente.');
  const items = Array.isArray(body?.items) ? body.items : [];
  if (!items.length || items.length > 20) throw fail('Panier vide ou trop grand.');
  const out = items.map(it => {
    const type = TYPES[it.type] ? it.type : 'montant';
    let price, soin = '';
    if (type === 'soin') {
      soin = clean(it.soin, 120);
      price = SOINS[soin];
      if (!price) throw fail('Ce soin n\'est pas disponible en bon cadeau : ' + soin);
      const pp_ = Number(promo?.soins?.[soin]);
      if (pp_ > 0 && pp_ < price) price = pp_;   // prix promotionnel en cours
    } else {
      price = Math.round(Number(it.amount));
      if (!(price >= MONTANT_MIN && price <= MONTANT_MAX)) throw fail(`Le montant doit être entre ${MONTANT_MIN} et ${MONTANT_MAX} €.`);
    }
    const qty = Math.max(1, Math.min(10, Math.round(Number(it.qty) || 1)));
    return {
      type, soin, price, qty,
      design: it.design === 'fete' ? 'fete' : 'classique',
      occasion: OCCASIONS.includes(it.occasion) ? it.occasion : OCCASIONS[0],
      to: clean(it.to, 30), msg: clean(it.msg, 160),
      format: it.format === 'papier' ? 'papier' : 'e'
    };
  });
  const b = body.buyer || {};
  const buyer = { name: clean(b.name, 80), email: clean(b.email, 120).toLowerCase(), tel: clean(b.tel, 30) };
  if (!buyer.name || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(buyer.email)) throw fail('Nom et e-mail valides obligatoires.');
  return { items: out, buyer };
}
const label = it => it.type === 'soin' ? 'Bon cadeau · ' + it.soin : it.type === 'accessoire' ? 'Bon cadeau accessoires ' + it.price + ' €' : 'Bon cadeau ' + it.price + ' €';

async function createOrder(req, env) {
  const cart = normalizeCart(await req.json().catch(() => null), await activePromo(env));
  const total = cart.items.reduce((s, it) => s + it.price * it.qty, 0);
  const ref = 'AB-' + randomCode(6);
  const { ok, data } = await pp(env, '/v2/checkout/orders', {
    intent: 'CAPTURE',
    purchase_units: [{
      reference_id: ref, custom_id: ref,
      description: clean('Aqua Beauté · bons cadeaux · ' + cart.buyer.name, 127),
      amount: { currency_code: 'EUR', value: euros(total), breakdown: { item_total: { currency_code: 'EUR', value: euros(total) } } },
      items: cart.items.map(it => ({
        name: clean(label(it) + (it.to ? ' pour ' + it.to : ''), 127),
        description: clean('[' + TYPES[it.type] + '] ' + (it.design === 'fete' ? it.occasion : 'Classique') + ' · ' + (it.format === 'e' ? 'par e-mail' : 'retrait institut') + (it.msg ? ' · ' + it.msg : ''), 127),
        quantity: String(it.qty), unit_amount: { currency_code: 'EUR', value: euros(it.price) }, category: 'DIGITAL_GOODS'
      }))
    }],
    application_context: { brand_name: 'Aqua Beauté', locale: 'fr-FR', shipping_preference: 'NO_SHIPPING', user_action: 'PAY_NOW' }
  });
  if (!ok || !data.id) throw fail('PayPal a refusé la commande. Réessayez dans un instant.', 502);
  await env.BONS.put('o:' + data.id, JSON.stringify({ ref, total: euros(total), ...cart, status: 'créée', created: Date.now() }), { expirationTtl: 3 * 86400 });
  return json({ id: data.id, ref });
}

async function captureOrder(orderId, env, url) {
  const key = 'o:' + orderId;
  const order = JSON.parse(await env.BONS.get(key) || 'null');
  if (!order) throw fail('Commande inconnue ou expirée.', 404);
  if (order.status === 'payée') return json(publicResult(order)); // déjà traitée (double clic, rechargement…)

  const { ok, data } = await pp(env, `/v2/checkout/orders/${orderId}/capture`, null, { 'PayPal-Request-Id': 'cap-' + orderId });
  const issues = (data?.details || []).map(d => d.issue);
  if (!ok && issues.includes('INSTRUMENT_DECLINED'))   // carte refusée : PayPal propose un autre moyen de paiement
    return json({ error: 'Votre moyen de paiement a été refusé. Choisissez-en un autre.', restart: true }, 422);
  let cap = data?.purchase_units?.[0]?.payments?.captures?.[0];
  if (!ok && issues.includes('ORDER_ALREADY_CAPTURED')) cap = await getCapture(env, orderId);
  else if (!ok) throw fail('Le paiement n\'a pas abouti. Aucun montant n\'a été prélevé.', 402);
  return json(await finalize(orderId, cap, env, url));
}

async function getCapture(env, orderId) {
  const r = await fetch(ppBase(env) + '/v2/checkout/orders/' + orderId, { headers: { authorization: 'Bearer ' + await ppToken(env) } });
  const d = await r.json().catch(() => ({}));
  return d?.purchase_units?.[0]?.payments?.captures?.[0];
}

// Création des bons une fois l'argent confirmé (appelé par le site ou par la notification PayPal).
async function finalize(orderId, cap, env, url) {
  const key = 'o:' + orderId;
  const order = JSON.parse(await env.BONS.get(key) || 'null');
  if (!order) throw fail('Commande inconnue ou expirée.', 404);
  if (order.status === 'payée') return publicResult(order);
  if (!cap || cap.amount?.currency_code !== 'EUR' || cap.amount?.value !== order.total)
    throw fail('Paiement non confirmé par PayPal. Contactez l\'institut au 03 88 23 00 98.', 402);
  if (cap.status === 'PENDING') {           // PayPal vérifie encore le paiement : les bons partiront à la confirmation
    if (order.status !== 'en attente') {
      order.status = 'en attente'; order.capture = cap.id;
      await env.BONS.put(key, JSON.stringify(order));
      await env.BONS.put('c:' + cap.id, orderId);
      try { await brevo(env, { to: [{ email: env.SHOP_EMAIL || env.SENDER_EMAIL }], subject: 'Paiement PayPal en attente : ' + order.ref, htmlContent: mailLayout(`<p>PayPal vérifie le paiement de ${esc(order.buyer.name)} (${order.total.replace('.', ',')} €, commande ${order.ref}). Les bons seront créés et envoyés automatiquement dès la confirmation.</p>`) }); } catch (e) { console.error(e); }
    }
    return { ok: true, pending: true, ref: order.ref, vouchers: [] };
  }
  if (cap.status !== 'COMPLETED') throw fail('Paiement non confirmé par PayPal. Contactez l\'institut au 03 88 23 00 98.', 402);

  // Codes déterministes : deux demandes simultanées produisent les mêmes codes, donc jamais de bons en double.
  const created = Date.now();
  const expires = new Date(created); expires.setFullYear(expires.getFullYear() + 1);
  const vouchers = []; let n = 0;
  for (const it of order.items) for (let i = 0; i < it.qty; i++) {
    const code = await codeFor(env, orderId, n++);
    const prev = JSON.parse(await env.BONS.get('v:' + code) || 'null');
    const v = prev || { code, ...it, qty: 1, buyer: order.buyer, order: order.ref, paypalOrder: orderId, paypalCapture: cap.id, created, expires: expires.getTime(), untilFr: frDate(expires), status: 'valide' };
    if (!prev) await env.BONS.put('v:' + code, JSON.stringify(v), { metadata: meta(v) });
    vouchers.push(v);
  }
  order.status = 'payée'; order.paid = created; order.capture = cap.id; order.codes = vouchers.map(v => v.code);
  order.vouchers = vouchers.map(v => ({ code: v.code, design: v.design, occasion: v.occasion, to: v.to, msg: v.msg, type: v.type, soin: v.soin, price: v.price, until: v.untilFr, format: v.format, created: v.created, expires: v.expires }));
  await env.BONS.put(key, JSON.stringify(order)); // conservée sans expiration
  await env.BONS.put('c:' + cap.id, orderId);

  // PDF + e-mails (un échec d'envoi ne bloque pas : les bons existent et l'institut a la liste)
  if (!order.emailed) {
    try { await sendEmails(order, vouchers, env, url); order.emailed = true; }
    catch (e) { console.error('e-mail', e); order.emailError = String(e.message || e); }
    await env.BONS.put(key, JSON.stringify(order));
  }
  return publicResult(order);
}
async function codeFor(env, orderId, n) {
  const h = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode((env.PAYPAL_SECRET || '') + '|' + orderId + '|' + n)));
  const c = Array.from(h.slice(0, 8), x => ALPH[x % ALPH.length]).join('');
  return 'AQB-' + c.slice(0, 4) + '-' + c.slice(4);
}

/* ---------------- Notifications PayPal (webhook) ----------------
   Filet de sécurité : si le client ferme la page juste après avoir payé, si PayPal confirme plus tard un paiement
   « en attente », ou si Christelle rembourse un bon depuis PayPal. */
async function webhook(req, env, url) {
  if (!env.PAYPAL_WEBHOOK_ID) return json({ error: 'Webhook non configuré' }, 503);
  const raw = await req.text(); let ev; try { ev = JSON.parse(raw); } catch { return json({ error: 'JSON invalide' }, 400); }
  const h = n => req.headers.get(n);
  const { ok, data } = await pp(env, '/v1/notifications/verify-webhook-signature', {
    auth_algo: h('paypal-auth-algo'), cert_url: h('paypal-cert-url'), transmission_id: h('paypal-transmission-id'),
    transmission_sig: h('paypal-transmission-sig'), transmission_time: h('paypal-transmission-time'), webhook_id: env.PAYPAL_WEBHOOK_ID, webhook_event: ev
  });
  if (!ok || data.verification_status !== 'SUCCESS') return json({ error: 'Signature invalide' }, 401);
  const r = ev.resource || {};
  try {
    if (ev.event_type === 'CHECKOUT.ORDER.APPROVED') {
      if (await env.BONS.get('o:' + r.id)) await captureOrder(r.id, env, url);
    } else if (ev.event_type === 'PAYMENT.CAPTURE.COMPLETED') {
      const orderId = r.supplementary_data?.related_ids?.order_id || await env.BONS.get('c:' + r.id);
      if (orderId && await env.BONS.get('o:' + orderId)) await finalize(orderId, r, env, url);
    } else if (ev.event_type === 'PAYMENT.CAPTURE.REFUNDED' || ev.event_type === 'PAYMENT.CAPTURE.REVERSED') {
      const capId = (r.links || []).find(l => l.rel === 'up')?.href?.split('/').pop() || r.id;
      const orderId = await env.BONS.get('c:' + capId);
      const order = orderId && JSON.parse(await env.BONS.get('o:' + orderId) || 'null');
      for (const code of order?.codes || []) {
        const v = JSON.parse(await env.BONS.get('v:' + code) || 'null');
        if (v && v.status !== 'utilisé') { v.status = 'remboursé'; v.refundedAt = Date.now(); await env.BONS.put('v:' + code, JSON.stringify(v), { metadata: meta(v) }); }
      }
    }
  } catch (e) { console.error('webhook', e); return json({ error: 'Traitement impossible' }, 500); } // PayPal réessaiera
  return json({ ok: true });
}
const publicResult = o => ({ ok: true, ref: o.ref, vouchers: o.vouchers || [], emailed: !!o.emailed, pending: o.status === 'en attente' });
const meta = v => ({ to: v.to, price: v.price, type: v.type, soin: v.soin.slice(0, 60), status: v.status, created: v.created, expires: v.expires, buyer: v.buyer.name.slice(0, 40), usedAt: v.usedAt || 0 });
function randomCode(n) { return Array.from(crypto.getRandomValues(new Uint8Array(n)), x => ALPH[x % ALPH.length]).join(''); }

/* ---------------- E-mails (Brevo) ---------------- */
async function sendEmails(order, vouchers, env, url) {
  const verify = c => `${url.origin}/verifier?code=${c}`;
  const pdfs = [];
  for (const v of vouchers) pdfs.push({ name: `Bon-cadeau-Aqua-Beaute-${v.code}.pdf`, content: toBase64(await voucherPDF(v, verify(v.code))) });
  const eItems = vouchers.map((v, i) => ({ v, pdf: pdfs[i] }));
  const toBuyer = eItems.filter(x => x.v.format === 'e');
  const papier = eItems.filter(x => x.v.format === 'papier');
  const list = vouchers.map(v => `<li><b>${esc(label(v))}</b> <span style="color:#8A929C">(${TYPES[v.type]})</span>${v.to ? ' pour ' + esc(v.to) : ''} · code <b style="font-family:monospace">${v.code}</b>${v.format === 'papier' ? ' · à retirer à l\'institut' : ''}</li>`).join('');

  const buyerHtml = mailLayout(`
    <h1 style="color:#2B8DBB;font-size:22px;margin:0 0 8px">Merci ${esc(order.buyer.name)} !</h1>
    <p>Votre paiement de <b>${order.total.replace('.', ',')} €</b> est confirmé (commande ${order.ref}).</p>
    <ul style="padding-left:18px">${list}</ul>
    ${toBuyer.length ? `<p>${toBuyer.length > 1 ? 'Vos bons cadeaux sont joints' : 'Votre bon cadeau est joint'} à cet e-mail en PDF : il suffit de l'imprimer ou de l'offrir tel quel. Le jour du rendez-vous, il se présente imprimé ou sur téléphone.</p>` : ''}
    ${papier.length ? `<p>${papier.length > 1 ? 'Les bons imprimés vous attendent' : 'Le bon imprimé vous attend'} à l'institut, dans sa jolie enveloppe, aux heures d'ouverture.</p>` : ''}
    <p>Chaque bon est valable un an, sur rendez-vous au <b>03 88 23 00 98</b>.</p>`);
  await brevo(env, { to: [{ email: order.buyer.email, name: order.buyer.name }], subject: toBuyer.length ? 'Votre bon cadeau Aqua Beauté 🎁' : 'Votre commande de bon cadeau Aqua Beauté', htmlContent: buyerHtml, attachment: toBuyer.length ? toBuyer.map(x => x.pdf) : undefined, replyTo: { email: env.SHOP_EMAIL || env.SENDER_EMAIL, name: 'Aqua Beauté' } });

  if (env.SHOP_EMAIL) {
    const shopHtml = mailLayout(`
      <h1 style="color:#B8006E;font-size:20px;margin:0 0 8px">Nouveau bon cadeau vendu</h1>
      <p><b>${order.total.replace('.', ',')} €</b> payés par PayPal · commande ${order.ref}</p>
      <p>Acheteur : ${esc(order.buyer.name)} · ${esc(order.buyer.email)} · ${esc(order.buyer.tel || '—')}</p>
      <ul style="padding-left:18px">${list}</ul>
      ${papier.length ? '<p><b>À imprimer et préparer en enveloppe :</b> ' + papier.map(x => x.v.code).join(', ') + '</p>' : ''}
      <p>Les PDF sont joints. Pour vérifier un bon en caisse : <a href="${url.origin}/verifier">${url.origin}/verifier</a></p>`);
    await brevo(env, { to: [{ email: env.SHOP_EMAIL, name: 'Aqua Beauté' }], subject: `🎁 Bon cadeau vendu : ${order.total.replace('.', ',')} € (${order.buyer.name})`, htmlContent: shopHtml, attachment: pdfs, replyTo: { email: order.buyer.email, name: order.buyer.name } });
  }
}
async function brevo(env, msg) {
  const r = await fetch('https://api.brevo.com/v3/smtp/email', { method: 'POST', headers: { 'api-key': env.BREVO_API_KEY, 'content-type': 'application/json', accept: 'application/json' }, body: JSON.stringify({ sender: { email: env.SENDER_EMAIL, name: env.SENDER_NAME || 'Aqua Beauté' }, ...msg }) });
  if (!r.ok) throw new Error('Brevo ' + r.status + ' ' + (await r.text()).slice(0, 200));
}
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const mailLayout = body => `<!doctype html><html><body style="margin:0;background:#F4FAFD;font-family:Arial,Helvetica,sans-serif;color:#2A2A33">
<div style="max-width:560px;margin:0 auto;padding:24px 16px">
<div style="background:#fff;border-radius:16px;padding:28px 24px;border:1px solid #E6ECF0">
<div style="font-family:Georgia,serif;font-style:italic;font-size:28px;color:#B8006E;margin-bottom:16px">Aqua Beauté</div>
<div style="font-size:15px;line-height:1.6">${body}</div></div>
<p style="font-size:12px;color:#8A929C;text-align:center;margin-top:16px">Aqua Beauté · Institut de beauté SPA · 8 rue du Maréchal Foch, 67190 Mutzig · 03 88 23 00 98<br>SARL AQUA BEAUTE au capital de 7 000 € · SIRET 798 632 329 00016 · TVA FR06 798 632 329</p>
</div></body></html>`;

/* ---------------- Vérification en magasin ---------------- */
async function checkPin(req, env) {
  const ip = req.headers.get('cf-connecting-ip') || 'x';
  const rlKey = 'rl:' + ip;
  const tries = Number(await env.BONS.get(rlKey) || 0);
  if (tries >= 8) return json({ error: 'Trop d\'essais. Réessayez dans 15 minutes.' }, 429);
  const pin = req.headers.get('x-staff-pin') || '';
  if (!env.STAFF_PIN || pin.length < 4 || !timingSafeEqual(pin, env.STAFF_PIN)) {
    await env.BONS.put(rlKey, String(tries + 1), { expirationTtl: 900 });
    return json({ error: 'Code PIN incorrect.' }, 401);
  }
  return null;
}
function timingSafeEqual(a, b) { if (a.length !== b.length) return false; let r = 0; for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i); return r === 0; }
const view = v => ({ code: v.code, label: label(v), type: v.type, typeLabel: TYPES[v.type], soin: v.soin, price: v.price, to: v.to, msg: v.msg, design: v.design, buyer: v.buyer?.name, buyerTel: v.buyer?.tel, format: v.format, created: v.created, expires: v.expires, status: v.status, usedAt: v.usedAt || null, expired: Date.now() > v.expires });
async function getVoucher(code, env) {
  const v = JSON.parse(await env.BONS.get('v:' + code) || 'null');
  if (!v) return json({ error: 'Aucun bon ne correspond à ce code.' }, 404);
  return json(view(v));
}
async function useVoucher(code, use, env) {
  const v = JSON.parse(await env.BONS.get('v:' + code) || 'null');
  if (!v) return json({ error: 'Aucun bon ne correspond à ce code.' }, 404);
  if (use) {
    if (v.status === 'remboursé') return json({ error: 'Ce bon a été remboursé : il n\'est plus valable.', voucher: view(v) }, 409);
    if (v.status === 'utilisé') return json({ error: 'Ce bon a déjà été utilisé le ' + new Date(v.usedAt).toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris' }) + '.', voucher: view(v) }, 409);
    v.status = 'utilisé'; v.usedAt = Date.now();
  } else { v.status = 'valide'; delete v.usedAt; }
  await env.BONS.put('v:' + code, JSON.stringify(v), { metadata: meta(v) });
  return json(view(v));
}
async function listVouchers(env, url) {
  const out = []; let cursor;
  do {
    const r = await env.BONS.list({ prefix: 'v:', cursor, limit: 1000 });
    r.keys.forEach(k => out.push({ code: k.name.slice(2), ...(k.metadata || {}) }));
    cursor = r.list_complete ? null : r.cursor;
  } while (cursor && out.length < 5000);
  out.sort((a, b) => (b.created || 0) - (a.created || 0));
  return json({ vouchers: out.map(v => ({ ...v, expired: Date.now() > v.expires })) });
}

async function exportCsv(env) {
  const rows = []; let cursor;
  do { const r = await env.BONS.list({ prefix: 'v:', cursor, limit: 1000 }); for (const k of r.keys) rows.push(JSON.parse(await env.BONS.get(k.name))); cursor = r.list_complete ? null : r.cursor; } while (cursor);
  rows.sort((a, b) => b.created - a.created);
  const f = t => t ? new Date(t).toLocaleString('fr-FR', { timeZone: 'Europe/Paris', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  const lines = [['Code', 'Type', 'Soin', 'Valeur (€)', 'Pour', 'Acheteur', 'E-mail', 'Téléphone', 'Remise', 'Acheté le', 'Expire le', 'Statut', 'Utilisé le', 'Commande PayPal']]
    .concat(rows.map(v => [v.code, TYPES[v.type], v.soin, String(v.price).replace('.', ','), v.to, v.buyer?.name, v.buyer?.email, v.buyer?.tel, v.format === 'e' ? 'e-mail' : 'institut', f(v.created), f(v.expires), v.status === 'valide' && Date.now() > v.expires ? 'expiré' : v.status, f(v.usedAt), v.paypalOrder]));
  const csv = '\uFEFF' + lines.map(l => l.map(c => '"' + String(c ?? '').replace(/"/g, '""') + '"').join(';')).join('\r\n') + '\r\n';
  return new Response(csv, { headers: { 'content-type': 'text/csv; charset=utf-8', 'content-disposition': 'attachment; filename="bons-cadeaux-aqua-beaute.csv"', 'cache-control': 'no-store' } });
}
