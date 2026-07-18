#!/bin/sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
echo "Reaplicando e validando o mapa interativo de clientes..."
exec sh "$SCRIPT_DIR/install-mapa.sh"
