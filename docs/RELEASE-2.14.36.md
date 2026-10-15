# Pacote 2.14.36 — Mapa 1.3.37

Corrige a configuração do Telegram em MK-AUTH com PHP protegido por AppArmor. O teste podia entregar a mensagem ao grupo e, em seguida, falhar ao salvar a verificação ou a configuração por falta de acesso à pasta privada.

- Instalador adiciona um include local nos perfis nativos PHP admin/central, com regras `owner` limitadas à pasta privada do Telegram. Mantém AppArmor ativo, faz backup e restaura os arquivos se houver falha ao recarregar o perfil.
- Corrige dono e modo dos JSONs e locks existentes, preservando conteúdo. Configuração e tokens continuam fora da pasta web.
- Selecionar um grupo preenche o Chat ID. Se houver somente um grupo e o campo estiver vazio, a sincronização seleciona esse destino. Seleções restauradas pelo navegador também são consideradas ao enviar o formulário. Chat ID digitado manualmente é preservado.
- Erros de permissão ao salvar passam a ter orientação clara; configuração simultânea informa para aguardar.
- Atualiza a identificação de cache do JavaScript do Telegram. Assets compartilhados do monitor usam a data real do arquivo para não substituir a identificação de cache usada pela suíte.

Validação: falha real de escrita pelo PHP-FPM confinado da FlippNet antes da correção e sucesso depois; regras restritas, idempotência e rollback do AppArmor; sincronização com um/múltiplos grupos, seleção restaurada e Chat ID manual; API com permissões, CSRF, ativação/desativação e configuração; desktop/mobile em cinco tamanhos. Nenhum teste envia mensagens reais sem ação do operador.
