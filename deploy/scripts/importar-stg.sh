#!/usr/bin/env bash
#
# Importa (ou re-importa) TODOS os dados do legado STG no Sistema DEZ.
#
#   sudo -u dez /opt/dez/app/deploy/scripts/importar-stg.sh            # executa
#   sudo -u dez /opt/dez/app/deploy/scripts/importar-stg.sh --dry-run  # só simula
#
# É IDEMPOTENTE: pode rodar quantas vezes quiser. Cada comando casa por
# legacy_id / search_name / idempotency_key, então nada duplica.
#
# O que faz, na ORDEM correta (a ordem importa: o catálogo precisa existir antes
# dos modelos, e os médicos/pacientes antes dos atendimentos):
#   1. catálogo + preços           (arquivos da extração)
#   2. modelos de laudo            (arquivos da extração)
#   3. médicos                     (do ATENDIMENTO: fonte confiável; a grade usa apelidos)
#   4. pacientes                   (só quem teve atendimento em 24 meses)
#   5. atendimentos                (24 meses, criando as ofertas que faltarem)
#   6. agenda: materializa 90 dias
#   7. otimiza caches
#
# Ver docs/plano_deploy.md §22 para o mapa do schema do legado e as armadilhas.

set -euo pipefail

ARTISAN=/opt/dez/bin/artisan
APP=/opt/dez/app/backend
MODE="${1:-}"

if [ "$MODE" = "--dry-run" ]; then
	OPT="--dry-run"
	LOG_TAG="DRY-RUN"
else
	OPT="--commit"
	LOG_TAG="COMMIT"
fi

[ -x "$ARTISAN" ] || { echo "artisan não encontrado em $ARTISAN" >&2; exit 1; }
[ -f "$APP/.env" ] || { echo ".env não encontrado em $APP" >&2; exit 1; }

log() { printf '\n\033[1;34m==> [%s]\033[0m %s\n' "$LOG_TAG" "$*"; }

started=$(date -Is)
log "1/7 catálogo e preços"
"$ARTISAN" stg:import-catalog "$OPT"

log "2/7 modelos de laudo"
"$ARTISAN" stg:import-report-templates "$OPT"

log "3/7 médicos (fonte: atendimento)"
if [ "$MODE" = "--dry-run" ]; then
	"$ARTISAN" stg:import-doctors --source=appointments --dry-run
else
	"$ARTISAN" stg:import-doctors --source=appointments --commit
fi

log "4/7 pacientes (24 meses)"
"$ARTISAN" stg:import-patients "$OPT" --only-with-appointments-months=24

log "5/7 atendimentos (24 meses; cria ofertas se faltarem)"
if [ "$MODE" = "--dry-run" ]; then
	"$ARTISAN" stg:import-appointments --dry-run --create-offerings
else
	"$ARTISAN" stg:import-appointments --commit --create-offerings
fi

if [ "$MODE" = "--dry-run" ]; then
	log "dry-run concluído — nada foi gravado (nenhum passo 6/7 ou 7/7)"
	exit 0
fi

log "6/7 materializando a agenda (90 dias)"
"$ARTISAN" schedule:materialize

log "7/7 otimizando caches"
"$ARTISAN" optimize

printf '\n\033[1;32m==> Importação concluída\033[0m (início: %s)\n' "$started"
echo "Conferir: sudo -u dez $ARTISAN tinker --execute 'printf(\"pacientes=%d profissionais=%d ofertas=%d atendimentos=%d\n\", DB::table(\"patients\")->count(), DB::table(\"professionals\")->count(), DB::table(\"service_offerings\")->count(), DB::table(\"appointments\")->count());'"
