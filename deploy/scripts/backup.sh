#!/usr/bin/env bash
#
# Backup diário do Sistema DEZ: dump do banco + storage/ + .env.
#
#   backup.sh
#
# Executado pelo dez-backup.timer (03:00). Ver docs/plano_deploy.md §13.
# Retenção: 14 diários + 4 semanais (cópia de domingo).

set -euo pipefail

DEZ_ROOT=/opt/dez
APP="$DEZ_ROOT/app"
ENV_FILE="$APP/backend/.env"
DEST=/var/backups/dez
DIARIO="$DEST/diario"
SEMANAL="$DEST/semanal"
LOG=/var/log/dez/backup.log
KEEP_DIAS=14
KEEP_SEMANAS=4
STAMP=$(date +%F_%H%M)

log() { printf '%s %s\n' "$(date -Is)" "$*" | tee -a "$LOG"; }

mkdir -p "$DIARIO" "$SEMANAL"

if [ ! -f "$ENV_FILE" ]; then
	log "ABORTADO: $ENV_FILE não encontrado"
	exit 1
fi

get() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_HOST=$(get DB_HOST)
DB_PORT=$(get DB_PORT)
DB_NAME=$(get DB_DATABASE)
DB_USER=$(get DB_USERNAME)
DB_PASS=$(get DB_PASSWORD)
DUMP="$DIARIO/$DB_NAME-$STAMP.dump"

# Binários: usar SEMPRE o mesmo conjunto, do diretório versionado do servidor.
# O pg_restore do PATH pode ser de uma versão anterior e recusar o dump com
# "unsupported version (1.16) in file header", reprovando um dump que está bom.
PG_BIN="$(ls -d /usr/lib/postgresql/*/bin 2>/dev/null | sort -V | tail -1)"
if [ -n "$PG_BIN" ] && [ -x "$PG_BIN/pg_dump" ] && [ -x "$PG_BIN/pg_restore" ]; then
	PG_DUMP="$PG_BIN/pg_dump"
	PG_RESTORE="$PG_BIN/pg_restore"
else
	PG_DUMP="$(command -v pg_dump)"
	PG_RESTORE="$(command -v pg_restore)"
fi
log "binários: $PG_DUMP ($("$PG_DUMP" --version))"

log "pg_dump $DB_NAME@$DB_HOST:$DB_PORT"
PGPASSWORD="$DB_PASS" "$PG_DUMP" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -Fc -f "$DUMP"

log "verificando integridade do dump"
if ! "$PG_RESTORE" --list "$DUMP" >/dev/null; then
	log "ABORTADO: dump ilegível para $("$PG_RESTORE" --version) (arquivo mantido para inspeção)"
	exit 1
fi

log "arquivando storage/app e .env"
tar -czf "$DIARIO/arquivos-$STAMP.tar.gz" -C "$APP/backend" storage/app .env

if [ "$(date +%u)" = "7" ]; then
	cp -f "$DUMP" "$SEMANAL/"
	log "cópia semanal (domingo) criada"
fi

log "aplicando retenção"
find "$DIARIO" -type f -mtime +"$KEEP_DIAS" -delete
ls -1t "$SEMANAL"/*.dump 2>/dev/null | tail -n +$((KEEP_SEMANAS + 1)) | xargs -r rm -f

log "backup ok ($(du -h "$DUMP" | cut -f1)) — $DUMP"
