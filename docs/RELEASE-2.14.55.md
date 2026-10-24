# v2.14.55 — Acrescentar áreas e separar os filtros de contrato

Mapa 1.3.55 revela temporariamente os clientes ao pressionar Espaço para acrescentar uma nova área. Mantém as áreas e os pontos ajustados, mostrando os clientes que atendem aos demais filtros e permissões. Ao concluir o retângulo ou polígono, mapa e lista voltam a exibir somente a união das regiões selecionadas.

Soltar Espaço sem iniciar um desenho restaura o filtro anterior. No polígono em andamento, os candidatos continuam visíveis até fechar ou cancelar. Cancelar, perder o foco ou interromper o gesto restaura a seleção confirmada. Contadores e resumo continuam representando as áreas confirmadas durante a edição.

Ações de mensagens ficam desabilitadas durante a visualização temporária, e o envio ao compositor também é bloqueado nessa situação. Evita incluir clientes que ainda não foram selecionados.

Testes com Leaflet real: revelação imediata, regiões preservadas, união sem duplicação, fim do gesto, polígono, cancelamento, navegação, permissões e bloqueio da composição durante o desenho. Guia atualizado; configurações locais preservadas.

Remove os cards Ativos/Bloqueados. A escolha de contrato fica em Filtros → Logins → Contrato, com Liberados, Bloqueados, ambos, Desativados e Todos, e quantidades em cada opção para quem possui Totais. Os quatro cards de conexão permanecem. Preserva os filtros já aplicados e explica a diferença entre bloqueio e conexão.
