#!/usr/bin/env node
// Generate the Drupal.org / Project Browser project logo for the Cinatra module.
//
// SOURCE OF TRUTH: the Cinatra brand. This script reproduces the sanctioned
// PRIMARY (mustard) colourway from the cinatra-ai/design repo — the mustard
// fedora mark on a paper/white ground. See:
//   design: assets/logo/variants.json  -> colorways.mustard, applications.favicon
//           tokens/brand.json          -> color.mustard / color.paper
//           scripts/generate-assets.mjs-> the mustard mark on paper
//
// Brand rule (design/assets/logo/variants.json, meta.rule):
//   "Mustard on paper or surface. Navy on paper. Cream on navy.
//    Never mustard on a coloured chip or on the navy ground."
// So the mark MUST be the mustard fedora on white/paper, NOT on the navy ground.
// We render a full-bleed WHITE (#ffffff) square with the mustard fedora centred,
// and NO rounded corners (Project Browser applies its own mask / renders the
// tile on its own surface, so we bake in neither a corner radius nor a border).
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
const MUSTARD = "#c79545"; // color.mustard.value — the brand colour (the mark)
const WHITE = "#ffffff"; //   white/paper ground (mustard reads on paper, not navy)

// The fedora mark geometry, copied verbatim from the design repo's
// scripts/generate-assets.mjs (FEDORA_PATHS) / src/app/icon.svg. Keeping it
// inline makes this script self-contained and deterministic; if the brand mark
// changes upstream, re-copy these two paths and the transform.
const FEDORA_PATHS =
  `<path d="M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z"/>` +
  `<path d="M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z"/>`;

// project-logo SVG — the mustard fedora on a full-bleed WHITE square. The mark's
// native drawn path occupies a 368x192 box (origin 72,64) inside its 512x512
// viewBox; we scale it to ~79% of the canvas width to match the cross-surface
// favicon sizing (cinatra src/app/icon.svg). Square corners, no border, no
// rounding (Project Browser masks the tile itself).
const SVG =
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">` +
  `<rect width="512" height="512" fill="${WHITE}"/>` +
  `<g transform="translate(-25.6 80) scale(1.1)" fill="${MUSTARD}">${FEDORA_PATHS}</g>` +
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
