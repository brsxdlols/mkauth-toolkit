# v2.14.45 — Retorno real de quedas massivas no Telegram

O monitor real passa a persistir os clientes e o identificador da mensagem de cada queda massiva entregue. Quando a quantidade online muda, envia uma resposta ao alerta original com quantos voltaram e quantos continuam offline; na recuperação completa, responde NORMALIZADA e encerra a ocorrência.

O acompanhamento funciona mesmo sem avisos individuais, conta ativos e bloqueados e respeita os grupos autorizados. Persiste após reiniciar o worker, deduplica tentativas, mantém o estado em falhas de entrega e substitui atualizações pendentes por dados atuais. Limita as ocorrências persistidas para evitar crescimento indefinido.

As mensagens antigas não armazenaram a lista de participantes; não é possível reconstruir seus retornos com segurança. Acompanhamento vale para quedas entregues após esta atualização. Se a mensagem original tiver sido removida, a recuperação não é enviada como mensagem avulsa.

Validação de respostas parciais/completas, reinício, falha/repetição, oscilações e isolamento por cliente/grupo. Configurações locais preservadas e runtime atualizado pela suíte.
