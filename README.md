# MK-AUTH Toolkit

Addons e ferramentas para MK-AUTH com instala??o automatizada.

## Geocodifica??o de clientes

A vers?o `2.10.3` adiciona ao cadastro de clientes, mant?m compatibilidade com PHP legado e inclui atualiza??o administrativa em lote, inclusive em segundo plano com andamento e aviso persistente de conclus?o:

- pesquisa de endere?o e coordenadas em janela integrada;
- sele??o autom?tica do provedor configurado em **Op??es ? Mapas**;
- OpenStreetMap/Photon e ViaCEP sem chave;
- Google Maps/Geocoding usando a chave nativa do MK-AUTH;
- preenchimento de CEP, logradouro, n?mero, bairro, cidade, estado e IBGE;
- marcador arrast?vel que ajusta somente as coordenadas;
- valida??o postal para evitar que resultados aproximados sobrescrevam o endere?o correto;
- preenchimento manual sempre preservado.
- pr?via e sele??o de clientes sem coordenadas em **Op??es ? Recursos ? Mapas**;
- confirma??o expl?cita e processamento progressivo, sem sobrescrever coordenadas existentes.

Nenhuma chave Google ou credencial ? armazenada neste reposit?rio.

## Instala??o

### Direto do GitHub p?blico (recomendado)

No MK-AUTH, como `root`, execute:

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/mkauth-toolkit/main/installers/github-install.sh | sh
```

O instalador consulta a Release mais recente, baixa o arquivo `.run` e sua
assinatura SHA-256, valida a integridade e instala com backup autom?tico.

### Reposit?rio privado

Crie um Fine-grained Personal Access Token limitado ao reposit?rio
`mkauth-toolkit`, apenas com a permiss?o **Contents: Read-only**. No MK-AUTH,
como `root`, execute o comando abaixo. O token ? solicitado de forma oculta e
n?o fica salvo no hist?rico:

```bash
read -rsp "Token GitHub: " GH_TOKEN; echo; T=$(mktemp); curl -fsSL -H "Authorization: Bearer $GH_TOKEN" -H "Accept: application/vnd.github.raw+json" https://api.github.com/repos/brsxdlols/mkauth-toolkit/contents/installers/github-install.sh -o "$T" && GH_TOKEN="$GH_TOKEN" sh "$T"; R=$?; rm -f "$T"; unset GH_TOKEN T; (exit $R)
```

### Clone autenticado

No servidor MK-AUTH, como `root`:

```bash
git clone https://github.com/brsxdlols/mkauth-toolkit.git /opt/mkauth-toolkit
cd /opt/mkauth-toolkit
./installers/install-all.sh
```

Como o reposit?rio ? privado, o clone exige autentica??o GitHub. Para servidores sem acesso ao GitHub, gere o instalador autoextra?vel e envie por SCP.

### Instalador autoextra?vel

No Windows:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\installers\build-release.ps1
```

Copie `dist/mkauth-geocodificacao-2.14.16.run` para o servidor e execute:

```bash
bash /tmp/mkauth-geocodificacao-2.14.16.run
```

## Repara??o ap?s atualiza??o do MK-AUTH

```bash
cd /opt/mkauth-toolkit
git pull --ff-only
./installers/repair.sh
```

## Rollback

Cada instala??o cria backup em `/root/backups/mk-auth-geocodificacao-*`.

```bash
./installers/rollback.sh /root/backups/mk-auth-geocodificacao-AAAAmmdd-HHMMSS-v2.10.3
```

Consulte [docs/geocodificacao.md](docs/geocodificacao.md) para requisitos, valida??o e limita??es.

## Mapa interativo de clientes

O mesmo toolkit inclui o mapa interativo em `addons/mapa-clientes`, sem alterar
o funcionamento do addon de geocodifica??o. A instala??o combinada usa:

```bash
./installers/install-all.sh
```

Para instalar ou reparar somente o mapa:

```bash
./installers/install-mapa.sh
```

Consulte [docs/mapa-clientes.md](docs/mapa-clientes.md) para recursos,
valida??o e rollback do mapa.
