# v2.14.51 — Níveis percentuais e encerramento da queda massiva

Mapa 1.3.52 adiciona, em Telegram → Alertas, disparo por quantidade ou percentuais da base monitorada. Os níveis 10%, 20%, 50%, 70% e 80% podem ser marcados juntos. Cada nível atingido gera uma ocorrência independente com os clientes afetados naquele momento, sem repetir o mesmo nível para as mesmas quedas dentro da janela.

A base percentual considera todos os contratos ativos e bloqueados no escopo autorizado do operador, com ou sem coordenadas. Filtros visuais e regiões temporárias do mapa não alteram essa base. Só desconexões detectadas na janela e ainda offline entram no percentual.

A opção Encerrar quando voltar permite 70%, 80%, 90% ou 100% dos afetados; padrão recomendado de 80%. Cada ocorrência preserva o critério definido ao abrir. Depois de dois minutos de coletas contínuas acima do percentual, envia a resposta final na mensagem original, informando o percentual e os clientes ainda offline. Encerra após a entrega, sem continuar aguardando os restantes.

Oscilações abaixo do limite, perda de escopo ou intervalo entre coletas acima de 60 segundos reiniciam a estabilidade. Falha de entrega mantém o incidente aberto. Retornos parciais respeitam o lote de clientes configurado. As ocorrências antigas usam 80% de recuperação. Configurações existentes de disparo por quantidade, grupos e permissões são preservadas.

Guia, ajuda, demonstração e resumo de salvamento atualizados. Validação: níveis múltiplos, independência dos incidentes, arredondamento exato (515/644 e 516/644), estabilidade de 120 segundos, oscilação, escopo, lacunas, reenvio, resposta original e encerramento; interface em cinco tamanhos de tela e guia em quatro. Nenhuma mensagem real nos testes.
