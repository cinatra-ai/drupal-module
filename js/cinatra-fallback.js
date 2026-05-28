/**
 * @file
 * Cinatra fallback widget chrome.
 *
 * Wires the floating fallback button + "cannot connect" error card that the
 * module renders whenever the Cinatra URL is configured. If the real bundle
 * mounts (#cinatra-root gets data-cinatra-mounted="true") the fallback hides
 * itself. Otherwise, clicking the button HEAD-checks the bundle URL and shows
 * a graceful, admin-facing message — never a silent missing widget. Mirrors the
 * WordPress plugin's inline fallback.
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
          msg.textContent =
            "Cinatra is not configured. Set the Cinatra URL + API key at " +
            "/admin/config/services/cinatra.";
          box.style.display = "block";
          return;
        }
        fetch(cu + "/api/drupal/bundle.js", {
          method: "HEAD",
          cache: "no-store",
          signal: AbortSignal.timeout(4000),
        })
          .then(function (r) {
            msg.textContent = r.ok
              ? "Cinatra is reachable but the widget has not loaded yet. Try refreshing the page."
              : "Cinatra returned HTTP " + r.status + ". Check your instance at: " + cu;
            box.style.display = "block";
          })
          .catch(function () {
            msg.textContent =
              "Cannot reach " + cu + ". Check that your Cinatra instance is running.";
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
