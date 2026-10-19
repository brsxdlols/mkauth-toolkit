# v2.14.46 — Seleção geográfica e retornos massivos em lotes

Segurar Espaço e arrastar dentro do Mapa desenha um retângulo Leaflet. A região filtra os mesmos clientes nos marcadores e na lista, em conjunto com os filtros atuais. Esc e Limpar região removem a região. As instruções ficam visíveis no Mapa. A navegação original é restaurada ao soltar Espaço ou perder foco; campos de texto e controles de formulário mantêm seu funcionamento. Atualizações periódicas preservam a região enquanto a página permanece aberta.

Os retornos de quedas massivas passam a respeitar o mínimo configurado como lote: mínimo 10 gera novos avisos ao atingir 10, 20 etc. recuperados. A normalização total sempre é enviada, inclusive com lote final menor. Decréscimos e oscilações de um cliente não geram mensagens repetidas. O acompanhamento permanece vinculado à mensagem original.

Validação com Leaflet real: seleção em ambos os sentidos, interseção de filtros, Esc/limpeza, teclado, perda de foco e navegação. Testes de recuperação 10/20/23, mínimo atualizado e preservação dos vínculos. Instalação inclui o novo script, preserva configurações e atualiza o runtime pela suíte.
