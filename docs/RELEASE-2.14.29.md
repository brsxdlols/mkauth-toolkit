Mapa de clientes 1.3.30

- Entrada principal do tráfego: `/admin/addons/mapa-clientes/monitor/`, com `?login=LOGIN`. Compatibilidade com `?monitor=1` preservada.
- AMPLIAR e menu usam a nova entrada; o instalador migra os atalhos opcionais de configuração com backup e preserva os links usados pela Busca.
- A entrada delega ao index.hhvm nativo, preserva autenticação e acrescenta base para assets e APIs da pasta original.
- Incorpora gráfico da suíte Dashboard + Busca (commit 459bf4d8031781dbae8a5e90e5ab0f23a4e34c76): altura acompanhando os dados no desktop, SVG dimensionado, valores finais Download azul/Upload vermelho em Mbps, preenchimento 0,16 e tooltips com horário e ambos os valores.
- Permissão `permissao('perm_totais')` protege cartões, resultados dos filtros, agrupamentos, avisos agregados e resumo da API, sem remover filtros ou registros autorizados.
- Consultas de clientes, adicionais, tráfego, CTO e ajuste de coordenadas respeitam grupos do operador. Dispositivos autorizados guardam a identidade para reavaliar permissões atuais; autorizações antigas sem operador exigem login administrativo para renovar.
- Mapa/Satélite do percurso acompanha a camada principal imediatamente, inclusive no quadro ampliado.

Validação: PHP 8.0 no Visualnet; testes de Totais concedido/negado, grupos e identidade; filtros existentes; gráfico em 1440×900 e 390×844; seleção por login e rota antiga; teste de troca Mapa/Satélite. A conferência final no ambiente real com as contas dos operadores cabe ao usuário.
