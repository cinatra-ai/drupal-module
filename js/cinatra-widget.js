/**
 * @file
 * Cinatra assistant widget — CANONICAL, locally-shipped widget (cinatra#411).
 * This is the Drupal mirror of the canonical source-of-truth widget; it is
 * hand-mirrored from cinatra-ai/wordpress-plugin/assets/cinatra-widget.js (the
 * copy authored first). It is NOT a re-vendor of any Cinatra host route — the
 * cinatra repo's src/app/api/drupal/bundle.js/route.ts is the DEPRECATED,
 * pre-Option-A artifact (nothing executes it; scheduled for removal). See the
 * contract: cinatra docs/widget-source-of-truth.md.
 *
 * Implements the apiKey-free, short-lived token-exchange flow (wp#4 /
 * cinatra#220): the long-lived integration key never reaches the browser. The
 * browser exchanges a short-lived, origin/audience/scope-bound token via the
 * same-origin Drupal broker route (drupalSettings.cinatra.tokenEndpoint) and
 * streams to the instance with it.
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
 *
 * Sync model: author a widget change in the WordPress copy first, then mirror
 * it here (the two differ only in the CMS-config accessor + library plumbing).
 * tools/widget-parity-check.mjs gates the security-critical invariants.
 */
(function () {
  // ---------------------------------------------------------------------------
  // Bootstrap
  // ---------------------------------------------------------------------------
  var config = (window.drupalSettings && window.drupalSettings.cinatra) || {};
  // The long-lived key is NEVER in the browser. The widget needs the instance
  // origin plus the same-origin Drupal broker route that mints short-lived
  // tokens. (config.apiKey no longer exists by design.)
  if (!config.cinatraUrl || !config.tokenEndpoint) {
    console.warn('[cinatra] Missing drupalSettings.cinatra (cinatraUrl + tokenEndpoint required)');
    return;
  }
  var rootEl = document.getElementById('cinatra-root');
  if (!rootEl) { console.warn('[cinatra] #cinatra-root not found'); return; }
  if (rootEl.dataset.cinatraMounted === 'true') return;
  // NOTE: data-cinatra-mounted is set ONLY after capability negotiation succeeds
  // (see boot() at the bottom). Setting it earlier would hide the fallback chrome
  // even when the instance is unavailable/incompatible.

  // ---------------------------------------------------------------------------
  // Capability + version negotiation, and short-lived token exchange.
  //
  // capabilities: fetched once at boot from {cinatraUrl}/api/agents/
  // drupal-content-editor/capabilities (auth-free, static contract metadata).
  // It is a HARD PREREQUISITE: any failure — HTTP not-ok (incl. 404 / 5xx),
  // network error, timeout, non-JSON body, missing `capabilities` object,
  // missing/empty supportedContractVersions, no mutually-supported contract
  // version, missing required capability fields (streamPath / tokenPath), or
  // supportsTokenExchange !== true — ABORTS the mount. The widget then never
  // attaches its Shadow DOM and never sets data-cinatra-mounted, so the
  // always-visible fallback button remains as the "instance unavailable /
  // incompatible" chrome. There are NO optimistic defaults and NO legacy
  // long-lived path — the browser holds no apiKey and the same-origin broker
  // token is the only stream auth model.
  //
  // - supportsChangesFrame: the apply-changes affordance is enabled ONLY when
  //   the instance explicitly advertises it (absent flag => disabled).
  //
  // Client knows v1 and v2; it negotiates the highest mutually-supported
  // contract version and pins it on token-exchange + stream-init.
  // ---------------------------------------------------------------------------
  var CLIENT_CONTRACT_VERSIONS = ['v1', 'v2'];
  // Required-login (cinatra#410): the agent slug + the auth `client` name the
  // hand-mirrored Drupal differentiators (vs the WordPress source copy). The
  // hosted PKCE init/token resolve the agent's instances config key from
  // `client`, so it must match the agent's instancesConfigKey ('drupal').
  var AGENT_SLUG = 'drupal-content-editor';
  var AUTH_CLIENT = 'drupal';
  // cinatra#1221 S5 — the unified broker-auth assistant chat contract. The widget
  // streams here (NOT the OLD /api/agents/{slug}/stream relay); the cit_/cwu_
  // tokens are minted bound to THIS audience, so a token presented at the legacy
  // relay now fails aud_mismatch. This is a FIXED same-origin path (not the
  // capability-advertised streamPath) — the chat route is the single contract.
  var CHAT_ROUTE_PATH = '/api/assistants/chat';
  var negotiatedVersion = null;   // set ONLY by a successful negotiate()
  var supportsChanges = false;    // enabled ONLY when explicitly advertised
  var supportsMarkdown = false;   // enabled ONLY when explicitly advertised
  var streamPath = null;          // set ONLY by a successful negotiate()
  var tokenCache = null;          // { token, expiresAtMs }

  var STREAM_BASE = String(config.cinatraUrl).replace(/\/+$/, '');
  var TOKEN_ENDPOINT = String(config.tokenEndpoint);

  // Bounded-timeout fetch. AbortSignal.timeout() is not universal, so drive an
  // AbortController ourselves; the timer is always cleared.
  function fetchWithTimeout(url, opts, timeoutMs) {
    var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timer = null;
    var options = opts || {};
    if (controller) {
      options = Object.assign({}, options, { signal: controller.signal });
      timer = setTimeout(function () { try { controller.abort(); } catch (_) {} }, timeoutMs);
    }
    var p;
    try {
      p = fetch(url, options);
    } catch (err) {
      // fetch threw synchronously (very rare) — clear the timer and propagate.
      if (timer) clearTimeout(timer);
      return Promise.reject(err);
    }
    return p.then(
      function (resp) { if (timer) clearTimeout(timer); return resp; },
      function (err) { if (timer) clearTimeout(timer); throw err; }
    );
  }

  // CLIENT_CONTRACT_VERSIONS is ordered OLDEST -> NEWEST, so "last match wins"
  // yields the highest mutually-supported version. Returns null when there is
  // no mutual version (the caller then aborts the mount). Do not reverse the
  // array without flipping this loop's selection rule.
  function pickVersion(serverVersions) {
    if (!Array.isArray(serverVersions)) { return null; }
    var best = null;
    for (var i = 0; i < CLIENT_CONTRACT_VERSIONS.length; i++) {
      if (serverVersions.indexOf(CLIENT_CONTRACT_VERSIONS[i]) !== -1) {
        best = CLIENT_CONTRACT_VERSIONS[i];
      }
    }
    return best;
  }

  // Returns a Promise<boolean>: true only if /capabilities succeeded AND
  // validated. No optimistic defaults; the caller aborts the mount on false.
  function negotiate() {
    return fetchWithTimeout(STREAM_BASE + '/api/agents/drupal-content-editor/capabilities', {
      method: 'GET',
      cache: 'no-store',
    }, 5000).then(function (r) {
      if (!r || !r.ok) { return false; }
      return r.json().then(function (data) {
        if (!data || typeof data !== 'object') { return false; }
        var caps = data.capabilities;
        if (!caps || typeof caps !== 'object') { return false; }
        var version = pickVersion(data.supportedContractVersions);
        if (!version) { return false; }
        // The same-origin broker token is the only stream auth model — an
        // instance that cannot mint short-lived tokens is incompatible.
        if (caps.supportsTokenExchange !== true) { return false; }
        if (typeof caps.tokenPath !== 'string' || !caps.tokenPath) { return false; }
        if (typeof caps.streamPath !== 'string' || !caps.streamPath) { return false; }
        // SAME-ORIGIN STREAM PATH (token-exfiltration guard). The stream fetch
        // carries the short-lived Bearer token; it must hit the configured
        // instance origin and nowhere else. A malicious/compromised
        // /capabilities response could otherwise smuggle an authority via the
        // streamPath ("@host", "//host", "https://host", backslash tricks),
        // and STREAM_BASE + streamPath would ship the token off-origin.
        // Require a plain root-absolute path, then resolve+assert same-origin
        // and store only the validated pathname+search (never raw concat).
        if (caps.streamPath.indexOf('\\') !== -1) { return false; }     // no backslash authority tricks
        if (caps.streamPath.charAt(0) !== '/') { return false; }        // must be a root-absolute path
        if (caps.streamPath.charAt(1) === '/') { return false; }        // protocol-relative => off-origin authority
        var resolvedStreamPath;
        try {
          var base = new URL(config.cinatraUrl);
          var u = new URL(caps.streamPath, base.origin + '/');
          if (u.origin !== base.origin) { return false; }
          if (u.pathname.charAt(0) !== '/' || u.pathname.charAt(1) === '/') { return false; }
          resolvedStreamPath = u.pathname + u.search;
        } catch (_) { return false; }
        negotiatedVersion = version;
        streamPath = resolvedStreamPath;
        supportsChanges = caps.supportsChangesFrame === true;
        supportsMarkdown = caps.supportsMarkdown === true;
        return true;
      }).catch(function () { return false; });
    }).catch(function () {
      return false;
    });
  }

  // Exchange (or reuse a cached) short-lived token via the same-origin Drupal
  // broker route. The broker holds the long-lived key server-side and never
  // exposes it. Tokens are reused across turns until ~10s before expiry.
  function getStreamToken() {
    var now = Date.now();
    if (tokenCache && tokenCache.token && tokenCache.expiresAtMs - 10000 > now) {
      return Promise.resolve(tokenCache.token);
    }
    // Drupal's _csrf_token route requirement validates the token from the
    // `token` query parameter against the route path seed. The broker route is
    // also permission-gated and same-origin (session cookie), so the token is
    // defense-in-depth against cross-site POSTs.
    var url = TOKEN_ENDPOINT;
    if (config.csrfToken) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(config.csrfToken);
    }
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ contractVersion: negotiatedVersion }),
    }).then(function (r) {
      return r.text().then(function (raw) {
        var parsed = null;
        try { parsed = JSON.parse(raw); } catch (_) {}
        if (!r.ok || !parsed || !parsed.token) {
          // The same-origin Drupal broker returns { error: "<string>" }; the
          // instance returns { error: { message } }. Accept both shapes.
          var msg = '';
          if (parsed) {
            if (typeof parsed.error === 'string') { msg = parsed.error; }
            else if (parsed.error && parsed.error.message) { msg = parsed.error.message; }
            else if (parsed.message) { msg = parsed.message; }
          }
          if (!msg) { msg = 'token exchange failed (HTTP ' + r.status + ')'; }
          throw new Error(msg);
        }
        var ttlMs = (typeof parsed.expiresIn === 'number' ? parsed.expiresIn : 300) * 1000;
        tokenCache = { token: parsed.token, expiresAtMs: Date.now() + ttlMs };
        return parsed.token;
      });
    });
  }

  // ---------------------------------------------------------------------------
  // mountWidget() — builds the Shadow DOM + wires the assistant. Called ONLY
  // after negotiate() resolves true (see boot() at the bottom).
  // ---------------------------------------------------------------------------
  function mountWidget() {
  // Re-check after the async negotiation gap: a second copy of this IIFE could
  // have mounted while we awaited /capabilities. Bail if a Shadow DOM already
  // exists or the marker is already set (defense against duplicate includes).
  if (rootEl.dataset.cinatraMounted === 'true' || rootEl.shadowRoot) { return; }

  // ---------------------------------------------------------------------------
  // Shadow DOM
  // ---------------------------------------------------------------------------
  var shadow = rootEl.attachShadow({ mode: 'open' });
  // The data-cinatra-mounted marker (which hides the fallback chrome) is set at
  // the very END of synchronous mount construction — see the bottom of this
  // function. A throw at any point during mount therefore leaves the fallback
  // visible rather than hiding it over a half-built / dead widget.

  // ---------------------------------------------------------------------------
  // CSS
  // Collapsed: single logo circle (position:fixed, bottom-right).
  // Expanded:  .cw-widget flex-column (panel on top, pill on bottom), same anchor.
  //            Drag the top-left corner (.cw-resize) to resize width + panel height.
  // ---------------------------------------------------------------------------
  var style = document.createElement('style');
  style.textContent = [
    ':host { all: initial; }',

    /* Collapsed logo circle — same size/position as the submit button inside the pill.
       Pill padding-bottom:10px → bottom: 56+10=66px.
       Pill padding-right:12px  → right: 24+12=36px.
       Submit button: 32×32px. */
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

    /* Expanded widget: position:fixed container, panel+pill both absolutely placed.
       Pill is at bottom:0 and grows upward, overlapping the panel — panel stays put.
       JS sets: width, height = panelH + PILL_GAP + PILL_MIN_H */
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

    /* Response panel: absolute top of widget, fixed height set by JS */
    '.cw-panel {',
    '  position: absolute; top: 0; left: 0; right: 0;',
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

    /* Messages */
    '.cw-messages {',
    '  flex: 1; overflow-y: auto; padding: 16px;',
    '  display: flex; flex-direction: column; gap: 12px;',
    '}',
    '.cw-msg { line-height: 1.6; max-width: 88%; }',
    '.cw-msg-user {',
    '  align-self: flex-end; background: #15213a; color: #ffffff;',
    '  padding: 8px 14px; border-radius: 18px;',
    '  font: 14px system-ui, sans-serif; white-space: pre-wrap;',
    '}',
    '.cw-msg-assistant {',
    '  align-self: flex-start; color: #15213a;',
    '  font: 14px/1.6 system-ui, sans-serif;',
    '}',
    '.cw-msg-assistant p { margin: 4px 0; }',
    '.cw-msg-assistant p:first-child { margin-top: 0; }',
    '.cw-msg-assistant p:last-child { margin-bottom: 0; }',
    '.cw-msg-assistant h1,.cw-msg-assistant h2,.cw-msg-assistant h3 { font-weight:600; margin:12px 0 4px; line-height:1.3; }',
    '.cw-msg-assistant h1 { font-size:1.2em; }',
    '.cw-msg-assistant h2 { font-size:1.08em; }',
    '.cw-msg-assistant h3 { font-size:1em; }',
    '.cw-msg-assistant ul,.cw-msg-assistant ol { margin:6px 0; padding-left:20px; }',
    '.cw-msg-assistant li { margin:2px 0; }',
    '.cw-msg-assistant code { background:#f7f7f3; padding:1px 5px; border-radius:4px; font-family:ui-monospace,monospace; font-size:0.88em; }',
    '.cw-msg-assistant pre { background:#f7f7f3; padding:12px; border-radius:8px; overflow-x:auto; margin:8px 0; }',
    '.cw-msg-assistant pre code { background:none; padding:0; font-size:0.88em; }',
    '.cw-msg-assistant strong { font-weight:600; }',
    '.cw-msg-assistant em { font-style:italic; }',
    '.cw-msg-assistant a { color:#364e81; text-decoration:underline; }',
    '.cw-msg-assistant blockquote { border-left:3px solid #15213a14; padding-left:12px; margin:6px 0; color:#5a6477; }',
    '.cw-msg-assistant table { border-collapse:collapse; margin:8px 0; font-size:0.9em; }',
    '.cw-msg-assistant th,.cw-msg-assistant td { border:1px solid #15213a14; padding:6px 10px; text-align:left; }',
    '.cw-msg-assistant th { background:#f7f7f3; font-weight:600; }',
    '.cw-msg-assistant hr { border:none; border-top:1px solid #15213a14; margin:8px 0; }',
    /* Thinking indicator: pulsating dot + 'Thinking...' label, mirroring ThinkingIndicator in packages/chat/src/chat-page.tsx (no shimmer in widget). */
    /* Spacer at the bottom of the panel — pushes the scrollable messages area to end above the pill,
       so the scrollbar track never extends into the pill overlap zone. Height set by setWidgetSize(). */
    '.cw-messages-spacer { flex-shrink: 0; pointer-events: none; }',

    '.cw-thinking { display:flex; align-items:center; gap:8px; color:#5a6477; font-size:13px; }',
    '.cw-thinking-dot { position:relative; display:inline-flex; width:8px; height:8px; flex-shrink:0; }',
    '.cw-thinking-dot::before {',
    '  content:""; position:absolute; inset:0; border-radius:9999px;',
    '  background:#5a6477; opacity:0.75;',
    '  animation: cw-ping 1s cubic-bezier(0,0,0.2,1) infinite;',
    '}',
    '.cw-thinking-dot::after {',
    '  content:""; position:relative; display:inline-block;',
    '  width:8px; height:8px; border-radius:9999px; background:#5a6477;',
    '}',
    '.cw-thinking-label { font-weight:500; color:#5a6477; }',
    '@keyframes cw-ping {',
    '  75%, 100% { transform: scale(2); opacity: 0; }',
    '}',

    /* Prompt box: absolute bottom of widget, grows upward over the panel.
       align-items:flex-end keeps + and submit pinned to the bottom row.
       padding-bottom:14px gives the row a bit more lift from the bottom edge. */
    '.cw-pill {',
    '  position: absolute; bottom: 0; left: 0; right: 0;',
    '  background: #ffffff; border: 1px solid #15213a14; border-top: none;',
    '  border-radius: 0 0 16px 16px;',
    '  padding: 10px 12px;',
    '  display: flex; align-items: flex-end; gap: 10px;',
    '  box-sizing: border-box;',
    '  box-shadow: 0 4px 16px rgba(0,0,0,0.1);',
    '  z-index: 2;',
    '}',

    /* Flyout toggle (+): margin-bottom centers it with the 32px submit circle.
       + is ~20px tall → (32-20)/2 = 6px margin-bottom aligns centers. */
    '.cw-flyout-btn {',
    '  background: none; border: none; cursor: pointer; flex-shrink: 0;',
    '  color: #5a6477; font-size: 20px; line-height: 1;',
    '  font-family: system-ui, sans-serif; font-weight: 300;',
    '  padding: 0 2px; margin-bottom: 6px; display: flex; align-items: center;',
    '}',
    '.cw-flyout-btn:hover { color: #364e81; }',
    '.cw-flyout-btn:active { color: #2d416c; }',

    /* Textarea: grows freely with no max-height; no scrollbar */
    '.cw-textarea {',
    '  flex: 1; border: none; outline: none; resize: none;',
    '  font: 15px/1.5 system-ui, -apple-system, sans-serif;',
    '  background: transparent; color: #15213a;',
    '  min-height: 24px; overflow-y: hidden;',
    '  padding: 3px 0 0 0; margin: 0 0 4px;',
    '}',
    '.cw-textarea::placeholder { color: #5a6477; }',

    /* Submit circle inside the pill (bottom-right) */
    '.cw-submit {',
    '  width: 32px; height: 32px; border-radius: 9999px;',
    '  background: #364e81; border: none; cursor: pointer;',
    '  display: flex; align-items: center; justify-content: center;',
    '  flex-shrink: 0; transition: background 0.15s;',
    '}',
    '.cw-submit:hover { background: #2d416c; }',
    '.cw-submit:disabled { opacity: 0.4; cursor: not-allowed; }',

    /* Flyout menu */
    '.cw-flyout-menu {',
    '  position: absolute; bottom: calc(100% + 10px); left: 0;',
    '  background: #ffffff; border: 1px solid #15213a14;',
    '  border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.15);',
    '  padding: 6px; min-width: 180px; z-index: 10;',
    '}',
    '.cw-flyout-item {',
    '  display: block; width: 100%; box-sizing: border-box;',
    '  padding: 8px 12px; border: none; background: none;',
    '  text-align: left; font: 13px system-ui, sans-serif;',
    '  cursor: pointer; border-radius: 8px; color: #15213a; text-decoration: none;',
    '}',
    '.cw-flyout-item:hover { background: #e8e8e3; }',

    /* Diff card — rendered inline in the message list after a content edit */
    '.cw-diff-card { align-self: flex-start; flex-shrink: 0; max-width: 88%; margin-top: 4px; margin-bottom: 4px; border: 1px solid #15213a14; border-radius: 10px; overflow: hidden; font: 13px system-ui, sans-serif; }',
    '.cw-diff-card-header { padding: 6px 12px; background: #f7f7f3; border-bottom: 1px solid #15213a14; font-weight: 600; color: #5a6477; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; }',
    '.cw-diff-row { display: flex; gap: 8px; padding: 6px 12px; align-items: baseline; min-width: 0; }',
    '.cw-diff-row + .cw-diff-row { border-top: 1px solid #15213a14; }',
    '.cw-diff-field { font-weight: 600; color: #15213a; white-space: nowrap; flex-shrink: 0; }',
    '.cw-diff-values { display: flex; flex-direction: column; gap: 2px; min-width: 0; overflow: hidden; }',
    '.cw-diff-before { color: #5a6477; text-decoration: line-through; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }',
    '.cw-diff-after { color: #364e81; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }',
    '.cw-diff-footer { padding: 5px 12px; background: #e8e8e3; color: #5a6477; font-size: 11px; border-top: 1px solid #15213a14; }',

    /* Required-login window (cinatra#410). Shown in place of the conversation
       (.cw-messages + .cw-pill) until a valid per-user token is held. Matches the
       Cinatra /sign-in palette: brand navy #15213a primary on the #f7f7f3 panel,
       muted #5a6477 secondary text. NO email/password inputs and NO sign-up — the
       only affordance is the button that opens the hosted /widget-auth popup. */
    '.cw-login {',
    '  flex: 1; min-height: 0; display: flex; flex-direction: column;',
    '  align-items: center; justify-content: center;',
    '  padding: 32px 28px; text-align: center; gap: 16px; box-sizing: border-box;',
    '}',
    '.cw-login-mark { display: flex; align-items: center; justify-content: center; }',
    '.cw-login-title { font: 600 18px/1.3 system-ui, -apple-system, sans-serif; color: #15213a; letter-spacing: -0.01em; margin: 0; }',
    '.cw-login-sub { font: 14px/1.55 system-ui, -apple-system, sans-serif; color: #5a6477; max-width: 280px; margin: 0; }',
    '.cw-login-btn {',
    '  width: 100%; max-width: 260px; padding: 10px 16px; margin-top: 4px;',
    '  background: #15213a; color: #ffffff; border: none; border-radius: 10px;',
    '  font: 600 14px system-ui, -apple-system, sans-serif; cursor: pointer;',
    '  transition: background 0.15s;',
    '}',
    '.cw-login-btn:hover { background: #1d2c4d; }',
    '.cw-login-btn:disabled { opacity: 0.55; cursor: default; }',
    '.cw-login-err { font: 13px/1.5 system-ui, -apple-system, sans-serif; color: #b42318; min-height: 18px; max-width: 280px; }',
  ].join('\n');
  shadow.appendChild(style);

  // Local-only widget: do NOT inject a remote webfont. The original embed bundle
  // pulled Archivo from fonts.googleapis.com, but that is an undisclosed
  // third-party browser request and breaks the "fully local" guarantee. The
  // wordmark/headers fall back to the system-ui stack already declared in the
  // CSS font-family (Archivo, system-ui, sans-serif).

  // ---------------------------------------------------------------------------
  // SVG builders
  // ---------------------------------------------------------------------------
  var SVG_NS = 'http://www.w3.org/2000/svg';
  function mkEl(tag, attrs) {
    var el = document.createElementNS(SVG_NS, tag);
    for (var k in attrs) el.setAttribute(k, String(attrs[k]));
    return el;
  }
  function mkSvg(w, h, vb) { return mkEl('svg', { width: w, height: h, viewBox: vb, fill: 'none' }); }

  // Cinatra logo — logo-colored fedora on mint circle button
  // Paths and colors sourced from src/lib/cinatra-brand.ts (CINATRA_LOGO, CINATRA_THEME) at build time.
  function makeLogoSvg() {
    var svg = mkSvg(22, 14, '0 0 512 320');
    svg.setAttribute('fill', 'none');
    svg.appendChild(mkEl('path', { d: 'M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z', fill: '#c79545' }));
    svg.appendChild(mkEl('path', { d: 'M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z', fill: '#c79545' }));
    return svg;
  }

  // Cinatra logo — dark fedora on light panel header
  // Paths sourced from src/lib/cinatra-brand.ts (CINATRA_LOGO) at build time.
  function makeLogoDarkSvg() {
    // Spec §I rule 1: fedora height = wordmark font-size (14), width = 1.6×.
    var svg = mkSvg(22, 14, '0 0 512 320');
    svg.setAttribute('fill', 'none');
    svg.appendChild(mkEl('path', { d: 'M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z', fill: '#c79545' }));
    svg.appendChild(mkEl('path', { d: 'M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z', fill: '#c79545' }));
    return svg;
  }

  function makeArrowSvg() {
    var svg = mkSvg(14, 14, '0 0 24 24');
    svg.appendChild(mkEl('path', { d: 'M12 19V5', stroke: 'white', 'stroke-width': '2.5', 'stroke-linecap': 'round' }));
    svg.appendChild(mkEl('path', { d: 'M5 12l7-7 7 7', stroke: 'white', 'stroke-width': '2.5', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
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
  // Circle drag-to-reposition: position helpers (session-only, no persistence)
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

  // Circle drag state machine
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
  // DOM: expanded widget — panel + pill stacked
  // ---------------------------------------------------------------------------
  var currentWidth = 580;
  var currentPanelHeight = 440;
  // Min height of the pill (6+24+14 padding+textarea + 2px border) — used to
  // set the widget container height so absolute children have a reference.
  var PILL_MIN_H = 46;
  var PILL_GAP = 16;

  function setWidgetSize() {
    cwWidget.style.width = currentWidth + 'px';
    cwWidget.style.height = (currentPanelHeight + PILL_GAP + PILL_MIN_H) + 'px';
    panel.style.height = (currentPanelHeight + PILL_GAP + Math.floor(PILL_MIN_H / 2)) + 'px';
    spacerEl.style.height = (PILL_GAP + Math.floor(PILL_MIN_H / 2)) + 'px';
  }

  var cwWidget = document.createElement('div');
  cwWidget.className = 'cw-widget';
  cwWidget.style.display = 'none';
  shadow.appendChild(cwWidget);

  // Resize corner (top-left)
  var resizeEl = document.createElement('div');
  resizeEl.className = 'cw-resize';
  cwWidget.appendChild(resizeEl);

  // Response panel
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

  var messagesEl = document.createElement('div');
  messagesEl.className = 'cw-messages';
  messagesEl.setAttribute('role', 'log');
  messagesEl.setAttribute('aria-live', 'polite');
  panel.appendChild(messagesEl);

  var spacerEl = document.createElement('div');
  spacerEl.className = 'cw-messages-spacer';
  panel.appendChild(spacerEl);

  // ---------------------------------------------------------------------------
  // Required-login window (cinatra#410). Built once at mount; shown by
  // applyPanelMode() when there is no valid per-user token, hidden in
  // conversation mode. The .cw-messages + .cw-pill conversation surfaces are
  // hidden while this is shown, so the conversation is NEVER visible pre-login.
  // This panel has NO credential inputs and NO sign-up — its only affordance is
  // the button that opens the Cinatra-hosted /widget-auth login popup, so raw
  // credentials never touch this CMS-origin DOM.
  // ---------------------------------------------------------------------------
  var loginEl = document.createElement('div');
  loginEl.className = 'cw-login';

  var loginMark = document.createElement('div');
  loginMark.className = 'cw-login-mark';
  var loginLogo = mkSvg(40, 25, '0 0 512 320');
  loginLogo.setAttribute('fill', 'none');
  loginLogo.appendChild(mkEl('path', { d: 'M72 214 C 72 200 96 190 130 188 C 168 186 196 200 256 210 C 316 220 358 214 400 200 C 426 192 440 196 440 208 C 440 222 420 234 388 242 C 340 254 288 256 256 256 C 202 256 132 248 100 238 C 80 232 72 224 72 214 Z', fill: '#c79545' }));
  loginLogo.appendChild(mkEl('path', { d: 'M146 188 C 150 130 176 86 212 72 C 226 66 240 64 252 64 C 262 64 270 70 268 80 L 264 100 C 272 88 288 82 300 82 C 332 82 356 118 362 188 Z', fill: '#c79545' }));
  loginMark.appendChild(loginLogo);
  loginEl.appendChild(loginMark);

  var loginTitle = document.createElement('p');
  loginTitle.className = 'cw-login-title';
  loginTitle.textContent = 'Sign in to continue';
  loginEl.appendChild(loginTitle);

  var loginSub = document.createElement('p');
  loginSub.className = 'cw-login-sub';
  loginSub.textContent = 'Sign in with your Cinatra account to use the assistant on this site.';
  loginEl.appendChild(loginSub);

  var loginBtn = document.createElement('button');
  loginBtn.className = 'cw-login-btn';
  loginBtn.type = 'button';
  loginBtn.textContent = 'Sign in with Cinatra';
  loginEl.appendChild(loginBtn);

  var loginErr = document.createElement('div');
  loginErr.className = 'cw-login-err';
  loginErr.setAttribute('role', 'alert');
  loginErr.setAttribute('aria-live', 'polite');
  loginEl.appendChild(loginErr);

  panel.appendChild(loginEl);

  // Prompt box (pill)
  var pill = document.createElement('div');
  pill.className = 'cw-pill';
  cwWidget.appendChild(pill);

  // Flyout menu (absolute inside pill)
  var flyoutMenu = document.createElement('div');
  flyoutMenu.className = 'cw-flyout-menu';
  flyoutMenu.style.display = 'none';
  pill.appendChild(flyoutMenu);

  var clearItem = document.createElement('button');
  clearItem.className = 'cw-flyout-item';
  clearItem.type = 'button';
  clearItem.textContent = 'Clear conversation';
  flyoutMenu.appendChild(clearItem);

  var settingsItem = document.createElement('a');
  settingsItem.className = 'cw-flyout-item';
  // drupalAdminUrl is the Drupal settings-form route on THIS site (set by the
  // module). It is the local admin page, not a path on the Cinatra origin.
  settingsItem.href = config.drupalAdminUrl || '#';
  settingsItem.textContent = 'Widget administration';
  settingsItem.target = '_blank';
  settingsItem.rel = 'noopener noreferrer';
  flyoutMenu.appendChild(settingsItem);

  var flyoutBtn = document.createElement('button');
  flyoutBtn.className = 'cw-flyout-btn';
  flyoutBtn.type = 'button';
  flyoutBtn.setAttribute('aria-label', 'Options');
  flyoutBtn.textContent = '+';
  pill.appendChild(flyoutBtn);

  var textarea = document.createElement('textarea');
  textarea.className = 'cw-textarea';
  textarea.placeholder = 'Ask Cinatra…';
  textarea.rows = 1;
  pill.appendChild(textarea);

  var submitBtn = document.createElement('button');
  submitBtn.className = 'cw-submit';
  submitBtn.type = 'button';
  submitBtn.disabled = true;
  submitBtn.appendChild(makeArrowSvg());
  pill.appendChild(submitBtn);

  // ---------------------------------------------------------------------------
  // State
  // ---------------------------------------------------------------------------
  var isOpen = false;
  var isFlyoutOpen = false;
  var isStreaming = false;

  // Required-login state (cinatra#410). On a fresh mount there is never a valid
  // per-user token, so the panel starts in 'login' mode. `userToken` is held in
  // module scope (in-memory ONLY — never sessionStorage; a bearer in storage
  // outlives the tab and is XSS-exfiltratable). `pkce` holds the in-flight
  // handshake (verifier/state/popup) and is single-use.
  var panelMode = 'login';   // 'login' | 'conversation'
  var userToken = null;      // { token, expiresAtMs }
  var pkce = null;           // { codeVerifier, state, popup } during a handshake
  var popupTimer = null;     // setInterval handle while watching for popup close

  // Truncate long before/after values so they fit on one line in the diff card.
  function trunc(s, n) { return s && s.length > n ? s.slice(0, n) + '\u2026' : (s || ''); }

  function renderDiffCard(fields) {
    var card = document.createElement('div');
    card.className = 'cw-diff-card';
    var hdr = document.createElement('div');
    hdr.className = 'cw-diff-card-header';
    hdr.textContent = fields.length > 0 ? 'Changes applied' : 'Content updated';
    card.appendChild(hdr);
    if (fields.length === 0) {
      var note = document.createElement('div');
      note.className = 'cw-diff-row';
      note.style.cssText = 'color:#5a6477;font-size:12px;font-style:italic;';
      note.textContent = 'Field-level diff not available for rich content — reload will apply changes.';
      card.appendChild(note);
    }
    for (var fi = 0; fi < fields.length; fi++) {
      var f = fields[fi];
      var row = document.createElement('div');
      row.className = 'cw-diff-row';
      var fieldEl = document.createElement('span');
      fieldEl.className = 'cw-diff-field';
      fieldEl.textContent = (f.field || '') + ':';
      var vals = document.createElement('div');
      vals.className = 'cw-diff-values';
      if (f.before) {
        var bef = document.createElement('span');
        bef.className = 'cw-diff-before';
        bef.textContent = trunc(String(f.before), 80);
        vals.appendChild(bef);
      }
      var aft = document.createElement('span');
      aft.className = 'cw-diff-after';
      aft.textContent = trunc(String(f.after || '(removed)'), 80);
      vals.appendChild(aft);
      row.appendChild(fieldEl);
      row.appendChild(vals);
      card.appendChild(row);
    }
    return card;
  }

  // ---------------------------------------------------------------------------
  // History
  // ---------------------------------------------------------------------------
  var HISTORY_KEY = 'cinatra_history_' + (config.instanceId || 'default');
  var history = [];
  try { var raw = window.sessionStorage.getItem(HISTORY_KEY); if (raw) history = JSON.parse(raw) || []; } catch (_) {}
  function saveHistory() { try { window.sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history)); } catch (_) {} }

  // ---------------------------------------------------------------------------
  // Conversation thread id (cinatra#1221 S5). The unified assistant chat contract
  // (POST /api/assistants/chat) REQUIRES a `threadId` (1..200 chars) — the OLD
  // relay had none. We mint one per conversation and persist it (sessionStorage,
  // keyed with the history) so a reopen/auto-reload continues the SAME thread;
  // "Clear conversation" mints a fresh one. Held here only; it is a client-chosen
  // opaque id — the host authorizes it against the widget principal, never trusts
  // it for identity.
  // ---------------------------------------------------------------------------
  var THREAD_KEY = 'cinatra_thread_' + (config.instanceId || 'default');
  var _threadId = null;
  function newThreadId() {
    try { if (window.crypto && crypto.randomUUID) { return 'drw_' + crypto.randomUUID(); } } catch (_) {}
    try {
      var a = new Uint8Array(16); crypto.getRandomValues(a);
      var s = ''; for (var i = 0; i < a.length; i++) { s += (a[i] + 256).toString(16).slice(1); }
      return 'drw_' + s;
    } catch (_) {}
    return 'drw_' + String(Date.now()) + Math.random().toString(16).slice(2);
  }
  function getThreadId() {
    if (_threadId) return _threadId;
    try { _threadId = window.sessionStorage.getItem(THREAD_KEY) || null; } catch (_) {}
    if (!_threadId) { _threadId = newThreadId(); try { window.sessionStorage.setItem(THREAD_KEY, _threadId); } catch (_) {} }
    return _threadId;
  }
  function resetThreadId() {
    _threadId = newThreadId();
    try { window.sessionStorage.setItem(THREAD_KEY, _threadId); } catch (_) {}
  }

  // ---------------------------------------------------------------------------
  // Markdown — inline renderer (no CDN dependency; XSS-safe: all user text
  // goes through esc() before insertion, no raw innerHTML from LLM output)
  // ---------------------------------------------------------------------------
  function renderMd(text) {
    if (!text) return '';
    function esc(s) {
      return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function inlineRender(s) {
      s = esc(s);
      s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
      s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
      s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
      return s;
    }
    var lines = text.split('\n');
    var html = '';
    var inCode = false, codeLines = [];
    var listType = null, listItems = [];
    function flushList() {
      if (!listItems.length) return;
      var tag = listType === 'ol' ? 'ol' : 'ul';
      html += '<' + tag + '>' + listItems.map(function(li) { return '<li>' + inlineRender(li) + '</li>'; }).join('') + '</' + tag + '>';
      listItems = []; listType = null;
    }
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (!inCode && /^```/.test(line)) { flushList(); inCode = true; codeLines = []; continue; }
      if (inCode) {
        if (/^```/.test(line)) { html += '<pre><code>' + esc(codeLines.join('\n')) + '</code></pre>'; inCode = false; codeLines = []; }
        else codeLines.push(line);
        continue;
      }
      var olM = line.match(/^(\d+)\.\s+(.*)/);
      var ulM = !olM && line.match(/^[-*]\s+(.*)/);
      if (olM) { if (listType !== 'ol') flushList(); listType = 'ol'; listItems.push(olM[2]); }
      else if (ulM) { if (listType !== 'ul') flushList(); listType = 'ul'; listItems.push(ulM[1]); }
      else {
        flushList();
        var hM = line.match(/^(#{1,3})\s+(.*)/);
        if (hM) { var lvl = Math.min(hM[1].length + 1, 4); html += '<h' + lvl + '>' + inlineRender(hM[2]) + '</h' + lvl + '>'; }
        else if (line.trim() === '') html += '';
        else html += '<p>' + inlineRender(line) + '</p>';
      }
    }
    if (inCode) html += '<pre><code>' + esc(codeLines.join('\n')) + '</code></pre>';
    flushList();
    return html;
  }

  // Render assistant content. Markdown is used ONLY when the instance advertised
  // supportsMarkdown; otherwise the text is rendered as plain text (absent
  // forward flag => the behavior is disabled).
  function renderAssistantInto(el, content) {
    if (supportsMarkdown) { el.innerHTML = renderMd(content); }
    else { el.textContent = content || ''; }
  }

  // ---------------------------------------------------------------------------
  // Render message bubble
  // ---------------------------------------------------------------------------
  function renderMessage(role, content, asAssistant) {
    var el = document.createElement('div');
    el.className = 'cw-msg cw-msg-' + role;
    if (asAssistant) renderAssistantInto(el, content);
    else el.textContent = content;
    messagesEl.appendChild(el);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return el;
  }

  for (var i = 0; i < history.length; i++) {
    var h = history[i];
    renderMessage(h.role, h.content, h.role === 'assistant');
    if (h.diff && Array.isArray(h.diff)) {
      messagesEl.appendChild(renderDiffCard(h.diff));
    }
  }

  // ---------------------------------------------------------------------------
  // Open / collapse — circle↔widget swap
  // ---------------------------------------------------------------------------
  function openWidget() {
    isOpen = true;
    // Keep circle visible but behind the widget panel while open.
    circle.style.zIndex = '9999990';
    setWidgetSize();
    cwWidget.style.display = 'block';
    // If a long-idle widget is reopened in conversation mode but the per-user
    // token has since expired, drop back to login rather than show a dead
    // conversation (the stream would 401 anyway). applyPanelMode() reflects it.
    if (panelMode === 'conversation' && !userTokenValid()) { forceReLogin(); }
    if (panelMode === 'conversation') { textarea.focus(); }
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function collapseWidget() {
    isOpen = false;
    closeFlyout();
    cwWidget.style.display = 'none';
    // Restore circle z-index to CSS default (10000000) when panel is closed.
    circle.style.zIndex = '';
  }

  function closeFlyout() {
    isFlyoutOpen = false;
    flyoutMenu.style.display = 'none';
  }

  // ---------------------------------------------------------------------------
  // Auto-resize textarea
  // ---------------------------------------------------------------------------
  function resizeTextarea() {
    textarea.style.height = 'auto';
    textarea.style.height = textarea.scrollHeight + 'px';
    var pillH = pill.offsetHeight || PILL_MIN_H;
    var deltaH = Math.max(0, pillH - PILL_MIN_H);
    var spacerH = PILL_GAP + Math.floor(PILL_MIN_H / 2);
    panel.style.height = (currentPanelHeight + spacerH - deltaH) + 'px';
  }
  textarea.addEventListener('input', resizeTextarea);
  textarea.addEventListener('input', function () {
    if (!isStreaming) submitBtn.disabled = textarea.value.trim() === '';
  });

  // ---------------------------------------------------------------------------
  // Submit
  // ---------------------------------------------------------------------------
  function doSubmit() {
    var text = textarea.value.trim();
    if (text && !isStreaming) {
      textarea.value = '';
      resizeTextarea();
      sendMessage(text);
    }
  }

  circle.addEventListener('click', function(e) {
    if (circleDragMoved) { circleDragMoved = false; e.stopPropagation(); return; }
    if (isOpen) { collapseWidget(); } else { openWidget(); }
  });
  closeBtn.addEventListener('click', function() { collapseWidget(); });
  submitBtn.addEventListener('click', function() { doSubmit(); });
  loginBtn.addEventListener('click', function() { startLogin(); });

  // ---------------------------------------------------------------------------
  // Required-login (cinatra#410): listen ONCE for the hosted /widget-auth popup's
  // postMessage. The handshake is bound three ways — the message must come from
  // the configured instance ORIGIN, carry the exact type, and its `state` must
  // match the in-flight PKCE tuple. We additionally bind to the popup's window
  // (ev.source === pkce.popup) so another same-origin Cinatra window/frame can
  // never complete a handshake even if `state` were ever confused/leaked.
  // ---------------------------------------------------------------------------
  window.addEventListener('message', function (ev) {
    var instOrigin;
    try { instOrigin = new URL(config.cinatraUrl).origin; } catch (_) { return; }
    if (ev.origin !== instOrigin) { return; }
    var d = ev.data;
    if (!d || d.type !== 'cinatra-widget-auth' || typeof d.code !== 'string') { return; }
    if (!pkce) { return; }                              // no handshake in flight
    if (!pkce.popup || ev.source !== pkce.popup) { return; } // source binding (fail-closed: a null/foreign source is rejected)
    if (d.state !== pkce.state) { return; }             // CSRF/state binding
    redeemCode(d.code);                                 // single-use; pkce consumed inside
  });

  textarea.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey && !isStreaming) {
      e.preventDefault();
      doSubmit();
    }
  });

  // ---------------------------------------------------------------------------
  // Click outside → collapse
  // ---------------------------------------------------------------------------
  document.addEventListener('click', function(e) {
    if (!isOpen) return;
    var path = e.composedPath ? e.composedPath() : [];
    for (var p = 0; p < path.length; p++) { if (path[p] === rootEl) return; }
    collapseWidget();
  });

  // ---------------------------------------------------------------------------
  // Flyout
  // ---------------------------------------------------------------------------
  flyoutBtn.addEventListener('click', function(e) {
    e.stopPropagation();
    isFlyoutOpen = !isFlyoutOpen;
    flyoutMenu.style.display = isFlyoutOpen ? 'block' : 'none';
  });

  clearItem.addEventListener('click', function() {
    history = []; saveHistory(); resetThreadId(); messagesEl.innerHTML = ''; closeFlyout();
  });

  // ---------------------------------------------------------------------------
  // Resize: drag top-left corner to adjust width (left) and panel height (up)
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
    currentWidth = Math.max(320, Math.min(window.innerWidth - 48, resizeStartWidth + dw));
    currentPanelHeight = Math.max(200, Math.min(window.innerHeight - 200, resizeStartPanelH + dh));
    setWidgetSize();
  });

  document.addEventListener('mouseup', function() {
    if (circleDragging) {
      circleDragging = false;
      circle.style.cursor = '';
    }
    resizeDragging = false;
  });

  // ---------------------------------------------------------------------------
  // Required-login (cinatra#410): the per-user PKCE handshake against the hosted
  // /widget-auth surface (#407), the dual-token stream gate (#408), and the
  // login-window mode toggle. The browser never holds the long-lived cnx_ key:
  // both the init and token redemptions go through the same-origin Drupal broker
  // routes, which present cnx_ server-to-server (exactly like the cit_ stream
  // token mint). The opaque cwu_ user token is short-lived (15-min TTL, no
  // refresh) and held in memory only.
  //
  // Drupal CSRF idiom (the ONLY divergence from the WordPress source copy):
  // the broker routes are `_csrf_token: 'TRUE'`, which validates the token from
  // the `?token=` QUERY parameter (the route-path-seeded token), NOT a header —
  // mirroring the existing getStreamToken() above. Each route's token is seeded
  // to its OWN path, so the init and token relays carry DIFFERENT tokens
  // (authInitCsrfToken / authTokenCsrfToken). brokerUrl() appends the supplied
  // token as a query param and brokerHeaders() carries no CSRF header.
  // ---------------------------------------------------------------------------

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
  async function sha256b64url(str) {
    var data = new TextEncoder().encode(str);
    var dig = await crypto.subtle.digest('SHA-256', data);
    return b64url(new Uint8Array(dig));
  }

  // Append the given route-seeded Drupal CSRF token as the `?token=` query the
  // broker validates. Each _csrf_token route seeds its token to its own path, so
  // the caller passes the token that matches the endpoint.
  function brokerUrl(endpoint, csrfToken) {
    var url = String(endpoint);
    if (csrfToken) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + 'token=' + encodeURIComponent(csrfToken);
    }
    return url;
  }
  function brokerHeaders() {
    return { 'Content-Type': 'application/json' };
  }

  function userTokenValid() {
    return !!(userToken && userToken.token && (userToken.expiresAtMs - 5000) > Date.now());
  }

  // Reflect panelMode into the DOM. The header (wordmark + close) stays in both
  // modes; the conversation surfaces are display:none in login mode so they are
  // never visible without a valid token.
  function applyPanelMode() {
    var login = panelMode === 'login';
    loginEl.style.display = login ? 'flex' : 'none';
    messagesEl.style.display = login ? 'none' : 'flex';
    spacerEl.style.display = login ? 'none' : 'block';
    pill.style.display = login ? 'none' : 'flex';
  }

  function clearHandshake() {
    if (popupTimer) { try { clearInterval(popupTimer); } catch (_) {} popupTimer = null; }
    pkce = null;
  }

  // Drop the user token and return to the login window. Used on expiry and on a
  // fail-closed 401 from the dual-token stream. Cleans up streaming UI state so
  // a stale "Thinking…" bubble never implies a response is still in progress.
  function forceReLogin(message) {
    userToken = null;
    isStreaming = false;
    clearHandshake();
    panelMode = 'login';
    applyPanelMode();
    submitBtn.disabled = textarea.value.trim() === '';
    loginErr.textContent = message || 'Your session expired. Please sign in again.';
  }

  function watchPopupClosed() {
    if (popupTimer) { try { clearInterval(popupTimer); } catch (_) {} popupTimer = null; }
    var ticks = 0;
    popupTimer = setInterval(function () {
      ticks++;
      var closed = false;
      try { closed = !pkce || !pkce.popup || pkce.popup.closed; } catch (_) { closed = false; }
      if (closed || ticks > 600) {
        try { clearInterval(popupTimer); } catch (_) {}
        popupTimer = null;
        if (pkce) {
          pkce = null;
          loginBtn.disabled = false;
        }
      }
    }, 500);
  }

  async function startLogin() {
    if (pkce) { return; }                  // reject concurrent handshakes
    loginErr.textContent = '';
    loginBtn.disabled = true;
    try {
      if (typeof window.crypto === 'undefined' || !window.crypto || !crypto.subtle || typeof btoa === 'undefined') {
        throw new Error('Secure sign-in is not available in this browser.');
      }
      var verifier = randB64url(48);
      var challenge = await sha256b64url(verifier);
      var state = randB64url(24);

      // 1) init via OUR same-origin broker (cnx_ presented server-to-server).
      var initResp = await fetch(brokerUrl(config.authInitEndpoint, config.authInitCsrfToken), {
        method: 'POST',
        credentials: 'same-origin',
        headers: brokerHeaders(),
        body: JSON.stringify({
          client: AUTH_CLIENT,
          agentSlug: AGENT_SLUG,
          codeChallenge: challenge,
          codeChallengeMethod: 'S256',
          state: state,
          instanceId: config.instanceId || undefined,
        }),
      });
      if (!initResp.ok) { throw new Error('Could not start sign-in.'); }
      var initBody = await initResp.json();
      if (!initBody || !initBody.authorizeUrl) { throw new Error('Could not start sign-in.'); }

      // SECURITY: the popup destination MUST be same-origin with the configured
      // instance — defense against a compromised broker steering the login popup
      // off-instance. Resolve and re-assert the origin here.
      var instOrigin = new URL(config.cinatraUrl).origin;
      var authUrl = new URL(initBody.authorizeUrl, instOrigin + '/');
      if (authUrl.origin !== instOrigin) { throw new Error('Refusing off-origin sign-in.'); }

      pkce = { codeVerifier: verifier, state: state, popup: null };

      // 2) open the hosted login popup. We need window.opener for the popup's
      // postMessage back, so we do NOT pass noopener; the message listener's
      // origin + type + state + source binding are the controls.
      var popup = window.open(authUrl.href, 'cinatra-login',
        'width=460,height=640,menubar=no,toolbar=no,location=yes,status=no');
      if (!popup) {
        pkce = null;
        throw new Error('Pop-up blocked. Allow pop-ups for this site and try again.');
      }
      pkce.popup = popup;
      watchPopupClosed();
    } catch (err) {
      clearHandshake();
      loginErr.textContent = (err && err.message) ? err.message : 'Sign-in failed.';
      loginBtn.disabled = false;
    }
  }

  async function redeemCode(code) {
    var local = pkce;
    clearHandshake();                      // consume handshake + stop popup watch
    loginBtn.disabled = true;
    try {
      var resp = await fetch(brokerUrl(config.authTokenEndpoint, config.authTokenCsrfToken), {
        method: 'POST',
        credentials: 'same-origin',
        headers: brokerHeaders(),
        body: JSON.stringify({
          grantType: 'authorization_code',
          client: AUTH_CLIENT,
          agentSlug: AGENT_SLUG,
          code: code,
          codeVerifier: local.codeVerifier,
        }),
      });
      if (!resp.ok) { throw new Error('Sign-in could not be completed.'); }
      var body = await resp.json();
      // Validate the token shape before trusting it (defends against a malformed
      // broker/upstream response): opaque Bearer cwu_ token + a sane TTL.
      if (!body || typeof body.token !== 'string' || body.token.indexOf('cwu_') !== 0) {
        throw new Error('Sign-in could not be completed.');
      }
      if (body.tokenType && String(body.tokenType).toLowerCase() !== 'bearer') {
        throw new Error('Sign-in could not be completed.');
      }
      var ttlSec = (typeof body.expiresIn === 'number' && body.expiresIn > 0) ? body.expiresIn : 900;
      if (ttlSec > 900) { ttlSec = 900; }   // clamp to the backend max (15-min TTL)
      userToken = { token: body.token, expiresAtMs: Date.now() + (ttlSec * 1000) };
      try { if (local.popup && !local.popup.closed) { local.popup.close(); } } catch (_) {}
      loginErr.textContent = '';
      panelMode = 'conversation';
      applyPanelMode();
      textarea.focus();
      submitBtn.disabled = textarea.value.trim() === '';
    } catch (err) {
      try { if (local && local.popup && !local.popup.closed) { local.popup.close(); } } catch (_) {}
      loginErr.textContent = (err && err.message) ? err.message : 'Sign-in failed.';
    } finally {
      loginBtn.disabled = false;
    }
  }

  // ---------------------------------------------------------------------------
  // SSE streaming chat
  // ---------------------------------------------------------------------------
  async function sendMessage(userText) {
    // DUAL-TOKEN GATE (cinatra#408): a valid per-user token is REQUIRED to
    // stream. If it is missing/expired, force re-login BEFORE appending any
    // message bubble — the conversation must never proceed without a token.
    if (!userTokenValid()) { forceReLogin(); return; }
    history.push({ role: 'user', content: userText });
    renderMessage('user', userText, false);
    saveHistory();

    var assistantEl = document.createElement('div');
    assistantEl.className = 'cw-msg cw-msg-assistant cw-thinking';
    assistantEl.innerHTML = '<span class="cw-thinking-dot"></span><span class="cw-thinking-label">Thinking…</span>';
    messagesEl.appendChild(assistantEl);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    // Turn state. The unified route streams AG-UI events over a durable log; the
    // widget accumulates the text deltas into one bubble and tracks the run id +
    // last SSE event id for a best-effort resume.
    var assistantText = '';
    var streamingStarted = false;
    var pendingSeparator = false;   // insert a blank line when a new text segment opens after a tool round
    var hadContentEdit = false;     // a *_content_editor_run tool call happened this turn
    var sawTerminal = false;        // a RUN_FINISHED / RUN_ERROR frame arrived
    var currentRunId = null;        // captured from the AG-UI events (resume target)
    var lastEventId = '';           // SSE `id:` cursor for Last-Event-ID resume
    isStreaming = true;
    submitBtn.disabled = true;

    // Extract a single SSE field value ("id" / "data") from a record line, or
    // null if the line is not that field. Tolerates the one-space form the host
    // emits ("id: x", "data: {...}") and a no-space form.
    function sseField(line, name) {
      if (line.indexOf(name + ':') !== 0) { return null; }
      var v = line.slice(name.length + 1);
      if (v.charAt(0) === ' ') { v = v.slice(1); }
      return v;
    }

    // Apply one decoded AG-UI event. The unified harness (ag-ui-sink-adapter)
    // maps the runtime's bespoke sink onto the AG-UI union — the `type` lives in
    // the JSON, NOT on an SSE `event:` line. We render TEXT_MESSAGE_CONTENT
    // deltas, key the content-edit reload on a *_content_editor_run TOOL_CALL,
    // and treat RUN_ERROR / RUN_FINISHED as terminal (exactly once).
    function handleAgUiEvent(ev) {
      if (!ev || typeof ev !== 'object' || typeof ev.type !== 'string') { return; }
      if (typeof ev.runId === 'string' && ev.runId) { currentRunId = ev.runId; }
      switch (ev.type) {
        case 'TEXT_MESSAGE_START':
          // A fresh text segment after existing content (e.g. resumed prose after
          // a tool round) is separated by a blank line for readability.
          if (assistantText) { pendingSeparator = true; }
          break;
        case 'TEXT_MESSAGE_CONTENT':
          if (typeof ev.delta === 'string' && ev.delta) {
            if (!streamingStarted) { streamingStarted = true; assistantEl.classList.remove('cw-thinking'); }
            if (pendingSeparator) { assistantText += '\n\n'; pendingSeparator = false; }
            assistantText += ev.delta;
            renderAssistantInto(assistantEl, assistantText);
            messagesEl.scrollTop = messagesEl.scrollHeight;
          }
          break;
        case 'TOOL_CALL_START':
          // The AG-UI wire deliberately carries NO tool RESULT payload, so the
          // old field-level `changes` diff is gone. We detect that a content edit
          // occurred by the tool NAME and reload the page on completion so the
          // applied change becomes visible (the content-editor demotes a
          // published node to draft; the edit lives in the node's revisions).
          if (typeof ev.toolCallName === 'string' && /_content_editor_run$/.test(ev.toolCallName)) {
            hadContentEdit = true;
            if (!streamingStarted) { streamingStarted = true; assistantEl.classList.remove('cw-thinking'); }
          }
          break;
        case 'RUN_ERROR':
          sawTerminal = true;
          assistantEl.classList.remove('cw-thinking');
          if (assistantText) { renderAssistantInto(assistantEl, assistantText); }
          else { assistantEl.textContent = 'Error: ' + (typeof ev.message === 'string' && ev.message ? ev.message : 'The assistant stream failed.'); }
          break;
        case 'RUN_FINISHED':
          sawTerminal = true;
          if (assistantText) { renderAssistantInto(assistantEl, assistantText); }
          break;
        default:
          // RUN_STARTED / TEXT_MESSAGE_END / TOOL_CALL_END / DATA_PART: no UI.
          break;
      }
    }

    // Consume an AG-UI SSE reader to a terminal frame (or the stream end). Frames
    // are `id: <redisId>\n data: <json>\n\n`; `:`-comment/keepalive lines and
    // blank lines are ignored. Returns once a terminal event is seen so a later
    // resume tail cannot double-apply.
    async function pumpReader(reader) {
      var decoder = new TextDecoder();
      var buffer = '';
      while (true) {
        var chunk = await reader.read();
        if (chunk.done) { break; }
        buffer += decoder.decode(chunk.value, { stream: true });
        var records = buffer.split('\n\n');
        buffer = records.pop() || '';
        for (var r = 0; r < records.length; r++) {
          if (!records[r]) { continue; }
          var lines = records[r].split('\n');
          var dataParts = [];
          for (var j = 0; j < lines.length; j++) {
            var idv = sseField(lines[j], 'id');
            if (idv !== null) { if (idv) { lastEventId = idv; } continue; }
            var dv = sseField(lines[j], 'data');
            if (dv !== null) { dataParts.push(dv); }
          }
          if (!dataParts.length) { continue; }
          var ev; try { ev = JSON.parse(dataParts.join('\n')); } catch (_) { continue; }
          handleAgUiEvent(ev);
          if (sawTerminal) { return; }
        }
      }
    }

    try {
      // SECURITY: build the stream URL from the configured instance origin + the
      // FIXED unified chat path. It is a constant root-absolute path (not a
      // server-advertised one), but we still re-resolve + re-assert the origin
      // BEFORE minting the token so the cit_ Bearer can never leave the
      // configured instance origin. The off-origin `throw` MUST precede
      // getStreamToken() (the last-line-of-defense ordering).
      var streamBase = new URL(config.cinatraUrl);
      var streamUrl = new URL(CHAT_ROUTE_PATH, streamBase.origin + '/');
      if (streamUrl.origin !== streamBase.origin) {
        throw new Error('Refusing to stream to an off-origin endpoint.');
      }

      // Capabilities are already negotiated (mount is gated on success), so the
      // broker token-exchange path is guaranteed available. Exchange a
      // short-lived token; the long-lived key never touches JS.
      var streamToken;
      try {
        streamToken = await getStreamToken();
      } catch (tokErr) {
        assistantEl.classList.remove('cw-thinking');
        assistantEl.textContent = 'Could not authorize the assistant: ' + (tokErr && tokErr.message ? tokErr.message : 'token exchange failed');
        return;
      }

      var response = await fetch(streamUrl.href, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': 'Bearer ' + streamToken,
          // DUAL-TOKEN (cinatra#408): the per-user token is required by the
          // fail-closed broker-auth route in addition to the cit_ Bearer above.
          'X-Cinatra-Widget-User-Token': userToken.token,
        },
        // UNIFIED CONTRACT (cinatra#1221 S5). The route requires a `threadId` and
        // resolves the widget binding from `assistant` (the connector kind /
        // handle). The OLD relay's `contractVersion` + `context` fields are not
        // part of this schema and are dropped.
        body: JSON.stringify({
          threadId: getThreadId(),
          assistant: AUTH_CLIENT,
          messages: history.map(function(m) { return { role: m.role, content: m.content }; }),
        }),
      });

      // FAIL-CLOSED RE-LOGIN (cinatra#408): an expired/revoked/invalid per-user
      // token returns 401 with X-Cinatra-Widget-Auth: required. Drop the token,
      // remove the in-flight "Thinking…" bubble, and return to the login window.
      if (response.status === 401 && response.headers.get('X-Cinatra-Widget-Auth') === 'required') {
        try { if (assistantEl && assistantEl.parentNode) { assistantEl.parentNode.removeChild(assistantEl); } } catch (_) {}
        // The user turn never produced a response; drop it from history so the
        // re-login does not replay a half-sent turn on the next send.
        if (history.length && history[history.length - 1].role === 'user') { history.pop(); saveHistory(); }
        forceReLogin();
        return;
      }

      if (!response.ok || !response.body) {
        var errText = 'Error ' + response.status;
        try {
          var raw = await response.text();
          var parsed = null;
          try { parsed = JSON.parse(raw); } catch (_) {}
          // Structured contract/admin error: { error: { message, code } }.
          if (parsed && parsed.error && parsed.error.message) { errText = parsed.error.message; }
          else if (parsed && typeof parsed.error === 'string') { errText = parsed.error; }
          else { errText += ': ' + raw.slice(0, 200); }
        } catch (_) {}
        assistantEl.classList.remove('cw-thinking');
        assistantEl.textContent = errText;
        return;
      }

      // RESUME CREDENTIAL (cinatra#1221 S5, OPTION A). The run-bound resume token
      // is delivered on a response header; the browser re-presents it on the
      // AG-UI resume/tail GET if the primary stream drops before a terminal
      // frame. NOTE: cross-origin, this header is readable ONLY once the host
      // adds it to Access-Control-Expose-Headers AND CORS-reflects the resume GET
      // (both core follow-ups); until then this is null and resume degrades to a
      // fresh turn. Never persisted; in-memory for this turn only.
      var resumeToken = null;
      try { resumeToken = response.headers.get('X-Cinatra-Chat-Resume-Token'); } catch (_) {}

      await pumpReader(response.body.getReader());

      // BEST-EFFORT RESUME: the primary stream ended WITHOUT a terminal frame
      // (transport drop) and we hold a run-bound resume token — reconnect ONCE to
      // the AG-UI resume/tail route with Last-Event-ID and continue. Any failure
      // (network / 401 / cross-origin CORS block) degrades silently to whatever
      // already streamed. Same-origin re-assertion mirrors the POST.
      if (!sawTerminal && resumeToken && currentRunId) {
        try {
          var resumeUrl = new URL('/api/assistants/runs/' + encodeURIComponent(currentRunId) + '/stream', streamBase.origin + '/');
          if (resumeUrl.origin === streamBase.origin) {
            var resumeHeaders = { 'Authorization': 'Bearer ' + resumeToken };
            if (lastEventId) { resumeHeaders['Last-Event-ID'] = lastEventId; }
            var resumeResp = await fetch(resumeUrl.href, { method: 'GET', headers: resumeHeaders });
            if (resumeResp && resumeResp.ok && resumeResp.body) {
              await pumpReader(resumeResp.body.getReader());
            }
          }
        } catch (_) { /* degrade: keep whatever streamed */ }
      }

      if (assistantText) { history.push({ role: 'assistant', content: assistantText }); saveHistory(); }
      else if (!streamingStarted) { assistantEl.classList.remove('cw-thinking'); assistantEl.textContent = '(no response)'; }

      // A content edit was applied — reload the page so the change is visible.
      if (hadContentEdit && sawTerminal) {
        try { window.sessionStorage.setItem('cinatra-reopen', '1'); } catch (_) {}
        setTimeout(function() { window.location.reload(); }, 1500);
      }
    } catch (err) {
      assistantEl.classList.remove('cw-thinking');
      assistantEl.textContent = 'Network error: ' + (err && err.message ? err.message : 'unknown');
    } finally {
      isStreaming = false;
      submitBtn.disabled = textarea.value.trim() === '';
    }
  }

  // Synchronous mount construction is complete: mark mounted (this hides the
  // fallback chrome). Set LAST so any throw above leaves the fallback visible.
  rootEl.dataset.cinatraMounted = 'true';

  // Required-login (cinatra#410): render in login mode first (conversation
  // surfaces hidden). On a fresh mount there is never a valid per-user token, so
  // the login window is shown until the user signs in. Placed AFTER the marker
  // so a throw still leaves the fallback chrome rather than a half-built widget.
  applyPanelMode();

  // Reopen widget after an auto-reload triggered by a content edit.
  try {
    if (window.sessionStorage.getItem('cinatra-reopen') === '1') {
      window.sessionStorage.removeItem('cinatra-reopen');
      openWidget();
    }
  } catch (_) {}

  } // end mountWidget()

  // ---------------------------------------------------------------------------
  // Boot: capabilities is a HARD PREREQUISITE. Negotiate FIRST; mount ONLY on
  // success. On any failure we never attachShadow and never set
  // data-cinatra-mounted, so the always-visible fallback button remains as the
  // "instance unavailable / incompatible" chrome.
  // ---------------------------------------------------------------------------
  negotiate().then(function (ok) {
    if (ok) { mountWidget(); }
    else {
      console.warn('[cinatra] capability negotiation failed — instance unavailable or incompatible; widget not mounted');
    }
  }).catch(function () {
    console.warn('[cinatra] capability negotiation error — instance unavailable; widget not mounted');
  });

})();
