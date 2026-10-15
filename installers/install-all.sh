#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
command -v python3 >/dev/null 2>&1 || { echo 'ERRO: python3 necessario para a manutencao do Toolkit' >&2; exit 1; }

echo "Instalando geocodificacao..."
sh "$SCRIPT_DIR/install.sh"
echo "Instalando mapa interativo de clientes..."
sh "$SCRIPT_DIR/install-mapa.sh"
sh "$SCRIPT_DIR/install-maintenance.sh"
echo "MK-AUTH Toolkit instalado com sucesso."
