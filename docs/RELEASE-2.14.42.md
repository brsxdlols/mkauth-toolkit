# v2.14.42 — Salvar alertas sem teste prévio

Remove a exigência de teste prévio ou recente ao salvar preferências, inclusive com envio ativo e apenas queda massiva selecionada. Permite salvar sem cards selecionados. A ativação mantém sua verificação automática. Preserva as permissões, os grupos e o estado de ativação.

Cada salvamento envia uma confirmação curta com envio, alertas selecionados, contratos individuais e parâmetros massivos quando habilitados, sem token, Chat ID ou indicação técnica do fuso. Falha da confirmação não desfaz o salvamento.

Validação de API sem teste anterior, sem alertas selecionados, permissões, CSRF e falhas de entrega. Configurações preservadas e runtime atualizado pela suíte.
