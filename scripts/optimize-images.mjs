/**
 * Bild-Optimierungspipeline
 * -------------------------
 * Quelle:  src/assets/originals/  (unveränderte Originale, max. verfügbare Auflösung)
 * Ziel:    public/images/         (AVIF primär + WebP-Fallback, mehrere Breiten)
 *
 * Vorgehen pro Bild:
 *   1. Pixelabmessungen auf reale Darstellungsgröße reduzieren (+ 10–20 % Reserve)
 *   2. AVIF erzeugen (effort 7), WebP-Fallback erzeugen
 *   3. Metadaten werden von sharp beim Re-Encoden automatisch entfernt
 *
 * Ausführen:  pnpm optimize:images
 */
import sharp from 'sharp';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url)) + '/..';
const SRC = path.join(root, 'src/assets/originals');
const OUT = path.join(root, 'public/images');

const AVIF_EFFORT = 7;

/**
 * Qualitäts-Budget je nach realem Website-Einsatz:
 * - stark überlagerte / geblurte Hintergründe -> sehr niedrige Qualität möglich
 * - sichtbare Hintergründe -> mittlere Qualität
 * - Inhaltsfotos mit Gesichtern / LCP -> höhere Qualität
 */
const jobs = [
  {
    src: 'Rauchmelder-anbringen-320-fd126e-320.webp',
    name: 'hero-techniker',
    widths: [320], // Quelle existiert nur in 320 px — keine größere Variante auf dem CDN
    avifQ: 58,
    webpQ: 82,
  },
  {
    src: 'Logo_Normcheck_Rauchmelder-320-c7799d-320.webp',
    name: 'logo-normcheck',
    widths: [320], // dargestellt max. 240 px breit -> 33 % Reserve
    avifQ: 64,
    webpQ: 84,
  },
  {
    src: 'Unsere-Techniker-mit-Wagen-768-be991c-768.webp',
    name: 'techniker-team',
    widths: [480, 768], // Inhaltsfoto mit Gesichtern: Desktop max. ~600 px, Mobile 100 vw
    avifQ: 56,
    webpQ: 76,
  },
  {
    src: 'cecilia-miraldi-tylzY0GiFpY-unsplash-1536-9b5385-1536.webp',
    name: 'bg-pruefung',
    widths: [768, 1280, 1536], // > 90 % weißes Overlay + Blur -> kaum sichtbar
    avifQ: 34,
    webpQ: 58,
  },
  {
    src: 'vvills-ZKe0ChdQTvk-unsplash-1536-599060-1536.webp',
    name: 'bg-services',
    widths: [768, 1280, 1536], // dunkles Overlay 28–55 %, Motiv gut sichtbar
    avifQ: 44,
    webpQ: 68,
  },
  {
    src: 'lasse-diercks-VrLHlLNVw7M-unsplash-1024-7fc208-1024.webp',
    name: 'bg-formular',
    widths: [640, 1024], // CSS blur(8px) + 70 % Overlay -> Detailtiefe irrelevant
    avifQ: 30,
    webpQ: 54,
  },
  {
    src: 'paul-lichtblau-uMId2WIsWyM-unsplash-1024-58005d-1024.webp',
    name: 'bg-ablauf',
    widths: [640, 1024], // 86–91 % dunkles Overlay + blur(1px)
    avifQ: 30,
    webpQ: 54,
  },
  {
    src: 'sebastian-herrmann-k08MDpZm5zY-unsplash-1024-730d16-1024.webp',
    name: 'bg-berlin',
    widths: [640, 1024], // 65–80 % Overlay, Skyline erkennbar
    avifQ: 42,
    webpQ: 66,
  },
  {
    src: 'photo_2026-09-14_19-40-13-Photoroom-320.webp',
    name: 'rauchmelder-freisteller',
    widths: [256, 320], // dargestellt max. 160 px (Desktop only)
    avifQ: 56,
    webpQ: 80,
  },
];

const kb = (b) => (b / 1024).toFixed(1).padStart(7);

async function processJob(job) {
  const input = path.join(SRC, job.src);
  const meta = await sharp(input).metadata();
  const results = [];
  for (const width of job.widths) {
    if (width > meta.width) continue;
    const resized = sharp(input).resize({ width, withoutEnlargement: true });

    const avif = await resized.clone()
      .avif({ quality: job.avifQ, effort: AVIF_EFFORT })
      .toBuffer({ resolveWithObject: true });
    const avifName = `${job.name}-${width}.avif`;
    await writeFile(path.join(OUT, avifName), avif.data);

    const webp = await resized.clone()
      .webp({ quality: job.webpQ })
      .toBuffer({ resolveWithObject: true });
    const webpName = `${job.name}-${width}.webp`;
    await writeFile(path.join(OUT, webpName), webp.data);

    results.push({ width, height: avif.info.height, avif: avif.data.length, webp: webp.data.length });
  }
  return { job, meta, results };
}

/** Weiße Silhouette des Logos (Alpha-Maske) für das OG-Bild auf dunklem Grund. */
async function whiteLogoPng(height) {
  const logo = path.join(SRC, 'Logo_Normcheck_Rauchmelder-320-c7799d-320.webp');
  const resized = sharp(logo).resize({ height });
  const { data, info } = await resized.png().toBuffer({ resolveWithObject: true });
  const alpha = await sharp(data).extractChannel(3).toBuffer();
  return sharp({
    create: { width: info.width, height: info.height, channels: 3, background: '#ffffff' },
  })
    .joinChannel(alpha)
    .png()
    .toBuffer();
}

/** Open-Graph-Bild 1200×630 (Fallback für SEOHead: /home-og.jpg). */
async function buildOgImage() {
  const W = 1200, H = 630;
  const logo = await whiteLogoPng(64);
  const svg = `<svg width="${W}" height="${H}" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0d4672"/>
      <stop offset="0.6" stop-color="#062b47"/>
      <stop offset="1" stop-color="#041d33"/>
    </linearGradient>
    <radialGradient id="glow" cx="0.85" cy="0.25" r="0.9">
      <stop offset="0" stop-color="#1a5d8f" stop-opacity="0.55"/>
      <stop offset="0.6" stop-color="#0d4672" stop-opacity="0"/>
    </radialGradient>
  </defs>
  <rect width="${W}" height="${H}" fill="url(#bg)"/>
  <rect width="${W}" height="${H}" fill="url(#glow)"/>
  <text x="80" y="330" font-family="Arial, Helvetica, sans-serif" font-size="82" font-weight="bold" fill="#ffffff" letter-spacing="-2">Rauchmelderservice Berlin</text>
  <text x="80" y="400" font-family="Arial, Helvetica, sans-serif" font-size="40" font-weight="bold" fill="#52ce1b">Installation · Wartung · Prüfung</text>
  <text x="80" y="462" font-family="Arial, Helvetica, sans-serif" font-size="28" fill="#c3d3e0">Für Vermieter, Eigentümer &amp; Hausverwaltungen – in ganz Berlin</text>
  <rect x="80" y="520" width="240" height="6" rx="3" fill="#52ce1b"/>
</svg>`;
  const og = await sharp(Buffer.from(svg), { density: 96 })
    .composite([{ input: logo, left: 80, top: 72 }])
    .flatten({ background: '#062b47' })
    .jpeg({ quality: 84, mozjpeg: true })
    .toBuffer();
  await writeFile(path.join(OUT, 'home-og.jpg'), og);
  return og.length;
}

/** Favicons aus dem lokalen SVG rendern. */
async function buildFavicons() {
  const svg = await readFile(path.join(root, 'public/favicon.svg'));
  const apple = await sharp(svg, { density: 384 })
    .resize(180, 180)
    .flatten({ background: '#ffffff' })
    .png()
    .toBuffer();
  await writeFile(path.join(OUT, 'apple-touch-icon.png'), apple);
  const f32 = await sharp(svg, { density: 384 })
    .resize(32, 32)
    .png()
    .toBuffer();
  await writeFile(path.join(OUT, 'favicon-32.png'), f32);
  return { apple: apple.length, f32: f32.length };
}

await mkdir(OUT, { recursive: true });
console.log('Bild                       Breite  Höhe      AVIF      WebP');
console.log('─'.repeat(64));

let totalAvif = 0;
for (const job of jobs) {
  const { meta, results } = await processJob(job);
  for (const r of results) {
    totalAvif += r.avif;
    console.log(
      `${job.name.padEnd(26)} ${String(r.width).padStart(5)} ${String(r.height).padStart(6)} ${kb(r.avif)} KB ${kb(r.webp)} KB` +
      `   (Quelle ${meta.width}×${meta.height})`,
    );
  }
}
const ogSize = await buildOgImage();
const fav = await buildFavicons();
console.log('─'.repeat(64));
console.log(`OG-Bild home-og.jpg 1200×630:            ${kb(ogSize)} KB`);
console.log(`apple-touch-icon.png 180×180:            ${kb(fav.apple)} KB`);
console.log(`favicon-32.png 32×32:                  ${kb(fav.f32)} KB`);
console.log(`Σ alle AVIF-Varianten:                 ${kb(totalAvif)} KB`);
