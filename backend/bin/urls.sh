#!/usr/bin/env bash
#
# Imprime las URLs de todos los servicios levantados, leyendo los puertos REALES
# de Docker en vez de asumir los defaults del compose.
#
# Uso:
#   ./bin/urls.sh
#
# Homologado con whatspapp y employ-secure-pro-monorepo: misma
# estructura y mismos helpers. Las diferencias son de dominio (acá no hay Reverb ni
# Horizon; los otros sí).
#
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

if [ -t 1 ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; CYAN=$'\033[36m'; RESET=$'\033[0m'
else
    BOLD=''; DIM=''; GREEN=''; YELLOW=''; CYAN=''; RESET=''
fi

normalize_host() {
    # El filtro final descarta respuestas basura: para un servicio que NO publica
    # el puerto (como pgsql.test, que es interno), `docker compose port` devuelve
    # cosas como "invalid IP:0" en vez de fallar. Sin esto se imprimía tal cual.
    sed -e 's/^0\.0\.0\.0/localhost/' -e 's/^\[::\]/localhost/' -e 's/^127\.0\.0\.1/localhost/' \
        | grep -E '^[A-Za-z0-9._-]+:[1-9][0-9]{1,4}$' || true
}

# Dirección publicada por un servicio de ESTE proyecto. Vacío si no está arriba.
published_address() {
    local service="$1" container_port="$2"
    docker compose port "$service" "$container_port" 2>/dev/null | tail -n 1 | normalize_host
}

# Igual, pero para los servicios COMPARTIDOS (valkey, mailpit, rustfs). Viven en
# el proyecto de compose `devshared`, no en éste, así que `docker compose port` a
# secas no los encuentra y los reportaría como "no levantado" aunque estén arriba.
shared_address() {
    local service="$1" container_port="$2"
    docker compose -p devshared -f docker/devshared.yaml port "$service" "$container_port" 2>/dev/null \
        | tail -n 1 | normalize_host
}

# Vite corre en el HOST, no en Docker, así que su puerto no se puede preguntar a
# compose. Y NO se puede asumir VITE_PORT: ese puerto lo publica el contenedor
# `laravel.test`, así que Vite lo encuentra ocupado y salta al siguiente libre
# (por eso termina en 5193 y no en 5192). Se busca el proceso real.
vite_address() {
    local frontend_dir port pid
    frontend_dir="$(cd .. && pwd)/frontend"
    # PID más alto primero = el arrancado más recientemente. Importa cuando quedó
    # un dev server viejo dando vueltas: queremos el que acaba de lanzar start.sh,
    # no el sobrante.
    for pid in $(pgrep -f "vite" 2>/dev/null | sort -rn); do
        ps -o args= -p "$pid" 2>/dev/null | grep -q "$frontend_dir" || continue
        port=$(lsof -nP -iTCP -sTCP:LISTEN -a -p "$pid" 2>/dev/null \
            | awk 'NR>1 {print $9}' | grep -oE '[0-9]+$' | sort -n | head -1)
        [ -n "$port" ] && { echo "localhost:$port"; return; }
    done
}

env_value() {
    [ -f .env ] || return 0
    grep -E "^${1}=" .env 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"'\'
}

print_row() {
    local label="$1" address="$2" scheme="$3" credentials="${4:-}" empty_msg="${5:--- no levantado --}"

    if [ -z "$address" ]; then
        printf "  %-14s ${DIM}%s${RESET}\n" "$label" "$empty_msg"
        return
    fi

    printf "  %-14s ${CYAN}%s${RESET}" "$label" "${scheme}${address}"
    [ -n "$credentials" ] && printf "   ${DIM}%s${RESET}" "$credentials"
    printf "\n"
}

if ! docker compose ps --quiet 2>/dev/null | grep -q .; then
    printf "\n  ${YELLOW}No hay servicios levantados en este proyecto.${RESET}\n"
    printf "  ${DIM}Arrancalos con:${RESET}\n"
    printf "  ${DIM}./start.sh (desde la raíz del repo)${RESET}\n\n"
    exit 0
fi

DB_USER="$(env_value DB_USERNAME)"
DB_NAME="$(env_value DB_DATABASE)"

printf "\n  ${BOLD}residia — servicios levantados${RESET}\n"
printf "  ${DIM}%s${RESET}\n" "──────────────────────────────────────────────────────────────"

print_row "API"         "$(published_address laravel.test 80)"  "http://"
print_row "Frontend"    "$(vite_address)"                       "http://" "corre en el host: pnpm dev:localhost"

printf "\n  ${BOLD}Paneles de administración${RESET}\n"
printf "  ${DIM}%s${RESET}\n" "──────────────────────────────────────────────────────────────"

# Este proyecto no tiene Horizon ni Reverb instalados: no hay panel de colas ni
# WebSocket. La cola está en `sync`, o sea que los jobs corren inline.
print_row "Mailpit"     "$(shared_address mailpit 8025)"        "http://" "compartido · sin credenciales"
print_row "RedisInsight" "$(shared_address redisinsight 5540)"  "http://" "compartido · explorar claves de valkey (host: valkey / 6379)"
print_row "rustfs"      "$(shared_address rustfs 9001)"         "http://" "compartido · sail / password"

printf "\n  ${BOLD}Conexiones directas${RESET}\n"
printf "  ${DIM}%s${RESET}\n" "──────────────────────────────────────────────────────────────"

print_row "Postgres"    "$(published_address pgsql 5432)"       "" "${DB_USER:-<DB_USERNAME>} · db: ${DB_NAME:-<DB_DATABASE>}"
# pgsql.test corre pero NO publica puerto al host (es sólo para la suite dentro
# del contenedor), así que el mensaje por defecto "-- no levantado --" mentiría.
print_row "Postgres test" "$(published_address pgsql.test 5432)" "" "db: testing · tmpfs" \
    "corriendo, sin puerto publicado (sólo interno)"
print_row "Valkey"      "$(shared_address valkey 6379)"         "" "compartido · hoy sin uso acá (CACHE_STORE=file, QUEUE=sync)"
print_row "rustfs S3"   "$(shared_address rustfs 9000)"         "http://" "compartido · sail / password"

printf "\n  ${GREEN}Puertos leídos de Docker en vivo, no de los defaults del compose.${RESET}\n"
printf "  ${DIM}Los marcados 'compartido' viven en el proyecto de compose 'devshared'.${RESET}\n\n"
