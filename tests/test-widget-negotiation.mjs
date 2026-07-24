// Standalone behavior tests for the vendored widget: capability negotiation +
// the S5 (cinatra#1221) parent↔iframe embed bridge.
//
// Runs under plain `node tests/test-widget-negotiation.mjs` — no jsdom, no
// bundler, no Drupal. Exit code 0 = all pass, 1 = a failure.
//
// This is the Drupal MIRROR of the WordPress source harness
// (cinatra-ai/wordpress-plugin/tests/test-widget-negotiation.mjs). It differs
// ONLY in the CMS seams: the drupalSettings.cinatra config accessor, the Drupal
// broker CSRF idiom (the `_csrf_token` `?token=` query rather than the WP REST
// nonce header), the node-native content context, and the in-place-refresh sink
// (a same-document `cinatra:content-applied` CustomEvent rather than the WP
// wp.data invalidateResolution). The §12 bridge assertions are identical.
//
// ARCHITECTURE (S5 cinatra#1221): the assistant conversation moved INTO a
// Cinatra-served `/embed/assistant` iframe. The widget no longer streams; it
// negotiates + logs in, then frames the embed page as the SOLE session owner and
// speaks the §12 parent-side bridge. So this harness covers, in one place:
//
//   UNCONDITIONAL MOUNT (S5 unified-broker cutover, cinatra#2029): the shell no
//   longer pre-flight-negotiates. The bespoke `GET /api/agents/{slug}/capabilities`
//   was deleted (cinatra#1991, no migration window) and the AG-UI capability
//   handshake moved CLIENT-SIDE into the /embed/assistant iframe (unified broker
//   `GET /api/assistants/chat/capabilities`). So the shell now:
//     - boot                        -> MOUNTS (login-gated), makes NO /capabilities fetch
//     - every network call rejects  -> STILL MOUNTS (no pre-flight gate to abort on)
//     - duplicate include           -> mounts once
//
//   REQUIRED-LOGIN GATE (cinatra#410): mounts in LOGIN mode; no iframe, no token,
//     no bootstrap until the hosted-PKCE handshake yields an opaque cwu_ token.
//
//   §12 BRIDGE (cinatra#1221): the iframe is sandboxed and framed at
//     /embed/assistant WITHOUT tokens in its URL; on READY the parent mints cit_
//     ONCE and posts a single BOOTSTRAP to the EXACT Cinatra origin (never "*")
//     carrying cit_/cwu_; origin + source-window binding rejects a spoofed READY;
//     single bootstrap per frame; resize is CLAMPED; apply_intent is permission-
//     checked, LRU-deduped, and routes through an IN-PLACE draft refresh (no
//     egress, no reload — #1214).
//
//   §12b PORT TRANSPORT (cinatra#1965/#1970): a READY that TRANSFERS a MessagePort
//     drives the token-bearing BOOTSTRAP over the RETAINED port (never a window
//     post) and services uplinks on it; a window-delivered uplink is IGNORED in
//     port mode; `requirePort` refuses a port-less (legacy-window) downgrade. The
//     legacy window path (a port-less READY) is kept for the negotiated transition
//     and is still exercised above.

import fs from "node:fs";
import path from "node:path";
import vm from "node:vm";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WIDGET_SRC = fs.readFileSync(
  path.join(__dirname, "..", "js", "cinatra-widget.js"),
  "utf8",
);

const INSTANCE_ORIGIN = "https://instance.example";
// Drupal same-origin broker routes (vs the WP `/wp-json/cinatra/v1/*` routes).
const TOKEN_ENDPOINT = "https://site.example/cinatra/token";
const AUTH_INIT = "https://site.example/cinatra/widget-auth/init";
const AUTH_TOKEN = "https://site.example/cinatra/widget-auth/token";
// Drupal per-route `_csrf_token` seeds (each route seeds its OWN token, so init
// and token carry DIFFERENT tokens; the cit_ mint carries a third).
const CSRF_INIT = "drupal-csrf-init";
const CSRF_TOKEN = "drupal-csrf-token";
const CSRF_MINT = "drupal-csrf-mint";
const ID_PATTERN = /^[A-Za-z0-9_-]{22,128}$/;

let failures = 0;
function check(label, cond) {
  if (cond) {
    console.log(`  PASS  ${label}`);
  } else {
    console.log(`  FAIL  ${label}`);
    failures++;
  }
}

// ---------------------------------------------------------------------------
// Minimal DOM/window shim. Records attachShadow, the data-cinatra-mounted marker,
// the login handshake surfaces, and — new for the bridge — the mounted iframe
// (sandbox attr + src + contentWindow), the frame window's postMessage sink
// (BOOTSTRAP capture), and the Drupal in-place-refresh sink (a document
// `cinatra:content-applied` CustomEvent — the apply refresh).
// ---------------------------------------------------------------------------
function makeEnv(fetchImpl, sharedRoot, captured) {
  let attachShadowCount = 0;
  const messageListeners = [];   // window 'message' listeners (auth popup + bridge)
  const openedPopups = [];

  function makeStubEl(isRoot, tag) {
    const el = {
      _tag: tag || "div",
      style: {},
      dataset: {},
      shadowRoot: null,
      classList: { add() {}, remove() {}, contains() { return false; } },
      attributes: {},
      children: [],
      parentNode: null,
      _clickHandlers: [],
      _loadHandlers: [],
      set placeholder(v) {
        this._placeholder = v;
        if (captured) { captured.textarea = this; }
      },
      get placeholder() { return this._placeholder; },
      set className(v) {
        this._className = v;
        if (captured) { captured.byClass[v] = this; }
      },
      get className() { return this._className; },
      set textContent(v) {
        this._textContent = v;
        if (captured && v === "Sign in with Cinatra") { captured.loginBtnEl = this; }
      },
      get textContent() { return this._textContent; },
      setAttribute(k, v) {
        this.attributes[k] = v;
        if (captured && this._tag === "iframe" && k === "src") { captured.iframeSrc = v; }
      },
      getAttribute(k) { return this.attributes[k]; },
      appendChild(c) { c.parentNode = this; this.children.push(c); return c; },
      removeChild(c) {
        const i = this.children.indexOf(c);
        if (i !== -1) this.children.splice(i, 1);
        c.parentNode = null;
        return c;
      },
      addEventListener(type, handler) {
        if (type === "click") {
          this._clickHandlers.push(handler);
          if (captured) { captured.clickHandlers.push(handler); }
        } else if (type === "load") {
          this._loadHandlers.push(handler);
        }
      },
      removeEventListener() {},
      querySelector() { return null; },
      attachShadow() {
        attachShadowCount++;
        const sh = makeStubEl(false);
        if (isRoot) { this.shadowRoot = sh; }
        return sh;
      },
      focus() {},
      getBoundingClientRect() { return { left: 0, top: 0, width: 0, height: 0 }; },
    };
    if ((tag || "div") === "iframe") {
      // The frame window: the bridge captures it as `frameWindow` and posts the
      // BOOTSTRAP to it (addressed to the Cinatra origin). Record every post.
      el.contentWindow = {
        postMessage(msg, targetOrigin) {
          if (captured) { captured.bootstrapPosts.push({ msg, targetOrigin }); }
        },
      };
      if (captured) { captured.iframeEl = el; }
    }
    return el;
  }

  const rootEl = sharedRoot || makeStubEl(true);

  const documentStub = {
    getElementById(id) { return id === "cinatra-root" ? rootEl : null; },
    createElement(tag) { return makeStubEl(false, tag); },
    createElementNS() { return makeStubEl(false, "svg"); },
    querySelector() { return null; },
    addEventListener() {},
    // The Drupal in-place-refresh sink: refreshCurrentDraft() dispatches a
    // `cinatra:content-applied` CustomEvent here (no reload, no egress). Record it.
    dispatchEvent(ev) { if (captured) { captured.applied.push(ev); } return true; },
    head: makeStubEl(),
    body: makeStubEl(),
    readyState: "complete",
  };

  const sandbox = {
    window: {
      drupalSettings: {
        cinatra: {
          cinatraUrl: INSTANCE_ORIGIN,
          tokenEndpoint: TOKEN_ENDPOINT,
          authInitEndpoint: AUTH_INIT,
          authTokenEndpoint: AUTH_TOKEN,
          // Drupal per-route CSRF seeds: init and token carry DIFFERENT tokens;
          // the cit_ mint carries its own.
          authInitCsrfToken: CSRF_INIT,
          authTokenCsrfToken: CSRF_TOKEN,
          csrfToken: CSRF_MINT,
          instanceId: "i1",
          // Node-native canonical resource (server-provided; Drupal has no client
          // editor store). buildContentContext() reads these.
          nodeId: 5,
          nodeBundle: "article",
          nodeStatus: "draft",
        },
      },
      innerWidth: 1280,
      innerHeight: 800,
      location: { href: "https://site.example/node/1", reload() {} },
      addEventListener(type, handler) { if (type === "message") { messageListeners.push(handler); } },
      removeEventListener(type, handler) {
        if (type === "message") {
          const i = messageListeners.indexOf(handler);
          if (i !== -1) messageListeners.splice(i, 1);
        }
      },
      open(url) { const popup = { url, closed: false, close() { this.closed = true; } }; openedPopups.push(popup); return popup; },
      crypto: {
        getRandomValues(arr) { for (let i = 0; i < arr.length; i++) { arr[i] = (i * 7 + 3) & 0xff; } return arr; },
        subtle: { async digest() { return new ArrayBuffer(32); } },
      },
    },
    document: documentStub,
    console,
    fetch: fetchImpl,
    setTimeout: (fn) => { return 0; },
    clearTimeout: () => {},
    setInterval: () => { return 0; },
    clearInterval: () => {},
    btoa: (s) => Buffer.from(s, "binary").toString("base64"),
    TextEncoder,
    AbortController: class {
      constructor() { this.signal = {}; }
      abort() {}
    },
    CustomEvent: class {
      constructor(type, init) { this.type = type; this.detail = init && init.detail; }
    },
    Object, Array, JSON, Promise, Date, Math, String, Number, Uint8Array, isFinite,
    URL,
    TextDecoder: class { decode() { return ""; } },
  };
  sandbox.crypto = sandbox.window.crypto;
  sandbox.window.document = documentStub;
  sandbox.globalThis = sandbox;
  return {
    sandbox, rootEl,
    attachShadowCount: () => attachShadowCount,
    messageListeners, openedPopups,
  };
}

function jsonResponse(status, body) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
    text: () => Promise.resolve(JSON.stringify(body)),
    headers: { get() { return null; } },
  });
}

async function flush(n) { for (let i = 0; i < (n || 20); i++) { await Promise.resolve(); } }

// Boot the IIFE and settle the microtask queue. The shell mounts synchronously
// (login-gated) with no pre-flight fetch; `fetchImpl` is a spy so a test can
// assert the shell issued NO capabilities request at boot.
async function boot(fetchImpl, sharedRoot) {
  const captured = newCaptured();
  const env = makeEnv(fetchImpl, sharedRoot, captured);
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  await flush();
  return {
    env, captured,
    mounted: env.rootEl.dataset.cinatraMounted === "true",
    attachShadow: env.attachShadowCount() > 0,
    attachShadowCount: env.attachShadowCount(),
  };
}

function newCaptured() {
  return { clickHandlers: [], textarea: null, loginBtnEl: null, byClass: {}, iframeEl: null, iframeSrc: null, bootstrapPosts: [], applied: [] };
}

// A synchronous MessagePort double for the §12b port transport: captures posts
// (the BOOTSTRAP; no targetOrigin), a message listener list, start/close state,
// and `deliver(data)` which fires the listener synchronously (an iframe->parent
// uplink over the entangled port). Mirrors the core client test's makePort().
function makePort() {
  const posts = [];
  const listeners = [];
  return {
    posts,
    started: false,
    closed: false,
    postMessage(msg) { posts.push(msg); },
    addEventListener(type, l) { if (type === "message") listeners.push(l); },
    removeEventListener(type, l) { const i = listeners.indexOf(l); if (i !== -1) listeners.splice(i, 1); },
    start() { this.started = true; },
    close() { this.closed = true; },
    deliver(data) { for (const l of listeners.slice()) { try { l({ data }); } catch (_) {} } },
  };
}

// Drive a fresh widget from boot through the hosted-PKCE login to a mounted
// sandboxed /embed/assistant iframe, returning the handles the §12/§12b bridge
// tests need. `cfgExtra` merges into drupalSettings.cinatra (e.g. { requirePort:
// true }) BEFORE the widget evaluates, so config toggles take effect.
async function driveToPostLogin(cfgExtra) {
  let initState = null;
  const fetched = [];
  const fetchImpl = (url, opts) => {
    const u = String(url);
    const base = u.split("?")[0];
    const body = (opts && opts.body) ? JSON.parse(opts.body) : null;
    fetched.push({ url: u, base, method: (opts && opts.method) || "GET", headers: (opts && opts.headers) || {}, body });
    if (base === AUTH_INIT) {
      initState = body && body.state;
      return jsonResponse(200, { txnId: "txn1", authorizeUrl: INSTANCE_ORIGIN + "/widget-auth?txn=txn1", instanceId: "i1" });
    }
    if (base === AUTH_TOKEN) { return jsonResponse(200, { token: "cwu_user-tok", tokenType: "Bearer", expiresIn: 900 }); }
    if (base === TOKEN_ENDPOINT) { return jsonResponse(200, { token: "cit_site-tok", expiresIn: 300 }); }
    return jsonResponse(200, {});
  };
  const captured = newCaptured();
  const env = makeEnv(fetchImpl, undefined, captured);
  if (cfgExtra) { Object.assign(env.sandbox.window.drupalSettings.cinatra, cfgExtra); }
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  await flush();
  if (captured.loginBtnEl && captured.loginBtnEl._clickHandlers.length) {
    for (const h of captured.loginBtnEl._clickHandlers) { try { h({}); } catch (_) {} }
    await flush();
    const popup = env.openedPopups[env.openedPopups.length - 1];
    for (const listener of env.messageListeners) {
      try { listener({ origin: INSTANCE_ORIGIN, source: popup, data: { type: "cinatra-widget-auth", code: "auth-code-1", state: initState } }); } catch (_) {}
    }
    await flush(30);
  }
  const iframe = captured.iframeEl;
  const frameWin = iframe && iframe.contentWindow;
  const deliverToBridge = (ev) => { for (const l of env.messageListeners) { try { l(ev); } catch (_) {} } };
  return { env, captured, fetched, iframe, frameWin, deliverToBridge };
}

async function main() {
  console.log("widget unconditional mount + §12 embed bridge");

  // -------------------------------------------------------------------------
  // UNCONDITIONAL MOUNT (S5 unified-broker cutover, cinatra#2029). The shell no
  // longer pre-flight-negotiates against the deleted /api/agents/{slug}/
  // capabilities route (cinatra#1991); the AG-UI capability handshake runs
  // CLIENT-SIDE inside the /embed/assistant iframe against the unified broker. So
  // the shell mounts unconditionally (login-gated) and makes NO capabilities
  // request at boot.
  // -------------------------------------------------------------------------
  {
    const fetches = [];
    const r = await boot((url) => { fetches.push(String(url)); return jsonResponse(200, {}); });
    check("boot -> MOUNTS unconditionally (attachShadow), login-gated", r.mounted && r.attachShadow);
    check(
      "boot -> NO capability pre-flight (no /capabilities, no /api/agents/… fetch at boot)",
      !fetches.some((u) => u.indexOf("/capabilities") !== -1 || u.indexOf("/api/agents/") !== -1),
    );
  }
  {
    // No pre-flight gate: even if every network call rejects, the shell STILL
    // mounts (the retired negotiation would have aborted the mount here).
    const r = await boot(() => Promise.reject(new Error("network down")));
    check("every network call rejects -> STILL MOUNTS (no pre-flight gate to abort on)", r.mounted && r.attachShadow);
  }
  {
    const first = await boot(() => jsonResponse(200, {}));
    const second = await boot(() => jsonResponse(200, {}), first.env.rootEl);
    check(
      "duplicate include -> mounts exactly once (attachShadow called once total)",
      first.mounted && first.env.rootEl.dataset.cinatraMounted === "true" &&
        (first.attachShadowCount + second.attachShadowCount) === 1,
    );
  }

  // -------------------------------------------------------------------------
  // LOGIN GATE + §12 BRIDGE — one end-to-end drive.
  // -------------------------------------------------------------------------
  {
    let initState = null;
    const fetched = [];
    const fetchImpl = (url, opts) => {
      const u = String(url);
      const base = u.split("?")[0];   // Drupal appends the CSRF token as ?token=
      const body = (opts && opts.body) ? JSON.parse(opts.body) : null;
      fetched.push({ url: u, base, method: (opts && opts.method) || "GET", headers: (opts && opts.headers) || {}, body });
        if (base === AUTH_INIT) {
        initState = body && body.state;
        return jsonResponse(200, { txnId: "txn1", authorizeUrl: INSTANCE_ORIGIN + "/widget-auth?txn=txn1", instanceId: "i1" });
      }
      if (base === AUTH_TOKEN) { return jsonResponse(200, { token: "cwu_user-tok", tokenType: "Bearer", expiresIn: 900 }); }
      if (base === TOKEN_ENDPOINT) { return jsonResponse(200, { token: "cit_site-tok", expiresIn: 300 }); }
      return jsonResponse(200, {});
    };

    const captured = newCaptured();
    const env = makeEnv(fetchImpl, undefined, captured);
    vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
    await flush();
    const mounted = env.rootEl.dataset.cinatraMounted === "true";

    // (1) Pre-login: the iframe is NOT mounted and no cit_ token is minted (the
    //     login gate holds — the conversation surface never appears token-less).
    const preLoginNoFrame = mounted && !captured.iframeEl;
    const preLoginNoToken = !fetched.some((f) => f.base === TOKEN_ENDPOINT);
    check("login gate: pre-login has NO iframe and NO cit_ mint (token-less conversation blocked)", preLoginNoFrame && preLoginNoToken);

    // (2) Drive the hosted-PKCE login: click sign-in, deliver the popup callback.
    let loggedIn = false;
    if (mounted && captured.loginBtnEl && captured.loginBtnEl._clickHandlers.length) {
      for (const h of captured.loginBtnEl._clickHandlers) { try { h({}); } catch (_) {} }
      await flush();
      const popup = env.openedPopups[env.openedPopups.length - 1];
      for (const listener of env.messageListeners) {
        try { listener({ origin: INSTANCE_ORIGIN, source: popup, data: { type: "cinatra-widget-auth", code: "auth-code-1", state: initState } }); } catch (_) {}
      }
      await flush(30);
      loggedIn = true;
    }
    const initPost = fetched.find((f) => f.base === AUTH_INIT && f.method === "POST");
    const tokenPost = fetched.find((f) => f.base === AUTH_TOKEN && f.method === "POST");
    // Drupal CSRF idiom: the route-seeded token rides the ?token= QUERY (each
    // route its OWN token), NOT a header — so no CSRF header is present.
    const initCsrfOk = !!initPost && initPost.url.indexOf("token=" + CSRF_INIT) !== -1 && !initPost.headers["X-WP-Nonce"];
    const tokenCsrfOk = !!tokenPost && tokenPost.url.indexOf("token=" + CSRF_TOKEN) !== -1 && !tokenPost.headers["X-WP-Nonce"];
    const verifierSent = !!tokenPost && tokenPost.body && typeof tokenPost.body.codeVerifier === "string" && tokenPost.body.codeVerifier.length > 0;
    check(
      "login handshake: init+token relayed to OUR broker (same-origin) with the per-route ?token= CSRF + PKCE verifier",
      loggedIn && initCsrfOk && tokenCsrfOk && verifierSent,
    );

    // (3) Post-login: the sandboxed iframe is mounted at /embed/assistant, with
    //     the disambiguators but WITHOUT any token in the URL.
    const iframe = captured.iframeEl;
    const src = captured.iframeSrc || "";
    const sandboxAttr = iframe ? iframe.getAttribute("sandbox") : "";
    const sandboxOk = sandboxAttr === "allow-scripts allow-same-origin";
    const srcOk = src.indexOf(INSTANCE_ORIGIN + "/embed/assistant") === 0 &&
      src.indexOf("instanceId=i1") !== -1 && src.indexOf("assistant=drupal") !== -1;
    const noTokenInUrl = src.indexOf("cit_") === -1 && src.indexOf("cwu_") === -1 && src.toLowerCase().indexOf("token") === -1;
    check("post-login: sandboxed iframe framed at /embed/assistant (disambiguators only, NO token in URL)", !!iframe && sandboxOk && srcOk && noTokenInUrl);

    // (4) READY from a SPOOFED origin / SPOOFED source-window is IGNORED (no
    //     bootstrap, no cit_ mint). Origin + source-window binding.
    const frameWin = iframe && iframe.contentWindow;
    const readyMsg = { type: "cinatra.embed.ready", protocolVersion: 1, nonce: "nonce0123456789abcdef012", seq: 0 };
    function deliverToBridge(ev) { for (const l of env.messageListeners) { try { l(ev); } catch (_) {} } }
    deliverToBridge({ origin: "https://evil.example", source: frameWin, data: readyMsg });
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: { not: "the frame" }, data: readyMsg });
    await flush();
    // cit_ is pre-minted at enterConversation (before the frame mounts), so the
    // security property here is: NO BOOTSTRAP is posted for a spoofed READY.
    check("bridge: READY from wrong origin OR wrong source-window is IGNORED (no bootstrap posted)", captured.bootstrapPosts.length === 0);

    // (5) A well-formed READY from the real frame -> ONE bootstrap posted to the
    //     EXACT Cinatra origin (never "*"), echoing the nonce, seq 0, carrying
    //     cit_/cwu_; cit_ minted exactly once. The cms carries the node-native
    //     canonical resource (resourceId=nodeId, resourceType=nodeBundle).
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg });
    await flush(30);
    const posts = captured.bootstrapPosts;
    const post = posts[0];
    const bmsg = post && post.msg;
    const bootstrapOk = posts.length === 1 && !!bmsg &&
      bmsg.type === "cinatra.embed.bootstrap" &&
      bmsg.protocolVersion === 1 &&
      post.targetOrigin === INSTANCE_ORIGIN && post.targetOrigin !== "*" &&
      ID_PATTERN.test(bmsg.correlationId) &&
      bmsg.nonceEcho === readyMsg.nonce &&
      bmsg.seq === 0 &&
      bmsg.auth && bmsg.auth.citToken === "cit_site-tok" && bmsg.auth.cwuToken === "cwu_user-tok" &&
      bmsg.session && bmsg.session.assistant === "drupal" && ID_PATTERN.test(bmsg.session.threadId) &&
      bmsg.cms && bmsg.cms.instanceId === "i1" && bmsg.cms.resourceId === "5" && bmsg.cms.resourceType === "article" && bmsg.cms.status === "draft";
    const cit_mints = fetched.filter((f) => f.base === TOKEN_ENDPOINT).length;
    check("bridge: READY -> ONE BOOTSTRAP to the exact origin (nonce echo, seq 0, cit_+cwu_, node cms), cit_ minted once", bootstrapOk && cit_mints === 1);

    const correlationId = bmsg && bmsg.correlationId;

    // (6) Single bootstrap per frame: a SECOND READY does not re-bootstrap.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: "secondNonce0123456789abc", seq: 0 } });
    await flush();
    check("bridge: single bootstrap per frame (a second READY is ignored)", captured.bootstrapPosts.length === 1);

    // (7) resize: an in-range height ABOVE the panel cap is CLAMPED (not trusted);
    //     a height OVER the schema max is REJECTED. maxPanelHeight() ==
    //     innerHeight(800) - 120 == 680; RESIZE_MAX_HEIGHT == 20000.
    const cwWidget = captured.byClass["cw-widget"];
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 1, correlationId, seq: 1, height: 5000 } });
    await flush();
    const clampedH = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: in-range resize height above the cap is CLAMPED (<= 680px)", typeof clampedH === "number" && clampedH > 0 && clampedH <= 680);
    // Over the schema max -> rejected: the height is unchanged from the clamp above.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 1, correlationId, seq: 2, height: 999999 } });
    await flush();
    const afterOverMax = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: over-schema-max resize height (>20000) is REJECTED (height unchanged)", afterOverMax === clampedH);

    // (8) apply_intent (untrusted selector) -> ONE in-place draft refresh via the
    //     Drupal CustomEvent sink; a DUPLICATE id is deduped (LRU); a WRONG
    //     correlationId is ignored; and NO content-egress fetch is made.
    const fetchCountBeforeApply = fetched.length;
    const applyMsg = (seq, id) => ({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 1, correlationId, seq, viewType: "content_change_proposal", proposalId: id } });
    deliverToBridge(applyMsg(3, "prop-A"));
    await flush();
    deliverToBridge(applyMsg(4, "prop-A"));      // duplicate id -> LRU dedup
    await flush();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 1, correlationId: "WRONGcorrelationId012345", seq: 5, viewType: "content_change_proposal", proposalId: "prop-B" } });
    await flush();
    const oneRefresh = captured.applied.length === 1 &&
      captured.applied[0].type === "cinatra:content-applied" &&
      captured.applied[0].detail && captured.applied[0].detail.resourceId === "5";
    const noApplyEgress = fetched.length === fetchCountBeforeApply; // no fetch at all on apply
    check("bridge: apply_intent -> ONE in-place draft refresh (dup id + wrong correlationId ignored)", oneRefresh);
    check("bridge: apply_intent does NOT egress (no fetch on apply — #1214)", noApplyEgress);

    // (9) a DIFFERENT proposal id refreshes again (proves it was dedup, not a
    //     one-shot latch).
    deliverToBridge(applyMsg(6, "prop-C"));
    await flush();
    check("bridge: a new proposal id refreshes again (dedup, not a one-shot latch)", captured.applied.length === 2);

    // (10) presence-XOR: an apply carrying BOTH selector keys (one empty) is
    //      REJECTED (matches the core presence-XOR schema), so no refresh fires.
    const beforeBoth = captured.applied.length;
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 1, correlationId, seq: 7, viewType: "content_change_proposal", proposalId: "", changeSetId: "cs-1" } });
    await flush();
    check("bridge: apply carrying BOTH selector keys is rejected (presence-XOR, no refresh)", captured.applied.length === beforeBoth);
  }

  // -------------------------------------------------------------------------
  // §12b DOCUMENT-BOUND MESSAGEPORT TRANSPORT (cinatra#1965/#1970). The primary,
  // hardened path: on a READY that TRANSFERS a MessagePort, the parent sends the
  // token-bearing BOOTSTRAP over the RETAINED port (NEVER a window postMessage)
  // and services uplinks on it; a window-delivered uplink is IGNORED in port mode.
  // This is the whole point of #1965 — a same-origin REPLACEMENT of the frame is a
  // fresh realm that never inherited the port, so it can never receive the tokens.
  // -------------------------------------------------------------------------
  {
    const P_NONCE_1 = "portNonce" + "1".repeat(16); // 25 chars, satisfies ID_PATTERN
    const P_NONCE_2 = "portNonce" + "2".repeat(16);
    const { captured, deliverToBridge, frameWin } = await driveToPostLogin();
    const port = makePort();
    const readyMsg = { type: "cinatra.embed.ready", protocolVersion: 1, nonce: P_NONCE_1, seq: 0 };

    // (P1) READY TRANSFERS a port -> the BOOTSTRAP rides the PORT, never the
    //      window. No window bootstrap post; exactly one port post; nonce echo,
    //      seq 0, cit_/cwu_, node cms; the port is started (listening for uplinks).
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg, ports: [port] });
    await flush(30);
    const bmsg = port.posts[0];
    const portBootstrapOk = captured.bootstrapPosts.length === 0 && port.posts.length === 1 && !!bmsg &&
      bmsg.type === "cinatra.embed.bootstrap" &&
      bmsg.protocolVersion === 1 &&
      ID_PATTERN.test(bmsg.correlationId) &&
      bmsg.nonceEcho === P_NONCE_1 &&
      bmsg.seq === 0 &&
      bmsg.auth && bmsg.auth.citToken === "cit_site-tok" && bmsg.auth.cwuToken === "cwu_user-tok" &&
      bmsg.session && bmsg.session.assistant === "drupal" && ID_PATTERN.test(bmsg.session.threadId) &&
      bmsg.cms && bmsg.cms.instanceId === "i1" && bmsg.cms.resourceId === "5" && bmsg.cms.resourceType === "article";
    check("port: READY-with-port -> BOOTSTRAP rides the PORT (no window post), nonce echo/seq0/cit_+cwu_/node cms", portBootstrapOk && port.started === true);

    const correlationId = bmsg && bmsg.correlationId;

    // (P2) single bootstrap per frame: a SECOND READY (fresh port) does not
    //      re-bootstrap on either transport.
    const port2 = makePort();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: P_NONCE_2, seq: 0 }, ports: [port2] });
    await flush();
    check("port: single bootstrap per frame (a second READY-with-port is ignored)", port.posts.length === 1 && port2.posts.length === 0 && captured.bootstrapPosts.length === 0);

    // (P3) uplinks ride the PORT: a resize over the port is CLAMPED; a WINDOW-
    //      delivered uplink in port mode is IGNORED (uplinks travel the port only).
    const cwWidget = captured.byClass["cw-widget"];
    port.deliver({ type: "cinatra.embed.resize", protocolVersion: 1, correlationId, seq: 1, height: 5000 });
    await flush();
    const clampedH = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("port: resize uplink over the PORT is CLAMPED (<= 680px)", typeof clampedH === "number" && clampedH > 0 && clampedH <= 680);
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 1, correlationId, seq: 2, height: 100 } });
    await flush();
    const afterWindowUplink = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("port: a WINDOW-delivered uplink is IGNORED in port mode (uplinks ride the port only)", afterWindowUplink === clampedH);

    // (P4) apply_intent over the PORT -> ONE in-place draft refresh (the Drupal
    //      CustomEvent sink), targeting the parent's OWN canonical resource.
    port.deliver({ type: "cinatra.embed.apply_intent", protocolVersion: 1, correlationId, seq: 3, viewType: "content_change_proposal", proposalId: "port-prop-A" });
    await flush();
    const appliedOk = captured.applied.length === 1 &&
      captured.applied[0].type === "cinatra:content-applied" &&
      captured.applied[0].detail && captured.applied[0].detail.resourceId === "5";
    check("port: apply_intent over the PORT -> ONE in-place draft refresh", appliedOk);
  }

  // (P5) DOWNGRADE REFUSAL: under requirePort, a port-LESS READY sends NOTHING —
  //      the legacy window transport cannot be forced by stripping the port; a
  //      subsequent READY WITH a port still bootstraps over the port.
  {
    const NP_NONCE = "noPortNonce" + "0".repeat(14); // 25 chars
    const RP_NONCE = "reqPortNonce" + "0".repeat(14);
    const { captured, deliverToBridge, frameWin } = await driveToPostLogin({ requirePort: true });
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: NP_NONCE, seq: 0 } });
    await flush(30);
    check("port: requirePort REFUSES a port-less READY (no window bootstrap; downgrade blocked)", captured.bootstrapPosts.length === 0);
    const port = makePort();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: RP_NONCE, seq: 0 }, ports: [port] });
    await flush(30);
    check("port: under requirePort a READY-with-port still bootstraps over the PORT", port.posts.length === 1 && captured.bootstrapPosts.length === 0);
  }

  // -------------------------------------------------------------------------
  // SYNCHRONOUS BOOTSTRAP RELEASE (source-level, defense-in-depth). A frame's
  // WindowProxy identity is STABLE across navigations, so no post-await window
  // check can be fully airtight. The design instead removes the async gap: the
  // cit_ token is PRE-MINTED before the frame mounts, and the READY->BOOTSTRAP
  // release reads it from the synchronous cache and posts in the SAME message
  // task (no await) — a same-origin navigation cannot interleave within one
  // synchronous task. This lives in closure-private state (unreachable from the
  // sandbox), so — as the harness already does for the login/mint ordering — we
  // pin the security-relevant STRUCTURE against the widget source.
  {
    const ec = WIDGET_SRC.indexOf("function enterConversation");
    const ecRegion = ec === -1 ? "" : WIDGET_SRC.slice(ec, ec + 900);
    const preMintsBeforeMount =
      /getStreamToken\s*\(\)/.test(ecRegion) &&
      /mountBridgeIframe\s*\(/.test(ecRegion) &&
      ecRegion.indexOf("getStreamToken") < ecRegion.indexOf("mountBridgeIframe");
    const syncRelease =
      /function\s+getCachedCitToken/.test(WIDGET_SRC) &&
      /bootstrapped\s*=\s*true;\s*\n?\s*sendBootstrap\s*\(\s*buildBootstrap/.test(WIDGET_SRC);
    check("frame-safety: cit_ pre-minted before mount AND READY->BOOTSTRAP released synchronously from cache (no async gap)", preMintsBeforeMount && syncRelease);
  }

  console.log(failures === 0 ? "\nALL PASS" : `\n${failures} FAILURE(S)`);
  process.exit(failures === 0 ? 0 : 1);
}

main();
