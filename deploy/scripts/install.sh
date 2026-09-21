#!/usr/bin/env bash
#
# Provisionamento inicial do Sistema DEZ no servidor da Clínica DEZ.
#
#   sudo DEZ_REPO_URL=git@github.com:marcelocv8/sistema-dez.git ./install.sh
#
# NÃO toca em nada do STG: não instala PHP no host, não mexe no Apache, no
# vhost stg8080.conf, no PostgreSQL existente (além de criar role/database,
# feito à parte por deploy/sql/provision.sql) nem no túnel cloudflared atual.
#
# Detalhes: docs/plano_deploy.md §5, §6, §8, §9.

set -euo pipefail

DEZ_USER=dez
DEZ_ROOT=/opt/dez
FRANKENPHP_VERSION="${FRANKENPHP_VERSION:-latest}"
REPO_URL="${DEZ_REPO_URL:-}"
REPO_DIR="$DEZ_ROOT/app"
PORT=8002

log()  { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[aviso]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[erro]\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "rode com sudo"
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || warn "testado em Ubuntu 24.04; SO detectado: ${PRETTY_NAME:-desconhecido}"

log "1/8 dependências do sistema (rsync, unzip, curl, ca-certificates)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq rsync unzip curl ca-certificates >/dev/null

command -v git >/dev/null || apt-get install -y -qq git >/dev/null

log "2/8 conferindo a porta $PORT"
if ss -lntH "sport = :$PORT" | grep -q .; then
	die "a porta $PORT já está em uso — escolha outra e ajuste deploy/caddy/Caddyfile"
fi

log "3/8 usuário de sistema e diretórios"
if ! id -u "$DEZ_USER" >/dev/null 2>&1; then
	adduser --system --group --home "$DEZ_ROOT" --shell /bin/bash "$DEZ_USER"
else
	warn "usuário $DEZ_USER já existe"
fi
mkdir -p "$DEZ_ROOT"/{bin,frontend,releases,_import/stg,_import/laudos} \
         /var/log/dez /var/backups/dez/{diario,semanal}
chown -R "$DEZ_USER:$DEZ_USER" "$DEZ_ROOT" /var/log/dez /var/backups/dez

log "4/8 FrankenPHP static"
if [ -x "$DEZ_ROOT/bin/frankenphp" ]; then
	warn "frankenphp já instalado ($("$DEZ_ROOT/bin/frankenphp" version 2>/dev/null | head -1))"
else
	tmp=$(mktemp -d)
	curl -fsSL -o "$tmp/frankenphp.tar.gz" \
		"https://github.com/php/frankenphp/releases/${FRANKENPHP_VERSION}/download/frankenphp-linux-x86_64.tar.gz"
	tar -xzf "$tmp/frankenphp.tar.gz" -C "$tmp"
	install -m 0755 -o root -g root "$tmp/frankenphp" "$DEZ_ROOT/bin/frankenphp"
	rm -rf "$tmp"
	"$DEZ_ROOT/bin/frankenphp" version
fi
"$DEZ_ROOT/bin/frankenphp" php-cli -m | grep -qi pdo_pgsql \
	|| die "o binário não traz pdo_pgsql — baixe o build estático oficial"

log "5/8 configuração (php.ini, Caddyfile, wrapper artisan)"
src="$(cd "$(dirname "$0")/.." && pwd)"
install -m 0644 -o root -g root "$src/frankenphp/php.ini" "$DEZ_ROOT/bin/php.ini"
install -m 0644 -o root -g root "$src/caddy/Caddyfile"   "$DEZ_ROOT/Caddyfile"
cat > "$DEZ_ROOT/bin/artisan" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
export PHPRC=/opt/dez/bin
exec /opt/dez/bin/frankenphp php-cli /opt/dez/app/backend/artisan "$@"
EOF
chmod 0755 "$DEZ_ROOT/bin/artisan"
chown root:root "$DEZ_ROOT/bin/artisan"

log "6/8 units do systemd e sudoers"
install -m 0644 -o root -g root "$src"/systemd/dez-web.service     /etc/systemd/system/
install -m 0644 -o root -g root "$src"/systemd/dez-schedule.service /etc/systemd/system/
install -m 0644 -o root -g root "$src"/systemd/dez-schedule.timer  /etc/systemd/system/
install -m 0644 -o root -g root "$src"/systemd/dez-backup.service  /etc/systemd/system/
install -m 0644 -o root -g root "$src"/systemd/dez-backup.timer    /etc/systemd/system/
install -m 0644 -o root -g root "$src"/systemd/dez-tunnel.service  /etc/systemd/system/
sed "s/^dez /$DEZ_USER /" "$src/systemd/sudoers.dez-deploy" > /etc/sudoers.d/dez-deploy
chmod 0440 /etc/sudoers.d/dez-deploy
visudo -c >/dev/null || die "sudoers inválido — reverta /etc/sudoers.d/dez-deploy"
systemctl daemon-reload
systemctl enable dez-schedule.timer dez-backup.timer >/dev/null

log "7/8 repositório em $REPO_DIR"
if [ -d "$REPO_DIR/.git" ]; then
	warn "repositório já clonado — pulando"
elif [ -n "$REPO_URL" ]; then
	sudo -u "$DEZ_USER" git clone "$REPO_URL" "$REPO_DIR"
else
	warn "DEZ_REPO_URL não informado: clone manualmente como $DEZ_USER"
	warn "  sudo -u $DEZ_USER git clone <url-do-repo> $REPO_DIR"
fi
if [ -d "$REPO_DIR/deploy/scripts" ]; then
	# o bit de execução não viaja bem pelo Windows; garante aqui
	chmod 0755 "$REPO_DIR"/deploy/scripts/*.sh
fi

log "8/8 próximos passos"
cat <<EOF

Base instalada. Faltam (tudo documentado em docs/plano_deploy.md):

  1) Banco (precisa do superusuário do PostgreSQL):
       sudo -u postgres psql -p 5432 -d postgres -v app_password="'<SENHA>'" \\
         -f $REPO_DIR/deploy/sql/provision.sql
  2) Ambiente e dados:
       sudo $REPO_DIR/deploy/scripts/bootstrap.sh --url https://dez.clinicadez.com.br
  3) URL pública (túnel NOVO, separado do túnel do STG):
       sudo -u $DEZ_USER $REPO_DIR/deploy/scripts/tunnel.sh dez.clinicadez.com.br
  4) Serviços:
       sudo systemctl start dez-web.service dez-schedule.timer dez-backup.timer dez-tunnel.service
       curl -s -o /dev/null -w '%{http_code}\\n' http://127.0.0.1:$PORT/up

Nada do STG foi alterado.
EOF
