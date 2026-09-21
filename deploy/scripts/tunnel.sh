#!/usr/bin/env bash
#
# Cria (ou reconfigura) o túnel Cloudflare PRÓPRIO do Sistema DEZ.
#
#   sudo -u dez /opt/dez/app/deploy/scripts/tunnel.sh dez.clinicadez.com.br
#
# ATENÇÃO: este script cria um túnel NOVO e independente. Ele NUNCA lê nem
# escreve /etc/cloudflared/config.yml — esse arquivo pertence ao túnel que serve
# o STG em produção, e reiniciá-lo derruba a clínica.
#
# Pré-requisito (interativo, uma única vez, com navegador):
#   cloudflared tunnel login      # gera ~/.cloudflared/cert.pem

set -euo pipefail

HOSTNAME_ARG="${1:-}"
TUNNEL_NAME="${2:-dez}"
DEZ_ROOT=/opt/dez
CFG_DIR="$DEZ_ROOT/cloudflared"
CFG="$CFG_DIR/config.yml"
ORIGIN="http://127.0.0.1:8002"

[ -n "$HOSTNAME_ARG" ] || { echo "uso: tunnel.sh <hostname> [nome-do-tunel]" >&2; exit 1; }
[ "$(id -u)" -ne 0 ] || { echo "rode como o usuário dez (sem sudo)" >&2; exit 1; }
command -v cloudflared >/dev/null || { echo "cloudflared não encontrado no PATH" >&2; exit 1; }

if [ ! -f "$HOME/.cloudflared/cert.pem" ]; then
	echo "Falta ~/.cloudflared/cert.pem." >&2
	echo "Rode primeiro, com navegador:  cloudflared tunnel login" >&2
	exit 1
fi

mkdir -p "$CFG_DIR"

if cloudflared tunnel list 2>/dev/null | awk '{print $2}' | grep -qx "$TUNNEL_NAME"; then
	UUID=$(cloudflared tunnel list 2>/dev/null | awk -v n="$TUNNEL_NAME" '$2 == n {print $1}')
	echo "==> reaproveitando o túnel '$TUNNEL_NAME' ($UUID)"
else
	echo "==> criando o túnel '$TUNNEL_NAME'"
	UUID=$(cloudflared tunnel create "$TUNNEL_NAME" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1)
	[ -n "$UUID" ] || { echo "não foi possível obter o UUID do túnel" >&2; exit 1; }
fi

cat > "$CFG" <<EOF
# Túnel do Sistema DEZ (independente do túnel do STG).
tunnel: $UUID
credentials-file: $HOME/.cloudflared/$UUID.json

ingress:
  - hostname: $HOSTNAME_ARG
    service: $ORIGIN
  - service: http_status:404
EOF
chmod 0640 "$CFG"

echo "==> validando a configuração"
cloudflared tunnel ingress validate --config "$CFG"

echo "==> criando/atualizando o CNAME $HOSTNAME_ARG no Cloudflare"
cloudflared tunnel route dns "$TUNNEL_NAME" "$HOSTNAME_ARG" || \
	echo "[aviso] o CNAME pode já existir — confira no painel do Cloudflare"

cat <<EOF

Túnel pronto: $UUID  ->  $ORIGIN

Suba o serviço (o sudo é só para o systemd, o túnel roda como dez):
  sudo systemctl enable --now dez-tunnel.service
  sudo systemctl status dez-tunnel.service

Validação:
  curl -s -o /dev/null -w '%{http_code}\n' https://$HOSTNAME_ARG/up
EOF
