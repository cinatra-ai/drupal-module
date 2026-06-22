// Standalone behavior tests for the vendored widget's capability negotiation.
//
// Runs under plain `node tests/test-widget-negotiation.mjs` — no jsdom, no
// bundler, no Drupal. Exit code 0 = all pass, 1 = a failure.
//
// Covers the "drop the old-instance fallback, keep negotiation" contract
// (cinatra#220): /capabilities is a HARD PREREQUISITE. The widget mounts ONLY
// when negotiation succeeds + validates; on ANY failure it never attaches its
// Shadow DOM and never sets data-cinatra-mounted (so the always-visible
// fallback button stays as the unavailable/incompatible chrome).
//
//   - /capabilities failure (HTTP not-ok / network)  -> UNAVAILABLE (no mount)
//   - missing required field (no streamPath)          -> INCOMPATIBLE (no mount)
//   - no mutually-supported contractVersion           -> UNAVAILABLE (no mount)
//   - healthy v2 instance (control)                   -> MOUNTS

import fs from "node:fs";
import path from "node:path";
import vm from "node:vm";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WIDGET_SRC = fs.readFileSync(
  path.join(__dirname, "..", "js", "cinatra-widget.js"),
  "utf8",
);

let failures = 0;
function check(label, cond) {
  if (cond) {
    console.log(`  PASS  ${label}`);
  } else {
    console.log(`  FAIL  ${label}`);
    failures++;
  }
}

function makeEnv(fetchImpl, sharedRoot, captured) {
  let attachShadowCount = 0;
  // Required-login (cinatra#410): the login handshake needs Web Crypto, btoa,
  // window.open, and a captured window 'message' listener. The login-gate test
  // drives a real login through these before streaming.
  const messageListeners = [];
  const openedPopups = [];

  function makeStubEl(isRoot) {
    const el = {
      style: {},
      dataset: {},
      shadowRoot: null,
      classList: { add() {}, remove() {}, contains() { return false; } },
      attributes: {},
      children: [],
      _clickHandlers: [],
      set placeholder(v) {
        this._placeholder = v;
        if (captured) { captured.textarea = this; }
      },
      get placeholder() { return this._placeholder; },
      // Identify the login button by its label so a test can fire ONLY its click
      // handler (the login-gate test drives login then send distinctly).
      set textContent(v) {
        this._textContent = v;
        if (captured && v === "Sign in with Cinatra") { captured.loginBtnEl = this; }
      },
      get textContent() { return this._textContent; },
      setAttribute(k, v) { this.attributes[k] = v; },
      getAttribute(k) { return this.attributes[k]; },
      appendChild(c) { this.children.push(c); return c; },
      addEventListener(type, handler) {
        if (type === "click") {
          this._clickHandlers.push(handler);
          if (captured) { captured.clickHandlers.push(handler); }
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
    return el;
  }

  const rootEl = sharedRoot || makeStubEl(true);

  const documentStub = {
    getElementById(id) { return id === "cinatra-root" ? rootEl : null; },
    createElement() { return makeStubEl(); },
    createElementNS() { return makeStubEl(); },
    querySelector() { return null; },
    addEventListener() {},
    head: makeStubEl(),
    body: makeStubEl(),
    readyState: "complete",
  };

  const storage = (() => {
    const m = new Map();
    return {
      getItem(k) { return m.has(k) ? m.get(k) : null; },
      setItem(k, v) { m.set(k, String(v)); },
      removeItem(k) { m.delete(k); },
    };
  })();

  const sandbox = {
    window: {
      drupalSettings: {
        cinatra: {
          cinatraUrl: "https://instance.example",
          tokenEndpoint: "https://site.example/cinatra/token",
          // Required-login broker relays + the per-route Drupal CSRF tokens
          // (cinatra#410). Each _csrf_token route is seeded to its own path, so
          // init and token carry DIFFERENT tokens.
          authInitEndpoint: "https://site.example/cinatra/widget-auth/init",
          authTokenEndpoint: "https://site.example/cinatra/widget-auth/token",
          authInitCsrfToken: "drupal-csrf-init",
          authTokenCsrfToken: "drupal-csrf-token",
          csrfToken: "drupal-csrf",
          instanceId: "i1",
        },
      },
      sessionStorage: storage,
      innerWidth: 1280,
      innerHeight: 800,
      location: { href: "https://site.example/node/1", reload() {} },
      addEventListener(type, handler) { if (type === "message") { messageListeners.push(handler); } },
      removeEventListener() {},
      open(url) { const popup = { url, closed: false, close() { this.closed = true; } }; openedPopups.push(popup); return popup; },
      crypto: {
        getRandomValues(arr) { for (let i = 0; i < arr.length; i++) { arr[i] = (i * 7 + 3) & 0xff; } return arr; },
        subtle: { async digest() { return new ArrayBuffer(32); } },
      },
    },
    document: documentStub,
    console,
    fetch: fetchImpl,
    setTimeout: () => 0,
    clearTimeout: () => {},
    setInterval: () => 0,
    clearInterval: () => {},
    btoa: (s) => Buffer.from(s, "binary").toString("base64"),
    TextEncoder,
    AbortController: class {
      constructor() { this.signal = {}; }
      abort() {}
    },
    Object,
    Array,
    JSON,
    Promise,
    Date,
    Math,
    String,
    Uint8Array,
    // The widget resolves caps.streamPath against the configured origin with
    // the WHATWG URL constructor (same-origin guard). Real browsers always
    // expose URL; the vm sandbox must too, or the guard would throw and mask
    // the behavior under test.
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

async function boot(fetchImpl, sharedRoot) {
  const env = makeEnv(fetchImpl, sharedRoot);
  vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
  for (let i = 0; i < 20; i++) { await Promise.resolve(); }
  return {
    env,
    mounted: env.rootEl.dataset.cinatraMounted === "true",
    attachShadow: env.attachShadowCount() > 0,
    attachShadowCount: env.attachShadowCount(),
  };
}

const HEALTHY = {
  agentSlug: "drupal-content-editor",
  contractVersion: "v2",
  supportedContractVersions: ["v1", "v2"],
  minContractVersion: "v1",
  maxContractVersion: "v2",
  capabilities: {
    supportsChangesFrame: true,
    supportsMarkdown: true,
    supportsTokenExchange: true,
    sseFrames: ["text", "changes", "error", "done"],
    streamPath: "/api/agents/drupal-content-editor/stream",
    tokenPath: "/api/agents/drupal-content-editor/token",
  },
};

async function main() {
  console.log("widget capability negotiation (hard prerequisite)");

  {
    const r = await boot(() => jsonResponse(200, HEALTHY));
    check("healthy v2 instance -> MOUNTS (control)", r.mounted && r.attachShadow);
  }

  {
    const r = await boot(() => jsonResponse(500, { error: "boom" }));
    check("/capabilities 5xx -> UNAVAILABLE (no mount, no attachShadow)", !r.mounted && !r.attachShadow);
  }

  {
    const r = await boot(() => jsonResponse(404, { error: "Unknown agent" }));
    check("/capabilities 404 -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  {
    const r = await boot(() => Promise.reject(new Error("network down")));
    check("/capabilities network error -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    delete body.capabilities.streamPath;
    const r = await boot(() => jsonResponse(200, body));
    check("missing required field (streamPath) -> INCOMPATIBLE (no mount)", !r.mounted && !r.attachShadow);
  }

  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    body.capabilities.supportsTokenExchange = false;
    const r = await boot(() => jsonResponse(200, body));
    check("supportsTokenExchange:false -> INCOMPATIBLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // REGRESSION (token-exfiltration guard): an otherwise-healthy /capabilities
  // whose streamPath resolves OFF the configured cinatraUrl origin MUST NOT
  // mount. Otherwise STREAM_BASE + streamPath would ship the short-lived Bearer
  // stream token to a foreign origin. cinatraUrl is "https://instance.example".
  // Each vector below either smuggles an authority (// , https:// , @host,
  // backslash tricks) or is otherwise not a plain root-absolute same-origin
  // path; all must => negotiate() false => NO mount => fallback chrome.
  {
    const OFF_ORIGIN_STREAM_PATHS = [
      "@evil.example/stream",         // raw concat => https://instance.example@evil.example/... => evil origin
      "//evil.example/stream",        // protocol-relative authority
      "https://evil.example/stream",  // absolute foreign URL
      "\\\\evil.example/stream",      // backslash authority (\\host) — resolves off-origin
      "/\\evil.example/stream",       // /\host backslash-authority trick
      "/\\/evil.example/stream",      // /\/host backslash-authority trick
    ];
    for (const sp of OFF_ORIGIN_STREAM_PATHS) {
      const body = JSON.parse(JSON.stringify(HEALTHY));
      body.capabilities.streamPath = sp;
      const r = await boot(() => jsonResponse(200, body));
      check(
        `off-origin streamPath ${JSON.stringify(sp)} -> NO mount (no attachShadow)`,
        !r.mounted && !r.attachShadow,
      );
    }
  }

  // Control for the same-origin guard: a healthy root-absolute same-origin
  // streamPath (with a query) still mounts — the guard must not over-reject.
  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    body.capabilities.streamPath = "/api/agents/drupal-content-editor/stream?v=2";
    const r = await boot(() => jsonResponse(200, body));
    check("same-origin streamPath with query -> MOUNTS (guard control)", r.mounted && r.attachShadow);
  }

  {
    const body = JSON.parse(JSON.stringify(HEALTHY));
    body.supportedContractVersions = ["v0", "v9"];
    const r = await boot(() => jsonResponse(200, body));
    check("no mutual contractVersion -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  {
    const r = await boot(() => Promise.resolve({
      ok: true,
      status: 200,
      json: () => Promise.reject(new SyntaxError("bad json")),
      text: () => Promise.resolve("<html>not json"),
      headers: { get() { return null; } },
    }));
    check("malformed JSON -> UNAVAILABLE (no mount)", !r.mounted && !r.attachShadow);
  }

  // Duplicate include against the SAME root -> mounts exactly once (the second
  // copy sees the marker / existing shadowRoot and bails).
  {
    const first = await boot(() => jsonResponse(200, HEALTHY));
    const second = await boot(() => jsonResponse(200, HEALTHY), first.env.rootEl);
    check(
      "duplicate include -> mounts exactly once (attachShadow called once total)",
      first.mounted && first.env.rootEl.dataset.cinatraMounted === "true" &&
        (first.attachShadowCount + second.attachShadowCount) === 1,
    );
  }

  // REQUIRED-LOGIN GATE (cinatra#410, behavioral): a healthy same-origin instance
  // mounts in LOGIN mode (no per-user token), so a user message must NOT stream
  // until login completes. This drives the full hosted-PKCE handshake (init via
  // the broker -> popup -> postMessage -> token via the broker -> opaque cwu_)
  // and then a real send, asserting:
  //   * before login: a send attempt does NOT POST the stream;
  //   * the login init + token relays go to OUR same-origin broker carrying the
  //     Drupal CSRF token in the `?token=` QUERY (NOT a header) — the divergence
  //     from the WordPress source copy;
  //   * after login: the stream POST carries BOTH the cit_ Bearer AND the
  //     X-Cinatra-Widget-User-Token: cwu_ dual token (#408).
  {
    const TOKEN_ENDPOINT = "https://site.example/cinatra/token";
    const AUTH_INIT = "https://site.example/cinatra/widget-auth/init";
    const AUTH_TOKEN = "https://site.example/cinatra/widget-auth/token";
    const STREAM_URL = "https://instance.example/api/agents/drupal-content-editor/stream";
    const INSTANCE_ORIGIN = "https://instance.example";
    const fetched = [];
    let initState = null;
    const urlNoQuery = (u) => String(u).split("?")[0];
    const fetchImpl = (url, opts) => {
      const u = String(url);
      const base = urlNoQuery(u);
      const body = (opts && opts.body) ? JSON.parse(opts.body) : null;
      fetched.push({ url: u, base, method: (opts && opts.method) || "GET", headers: (opts && opts.headers) || {}, body });
      if (u.indexOf("/capabilities") !== -1) { return jsonResponse(200, HEALTHY); }
      if (base === AUTH_INIT) {
        initState = body && body.state;
        return jsonResponse(200, { txnId: "txn1", authorizeUrl: INSTANCE_ORIGIN + "/widget-auth?txn=txn1", instanceId: "i1" });
      }
      if (base === AUTH_TOKEN) { return jsonResponse(200, { token: "cwu_user-tok", tokenType: "Bearer", expiresIn: 900 }); }
      if (base === TOKEN_ENDPOINT) { return jsonResponse(200, { token: "stream-tok", expiresIn: 300 }); }
      return Promise.resolve({
        ok: true,
        status: 200,
        body: { getReader() { return { read() { return Promise.resolve({ done: true }); } }; } },
        text: () => Promise.resolve(""),
        headers: { get() { return null; } },
      });
    };

    const captured = { clickHandlers: [], textarea: null, loginBtnEl: null };
    const env = makeEnv(fetchImpl, undefined, captured);
    vm.runInNewContext(WIDGET_SRC, env.sandbox, { filename: "cinatra-widget.js" });
    for (let i = 0; i < 20; i++) { await Promise.resolve(); }
    const mounted = env.rootEl.dataset.cinatraMounted === "true";

    // (1) Pre-login: a send attempt must NOT reach the stream (login gate).
    let preLoginStreamed = false;
    if (mounted && captured.textarea) {
      captured.textarea.value = "hello";
      for (const h of captured.clickHandlers) { try { h({ stopPropagation() {} }); } catch (_) {} }
      for (let i = 0; i < 20; i++) { await Promise.resolve(); }
      preLoginStreamed = fetched.some((f) => f.base === STREAM_URL && f.method === "POST");
    }
    check(
      "login gate: pre-login send does NOT POST the stream (token-less stream blocked)",
      mounted && !preLoginStreamed,
    );

    // (2) Drive the login handshake.
    let loggedIn = false;
    if (mounted && captured.loginBtnEl && captured.loginBtnEl._clickHandlers.length) {
      for (const h of captured.loginBtnEl._clickHandlers) { try { h({}); } catch (_) {} }
      for (let i = 0; i < 20; i++) { await Promise.resolve(); }
      const popup = env.openedPopups[env.openedPopups.length - 1];
      for (const listener of env.messageListeners) {
        try { listener({ origin: INSTANCE_ORIGIN, source: popup, data: { type: "cinatra-widget-auth", code: "auth-code-1", state: initState } }); } catch (_) {}
      }
      for (let i = 0; i < 30; i++) { await Promise.resolve(); }
      loggedIn = true;
    }
    const initPost = fetched.find((f) => f.base === AUTH_INIT && f.method === "POST");
    const tokenPost = fetched.find((f) => f.base === AUTH_TOKEN && f.method === "POST");
    // Drupal CSRF idiom: each endpoint's OWN route-seeded token is in the
    // ?token= QUERY, never a header (X-CSRF-Token).
    const initCsrfQueryOk = !!initPost && /[?&]token=drupal-csrf-init(&|$)/.test(initPost.url) && !initPost.headers["X-CSRF-Token"];
    const tokenCsrfQueryOk = !!tokenPost && /[?&]token=drupal-csrf-token(&|$)/.test(tokenPost.url) && !tokenPost.headers["X-CSRF-Token"];
    const verifierSent = !!tokenPost && tokenPost.body && typeof tokenPost.body.codeVerifier === "string" && tokenPost.body.codeVerifier.length > 0;
    check(
      "login handshake: init+token relayed with per-route CSRF in ?token= query (not a header) + PKCE verifier",
      loggedIn && initCsrfQueryOk && tokenCsrfQueryOk && verifierSent,
    );

    // (3) Post-login: a real send streams with BOTH the cit_ Bearer and cwu_.
    let sent = false;
    if (loggedIn && captured.textarea) {
      captured.textarea.value = "hello again";
      for (const h of captured.clickHandlers) { try { h({ stopPropagation() {} }); } catch (_) {} }
      for (let i = 0; i < 30; i++) { await Promise.resolve(); }
      sent = true;
    }
    const streamPost = fetched.find((f) => f.base === STREAM_URL && f.method === "POST");
    const bearerOk = !!streamPost && /^Bearer stream-tok$/.test(String(streamPost.headers.Authorization || ""));
    const dualTokenOk = !!streamPost && streamPost.headers["X-Cinatra-Widget-User-Token"] === "cwu_user-tok";
    const tokenMints = fetched.filter((f) => f.base === TOKEN_ENDPOINT).length;
    check(
      "send happy path: post-login stream POST carries cit_ Bearer + cwu_ dual token; cit_ minted once",
      mounted && sent && bearerOk && dualTokenOk && tokenMints === 1,
    );
  }

  console.log(failures === 0 ? "\nALL PASS" : `\n${failures} FAILURE(S)`);
  process.exit(failures === 0 ? 0 : 1);
}

main();
