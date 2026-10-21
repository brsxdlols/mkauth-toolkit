# v2.14.51 — Encerrar queda massiva por recuperação de 80%

Mapa 1.3.52 encerra a ocorrência depois de pelo menos 80% dos clientes originais estarem online por dois minutos de coletas contínuas. A resposta final fica vinculada ao alerta original e informa o percentual real, a quantidade recuperada e quantos continuam offline. Depois da confirmação de entrega, o acompanhamento daquela ocorrência é encerrado, sem aguardar 100%.

Oscilações abaixo de 80%, ausência de clientes no escopo autorizado ou intervalo sem coleta acima de 60 segundos reiniciam a confirmação. Falha de entrega mantém o incidente aberto para nova tentativa. Os retornos parciais anteriores ao critério continuam respeitando os lotes configurados.

Aplica-se também às ocorrências já abertas, após observar a estabilidade no monitor atualizado. Preserva configurações, grupos e permissões. Guia, ajuda e demonstração atualizados.

Validação: PHP com transporte simulado, 515/644 versus 516/644, estabilidade de 120 segundos, oscilações, escopo, lacuna de coleta, reinício, falha/reenvio, resposta original, encerramento sem aguardar os restantes e regressões de lotes/avisos individuais. Nenhuma mensagem real nos testes.
