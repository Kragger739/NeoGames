// One-off generator for the raster social/PWA assets that can't be authored by
// hand: public/og-image.png (1200x630 share card) and public/apple-touch-icon.png
// (180x180 iOS home-screen icon). Both are rasterised from the brand mark in
// public/favicon.svg so they stay in sync with it.
//
// Not part of the build. Re-run after changing the logo or the card copy:
//   cd frontend && node scripts/gen-og-assets.mjs
// Requires `sharp` (install transiently):  npx --yes --package=sharp node scripts/gen-og-assets.mjs
//
// The committed PNGs are what ship; this script only needs to run when they
// change.

import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { writeFile } from "node:fs/promises";

import sharp from "sharp";

const here = dirname(fileURLToPath(import.meta.url));
const publicDir = resolve(here, "..", "public");

const CREAM = "#FFF9F2";
const CORAL = "#FF5C7A";
const INK = "#1F1B2E";
const MUTED = "#6B6577";

// The favicon.svg mark, inlined so both assets render the exact same glyph.
const mark = `
  <path d="M24 3c1.1 0 2 .9 2 2v14.2l10-5.8a2 2 0 0 1 2.7.73l3 5.2a2 2 0 0 1-.73 2.73L31 27l10 5.77a2 2 0 0 1 .73 2.73l-3 5.2a2 2 0 0 1-2.73.73L26 35.8V44a2 2 0 0 1-4 0v-8.2L12 41.6a2 2 0 0 1-2.73-.73l-3-5.2a2 2 0 0 1 .73-2.73L17 27 7 21.23a2 2 0 0 1-.73-2.73l3-5.2a2 2 0 0 1 2.73-.73l10 5.8V5c0-1.1.9-2 2-2z" fill="${CORAL}"/>
  <circle cx="39" cy="10" r="4" fill="#FFC93C"/>
  <circle cx="7" cy="38" r="3.5" fill="#17C3B2"/>
  <circle cx="41" cy="37" r="3" fill="#8B5CF6"/>
`;

const FONT_STACK =
  "'Space Grotesk','Plus Jakarta Sans','Segoe UI',Helvetica,Arial,sans-serif";

const ogSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
  <rect width="1200" height="630" fill="${CREAM}"/>
  <rect x="0" y="0" width="1200" height="12" fill="${CORAL}"/>
  <circle cx="1060" cy="120" r="46" fill="#FFC93C"/>
  <circle cx="1140" cy="470" r="30" fill="#17C3B2"/>
  <circle cx="120" cy="540" r="26" fill="#8B5CF6"/>
  <circle cx="1010" cy="560" r="18" fill="#FF6FB5"/>
  <g transform="translate(96 210) scale(4.2)">${mark}</g>
  <text x="356" y="290" font-family="${FONT_STACK}" font-size="104" font-weight="800" fill="${INK}">NeoGames</text>
  <text x="360" y="360" font-family="${FONT_STACK}" font-size="42" font-weight="600" fill="${MUTED}">Party games for game night</text>
  <text x="360" y="420" font-family="${FONT_STACK}" font-size="30" font-weight="500" fill="${MUTED}">Guess the song. Tease your friends. Take the win.</text>
</svg>`;

// Mark kept inside a generous safe zone (~18% padding) so it survives the
// circular crop applied to "maskable" icons.
const touchSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180">
  <rect width="180" height="180" rx="40" fill="${CREAM}"/>
  <g transform="translate(33 33) scale(2.375)">${mark}</g>
</svg>`;

async function render(svg, out, label) {
  const png = await sharp(Buffer.from(svg)).png().toBuffer();
  await writeFile(resolve(publicDir, out), png);
  console.log(`wrote public/${out}  (${png.length} bytes) - ${label}`);
}

await render(ogSvg, "og-image.png", "1200x630 social share card");
await render(touchSvg, "apple-touch-icon.png", "180x180 iOS touch icon");
