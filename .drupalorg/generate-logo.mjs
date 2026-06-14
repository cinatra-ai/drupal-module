#!/usr/bin/env node
// Generate the Drupal.org / Project Browser project logo for the Cinatra module.
//
// SOURCE OF TRUTH: the Cinatra brand. This script reproduces the sanctioned
// *app-icon* treatment from the cinatra-ai/design repo — the mustard fedora
// mark on the full-bleed navy ground — which is the only brand colourway that
// is correct for a square app/avatar tile. See:
//   design: assets/logo/variants.json  -> applications.appIcon
//           tokens/brand.json          -> color.mustard / color.navy
//           scripts/generate-assets.mjs-> appIconSvg() (identical geometry)
//
// Why the app icon and not the favicon: the favicon is a mustard fedora on a
// WHITE chip with a navy hairline border and a rounded corner. Drupal.org's
// Project Browser explicitly asks for NO rounded corners baked into the PNG
// (it applies its own mask) and renders the tile on its own surface, so the
// white-chip favicon is wrong here. The app icon is full-bleed navy with square
// corners — exactly what Project Browser wants.
//
// OUTPUT (committed alongside this script under .drupalorg/images/):
//   logo.png      512x512, PNG, no animation, <=10 KB (pngquant ~80% quality)
//   logo_svg.txt  the vector master (Project Browser reads this for crisp SVG)
//
// These files are STAGED here, not yet at the repo root, because the drupal.org
// project is parked (not yet created). At project-creation time, promote them:
//   cp .drupalorg/images/logo.png      ./logo.png
//   cp .drupalorg/images/logo_svg.txt  ./logo_svg.txt
//   git add logo.png logo_svg.txt && git commit
// (logo.png / logo_svg.txt MUST live at the repo root on the default branch for
// Project Browser to pick them up.)
//
// Run:  CINATRA_DESIGN_NODE_MODULES=/path/to/cinatra-ai/design/node_modules \
//         node .drupalorg/generate-logo.mjs
//
// Deps: `sharp`, from the design repo's toolchain. This module is NOT inside a
// package that declares sharp, so a bare `import sharp from "sharp"` cannot be
// resolved here, and ESM does NOT honour NODE_PATH for bare specifiers. Point
// CINATRA_DESIGN_NODE_MODULES at a `cinatra-ai/design` checkout that has run
// `npm install`; this script resolves sharp from there via createRequire. (If
// you instead `npm install sharp` somewhere reachable from this file, the env
// var is optional.) pngquant is shelled out to if present on PATH.

import { writeFileSync, mkdirSync, statSync, unlinkSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { execFileSync } from "node:child_process";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const designModules = process.env.CINATRA_DESIGN_NODE_MODULES;
// Resolve sharp from the design checkout first (the canonical toolchain), then
// fall back to normal resolution from this module's location.
const sharp = designModules
  ? require(join(designModules, "sharp"))
  : require("sharp");

const here = dirname(fileURLToPath(import.meta.url));
const outDir = join(here, "images");
mkdirSync(outDir, { recursive: true });

// ---- brand constants (mirror of tokens/brand.json) --------------------------
const MUSTARD = "#c79545"; // color.mustard.value — the brand colour (mark only)
const NAVY = "#15213a"; //    color.navy.value    — app-icon ground

// The fedora mark geometry, copied verbatim from the design repo's
// scripts/generate-assets.mjs (FEDORA_PATHS). Keeping it inline makes this
// script self-contained and deterministic; if the brand mark changes upstream,
// re-copy these two paths and the transform from generate-assets.mjs appIconSvg().
const FEDORA_PATHS =
  `<path d="M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z"/>` +
  `<path d="M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z"/>`;

// app-icon SVG — identical to design repo appIconSvg(): full-bleed navy square,
// fedora at scale 0.835 centred. Square corners (no rounding); Project Browser
// masks it itself.
const SVG =
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">` +
  `<rect width="512" height="512" fill="${NAVY}"/>` +
  `<g transform="translate(256 256) scale(0.835) translate(-256 -160)" fill="${MUSTARD}">${FEDORA_PATHS}</g>` +
  `</svg>`;

// ---- logo_svg.txt (vector master Project Browser can use) -------------------
const svgTxtPath = join(outDir, "logo_svg.txt");
writeFileSync(svgTxtPath, SVG + "\n");
console.log("wrote", svgTxtPath);

// ---- logo.png (512x512 raster) ----------------------------------------------
const rawPng = join(outDir, "logo.raw.png");
const finalPng = join(outDir, "logo.png");
const buf = await sharp(Buffer.from(SVG), { density: 300 }).resize(512, 512).png().toBuffer();
writeFileSync(rawPng, buf);

// Compress under Project Browser's 10 KB guidance with pngquant (lossy ~80%).
let finalBytes;
try {
  execFileSync("pngquant", ["--quality=70-90", "--strip", "--force", "--output", finalPng, rawPng]);
  finalBytes = statSync(finalPng).size;
  console.log("wrote", finalPng, "(pngquant)");
} catch (e) {
  // pngquant unavailable — fall back to the un-quantised PNG and warn.
  writeFileSync(finalPng, buf);
  finalBytes = buf.length;
  console.warn("pngquant not found — wrote un-quantised PNG; install pngquant to meet the <=10 KB guidance");
}
// remove the intermediate
try { unlinkSync(rawPng); } catch {}

const kb = (finalBytes / 1024).toFixed(2);
console.log(`logo.png: 512x512, ${finalBytes} bytes (${kb} KB)`);
if (finalBytes > 10 * 1024) {
  console.warn(`WARNING: logo.png is ${kb} KB, above the ~10 KB Project Browser guidance.`);
}
