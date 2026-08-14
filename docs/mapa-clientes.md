# Mapa interativo de clientes

O módulo instala o mapa protegido em `/admin/addons/mapa-clientes/maps.hhvm` sem substituir o addon de geocodificação administrativa. A URL antiga `/central/maps.hhvm` apenas redireciona para a área protegida.

## Recursos

- identifica clientes bloqueados pelo campo nativo `sis_cliente.bloqueado`, com cadeado no marcador e estado no popup;
- incorpora monitor próprio de tráfego RouterOS enquanto o cliente estiver online;

- clientes online, offline e sem coordenadas nos totais e na lista;
- filtros, busca, clusters e atualização em 30 segundos, 60 segundos ou 5 minutos;
- usa Google Maps ou OpenStreetMap conforme `Opções > Recursos do sistema > Mapas`, preservando a escolha Mapa/Satélite;
- inclui clientes principais e adicionais nos totais, marcadores, edição de localização e vínculos com CTO;
- mostra as rotas Cliente–CTO em linha reta (Rádio) e pelas ruas (Fibra), com seleção independente;
- popups técnicos, tempo online e distância do provedor;
- notificações, alerta de queda em massa, sirene opcional e demonstração;
- cadastro manual ou por pesquisa de coordenadas, protegido por sessão e CSRF;
- modo tela cheia, temas Dark e White e painel lateral recolhível.
- acesso obrigatório por sessão administrativa ou dispositivo confiável autorizado;
- opção "Manter acesso ao mapa neste dispositivo", sem armazenar usuário ou senha.

## Instalação somente do mapa

```sh
cd /opt/mkauth-toolkit
sh installers/install-mapa.sh
```

## Instalação combinada

```sh
cd /opt/mkauth-toolkit
sh installers/install-all.sh
```

O instalador cria backup individual em `/root/backups/mk-auth-mapa-clientes-*`.

## Rollback

```sh
sh installers/rollback-mapa.sh /root/backups/mk-auth-mapa-clientes-AAAAmmdd-HHMMSS-v1.1.0
```

Nenhuma credencial, senha ou chave de mapas é incluída no repositório. A chave configurada no MK-AUTH é lida apenas no servidor e entregue à biblioteca oficial do Google quando esse provedor estiver selecionado. Recomenda-se restringi-la ao domínio do provedor no Google Cloud.
