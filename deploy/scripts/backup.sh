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

# Binários do PostgreSQL — par da MESMA versão do cluster.
# A máquina tem mais de um cliente instalado (16 do Ubuntu e 17 do PGDG). Um
# pg_dump 17 gera arquivo no formato 1.16, que o pg_restore 16 recusa com
# "unsupported version (1.16) in file header" — foi o erro da 1ª execução, e o
# dump estava bom. Escolhemos a versão do cluster que atende a porta do .env;
# se não descobrir, a maior instalada; senão, o do PATH.
PG_VER="$(grep -lE "^[[:space:]]*port[[:space:]]*=[[:space:]]*${DB_PORT}[[:space:]]*$" \
	/etc/postgresql/*/*/postgresql.conf 2>/dev/null \
	| sed -E 's#^/etc/postgresql/([0-9]+)/.*#\1#' | sort -n | tail -1)"
if [ -z "$PG_VER" ]; then
	PG_VER="$(ls -d /etc/postgresql/*/* 2>/dev/null | sed -E 's#^/etc/postgresql/([0-9]+)/.*#\1#' | sort -n | tail -1)"
fi

if [ -n "$PG_VER" ] && [ -x "/usr/lib/postgresql/$PG_VER/bin/pg_dump" ] && [ -x "/usr/lib/postgresql/$PG_VER/bin/pg_restore" ]; then
	PG_DUMP="/usr/lib/postgresql/$PG_VER/bin/pg_dump"
	PG_RESTORE="/usr/lib/postgresql/$PG_VER/bin/pg_restore"
else
	PG_DUMP="$(command -v pg_dump)"
	PG_RESTORE="$(command -v pg_restore)"
fi
log "postgres client: $PG_DUMP ($("$PG_DUMP" --version))"

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
