# v2.14.59 - Preservar o estado do Telegram

Arquivos temporários de coleta vazios ou inválidos não impedem mais a abertura das configurações do Telegram. Runtime e amostras são reconstruídos na próxima coleta; credenciais e histórico de mensagens continuam falhando de forma segura se inválidos, sem redefinição automática.

A gravação agora exige que todos os bytes sejam escritos e relidos corretamente antes da substituição atômica do arquivo. Escrita curta ou falha de codificação preserva o estado válido anterior.

Na FlippNet, configuração, bot, grupo e histórico estavam válidos; runtime e transitions estavam vazios. Foi feito backup privado e reconstrução apenas desses dois arquivos. Coleta retomada, com configuração inalterada e fila sem erro. A causa original do esvaziamento não foi determinada.

Validação em PHP Linux: recuperação de estado temporário, bloqueio de histórico inválido, falha de codificação e escrita parcial real limitada com ulimit. Detecção existente de alertas também passou, sem mensagens reais de teste.
