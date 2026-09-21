#!/usr/bin/env bash
#
# Bootstrap do ambiente de produção do Sistema DEZ (primeira execução).
#
#   sudo /opt/dez/app/deploy/scripts/bootstrap.sh --url https://dez.clinicadez.com.br
#
# Cria o backend/.env a partir de deploy/env.production.example, gera os
# segredos exclusivos deste servidor, roda as migrations e cria o primeiro
# usuário Admin. NUNCA roda `db:seed` puro (o DatabaseSeeder criaria
# test@example.com quando APP_ENV != local).

set -euo pipefail

DEZ_ROOT=/opt/dez
APP="$DEZ_ROOT/app"
BACKEND="$APP/backend"
ARTISAN="$DEZ_ROOT/bin/artisan"
ENV_FILE="$BACKEND/.env"
EXAMPLE="$APP/deploy/env.production.example"

URL=""
DB_PASSWORD="${DEZ_DB_PASSWORD:-}"
ADMIN_EMAIL="${DEZ_ADMIN_EMAIL:-}"
ADMIN_NAME="${DEZ_ADMIN_NAME:-Admin DEZ}"

while [ $# -gt 0 ]; do
	case "$1" in
		--url)          URL="$2"; shift 2 ;;
		--db-password)  DB_PASSWORD="$2"; shift 2 ;;
		--admin-email)  ADMIN_EMAIL="$2"; shift 2 ;;
		--admin-name)   ADMIN_NAME="$2"; shift 2 ;;
		-h|--help)      sed -n '2,12p' "$0"; exit 0 ;;
		*)              echo "opção desconhecida: $1" >&2; exit 1 ;;
	esac
done

[ "$(id -u)" -eq 0 ] || { echo "rode com sudo" >&2; exit 1; }
[ -d "$BACKEND" ]     || { echo "backend não encontrado em $BACKEND" >&2; exit 1; }
[ -n "$URL" ]         || { echo "informe --url https://dez.clinicadez.com.br" >&2; exit 1; }
[ -n "$DB_PASSWORD" ] || { echo "informe --db-password (a mesma do provision.sql)" >&2; exit 1; }

log() { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }

if [ -f "$ENV_FILE" ]; then
	log "backend/.env já existe — preservando (nada sobrescrito)"
else
	log "criando backend/.env"
	install -m 0640 -o dez -g dez "$EXAMPLE" "$ENV_FILE"

	sed -i "s|^APP_URL=.*|APP_URL=$URL|" "$ENV_FILE"
	sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${URL#*://}|" "$ENV_FILE"
	sed -i "s|^FRONTEND_URLS=.*|FRONTEND_URLS=$URL|" "$ENV_FILE"
	sed -i "s|^PORTAL_BASE_URL=.*|PORTAL_BASE_URL=$URL/resultados|" "$ENV_FILE"
	sed -i "s|^BOOKING_BASE_URL=.*|BOOKING_BASE_URL=$URL/agendar|" "$ENV_FILE"
	sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" "$ENV_FILE"
	sed -i "s|^IDENTITY_PEPPER=.*|IDENTITY_PEPPER=$(openssl rand -hex 32)|" "$ENV_FILE"
	sed -i "s|^MANAGEMENT_TOKEN_SECRET=.*|MANAGEMENT_TOKEN_SECRET=$(openssl rand -hex 32)|" "$ENV_FILE"
fi

log "gerando APP_KEY (se ainda não houver)"
grep -q '^APP_KEY=base64:' "$ENV_FILE" || sudo -u dez "$ARTISAN" key:generate --force

log "migrations"
sudo -u dez "$ARTISAN" migrate --force

log "caches de produção"
sudo -u dez "$ARTISAN" optimize

if [ -n "$ADMIN_EMAIL" ]; then
	log "criando Admin $ADMIN_EMAIL"
	sudo -u dez "$ARTISAN" team:user "$ADMIN_EMAIL" --name="$ADMIN_NAME" --role=ADMIN
else
	echo
	echo "Nenhum usuário criado. Crie um por pessoa (a senha é pedida no prompt):"
	echo "  sudo -u dez $ARTISAN team:user <email> --name=\"<Nome>\" --role=ADMIN"
	echo "  perfis: ADMIN | SUPERVISOR | RECEPTION | CASHIER | MANAGER | DOCTOR"
fi

echo
echo "Ambiente pronto. Suba os serviços com:"
echo "  sudo systemctl start dez-web.service dez-schedule.timer dez-backup.timer dez-tunnel.service"
