# Pacote 2.14.30 — Mapa de Clientes 1.3.31

O Mapa e o monitor isolado da Dashboard/Busca passam a receber a mesma base publicada. Quando o atualizador da suíte estiver instalado, o instalador do Mapa o executa como root com um novo diretório de backup. A entrada gerenciada `suite-map-monitor-entry-v2` e as configurações locais são preservadas. Falhas na sincronização são informadas como erro; o runtime nunca é editado manualmente.

- Geocodificação usa URLs absolutas, inclusive dentro do monitor e de modais. Configurações locais são preservadas.
- AMPLIAR no Mapa retorna à posição e ao cliente anteriores, permitindo ampliar novamente. A entrada direta do monitor mantém sua navegação própria. Mobile recebe o retorno ao MK-AUTH.
- Instalação e primeira abertura de cada versão usam casas sem agrupamento e filtros ativos limpos. Mapa/Satélite, tema escolhido e filtros nomeados permanecem salvos; o tema inicial é branco.
- Telegram recebe configuração por abas, guia ilustrado, relógio no fuso escolhido, testes e um único controle de ativação. O conteúdo se divide em etapas quando a tela é pequena, sem rolagem interna ou campos cortados.
- Alertas individuais mostram Nome, contrato, CTO/porta quando cadastrados, rua e bairro. O retorno responde à mensagem da queda quando seu identificador está salvo. Horários usam o evento RADIUS disponível, com indicação de detecção quando não houver horário do evento.
- Queda massiva conta contratos ativos e bloqueados dos grupos autorizados e resume bairros, CTOs, ruas e período. O filtro de contratos controla somente alertas individuais. As demonstrações são fictícias e identificadas como TESTE.
- Coleta Telegram independente do navegador a cada 15 segundos, fila persistente, respostas persistentes e proteção contra duplicação em novas tentativas. A disponibilidade do evento continua dependendo da atualização no RADIUS e da entrega pelo Telegram; não há garantia de latência zero.

Validação: PHP no Visualnet; autorização, grupos, CSRF, mensagens e respostas com transporte simulado; instalador isolado com preservação de configuração, ausência/falha do atualizador e recriação pelo atualizador oficial; gráfico desktop/mobile; modal em 360×568, 360×640, 400×824, 1366×768 e 1600×731; padrões por versão com preservação de tema/camada/presets.

Não inclui credenciais de provedores ou do Telegram. Mensagens anteriores sem identificador salvo não recebem resposta retroativa.
