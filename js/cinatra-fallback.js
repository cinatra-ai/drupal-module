/**
 * @file
 * Cinatra fallback widget chrome.
 *
 * Wires the floating fallback button + "cannot connect" error card that the
 * module renders whenever the Cinatra URL is configured. If the real bundle
 * mounts (#cinatra-root gets data-cinatra-mounted="true") the fallback hides
 * itself. Otherwise, clicking the button probes the instance's public embed page
 * (the widget host) and shows a graceful, admin-facing message — never a silent
 * missing widget. Mirrors the WordPress plugin's inline fallback.
 */
(function (Drupal, drupalSettings) {
  "use strict";

  Drupal.behaviors.cinatraFallback = {
    attach: function () {
      var btn = document.getElementById("cw-fallback-btn");
      // Bind once — Drupal.behaviors.attach can run repeatedly (AJAX, etc.).
      if (!btn || btn.dataset.cwBound === "true") {
        return;
      }
      btn.dataset.cwBound = "true";

      var box = document.getElementById("cw-fallback-error");
      var msg = document.getElementById("cw-fe-msg");
      var cls = document.getElementById("cw-fe-close");
      var settings = (drupalSettings && drupalSettings.cinatra) || {};
      var cu = String(settings.cinatraUrl || "").replace(/\/+$/, "");
      var root = document.getElementById("cinatra-root");
      var mounted = false;

      // Hide the fallback the moment the real widget mounts its Shadow DOM.
      // Check the current state first — the bundle may have mounted before this
      // behavior attaches, in which case the observer would never fire.
      if (root) {
        if (root.dataset.cinatraMounted === "true") {
          btn.style.display = "none";
          if (box) {
            box.style.display = "none";
          }
          mounted = true;
        } else {
          var obs = new MutationObserver(function (_records, observer) {
            if (root.dataset.cinatraMounted === "true") {
              btn.style.display = "none";
              if (box) {
                box.style.display = "none";
              }
              mounted = true;
              observer.disconnect();
            }
          });
          obs.observe(root, { attributes: true });
        }
      }

      btn.addEventListener("click", function () {
        if (mounted) {
          return;
        }
        if (!box || !msg) {
          return;
        }
        if (!cu) {
          msg.textContent = Drupal.t(
            "Cinatra is not configured. Set the Cinatra URL and API key at /admin/config/services/cinatra."
          );
          box.style.display = "block";
          return;
        }
        // The widget bundle now ships locally, so a missing widget means the
        // instance itself is unreachable/misconfigured. The legacy auth-free
        // /api/agents/{slug}/capabilities probe was removed with the
        // unified-broker cutover (cinatra#2029); the widget host is now the public
        // /embed/assistant page. It is cross-origin and emits no CORS headers, so
        // this is a `no-cors` probe: a resolved (opaque) response means the
        // instance answered — reachable; a rejection (DNS / connection refused /
        // timeout) means it did not — unreachable. The opaque response status is
        // intentionally not read.
        // Bounded-timeout via a guarded AbortController (AbortSignal.timeout is not
        // universal and can throw synchronously on older browsers).
        var controller =
          typeof AbortController !== "undefined" ? new AbortController() : null;
        var timer = controller
          ? setTimeout(function () {
              try {
                controller.abort();
              } catch (e) {
                // Ignore: aborting an already-settled controller is a no-op.
              }
            }, 4000)
          : null;
        fetch(cu + "/embed/assistant", {
          method: "GET",
          mode: "no-cors",
          cache: "no-store",
          signal: controller ? controller.signal : undefined,
        })
          .then(function () {
            if (timer) {
              clearTimeout(timer);
            }
            msg.textContent = Drupal.t(
              "Cinatra is reachable but the assistant has not loaded yet. Try refreshing the page."
            );
            box.style.display = "block";
          })
          .catch(function () {
            if (timer) {
              clearTimeout(timer);
            }
            msg.textContent = Drupal.t(
              "Cannot reach !url. Check that your Cinatra instance is running.",
              { "!url": cu }
            );
            box.style.display = "block";
          });
      });

      if (cls && box) {
        cls.addEventListener("click", function () {
          box.style.display = "none";
        });
      }

      document.addEventListener("click", function (e) {
        if (!box || box.style.display === "none") {
          return;
        }
        if (!box.contains(e.target) && e.target !== btn) {
          box.style.display = "none";
        }
      });
    },
  };
})(Drupal, drupalSettings);
