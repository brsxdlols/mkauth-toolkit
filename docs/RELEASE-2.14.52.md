# v2.14.52 — Ativos e bloqueados no mapa e nove níveis de queda

Mapa 1.3.53 adiciona os botões Ativos (contratos sem bloqueio) e Bloqueados com quantidade e filtro ao clicar. Clique novamente para remover o filtro. Os botões combinam com Online/Offline, sincronizam com Situação do cadastro e respeitam a região selecionada. Cadastros desativados não entram nesses dois totais. A visualização dos números continua protegida pela permissão Totais.

Os níveis percentuais do Telegram agora incluem 10%, 20%, 30%, 40%, 50%, 60%, 70%, 80% e 90%, selecionáveis juntos. O campo de lote foi renomeado para Avisar a cada N retornos, com exemplo na ajuda: 10 avisa em 10, 20 e 30 recuperados. O encerramento continua separado pelo percentual configurado e estabilidade de 2 minutos.

Corrige também a supressão do clique após arrastar vértices, sem depender da velocidade de um temporizador. Guia e testes atualizados.

Validação: contadores ativos/bloqueados, filtros combinados, mapa/lista, região, cadastros desativados e permissão; Leaflet real; interface Telegram em cinco tamanhos; guia em quatro; PHP com nove níveis, não repetição e recuperação. Na SMART, a regra AppArmor da pasta privada foi reaplicada preservando os outros perfis; gravação, lock, rename e limpeza testados sob www-data nos perfis admin/central, sem enviar mensagens.
