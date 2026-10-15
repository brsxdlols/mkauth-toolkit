# Pacote 2.14.33 — Mapa 1.3.34

Reorganiza os filtros gerais em Visualização, Avisos de clientes e Queda em massa.

- Avisos de conexão controlam os popups individuais de conexão/desconexão.
- Queda em massa tem um único controle **Ativar alerta no mapa**. Quantidade mínima, duração e sirene aparecem somente quando ativado.
- Desativar fecha o aviso crítico e para a sirene. Reativar recupera as escolhas de quantidade, duração e som; a opção antiga Nenhum é reconhecida como desligada.
- Avisos individuais e queda em massa funcionam independentemente. Desligar um não desliga o outro.
- Texto explicativo diferencia alertas deste navegador das configurações do Telegram. Nenhuma configuração Telegram é alterada.

Preserva a proteção de totais por `perm_numClientes`, os grupos permitidos e a duração automática de 1, 5 ou 10 minutos. Validação em desktop/mobile, temas claro/escuro, opções dependentes, migração de Nenhum, valores restaurados, independência dos avisos e cancelamento do som ao fechar.
