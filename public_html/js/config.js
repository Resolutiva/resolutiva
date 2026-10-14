/* ============================================================
   Resolutiva — configuração da landing (edite aqui)
   Arquivo: js/config.js
   Depois de alterar, publique este arquivo e suba o número da versão
   (?v=) no <script> do index.html para limpar o cache.
   ============================================================ */
window.RV_CONFIG = {
  // Seção "Avaliações" (nota e depoimentos do Google, vindos do ResolutivaPages).
  // false = a seção não aparece e nenhuma chamada à API é feita.
  googleReviews: true,

  // Quantos depoimentos mostrar e a nota mínima (em estrelas) para exibir.
  maxReviews: 3,
  minStars: 4,

  // Seção "Instagram" (últimas postagens, vindas do ResolutivaPages), antes do rodapé.
  // false = a seção não aparece e nenhuma chamada à API é feita.
  instagram: true,

  // Quantas postagens carregar (1 a 12; no celular aparecem todas, em 3 colunas).
  // O ResolutivaPages também limita pelo que o bloco permite.
  instagramPosts: 6,

  // Quantas aparecem no computador (as demais ficam só no celular).
  instagramPostsDesktop: 4,

  // Opcional: outra landing do ResolutivaPages como fonte (vazio = a do data-endpoint do index.html).
  // Ex.: "https://pages.resolutiva.com.br/api/v1/landing/OUTRO-SLUG/instagram"
  instagramEndpoint: ""
};
