# Telegram — Mapa de Clientes

A configuração exige autenticação nativa e permissões de configuração e clientes. Queda massiva exige Totais. Os grupos de clientes permitidos continuam aplicados ao monitor independente do navegador.

O modal tem abas Conexão, Alertas, Testes e Guia ilustrado. O guia tem seis passos com desenhos, Anterior e Próximo, incluindo Telegram Web, BotFather, criação do bot, token, grupo, /start no grupo e teste antes de ativar. Token salvo fica visível somente aos operadores autorizados no painel/API; nunca integra código, pacote, histórico de eventos ou mensagem de configuração.

Cada salvamento envia ao grupo um resumo: situação do envio automático, grupo/Chat ID, alertas selecionados, contratos monitorados, quantidade e intervalo para queda massiva, fuso e horário do salvamento. Não inclui o token. Usar o botão Desativar informa que o envio foi desativado. Falha de confirmação não impede salvar ou desativar; aparece no painel. Um pedido já em trânsito pode ser entregue durante a desativação.

O relógio do painel atualiza a cada segundo no fuso selecionado e mostra data/hora, diferença UTC e identificador IANA. Curitiba: America/Sao_Paulo; Manaus: America/Manaus; oeste do Amazonas: America/Eirunepe. Isso não muda o relógio do MK-AUTH/banco.

Avisos usam CLIENTE CONECTADO / CLIENTE DESCONECTADO, com rua, número e bairro quando disponíveis. Reconexão informa duração offline por horários coerentes do RADIUS; observação do monitor é identificada como estimada e dados insuficientes omitem a duração. As caixas Ativos/Bloqueados filtram somente os avisos individuais. Quedas massivas contam ambos, sempre dentro dos grupos permitidos ao operador, considerando novas transições para offline.

## Coleta e entrega

telegram_scheduler.php é um processo CLI persistente de www-data, supervisionado pelo cron uma vez por minuto. Mantém trava exclusiva para não duplicar coletores. Chama telegram_worker.php a cada 15 segundos, descontando a duração da execução; chamadas longas de banco/rede podem alongar esse intervalo. O processo não depende de página aberta. Se morrer, o próximo cron o recria. O worker mantém sua própria trava e revalida habilitação/configuração antes de cada envio.

Não há confirmação em duas leituras: a mudança encontrada no banco gera evento na própria coleta. O mapa também mostra a mudança na primeira atualização e usa 15 segundos por padrão. Timers de abas ocultas podem sofrer limitação do navegador; retornar à aba força atualização. RADIUS/accounting é a fonte: esse polling não garante capturar toda oscilação que comece e termine entre duas leituras, nem informar uma queda física antes de o NAS atualizar o banco.

A primeira ativação cria referência sem histórico. Salvar novamente não reinicia a referência. Mudança de operador ou dos grupos permitidos cria nova referência. Alterar o filtro individual de contratos não reinicia a referência massiva. Desativar limpa a referência quando o worker constata a desativação. Intervalo sem coleta superior a cinco minutos reinicia a referência para não emitir histórico como ocorrência nova.

A fila persiste em disco, com prioridade massiva, até três envios por execução, até cinco tentativas, limite de 1000 registros e validade de uma hora. Telegram 429 aguarda 120 segundos; outras falhas aguardam 60. Há risco de repetição quando Telegram aceita mas a resposta se perde. Histórico privado registra detecção, tentativa e entrega aceita pela API, sem significar leitura pelo destinatário. CLI history mostra as últimas 20 ocorrências. A API retorna diagnóstico da última coleta, duração, fila e erro.

Estado privado: /var/lib/mkauth-map-telegram, diretório 0700 e arquivos 0600 de www-data. Cron: /etc/cron.d/mkauth-map-telegram. Instalação preserva as credenciais e faz backup. PHP CLI/mysqli/cURL com certificados e acesso HTTPS são necessários; shell_exec não é usado, mas passthru precisa estar habilitado para executar o worker.

Os seis botões de demonstração enviam dados fictícios marcados TESTE. Respostas de recuperação ficam ligadas ao teste original. Retornos individuais reais respondem ao aviso original da queda. Recuperações massivas automáticas continuam fora deste ajuste.

## Contratos, horários e botão único

Contratos agora usa caixas independentes Ativos (não bloqueados) e Bloqueados. Marcar ambas equivale a todos os contratos ativos do cadastro; pelo menos uma precisa estar marcada para salvar. A seleção anterior é preservada na atualização e passa a aplicar-se apenas aos avisos individuais.

O botão único Ativar/Desativar altera imediatamente o envio usando os ajustes já salvos. Salvar configuração não alterna essa situação. A ativação valida token, grupo, teste anterior e permissões; a desativação continua possível mesmo com credenciais inválidas. A situação é verde quando ativada e vermelha quando desativada.

Os avisos têm Nome: e Contrato: ✅ Ativo ou 🔒 Bloqueado. O texto mostra somente a hora principal da sessão registrada no RADIUS. Sem registro válido, a hora é identificada como detectada. A data é exibida sem UTC ou nome do fuso; os horários de diagnóstico são mantidos internamente. O horário RADIUS não garante o instante físico da queda nem o instante em que o servidor recebeu o registro. A formatação usa o fuso configurado, sem exibi-lo nos alertas.

## Respostas, localização e texto resumido

telegram_delivery.php persiste em delivery.json o identificador retornado pelo Telegram para cada queda individual. O retorno usa reply_parameters apontando para aquela mensagem, com isolamento por bot, grupo e chave do cliente. Não associa retorno a queda posterior. Reiniciar o coletor mantém os vínculos. O histórico registra message_id/reply_to e os horários internos, sem mostrar detalhes técnicos extras nos avisos.

O cache de até 5000 eventos enviados evita repetir eventos cuja confirmação já foi persistida. Falhas anteriores à persistência da confirmação ainda podem provocar repetição, como nas demais requisições HTTP. Se a mensagem da queda foi apagada, allow_sending_without_reply preserva o envio do retorno, sem obrigar um vínculo impossível. Avisos anteriores à instalação deste mecanismo não têm ID armazenado e não podem ser vinculados retroativamente. Sem alerta de queda enviado, a conexão é enviada avulsa, se selecionada.

Referência de resposta: [Telegram Bot API — ReplyParameters](https://core.telegram.org/bots/api#replyparameters).

Avisos individuais priorizam CTO/porta, bairro e endereço, seguidos de Nome, Login e Contrato. Exibem somente a data e hora principal: Queda/Conexão registrada quando existe timestamp RADIUS válido; Queda/Conexão detectada quando não existe. Não exibem UTC, offset, identificador IANA ou uma segunda hora de detecção. Os horários internos permanecem no diagnóstico.

Queda massiva inclui a contagem de ativos/bloqueados, distribuição por bairro/CTO e ruas cadastradas, seguida do período das quedas. Uma CTO compartilhada entre bairros aparece em cada um com a quantidade correspondente; cada cliente entra uma vez. Dados ausentes são identificados e nenhuma CTO é deduzida por proximidade. Resumos grandes são limitados para caber numa mensagem, preservando o total e o período. A distribuição das desconexões não confirma falha física na CTO.

Os exemplos individuais e de queda massiva usam o mesmo formatador dos avisos reais e continuam marcados TESTE. O exemplo massivo permanece fictício com dez clientes.
