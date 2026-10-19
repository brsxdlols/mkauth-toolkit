# v2.14.47 — Quantidades e mensagens para a região selecionada

O quadro da seleção exibe Selecionados, Online e Offline, atualizados com os mesmos clientes da lista e dos marcadores. Os agregados só aparecem com Clientes → Totais (perm_numClientes); ao perder a permissão, deixam o DOM.

Usuários com perm_mensagem e perm_clientes podem abrir WhatsApp, E-mail ou SMS no compositor nativo do MK-AUTH, com os destinatários da região. A composição exige confirmação manual na tela nativa para enviar; nenhum envio é automático. Canais não configurados ficam indisponíveis. O canal usa a configuração existente do MK-AUTH, inclusive Opções → WhatsApp → Mensagens.

O servidor valida CSRF, IDs, permissão e grupos autorizados novamente. Adicionais são vinculados ao cadastro principal e destinos repetidos são agrupados. Até 500 registros por composição. Parâmetros são enviados por POST; não são criados logs de contatos nem chamadas de disparo no addon.

Testes com Leaflet real e navegador cobrem contadores, remoção por permissão, botões, composição, canal e ausência de envio automático. Testes PHP cobrem CSRF, permissões, grupos, adicionais, deduplicação e rejeição de logins incompatíveis com a interface legada. Configurações preservadas e runtime atualizado pelo instalador oficial da suíte.
