#!/bin/sh
set -eu

REPOSITORY="${MKAUTH_GITHUB_REPOSITORY:-brsxdlols/mkauth-toolkit}"
API_ROOT="https://api.github.com/repos/$REPOSITORY"
WORK_DIR=$(mktemp -d /tmp/mkauth-geocodificacao.XXXXXX)

cleanup() {
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT HUP INT TERM

fail() {
    echo "ERRO: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "execute como root"
command -v curl >/dev/null 2>&1 || fail "curl nao encontrado"
command -v sha256sum >/dev/null 2>&1 || fail "sha256sum nao encontrado"
API_HEADER="Accept: application/vnd.github+json"
VERSION_HEADER="X-GitHub-Api-Version: 2022-11-28"
RELEASE_JSON="$WORK_DIR/release.json"

githubCurl() {
    if [ -n "${GH_TOKEN:-}" ]; then
        curl -fsSL -H "Authorization: Bearer $GH_TOKEN" "$@"
    else
        curl -fsSL "$@"
    fi
}

echo "Consultando a ultima versao no GitHub..."
githubCurl \
    -H "$API_HEADER" \
    -H "$VERSION_HEADER" \
    "$API_ROOT/releases/latest" > "$RELEASE_JSON" || fail "nao foi possivel consultar a Release"

TAG=$(sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$RELEASE_JSON" | head -n 1)
RUN_ID=$(awk '
    /"id"[[:space:]]*:/ { id=$2; gsub(/,/, "", id) }
    /"name"[[:space:]]*:[[:space:]]*"mkauth-geocodificacao-[^"]*\.run"/ { print id; exit }
' "$RELEASE_JSON")
SHA_ID=$(awk '
    /"id"[[:space:]]*:/ { id=$2; gsub(/,/, "", id) }
    /"name"[[:space:]]*:[[:space:]]*"mkauth-geocodificacao-[^"]*\.run\.sha256"/ { print id; exit }
' "$RELEASE_JSON")

[ -n "$TAG" ] || fail "tag da Release nao encontrada"
[ -n "$RUN_ID" ] || fail "instalador .run nao encontrado na Release $TAG"
[ -n "$SHA_ID" ] || fail "arquivo SHA-256 nao encontrado na Release $TAG"

RUN_FILE="$WORK_DIR/installer.run"
SHA_FILE="$WORK_DIR/installer.run.sha256"

echo "Baixando MK-AUTH Geocodificacao $TAG..."
githubCurl \
    -H "Accept: application/octet-stream" \
    -H "$VERSION_HEADER" \
    "$API_ROOT/releases/assets/$RUN_ID" -o "$RUN_FILE" || fail "falha ao baixar o instalador"
githubCurl \
    -H "Accept: application/octet-stream" \
    -H "$VERSION_HEADER" \
    "$API_ROOT/releases/assets/$SHA_ID" -o "$SHA_FILE" || fail "falha ao baixar a verificacao SHA-256"

EXPECTED=$(awk '{print $1}' "$SHA_FILE")
ACTUAL=$(sha256sum "$RUN_FILE" | awk '{print $1}')
[ -n "$EXPECTED" ] || fail "SHA-256 esperado esta vazio"
[ "$EXPECTED" = "$ACTUAL" ] || fail "SHA-256 invalido; instalacao cancelada"

echo "Integridade confirmada: $ACTUAL"
echo "Executando o instalador..."
GH_TOKEN='' sh "$RUN_FILE"
echo "Instalacao remota concluida: $TAG"
