/**
 * @file
 * Cinatra assistant widget — CANONICAL, locally-shipped widget (cinatra#411).
 * This is the Drupal MIRROR of the canonical source-of-truth widget; it is
 * hand-mirrored from cinatra-ai/wordpress-plugin/assets/cinatra-widget.js (the
 * copy authored first). It is NOT a re-vendor of any Cinatra host route — the
 * cinatra repo's src/app/api/drupal/bundle.js/route.ts is the DEPRECATED,
 * pre-Option-A artifact (nothing executes it; scheduled for removal). See the
 * contract: cinatra docs/widget-source-of-truth.md.
 *
 * ARCHITECTURE (S5 / cinatra#1221): the assistant conversation is NOT rendered by
 * this file. The Cinatra instance serves the AG-UI surface at `/embed/assistant`
 * and THIS widget mounts it in a sandboxed <iframe> as the SOLE session owner.
 * This shell keeps only the host-side concerns that MUST live on the CMS origin:
 * the launcher/panel chrome and the parent half of the §12 parent↔iframe
 * postMessage bridge.
 *
 * PROTOCOL 2 — THE SITE IS NOT A PARTY TO THE SIGN-IN (cinatra#2674).
 * Until protocol 1 this shell ran the whole per-user ceremony: it started the
 * hosted PKCE transaction through a same-origin PHP relay, received the opaque
 * `cwu_` user token back, minted a short-lived `cit_` site token through a second
 * relay, and handed BOTH into the iframe in a credential-bearing BOOTSTRAP
 * message. It worked, and it meant this site possessed proof of the person — a
 * credential that belongs to the user and to Cinatra and was never the site's
 * business.
 *
 * That is over. The iframe now mints its own PKCE verifier, starts the
 * transaction same-origin on the Cinatra origin, opens the hosted sign-in as a
 * TOP-LEVEL Cinatra popup, and redeems the code itself. Nothing about the
 * ceremony crosses this boundary. The one inbound message this shell sends is a
 * CONTEXT message carrying PUBLIC SELECTORS ONLY — which site, which assistant,
 * which node is on screen — and it composes no credential of any kind. The
 * retired `/api/widget-auth/{init,token}` endpoints answer 410 Gone; this module
 * calls neither them nor any local relay to them, so their retirement is silent
 * here rather than an error path.
 *
 * The module's remaining credential is the backend-only `cnx_` integration key
 * provisioned by "Connect with Cinatra". It stays server-side, is used only for
 * connect/webhook concerns, and never reaches the browser.
 *
 * TRUST BOUNDARY (§4/§6/§12):
 *   * THE INSTANCE MUST BE A DIFFERENT ORIGIN FROM THIS PAGE, and the widget
 *     refuses to mount when it is not. Everything below rests on that: the
 *     credential lives in the frame's memory, and "the site cannot read it" is
 *     an origin guarantee, not a code guarantee. An instance served under this
 *     site's own origin would leave the widget claiming a protection that does
 *     not exist there, which is worse than not running at all.
 *   * The iframe is `sandbox="allow-scripts allow-same-origin allow-popups
 *     allow-popups-to-escape-sandbox"` framing `/embed/assistant`. The two popup
 *     flags are what make the frame-owned sign-in possible AT ALL: the ceremony
 *     is a TOP-LEVEL Cinatra window (the only place a Cinatra session cookie is
 *     first-party), and a sandboxed frame cannot open one without `allow-popups`,
 *     nor let it act as a normal top-level document — following its own redirects
 *     and submitting its own form — without `allow-popups-to-escape-sandbox`.
 *     The escape applies to the OPENED WINDOW, never to the frame: the frame
 *     still has no top-navigation, no forms, no modals, no downloads and no
 *     pointer lock over this page.
 *   * §12b DOCUMENT-BOUND MESSAGEPORT TRANSPORT (cinatra#1965/#1970): the iframe
 *     transfers ONE MessageChannel endpoint in the (origin+source-gated) READY;
 *     the parent RETAINS it, sends the CONTEXT message over that port, and
 *     services uplinks on it. At protocol 2 this is no longer a credential wall
 *     (there is no credential to misdeliver) — it binds the channel to the realm
 *     that ran the handshake, so a same-origin replacement document cannot take
 *     over an established session's uplink channel. The origin-pinned WINDOW
 *     transport remains for a frame whose READY carries no transferred port;
 *     `requirePort` refuses that.
 *   * Every WINDOW postMessage to the frame uses an EXPLICIT targetOrigin (the
 *     Cinatra instance origin), NEVER "*"; a port-bound send needs no origin (the
 *     origin-targeted READY transfer that delivered the port IS the binding).
 *   * Inbound WINDOW frame messages (READY is one) are accepted ONLY when
 *     `event.origin === cinatraOrigin` AND `event.source === iframe.contentWindow`
 *     (origin + source-window binding); a port message's provenance IS that origin
 *     gate — the port was transferred ONLY to the Cinatra origin.
 *   * READY→CONTEXT: the parent mints a CSPRNG correlationId (≥128-bit), echoes
 *     the frame nonce, sends seq=0, and one context message per frame.
 *   * NO CREDENTIAL-SHAPED VALUE LEAVES THIS SHELL. Removing the credential field
 *     removes the credential slot; it does not remove the possibility of a
 *     credential VALUE in an allowed field (a node id that is really a `cwu_…`).
 *     So the composed CONTEXT message is scanned recursively for Cinatra's bearer
 *     prefixes before it is sent, and a message that carries one is NOT SENT AT
 *     ALL — a message that never leaves is strictly better than one the frame
 *     rejects on arrival. Mirrors the core `sendContextOverTransport` guard.
 *   * Two INDEPENDENT monotonic seq counters (one per direction) per correlationId.
 *   * apply_intent carries an UNTRUSTED SELECTOR only: the parent re-checks the
 *     current user may edit, uses its OWN canonical resource, dedups against a
 *     bounded LRU, and does an in-place draft refresh — NO direct JSON:API egress
 *     (#1214: field-apply happens server-side via the CMS MCP integration).
 *   * resize height is CLAMPED to the panel cap (clamp, never trust the value).
 *
 * Security-critical invariants (no apiKey and no bearer in the browser; no token
 * broker; no credential-shaped value on the bridge; sandbox iframe with the
 * popup grant and nothing more; explicit targetOrigin; source-window binding; no
 * apply-time egress) are gated by tools/widget-parity-check.mjs in CI (the SAME
 * gate shipped to both repos).
 *
 * Sync model: author a widget change in the WordPress copy first, then mirror it
 * here. The two copies now differ ONLY in the CMS-config accessor, the CMS
 * content-context accessor + in-place-refresh sink, the assistant identifier, and
 * the fully-local no-webfont policy. The broker CSRF idiom that used to be a
 * difference is gone from both — neither shell calls a broker any more. The §12
 * bridge core is byte-identical.
 *
 * SPDX-License-Identifier: Apache-2.0
 *
 * This vendored file is the Cinatra app frontend, licensed Apache-2.0, and is
 * distributed inside this GPL-2.0-or-later module. Apache-2.0 is GPL-compatible
 * (under GPLv3, which "GPL-2.0-or-later" reaches), so the module stays
 * distributable on Drupal.org. The module itself (everything outside this
 * vendored file) remains GPL-2.0-or-later.
 *
 *   Cinatra assistant widget bundle
 *   Copyright Cinatra
 *   Licensed under the Apache License, Version 2.0:
 *   https://www.apache.org/licenses/LICENSE-2.0
 */
(function () {
  // ---------------------------------------------------------------------------
  // Config guard. The browser holds NO credential of any kind — not the
  // long-lived key, and (since protocol 2) not a short-lived bearer either. All
  // this shell needs is the instance URL it frames.
  // ---------------------------------------------------------------------------
  var config = (window.drupalSettings && window.drupalSettings.cinatra) || {};
  if (!config.cinatraUrl) {
    console.warn('[cinatra] Missing drupalSettings.cinatra (cinatraUrl required)');
    return;
  }
  var rootEl = document.getElementById('cinatra-root');
  if (!rootEl) { console.warn('[cinatra] #cinatra-root not found'); return; }
  if (rootEl.dataset.cinatraMounted === 'true') return;
  // NOTE: data-cinatra-mounted is set at the END of synchronous mount construction
  // (see mountWidget()). The shell no longer pre-flight-negotiates, so it mounts
  // unconditionally at boot; a throw mid-mount still leaves the fallback chrome
  // visible (the marker that hides it is set LAST).

  // §4: the `?assistant` value — a PUBLIC HANDLE, never an agent slug. The
  // Cinatra server maps it to the agent through its own closed table, so this
  // shell names no agent and could not name another one if it tried. MUST equal
  // the embed page's `session.assistant` agreement check.
  var EMBED_ASSISTANT = 'drupal';

  // ---------------------------------------------------------------------------
  // §12 bridge protocol constants (the byte-level contract both halves pin).
  // Mirror of cinatra-ai/cinatra src/lib/embed/bridge-protocol.ts — kept in sync
  // by review + the parity gate. There is NO arbitrary-tool channel: the message
  // type set is CLOSED.
  //
  // PROTOCOL 2 (cinatra#2674) is deliberately BREAKING: the credential-bearing
  // `cinatra.embed.bootstrap` envelope is RETIRED, and the version literal moved
  // from 1 to 2 so a protocol-1 parent and a protocol-2 frame cannot negotiate at
  // all. That is what makes "the site can no longer deliver a credential" true
  // rather than merely intended — there is no silent fallback to the old flow.
  // ---------------------------------------------------------------------------
  var EMBED_PROTOCOL_VERSION = 2;
  var MSG = {
    ready: 'cinatra.embed.ready',       // iframe -> parent, pre-context (no correlationId)
    context: 'cinatra.embed.context',   // parent -> iframe, PUBLIC SELECTORS ONLY
    resize: 'cinatra.embed.resize',     // iframe -> parent
    focus: 'cinatra.embed.focus',       // iframe -> parent
    a11y: 'cinatra.embed.a11y',         // iframe -> parent
    applyIntent: 'cinatra.embed.apply_intent', // iframe -> parent
  };
  // A CSPRNG base64url id carrying >=128 bits of entropy is >=22 chars; charset +
  // length are enforced so a merely-short/low-entropy id is rejected (§6b).
  var ID_PATTERN = /^[A-Za-z0-9_-]{22,128}$/;
  var RESIZE_MAX_HEIGHT = 20000;                 // §5/§B9 schema upper bound
  var APPLY_INTENT_VIEW_TYPES = ['content_change_proposal'];
  var APPLY_LRU_MAX = 64;                         // §6f bounded seen-id LRU

  // ---------------------------------------------------------------------------
  // CREDENTIAL-SHAPED VALUE GUARD (cinatra#2674) — the second half of "no
  // credential crosses this boundary", mirrored from the core bridge protocol.
  //
  // Retiring the BOOTSTRAP removed the credential FIELD. It did not remove the
  // possibility of a credential VALUE: every field this shell still sends is a
  // string the site chooses, so a node id or bundle name that happens to be (or
  // is made to be) a `cwu_…` would put a bearer back on the bridge — the exact
  // thing this protocol exists to end.
  //
  // So the composed message is scanned recursively for Cinatra's bearer prefixes
  // (`cwu_` per-user token, `cit_` site transport token, `cnx_` connect-site
  // credential) at any token boundary, case-insensitively, in keys as well as
  // values — and a message that carries one is NOT SENT. This is a CONTAINMENT
  // control, not a secret detector: it cannot recognise a credential with no
  // prefix and is not asked to. It over-matches a hypothetical node value that
  // merely begins with one of those prefixes; that is deliberate and fails closed.
  // ---------------------------------------------------------------------------
  var CREDENTIAL_VALUE_PREFIXES = ['cwu_', 'cit_', 'cnx_'];
  // A prefix ANYWHERE in the string at a token boundary: `"Error: cwu_…"` and
  // `"https://x/?t=cwu_…"` are credentials on the wire too, and an error string
  // or a URL is exactly how one arrives there by accident. The boundary class
  // keeps it from firing on a word that merely ENDS in those letters.
  var CREDENTIAL_TOKEN_RE = new RegExp(
    '(?:^|[^A-Za-z0-9])(?:' +
      CREDENTIAL_VALUE_PREFIXES.map(function (p) { return p.slice(0, -1); }).join('|') +
      ')_',
    'i'
  );
  function isCredentialShapedValue(value) {
    return typeof value === 'string' && CREDENTIAL_TOKEN_RE.test(value);
  }
  // EVERY UNKNOWN ANSWER IS "YES" (codex round 0, finding 1). This is the
  // SENDER's guard, so its bounds must fail CLOSED: a structure too deep to
  // finish walking, a container this walk cannot enumerate (a Map, a Set, a
  // cyclic graph), or anything else it cannot positively clear is treated as
  // carrying a credential and the message is refused. The alternative — the
  // receiver-side habit of returning false on "I could not tell" — would make
  // the guard defeatable by nesting, which is exactly the hole it exists to
  // close. Nothing this shell legitimately composes nests more than three deep
  // or holds a non-plain object, so failing closed costs a real message nothing.
  function containsCredentialShapedValue(value, depth) {
    var d = depth || 0;
    if (isCredentialShapedValue(value)) { return true; }
    if (value === null || typeof value !== 'object') { return false; }
    if (d >= 8) { return true; }
    var i;
    var tag = Object.prototype.toString.call(value);
    if (tag === '[object Array]') {
      for (i = 0; i < value.length; i++) {
        if (containsCredentialShapedValue(value[i], d + 1)) { return true; }
      }
      return false;
    }
    // Only a plain object can be walked exhaustively with Object.keys; anything
    // else (Map, Set, Date, a cross-realm object, a Proxy) may hold values this
    // walk would never see, so it is refused rather than waved through.
    if (tag !== '[object Object]') { return true; }
    var keys = Object.keys(value);
    for (i = 0; i < keys.length; i++) {
      // A key is as visible to a logger as a value.
      if (isCredentialShapedValue(keys[i])) { return true; }
      if (containsCredentialShapedValue(value[keys[i]], d + 1)) { return true; }
    }
    return false;
  }

  // The Cinatra instance origin — the ONLY origin the bridge posts CONTEXT to
  // and the ONLY origin/source it accepts uplinks from. Resolved ONCE, strictly.
  var cinatraOrigin = null;
  try { cinatraOrigin = new URL(config.cinatraUrl).origin; } catch (_) { cinatraOrigin = null; }
  if (!cinatraOrigin) {
    console.warn('[cinatra] cinatraUrl is not a valid origin; widget not mounted');
    return;
  }

  // PROTOCOL 2 REQUIRES A REAL ORIGIN BOUNDARY (codex round 1). The promise this
  // protocol makes is that the SITE cannot come to possess the person's Cinatra
  // credential: the credential is minted by the frame, held in the frame's
  // memory, and never crosses the postMessage boundary. That promise rests
  // entirely on the frame being a DIFFERENT ORIGIN from the page around it. If
  // an instance is deployed on this site's own origin — a reverse proxy serving
  // Cinatra under the Drupal host — then site JavaScript can reach straight into
  // the frame's realm and read what it holds, and the guarantee is not weakened,
  // it is simply absent.
  //
  // Under protocol 1 that arrangement cost nothing new, because the site
  // legitimately held the credential anyway. Under protocol 2 it would make the
  // widget quietly claim a protection it does not have, which is worse than not
  // running. So a same-origin instance is REFUSED here: the fallback chrome
  // stays visible and the operator gets a diagnostic naming the reason.
  var pageOrigin = null;
  try { pageOrigin = window.location && window.location.origin ? window.location.origin : null; } catch (_) { pageOrigin = null; }
  if (pageOrigin && cinatraOrigin === pageOrigin) {
    console.warn('[cinatra] the Cinatra instance is on this site\'s own origin; the assistant needs a separate origin to keep each editor\'s sign-in private, so it was not mounted');
    return;
  }

  // ---------------------------------------------------------------------------
  // mountWidget() — builds the Shadow DOM + wires the launcher and the
  // parent-side bridge. Called UNCONDITIONALLY at boot: the capability/contract
  // handshake runs CLIENT-SIDE inside the /embed/assistant iframe against the
  // unified broker surface, and (since protocol 2) so does the sign-in. There is
  // no shell pre-flight and no shell login gate left to condition the mount on.
  //
  // The frame itself is mounted LAZILY, on the first panel open: framing an
  // authenticated surface on every node page a permitted editor merely LOOKS at
  // would be a request nobody asked for. Until then this page makes no request to
  // the instance at all.
  // ---------------------------------------------------------------------------
  function mountWidget() {
  // Idempotency guard: a second copy of this IIFE (a double script include) could
  // already have mounted.
  if (rootEl.dataset.cinatraMounted === 'true' || rootEl.shadowRoot) { return; }
  var shadow = rootEl.attachShadow({ mode: 'open' });
  // The data-cinatra-mounted marker (which hides the fallback chrome) is set at
  // the very END of synchronous mount construction. A throw at any point during
  // mount therefore leaves the fallback visible rather than hiding it over a
  // half-built / dead widget.

  // ---------------------------------------------------------------------------
  // CSS
  // Collapsed: single logo circle (position:fixed, bottom-right).
  // Expanded:  .cw-widget (panel), same anchor. The conversation body is the
  //            mounted <iframe>; the textarea/submit live INSIDE the iframe.
  //            Drag the top-left corner (.cw-resize) to resize width + panel height.
  // ---------------------------------------------------------------------------
  var style = document.createElement('style');
  style.textContent = [
    ':host { all: initial; }',

    /* Collapsed logo circle. */
    '.cw-circle {',
    '  position: fixed; bottom: 66px; right: 36px;',
    '  width: 32px; height: 32px; border-radius: 9999px;',
    '  background: #e6ede7; border: 1.5px solid #c79545; cursor: pointer;',
    '  display: flex; align-items: center; justify-content: center;',
    '  box-shadow: 0 4px 16px rgba(0,0,0,0.18);',
    '  transition: background 0.15s; z-index: 10000000;',
    '  touch-action: none;',
    '}',
    '.cw-circle:hover { background: #d8e7db; }',

    /* Expanded widget: position:fixed container. */
    '.cw-widget {',
    '  position: fixed; bottom: 56px; right: 24px;',
    '  z-index: 10000000;',
    '}',

    /* Resize corner: top-left of widget, drag to resize width+height */
    '.cw-resize {',
    '  position: absolute; top: 0; left: 0;',
    '  width: 20px; height: 20px;',
    '  cursor: nwse-resize;',
    '  z-index: 3;',
    '}',

    /* Panel: fills the widget; header on top, body (login | iframe) below. */
    '.cw-panel {',
    '  position: absolute; top: 0; left: 0; right: 0; bottom: 0;',
    '  box-sizing: border-box;',
    '  background: #f7f7f3; color: #15213a;',
    '  border: 1px solid #15213a14; border-radius: 16px;',
    '  box-shadow: 0 16px 48px rgba(0,0,0,0.2);',
    '  display: flex; flex-direction: column; overflow: hidden;',
    '  z-index: 1;',
    '}',

    /* Panel header */
    '.cw-panel-header {',
    '  padding: 12px 16px; border-bottom: 1px solid #15213a14;',
    '  display: flex; align-items: center; justify-content: space-between;',
    '  background: #eceeea; flex-shrink: 0;',
    '}',
    '.cw-header-left { display: flex; align-items: center; gap: 8px; }',
    '.cw-wordmark { font: italic 800 14px Archivo, system-ui, sans-serif; color: #c79545; letter-spacing: -0.022em; }',
    '.cw-close {',
    '  background: none; border: none; cursor: pointer;',
    '  font-size: 20px; line-height: 1; color: #5a6477;',
    '  padding: 2px 6px; border-radius: 6px;',
    '  display: flex; align-items: center; justify-content: center;',
    '}',
    '.cw-close:hover { background: #f7f7f3; color: #15213a; }',

    /* Conversation body: the sandboxed embed iframe fills the panel body. */
    '.cw-frame-host { flex: 1; min-height: 0; display: flex; }',
    '.cw-frame {',
    '  flex: 1; width: 100%; height: 100%; border: none; background: #f7f7f3;',
    '  display: block;',
    '}',

    /* Visually-hidden aria-live region: the parent mirrors iframe a11y status
       here as textContent (never HTML) so host-page assistive tech is notified. */
    '.cw-a11y-live {',
    '  position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;',
    '  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;',
    '}',
    /* The sign-in card is rendered INSIDE the frame now (cinatra#2674), so this
       shell ships no login chrome and no credential-facing DOM at all. */
  ].join('\n');
  shadow.appendChild(style);

  // Local-only widget: do NOT inject a remote webfont. The original embed bundle
  // pulled Archivo from fonts.googleapis.com, but that is an undisclosed
  // third-party browser request and breaks the "fully local" guarantee required
  // on Drupal.org. The wordmark/headers fall back to the system-ui stack already
  // declared in the CSS font-family (Archivo, system-ui, sans-serif).

  // ---------------------------------------------------------------------------
  // SVG builders
  // ---------------------------------------------------------------------------
  var SVG_NS = 'http://www.w3.org/2000/svg';
  var LOGO_VIEWBOX = '0 0 512 320';
  var LOGO_BRIM = 'M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z';
  var LOGO_CROWN = 'M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z';
  var LOGO_COLOR = '#c79545';
  function mkEl(tag, attrs) {
    var el = document.createElementNS(SVG_NS, tag);
    for (var k in attrs) el.setAttribute(k, String(attrs[k]));
    return el;
  }
  function mkSvg(w, h, vb) { return mkEl('svg', { width: w, height: h, viewBox: vb, fill: 'none' }); }

  function makeLogoSvg() {
    var svg = mkSvg(22, 14, LOGO_VIEWBOX);
    svg.setAttribute('fill', 'none');
    svg.appendChild(mkEl('path', { d: LOGO_BRIM, fill: LOGO_COLOR }));
    svg.appendChild(mkEl('path', { d: LOGO_CROWN, fill: LOGO_COLOR }));
    return svg;
  }

  function makeLogoDarkSvg() {
    var svg = mkSvg(22, 14, LOGO_VIEWBOX);
    svg.setAttribute('fill', 'none');
    svg.appendChild(mkEl('path', { d: LOGO_BRIM, fill: LOGO_COLOR }));
    svg.appendChild(mkEl('path', { d: LOGO_CROWN, fill: LOGO_COLOR }));
    return svg;
  }

  // ---------------------------------------------------------------------------
  // DOM: collapsed circle
  // ---------------------------------------------------------------------------
  var circle = document.createElement('button');
  circle.className = 'cw-circle';
  circle.type = 'button';
  circle.appendChild(makeLogoSvg());
  shadow.appendChild(circle);

  // ---------------------------------------------------------------------------
  // Circle drag-to-reposition (session-only, no persistence)
  // ---------------------------------------------------------------------------
  function applyCirclePos(left, top) {
    circle.style.left = left + 'px';
    circle.style.top = top + 'px';
    circle.style.right = 'auto';
    circle.style.bottom = 'auto';
  }
  function clampCirclePos(left, top) {
    left = Math.max(0, Math.min(window.innerWidth - 32, left));
    top = Math.max(0, Math.min(window.innerHeight - 32, top));
    return { left: left, top: top };
  }

  var circleDragging = false;
  var circleDragStartX = 0, circleDragStartY = 0;
  var circleDragStartLeft = 0, circleDragStartTop = 0;
  var circleDragMoved = false;
  var CIRCLE_DRAG_THRESHOLD = 4;

  circle.addEventListener('mousedown', function(e) {
    e.preventDefault();
    var rect = circle.getBoundingClientRect();
    circleDragStartX = e.clientX;
    circleDragStartY = e.clientY;
    circleDragStartLeft = rect.left;
    circleDragStartTop = rect.top;
    circleDragging = true;
    circleDragMoved = false;
  });

  // ---------------------------------------------------------------------------
  // DOM: expanded widget — panel (header + body)
  // ---------------------------------------------------------------------------
  var currentWidth = 580;
  var currentPanelHeight = 460;   // total panel height (header + body)
  var MIN_PANEL_HEIGHT = 260;
  var userResizedPanel = false;   // a manual drag pins the height (disables auto-grow)

  function maxPanelHeight() { return Math.max(MIN_PANEL_HEIGHT, window.innerHeight - 120); }

  function setWidgetSize() {
    cwWidget.style.width = currentWidth + 'px';
    cwWidget.style.height = currentPanelHeight + 'px';
  }

  var cwWidget = document.createElement('div');
  cwWidget.className = 'cw-widget';
  cwWidget.style.display = 'none';
  shadow.appendChild(cwWidget);

  var resizeEl = document.createElement('div');
  resizeEl.className = 'cw-resize';
  cwWidget.appendChild(resizeEl);

  var panel = document.createElement('div');
  panel.className = 'cw-panel';
  cwWidget.appendChild(panel);

  var panelHeader = document.createElement('div');
  panelHeader.className = 'cw-panel-header';
  panel.appendChild(panelHeader);

  var headerLeft = document.createElement('div');
  headerLeft.className = 'cw-header-left';
  headerLeft.appendChild(makeLogoDarkSvg());
  var wordmark = document.createElement('span');
  wordmark.className = 'cw-wordmark';
  wordmark.textContent = 'Cinatra';
  headerLeft.appendChild(wordmark);
  panelHeader.appendChild(headerLeft);

  var closeBtn = document.createElement('button');
  closeBtn.className = 'cw-close';
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', 'Close');
  closeBtn.textContent = '×';
  panelHeader.appendChild(closeBtn);

  // Visually-hidden aria-live region: mirrors iframe a11y uplinks (textContent).
  var a11yLive = document.createElement('div');
  a11yLive.className = 'cw-a11y-live';
  a11yLive.setAttribute('role', 'status');
  a11yLive.setAttribute('aria-live', 'polite');
  panel.appendChild(a11yLive);

  // Conversation body host — the sandboxed embed iframe is mounted here on the
  // first panel open. There is no login window in front of it any more: the frame
  // renders its own sign-in card and runs the whole ceremony itself.
  var frameHost = document.createElement('div');
  frameHost.className = 'cw-frame-host';
  panel.appendChild(frameHost);

  // ---------------------------------------------------------------------------
  // State
  //
  // Note what is NOT here since protocol 2 (cinatra#2674): no `userToken`, no
  // `pkce` handshake, no popup watcher, no panel mode. This shell holds no
  // credential and no authentication state, so there is none to lose, expire or
  // leak — the frame owns all of it.
  // ---------------------------------------------------------------------------
  var isOpen = false;

  // ---------------------------------------------------------------------------
  // Content context (the `cms` selectors of the CONTEXT message and the parent's
  // OWN canonical resource for apply_intent — NOT a stream input).
  //
  // CMS seam: Drupal node edit forms are server-rendered, so the canonical
  // resource is provided by the module in drupalSettings.cinatra (nodeId /
  // nodeBundle / nodeStatus) rather than read from a client editor store as the
  // WordPress copy reads wp.data. The field names differ from the WP copy by
  // design; buildContext() maps them into the shared §12 `cms` shape.
  //
  // EVERY SELECTOR IS NORMALIZED TO A STRING HERE, ONCE (codex round 0, finding
  // 5). The frame's schema is strict about types as well as keys, so a numeric
  // nodeId or a boolean status arriving from a hand-edited drupalSettings would
  // make the whole message unparseable on the other side — a silent dead widget
  // rather than a visible error. This is also the single place the credential
  // guard has to reason about: after this function every selector is a plain
  // string, so no non-plain container can reach the composed message at all.
  // ---------------------------------------------------------------------------
  function asSelector(value) {
    if (value === null || value === undefined) { return ''; }
    if (typeof value === 'string') { return value; }
    // A number or boolean is normalized; anything else (an object, an array) is
    // not a selector and is dropped rather than stringified into nonsense.
    if (typeof value === 'number' || typeof value === 'boolean') { return String(value); }
    return '';
  }
  function buildContentContext() {
    return {
      instanceId: asSelector(config.instanceId),
      nodeId:     asSelector(config.nodeId),
      nodeBundle: asSelector(config.nodeBundle),
      nodeStatus: asSelector(config.nodeStatus),
    };
  }

  // ---------------------------------------------------------------------------
  // CSPRNG id helper. The ONLY random value this shell still mints is the
  // per-frame correlationId (§6b) — the PKCE verifier, challenge and `state` are
  // gone with the ceremony they belonged to, and they now live in the frame.
  // ---------------------------------------------------------------------------

  // base64url (no padding) of a byte array.
  function b64url(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function randB64url(n) {
    var a = new Uint8Array(n);
    crypto.getRandomValues(a);
    return b64url(a);
  }

  // ---------------------------------------------------------------------------
  // §12 PARENT-SIDE BRIDGE — the host half of the parent↔iframe embed protocol.
  //
  // The iframe (`/embed/assistant`) is the SOLE session owner AND, since protocol
  // 2, the sole holder of the credential. This shell sends ONE selector-only
  // CONTEXT message and services the closed set of iframe→parent uplinks. Every
  // trust-boundary control is enforced here: origin + source-window binding (on
  // the READY window message), schema/protocolVersion/nonce agreement, dual
  // monotonic seq, one context message per frame, the outbound credential-shape
  // refusal, apply_intent untrusted-selector permission checks + bounded LRU
  // dedup, and the resize clamp.
  //
  // §12b DOCUMENT-BOUND MESSAGEPORT TRANSPORT (cinatra#1965/#1970): the iframe
  // transfers ONE MessageChannel endpoint in the (origin+source-gated) READY; the
  // parent RETAINS it, sends the CONTEXT message over it, and services uplinks on
  // it. At protocol 1 this transport existed to protect a credential from a
  // same-origin REPLACEMENT of the frame. There is no credential to misdeliver
  // now, so it is kept for the narrower property it still provides at no cost:
  // the retained endpoint belongs to the realm that ran the handshake, so a
  // replacement document cannot take over an established session's uplink
  // channel. The origin-pinned WINDOW transport remains for a frame whose READY
  // carries no transferred port; `requirePort` refuses that.
  // ---------------------------------------------------------------------------
  var iframeEl = null;          // the mounted embed iframe (null until the panel opens)
  var frameWindow = null;       // iframeEl.contentWindow captured at load
  var frameNonce = null;        // the READY nonce the frame minted (echoed in context)
  var correlationId = null;     // parent-minted CSPRNG id, echoed by every uplink
  var contextSent = false;      // one CONTEXT message per frame
  var inboundSeqLast = null;    // iframe->parent monotonic gate (READY seeds it)
  var outboundSeqLast = null;   // parent->iframe monotonic counter (context = 0)
  var appliedLru = [];          // §6f bounded seen apply-id LRU for this correlationId
  // §12b transport state — chosen at READY (see onBridgeMessage).
  var activePort = null;        // the retained transferred MessagePort (port mode) or null
  var activeTransport = null;   // 'port' | 'legacy' | null — chosen at READY
  // Channel binding: when true, a READY that transfers NO port is REFUSED, so the
  // window transport cannot be selected merely by stripping the transferred port.
  // Defaults FALSE (a frame that transfers no port still works). At protocol 2
  // this is a channel-binding knob, not a credential control — the message
  // carries no credential either way. A deployment hardens by setting
  // drupalSettings.cinatra.requirePort = true.
  var requirePort = (config.requirePort === true);

  // A CSPRNG base64url correlationId carrying >=128 bits (24 base64url chars ==
  // 144 bits), satisfying ID_PATTERN (§6b).
  function mintCorrelationId() {
    return randB64url(18);
  }

  // §6c per-direction monotonic gate: a seq must be a nonnegative integer and, on
  // any direction after the first accepted value, strictly increase.
  function acceptInboundSeq(seq) {
    if (typeof seq !== 'number' || !isFinite(seq) || Math.floor(seq) !== seq || seq < 0) return false;
    if (inboundSeqLast !== null && seq <= inboundSeqLast) return false;
    inboundSeqLast = seq;
    return true;
  }
  function nextOutboundSeq() {
    var next = (outboundSeqLast === null ? -1 : outboundSeqLast) + 1;
    outboundSeqLast = next;
    return next;
  }

  // ALWAYS an explicit origin, NEVER "*" (§6a outbound). Posts to the frame window.
  function postToFrame(message) {
    if (!frameWindow) return;
    frameWindow.postMessage(message, cinatraOrigin);
  }

  // §12b send the CONTEXT message over the SELECTED transport. In PORT mode it
  // rides ONLY the retained entangled port — never a window postMessage; in
  // WINDOW mode it posts to the origin-pinned frame window (postToFrame: explicit
  // origin, never "*"). Mirrors the core sendContextOverTransport (§12b).
  //
  // THE OUTBOUND REFUSAL LIVES HERE, before either transport. The message is
  // re-validated for a credential-shaped value at any depth, and a message that
  // carries one is NOT SENT — the frame would refuse it on arrival, but a
  // credential that never leaves this page is strictly better than one that is
  // rejected after travelling. Returns whether the message was sent.
  function sendContext(message) {
    if (containsCredentialShapedValue(message)) { return false; }
    if (activeTransport === 'port') {
      activePort.postMessage(message);
      return true;
    }
    if (!frameWindow) { return false; }
    postToFrame(message);
    return true;
  }

  // §3a READY validator (pre-context; the ONLY message without a correlationId).
  // The protocolVersion literal is pinned: a protocol-1 frame cannot negotiate.
  function isValidReady(d) {
    return !!d && d.type === MSG.ready &&
      d.protocolVersion === EMBED_PROTOCOL_VERSION &&
      typeof d.nonce === 'string' && ID_PATTERN.test(d.nonce) &&
      // A non-negative integer, proven HERE rather than left to the seq gate:
      // the epoch reset below runs after this check and clears the gate, so this
      // is the last place a malformed seq can be caught before it matters.
      typeof d.seq === 'number' && isFinite(d.seq) &&
      Math.floor(d.seq) === d.seq && d.seq >= 0;
  }

  // The CLOSED uplink type set. Checked BEFORE the seq gate commits, so a
  // message the parent will drop anyway cannot first consume a sequence number
  // (codex round 0, finding 7). The gate is a scarce, one-way resource: nothing
  // that is not going to be dispatched may spend from it.
  function isKnownUplinkType(type) {
    return type === MSG.resize || type === MSG.focus ||
      type === MSG.a11y || type === MSG.applyIntent;
  }

  // §5 uplink common envelope validator (post-context): the closed type set,
  // protocolVersion, the echoed correlationId, and — last, because accepting it
  // MUTATES the gate — a monotonic seq for the iframe->parent direction.
  function validUplinkEnvelope(d) {
    if (!d || d.protocolVersion !== EMBED_PROTOCOL_VERSION) return false;
    if (!isKnownUplinkType(d.type)) return false;
    if (typeof d.correlationId !== 'string' || d.correlationId !== correlationId) return false;
    if (!acceptInboundSeq(d.seq)) return false;
    return true;
  }

  // §4: build the ONE CONTEXT message — mint the correlationId, echo the frame
  // nonce, seq=0. EVERY FIELD IS A PUBLIC SELECTOR, NOT AN ASSERTION and NOT A
  // CREDENTIAL: it names which assistant handle, which instance and which node is
  // on screen. The Cinatra server re-derives the authoritative site, org, origin,
  // agent and canonical instance from its own rows and denies on any mismatch, so
  // naming something this site does not own returns a denial, not that thing.
  //
  // There is no `auth` block, and there is no field a credential could occupy:
  // the schema on the other side is strict, so an added one would be rejected
  // rather than ignored. The optional `site` selector is omitted — this module
  // holds no public connect-site handle, and a disambiguator that is not needed
  // must not be invented.
  function buildContext(nonce) {
    frameNonce = nonce;
    correlationId = mintCorrelationId();
    var ctx = buildContentContext();
    // The cms block is built ONLY from the normalized context — never re-read
    // from raw config, which would reintroduce the untyped value the
    // normalization just removed (codex round 0, finding 5).
    var cms = { instanceId: ctx.instanceId };
    // CMS seam: map Drupal's node-native context onto the shared §12 `cms` shape.
    if (ctx.nodeId) { cms.resourceId = ctx.nodeId; }
    if (ctx.nodeBundle) { cms.resourceType = ctx.nodeBundle; }
    if (ctx.nodeStatus) { cms.status = ctx.nodeStatus; }
    return {
      type: MSG.context,
      protocolVersion: EMBED_PROTOCOL_VERSION,
      correlationId: correlationId,
      nonceEcho: frameNonce,
      seq: nextOutboundSeq(),              // parent->iframe counter starts at 0
      session: {
        threadId: correlationId,           // one thread per framed session
        assistant: EMBED_ASSISTANT,        // == ?assistant (a public handle)
      },
      cms: cms,
    };
  }

  // §5/§B9 resize: CLAMP the reported content height to the panel cap (clamp,
  // never reject a merely-tall height; NaN/negative/over-max are schema-dropped).
  function handleResize(height) {
    if (typeof height !== 'number' || !isFinite(height) ||
        Math.floor(height) !== height || height < 0 || height > RESIZE_MAX_HEIGHT) {
      return; // schema-reject
    }
    if (userResizedPanel) return; // a manual drag pins the height
    var HEADER_H = 46;
    var clamped = Math.max(MIN_PANEL_HEIGHT, Math.min(height + HEADER_H, maxPanelHeight()));
    currentPanelHeight = clamped;
    setWidgetSize();
  }

  // §5 focus: advisory. Bring the panel forward / keep it open on a focus request.
  function handleFocus(focus) {
    if (typeof focus !== 'boolean') return;
    if (focus && !isOpen) { openWidget(); }
  }

  // §5 a11y: mirror the assistant status into the parent aria-live region as
  // textContent — NEVER HTML (no markup injection from frame content).
  function handleA11y(liveRegion, politeness) {
    if (typeof liveRegion !== 'string' || liveRegion.length > 2000) return;
    if (politeness !== 'polite' && politeness !== 'assertive') return;
    a11yLive.setAttribute('aria-live', politeness);
    a11yLive.textContent = liveRegion;
  }

  // Best-effort re-check that the current user may edit the canonical resource
  // (§6f step 1). CMS seam: Drupal exposes NO synchronous client-side capability
  // oracle equivalent to WordPress core-data `canUser`. The field WRITE was
  // already performed AND permission-checked SERVER-SIDE by the CMS MCP
  // integration (#1214); this client gate only guards an in-place refresh of the
  // user's OWN already-open node edit form. Mirroring the WP rule (deny ONLY on an
  // explicit `false`, defer to the server on anything unresolved), we honor an
  // explicit per-node deny if the module ever advertises one
  // (config.currentUserMayEditNode === false) and otherwise defer to the server-
  // side authorization — an absent/unresolved oracle is NOT a hard client deny.
  function currentUserMayEdit(ctx) {
    try {
      if (config.currentUserMayEditNode === false) { return false; }
    } catch (_) {}
    return true;
  }

  // In-place draft refresh (#1214: NO widget-constructed JSON:API egress and NO
  // page reload). CMS seam: Drupal node edit forms are server-rendered, so there
  // is no client entity store to invalidate the way the WordPress copy calls
  // wp.data invalidateResolution. Instead we dispatch a same-document CustomEvent
  // that the module's (optional) edit-form integration listens for to re-render
  // the applied field in place; if nothing is listening it is a harmless no-op.
  // The event carries ONLY the non-secret resource disambiguators — never a
  // token, never content — and never triggers a reload or a network request. The
  // field WRITE itself already happened server-side via the CMS MCP integration.
  function refreshCurrentDraft(ctx) {
    try {
      if (typeof CustomEvent === 'function' &&
          document && typeof document.dispatchEvent === 'function') {
        document.dispatchEvent(new CustomEvent('cinatra:content-applied', {
          detail: {
            instanceId:   ctx.instanceId || '',
            resourceId:   ctx.nodeId || '',
            resourceType: ctx.nodeBundle || '',
          },
        }));
        return true;
      }
    } catch (_) {}
    return false;
  }

  // §5/§6e/§6f apply_intent: the payload carries an UNTRUSTED SELECTOR only (one of
  // proposalId/changeSetId) + a fixed viewType. NO content, NO tool call. The
  // parent (1) re-checks edit permission, (2) uses its OWN canonical resource,
  // (3) the correlationId binding already proves the signal belongs to this
  // framed thread/instance, (4) dedups against a bounded LRU, THEN does the
  // in-place draft refresh. The selector id is used ONLY as the LRU key — never as
  // a fetch selector, never egressed (#1214).
  function handleApplyIntent(d) {
    if (APPLY_INTENT_VIEW_TYPES.indexOf(d.viewType) === -1) return;
    // Exactly one selector must be PRESENT — matching the core presence-XOR schema
    // (`(proposalId != null) !== (changeSetId != null)`). A message carrying BOTH
    // keys (even if one is an empty string) or NEITHER is rejected; presence, not
    // value validity, decides the XOR so an empty-but-present field can't slip a
    // both-present message through.
    var proposalPresent = d.proposalId !== undefined && d.proposalId !== null;
    var changeSetPresent = d.changeSetId !== undefined && d.changeSetId !== null;
    if (proposalPresent === changeSetPresent) return;
    var selectorId = proposalPresent ? d.proposalId : d.changeSetId;
    // The present selector must still be a sane bounded non-empty string.
    if (typeof selectorId !== 'string' || selectorId.length === 0 || selectorId.length > 200) return;

    var ctx = buildContentContext();               // the parent's OWN canonical resource
    if (!currentUserMayEdit(ctx)) return;          // fail closed on explicit deny

    // Bounded LRU dedup per correlationId (a re-emitted apply must be idempotent).
    var lruKey = (proposalPresent ? 'p:' : 'c:') + selectorId;
    if (appliedLru.indexOf(lruKey) !== -1) return;
    appliedLru.push(lruKey);
    if (appliedLru.length > APPLY_LRU_MAX) { appliedLru.shift(); }

    refreshCurrentDraft(ctx);
    a11yLive.setAttribute('aria-live', 'polite');
    a11yLive.textContent = 'The assistant applied changes to this content.';
  }

  // Dispatch a validated post-context uplink to its handler (the closed set).
  // Shared by the WINDOW path (onBridgeMessage) and the PORT path
  // (onPortMessage) so both transports service the identical uplink set.
  function dispatchUplink(d) {
    if (d.type === MSG.resize) { handleResize(d.height); return; }
    if (d.type === MSG.focus) { handleFocus(d.focus); return; }
    if (d.type === MSG.a11y) { handleA11y(d.liveRegion, d.politeness); return; }
    if (d.type === MSG.applyIntent) { handleApplyIntent(d); return; }
    // Unknown type: dropped (the set is closed).
  }

  // §12b PORT uplink path — in PORT mode steady-state iframe->parent uplinks ride
  // the retained entangled port. No origin/source check is needed: the port was
  // transferred ONLY to the Cinatra origin by the origin-targeted READY, so its
  // provenance IS the origin gate (a NARROWING, never a loosening), and it is
  // document-bound. Envelope validation (correlationId binding + monotonic seq)
  // is identical to the window path.
  function onPortMessage(event) {
    if (!contextSent || activeTransport !== 'port') return;
    var d = event.data;
    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;
    if (!validUplinkEnvelope(d)) return;
    dispatchUplink(d);
  }

  // The inbound WINDOW bridge listener — origin + source-window bound. It carries
  // the pre-context READY (which transfers the §12b port) and, in WINDOW mode
  // only, the post-context uplinks. Attached when the iframe mounts, detached on
  // teardown.
  function onBridgeMessage(event) {
    // (§6a) strict origin, BEFORE schema.
    if (event.origin !== cinatraOrigin) return;
    // (§6a-2) source-window binding, BEFORE schema — a sibling frame on the same
    // origin must never drive this bridge. Nullish source never matches.
    if (!frameWindow || event.source !== frameWindow) return;

    var d = event.data;
    if (!d || typeof d !== 'object' || typeof d.type !== 'string') return;

    if (d.type === MSG.ready) {
      // §4 READY → CONTEXT. The release stays synchronous with the READY task —
      // not because a credential could be misdelivered any more (there is none),
      // but because a message with no await in front of it has no interleaving
      // to reason about at all.
      if (!isValidReady(d)) return;
      // A READY on an already-served frame that REPLAYS the nonce we answered is
      // ignored outright — that is the property the single-context latch was
      // really protecting, and the one an attacker could try.
      if (contextSent && d.nonce === frameNonce) return;
      // §12b select the transport from the port the frame transferred on this
      // (origin+source-gated) READY. A transferred port -> PORT MODE. NO port
      // under `requirePort` -> FAIL CLOSED doing NOTHING AT ALL (no state
      // mutation, no send), so the window transport cannot be selected by
      // stripping the transferred port. NO port without `requirePort` -> WINDOW
      // MODE: the origin-pinned window transport.
      //
      // The protocol transfers EXACTLY ONE endpoint. A READY carrying more is
      // not a frame speaking this protocol, so it is refused rather than
      // silently reduced to its first port (codex round 0, finding 6).
      var ports = event.ports;
      if (ports && ports.length > 1) return;
      var transferredPort = (ports && ports.length === 1) ? ports[0] : null;
      if (!transferredPort && requirePort) return;
      // EVERY CHECK ON THIS READY HAS NOW PASSED, so it is safe to retire the
      // previous epoch. The order matters and is the whole point: a malformed
      // READY (bad version, bad nonce, two ports, no port under requirePort)
      // must NOT be able to tear down an established session on its way to being
      // refused — that would turn a rejected message into a denial of service.
      // `isValidReady` has already proven `seq` is a non-negative integer, and
      // the reset clears the gate, so the acceptance below cannot fail after it.
      if (contextSent) { resetBridgeEpoch(); }
      // Seed the iframe->parent monotonic gate with READY's seq (§6c); post-
      // context uplinks must strictly increase from it (whichever transport they
      // then ride).
      if (!acceptInboundSeq(d.seq)) return;
      // Bind the chosen transport, then set the single-context latch BEFORE
      // posting so a re-entrant delivery cannot double-send. In WINDOW mode
      // event.source === frameWindow was already verified above, so the post
      // targets the exact document that sent READY.
      if (transferredPort) {
        activePort = transferredPort;
        activeTransport = 'port';
        activePort.addEventListener('message', onPortMessage);
        activePort.start();
      } else {
        activeTransport = 'legacy';
      }
      contextSent = true;
      // A refusal (a credential-shaped value anywhere in the composed message)
      // sends NOTHING and stays latched: retrying would compose the same refused
      // message. The frame simply keeps waiting for a host that never speaks —
      // quiet and fail-closed, with nothing on the wire.
      sendContext(buildContext(d.nonce));
      return;
    }

    // All other WINDOW messages are WINDOW-mode post-context uplinks. In PORT
    // mode the uplinks ride the retained port (onPortMessage), never the window —
    // so a stray/spoofed window uplink is dropped here WITHOUT touching the seq
    // gate. Each window uplink still requires the established correlationId + a
    // monotonic seq for the iframe->parent direction.
    if (!contextSent || activeTransport !== 'legacy') return;
    if (!validUplinkEnvelope(d)) return;
    dispatchUplink(d);
  }

  // TEST-ONLY render-parity seam carrier (cinatra#1998 (c), epic #1216 S6). The
  // Cinatra render-parity E2E frames THIS module's `/embed/assistant` iframe and
  // needs the deterministic corpus-render seam params (`parityThread` /
  // `parityTheme`, cinatra#1998 (b)) to ride the iframe src the module builds —
  // the harness cannot reach into the module's fixed src otherwise. This reads a
  // NAMESPACED signal the test stages on the host admin page — a
  // `window.__cinatraParitySeam` global (STORAGE-FREE: the widget persists
  // nothing — the iframe owns storage — so this trips no web-storage invariant),
  // with a same-named query param as a fallback. PRODUCTION never stages it, so
  // the returned suffix is '' and the src is BYTE-IDENTICAL to before. It is
  // doubly inert in prod: even a forged signal is a no-op because the Cinatra
  // server IGNORES `parityThread` unless its server-only `EMBED_PARITY_SEAM` gate
  // is on (off in prod) — the seam is server-gated, so this can NEVER inject
  // content or bypass auth in production. Carries NO token (only the two
  // non-secret render-parity disambiguators), exactly like instanceId/assistant.
  //
  // Returns the RAW values, not an encoded suffix (codex round 1): percent-
  // encoding a value destroys the token boundary the credential guard looks for
  // (`"x cnx_…"` becomes `"x%20cnx_…"`, whose preceding character is a digit),
  // so every component must be scanned BEFORE it is encoded. The caller composes
  // and encodes; this function only reads.
  function embedParitySeamValues() {
    var seam = { thread: '', theme: '' };
    try {
      // 1) A namespaced global the harness stages (survives host-page URL
      //    rewrites; storage-free so the non-disclosure invariant holds).
      var g = window.__cinatraParitySeam;
      if (g && typeof g === 'object') {
        seam.thread = typeof g.thread === 'string' ? g.thread : '';
        seam.theme = typeof g.theme === 'string' ? g.theme : '';
      }
      // 2) Fallback: a namespaced query param on the host admin URL.
      if (!seam.thread && window.location && window.location.search && window.URLSearchParams) {
        var q = new URLSearchParams(window.location.search);
        seam.thread = q.get('cinatra_parity_thread') || '';
        seam.theme = q.get('cinatra_parity_theme') || '';
      }
      if (seam.theme !== 'github-dark' && seam.theme !== 'github-light') {
        seam.theme = '';
      }
    } catch (_) {
      return { thread: '', theme: '' };
    }
    return seam;
  }

  // Build the sandboxed embed iframe and attach the bridge listener. The src is
  // the Cinatra-served `/embed/assistant` route carrying only the NON-SECRET
  // disambiguators (instanceId, assistant). No credential is ever in the URL, and
  // since protocol 2 there is no credential in this page to put there.
  //
  // THE SANDBOX GRANT, AND WHY THE POPUP FLAGS ARE PART OF THE PROTOCOL.
  // `allow-scripts allow-same-origin` is what the frame needs to be a Cinatra
  // document at all. The two popup flags are what the FRAME-OWNED SIGN-IN needs,
  // and they are not optional: the ceremony deliberately runs in a TOP-LEVEL
  // Cinatra window, because that is the one place a Cinatra session cookie is
  // first-party and therefore works identically in browsers that block
  // third-party cookies. A sandboxed frame cannot open a window at all without
  // `allow-popups`; and a window opened WITHOUT `allow-popups-to-escape-sandbox`
  // inherits this frame's restrictions, so the hosted sign-in could neither
  // submit its form nor follow its own redirect back. Without both flags the
  // sign-in cannot complete and the assistant is unusable.
  //
  // The escape applies to the OPENED WINDOW, never to the frame. The frame still
  // has no top-navigation of this page, no forms, no modals, no downloads and no
  // pointer lock; what it gained is the ability to open a normal Cinatra window,
  // which is exactly the capability the protocol moved here from this shell.
  function mountBridgeIframe() {
    if (iframeEl) return;
    // THE FRAME URL IS AN OUTBOUND PAYLOAD TOO (codex round 0, finding 2). The
    // bridge guard covers the postMessage; it cannot cover this, because the src
    // is composed from config and from the render-parity seam and then LEAVES
    // THE PAGE AS AN HTTP REQUEST — where it also lands in history, in an access
    // log and in a referrer.
    //
    // THE SCAN RUNS ON THE RAW COMPONENTS, BEFORE ENCODING (codex round 1).
    // Scanning only the finished URL is not enough: encodeURIComponent destroys
    // the very token boundary the guard matches on, so `"x cnx_…"` arrives as
    // `"x%20cnx_…"` — preceded by a digit — and slips past. The composed string
    // is scanned as well, but the raw components are the check that counts.
    var seam = embedParitySeamValues();
    var rawParts = [
      config.cinatraUrl,
      buildContentContext().instanceId,
      EMBED_ASSISTANT,
      seam.thread,
      seam.theme,
    ];
    var src = config.cinatraUrl + '/embed/assistant' +
      '?instanceId=' + encodeURIComponent(rawParts[1]) +
      '&assistant=' + encodeURIComponent(EMBED_ASSISTANT) +
      (seam.thread ? '&parityThread=' + encodeURIComponent(seam.thread) : '') +
      (seam.thread && seam.theme ? '&parityTheme=' + encodeURIComponent(seam.theme) : '');
    // A URL that fails either check means the frame is NOT MOUNTED at all,
    // rather than a request going out with a bearer in the query string. The
    // fallback chrome stays visible, which is the honest outcome: something is
    // misconfigured badly enough that the assistant must not start.
    if (containsCredentialShapedValue(rawParts) || containsCredentialShapedValue(src)) {
      console.warn('[cinatra] refusing to frame the assistant: the embed URL carries a credential-shaped value');
      iframeEl = null;
      return;
    }
    iframeEl = document.createElement('iframe');
    iframeEl.className = 'cw-frame';
    iframeEl.setAttribute('title', 'Cinatra assistant');
    iframeEl.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox');
    iframeEl.setAttribute('referrerpolicy', 'no-referrer');
    iframeEl.setAttribute('allow', '');
    // Keep the captured frame window current across loads (source-window binding +
    // outbound posts target exactly this frame).
    iframeEl.addEventListener('load', function () {
      frameWindow = iframeEl.contentWindow;
    });
    frameHost.appendChild(iframeEl);
    // contentWindow is available synchronously once appended; set it now so a READY
    // that races the load event is still source-bound.
    frameWindow = iframeEl.contentWindow;
    window.addEventListener('message', onBridgeMessage);
    iframeEl.setAttribute('src', src);
  }

  // NOTE: the frame teardown that used to live here is gone with the thing that
  // called it. Under protocol 1 the shell had to destroy the frame whenever the
  // per-user token expired or the person had to sign in again, because a frame
  // without a fresh credential from the parent was a dead frame. The frame's
  // session is now its own: it re-authenticates in place, and the parent has no
  // authentication event to react to. One mounted frame per page, for the life of
  // the page.

  // …but the frame's DOCUMENT can still be replaced under that one element — the
  // frame reloads itself, and the PR that defines protocol 2 says a reload runs
  // the ceremony again. The replacement document announces itself with a FRESH
  // READY carrying a FRESH nonce, and under a plain single-context latch the
  // parent would ignore it forever: the widget would sit at "waiting for the
  // host" until the whole page was reloaded, and the old entangled port would
  // leak (codex round 0, finding 4).
  //
  // So a READY whose nonce differs from the one already served starts a NEW
  // EPOCH: the previous port is closed, every per-document binding is cleared,
  // and the new document gets its own correlationId and context message. A
  // REPLAY of the same nonce is still ignored — that is the property the latch
  // was really protecting, and it is the one an attacker could try. Re-serving a
  // context message is safe in a way re-serving a bootstrap never was: it
  // carries no credential, and the frame burns its own single-use nonce gate, so
  // a second context on the SAME document is refused at the far end anyway.
  function resetBridgeEpoch() {
    if (activePort) {
      try { activePort.removeEventListener('message', onPortMessage); } catch (_) {}
      try { activePort.close(); } catch (_) {}
    }
    activePort = null;
    activeTransport = null;
    frameNonce = null;
    correlationId = null;
    contextSent = false;
    inboundSeqLast = null;
    outboundSeqLast = null;
    appliedLru = [];
  }

  // ---------------------------------------------------------------------------
  // Open / collapse — circle↔widget swap
  // ---------------------------------------------------------------------------
  function openWidget() {
    isOpen = true;
    circle.style.zIndex = '9999990';
    setWidgetSize();
    cwWidget.style.display = 'block';
    // Mount the frame on the FIRST open and never again for this page: framing an
    // authenticated Cinatra surface on every node page a permitted editor merely
    // looks at would be a request nobody asked for. The frame handles everything
    // after that — including asking the person to sign in, which is its business
    // now and not this shell's.
    mountBridgeIframe();
  }

  function collapseWidget() {
    isOpen = false;
    cwWidget.style.display = 'none';
    circle.style.zIndex = '';
  }

  // ---------------------------------------------------------------------------
  // Event wiring
  // ---------------------------------------------------------------------------
  circle.addEventListener('click', function(e) {
    if (circleDragMoved) { circleDragMoved = false; e.stopPropagation(); return; }
    if (isOpen) { collapseWidget(); } else { openWidget(); }
  });
  closeBtn.addEventListener('click', function() { collapseWidget(); });

  // NOTE: there is no auth-popup listener here any more (cinatra#2674). The
  // hosted sign-in posts its result to `window.location.origin` — the CINATRA
  // origin, inside the frame — so this page could not receive it whatever it
  // listened for. That one line in the hosted return step is the load-bearing
  // control, and it is the browser's, not ours.

  document.addEventListener('click', function(e) {
    if (!isOpen) return;
    var path = e.composedPath ? e.composedPath() : [];
    for (var p = 0; p < path.length; p++) { if (path[p] === rootEl) return; }
    collapseWidget();
  });

  // ---------------------------------------------------------------------------
  // Resize: drag top-left corner to adjust width (left) and panel height (up). A
  // manual drag pins the height (disables the iframe-driven auto-grow).
  // ---------------------------------------------------------------------------
  var resizeDragging = false;
  var resizeStartX = 0, resizeStartY = 0;
  var resizeStartWidth = 0, resizeStartPanelH = 0;

  resizeEl.addEventListener('mousedown', function(e) {
    e.preventDefault();
    e.stopPropagation();
    resizeDragging = true;
    resizeStartX = e.clientX;
    resizeStartY = e.clientY;
    resizeStartWidth = currentWidth;
    resizeStartPanelH = currentPanelHeight;
  });

  document.addEventListener('mousemove', function(e) {
    if (circleDragging) {
      var dx = e.clientX - circleDragStartX;
      var dy = e.clientY - circleDragStartY;
      if (!circleDragMoved && (Math.abs(dx) >= CIRCLE_DRAG_THRESHOLD || Math.abs(dy) >= CIRCLE_DRAG_THRESHOLD)) {
        circleDragMoved = true;
        circle.style.cursor = 'grabbing';
      }
      if (circleDragMoved) {
        var newLeft = circleDragStartLeft + dx;
        var newTop = circleDragStartTop + dy;
        var clamped = clampCirclePos(newLeft, newTop);
        applyCirclePos(clamped.left, clamped.top);
      }
    }
    if (!resizeDragging) return;
    var dw = resizeStartX - e.clientX; // drag left = wider
    var dh = resizeStartY - e.clientY; // drag up = taller
    userResizedPanel = true;
    currentWidth = Math.max(320, Math.min(window.innerWidth - 48, resizeStartWidth + dw));
    currentPanelHeight = Math.max(MIN_PANEL_HEIGHT, Math.min(maxPanelHeight(), resizeStartPanelH + dh));
    setWidgetSize();
  });

  document.addEventListener('mouseup', function() {
    if (circleDragging) {
      circleDragging = false;
      circle.style.cursor = '';
    }
    resizeDragging = false;
  });

  // Synchronous mount construction is complete: mark mounted (this hides the
  // fallback chrome). Set LAST so any throw above leaves the fallback visible.
  rootEl.dataset.cinatraMounted = 'true';

  } // end mountWidget()

  // ---------------------------------------------------------------------------
  // Boot: mount UNCONDITIONALLY. Both handshakes this shell used to run are gone
  // — the AG-UI capability negotiation moved into the /embed/assistant iframe,
  // and so did the sign-in (cinatra#2674) — so there is nothing left to gate the
  // mount on. The always-visible fallback button remains until
  // data-cinatra-mounted is set at the end of synchronous mount construction, and
  // the frame itself is not mounted until the panel is first opened.
  // ---------------------------------------------------------------------------
  mountWidget();

})();
