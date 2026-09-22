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
         "$DEZ_ROOT/.ssh" \
         /var/log/dez /var/backups/dez/{diario,semanal}
chown -R "$DEZ_USER:$DEZ_USER" "$DEZ_ROOT" /var/log/dez /var/backups/dez
chmod 0700 "$DEZ_ROOT/.ssh"

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
DEPLOY_KEY="$DEZ_ROOT/.ssh/dez_deploy"
SSH_CMD="ssh -i $DEPLOY_KEY -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new"

# evita prompt de host key no primeiro fetch (o serviço não é interativo)
if [ ! -s "$DEZ_ROOT/.ssh/known_hosts" ]; then
	ssh-keyscan -t rsa,ecdsa,ed25519 github.com > "$DEZ_ROOT/.ssh/known_hosts" 2>/dev/null || true
	chown "$DEZ_USER:$DEZ_USER" "$DEZ_ROOT/.ssh/known_hosts"
	chmod 0644 "$DEZ_ROOT/.ssh/known_hosts"
fi

if [ -d "$REPO_DIR/.git" ]; then
	warn "repositório já clonado — apenas ajustando a chave de deploy"
	sudo -u "$DEZ_USER" git -C "$REPO_DIR" config core.sshCommand "$SSH_CMD"
	[ -n "$REPO_URL" ] && sudo -u "$DEZ_USER" git -C "$REPO_DIR" remote set-url origin "$REPO_URL"
elif [ -n "$REPO_URL" ] && [ -f "$DEPLOY_KEY" ]; then
	sudo -u "$DEZ_USER" env GIT_SSH_COMMAND="$SSH_CMD" git clone "$REPO_URL" "$REPO_DIR"
	sudo -u "$DEZ_USER" git -C "$REPO_DIR" config core.sshCommand "$SSH_CMD"
elif [ -n "$REPO_URL" ]; then
	warn "sem a chave $DEPLOY_KEY — gere-a e clone manualmente como $DEZ_USER:"
	warn "  sudo -u $DEZ_USER ssh-keygen -t ed25519 -N \"\" -f $DEPLOY_KEY"
	warn "  (cole $DEPLOY_KEY.pub em GitHub > Settings > Deploy keys, SEM write access)"
	warn "  sudo -u $DEZ_USER env GIT_SSH_COMMAND=\"$SSH_CMD\" git clone $REPO_URL $REPO_DIR"
else
	warn "DEZ_REPO_URL não informado: clone manualmente como $DEZ_USER"
fi
if [ -d "$REPO_DIR/deploy/scripts" ]; then
	# o bit de execução não viaja bem pelo Windows; garante aqui
	chmod 0755 "$REPO_DIR"/deploy/scripts/*.sh
fi

log "8/8 próximos passos"
cat <<EOF

Base instalada. Faltam 3 passos (tudo documentado em docs/plano_deploy.md §19/F1):

  1) Banco — cria o role sistema_dez + database (SÓ cria objetos novos, não
     toca no banco legado). Guarde a senha gerada onde preferir:
       SENHA=\$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
       echo "senha do role sistema_dez: \$SENHA"
       PGPASSWORD='<senha-do-postgres>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres \\
         -v app_password="'\$SENHA'" -f $REPO_DIR/deploy/sql/provision.sql

  2) Ambiente, migrations e usuários do app:
       sudo $REPO_DIR/deploy/scripts/bootstrap.sh \\
         --url https://dez.clinicadez.com.br --db-password "\$SENHA" \\
         --admin-email <seu-email> --admin-name "<Seu Nome>"

  3) URL pública — rode como o usuário 'server' (que tem ~/.cloudflared/cert.pem),
     SEM sudo na frente (o script pede sudo internamente):
       $REPO_DIR/deploy/scripts/tunnel.sh dez.clinicadez.com.br

  Depois, subir tudo e validar:
       sudo systemctl enable --now dez-web.service dez-schedule.timer dez-backup.timer dez-tunnel.service
       curl -s -o /dev/null -w '%{http_code}\\n' http://127.0.0.1:$PORT/up
       curl -s -o /dev/null -w '%{http_code}\\n' https://dez.clinicadez.com.br/up

  Conferir que o STG segue no ar:
       curl -sI https://sistema.clinicadez.com.br | head -1
       sudo systemctl is-active apache2 postgresql@16-main cloudflared painel

Nada do STG foi alterado (nem Apache, nem o banco 'dez', nem o túnel dele).
EOF
