#!/bin/sh
set -eu
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
echo "Reaplicando e validando a integracao de geocodificacao..."
exec "$SCRIPT_DIR/install.sh"
