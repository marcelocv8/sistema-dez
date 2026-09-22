#!/usr/bin/env bash
#
# Cria (ou reconfigura) o túnel Cloudflare PRÓPRIO do Sistema DEZ e entrega as
# credenciais para o serviço, que roda como o usuário `dez`.
#
#   ./tunnel.sh dez.clinicadez.com.br
#
# Rode como o usuário que tem ~/.cloudflared/cert.pem (na clínica: `server`),
# SEM `sudo` na frente — o script pede sudo apenas para instalar os arquivos em
# /opt/dez. Rode como root NÃO funciona (o root não tem o cert.pem).
#
# ATENÇÃO: cria um túnel NOVO e independente. NUNCA lê nem escreve
# /etc/cloudflared/config.yml — esse arquivo pertence ao túnel que serve o STG
# em produção, e reiniciá-lo derruba a clínica.
#
# Pré-requisito (uma única vez, com navegador): cloudflared tunnel login

set -euo pipefail

HOSTNAME_ARG="${1:-}"
TUNNEL_NAME="${2:-dez}"
DEZ_ROOT=/opt/dez
CFG_DIR="$DEZ_ROOT/cloudflared"
CFG="$CFG_DIR/config.yml"
ORIGIN="http://127.0.0.1:8002"
SVC_USER=dez

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[aviso]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[erro]\033[0m %s\n' "$*" >&2; exit 1; }

[ -n "$HOSTNAME_ARG" ] || die "uso: tunnel.sh <hostname> [nome-do-tunel]"
[ "$(id -u)" -ne 0 ] || die "não rode como root: o cert.pem fica no ~ do usuário. Use o usuário 'server' (ou o dono do cert.pem)"
command -v cloudflared >/dev/null || die "cloudflared não encontrado no PATH"
[ -f "$HOME/.cloudflared/cert.pem" ] || {
	echo "Falta ~/.cloudflared/cert.pem" >&2
	echo "Rode primeiro, com navegador:  cloudflared tunnel login" >&2
	exit 1
}

# --- 1. tunel -----------------------------------------------------------------
if cloudflared tunnel list 2>/dev/null | awk '{print $2}' | grep -qx "$TUNNEL_NAME"; then
	UUID=$(cloudflared tunnel list 2>/dev/null | awk -v n="$TUNNEL_NAME" '$2 == n {print $1}')
	log "reaproveitando o túnel '$TUNNEL_NAME' ($UUID)"
else
	log "criando o túnel '$TUNNEL_NAME'"
	UUID=$(cloudflared tunnel create "$TUNNEL_NAME" | grep -oE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1)
	[ -n "$UUID" ] || die "não foi possível obter o UUID do túnel"
fi

SRC_CRED="$HOME/.cloudflared/$UUID.json"
[ -f "$SRC_CRED" ] || die "credenciais não encontradas em $SRC_CRED"

# --- 2. config (montada e validada antes de rotear o DNS) --------------------
TMP_CFG=$(mktemp)
cat > "$TMP_CFG" <<EOF
# Túnel do Sistema DEZ (independente do túnel do STG).
tunnel: $UUID
credentials-file: $CFG_DIR/$UUID.json

ingress:
  - hostname: $HOSTNAME_ARG
    service: $ORIGIN
  - service: http_status:404
EOF

log "validando a configuração do ingress"
# Sintaxe correta: --config é flag do comando `tunnel`, ANTES de `ingress`.
# (`cloudflared tunnel ingress validate --config …` → 'flag provided but not defined')
cloudflared tunnel --config "$TMP_CFG" ingress validate \
	|| { rm -f "$TMP_CFG"; die "ingress inválido"; }

# --- 3. DNS ------------------------------------------------------------------
log "criando/atualizando o CNAME $HOSTNAME_ARG no Cloudflare"
# ATENÇÃO: usar o UUID — NÃO o nome. Nesta máquina o cloudflared carrega o
# /etc/cloudflared/config.yml default (o do STG) e o `route dns` por nome
# acabou apontando o CNAME para o TÚNEL DO STG. Com o UUID não há ambiguidade.
# `--overwrite-dns` corrige um registro já criado apontando para outro túnel.
cloudflared tunnel route dns --overwrite-dns "$UUID" "$HOSTNAME_ARG" \
	|| warn "não foi possível criar/atualizar o CNAME — confira no painel do Cloudflare"

# --- 4. instalação para o serviço (usuário dez) -------------------------------
log "instalando credenciais e config em $CFG_DIR (dono: $SVC_USER)"
sudo install -d -o "$SVC_USER" -g "$SVC_USER" -m 0750 "$CFG_DIR"
sudo install -o "$SVC_USER" -g "$SVC_USER" -m 0600 "$SRC_CRED" "$CFG_DIR/$UUID.json"
sudo install -o "$SVC_USER" -g "$SVC_USER" -m 0640 "$TMP_CFG" "$CFG"
rm -f "$TMP_CFG"

cat <<EOF

Túnel pronto:  $UUID
Hostname:      https://$HOSTNAME_ARG
Origem:        $ORIGIN
Credenciais:   $CFG_DIR/$UUID.json (0600 $SVC_USER)
Config:        $CFG (0640 $SVC_USER)

Suba o serviço (o sudo é só para o systemd; o túnel roda como $SVC_USER):
  sudo systemctl enable --now dez-tunnel.service
  sudo systemctl status dez-tunnel.service

Validação:
  curl -s -o /dev/null -w '%{http_code}\\n' https://$HOSTNAME_ARG/up

Conferir que o túnel do STG continua intacto:
  sudo systemctl is-active cloudflared
  curl -sI https://sistema.clinicadez.com.br | head -1
EOF
