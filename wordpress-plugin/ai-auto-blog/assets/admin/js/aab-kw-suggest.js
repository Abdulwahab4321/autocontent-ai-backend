// aab-kw-suggest.js
// Live autosuggest for #aab-single-keyword using Google Suggest (no API key).
// Debounced, keyboard nav, and defensive against CORS failures.

(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    var input = document.getElementById("aab-single-keyword");
    if (!input) {
      console.warn("AAB autosuggest: input #aab-single-keyword not found.");
      return;
    }

    // Wrap input so dropdown positions correctly without changing layout
    var wrap = document.createElement("span");
    wrap.className = "aab-kw-wrap";
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var box = document.createElement("div");
    box.className = "aab-kw-suggest";
    box.style.display = "none";
    wrap.appendChild(box);

    var lastQuery = "";
    var items = [];
    var activeIndex = -1;
    var debounceTimer = null;
    var DEBOUNCE_MS = 200;

    function hideBox() {
      box.style.display = "none";
      box.innerHTML = "";
      activeIndex = -1;
      items = [];
    }
    function showBox() {
      if (box.children.length) box.style.display = "block";
    }
    function setActive(i) {
      var children = box.querySelectorAll(".aab-item");
      children.forEach(function (n) {
        n.classList.remove("aab-active");
      });
      if (i >= 0 && i < children.length) {
        children[i].classList.add("aab-active");
        children[i].scrollIntoView({ block: "nearest" });
        activeIndex = i;
      } else {
        activeIndex = -1;
      }
    }

    function applySuggestion(text) {
      input.value = text;
      // trigger input/change in case other code listens
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
      hideBox();
      input.focus();
    }

    function renderSuggestions(list) {
      items = list || [];
      box.innerHTML = "";
      if (!items.length) {
        hideBox();
        return;
      }
      items.forEach(function (s, idx) {
        var div = document.createElement("div");
        div.className = "aab-item";
        div.setAttribute("data-suggestion", s);
        div.innerText = s;
        div.addEventListener("mousedown", function (ev) {
          ev.preventDefault(); // prevents input blur before click
          applySuggestion(s);
        });
        box.appendChild(div);
      });
      activeIndex = -1;
      showBox();
    }

    // keyboard support
    input.addEventListener("keydown", function (e) {
      if (box.style.display === "none") return;
      if (e.key === "ArrowDown") {
        e.preventDefault();
        setActive(
          Math.min(
            activeIndex + 1,
            box.querySelectorAll(".aab-item").length - 1,
          ),
        );
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        setActive(Math.max(activeIndex - 1, 0));
      } else if (e.key === "Enter") {
        if (activeIndex >= 0 && items[activeIndex]) {
          e.preventDefault();
          applySuggestion(items[activeIndex]);
        } else {
          hideBox();
        }
      } else if (e.key === "Escape") {
        hideBox();
      }
    });

    document.addEventListener("click", function (e) {
      if (!wrap.contains(e.target)) hideBox();
    });

    box.addEventListener("mousemove", function (e) {
      var node = e.target;
      while (node && node !== box) {
        if (node.classList && node.classList.contains("aab-item")) {
          var children = Array.prototype.slice.call(box.children);
          var idx = children.indexOf(node);
          setActive(idx);
          break;
        }
        node = node.parentNode;
      }
    });

    function fetchSuggestions(q) {
      if (!q || q.length < 1) {
        renderSuggestions([]);
        return;
      }
      lastQuery = q;

      if (!window.AAB_KW_SUGGEST) {
        console.warn("AAB autosuggest: config missing");
        renderSuggestions([]);
        return;
      }

      var body = new URLSearchParams();
      body.append("action", "aab_kw_suggest");
      body.append("nonce", window.AAB_KW_SUGGEST.nonce);
      body.append("q", q);

      fetch(window.AAB_KW_SUGGEST.ajax_url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
        },
        body: body.toString(),
      })
        .then(function (r) {
          if (!r.ok) return Promise.reject("http:" + r.status);
          return r.json();
        })
        .then(function (json) {
          if (!json.success) {
            renderSuggestions([]);
            return;
          }

          var payload = json.data;
          var suggestions = [];

          if (Array.isArray(payload.data) && Array.isArray(payload.data[1])) {
            suggestions = payload.data[1];
          }

          var seen = {};
          var out = [];
          suggestions.forEach(function (s) {
            if (!s) return;
            var t = s.trim();
            if (t && !seen[t.toLowerCase()]) {
              seen[t.toLowerCase()] = true;
              out.push(t);
            }
          });

          renderSuggestions(out.slice(0, 10));
        })
        .catch(function (err) {
          console.warn("AAB autosuggest proxy failed:", err);
          renderSuggestions([]);
        });
    }

    function scheduleFetch(q) {
      if (debounceTimer) clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () {
        fetchSuggestions(q);
      }, DEBOUNCE_MS);
    }

    input.addEventListener("input", function () {
      var v = input.value || "";
      // avoid triggering on CSV/bulk paste
      if (v.indexOf("\n") !== -1 || v.indexOf(",") !== -1) {
        hideBox();
        return;
      }
      scheduleFetch(v.trim());
    });

    input.addEventListener("focus", function () {
      var v = input.value || "";
      if (v.trim()) scheduleFetch(v.trim());
    });
  }); // DOMContentLoaded
})();
