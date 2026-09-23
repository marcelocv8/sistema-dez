#!/usr/bin/env bash
#
# P12 — unifica os médicos da agenda (apelido da grade × médico real).
#
# Problema: a agenda provisória foi semeada a partir da grade do STG, que usa
# APELIDOS ("DR. CARLOS MOTA"). Depois, o import pelo ATENDIMENTO trouxe o médico
# real com o nome completo ("CARLOS JOSE MOTA DE LIMA") — o mesmo médico
# aparecia duas vezes.
#
# Este script roda `stg:merge-nickname-doctors`, que:
#   1. restaura o que a 1ª tentativa de limpeza removeu (profissionais por
#      apelido + ofertas + janelas — tudo soft-delete);
#   2. identifica o médico real por nome EXATO ou por TOKENS ÚNICOS
#      ("CARLOS MOTA" ⊆ "CARLOS JOSE MOTA DE LIMA"); se houver 0 ou mais de 1
#      candidato, NÃO arrisca;
#   3. MOVE as janelas de agenda para a oferta do médico real (sem perder nada);
#   4. mantém o profissional por apelido quando não dá para identificar (e
#      reporta a lista, para a recepção resolver na tela);
#   5. rematerializa a agenda (90 dias) e otimiza os caches.
#
# Idempotente. Uso:
#   sudo -u dez bash /opt/dez/app/deploy/scripts/unificar-medicos-apelido.sh --dry-run
#   sudo -u dez bash /opt/dez/app/deploy/scripts/unificar-medicos-apelido.sh
#
# Ver docs/plano_deploy.md §20.6.

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

log "1/3 fundindo os apelidos nos médicos reais (move as janelas)"
"$ARTISAN" stg:merge-nickname-doctors "$OPT"

if [ "$MODE" = "--dry-run" ]; then
	log "dry-run concluído — nada foi gravado"
	exit 0
fi

log "2/3 rematerializando a agenda (90 dias)"
"$ARTISAN" schedule:materialize

log "3/3 otimizando caches"
"$ARTISAN" optimize

printf '\n\033[1;32m==> Unificação concluída\033[0m\n'
echo
echo "Conferir no /cadastros e na /agenda: cada médico uma vez, com o nome completo."
echo "Conferir no banco:"
echo "  sudo -u dez $ARTISAN tinker --execute 'printf(\"profissionais=%d (por apelido=%d) ofertas=%d janelas=%d ocorrencias=%d\n\", DB::table(\"professionals\")->whereNull(\"deleted_at\")->count(), DB::table(\"professionals\")->whereNull(\"deleted_at\")->whereNull(\"legacy_id\")->count(), DB::table(\"service_offerings\")->whereNull(\"deleted_at\")->count(), DB::table(\"schedule_windows\")->whereNull(\"deleted_at\")->count(), DB::table(\"schedule_occurrences\")->count());'"
