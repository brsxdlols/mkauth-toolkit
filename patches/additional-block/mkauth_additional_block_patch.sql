-- MK-AUTH: propagacao de bloqueio/desbloqueio do login principal aos adicionais.
-- Compativel com MariaDB 10.3 e com o esquema MK-AUTH validado em 2026-08-08.

USE mkradius;

CREATE TABLE IF NOT EXISTS mkauth_adicional_block_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    principal_login VARCHAR(64) NOT NULL,
    additional_username VARCHAR(64) NOT NULL,
    desired_state ENUM('sim','nao') NOT NULL,
    status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    result_message VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_principal_additional (principal_login, additional_username),
    KEY ix_status_updated (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

DROP PROCEDURE IF EXISTS sp_mkauth_sync_adicionais_bloqueio;

DELIMITER $$
CREATE PROCEDURE sp_mkauth_sync_adicionais_bloqueio(
    IN p_principal VARCHAR(64),
    IN p_estado VARCHAR(3)
)
proc: BEGIN
    DECLARE v_p_mode VARCHAR(16) DEFAULT 'list';
    DECLARE v_h_mode VARCHAR(16) DEFAULT 'list';
    DECLARE v_pgcorte VARCHAR(16) DEFAULT 'rad';
    DECLARE v_bloq6 VARCHAR(16) DEFAULT 'nao';
    DECLARE v_principal_block_plan VARCHAR(64) DEFAULT 'Bloqueio';

    IF p_estado NOT IN ('sim', 'nao') THEN
        LEAVE proc;
    END IF;

    SELECT COALESCE(MAX(CASE WHEN nome='pbloqradius' THEN LOWER(TRIM(valor)) END), 'list'),
           COALESCE(MAX(CASE WHEN nome='tbloqradius' THEN LOWER(TRIM(valor)) END), 'list'),
           COALESCE(MAX(CASE WHEN nome='pgcorte' THEN LOWER(TRIM(valor)) END), 'rad'),
           COALESCE(MAX(CASE WHEN nome='bloq6' THEN LOWER(TRIM(valor)) END), 'nao')
      INTO v_p_mode, v_h_mode, v_pgcorte, v_bloq6
     FROM sis_opcao
     WHERE nome IN ('pbloqradius','tbloqradius','pgcorte','bloq6');

    -- Aceita os rotulos usados por versoes diferentes do MK-AUTH.
    IF v_p_mode IN ('lista','address-list','addresslist') THEN SET v_p_mode='list'; END IF;
    IF v_h_mode IN ('lista','address-list','addresslist') THEN SET v_h_mode='list'; END IF;

    SELECT COALESCE(NULLIF(NULLIF(TRIM(plano_bloqc), ''), 'nenhum'), 'Bloqueio')
      INTO v_principal_block_plan
      FROM sis_cliente
     WHERE login=p_principal
     LIMIT 1;

    -- O campo de estado passa a refletir o principal em todas as telas do MK-AUTH.
    UPDATE sis_adicional
       SET bloqueado=p_estado
     WHERE login=p_principal
       AND bloqueado<>p_estado;

    IF p_estado='sim' THEN
        IF v_pgcorte='nao' THEN
            UPDATE radcheck rc
            JOIN sis_adicional a ON a.username=rc.username
               SET rc.ativo='n'
             WHERE a.login=p_principal;
        ELSE
            UPDATE radcheck rc
            JOIN sis_adicional a ON a.username=rc.username
               SET rc.ativo='s'
             WHERE a.login=p_principal;

            -- O adicional usa seu plano de bloqueio; na ausencia dele, herda o do principal.
            DELETE rug
              FROM radusergroup rug
              JOIN sis_adicional a ON a.username=rug.username
             WHERE a.login=p_principal;

            INSERT INTO radusergroup (username,groupname,priority,login)
            SELECT a.username,
                   COALESCE(NULLIF(NULLIF(TRIM(a.plano_bloqa),''),'nenhum'),
                            NULLIF(NULLIF(TRIM(v_principal_block_plan),''),'nenhum'),
                            'Bloqueio'),
                   1,
                   a.login
              FROM sis_adicional a
             WHERE a.login=p_principal
               AND a.username IS NOT NULL AND TRIM(a.username)<>'';

            -- Limpa somente atributos de corte antigos e, no modo pool, o IP/pool normal.
            DELETE rr
              FROM radreply rr
              JOIN sis_adicional a ON a.username=rr.username
             WHERE a.login=p_principal
               AND (
                    (rr.attribute='Mikrotik-Address-List' AND LOWER(rr.value)='pgcorte')
                 OR (rr.attribute='Framed-Pool' AND
                        (LOWER(rr.value)='pgcorte' OR
                         (CASE WHEN LOWER(a.tipo)='pppoe' THEN v_p_mode ELSE v_h_mode END)='pool'))
                 OR (rr.attribute='Framed-IP-Address' AND
                         (CASE WHEN LOWER(a.tipo)='pppoe' THEN v_p_mode ELSE v_h_mode END)='pool')
                 OR (rr.attribute IN ('Delegated-IPv6-Prefix','Framed-IPv6-Prefix') AND
                         (CASE WHEN LOWER(a.tipo)='pppoe' THEN v_p_mode ELSE v_h_mode END)='pool')
                 OR (rr.attribute='Mikrotik-Delegated-IPv6-Pool' AND LOWER(rr.value)='pgcorte')
               );

            INSERT INTO radreply (username,attribute,op,value,login)
            SELECT a.username,'Framed-Pool','=','pgcorte',a.login
              FROM sis_adicional a
             WHERE a.login=p_principal
               AND (CASE WHEN LOWER(a.tipo)='pppoe' THEN v_p_mode ELSE v_h_mode END)='pool';

            INSERT INTO radreply (username,attribute,op,value,login)
            SELECT a.username,'Mikrotik-Address-List',':=','pgcorte',a.login
              FROM sis_adicional a
             WHERE a.login=p_principal
               AND (CASE WHEN LOWER(a.tipo)='pppoe' THEN v_p_mode ELSE v_h_mode END)='list';

            INSERT INTO radreply (username,attribute,op,value,login)
            SELECT a.username,'Mikrotik-Delegated-IPv6-Pool','=','pgcorte',a.login
              FROM sis_adicional a
             WHERE a.login=p_principal
               AND v_bloq6='sim'
               AND (CASE WHEN LOWER(a.tipo)='pppoe' THEN v_p_mode ELSE v_h_mode END)='pool';
        END IF;
    ELSE
        -- Desbloqueio: reativa autenticacao e restaura plano/endereco do adicional.
        UPDATE radcheck rc
        JOIN sis_adicional a ON a.username=rc.username
           SET rc.ativo='s'
         WHERE a.login=p_principal;

        DELETE rug
          FROM radusergroup rug
          JOIN sis_adicional a ON a.username=rug.username
         WHERE a.login=p_principal;

        INSERT INTO radusergroup (username,groupname,priority,login)
        SELECT a.username,a.plano,1,a.login
          FROM sis_adicional a
         WHERE a.login=p_principal
           AND a.username IS NOT NULL AND TRIM(a.username)<>''
           AND a.plano IS NOT NULL AND TRIM(a.plano)<>'';

        DELETE rr
          FROM radreply rr
          JOIN sis_adicional a ON a.username=rr.username
         WHERE a.login=p_principal
           AND rr.attribute IN (
               'Framed-IP-Address','Framed-Pool','Mikrotik-Address-List',
               'Mikrotik-Delegated-IPv6-Pool','Delegated-IPv6-Prefix','Framed-IPv6-Prefix'
           );

        INSERT INTO radreply (username,attribute,op,value,login)
        SELECT a.username,'Framed-IP-Address','=',TRIM(a.ip),a.login
          FROM sis_adicional a
         WHERE a.login=p_principal
           AND a.ip IS NOT NULL AND TRIM(a.ip)<>''
           AND (a.pool_name IS NULL OR TRIM(a.pool_name)='' OR LOWER(TRIM(a.pool_name))='nenhum');

        INSERT INTO radreply (username,attribute,op,value,login)
        SELECT a.username,'Framed-Pool','=',TRIM(a.pool_name),a.login
          FROM sis_adicional a
         WHERE a.login=p_principal
           AND a.pool_name IS NOT NULL AND TRIM(a.pool_name)<>''
           AND LOWER(TRIM(a.pool_name))<>'nenhum';

        INSERT INTO radreply (username,attribute,op,value,login)
        SELECT a.username,'Delegated-IPv6-Prefix',':=',TRIM(a.pool6),a.login
          FROM sis_adicional a
         WHERE a.login=p_principal
           AND a.pool6 IS NOT NULL AND TRIM(a.pool6)<>'';
    END IF;
END$$

DROP TRIGGER IF EXISTS tig_cliente_adicionais_bloqueio$$
CREATE TRIGGER tig_cliente_adicionais_bloqueio
AFTER UPDATE ON sis_cliente
FOR EACH ROW
BEGIN
    IF NOT (OLD.bloqueado <=> NEW.bloqueado) THEN
        CALL sp_mkauth_sync_adicionais_bloqueio(NEW.login, NEW.bloqueado);

        INSERT INTO mkauth_adicional_block_queue
            (principal_login,additional_username,desired_state,status,attempts,created_at,updated_at,processed_at,result_message)
        SELECT NEW.login,a.username,NEW.bloqueado,'pending',0,NOW(),NOW(),NULL,NULL
          FROM sis_adicional a
         WHERE a.login=NEW.login
           AND a.username IS NOT NULL AND TRIM(a.username)<>''
        ON DUPLICATE KEY UPDATE
            desired_state=VALUES(desired_state),
            status='pending', attempts=0, updated_at=NOW(), processed_at=NULL, result_message=NULL;
    END IF;
END$$
DELIMITER ;
