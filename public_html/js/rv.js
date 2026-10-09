/* ============================================================
   Resolutiva — landing
   Arquivo: js/rv.js
   ============================================================ */

(function () {
  "use strict";

  // ---------- Cookie banner ----------
  window.aceitarCookies = function () {
    try { localStorage.setItem("cookies_aceitos", "sim"); } catch (e) {}
    var el = document.getElementById("cookie-banner");
    if (el) el.style.display = "none";
  };

  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    var banner = document.getElementById("cookie-banner");
    var accepted = null;
    try { accepted = localStorage.getItem("cookies_aceitos"); } catch (e) {}
    if (banner && !accepted) banner.style.display = "block";

    var year = document.querySelector("[data-year]");
    if (year) year.textContent = new Date().getFullYear();

    var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    // ---------- Texto do statement: palavra por palavra ----------
    var split = document.querySelector("[data-split-text]");
    if (split) {
      (function wrap(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
          if (child.nodeType === 3) {
            var frag = document.createDocumentFragment();
            child.textContent.split(/(\s+)/).forEach(function (part) {
              if (!part) return;
              if (/^\s+$/.test(part)) {
                frag.appendChild(document.createTextNode(" "));
              } else {
                var s = document.createElement("span");
                s.className = "word";
                s.textContent = part;
                frag.appendChild(s);
              }
            });
            node.replaceChild(frag, child);
          } else if (child.nodeType === 1) {
            wrap(child);
          }
        });
      })(split);

      var words = split.querySelectorAll(".word");
      var onScrollWords = function () {
        var r = split.getBoundingClientRect();
        var vh = window.innerHeight;
        var p = (vh * 0.85 - r.top) / (r.height + vh * 0.35);
        var n = Math.round(Math.max(0, Math.min(1, p)) * words.length);
        for (var i = 0; i < words.length; i++) {
          words[i].classList.toggle("is-visible", i < n);
        }
      };
      if (reduce) {
        words.forEach(function (w) { w.classList.add("is-visible"); });
      } else {
        window.addEventListener("scroll", onScrollWords, { passive: true });
        onScrollWords();
      }
    }

    // ---------- Reveal ao rolar ----------
    var reveals = document.querySelectorAll(".reveal");
    if ("IntersectionObserver" in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) {
            e.target.classList.add("in");
            io.unobserve(e.target);
          }
        });
      }, { threshold: 0.12, rootMargin: "0px 0px -6% 0px" });
      reveals.forEach(function (el) { io.observe(el); });
    } else {
      reveals.forEach(function (el) { el.classList.add("in"); });
    }

    // ---------- Spotlight nos cards ----------
    document.querySelectorAll(".spot").forEach(function (card) {
      card.addEventListener("pointermove", function (e) {
        var r = card.getBoundingClientRect();
        card.style.setProperty("--mx", e.clientX - r.left + "px");
        card.style.setProperty("--my", e.clientY - r.top + "px");
      });
    });

    // ---------- Newsletter (ResolutivaPages) ----------
    // Envia para a rota pública do Pages: a inscrição entra como assinante
    // do cliente "Resolutiva" (slug em data-client) e recebe e-mail de confirmação.
    var nl = document.getElementById("newsletter-form");
    if (nl && window.fetch) {
      var nlBtn = nl.querySelector(".nl-btn");
      var nlMsg = nl.querySelector(".nl-feedback");
      var nlSay = function (text, isError) {
        nlMsg.textContent = text;
        nlMsg.classList.toggle("is-error", !!isError);
        nlMsg.hidden = false;
      };

      nl.addEventListener("submit", function (ev) {
        ev.preventDefault();
        if (!nl.checkValidity()) {
          nl.reportValidity();
          return;
        }
        nlBtn.disabled = true;
        nlMsg.hidden = true;

        fetch(nl.action, { method: "POST", body: new FormData(nl), headers: { Accept: "application/json" } })
          .then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
              return { ok: res.ok, status: res.status, data: data };
            });
          })
          .then(function (r) {
            if (r.ok) {
              var done = document.createElement("div");
              done.className = "nl-done";
              done.innerHTML = '<i class="fas fa-check"></i><span></span>';
              done.lastChild.textContent = r.data.message || "Quase lá! Enviamos um link de confirmação para o seu e-mail.";
              nl.innerHTML = "";
              nl.appendChild(done);
              return;
            }
            var first = r.data && r.data.errors ? Object.keys(r.data.errors)[0] : null;
            nlSay(first ? r.data.errors[first][0] : "Não foi possível concluir agora. Tente novamente em instantes.", true);
            nlBtn.disabled = false;
          })
          .catch(function () {
            nlSay("Sem conexão no momento. Tente novamente em instantes.", true);
            nlBtn.disabled = false;
          });
      });
    }

    // ---------- Header flutuante ----------
    var header = document.querySelector("[data-header]");
    var progress = document.querySelector(".page-progress span");
    var themed = Array.prototype.slice.call(document.querySelectorAll("[data-section-theme]"));
    var navLinks = Array.prototype.slice.call(document.querySelectorAll(".site-header nav a[href^='#']"));
    var sections = navLinks.map(function (a) { return document.querySelector(a.getAttribute("href")); });
    var lastY = window.scrollY;
    var ticking = false;

    function update() {
      ticking = false;
      var y = window.scrollY;
      var max = document.documentElement.scrollHeight - window.innerHeight;
      if (progress) progress.style.transform = "scaleX(" + (max > 0 ? y / max : 0) + ")";

      if (header) {
        // tema (claro/escuro) conforme a seção sob o header
        var probe = 48, dark = true;
        for (var i = 0; i < themed.length; i++) {
          var r = themed[i].getBoundingClientRect();
          if (r.top <= probe && r.bottom > probe) {
            dark = themed[i].getAttribute("data-section-theme") === "dark";
            break;
          }
        }
        header.classList.toggle("header-dark", dark);

        // esconde ao descer, mostra ao subir
        if (y > 220 && y > lastY + 4) header.classList.add("header-hidden");
        else if (y < lastY - 4 || y <= 220) header.classList.remove("header-hidden");
      }
      lastY = y;

      // link ativo
      var current = -1;
      sections.forEach(function (s, idx) {
        if (s && s.getBoundingClientRect().top <= window.innerHeight * 0.4) current = idx;
      });
      navLinks.forEach(function (a, idx) { a.classList.toggle("active", idx === current); });
    }

    window.addEventListener("scroll", function () {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
    window.addEventListener("resize", update);
    update();
  });
})();
