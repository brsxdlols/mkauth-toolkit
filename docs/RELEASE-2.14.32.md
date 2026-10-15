# Pacote 2.14.32 — Mapa 1.3.33

Os totais do Mapa passam a respeitar **Clientes → Totais**, por meio de `permissao('perm_numClientes')`. A permissão `perm_totais` não concede mais acesso às quantidades de clientes.

Sem a permissão, o HTML não contém elementos de contagem e a API omite o resumo agregado. Agrupamentos no mapa, resultados de filtros, avisos e quantidades de clientes nas CTOs continuam protegidos pela mesma política. Os botões de situação permanecem disponíveis para filtrar os registros autorizados.

Grupos permitidos, autenticação e CSRF preservados. As configurações existentes do Telegram não são redefinidas. Recursos massivos continuam sujeitos à permissão de totais do operador configurado.

Validação: permissões antigas e novas em sentidos opostos, grupos em tabelas temporárias, API real com e sem permissão, HTML renderizado sem contadores e API do Telegram. Instalação sincroniza o monitor isolado pelo atualizador oficial da suíte e preserva as configurações locais.
