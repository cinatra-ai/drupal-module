// Standalone behavior tests for the vendored widget: the mount, and the S5
// (cinatra#1221) parent↔iframe embed bridge at PROTOCOL 2 (cinatra#2674).
//
// Runs under plain `node tests/test-widget-negotiation.mjs` — no jsdom, no
// bundler, no Drupal. Exit code 0 = all pass, 1 = a failure.
//
// This is the Drupal MIRROR of the WordPress source harness
// (cinatra-ai/wordpress-plugin/tests/test-widget-negotiation.mjs). It differs
// ONLY in the CMS seams: the drupalSettings.cinatra config accessor, the
// node-native content context, and the in-place-refresh sink (a same-document
// `cinatra:content-applied` CustomEvent rather than the WP wp.data
// invalidateResolution). The §12 bridge assertions are identical.
//
// WHAT PROTOCOL 2 CHANGED, AND THEREFORE WHAT THIS HARNESS NOW ASSERTS.
// Protocol 1's inbound envelope was a BOOTSTRAP and it carried the credential
// pair: this shell ran the hosted PKCE handshake through same-origin PHP relays,
// minted a `cit_` site token through a third, and posted both into the frame. So
// the old harness asserted DELIVERY — that the tokens were minted once and
// arrived in the bootstrap.
//
// Every one of those assertions is now inverted into an ABSENCE assertion. The
// frame acquires its own credential on the Cinatra origin; this shell sends ONE
// selector-only CONTEXT message and never obtains, holds, composes or forwards a
// bearer. Concretely, in one place:
//
//   MOUNT: the shell mounts unconditionally and makes NO network request AT ALL
//     — not a capability pre-flight, not a token mint, not an auth relay. There
//     is no login window and no login gate in front of the frame.
//
//   RETIRED ENDPOINTS: nothing is called, so the instance's 410 Gone answers on
//     `/api/widget-auth/{init,token}` are never even reached. A fetch double that
//     answers 410 to EVERYTHING changes no behaviour: the widget still mounts and
//     still sends its context message.
//
//   §12 BRIDGE: the iframe is sandboxed (with the popup grant the frame-owned
//     sign-in requires) and framed at /embed/assistant with disambiguators only;
//     on READY the parent posts ONE `cinatra.embed.context` at protocolVersion 2
//     to the EXACT Cinatra origin (never "*"), with NO `auth` field and NO
//     credential-shaped value anywhere in it; a protocol-1 READY is refused;
//     origin + source-window binding rejects a spoofed READY; one context per
//     frame; resize is CLAMPED; apply_intent is permission-checked, LRU-deduped,
//     and routes through an IN-PLACE draft refresh (no egress, no reload —
//     #1214).
//
//   OUTBOUND CREDENTIAL REFUSAL: if the CMS context itself carries a
//     credential-shaped value (a node bundle that is really a `cwu_…`), the
//     message is NOT SENT AT ALL on either transport.
//
//   §12b PORT TRANSPORT (cinatra#1965/#1970): a READY that TRANSFERS a
//     MessagePort drives the CONTEXT message over the RETAINED port and services
//     uplinks on it; a window-delivered uplink is IGNORED in port mode;
//     `requirePort` refuses a port-less window downgrade.

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
const ID_PATTERN = /^[A-Za-z0-9_-]{22,128}$/;
// The bearer prefixes the platform mints. Nothing carrying one of these may
// appear in an outbound payload, at any depth, in a key or a value.
const CREDENTIAL_TOKEN_RE = /(?:^|[^A-Za-z0-9])(?:cwu|cit|cnx)_/i;

let failures = 0;
function check(label, cond) {
  if (cond) {
    console.log(`  PASS  ${label}`);
  } else {
    console.log(`  FAIL  ${label}`);
    failures++;
  }
}

// Recursive credential-shape scan of an outbound payload — keys as well as
// values, arrays and nested objects alike. This is the harness's OWN
// implementation, deliberately not imported from the widget: a test that reused
// the widget's guard would pass even if that guard were broken.
// Mirrors the SENDER's semantics deliberately: an unknown answer is "yes". A
// scan that returned false at its depth bound would let an assertion that says
// "no credential anywhere, at any depth" pass while being false, which is the
// one thing an absence assertion must never do.
function carriesCredentialShape(value, depth = 0) {
  if (typeof value === "string") return CREDENTIAL_TOKEN_RE.test(value);
  if (value === null || typeof value !== "object") return false;
  if (depth >= 8) return true;
  if (Array.isArray(value)) return value.some((v) => carriesCredentialShape(v, depth + 1));
  if (Object.prototype.toString.call(value) !== "[object Object]") return true;
  for (const key of Object.keys(value)) {
    if (CREDENTIAL_TOKEN_RE.test(key)) return true;
    if (carriesCredentialShape(value[key], depth + 1)) return true;
  }
  return false;
}

// ---------------------------------------------------------------------------
// Minimal DOM/window shim. Records attachShadow, the data-cinatra-mounted marker,
// the mounted iframe (sandbox attr + src + contentWindow), the frame window's
// postMessage sink (CONTEXT capture), and the Drupal in-place-refresh sink (a
// document `cinatra:content-applied` CustomEvent — the apply refresh).
// ---------------------------------------------------------------------------
function makeEnv(fetchImpl, sharedRoot, captured) {
  let attachShadowCount = 0;
  let randCalls = 0;
  const messageListeners = [];   // window 'message' listeners (the bridge)
  const openedPopups = [];       // must stay EMPTY: this shell opens no window

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
      set textContent(v) { this._textContent = v; },
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
      // CONTEXT message to it (addressed to the Cinatra origin). Record every post.
      el.contentWindow = {
        postMessage(msg, targetOrigin) {
          if (captured) { captured.windowPosts.push({ msg, targetOrigin }); }
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
      location: { href: "https://site.example/node/1", origin: "https://site.example", reload() {} },
      addEventListener(type, handler) { if (type === "message") { messageListeners.push(handler); } },
      removeEventListener(type, handler) {
        if (type === "message") {
          const i = messageListeners.indexOf(handler);
          if (i !== -1) messageListeners.splice(i, 1);
        }
      },
      open(url) { const popup = { url, closed: false, close() { this.closed = true; } }; openedPopups.push(popup); return popup; },
      crypto: {
        // Deterministic but VARYING across calls: a fixed byte pattern would
        // make two successive correlationId mints identical, and a test that
        // asserts a fresh id could then never fail.
        getRandomValues(arr) {
          for (let i = 0; i < arr.length; i++) { arr[i] = (i * 7 + 3 + randCalls * 31) & 0xff; }
          randCalls++;
          return arr;
        },
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
    URL, RegExp,
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

// Boot the IIFE and settle the microtask queue. `fetchImpl` is a spy so a test
// can assert the shell issued NO request of any kind.
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
  return { clickHandlers: [], textarea: null, byClass: {}, iframeEl: null, iframeSrc: null, windowPosts: [], applied: [] };
}

// A synchronous MessagePort double for the §12b port transport: captures posts
// (the CONTEXT message; no targetOrigin), a message listener list, start/close
// state, and `deliver(data)` which fires the listener synchronously (an
// iframe->parent uplink over the entangled port). Mirrors the core client test's
// makePort().
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

// Drive a fresh widget from boot to a mounted sandboxed /embed/assistant iframe.
// There is no login step to drive any more: the frame is mounted on the first
// panel open, which is the launcher click. `cfgExtra` merges into
// drupalSettings.cinatra (e.g. { requirePort: true }) BEFORE the widget
// evaluates, so config toggles take effect.
async function driveToMountedFrame(cfgExtra) {
  const fetched = [];
  const fetchImpl = (url, opts) => {
    fetched.push({ url: String(url), method: (opts && opts.method) || "GET" });
    return jsonResponse(200, {});
  };
  const captured = newCaptured();
  const env = makeEnv(fetchImpl, undefined, captured);
  if (cfgExtra) { Object.assign(env.sandbox.window.drupalSettings.cinatra, cfgExtra); }
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  await flush();
  // Open the panel (the launcher circle click) -> the frame mounts.
  for (const h of captured.clickHandlers) { try { h({}); } catch (_) {} }
  await flush(30);
  const iframe = captured.iframeEl;
  const frameWin = iframe && iframe.contentWindow;
  const deliverToBridge = (ev) => { for (const l of env.messageListeners) { try { l(ev); } catch (_) {} } };
  return { env, captured, fetched, iframe, frameWin, deliverToBridge };
}

const readyMsgV2 = (nonce, seq) => ({
  type: "cinatra.embed.ready",
  protocolVersion: 2,
  nonce,
  seq: seq || 0,
});

async function main() {
  console.log("widget mount + §12 embed bridge (protocol 2)");

  // -------------------------------------------------------------------------
  // MOUNT. The shell has no pre-flight and, since protocol 2, no login gate: it
  // mounts unconditionally and reaches the network for nothing at all.
  // -------------------------------------------------------------------------
  {
    const fetches = [];
    const r = await boot((url) => { fetches.push(String(url)); return jsonResponse(200, {}); });
    check("boot -> MOUNTS unconditionally (attachShadow)", r.mounted && r.attachShadow);
    check("boot -> makes NO network request of ANY kind (no pre-flight, no token mint, no auth relay)", fetches.length === 0);
    check("boot -> opens NO window (the sign-in popup belongs to the frame, not this page)", r.env.openedPopups.length === 0);
  }
  {
    // No pre-flight gate and no broker: even if every network call rejects, the
    // shell mounts. (Nothing calls fetch, so this is belt and braces.)
    const r = await boot(() => Promise.reject(new Error("network down")));
    check("every network call rejects -> STILL MOUNTS (nothing to abort on)", r.mounted && r.attachShadow);
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
  {
    // The retired endpoints answer 410 Gone. Nothing here calls them — so a
    // double that answers 410 to EVERYTHING changes nothing: the widget mounts,
    // the frame is framed, and the context message still goes out. "Quiet" is
    // literal: there is no request to fail and no failure to report.
    const fetched = [];
    const gone410 = (url) => {
      fetched.push(String(url));
      return jsonResponse(410, { error: "invalid_request", reason: "widget_auth_site_init_retired" });
    };
    const captured = newCaptured();
    const env = makeEnv(gone410, undefined, captured);
    vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
    await flush();
    for (const h of captured.clickHandlers) { try { h({}); } catch (_) {} }
    await flush(30);
    const frameWin = captured.iframeEl && captured.iframeEl.contentWindow;
    for (const l of env.messageListeners) {
      try { l({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2("goneNonce0123456789abcde") }); } catch (_) {}
    }
    await flush(30);
    check(
      "retired endpoints: a 410-to-everything instance changes nothing (no request made, frame framed, context sent)",
      fetched.length === 0 && !!captured.iframeEl && captured.windowPosts.length === 1,
    );
  }

  // -------------------------------------------------------------------------
  // §12 BRIDGE — one end-to-end drive over the WINDOW transport.
  // -------------------------------------------------------------------------
  {
    const fetched = [];
    const fetchImpl = (url, opts) => {
      fetched.push({ url: String(url), method: (opts && opts.method) || "GET" });
      return jsonResponse(200, {});
    };

    const captured = newCaptured();
    const env = makeEnv(fetchImpl, undefined, captured);
    vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
    await flush();
    const mounted = env.rootEl.dataset.cinatraMounted === "true";

    // (1) Before the panel is opened the frame is NOT mounted: an authenticated
    //     Cinatra surface is not framed on a page the editor merely looked at.
    check("lazy frame: no iframe until the panel is opened", mounted && !captured.iframeEl);

    // (2) Open the panel -> the sandboxed iframe is framed at /embed/assistant
    //     with the disambiguators and no credential in the URL.
    for (const h of captured.clickHandlers) { try { h({}); } catch (_) {} }
    await flush(30);
    const iframe = captured.iframeEl;
    const src = captured.iframeSrc || "";
    const sandboxAttr = iframe ? iframe.getAttribute("sandbox") : "";
    const sandboxTokens = String(sandboxAttr || "").split(/\s+/).filter(Boolean);
    const sandboxOk =
      sandboxTokens.includes("allow-scripts") &&
      sandboxTokens.includes("allow-same-origin") &&
      // The frame-owned sign-in is a TOP-LEVEL Cinatra window; without these it
      // cannot be opened, or cannot follow its own redirect once open.
      sandboxTokens.includes("allow-popups") &&
      sandboxTokens.includes("allow-popups-to-escape-sandbox") &&
      // …and nothing else: the frame still may not navigate or drive this page.
      !sandboxTokens.includes("allow-top-navigation") &&
      !sandboxTokens.includes("allow-forms") &&
      !sandboxTokens.includes("allow-modals") &&
      !sandboxTokens.includes("allow-downloads");
    const srcOk = src.indexOf(INSTANCE_ORIGIN + "/embed/assistant") === 0 &&
      src.indexOf("instanceId=i1") !== -1 && src.indexOf("assistant=drupal") !== -1;
    const noTokenInUrl = !CREDENTIAL_TOKEN_RE.test(src) && src.toLowerCase().indexOf("token") === -1;
    check("panel open: sandboxed iframe framed at /embed/assistant (disambiguators only, NO credential in URL)", !!iframe && srcOk && noTokenInUrl);
    check("panel open: sandbox grants the sign-in popup and nothing more (no top-nav/forms/modals/downloads)", sandboxOk);

    // (3) READY from a SPOOFED origin / SPOOFED source-window is IGNORED.
    const frameWin = iframe && iframe.contentWindow;
    const readyMsg = readyMsgV2("nonce0123456789abcdef012");
    function deliverToBridge(ev) { for (const l of env.messageListeners) { try { l(ev); } catch (_) {} } }
    deliverToBridge({ origin: "https://evil.example", source: frameWin, data: readyMsg });
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: { not: "the frame" }, data: readyMsg });
    await flush();
    check("bridge: READY from wrong origin OR wrong source-window is IGNORED (nothing posted)", captured.windowPosts.length === 0);

    // (4) A PROTOCOL-1 READY is REFUSED. The version literal advanced to 2
    //     precisely so the two halves cannot negotiate: an unmigrated frame gets
    //     no context message rather than a silent downgrade.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: "v1Nonce0123456789abcdef", seq: 0 } });
    await flush();
    check("bridge: a protocol-1 READY is REFUSED (no downgrade path)", captured.windowPosts.length === 0);

    // (5) A well-formed protocol-2 READY -> ONE CONTEXT message posted to the
    //     EXACT Cinatra origin (never "*"), echoing the nonce, seq 0, carrying
    //     the node-native cms selectors — and NO credential field.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg });
    await flush(30);
    const posts = captured.windowPosts;
    const post = posts[0];
    const cmsg = post && post.msg;
    const contextOk = posts.length === 1 && !!cmsg &&
      cmsg.type === "cinatra.embed.context" &&
      cmsg.protocolVersion === 2 &&
      post.targetOrigin === INSTANCE_ORIGIN && post.targetOrigin !== "*" &&
      ID_PATTERN.test(cmsg.correlationId) &&
      cmsg.nonceEcho === readyMsg.nonce &&
      cmsg.seq === 0 &&
      cmsg.session && cmsg.session.assistant === "drupal" && ID_PATTERN.test(cmsg.session.threadId) &&
      cmsg.cms && cmsg.cms.instanceId === "i1" && cmsg.cms.resourceId === "5" && cmsg.cms.resourceType === "article" && cmsg.cms.status === "draft";
    check("bridge: READY -> ONE CONTEXT to the exact origin (v2, nonce echo, seq 0, node cms selectors)", contextOk);

    // (6) THE ABSENCE ASSERTIONS. Protocol 1 asserted the bootstrap DELIVERED
    //     `auth.citToken` + `auth.cwuToken`; there is no such field now, no such
    //     value, and no mint that could have produced one.
    const noAuthField = !!cmsg && !("auth" in cmsg) && !("citToken" in cmsg) && !("cwuToken" in cmsg);
    check("bridge: the CONTEXT message has NO auth field (the credential slot is gone, not emptied)", noAuthField);
    check("bridge: NO credential-shaped value anywhere in the outbound payload (recursive, keys and values)", !!cmsg && !carriesCredentialShape(cmsg));
    check("bridge: the shell made NO request while establishing the session (no bearer was obtained to omit)", fetched.length === 0);

    const correlationId = cmsg && cmsg.correlationId;

    // (7) A REPLAYED READY — same nonce, the one already answered — is IGNORED.
    //     This is the property the single-context latch was really protecting.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsg });
    await flush();
    check("bridge: a REPLAYED READY (same nonce) is ignored (no second context)", captured.windowPosts.length === 1);

    // (8) resize: an in-range height ABOVE the panel cap is CLAMPED (not trusted);
    //     a height OVER the schema max is REJECTED. maxPanelHeight() ==
    //     innerHeight(800) - 120 == 680; RESIZE_MAX_HEIGHT == 20000.
    const cwWidget = captured.byClass["cw-widget"];
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 1, height: 5000 } });
    await flush();
    const clampedH = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: in-range resize height above the cap is CLAMPED (<= 680px)", typeof clampedH === "number" && clampedH > 0 && clampedH <= 680);
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 2, height: 999999 } });
    await flush();
    const afterOverMax = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("bridge: over-schema-max resize height (>20000) is REJECTED (height unchanged)", afterOverMax === clampedH);

    // (9) apply_intent (untrusted selector) -> ONE in-place draft refresh via the
    //     Drupal CustomEvent sink; a DUPLICATE id is deduped (LRU); a WRONG
    //     correlationId is ignored; and NO content-egress fetch is made.
    const fetchCountBeforeApply = fetched.length;
    const applyMsg = (seq, id) => ({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId, seq, viewType: "content_change_proposal", proposalId: id } });
    deliverToBridge(applyMsg(3, "prop-A"));
    await flush();
    deliverToBridge(applyMsg(4, "prop-A"));      // duplicate id -> LRU dedup
    await flush();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId: "WRONGcorrelationId012345", seq: 5, viewType: "content_change_proposal", proposalId: "prop-B" } });
    await flush();
    const oneRefresh = captured.applied.length === 1 &&
      captured.applied[0].type === "cinatra:content-applied" &&
      captured.applied[0].detail && captured.applied[0].detail.resourceId === "5";
    const noApplyEgress = fetched.length === fetchCountBeforeApply; // no fetch at all on apply
    check("bridge: apply_intent -> ONE in-place draft refresh (dup id + wrong correlationId ignored)", oneRefresh);
    check("bridge: apply_intent does NOT egress (no fetch on apply — #1214)", noApplyEgress);

    // (10) a DIFFERENT proposal id refreshes again (proves it was dedup, not a
    //      one-shot latch).
    deliverToBridge(applyMsg(6, "prop-C"));
    await flush();
    check("bridge: a new proposal id refreshes again (dedup, not a one-shot latch)", captured.applied.length === 2);

    // (11) presence-XOR: an apply carrying BOTH selector keys (one empty) is
    //      REJECTED (matches the core presence-XOR schema), so no refresh fires.
    const beforeBoth = captured.applied.length;
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId, seq: 7, viewType: "content_change_proposal", proposalId: "", changeSetId: "cs-1" } });
    await flush();
    check("bridge: apply carrying BOTH selector keys is rejected (presence-XOR, no refresh)", captured.applied.length === beforeBoth);

    // (11b) THE SEQ GATE IS NOT SPENT BY A MESSAGE THAT WILL BE DROPPED. An
    //       unknown uplink type carrying a high sequence number must not consume
    //       the gate, or it would silence every legitimate uplink after it. The
    //       closed type set is checked before the gate commits.
    const beforeStarve = captured.applied.length;
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.not_a_real_type", protocolVersion: 2, correlationId, seq: 999999 } });
    await flush();
    deliverToBridge(applyMsg(8, "prop-D"));
    await flush();
    check(
      "bridge: an unknown uplink with a high seq does NOT starve the gate (a later valid uplink still lands)",
      captured.applied.length === beforeStarve + 1,
    );

    // (12) DOCUMENT REPLACEMENT. The frame reloads — protocol 2 says a reload
    //      runs the sign-in ceremony again — and the replacement document
    //      announces itself with a FRESH nonce. It must be served, with a FRESH
    //      correlationId, or the widget would sit at "waiting for the host"
    //      forever. Re-serving is safe in a way re-bootstrapping never was: the
    //      message carries no credential.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2("reloadNonce0123456789abc") });
    await flush(30);
    const second = captured.windowPosts[1];
    check(
      "bridge: a READY with a NEW nonce (the frame's document was replaced) is served a FRESH context",
      captured.windowPosts.length === 2 && !!second &&
        second.msg.nonceEcho === "reloadNonce0123456789abc" &&
        second.msg.seq === 0 &&
        second.msg.correlationId !== correlationId &&
        !carriesCredentialShape(second.msg),
    );
  }

  // -------------------------------------------------------------------------
  // OUTBOUND CREDENTIAL REFUSAL. Removing the credential FIELD does not remove
  // the possibility of a credential VALUE: every remaining field is a string the
  // site chooses. A node bundle that is really a `cwu_…` must not travel — and
  // the refusal is at the SEND, not at the far end's parse, so nothing goes out
  // at all. Tested on BOTH transports, because a guard that only covered one
  // would be no guard.
  // -------------------------------------------------------------------------
  {
    const poisoned = { nodeBundle: "cwu_smuggled-bearer-value" };

    // WINDOW transport.
    const w = await driveToMountedFrame(poisoned);
    w.deliverToBridge({ origin: INSTANCE_ORIGIN, source: w.frameWin, data: readyMsgV2("poisonNonce0123456789abc") });
    await flush(30);
    check("outbound guard: a credential-shaped CMS value means NOTHING is posted on the window transport", w.captured.windowPosts.length === 0);

    // PORT transport — same refusal, fresh widget.
    const p = await driveToMountedFrame(poisoned);
    const poisonPort = makePort();
    p.deliverToBridge({ origin: INSTANCE_ORIGIN, source: p.frameWin, data: readyMsgV2("poisonPortNonce012345678"), ports: [poisonPort] });
    await flush(30);
    check("outbound guard: the same refusal holds on the PORT transport (nothing rides the port either)", poisonPort.posts.length === 0 && p.captured.windowPosts.length === 0);

    // THE FRAME URL IS AN OUTBOUND PAYLOAD TOO. The bridge guard cannot reach it:
    // the src is composed from config and leaves the page as an HTTP request,
    // where it also lands in history and in an access log. A credential-shaped
    // instanceId must mean the frame is NOT MOUNTED, not a request with a bearer
    // in its query string.
    const u = await driveToMountedFrame({ instanceId: "cnx_smuggled-site-credential" });
    check("outbound guard: a credential-shaped instanceId means the iframe is NOT mounted (no URL egress)", !u.captured.iframeEl && !u.captured.iframeSrc);

    // A non-string selector is DROPPED, not stringified into nonsense: the
    // frame's schema is strict about types as well as keys, so "[object Object]"
    // in cms.resourceType would make the whole message unparseable there.
    const t = await driveToMountedFrame({ nodeId: 7, nodeBundle: { evil: "object" }, nodeStatus: true });
    t.deliverToBridge({ origin: INSTANCE_ORIGIN, source: t.frameWin, data: readyMsgV2("typedNonce01234567890abc") });
    await flush(30);
    const typed = t.captured.windowPosts[0] && t.captured.windowPosts[0].msg;
    check(
      "outbound guard: every selector is a STRING (a number is normalized, a non-selector is dropped)",
      !!typed && typed.cms.resourceId === "7" && typed.cms.status === "true" && !("resourceType" in typed.cms),
    );

    // ENCODING MUST NOT BE AN ESCAPE HATCH. `encodeURIComponent` destroys the
    // token boundary the guard matches on — `"x cnx_…"` becomes `"x%20cnx_…"`,
    // whose preceding character is a digit — so a scan of the finished URL alone
    // would miss it. The raw components are what is checked.
    const e = await driveToMountedFrame({ instanceId: "x cnx_smuggled-through-encoding" });
    check("outbound guard: a credential that only survives ENCODING is still caught (raw components are scanned)", !e.captured.iframeEl && !e.captured.iframeSrc);

    // The NEGATIVE CONTROL the refusals need: the credential-free twin of the
    // same message must still be sent, or the guard would be indistinguishable
    // from a broken bridge.
    const { captured: c4, deliverToBridge: deliver4, frameWin: win4 } = await driveToMountedFrame({ nodeBundle: "article" });
    deliver4({ origin: INSTANCE_ORIGIN, source: win4, data: readyMsgV2("cleanNonce01234567890abc") });
    await flush(30);
    check("outbound guard NEGATIVE CONTROL: the credential-free twin IS sent", c4.windowPosts.length === 1 && c4.windowPosts[0].msg.cms.resourceType === "article");
  }

  // -------------------------------------------------------------------------
  // §12b DOCUMENT-BOUND MESSAGEPORT TRANSPORT (cinatra#1965/#1970). On a READY
  // that TRANSFERS a MessagePort, the parent sends the CONTEXT message over the
  // RETAINED port and services uplinks on it; a window-delivered uplink is
  // IGNORED in port mode. At protocol 2 this binds the CHANNEL to the realm that
  // ran the handshake — it is no longer a credential wall, because there is no
  // credential on this bridge to wall off.
  // -------------------------------------------------------------------------
  {
    const P_NONCE_1 = "portNonce" + "1".repeat(16); // 25 chars, satisfies ID_PATTERN
    const P_NONCE_2 = "portNonce" + "2".repeat(16);
    const { captured, deliverToBridge, frameWin } = await driveToMountedFrame();
    const port = makePort();

    // (P1) READY TRANSFERS a port -> the CONTEXT rides the PORT, never the
    //      window. No window post; exactly one port post; nonce echo, seq 0, node
    //      cms; no credential field or value; the port is started.
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2(P_NONCE_1), ports: [port] });
    await flush(30);
    const cmsg = port.posts[0];
    const portContextOk = captured.windowPosts.length === 0 && port.posts.length === 1 && !!cmsg &&
      cmsg.type === "cinatra.embed.context" &&
      cmsg.protocolVersion === 2 &&
      ID_PATTERN.test(cmsg.correlationId) &&
      cmsg.nonceEcho === P_NONCE_1 &&
      cmsg.seq === 0 &&
      !("auth" in cmsg) && !carriesCredentialShape(cmsg) &&
      cmsg.session && cmsg.session.assistant === "drupal" && ID_PATTERN.test(cmsg.session.threadId) &&
      cmsg.cms && cmsg.cms.instanceId === "i1" && cmsg.cms.resourceId === "5" && cmsg.cms.resourceType === "article";
    check("port: READY-with-port -> CONTEXT rides the PORT (no window post), v2/nonce echo/seq0/node cms, credential-free", portContextOk && port.started === true);

    const correlationId = cmsg && cmsg.correlationId;

    // (P2) a REPLAYED READY (same nonce, fresh port) is IGNORED on both
    //      transports — the replay cannot re-open the channel or move it.
    const port2 = makePort();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2(P_NONCE_1), ports: [port2] });
    await flush();
    check("port: a REPLAYED READY-with-port is ignored (channel stays where it was)", port.posts.length === 1 && port2.posts.length === 0 && captured.windowPosts.length === 0);

    // (P2b) a READY carrying MORE THAN ONE transferred port is refused outright:
    //       the protocol transfers exactly one, so this is not a frame speaking
    //       it, and reducing it to "the first port" would be a guess.
    const extraA = makePort();
    const extraB = makePort();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2("multiPortNonce0123456789"), ports: [extraA, extraB] });
    await flush();
    check(
      "port: a READY transferring MORE than one port is refused (nothing sent on any transport)",
      extraA.posts.length === 0 && extraB.posts.length === 0 && captured.windowPosts.length === 0,
    );
    // …and the refusal must not have COST anything: a malformed READY that is on
    // its way to being rejected must never tear down the established session, or
    // a rejected message would be a denial of service. The original port is
    // still open, and P3/P4 below prove the session still works on it.
    check("port: a refused READY does NOT tear down the established session", port.closed === false);

    // (P3) uplinks ride the PORT: a resize over the port is CLAMPED; a WINDOW-
    //      delivered uplink in port mode is IGNORED (uplinks travel the port only).
    const cwWidget = captured.byClass["cw-widget"];
    port.deliver({ type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 1, height: 5000 });
    await flush();
    const clampedH = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("port: resize uplink over the PORT is CLAMPED (<= 680px)", typeof clampedH === "number" && clampedH > 0 && clampedH <= 680);
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: { type: "cinatra.embed.resize", protocolVersion: 2, correlationId, seq: 2, height: 100 } });
    await flush();
    const afterWindowUplink = cwWidget && parseInt(String(cwWidget.style.height || "0"), 10);
    check("port: a WINDOW-delivered uplink is IGNORED in port mode (uplinks ride the port only)", afterWindowUplink === clampedH);

    // (P4) apply_intent over the PORT -> ONE in-place draft refresh (the Drupal
    //      CustomEvent sink), targeting the parent's OWN canonical resource.
    port.deliver({ type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId, seq: 3, viewType: "content_change_proposal", proposalId: "port-prop-A" });
    await flush();
    const appliedOk = captured.applied.length === 1 &&
      captured.applied[0].type === "cinatra:content-applied" &&
      captured.applied[0].detail && captured.applied[0].detail.resourceId === "5";
    check("port: apply_intent over the PORT -> ONE in-place draft refresh", appliedOk);

    // (P4b) NO REFUSED READY, OF ANY KIND, TEARS DOWN THE ESTABLISHED SESSION.
    //       The epoch reset sits after every validation for exactly this reason,
    //       and "after the port-count check" alone would be a thin claim — a
    //       version, nonce or seq that fails earlier must be just as harmless.
    //       Each of these is refused; after all of them the original port is
    //       still open and still serving uplinks.
    const refusedReadies = [
      { label: "protocol-1 version", data: { type: "cinatra.embed.ready", protocolVersion: 1, nonce: "refuseV1Nonce012345678901", seq: 0 } },
      { label: "nonce too short", data: { type: "cinatra.embed.ready", protocolVersion: 2, nonce: "short", seq: 0 } },
      { label: "non-integer seq", data: { type: "cinatra.embed.ready", protocolVersion: 2, nonce: "refuseSeqNonce0123456789", seq: 1.5 } },
      { label: "negative seq", data: { type: "cinatra.embed.ready", protocolVersion: 2, nonce: "refuseNegNonce0123456789", seq: -1 } },
    ];
    for (const r of refusedReadies) {
      deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: r.data, ports: [makePort()] });
    }
    await flush(30);
    port.deliver({ type: "cinatra.embed.apply_intent", protocolVersion: 2, correlationId, seq: 4, viewType: "content_change_proposal", proposalId: "port-prop-B" });
    await flush();
    check(
      "port: NO refused READY (bad version / bad nonce / bad seq) tears down the established session",
      port.closed === false && port.posts.length === 1 && captured.applied.length === 2,
    );

    // (P5) DOCUMENT REPLACEMENT over the port transport: the replacement
    //      document's READY (new nonce, its own fresh channel) is served on the
    //      NEW port, and the previous entangled port is CLOSED rather than left
    //      dangling with a live listener.
    const port3 = makePort();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2(P_NONCE_2), ports: [port3] });
    await flush(30);
    check(
      "port: a replacement document is served on its OWN port and the previous port is CLOSED",
      port3.posts.length === 1 &&
        port3.posts[0].nonceEcho === P_NONCE_2 &&
        port3.posts[0].correlationId !== correlationId &&
        port.closed === true &&
        captured.windowPosts.length === 0,
    );
  }

  // (P5) CHANNEL-BINDING REFUSAL: under requirePort, a port-LESS READY sends
  //      NOTHING — the window transport cannot be selected by stripping the port;
  //      a subsequent READY WITH a port still sends over the port.
  {
    const NP_NONCE = "noPortNonce" + "0".repeat(14); // 25 chars
    const RP_NONCE = "reqPortNonce" + "0".repeat(14);
    const { captured, deliverToBridge, frameWin } = await driveToMountedFrame({ requirePort: true });
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2(NP_NONCE) });
    await flush(30);
    check("port: requirePort REFUSES a port-less READY (no window post; the channel cannot be unbound)", captured.windowPosts.length === 0);
    const port = makePort();
    deliverToBridge({ origin: INSTANCE_ORIGIN, source: frameWin, data: readyMsgV2(RP_NONCE), ports: [port] });
    await flush(30);
    check("port: under requirePort a READY-with-port still sends over the PORT", port.posts.length === 1 && captured.windowPosts.length === 0);
  }

  // -------------------------------------------------------------------------
  // THE ORIGIN BOUNDARY IS LOAD-BEARING. Protocol 2's promise — that this site
  // cannot come to possess the person's Cinatra credential — rests on the frame
  // being a different ORIGIN from the page around it. An instance deployed on
  // this site's own origin does not weaken that promise, it removes it: site
  // script could read straight into the frame's realm. The widget refuses to
  // mount rather than claim a protection it does not have.
  // -------------------------------------------------------------------------
  {
    const captured = newCaptured();
    const env = makeEnv(() => jsonResponse(200, {}), undefined, captured);
    // The page origin the shim reports is https://site.example.
    env.sandbox.window.drupalSettings.cinatra.cinatraUrl = "https://site.example";
    vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
    await flush();
    check(
      "origin boundary: a same-origin instance is REFUSED (no mount, fallback chrome stays)",
      env.rootEl.dataset.cinatraMounted !== "true" && env.attachShadowCount() === 0 && !captured.iframeEl,
    );
    // Negative control: the identical widget on a cross-origin instance mounts.
    const ok = await boot(() => jsonResponse(200, {}));
    check("origin boundary NEGATIVE CONTROL: a cross-origin instance still mounts", ok.mounted && ok.attachShadow);
  }

  // -------------------------------------------------------------------------
  // SOURCE-LEVEL ABSENCE. The retired ceremony's machinery lived in closure-
  // private state the sandbox cannot reach, so — as this harness already does for
  // structural properties — the absence is pinned against the widget source. A
  // regression that re-adds a broker call or a credential field would be caught by
  // the parity gate too; pinning it here as well means the behavioural harness
  // fails loudly rather than silently passing an inverted contract.
  // -------------------------------------------------------------------------
  {
    const noBrokerCalls = !/config\.(?:tokenEndpoint|authInitEndpoint|authTokenEndpoint)/.test(WIDGET_SRC);
    const noWidgetAuthPath = !/['"]\/(?:api\/)?widget-auth/.test(WIDGET_SRC);
    const noAuthField = !/citToken\s*:/.test(WIDGET_SRC) && !/cwuToken\s*:/.test(WIDGET_SRC);
    check("source: no broker endpoint is read and no widget-auth path is composed", noBrokerCalls && noWidgetAuthPath);
    check("source: no citToken/cwuToken field is composed anywhere", noAuthField);
  }

  console.log(failures === 0 ? "\nALL PASS" : `\n${failures} FAILURE(S)`);
  process.exit(failures === 0 ? 0 : 1);
}

main();
