# Telegram do Mapa de Clientes — guia e comandos Linux

No mapa, atualize a página e abra a engrenagem. O botão **Guia: criar bot e grupo** explica como usar Telegram Web e BotFather, copiar o token, adicionar o bot ao grupo e sincronizar. Dentro do grupo, envie `/start@NomeDoSeuBot` (ou `/start`) antes de sincronizar. O token salvo fica visível no painel para operadores com as permissões atuais de configuração e clientes.

Os comandos abaixo são executados como root no servidor Linux. O programa roda como www-data para manter as permissões dos arquivos privados. Usa a configuração já salva; não exige colar o token no terminal.

## Diagnóstico sem enviar mensagens

```sh
TG=/opt/mk-auth/admin/addons/mapa-clientes/telegram_cli.php
runuser -u www-data -- php "$TG" status
runuser -u www-data -- php "$TG" check
runuser -u www-data -- php "$TG" groups
cat /etc/cron.d/mkauth-map-telegram
```

`status` mostra ativação, grupo, último processamento, último alerta automático, fila e erro; não mostra o token. `check` consulta bot, participação no grupo e permissão de envio. `groups` procura grupos recentes, sem remover webhook ou confirmar atualizações de outras integrações. Se a sincronização não funcionar com um bot compartilhado, use Chat ID manual.

## Enviar demonstrações ao grupo salvo

Cada comando envia uma mensagem identificada como **TESTE**, sem alterar, conectar ou desconectar clientes reais. As respostas precisam de um teste de queda anterior no mesmo bot/grupo, com menos de 24 horas.

```sh
TG=/opt/mk-auth/admin/addons/mapa-clientes/telegram_cli.php

# Cliente conectou (exemplo independente)
runuser -u www-data -- php "$TG" demo connect --send

# Desconexão e resposta indicando recuperação individual
runuser -u www-data -- php "$TG" demo disconnect --send
runuser -u www-data -- php "$TG" demo recovered --send

# Ocorrência de 10 clientes: queda, resposta 8/10, resposta 10/10
runuser -u www-data -- php "$TG" demo mass --send
runuser -u www-data -- php "$TG" demo partial --send
runuser -u www-data -- php "$TG" demo resolved --send
```

Para executar a sequência completa com intervalo entre os envios:

```sh
TG=/opt/mk-auth/admin/addons/mapa-clientes/telegram_cli.php
for exemplo in connect disconnect recovered mass partial resolved; do
    runuser -u www-data -- php "$TG" demo "$exemplo" --send || break
    sleep 2
done
```

Os seis botões em **Demonstrações no grupo** fazem os mesmos testes. Recuperações de demonstração são respostas à mensagem original de demonstração; não representam acompanhamento automático de incidentes reais.

## Desativar envios automáticos

```sh
runuser -u www-data -- php /opt/mk-auth/admin/addons/mapa-clientes/telegram_cli.php disable
```

Isso altera a configuração para desativada, preserva o token/grupo e tenta avisar o grupo. Mesmo se o aviso falhar, a desativação permanece salva. Testes manuais continuam disponíveis. Para reativar, use a engrenagem, faça o teste se solicitado, salve os ajustes e clique no botão Ativar.

Cada salvamento envia um resumo das configurações ao grupo. O botão único Ativar/Desativar altera imediatamente o envio e também envia uma confirmação. Salvar configuração preserva a situação atual do envio. Uma requisição de alerta que já estava em trânsito pode terminar antes da desativação.

Referências: [Tutorial oficial de criação do bot e token](https://core.telegram.org/bots/tutorial#obtain-your-bot-token), [Telegram Web](https://web.telegram.org/), [BotFather](https://t.me/BotFather), [Bot API](https://core.telegram.org/bots/api).

## Novas opções

Na engrenagem, escolha o fuso dos alertas e quais contratos notificam, marcando Ativos e/ou Bloqueados. Salve a configuração. Rua e número aparecem quando cadastrados; a reconexão informa a duração offline quando conhecida, ou identifica a estimativa observada pelo monitor.

O mapa atualiza por padrão a cada 15 segundos e consulta ao retornar à aba. O Telegram coleta no servidor a cada 15 segundos, sem exigir duas leituras e sem depender de navegador aberto; depende da atualização RADIUS. Não envia desconexões anteriores à leitura inicial.

## Histórico de detecção e entrega

```sh
TG=/opt/mk-auth/admin/addons/mapa-clientes/telegram_cli.php
runuser -u www-data -- php "$TG" status
runuser -u www-data -- php "$TG" history
```

O histórico informa os horários de detecção e entrega aceita pela API. A configuração tem relógio ao vivo no fuso selecionado. Cada salvamento envia um resumo ao grupo, sem incluir o token.

Os avisos mostram Nome e Contrato com ícone, além do horário registrado no RADIUS e do horário Detectado em, separados e identificados.

## Respostas e resumo por localização

O retorno individual responde à mensagem da queda quando ela foi enviada após a instalação do vínculo de respostas. Para demonstrar, envie primeiro Desconectou e depois Retorno do cliente.

Ativos/Bloqueados filtra os avisos individuais; a massiva contabiliza os dois grupos, dentro das permissões do operador. Os avisos não exibem UTC ou nome do fuso: mostram a hora principal da ocorrência e, na massiva, o período. A seleção de fuso nas configurações continua aplicada.
