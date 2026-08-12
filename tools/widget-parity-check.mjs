#!/usr/bin/env node
// Widget source-of-truth DRIFT / LOCKSTEP gate (cinatra#411, S5 cinatra#1221).
//
// Runs under plain `node tools/widget-parity-check.mjs` — no bundler, no npm
// install, no WordPress/Drupal. Exit 0 = all invariants hold; exit 1 = drift was
// found. Mirrors the dependency-free spirit of the repo's existing standalone
// harnesses (tests/test-widget-negotiation.mjs, tests/test-token-broker.php).
//
// WHAT THIS IS (and is NOT)
// -------------------------
// This is a lightweight, NO-DEPENDENCY DRIFT GUARD — a structural/grep-level
// invariant check, NOT a parser-based security firewall and NOT a substitute for
// review. It cheaply catches the regressions that matter most on the canonical
// widget. A determined obfuscation could still evade a regex (e.g. computed
// property access) — the real defense is review + the runtime bridge/negotiation
// tests; this gate is the cheap tripwire that makes the COMMON drift loud in CI.
//
// ARCHITECTURE — WHY THE INVARIANT SET IS WHAT IT IS
// ---------------------------------------------------
// The assistant conversation lives in a Cinatra-served `/embed/assistant` iframe
// that the widget frames as the SOLE session owner (S5 cinatra#1221). The vanilla
// AG-UI renderer + SSE stream loop that used to live in the widget are gone: the
// widget does not stream and holds no `Authorization: Bearer` fetch.
//
// PROTOCOL 2 (cinatra#2674) FLIPPED THE CREDENTIAL INVARIANTS THEMSELVES.
// At protocol 1 this gate REQUIRED the widget to mint a short-lived `cit_` token
// through a same-origin broker and to carry `citToken`/`cwuToken` in a BOOTSTRAP
// message. That was the correct invariant then and is a LIABILITY now: the site
// is no longer a party to the sign-in at all. The frame mints its own credential
// on the Cinatra origin; the widget sends ONE selector-only CONTEXT message.
//
// So the credential invariants are INVERTED rather than deleted — the difference
// matters, because a deleted invariant is silent about a regression and an
// inverted one is loud. This gate now REQUIRES the absence it used to forbid:
//   * no broker endpoint is read and no token mint exists (INV2, flipped);
//   * the message type is `cinatra.embed.context` at version literal 2, and the
//     retired `…bootstrap` type / `citToken` / `cwuToken` / `auth:` field cannot
//     come back (INV3f, flipped);
//   * no credential-shaped VALUE may be composed, and the outbound refusal that
//     enforces it must be present (INV3g, extended);
//   * the shell runs NO login gate of its own — the frame owns sign-in (INV6,
//     flipped);
//   * the iframe sandbox grants the popup capability the frame-owned sign-in
//     needs, and still nothing else (INV3a, widened by exactly two flags).
//
// The unified-broker cutover invariants (INV4) and the dead-bundle-route ban
// (INV5) are unchanged.
//
// This SAME file is shipped verbatim to both repos (it auto-detects the WP vs
// Drupal config accessor + widget path). Keeping it identical is itself part of
// the parity discipline.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const SELF_PATH = fileURLToPath(import.meta.url);
const __dirname = path.dirname(SELF_PATH);
const REPO_ROOT = path.resolve(__dirname, "..");

// ---------------------------------------------------------------------------
// Locate this repo's vendored widget copy (WP: assets/, Drupal: js/).
// ---------------------------------------------------------------------------
const CANDIDATES = [
  "assets/cinatra-widget.js", // wordpress-plugin
  "js/cinatra-widget.js", // drupal-module
];
const widgetRel = CANDIDATES.find((rel) =>
  fs.existsSync(path.join(REPO_ROOT, rel)),
);
if (!widgetRel) {
  console.error(
    `FAIL  no vendored widget found (looked for: ${CANDIDATES.join(", ")})`,
  );
  process.exit(1);
}
const WIDGET_PATH = path.join(REPO_ROOT, widgetRel);
const src = fs.readFileSync(WIDGET_PATH, "utf8");

// `code` = the widget with line- and block-comments stripped, so an invariant
// that must hold in EXECUTABLE code is not satisfied (or violated) by prose in a
// comment. The header comments legitimately MENTION `apiKey`, `Bearer`, `"*"`,
// `/wp-json`, etc. to explain their ABSENCE, so those bans must run against
// `code`, never the raw source.
function stripComments(s) {
  return s
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .split("\n")
    .map((line) => {
      // Strip a // comment, but not inside a string. Cheap heuristic: only treat
      // `//` as a comment start when it is NOT preceded by an odd number of
      // quotes on the line up to that point. Stripping a real token can only
      // surface as a missing-required-token FAIL (fail-closed), never a false
      // pass; the URL `//` inside `https://` is even-quoted so it is preserved.
      const idx = line.indexOf("//");
      if (idx === -1) return line;
      const before = line.slice(0, idx);
      const quotes = (before.match(/['"`]/g) || []).length;
      return quotes % 2 === 0 ? before : line;
    })
    .join("\n");
}
const code = stripComments(src);

let failures = 0;
const fails = [];
function assert(label, cond, detail) {
  if (cond) {
    console.log(`  PASS  ${label}`);
  } else {
    console.log(`  FAIL  ${label}${detail ? ` — ${detail}` : ""}`);
    failures++;
    fails.push(label);
  }
}

// ---------------------------------------------------------------------------
// Detect which CMS this copy is (drives the config-accessor assertion).
// ---------------------------------------------------------------------------
const isWordPress = /window\.CinatraConfig/.test(code);
const isDrupal = /window\.drupalSettings\s*&&\s*window\.drupalSettings\.cinatra|drupalSettings\.cinatra/.test(
  code,
);
const cms = isWordPress ? "WordPress" : isDrupal ? "Drupal" : "UNKNOWN";
console.log(`widget-parity-check: ${widgetRel} (${cms})`);

assert(
  "config accessor is a recognized CMS broker (CinatraConfig | drupalSettings.cinatra)",
  isWordPress || isDrupal,
  "neither window.CinatraConfig nor drupalSettings.cinatra found",
);

// ---------------------------------------------------------------------------
// INVARIANT 1 (UNCHANGED) — no long-lived apiKey token appears anywhere in
// EXECUTABLE widget code. Banning the whole `apiKey` identifier closes the
// fallback-contamination hole. Comments may MENTION apiKey to explain its
// ABSENCE; that is why we check `code` (comments stripped).
// ---------------------------------------------------------------------------
const apiKeyMatch = code.match(/\bapiKey\b/);
assert(
  "no long-lived apiKey token in executable widget code (any `apiKey` reference)",
  apiKeyMatch === null,
  apiKeyMatch
    ? "the identifier `apiKey` appears in executable code — the long-lived key must never reach the browser"
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 2 (FLIPPED by cinatra#2674) — THE SAME-ORIGIN TOKEN BROKER IS GONE.
// The widget used to read `config.tokenEndpoint` and mint a `cit_` token through
// `getStreamToken()`; a second pair of broker endpoints ran the per-user PKCE
// handshake. All three are retired with the ceremony they served, so the gate now
// BANS them: a reappearance would mean this site is obtaining a bearer again,
// which is precisely what the protocol change ended. The config keys are named
// individually so a partial reintroduction is caught too.
// ---------------------------------------------------------------------------
const BROKER_CONFIG_RE = /config\.(?:tokenEndpoint|authInitEndpoint|authTokenEndpoint|csrfToken|authInitCsrfToken|authTokenCsrfToken)/;
assert(
  "no same-origin credential-broker endpoint is read (tokenEndpoint / authInit / authToken)",
  !BROKER_CONFIG_RE.test(code),
  "the widget reads a broker endpoint from config — the site must not obtain a bearer at all; the frame mints its own",
);
assert(
  "no cit_ broker mint exists (getStreamToken is gone)",
  !/\bgetStreamToken\b/.test(code),
  "getStreamToken() reappeared — the short-lived site-token mint belongs to the frame now",
);
assert(
  "no widget-auth path is composed anywhere in the widget",
  !/['"]\/(?:api\/)?widget-auth/.test(code),
  "the widget composes a /widget-auth path — the retired endpoints answer 410 Gone and must not be called, directly or through a relay",
);

// ---------------------------------------------------------------------------
// INVARIANT 3 (REPLACES the old Bearer-stream invariant) — the §12 sandboxed
// iframe trust boundary. The widget no longer streams; it frames the Cinatra
// `/embed/assistant` surface and speaks the parent↔iframe bridge. Five checks:
//   3a  a sandboxed iframe is created with EXACTLY the four flags the protocol
//       needs — allow-scripts, allow-same-origin, and the two popup flags the
//       frame-owned sign-in requires — and NO other escalation (no top-nav, no
//       forms, no modals, no downloads, no pointer-lock).
//   3b  its src targets the Cinatra `/embed/assistant` route built from
//       config.cinatraUrl and carries the instanceId + assistant disambiguators.
//   3c  every postMessage uses an EXPLICIT targetOrigin — NEVER "*".
//   3d  inbound frame messages are gated on BOTH `event.origin === <cinatra
//       origin>` AND `event.source === <frame window>` (source-window binding).
//   3e  the widget holds NO `Authorization: Bearer` fetch header anywhere (the
//       stream moved into the iframe; the OLD required-Bearer invariant is now a
//       banned-Bearer invariant — the crux of the trust-boundary flip).
// ---------------------------------------------------------------------------

// 3a — sandbox attribute with the exact grant the protocol needs, and no more.
//
// THE POPUP FLAGS ARE REQUIRED, NOT MERELY TOLERATED (cinatra#2674). At protocol
// 1 both were correctly forbidden: the shell ran the sign-in, so a frame that
// could open a window was pure escalation. At protocol 2 the FRAME runs the
// sign-in, and it deliberately runs it in a TOP-LEVEL Cinatra window — the one
// place a Cinatra session cookie is first-party, which is why it behaves
// identically in browsers that block third-party cookies. A sandboxed frame
// cannot open a window at all without `allow-popups`, and a window opened
// without `allow-popups-to-escape-sandbox` inherits the frame's restrictions, so
// the hosted sign-in could neither submit its form nor follow its own redirect
// home. Withholding either flag does not harden anything; it makes the assistant
// unusable. The escape applies to the OPENED WINDOW, never to the frame — the
// frame still cannot navigate, submit or modal the host page, which is what the
// remaining bans below enforce.
const sandboxMatch = code.match(
  /setAttribute\(\s*['"]sandbox['"]\s*,\s*['"]([^'"]*)['"]\s*\)/,
);
const sandboxVal = sandboxMatch ? sandboxMatch[1] : null;
assert(
  "embed iframe is created with a sandbox attribute",
  sandboxVal !== null,
  "no `setAttribute('sandbox', '…')` on the embed iframe",
);
const sandboxTokens = sandboxVal ? sandboxVal.trim().split(/\s+/) : [];
const REQUIRED_SANDBOX = [
  "allow-scripts",
  "allow-same-origin",
  // The frame-owned sign-in (cinatra#2674): without these the ceremony cannot
  // open, or cannot complete once open.
  "allow-popups",
  "allow-popups-to-escape-sandbox",
];
// SET EQUALITY, not "the required ones are present plus a denylist" (codex
// round 1). A denylist can only ever name the escalations someone thought of;
// the sandbox vocabulary grows, and a future `allow-…` would sail through a
// partial ban. The grant is a closed set: exactly these four, no more, no fewer.
const missingRequired = REQUIRED_SANDBOX.filter((t) => !sandboxTokens.includes(t));
const unexpectedGranted = sandboxTokens.filter((t) => !REQUIRED_SANDBOX.includes(t));
assert(
  "iframe sandbox grants EXACTLY the four flags the protocol needs, and nothing else",
  missingRequired.length === 0 && unexpectedGranted.length === 0,
  missingRequired.length || unexpectedGranted.length
    ? `sandbox='${sandboxVal ?? ""}'${
        missingRequired.length ? ` is missing ${missingRequired.join(", ")} (the frame-owned sign-in cannot complete without the popup grant)` : ""
      }${
        unexpectedGranted.length ? ` grants unexpected flag(s): ${unexpectedGranted.join(", ")}` : ""
      }`
    : undefined,
);

// 3b — the iframe src is the Cinatra /embed/assistant route with the
// disambiguators, built from config.cinatraUrl (NOT a hardcoded origin).
assert(
  "iframe src targets the Cinatra /embed/assistant route from config.cinatraUrl",
  /config\.cinatraUrl\s*\+\s*['"]\/embed\/assistant/.test(code),
  "no `config.cinatraUrl + '/embed/assistant'` iframe src construction found",
);
assert(
  "iframe src carries the instanceId + assistant disambiguators",
  /instanceId=/.test(code) && /assistant=/.test(code),
  "the embed src does not carry both instanceId= and assistant= query params",
);

// 3c — targetOrigin discipline: NO wildcard, AND every WINDOW post is addressed
// to the resolved Cinatra origin variable (an explicit non-"*" literal or a
// different origin would be a leak). §12b adds a second, HARDENED transport: a
// send on the retained MessageChannel endpoint (`activePort`) carries NO
// targetOrigin — the origin-targeted READY transfer that delivered the port IS
// the binding — so it is exempt from the origin-arg requirement, but NOT from the
// "*" ban. We (i) ban a "*" arg on ANY post, (ii) require at least one post
// (the CONTEXT message is delivered over some transport), and (iii) require that EVERY
// WINDOW `.postMessage(` targets `cinatraOrigin` (no post to any other/computed
// origin). A rewrite that posts a window message elsewhere fails here.
// Ban a "*" literal ANYWHERE in a postMessage argument list (not just
// immediately before `)`), so a `postMessage(msg, cinatraOrigin || "*")`
// short-circuit fallback is caught too — for BOTH transports.
const WILDCARD_POSTMESSAGE_RE = /\.postMessage\s*\([^;)]*?(['"])\*\1/;
assert(
  'no postMessage uses a wildcard "*" targetOrigin (anywhere in the args)',
  !WILDCARD_POSTMESSAGE_RE.test(code),
  'a postMessage with a "*" argument was found — every post must be addressed to the exact Cinatra origin',
);
// Match EVERY postMessage call with its RECEIVER token (`\S*` = the contiguous
// non-space run before `.postMessage`, so it never crosses spaces/newlines) and
// its arg list. Capturing broadly is deliberate: a window send via ANY receiver
// spelling — `frameWindow.postMessage`, `getWindow().postMessage`,
// `(frameWindow).postMessage` — is still checked and cannot evade the origin
// requirement (a narrower `\w+`-only receiver form would let a non-identifier
// receiver slip a wrong-origin post past this gate).
const postMessageCalls = [
  ...code.matchAll(/(\S*)\.postMessage\s*\(([^;)]*?)\)/g),
];
assert(
  "at least one postMessage to the frame exists (the CONTEXT message is delivered)",
  postMessageCalls.length > 0,
  "no postMessage call found — the bridge never gives the frame its context",
);
// A send is COMPLIANT iff it is EITHER a WINDOW send whose arg list ENDS with
// `, cinatraOrigin` and nothing appended (this rejects a computed/short-circuit
// target such as `cinatraOrigin || "*"` or a ternary), OR the origin-less send on
// the retained port endpoint `activePort` — the §12b document-bound transport,
// the ONLY sanctioned origin-less sink (the origin-targeted READY transfer that
// delivered the port IS its binding). Anything else — a window send to another/
// computed origin, or an origin-less send on a NON-port receiver — is a leak/
// regression and fails here. ("*" is separately banned above for BOTH transports.)
const nonCompliantPosts = postMessageCalls.filter((m) => {
  const receiver = m[1];
  const args = m[2].trim();
  const windowToCinatraOrigin = /,\s*cinatraOrigin$/.test(args);
  const retainedPortSend =
    /(?:^|[^\w$])activePort$/.test(receiver) && !args.includes(",");
  return !(windowToCinatraOrigin || retainedPortSend);
});
assert(
  "every postMessage is EITHER a window send to EXACTLY `cinatraOrigin` OR the origin-less retained-port send (activePort)",
  nonCompliantPosts.length === 0,
  "a postMessage is neither addressed to exactly `cinatraOrigin` nor the sanctioned origin-less `activePort` send — an outbound send must not leak to another/computed origin",
);

// 3d — inbound gate MUST be the REJECT form (a `!==` early-return), not merely a
// mention of the identifiers: `if (event.origin === cinatraOrigin) return` would
// invert the gate yet pass a loose "mentions both sides" check. Require the
// `!==` reject spelling for BOTH the origin and the source-window binding.
assert(
  "inbound bridge REJECTS on event.origin !== the Cinatra origin (reject-form gate)",
  /event\.origin\s*!==\s*cinatraOrigin/.test(code),
  "no `event.origin !== cinatraOrigin` reject-form gate on the inbound bridge",
);
assert(
  "inbound bridge REJECTS on event.source !== the frame window (source-window binding)",
  /event\.source\s*!==\s*frameWindow/.test(code),
  "no `event.source !== frameWindow` reject-form binding on the inbound bridge",
);

// 3e — THE FLIP: the widget must hold NO Authorization: Bearer fetch header. The
// stream (and thus every Bearer-authenticated request) moved into the iframe;
// there is no token in this page at all now — the frame mints its own.
const BEARER_HEADER_RE =
  /(?:^|[,{])\s*["']?authorization["']?\s*:\s*["']Bearer\b/gim;
const bearerMatches = [...code.matchAll(BEARER_HEADER_RE)];
assert(
  "widget holds NO Authorization: Bearer fetch header (streaming moved into the iframe)",
  bearerMatches.length === 0,
  bearerMatches.length
    ? "an `Authorization: Bearer` header is still present — the widget must not authenticate anything; the frame holds the only credential"
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 3f — the §12 bridge speaks the pinned protocol. Structural markers
// so a rename/version drift from the core `bridge-protocol.ts` is loud.
// ---------------------------------------------------------------------------
assert(
  "bridge references the ready + context message types",
  /cinatra\.embed\.ready/.test(code) && /cinatra\.embed\.context/.test(code),
  "the 'cinatra.embed.ready' / 'cinatra.embed.context' message types are missing",
);
assert(
  "bridge pins EMBED_PROTOCOL_VERSION = 2",
  /EMBED_PROTOCOL_VERSION\s*=\s*2\b/.test(code),
  "EMBED_PROTOCOL_VERSION is not pinned to 2 — a protocol-1 parent cannot negotiate with a protocol-2 frame, and that break is the point",
);
// THE FLIP. The credential-bearing envelope is retired: there is no `auth` block,
// no `citToken`, no `cwuToken`, and the retired message TYPE cannot be reached by
// name either. This is the invariant that used to REQUIRE the opposite.
assert(
  "the retired credential-bearing BOOTSTRAP envelope is GONE (no `cinatra.embed.bootstrap`)",
  !/cinatra\.embed\.bootstrap/.test(code),
  "the retired bootstrap message type reappeared — the frame refuses it and the site must not compose it",
);
assert(
  "no credential FIELD is composed (no citToken / cwuToken / auth block)",
  !/citToken\s*:/.test(code) &&
    !/cwuToken\s*:/.test(code) &&
    !/\bauth\s*:\s*\{/.test(code),
  "a credential field reappeared in an outbound message — protocol 2 has no slot for one and the parent must never compose it",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-2 — the bridge's runtime trust controls each leave a structural
// marker so a rewrite that DROPS one is loud (the grep can't prove the runtime
// behavior, but a missing marker means the control is almost certainly gone):
//   * nonce echo (parent echoes the frame's READY nonce),
//   * one context message per frame (a guarded flag),
//   * uplink correlationId binding (drop a message whose correlationId differs),
//   * a monotonic inbound seq gate (drop a non-increasing seq).
// ---------------------------------------------------------------------------
assert(
  "bridge echoes the frame nonce (nonceEcho)",
  /nonceEcho\s*:/.test(code),
  "no `nonceEcho:` in the context message — the parent must echo the frame's READY nonce",
);
assert(
  "bridge guards re-entry with a `contextSent` latch",
  /\bcontextSent\b/.test(code) && /if\s*\(\s*contextSent\b/.test(code),
  "no `contextSent` latch found",
);
assert(
  "a REPLAYED READY (same nonce) is ignored",
  /contextSent\s*&&\s*d\.nonce\s*===\s*frameNonce/.test(code),
  "no same-nonce replay guard — a replayed READY must not draw a second context message",
);
// A frame reload replaces the DOCUMENT under the same element and announces
// itself with a fresh nonce. Without an epoch reset the parent would ignore it
// forever (the widget hangs at "waiting for the host") and leak the retained
// port. The reset must come AFTER every validation, so a READY on its way to
// being refused cannot tear down an established session.
assert(
  "a replacement document starts a new epoch (resetBridgeEpoch)",
  /function\s+resetBridgeEpoch\b/.test(code) &&
    /if\s*\(\s*contextSent\s*\)\s*\{\s*resetBridgeEpoch\s*\(\s*\)\s*;\s*\}/.test(code),
  "no resetBridgeEpoch() epoch reset — a reloaded frame would never be served again and its port would leak",
);
assert(
  "the epoch reset happens AFTER the transport checks (a refused READY costs nothing)",
  code.indexOf("if (!transferredPort && requirePort) return;") <
    code.indexOf("if (contextSent) { resetBridgeEpoch(); }"),
  "resetBridgeEpoch() runs before the READY is fully validated — a malformed READY could tear down an established session",
);
assert(
  "bridge binds uplinks to the minted correlationId (drop on mismatch)",
  /\.correlationId\s*!==\s*correlationId/.test(code),
  "no `<msg>.correlationId !== correlationId` binding on uplinks",
);
assert(
  "bridge enforces a monotonic inbound seq gate (drop non-increasing seq)",
  /seq\s*<=\s*inboundSeqLast/.test(code),
  "no `seq <= inboundSeqLast` monotonic drop found",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-3 — TOKENS NOT IN THE FRAME URL. The embed src carries ONLY the
// non-secret disambiguators (instanceId, assistant); a token in the URL would
// leak it via history/referrer/logs. Assert the `/embed/assistant` src builder
// contains no token identifier.
// ---------------------------------------------------------------------------
const embedSrcBuild = code.match(
  /config\.cinatraUrl\s*\+\s*['"]\/embed\/assistant[\s\S]{0,400}?;/,
);
assert(
  "embed iframe src carries NO credential (there is none in this page to put there)",
  !!embedSrcBuild && !/token|cit_|cwu_|cnx_/i.test(embedSrcBuild[0]),
  embedSrcBuild
    ? "the /embed/assistant src builder references a credential — nothing of the sort may be in the frame URL"
    : "could not locate the /embed/assistant src builder",
);

// ---------------------------------------------------------------------------
// INVARIANT 3f-4 — §12b DOCUMENT-BOUND MESSAGEPORT TRANSPORT (cinatra#1965/#1970).
// The iframe transfers ONE MessageChannel endpoint in the (origin+source-gated)
// READY; the parent RETAINS it, sends the selector-only CONTEXT message over it,
// and services uplinks on it. At protocol 2 this is no longer a credential wall —
// there is no credential on this bridge to misdeliver — so it is kept for the
// narrower property it still provides at no cost: the retained endpoint belongs
// to the realm that ran the handshake, so a same-origin replacement document
// cannot take over an established session's uplink channel. The origin-pinned
// WINDOW transport remains for a frame whose READY carries no port; `requirePort`
// refuses that. Structural markers so a regression that drops the port transport
// (or its refusal) is loud. These identifiers are part of the byte-identical §12
// bridge core shared across both CMS widgets.
// ---------------------------------------------------------------------------
assert(
  "bridge takes the transferred port from the origin-gated READY (event.ports)",
  /event\.ports\b/.test(code),
  "no `event.ports` read — the parent must take the transferred MessagePort the frame sent on READY",
);
assert(
  "bridge sends the CONTEXT message over the retained port (activePort.postMessage, no targetOrigin)",
  /activePort\s*\.\s*postMessage\s*\(/.test(code),
  "no `activePort.postMessage(` — in port mode the context message must ride the retained document-bound port, not a window",
);
assert(
  "bridge services uplinks on the retained port (activePort message listener)",
  /activePort\s*\.\s*addEventListener\s*\(\s*['"]message['"]/.test(code),
  "no `activePort.addEventListener('message', …)` — steady-state uplinks must ride the retained port in port mode",
);
assert(
  "bridge refuses the unbound channel under requirePort (a no-port READY sends NOTHING)",
  /config\.requirePort\b/.test(code) &&
    /!\s*transferredPort\s*&&\s*requirePort/.test(code),
  "no `config.requirePort` toggle + `!transferredPort && requirePort` fail-closed guard — a downgrade could be forced by stripping the transferred port",
);

// ---------------------------------------------------------------------------
// INVARIANT 3g (REWRITTEN by cinatra#2674) — NO CREDENTIAL, AND NO CREDENTIAL
// SHAPE. Protocol 1's version of this invariant was "the tokens the widget holds
// are relayed only into the BOOTSTRAP". The widget holds no token now, so the
// invariant is stronger and simpler: nothing credential-shaped may be composed,
// and the outbound refusal that enforces it must exist.
//   * no bearer PREFIX literal is composed anywhere in executable code, except
//     inside the guard's own prefix list (which is how the guard knows them);
//   * the recursive outbound guard is present and is consulted by the sender;
//   * no web storage at all (nothing is persisted — the iframe owns persistence);
//   * no credential identifier is passed to console.*.
// ---------------------------------------------------------------------------
assert(
  "the outbound credential-shape guard exists (containsCredentialShapedValue)",
  /function\s+containsCredentialShapedValue\b/.test(code) &&
    /containsCredentialShapedValue\s*\(/.test(code),
  "no recursive containsCredentialShapedValue() guard — a bearer smuggled into an allowed selector field would travel",
);
assert(
  "the sender REFUSES a credential-shaped message before either transport",
  /function\s+sendContext\b[\s\S]{0,240}?containsCredentialShapedValue\s*\(/.test(code),
  "sendContext() does not consult the credential-shape guard first — the refusal must happen before the message is handed to any transport",
);
// The frame URL is the OTHER outbound payload: it is composed from config and
// then LEAVES THE PAGE as an HTTP request, where it also lands in history, in an
// access log and in a referrer. The bridge guard cannot reach it, so the mount
// runs the same check on the composed src and refuses to frame on a hit.
assert(
  "the iframe src is credential-scanned on its RAW components AND composed form",
  /function\s+mountBridgeIframe\b[\s\S]{0,2400}?containsCredentialShapedValue\s*\(\s*rawParts\s*\)\s*\|\|\s*containsCredentialShapedValue\s*\(\s*src\s*\)/.test(code),
  "mountBridgeIframe() does not scan the RAW url components before encoding — percent-encoding destroys the token boundary the guard matches on, so a composed-string-only scan is bypassable",
);
// PROTOCOL 2's promise rests on the frame being a different ORIGIN from the page.
// A same-origin instance does not weaken it, it removes it: site script could
// read straight into the frame's realm. The widget must refuse rather than claim
// a protection it does not have.
assert(
  "the widget REFUSES to mount when the instance is on the page's own origin",
  /cinatraOrigin\s*===\s*pageOrigin/.test(code) &&
    /window\.location\s*&&\s*window\.location\.origin/.test(code),
  "no same-origin refusal — protocol 2's credential guarantee does not exist when the frame shares the page's origin, and the widget must not pretend otherwise",
);
// The guard's bounds must fail CLOSED on the sending side: a structure too deep
// to finish walking, or a container this walk cannot enumerate, is refused
// rather than waved through.
assert(
  "the credential guard fails CLOSED at its depth bound and on non-plain containers",
  /if\s*\(\s*d\s*>=\s*8\s*\)\s*\{\s*return true;/.test(code) &&
    /\[object Object\]['"]\s*\)\s*\{\s*return true;/.test(code),
  "the guard returns false on an unknown answer — on the SENDING side an unknown answer must mean refusal, or the guard is defeatable by nesting",
);
// The prefix list is the ONE sanctioned place a bearer prefix literal may appear.
const guardListMatch = code.match(/CREDENTIAL_VALUE_PREFIXES\s*=\s*\[[^\]]*\]/);
const codeOutsideGuard = guardListMatch
  ? code.replace(guardListMatch[0], "")
  : code;
assert(
  "no bearer prefix literal is composed outside the guard's own prefix list",
  !/['"`]c(?:wu|it|nx)_/.test(codeOutsideGuard),
  "a `cwu_`/`cit_`/`cnx_` literal appears in executable code outside the guard list — the widget must neither mint, match nor build a credential",
);
assert(
  "no web storage in the widget (localStorage/sessionStorage) — nothing can be persisted",
  !/\b(?:local|session)Storage\b/.test(code),
  "the widget references localStorage/sessionStorage — the iframe owns persistence",
);
// EVERY console.* ARGUMENT IS A FIXED STRING LITERAL. The older form of this
// check listed credential identifiers and banned those; that only ever caught
// the names it happened to know, and it misfired on a fixed diagnostic that
// merely says the WORD "credential". This shell has no diagnostic that needs a
// runtime value, so the checkable rule is the stronger one: a console call that
// starts with anything but a quote is passing a value, and a value is the thing
// that could be a credential.
const CONSOLE_NON_LITERAL_RE = /console\s*\.\s*\w+\s*\(\s*(?!['"`])/;
assert(
  "no console.* call passes a runtime value (every argument is a fixed string literal)",
  !CONSOLE_NON_LITERAL_RE.test(code),
  "a console.* call takes a non-literal first argument — this shell logs fixed diagnostics only, so a value there could be anything, credentials included",
);
const consoleLines = code
  .split("\n")
  .filter((line) => /console\s*\.\s*\w+\s*\(/.test(line));
const CREDENTIAL_IDENTIFIER_RE =
  /\b(?:citToken|cwuToken|userToken|cachedToken|getStreamToken|activePort|frameNonce)\b/;
assert(
  "no credential-adjacent identifier appears on a console.* line",
  !consoleLines.some((line) => CREDENTIAL_IDENTIFIER_RE.test(line)),
  "a credential-adjacent identifier appears on a console.* line",
);

// ---------------------------------------------------------------------------
// INVARIANT 3h — #1214 NO-DIRECT-EGRESS on apply. Field-apply happens server-side
// via the CMS MCP integration; on apply_intent the parent does an IN-PLACE draft
// refresh through the CMS's OWN data layer — it never constructs a /wp-json (or
// /wp/v2 / JSON:API) content fetch and never reloads the page. So:
//   * no literal `/wp-json` or `/wp/v2/` or `/jsonapi/` request string in the
//     widget (broker endpoints arrive as opaque config.* values, not literals);
//   * no `window.location.reload` (the old post-apply reload is gone).
// ---------------------------------------------------------------------------
assert(
  "no direct WP/Drupal content-egress URL literal in the widget (#1214)",
  !/\/wp-json\b/.test(code) && !/\/wp\/v2\//.test(code) && !/\/jsonapi\b/.test(code),
  "a /wp-json | /wp/v2/ | /jsonapi content-egress literal is present — apply must not direct-egress; the CMS MCP integration applies fields",
);
assert(
  "no page reload on apply (#1214 in-place draft refresh, not a reload)",
  !/location\s*\.\s*reload\s*\(/.test(code),
  "window.location.reload() is present — apply must do an in-place draft refresh, not a reload",
);

// ---------------------------------------------------------------------------
// INVARIANT 3i — apply_intent selector discipline + resize clamp. The apply path
// must (a) route through the in-place refresh, (b) re-check edit permission, (c)
// enforce presence-XOR of the selector, (d) use the parent's OWN canonical
// resource (buildContentContext) — never the message id — as the refresh target,
// (e) bound-dedup via an LRU, and (f) never dynamically egress a message-supplied
// URL. Structural markers so removing any of these is loud.
// ---------------------------------------------------------------------------
assert(
  "apply_intent is handled and routes through an in-place draft refresh",
  /cinatra\.embed\.apply_intent/.test(code) &&
    /refreshCurrentDraft\s*\(/.test(code),
  "the apply_intent handler / refreshCurrentDraft() in-place refresh is missing",
);
assert(
  "apply_intent re-checks edit permission (currentUserMayEdit)",
  /currentUserMayEdit\s*\(/.test(code),
  "no currentUserMayEdit() permission re-check in the apply path",
);
assert(
  "apply_intent enforces selector presence-XOR (proposalPresent === changeSetPresent -> drop)",
  /proposalPresent\s*===\s*changeSetPresent/.test(code),
  "no presence-XOR guard — a message carrying both/neither selector could slip through",
);
assert(
  "apply refresh targets the parent's OWN canonical resource (buildContentContext)",
  /buildContentContext\s*\(/.test(code),
  "the apply path does not derive the resource from buildContentContext() (the message id must never be the selector)",
);
assert(
  "apply_intent bounded LRU dedup (appliedLru)",
  /appliedLru/.test(code) && /appliedLru\.indexOf/.test(code),
  "no bounded-LRU dedup (appliedLru) in the apply path",
);
// No dynamic egress: the widget must never fetch a URL taken from a bridge
// message (the closed uplink schema carries no URL; a `fetch(d.<x>)` /
// `fetch(msg…)` would be an exfiltration/SSRF-style regression).
const DYNAMIC_EGRESS_RE = /fetch\s*\(\s*(?:d|msg|message|event|payload)\b/;
assert(
  "no dynamic egress of a bridge-message-supplied URL (no fetch(d.…)/fetch(msg…))",
  !DYNAMIC_EGRESS_RE.test(code),
  "a fetch() takes its URL from a bridge message — the uplink schema carries no URL and none may be egressed",
);
assert(
  "resize height is clamped (Math.min against a panel cap), not trusted",
  /Math\.min\s*\([^)]*maxPanelHeight\s*\(\)/.test(code) ||
    /Math\.min\s*\([^)]*RESIZE_MAX_HEIGHT/.test(code),
  "no Math.min clamp of the resize height against the panel cap found",
);

// ---------------------------------------------------------------------------
// INVARIANT 4 (FLIPPED by the unified-broker cutover, cinatra#2029) — the shell
// no longer runs its own capability pre-flight. The AG-UI capability/contract
// handshake moved CLIENT-SIDE into the /embed/assistant iframe (unified broker
// `GET /api/assistants/chat/capabilities`); the bespoke `GET /api/agents/{slug}/
// capabilities` it used was DELETED by cinatra#1991 (no migration window). So this
// invariant now BANS, in EXECUTABLE code (comments stripped, so the header may
// still name the retired paths to explain their absence):
//   4a  any reference to the deleted `/capabilities` route (the shell must not
//       fetch it — a live proof was the widget never mounting on a 404);
//   4b  the retired pre-flight machinery (CLIENT_CONTRACT_VERSIONS /
//       negotiateCapabilities) creeping back in — re-adding either is a dual-path
//       regression (the #1991 ruling left NO migration window).
// The unchanged cit_ broker mint (INV2) and the /embed/assistant framing (INV3b)
// are what remain of the credential + wire path.
// ---------------------------------------------------------------------------
assert(
  "4a — no reference to the DELETED /api/agents/{slug}/capabilities route in executable widget code",
  !/\/capabilities\b/.test(code) && !/\/api\/agents\//.test(code),
  "the widget references the retired `/capabilities` (or `/api/agents/…`) negotiation route — it was deleted by cinatra#1991; the iframe negotiates against the unified broker now",
);
assert(
  "4b — the retired shell pre-flight is GONE (no CLIENT_CONTRACT_VERSIONS)",
  !/\bCLIENT_CONTRACT_VERSIONS\b/.test(code),
  "CLIENT_CONTRACT_VERSIONS reappeared — the shell no longer negotiates a client-side contract version (the iframe owns the AG-UI handshake); re-adding it is a dual-path regression",
);
assert(
  "4b — the retired shell pre-flight is GONE (no negotiateCapabilities)",
  !/\bnegotiateCapabilities\b/.test(code),
  "negotiateCapabilities() reappeared — the shell mounts unconditionally now (login-gated); the capability handshake runs inside the /embed/assistant iframe",
);

// ---------------------------------------------------------------------------
// INVARIANT 5 (UNCHANGED) — the dead host bundle route must not creep back in.
// The widget is checked here; the admin/embed/schema/js tree is scanned below.
// ---------------------------------------------------------------------------
const DEAD_ROUTE_RE = /\/api\/(?:wordpress|drupal)\/bundle\.js/;
assert(
  "widget does not reference the dead host bundle route (/api/{wordpress,drupal}/bundle.js)",
  !DEAD_ROUTE_RE.test(code),
  "the widget references a retired bundle.js route",
);

const SHIPPED_EXTS = new Set([
  ".php",
  ".module",
  ".inc",
  ".yml",
  ".yaml",
  ".json",
  ".twig",
  ".html",
  ".js",
]);
const EXEMPT_DIR_PARTS = new Set([
  "tests",
  "test",
  "vendor",
  "node_modules",
  ".git",
  "docs",
  // Gitignored build-output staging (bin/build-wporg.sh regenerates it from the
  // current source). It is not tracked and never present in a fresh CI checkout;
  // a stale local copy must not fail the source-of-truth scan.
  "build",
]);
// Two files legitimately contain the dead-route TOKEN and must be exempt from the
// raw-bytes scan: the widget itself (its executable code was already checked,
// comments stripped) and this parity script (it contains DEAD_ROUTE_RE literally).
const DEAD_ROUTE_SCAN_EXEMPT = new Set([
  path.resolve(WIDGET_PATH),
  path.resolve(SELF_PATH),
]);
function walk(dir, out) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name.startsWith(".")) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (EXEMPT_DIR_PARTS.has(entry.name)) continue;
      walk(full, out);
    } else if (
      SHIPPED_EXTS.has(path.extname(entry.name)) &&
      !DEAD_ROUTE_SCAN_EXEMPT.has(path.resolve(full))
    ) {
      out.push(full);
    }
  }
  return out;
}
const shippedFiles = walk(REPO_ROOT, []);
const offenders = [];
for (const f of shippedFiles) {
  const content = fs.readFileSync(f, "utf8");
  if (DEAD_ROUTE_RE.test(content)) {
    offenders.push(path.relative(REPO_ROOT, f));
  }
}
assert(
  "no shipped admin/embed/schema/js string advertises the dead bundle.js route",
  offenders.length === 0,
  offenders.length
    ? `dead-route reference in: ${offenders.join(", ")}`
    : undefined,
);

// ---------------------------------------------------------------------------
// INVARIANT 6 (FLIPPED by cinatra#2674) — THE SHELL RUNS NO LOGIN GATE.
// #410 put a required-login window in the CMS-origin shell: it held the per-user
// token, so it had to decide when a person was signed in. The frame holds the
// credential now and renders its own sign-in card, so a login gate HERE would
// mean the shell had gone back to knowing something about the person's session —
// the exact coupling this change removes. The markers that used to be REQUIRED
// are therefore BANNED, in executable code (the header may still name them to
// explain their absence).
// ---------------------------------------------------------------------------
const SHELL_LOGIN_STATE_RE = /\b(?:panelMode|loginRequired|userToken|userTokenValid|forceReLogin|redeemCode|codeVerifier|codeChallenge)\b/;
const shellLoginMatch = code.match(SHELL_LOGIN_STATE_RE);
assert(
  "the shell runs NO login gate of its own (sign-in belongs to the frame)",
  shellLoginMatch === null,
  shellLoginMatch
    ? `the shell still carries login state (\`${shellLoginMatch[0]}\`) — the per-user ceremony moved into the frame and must not be mirrored here`
    : undefined,
);
assert(
  "the shell does not listen for the hosted sign-in result",
  !/cinatra-widget-auth/.test(code),
  "the widget still listens for the hosted auth postMessage — the hosted return step posts to the CINATRA origin, so this page could not receive it and must not try",
);

// ---------------------------------------------------------------------------
console.log("");
if (failures > 0) {
  console.error(
    `widget-parity-check: ${failures} FAIL(s) — security-critical widget drift:`,
  );
  for (const f of fails) console.error(`  - ${f}`);
  console.error(
    "See the source-of-truth contract: cinatra docs/widget-source-of-truth.md",
  );
  process.exit(1);
}
console.log("widget-parity-check: all invariants hold.");
process.exit(0);
