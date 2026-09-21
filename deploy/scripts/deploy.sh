#!/usr/bin/env bash
#
# Deploy do Sistema DEZ no servidor da Clínica DEZ.
#
#   deploy.sh <artefato.tgz>        artefato do CI (vendor/ + dist/<app>/)
#   deploy.sh --rollback <commit>   volta o código (e o artefato, se guardado)
#
# Garantias:
#   - aborta se a árvore de trabalho do git estiver suja;
#   - git pull --ff-only (nunca merge/force);
#   - rollback automático (código + vendor/dist) em qualquer falha;
#   - health check em /up antes de considerar o deploy concluído;
#   - log append em /var/log/dez/deploy.log.
#
# O usuário `dez` só tem sudo para reiniciar os units do próprio sistema
# (ver deploy/systemd/sudoers.dez-deploy).
#
# Detalhes: docs/plano_deploy.md §12.3.

set -euo pipefail

DEZ_ROOT=/opt/dez
REPO="$DEZ_ROOT/app"
APP="$REPO/backend"
FRONT="$DEZ_ROOT/frontend"
RELEASES="$DEZ_ROOT/releases"
ARTISAN="$DEZ_ROOT/bin/artisan"
LOG=/var/log/dez/deploy.log
HEALTH=http://127.0.0.1:8002/up
KEEP_RELEASES=5
APPS=(equipe agendamento paciente painel-tv)

log() { printf '%s %s\n' "$(date -Is)" "$*" | tee -a "$LOG"; }

run_artisan() {
	if [ "$(id -u)" -eq 0 ]; then
		sudo -u dez "$ARTISAN" "$@"
	else
		"$ARTISAN" "$@"
	fi
}

publish_artifact() {
	local artifact="$1" work app
	work=$(mktemp -d /tmp/dez-deploy.XXXXXX)
	tar -xzf "$artifact" -C "$work"

	if [ ! -d "$work/vendor" ]; then
		log "artefato inválido: vendor/ ausente"
		rm -rf "$work"
		return 1
	fi
	for app in "${APPS[@]}"; do
		if [ ! -d "$work/dist/$app" ]; then
			log "artefato inválido: dist/$app ausente"
			rm -rf "$work"
			return 1
		fi
	done

	rsync -a --delete "$work/vendor/" "$APP/vendor/"
	for app in "${APPS[@]}"; do
		mkdir -p "$FRONT/$app"
		rsync -a --delete "$work/dist/$app/" "$FRONT/$app/"
	done

	rm -rf "$work"
	log "vendor/ e os 4 frontends publicados"
}

restart_services() {
	sudo /usr/bin/systemctl restart dez-web.service
	sudo /usr/bin/systemctl restart dez-schedule.timer
}

health_check() {
	sleep 2
	curl -fsS -o /dev/null "$HEALTH"
}

# ---------------------------------------------------------------- rollback manual
if [ "${1:-}" = "--rollback" ]; then
	TARGET="${2:?informe o commit de destino}"
	git -C "$REPO" reset --hard "$TARGET"
	if [ -f "$RELEASES/$TARGET.tgz" ]; then
		publish_artifact "$RELEASES/$TARGET.tgz"
	else
		log "aviso: sem artefato guardado para $TARGET; vendor/ e dist/ continuam os atuais"
	fi
	restart_services
	log "rollback manual concluído para $TARGET"
	exit 0
fi

# ------------------------------------------------------------------------ deploy
ARTIFACT="${1:-}"
if [ -z "$ARTIFACT" ] || [ ! -f "$ARTIFACT" ]; then
	log "uso: deploy.sh <artefato.tgz> | deploy.sh --rollback <commit>"
	exit 1
fi

if [ -n "$(git -C "$REPO" status --porcelain)" ]; then
	log "ABORTADO: árvore de trabalho suja em $REPO"
	git -C "$REPO" status --short | tee -a "$LOG"
	exit 1
fi

mkdir -p "$RELEASES"
PREV=$(git -C "$REPO" rev-parse HEAD)

on_error() {
	log "FALHA no deploy — revertendo para $PREV"
	git -C "$REPO" reset --hard "$PREV" || true
	if [ -f "$RELEASES/$PREV.tgz" ]; then
		publish_artifact "$RELEASES/$PREV.tgz" || true
	fi
	restart_services || true
	exit 1
}
trap on_error ERR

log "atualizando código ($PREV -> origin/main)"
git -C "$REPO" fetch --prune origin
git -C "$REPO" pull --ff-only origin main
NEW=$(git -C "$REPO" rev-parse HEAD)

publish_artifact "$ARTIFACT"

log "migrations"
run_artisan migrate --force

log "otimizando caches (config/route/view/event)"
run_artisan optimize

log "reiniciando serviços"
restart_services

if ! health_check; then
	log "health check falhou em $HEALTH"
	on_error
fi

cp -f "$ARTIFACT" "$RELEASES/$NEW.tgz"
ls -1t "$RELEASES"/*.tgz 2>/dev/null | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -f

log "deploy ok: $PREV -> $NEW"
