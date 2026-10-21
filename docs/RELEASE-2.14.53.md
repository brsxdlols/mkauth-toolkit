# v2.14.53 — Imagem de satélite no ajuste de coordenadas

Mapa 1.3.54 corrige o mini mapa de Pesquisar e adicionar coordenadas. O satélite agora amplia a imagem disponível até o zoom visual 21, usando o nível nativo máximo 17, como o mapa principal. Evita pedir imagens inexistentes nos níveis 18/19.

A fonte informa ausência de cobertura como erro HTTP (blankTile=false). Em falha, a janela exibe Mapa automaticamente e informa o ocorrido. Mantém latitude, longitude, marcador, centro e zoom selecionados. Os botões Mapa/Satélite continuam sincronizados e o usuário pode tentar Satélite novamente.

Validação nas coordenadas da imagem reportada: níveis 15/16/17 disponíveis, 18/19 ausentes. Teste com Leaflet real confirmou ampliação 18/21, limite de requisições em 17, fallback de tile HTTP 404 sem alteração dos campos, arrasto do marcador e clique no mapa. Configurações locais preservadas.
