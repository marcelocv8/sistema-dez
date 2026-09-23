#!/usr/bin/env bash
#
# P12 — unifica os médicos da agenda.
#
# Problema: a agenda provisória foi semeada a partir da grade do STG, que usa
# APELIDOS ("DR. CARLOS MOTA"). Isso criou profissionais só com o apelido e,
# depois, o import pelo ATENDIMENTO trouxe o médico real com nome completo
# ("CARLOS JOSE MOTA DE LIMA") — o mesmo médico aparecia duas vezes.
#
# Este script:
#   1. refaz a agenda apontando para o médico REAL (a relação agenda↔médico vem
#      do atendimento: tb_agenda_exames.horarioagenda_id + .medico_agenda);
#   2. remove os profissionais criados por apelido e a agenda deles
#      (`--prune-nicknames`), preservando qualquer um que tenha atendimento;
#   3. rematerializa a agenda e otimiza os caches.
#
# É idempotente. Uso:
#   sudo -u dez bash /opt/dez/app/deploy/scripts/unificar-medicos-apelido.sh --dry-run
#   sudo -u dez bash /opt/dez/app/deploy/scripts/unificar-medicos-apelido.sh
#
# Ver docs/plano_deploy.md §20.6 e §22.

set -euo pipefail

ARTISAN=/opt/dez/bin/artisan
MODE="${1:-}"

if [ "$MODE" = "--dry-run" ]; then
	OPT="--dry-run"
	LOG_TAG="DRY-RUN"
else
	OPT="--commit"
	LOG_TAG="COMMIT"
fi

[ -x "$ARTISAN" ] || { echo "artisan não encontrado em $ARTISAN" >&2; exit 1; }

log() { printf '\n\033[1;34m==> [%s]\033[0m %s\n' "$LOG_TAG" "$*"; }

log "1/3 refazendo a agenda pelo médico REAL e removendo os apelidos"
"$ARTISAN" stg:import-grade "$OPT" --procedure=499 --prune-nicknames

if [ "$MODE" = "--dry-run" ]; then
	log "dry-run concluído — nada foi gravado"
	exit 0
fi

log "2/3 rematerializando a agenda (90 dias)"
"$ARTISAN" schedule:materialize

log "3/3 otimizando caches"
"$ARTISAN" optimize

printf '\n\033[1;32m==> Unificação concluída\033[0m\n'
echo "Conferir: sudo -u dez $ARTISAN tinker --execute 'printf(\"profissionais=%d (sem legacy_id=%d) ofertas=%d ocorrencias=%d\n\", DB::table(\"professionals\")->whereNull(\"deleted_at\")->count(), DB::table(\"professionals\")->whereNull(\"deleted_at\")->whereNull(\"legacy_id\")->count(), DB::table(\"service_offerings\")->count(), DB::table(\"schedule_occurrences\")->count());'"
