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

  // ===========================
  // Formulário de contato (FormSubmit)
  // ===========================
  document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("contato-form");
    if (!form) return;

    var success = document.getElementById("mensagem-sucesso");
    var error = document.getElementById("mensagem-erro");

    form.addEventListener("submit", function (e) {
      e.preventDefault();

      // Confirmação de política
      var chk = document.getElementById("politicaPrivacidade");
      if (chk && !chk.checked) {
        if (error) {
          error.innerText = "Você precisa aceitar a Política de Privacidade para enviar.";
          error.classList.remove("d-none");
        }
        return;
      }

      if (error) error.classList.add("d-none");

      var data = new FormData(form);

      fetch(form.action, {
        method: form.method || "POST",
        body: data,
        headers: { "Accept": "application/json" }
      })
        .then(function (response) {
          if (!response.ok) throw new Error("Falha no envio");
          // sucesso
          form.classList.add("d-none");
          if (success) {
            success.classList.remove("d-none");
            success.scrollIntoView({ behavior: "smooth", block: "start" });
          }
        })
        .catch(function () {
          if (error) {
            error.innerText = "Erro ao enviar. Tente novamente em alguns instantes.";
            error.classList.remove("d-none");
            error.scrollIntoView({ behavior: "smooth", block: "start" });
          } else {
            alert("Erro ao enviar. Tente novamente.");
          }
        });
    });
  });
})();
