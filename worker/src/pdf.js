// Fabrication du bon cadeau en PDF (format A5 paysage), dans le design choisi.
import { PDFDocument, StandardFonts, rgb } from 'pdf-lib';
import qrcode from 'qrcode-generator';

const hex = h => rgb(parseInt(h.slice(1, 3), 16) / 255, parseInt(h.slice(3, 5), 16) / 255, parseInt(h.slice(5, 7), 16) / 255);
const C = {
  ink: hex('#2A2A33'), soft: hex('#5B6470'), blue: hex('#2B8DBB'), blueSoft: hex('#E7F5FB'),
  magenta: hex('#B8006E'), plum: hex('#5C1360'), pink: hex('#FBE8F2'), gold: hex('#F7D774'),
  sky: hex('#8FD3EE'), white: rgb(1, 1, 1), deep: hex('#8E0657'), rose: hex('#E0559C')
};

// Les polices standard du PDF ne savent pas écrire tous les caractères (émojis…) : on les remplace.
function safe(font, text) {
  let out = '';
  for (const ch of String(text || '')) {
    try { font.encodeText(ch); out += ch; } catch { out += ch.trim() ? '' : ' '; }
  }
  return out.replace(/\s+/g, ' ').trim();
}
function wrap(font, text, size, maxW, maxLines) {
  const words = text.split(' '); const lines = []; let cur = '';
  for (const w of words) {
    const t = cur ? cur + ' ' + w : w;
    if (font.widthOfTextAtSize(t, size) <= maxW) cur = t; else { if (cur) lines.push(cur); cur = w; }
  }
  if (cur) lines.push(cur);
  if (lines.length > maxLines) { lines.length = maxLines; lines[maxLines - 1] = lines[maxLines - 1].replace(/\s*\S*$/, '…'); }
  return lines;
}
function drawQR(page, text, x, y, size, color) {
  const qr = qrcode(0, 'M'); qr.addData(text); qr.make();
  const n = qr.getModuleCount(), m = size / n;
  page.drawRectangle({ x: x - 6, y: y - 6, width: size + 12, height: size + 12, color: C.white });
  for (let r = 0; r < n; r++) for (let c = 0; c < n; c++)
    if (qr.isDark(r, c)) page.drawRectangle({ x: x + c * m, y: y + (n - 1 - r) * m, width: m + 0.2, height: m + 0.2, color });
}

export async function voucherPDF(v, verifyUrl) {
  const doc = await PDFDocument.create();
  doc.setTitle('Bon cadeau Aqua Beauté ' + v.code); doc.setAuthor('Aqua Beauté Mutzig');
  const W = 595, H = 420; const page = doc.addPage([W, H]);
  const serif = await doc.embedFont(StandardFonts.TimesRomanBoldItalic);
  const serifIt = await doc.embedFont(StandardFonts.TimesRomanItalic);
  const sans = await doc.embedFont(StandardFonts.Helvetica);
  const bold = await doc.embedFont(StandardFonts.HelveticaBold);
  const mono = await doc.embedFont(StandardFonts.CourierBold);
  const fete = v.design === 'fete';

  // --- fond ---
  if (fete) {
    page.drawRectangle({ x: 0, y: 0, width: W, height: H, color: C.deep });
    page.drawCircle({ x: W, y: 0, size: 300, color: C.magenta });
    page.drawCircle({ x: W + 40, y: -40, size: 190, color: C.rose, opacity: 0.55 });
    page.drawCircle({ x: -60, y: H + 40, size: 200, color: C.plum, opacity: 0.8 });
    const conf = [[40, 360, C.gold], [130, 395, C.sky], [250, 380, C.white], [320, 40, C.gold], [190, 25, C.sky], [70, 120, C.white], [420, 395, C.gold], [560, 210, C.sky], [470, 160, C.white], [30, 230, C.gold], [300, 250, C.white], [520, 120, C.gold]];
    conf.forEach(([x, y, c], i) => page.drawCircle({ x, y, size: i % 3 ? 3 : 4.5, color: c, opacity: 0.95 }));
    // ballons
    [[470, 330, 26, 32, C.gold], [515, 345, 28, 34, C.sky], [552, 318, 21, 26, C.white]].forEach(([x, y, rx, ry, c]) => {
      page.drawLine({ start: { x, y: y - ry }, end: { x: x - 6 + (x % 12), y: y - ry - 70 }, thickness: 0.8, color: C.white, opacity: 0.7 });
      page.drawEllipse({ x, y, xScale: rx, yScale: ry, color: c });
      page.drawEllipse({ x: x - rx * 0.35, y: y + ry * 0.3, xScale: rx * 0.18, yScale: ry * 0.25, color: C.white, opacity: 0.5 });
    });
  } else {
    page.drawRectangle({ x: 0, y: 0, width: W, height: H, color: C.white });
    page.drawCircle({ x: W - 40, y: H + 30, size: 190, color: C.pink });
    page.drawCircle({ x: -30, y: -50, size: 170, color: C.blueSoft });
    page.drawRectangle({ x: 14, y: 14, width: W - 28, height: H - 28, borderColor: C.magenta, borderWidth: 0.8, borderOpacity: 0.35 });
  }
  const txt = fete ? C.white : C.ink, sub = fete ? C.white : C.soft, accent = fete ? C.gold : C.plum, big = fete ? C.white : C.blue, brand = fete ? C.white : C.magenta;

  // --- en-tête ---
  page.drawText('Aqua Beauté', { x: 40, y: H - 70, size: 34, font: serif, color: brand });
  page.drawText('INSTITUT DE BEAUTÉ · MUTZIG', { x: 42, y: H - 88, size: 8, font: bold, color: sub, opacity: 0.85 });

  // --- titre et contenu ---
  const title = fete ? safe(serif, v.occasion || 'Joyeux anniversaire') : 'Bon cadeau';
  page.drawText(title, { x: 40, y: H - 150, size: 30, font: serif, color: accent });
  page.drawText(safe(sans, v.to ? 'Pour ' + v.to : 'Pour vous'), { x: 42, y: H - 176, size: 13, font: sans, color: sub });
  if (v.type === 'soin') {
    const l = wrap(bold, safe(bold, v.soin), 20, 330, 2);
    l.forEach((line, i) => page.drawText(line, { x: 40, y: H - 210 - i * 24, size: 20, font: bold, color: big }));
  } else {
    page.drawText(String(v.price).replace('.', ',') + ' €', { x: 40, y: H - 222, size: 44, font: bold, color: big });
  }
  if (v.msg) {
    const l = wrap(serifIt, '« ' + safe(serifIt, v.msg) + ' »', 12.5, 330, 3);
    l.forEach((line, i) => page.drawText(line, { x: 42, y: H - 262 - i * 16, size: 12.5, font: serifIt, color: sub }));
  }

  // --- code, validité, QR ---
  page.drawText('Code', { x: 42, y: 92, size: 8, font: bold, color: sub });
  const cw = mono.widthOfTextAtSize(v.code, 16);
  page.drawRectangle({ x: 38, y: 62, width: cw + 16, height: 24, color: fete ? C.white : C.pink });
  page.drawText(v.code, { x: 46, y: 69, size: 16, font: mono, color: C.ink });
  const valid = v.type === 'soin' ? 'Soin offert · valable jusqu\'au ' + v.untilFr : 'Valable jusqu\'au ' + v.untilFr + ' sur toutes les prestations';
  page.drawText(safe(sans, valid), { x: 42, y: 44, size: 9, font: sans, color: sub });
  page.drawText('8 rue du Maréchal Foch, 67190 Mutzig · 03 88 23 00 98 · Sur rendez-vous', { x: 42, y: 28, size: 8, font: sans, color: sub, opacity: 0.85 });
  drawQR(page, verifyUrl, W - 140, 40, 96, C.ink);
  page.drawText('Présentez ce bon', { x: W - 140, y: 22, size: 7.5, font: sans, color: sub });
  page.drawText('à l\'institut', { x: W - 140, y: 13, size: 7.5, font: sans, color: sub });

  return await doc.save();
}

export function toBase64(bytes) {
  let s = ''; const chunk = 0x8000;
  for (let i = 0; i < bytes.length; i += chunk) s += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
  return btoa(s);
}
