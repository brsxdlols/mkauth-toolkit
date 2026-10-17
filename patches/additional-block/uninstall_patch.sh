#!/bin/bash
set -euo pipefail

rm -f /etc/cron.d/mkauth-additional-block
rm -f /opt/mk-auth/scripts/mkauth_additional_block_worker.php

mysql -uroot -pvertrigo <<'SQL'
USE mkradius;
DROP TRIGGER IF EXISTS tig_cliente_adicionais_bloqueio;
DROP PROCEDURE IF EXISTS sp_mkauth_sync_adicionais_bloqueio;
-- A fila e mantida como historico. Para remove-la definitivamente:
-- DROP TABLE mkauth_adicional_block_queue;
SQL

echo "Patch removido; trigger oficial tig_cliente permaneceu intacto."
