# Patch MK-AUTH — bloqueio/desbloqueio de logins adicionais

Data da implementação: 2026-08-08
Ambiente validado: instalação MK-AUTH com MariaDB 10.3 e MikroTik RouterOS

## Resultado

O patch foi instalado e validado em ambiente de produção controlado. Bloqueios e desbloqueios do login principal agora são propagados aos registros vinculados em `sis_adicional`, com atualização imediata do estado/RADIUS e disconnect assíncrono em até um minuto.

Configuração detectada no ambiente:

- `pgcorte=rad`
- `pbloqradius=pool`
- `tbloqradius=pool`
- `bloq6=sim`

O patch também aceita o modo `list`/`lista`/`address-list` sem precisar ser alterado.

## Diagnóstico técnico

O ponto exato da falha é o trigger oficial `mkradius.tig_cliente`, executado depois de updates em `sis_cliente`.

O trigger original usa exclusivamente `new.login`:

```sql
UPDATE radcheck SET ativo = 'n' WHERE username = new.login;
UPDATE radcheck SET value = senha_atual
 WHERE attribute = 'Password' AND username = new.login;
```

Ele não consulta `sis_adicional`, não atualiza `sis_adicional.bloqueado`, não altera os atributos RADIUS dos adicionais e não solicita o disconnect deles. Os scripts oficiais `corte.php`, `liberar.php`, `pgcorte.php` e `desloga.php` estão empacotados pelo MK-AUTH; editar esses arquivos diretamente seria frágil e sujeito a sobrescrita em atualização.

No ambiente validado, o bloqueio do principal em modo pool é representado por:

- grupo `radusergroup=Bloqueio`;
- remoção de `Framed-IP-Address`;
- `Framed-Pool=pgcorte`;
- `Mikrotik-Delegated-IPv6-Pool=pgcorte` quando `bloq6=sim`;
- disconnect para nova autenticação.

No desbloqueio, o grupo/plano e o IP ou pool original são restaurados.

## Objetos instalados

Banco `mkradius`:

- trigger `tig_cliente_adicionais_bloqueio`;
- procedure `sp_mkauth_sync_adicionais_bloqueio`;
- tabela/fila `mkauth_adicional_block_queue`.

Sistema:

- `/opt/mk-auth/scripts/mkauth_additional_block_worker.php`;
- `/etc/cron.d/mkauth-additional-block` — execução a cada minuto;
- log `/var/log/mkauth_additional_block.log`.

O trigger oficial `tig_cliente` permaneceu intacto. O patch usa um segundo trigger para evitar conflito com o core.

## Comportamento implementado

Ao mudar `sis_cliente.bloqueado`:

1. Localiza todos os adicionais por `sis_adicional.login = sis_cliente.login`.
2. Replica `sim`/`nao` em `sis_adicional.bloqueado`.
3. Lê o modo atual do MK-AUTH (`pbloqradius` para PPPoE e `tbloqradius` para Hotspot).
4. No modo pool:
   - troca para o plano de bloqueio do adicional, com fallback para o plano de bloqueio do principal;
   - remove IP/pool normal;
   - grava `Framed-Pool=pgcorte` e a pool IPv6 de corte.
5. No modo address-list:
   - mantém o IP normal;
   - grava `Mikrotik-Address-List := pgcorte`.
6. No desbloqueio:
   - remove atributos `pgcorte`;
   - restaura `sis_adicional.plano`, IP, pool e IPv6;
   - reativa `radcheck.ativo`.
7. Enfileira disconnect transacionalmente.
8. O worker envia `Disconnect-Request` ao NAS na porta 3799. Se não houver resposta/ACK, usa a API do MikroTik para remover a sessão PPPoE/Hotspot ativa.

## Validação realizada

### Sintaxe e instalação

- `php -l`: sem erros.
- Trigger, procedure, fila, worker e cron confirmados no servidor.
- Backup pré-instalação criado em `/opt/mk-auth/backups/additional-block-patch-AAAAMMDD-HHMMSS`.

### Teste transacional de bloqueio/desbloqueio

Foi usado um principal com dois adicionais, dentro de uma transação finalizada com `ROLLBACK`. Identificadores e endereços do assinante foram omitidos deste repositório.

Modo pool:

- ambos passaram de `bloqueado=nao` para `sim`;
- grupo mudou de `FIBRA_RAMAL` para `Bloqueio`;
- `Framed-IP-Address` foi removido;
- `Framed-Pool=pgcorte` e `Mikrotik-Delegated-IPv6-Pool=pgcorte` foram criados;
- uma fila `pending` foi criada para cada adicional.

Desbloqueio na mesma transação:

- ambos voltaram a `bloqueado=nao`;
- grupo `FIBRA_RAMAL` restaurado;
- os dois IPs originais foram restaurados;
- fila atualizada para estado desejado `nao`.

Modo address-list simulado na transação:

- IPs fixos foram mantidos;
- grupo mudou para `Bloqueio`;
- ambos receberam `Mikrotik-Address-List := pgcorte`.

Após `ROLLBACK`:

- configuração real voltou a `pbloqradius=pool`;
- principal e adicionais permaneceram desbloqueados;
- plano e IPs originais permaneceram intactos;
- nenhuma linha de teste ficou na fila.

### Teste real do worker/disconnect

Foi enfileirado um disconnect controlado de um adicional, sem alterar seu estado financeiro. Resultado anonimizado:

```text
OK principal=<principal> adicional=<adicional> state=nao <nas>:radius_ack
```

O MikroTik respondeu `Disconnect-ACK`. Depois do teste:

- principal: `bloqueado=nao`;
- adicional: `bloqueado=nao`;
- grupo/plano original restaurado;
- IP original restaurado;
- fila: `done`, uma tentativa.

Não foi mantido nenhum cliente real em estado bloqueado durante a validação.

### Auditoria final

- divergências principal/adicional: `0`;
- itens `pending`, `processing` ou `failed`: `0`;
- atributos `pgcorte` duplicados: `0`;
- quatro MikroTiks acessíveis pelo reconciliador existente: `routers_ok=4`, `routers_fail=0`;
- trigger oficial confirmado sem alteração.

## Aplicação em outro servidor compatível

Copiar estes três arquivos para o mesmo diretório:

- `mkauth_additional_block_patch.sql`;
- `mkauth_additional_block_worker.php`;
- `install_patch.sh`.

Depois executar como root:

```bash
chmod 0750 install_patch.sh
./install_patch.sh
```

Verificação:

```bash
php -l /opt/mk-auth/scripts/mkauth_additional_block_worker.php
mysql -uroot -pvertrigo -e "USE mkradius; SHOW TRIGGERS; SELECT status,COUNT(*) FROM mkauth_adicional_block_queue GROUP BY status;"
tail -f /var/log/mkauth_additional_block.log
```

## Reversão

Executar `uninstall_patch.sh` como root:

```bash
chmod 0750 uninstall_patch.sh
./uninstall_patch.sh
```

A reversão remove o trigger complementar, a procedure, o worker e o cron. A fila é preservada como histórico; o próprio script indica o comando opcional para removê-la. O trigger oficial nunca é removido ou substituído.
