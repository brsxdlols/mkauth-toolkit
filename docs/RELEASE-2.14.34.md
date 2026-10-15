# Pacote 2.14.34 — Mapa 1.3.35 / Geocodificação 2.10.5

Adiciona manutenção automática dos arquivos descartáveis do Toolkit. Não apaga nem trunca logs, banco, sessões ou arquivos do RADIUS, MySQL, Dashboard/Busca ou outros serviços.

- Cache de geocodificação revisado a cada hora: expiração de 30 dias, alvo de 64 MiB e 10 mil arquivos, removendo primeiro os mais antigos. Não altera coordenadas cadastradas.
- Trabalhos concluídos há mais de 7 dias podem ser removidos. Tarefas ativas e o lock do worker são preservados.
- Backups novos recebem marcador de propriedade. Retenção de 7 cópias/30 dias, alvo de 512 MiB por addon. Preserva as duas tentativas mais recentes e os dois últimos backups concluídos; esses backups protegidos podem exceder o alvo. Falhas recentes recebem 24 horas de proteção. Backups antigos sem marcador e backups manuais permanecem intactos.
- Log da manutenção limitado a aproximadamente 256 KiB, com descarte do início ao atingir o limite. Erros de consulta externa da geocodificação registram no máximo um aviso por hora no log PHP/Apache.
- Histórico Telegram já limitado a 200 eventos, fila a 1.000 e deduplicação a 5.000 registros. Configuração, fila ativa, incidentes abertos e dados de resposta são preservados. Temporários Telegram abandonados há mais de 24 horas são removidos.
- O instalador `.run` passa a remover a pasta de extração ao terminar ou falhar, corrigindo o uso de `exec` que impedia o trap de limpeza.

Instalação completa exige Python 3 e registra cron horário. Programa e configuração ficam em `/opt/mk-auth/scripts/mkauth-toolkit-maintenance/`; cron em `/etc/cron.d/mkauth-toolkit-maintenance`. A configuração contém somente caminhos, sem token Telegram ou senha do banco. Modo de conferência: `python3 /opt/mk-auth/scripts/mkauth-toolkit-maintenance/maintain.py --config /opt/mk-auth/scripts/mkauth-toolkit-maintenance/config.json --dry-run`.

Os limites de cache são aplicados na manutenção horária, não constituem quota de filesystem. Estado ativo acompanha a quantidade de clientes. A medida reduz o crescimento causado pelo addon, mas não garante espaço livre se outros serviços ou backups ocuparem o disco.

Validação: retenção por idade/tamanho/quantidade, dry-run, isolamento de symlinks, preservação de configuração e tarefas ativas, retenção de rollback, log limitado, limitação de erros da geocodificação e instalação em diretórios isolados. Atualização do runtime pela ferramenta oficial da suíte.
