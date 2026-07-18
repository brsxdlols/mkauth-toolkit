#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)

echo "Instalando geocodificacao..."
sh "$SCRIPT_DIR/install.sh"
echo "Instalando mapa interativo de clientes..."
sh "$SCRIPT_DIR/install-mapa.sh"
echo "MK-AUTH Toolkit instalado com sucesso."
