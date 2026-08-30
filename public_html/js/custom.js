/* ============================================================
   Resolutiva - JS customizado
   Arquivo: js/custom.js
   ============================================================ */

(function () {
  "use strict";

  // ===========================
  // Cookie Banner
  // ===========================
  function aceitarCookies() {
    localStorage.setItem("cookies_aceitos", "sim");
    var el = document.getElementById("cookie-banner");
    if (el) el.style.display = "none";
  }
  window.aceitarCookies = aceitarCookies;

  document.addEventListener("DOMContentLoaded", function () {
    var banner = document.getElementById("cookie-banner");
    if (banner && !localStorage.getItem("cookies_aceitos")) {
      banner.style.display = "block";
    }
  });

})();
