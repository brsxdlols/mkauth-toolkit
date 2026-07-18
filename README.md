# MK-AUTH Toolkit

Addons e ferramentas para MK-AUTH com instalação automatizada.

## Geocodificação de clientes

A versão `2.10.3` adiciona ao cadastro de clientes, mantém compatibilidade com PHP legado e inclui atualização administrativa em lote, inclusive em segundo plano com andamento e aviso persistente de conclusão:

- pesquisa de endereço e coordenadas em janela integrada;
- seleção automática do provedor configurado em **Opções → Mapas**;
- OpenStreetMap/Photon e ViaCEP sem chave;
- Google Maps/Geocoding usando a chave nativa do MK-AUTH;
- preenchimento de CEP, logradouro, número, bairro, cidade, estado e IBGE;
- marcador arrastável que ajusta somente as coordenadas;
- validação postal para evitar que resultados aproximados sobrescrevam o endereço correto;
- preenchimento manual sempre preservado.
- prévia e seleção de clientes sem coordenadas em **Opções → Recursos → Mapas**;
- confirmação explícita e processamento progressivo, sem sobrescrever coordenadas existentes.

Nenhuma chave Google ou credencial é armazenada neste repositório.

## Instalação

### Direto do GitHub público (recomendado)

No MK-AUTH, como `root`, execute:

```bash
curl -fsSL https://raw.githubusercontent.com/brsxdlols/mkauth-toolkit/main/installers/github-install.sh | sh
```

O instalador consulta a Release mais recente, baixa o arquivo `.run` e sua
assinatura SHA-256, valida a integridade e instala com backup automático.

### Repositório privado

Crie um Fine-grained Personal Access Token limitado ao repositório
`mkauth-toolkit`, apenas com a permissão **Contents: Read-only**. No MK-AUTH,
como `root`, execute o comando abaixo. O token é solicitado de forma oculta e
não fica salvo no histórico:

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

Como o repositório é privado, o clone exige autenticação GitHub. Para servidores sem acesso ao GitHub, gere o instalador autoextraível e envie por SCP.

### Instalador autoextraível

No Windows:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\installers\build-release.ps1
```

Copie `dist/mkauth-geocodificacao-2.14.6.run` para o servidor e execute:

```bash
bash /tmp/mkauth-geocodificacao-2.14.6.run
```

## Reparação após atualização do MK-AUTH

```bash
cd /opt/mkauth-toolkit
git pull --ff-only
./installers/repair.sh
```

## Rollback

Cada instalação cria backup em `/root/backups/mk-auth-geocodificacao-*`.

```bash
./installers/rollback.sh /root/backups/mk-auth-geocodificacao-AAAAmmdd-HHMMSS-v2.10.3
```

Consulte [docs/geocodificacao.md](docs/geocodificacao.md) para requisitos, validação e limitações.

## Mapa interativo de clientes

O mesmo toolkit inclui o mapa interativo em `addons/mapa-clientes`, sem alterar
o funcionamento do addon de geocodificação. A instalação combinada usa:

```bash
./installers/install-all.sh
```

Para instalar ou reparar somente o mapa:

```bash
./installers/install-mapa.sh
```

Consulte [docs/mapa-clientes.md](docs/mapa-clientes.md) para recursos,
validação e rollback do mapa.
