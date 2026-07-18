# Mapa interativo de clientes

O módulo instala o mapa em `/central/maps.hhvm` sem substituir o addon de geocodificação administrativa.

## Recursos

- clientes online, offline e sem coordenadas nos totais e na lista;
- filtros, busca, clusters e atualização em 30 segundos, 60 segundos ou 5 minutos;
- OpenStreetMap e satélite, respeitando a configuração nativa quando disponível;
- popups técnicos, tempo online e distância do provedor;
- notificações, alerta de queda em massa, sirene opcional e demonstração;
- cadastro manual ou por pesquisa de coordenadas, protegido por sessão e CSRF;
- modo tela cheia, temas Dark e White e painel lateral recolhível.

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
sh installers/rollback-mapa.sh /root/backups/mk-auth-mapa-clientes-AAAAmmdd-HHMMSS-v1.0.0
```

Nenhuma credencial, senha ou chave de mapas é incluída no repositório.
