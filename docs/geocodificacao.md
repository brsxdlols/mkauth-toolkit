# Geocodificação — operação e suporte

## Requisitos

- MK-AUTH com diretório administrativo em `/opt/mk-auth/admin`;
- PHP com cURL e JSON;
- acesso HTTPS de saída para ViaCEP, Photon/OpenStreetMap e, quando selecionado, Google Maps;
- navegador moderno.

## Provedores

O addon lê a configuração nativa `conf_mapas.hhvm` dentro da sessão autenticada:

- `OpenStreet`: Photon + OpenStreetMap + ViaCEP;
- `GoogleMaps`: Maps JavaScript + Google Geocoding/Places + ViaCEP.

O ViaCEP permanece responsável pela confirmação postal e pelo código IBGE. Se Places não estiver habilitado, o modo Google usa Geocoding como fallback.

## APIs Google

Para o funcionamento completo, habilite:

- Maps JavaScript API;
- Geocoding API;
- Places API para autocomplete completo.

Restrinja a chave por domínio e apenas às APIs utilizadas. A chave continua armazenada pelo próprio MK-AUTH.

## Arquivos instalados

- `/opt/mk-auth/admin/addons/geocodificacao/config.php`
- `/opt/mk-auth/admin/addons/geocodificacao/geocode.php`
- `/opt/mk-auth/admin/addons/geocodificacao/geocodificacao.js`
- `/opt/mk-auth/admin/estilos/leaflet.css`
- `/opt/mk-auth/admin/scripts/leaflet.js`
- carregador versionado em `/opt/mk-auth/admin/scripts/mk-auth.js`

## Segurança

- nenhuma alteração em massa no banco;
- nenhuma coordenada é gravada até o operador salvar o cadastro;
- consultas externas possuem debounce, cache e limite de requisições;
- o endereço manual continua editável;
- o instalador cria backup antes de qualquer substituição.

## Validação recomendada

1. Abra um cliente de teste.
2. Acesse a aba Endereço.
3. Clique em **Pesquisar no mapa**.
4. Pesquise CEP, logradouro e número.
5. Selecione o resultado e, se necessário, arraste o marcador.
6. Clique em **Usar esta posição**.
7. Confirme endereço, IBGE e coordenadas antes de salvar.
