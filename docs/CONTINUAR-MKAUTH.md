# Continuidade MK-Auth no Codex

Contexto reunido em 2026-10-01. As observacoes de servidores abaixo sao historicas e precisam de nova verificacao antes de editar producao.

## Repositorio

https://github.com/brsxdlols/mkauth-toolkit

Instalador: installers/install-radius-reconcile.sh

O instalador publicado foi comparado com a copia local nesta conversa: conteudo igual, blob GitHub e868071da5bf46ce2df971ad5b9865e95d64f40a.

## Instalacao

```sh
curl -fsSL https://raw.githubusercontent.com/brsxdlols/mkauth-toolkit/main/installers/install-radius-reconcile.sh -o /root/install-radius-reconcile.sh && chmod +x /root/install-radius-reconcile.sh && sh /root/install-radius-reconcile.sh
```

Alternativa:

```sh
wget -O /root/install-radius-reconcile.sh https://raw.githubusercontent.com/brsxdlols/mkauth-toolkit/main/installers/install-radius-reconcile.sh && chmod +x /root/install-radius-reconcile.sh && sh /root/install-radius-reconcile.sh
```

## Comportamento atual

- Consulta RouterOS API dos ramais cadastrados em nas, usuario mkauth, senha do ramal e fallback configuravel; porta padrao 8728.
- Filtra PPPoE autenticado por RADIUS e reconcilia sessoes em radacct. Nao pressupor que fecha todas as sessoes antigas: revisar o codigo para qualquer mudanca nesse comportamento.
- Instala cron a cada dois minutos e executa --apply ao final.
- Instala status e alerta de falha de API nas dashboards reconhecidas em /admin e /admin/addons/dashboard.
- Nao altera as queries de contagem online/offline da dashboard.
- Historicamente usado com PHP 7; compatibilidade com cada versao PHP 8 deve ser validada no ambiente alvo antes de distribuicao em massa.

## Caminhos e diagnostico

Script: /opt/mk-auth/scripts/mkauth_radius_ppp_reconcile.php

Cron: /etc/cron.d/mkauth-radius-ppp-reconcile

Log: /var/log/mkauth_radius_ppp_reconcile.log

Estado: /var/lib/mkauth_radius_ppp_reconcile/status.json

Backups do instalador: /root/mkauth_radius_reconcile_backup_*

```sh
php /opt/mk-auth/scripts/mkauth_radius_ppp_reconcile.php
php /opt/mk-auth/scripts/mkauth_radius_ppp_reconcile.php --apply
grep ROUTER_FAIL /var/log/mkauth_radius_ppp_reconcile.log | tail -n 20
```

## Historico relevante

Um patch antigo contou logins brutos de radacct, incluindo sessoes sem cadastro correspondente. Produziu online maior que total e offline negativo. O bloco foi removido do instalador. A contagem de principais e adicionais deve ser corrigida separadamente, com consultas vinculadas a sis_cliente e sis_adicional e validacao de logins distintos.

Nao executar automaticamente DELETE de duplicatas e ALTER UNIQUE em radacct: esses comandos anteriores removem historico e mudam o schema. Primeiro verificar indices, duplicatas, consistencia e backup restauravel. O pedido de migracao de notebook nao autoriza rodar esses comandos nos servidores.

O backup antigo de referencia do instalador veio da hospedagem lg.sistelfibra.com.br, caminho /var/files/install_mkauth_radius_reconcile-poup-up.sh; as credenciais devem ser fornecidas de forma privada.

## Servidores historicos

- clinicpnet: 45.172.144.97, SSH 6775, usuario root. Dashboard /opt/mk-auth/admin/index.hhvm, index.php e indexnovo.hhvm. Confirmar acesso atual.
- kmprovedor: 45.172.144.56, SSH 22, usuario root. Addon https://kmprovedor.net.br/admin/addons/radius/ e diretorio esperado /opt/mk-auth/admin/addons/radius.
- Banco historico: mkradius; credenciais devem ser confirmadas e fornecidas em privado.

Nenhuma senha e publicada neste documento.

## Proximo trabalho

Revitalizar Radius Logs v4.2 do kmprovedor. Primeiro inspecionar via SSH e fazer backup dos arquivos. Melhorar filtros, leitura dos erros e permitir clicar no login para pesquisar ou abrir o cliente usando os endpoints reais do MK-Auth. Entender RUN, STOP e Limpar Conexoes Presas antes de alterar comandos. Manter o reconciliador e a contagem da dashboard fora do escopo dessa revitalizacao, salvo pedido expresso.

Chat dedicado criado anteriormente: 019f9b5e-9daf-7ab2-8997-ea05b0e0852f. Esse ID e uma referencia; disponibilidade no outro notebook depende da conta e da sincronizacao do aplicativo.

## Preparacao do outro notebook

Entrar no Codex com a mesma conta. Para obter o codigo:

```sh
git clone https://github.com/brsxdlols/mkauth-toolkit.git
```

Abrir a pasta como projeto e fornecer este documento ao Codex. Fornecer credenciais atuais de SSH em privado quando precisar acessar os servidores. O clone traz o codigo, mas nao copia arquivos locais, sessoes SSH ou todo o historico do chat.

Prompt sugerido:

> Leia docs/CONTINUAR-MKAUTH.md e installers/install-radius-reconcile.sh. Continue o trabalho MK-Auth a partir desse contexto. Primeiro confirme o estado atual e os backups. O proximo objetivo e revitalizar o addon Radius Logs do kmprovedor com login clicavel para localizar o cliente. Nao reintroduza o patch de contagem bruta de radacct. Vou fornecer as credenciais atuais em privado.
