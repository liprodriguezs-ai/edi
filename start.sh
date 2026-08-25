#!/usr/bin/env bash
#
# Arranque del entorno local. Seguro de re-correr: cada paso verifica si el
# trabajo ya está hecho y lo saltea. La primera vez instala y provisiona; las
# siguientes sólo levanta.
#
#   ./start.sh              stack completo (termina corriendo los procesos)
#   ./start.sh --check      diagnostica y no arranca nada
#   ./start.sh --no-front   sin el dev server del frontend
#   ./start.sh --fresh      borra la base y re-siembra antes de arrancar
#
# Corre en macOS, Linux y Windows vía WSL2 (Sail es un script `sh`).
#
# ─────────────────────────────────────────────────────────────────────────────
# ESTE SCRIPT ESTÁ HOMOLOGADO ENTRE PROYECTOS.
#
# Sólo el bloque CONFIG de abajo cambia de un repo a otro. Del separador
# "CUERPO COMÚN" para abajo es idéntico byte a byte en todos los proyectos:
# si arreglás un bug ahí, copiá el cambio a los demás repos.
# ─────────────────────────────────────────────────────────────────────────────

set -uo pipefail

# ═════════════════════════════════════════════════════════════════════════════
# CONFIG DEL PROYECTO — lo único que cambia entre repos
# ═════════════════════════════════════════════════════════════════════════════

# Debe coincidir con COMPOSE_PROJECT_NAME en backend/.env. Si no coincide, sail
# reutiliza los contenedores de OTRO proyecto (ver la validación más abajo).
#
# Los volúmenes de Postgres se derivan de acá (`edi_sail-pgsql`), así que
# renombrarlo dejaría la base de desarrollo huérfana.
PROJECT_NAME="edi"
DB_NAME="edi"

# valkey/mailpit/rustfs viven en backend/docker/devshared.yaml, compartidos por
# todos los proyectos. Este repo se cuelga de su red `devshared`.
USES_SHARED_STACK=true

# Monorepo plano: backend/, frontend/ y landing/ viven en este mismo repo.
HAS_SUBMODULES=false

# Vacío a propósito: frontend/ no tiene .nvmrc ni `engines`, así que este
# proyecto no fija versión de Node. Se usa la que esté activa.
NODE_MAJOR=""

FRONTEND_DEV_CMD="pnpm dev:localhost"

# Puertos que este proyecto publica al host. Cada uno tiene que estar libre o
# `sail up` muere a medias con "port is already allocated".
PORT_VARS="FORWARD_DB_PORT APP_PORT VITE_PORT"

# Se muestra cuando el script crea el .env desde el example.
ENV_HINTS="COMPOSE_PROJECT_NAME=edi · DB_DATABASE=edi · DB_HOST=pgsql · DB_PORT=5432
    MAIL_MAILER=smtp · MAIL_HOST=mailpit · MAIL_PORT=1025
    FORWARD_DB_PORT/APP_PORT/VITE_PORT propios, para no chocar con otros proyectos"

# Chequeos de .env propios del proyecto. Sumá `problems=1` para bloquear.
# ── Sincronización de IP de LAN ──────────────────────────────────────────────
# Portado de kivo7/residia (mismo scaffold). Los dev servers se atan a la IP de
# LAN, y Sanctum ata la cookie de refresh al ORIGEN: si el front y la API no
# comparten host, la sesión se cae a /login y parece que "el backend no levanta".
# Antes esto se arreglaba a mano y se volvía a romper al siguiente DHCP — este
# .env llegó a acumular una IP muerta durante días.
#
# OJO: .env.production NO se toca — apunta a producción a propósito.
FRONTEND_ENV_FILES=(".env" ".env.localhost" ".env.staging")

detect_lan_ip() {
  local ip='' iface
  # macOS: la interfaz de la ruta por defecto, no la primera que aparezca
  if command -v route >/dev/null 2>&1 && command -v ipconfig >/dev/null 2>&1; then
    iface="$(route -n get default 2>/dev/null | awk '/interface:/{print $2; exit}')"
    [ -n "$iface" ] && ip="$(ipconfig getifaddr "$iface" 2>/dev/null)"
  fi
  # Linux / WSL2
  if [ -z "$ip" ] && command -v ip >/dev/null 2>&1; then
    ip="$(ip -4 route get 1.1.1.1 2>/dev/null \
      | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')"
  fi
  printf '%s' "$ip"
}

# Reemplaza (o agrega) KEY=VAL. Si la clave está repetida deja SOLO la primera:
# Laravel se queda con la ÚLTIMA, así que un duplicado olvidado gana en silencio.
# Con awk y archivo temporal porque `sed -i` no es portable entre BSD y GNU.
set_env_var() {
  local file="$1" key="$2" val="$3" tmp
  [ -f "$file" ] || return 0
  tmp="$(mktemp)" || return 1
  KEY="$key" VAL="$val" awk '
    BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VAL"]; done = 0 }
    index($0, k "=") == 1 { if (!done) { print k "=" v; done = 1 } next }
    { print }
    END { if (!done) print k "=" v }
  ' "$file" > "$tmp" && cat "$tmp" > "$file"
  rm -f "$tmp"
}

# Puerto REAL del dev server del frontend.
#
# NO es `VITE_PORT` de backend/.env: esa variable es la que el contenedor
# `laravel.test` publica al host, y en este proyecto Vite NO corre en Docker sino
# en el host, con su puerto fijado en vite.config.ts. Usar VITE_PORT deja
# SANCTUM_STATEFUL_DOMAINS apuntando a un puerto donde no hay nada, y la sesión
# se cae sin explicación.
detect_frontend_port() {
  local port=''
  [ -f "$FRONTEND/vite.config.ts" ] && port="$(
    grep -oE '^[[:space:]]*port:[[:space:]]*[0-9]+' "$FRONTEND/vite.config.ts" \
      | head -1 | grep -oE '[0-9]+'
  )"
  [ -z "$port" ] && port="$(env_value VITE_PORT)"
  printf '%s' "${port:-5173}"
}

sync_lan_ip() {
  local ip app_port vite_port want_api changed=0 f
  ip="$(detect_lan_ip)"
  if [ -z "$ip" ]; then
    warn "no pude detectar la IP de LAN — dejo los .env como están"
    return 0
  fi

  app_port="$(env_value APP_PORT)"; app_port="${app_port:-80}"
  vite_port="$(detect_frontend_port)"
  want_api="http://${ip}:${app_port}/api"

  # ¿Hace falta tocar algo?
  [ "$(env_value APP_URL)" = "http://${ip}:${app_port}" ] || changed=1
  [ "$(env_value APP_FRONTEND_URL)" = "http://${ip}:${vite_port}" ] || changed=1
  for f in "${FRONTEND_ENV_FILES[@]}"; do
    [ -f "$FRONTEND/$f" ] || continue
    grep -qxF "VITE_API_BASE_URL=${want_api}" "$FRONTEND/$f" || changed=1
  done

  if [ "$changed" = "0" ]; then
    skip "IP de LAN ya sincronizada ($ip)"
    return 0
  fi

  if [ "$CHECK_ONLY" = true ]; then
    warn "los .env apuntan a otra IP — corré ./start.sh (sin --check) para sincronizar a $ip"
    return 0
  fi

  set_env_var "$BACKEND/.env" APP_URL          "http://${ip}:${app_port}"
  set_env_var "$BACKEND/.env" APP_FRONTEND_URL "http://${ip}:${vite_port}"
  set_env_var "$BACKEND/.env" SANCTUM_STATEFUL_DOMAINS \
    "${ip}:${vite_port},localhost,localhost:${vite_port},127.0.0.1,127.0.0.1:${vite_port}"

  for f in "${FRONTEND_ENV_FILES[@]}"; do
    [ -f "$FRONTEND/$f" ] && set_env_var "$FRONTEND/$f" VITE_API_BASE_URL "$want_api"
  done

  ok "IP de LAN sincronizada: $ip (API :$app_port · front :$vite_port)"

  # Si el contenedor ya estaba arriba leyó el .env viejo al bootear.
  if docker ps --filter "name=${PROJECT_NAME}-laravel.test" --filter status=running -q 2>/dev/null | grep -q .; then
    docker restart "${PROJECT_NAME}-laravel.test-1" >/dev/null 2>&1 \
      && ok "backend reiniciado para tomar la IP nueva"
  fi
}


project_env_checks() {
  local host

  sync_lan_ip

  host="$(env_value DB_HOST)"
  [[ "$host" == "pgsql" ]] \
    && ok "DB_HOST=pgsql (red interna de docker)" \
    || warn "DB_HOST=$host — con sail debe ser 'pgsql', no una IP"

  # mailpit es el único servicio compartido que este proyecto usa de verdad:
  # cache va a `file`, sesión a `database`, cola en `sync` y media al disco
  # `public`, así que valkey y rustfs hoy no se tocan.
  [[ "$(env_value MAIL_HOST)" == "mailpit" ]] \
    && ok "MAIL_HOST=mailpit (compartido)" \
    || warn "MAIL_HOST=$(env_value MAIL_HOST) — con el stack compartido debe ser 'mailpit'"
}

# Se llama ANTES de migrar. Devuelve 0 si hay que sembrar.
project_needs_seed() {
  local tables
  tables=$(db_query "select count(*) from information_schema.tables where table_schema='public';")
  [[ "${tables:-0}" -lt 20 ]]
}

# Procesos en foreground. Este proyecto no tiene Reverb ni Horizon: la cola está
# en `sync` (los jobs corren inline), así que lo único que va acá es el frontend,
# que lo lanza el cuerpo común.
project_processes() { :; }

# Panel de URLs. El cuerpo común lo llama AL FINAL, con Vite ya escuchando, para
# que los puertos que imprime sean los reales y no "-- no levantado --".
project_urls() {
  [ -x "$BACKEND/bin/urls.sh" ] && "$BACKEND/bin/urls.sh"
}
# ═════════════════════════════════════════════════════════════════════════════
# CUERPO COMÚN — idéntico en todos los repos homologados. No editar por proyecto.
# ═════════════════════════════════════════════════════════════════════════════

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND="$ROOT/backend"
FRONTEND="$ROOT/frontend"
SAIL="$BACKEND/vendor/bin/sail"

# Los servicios compartidos viven DENTRO del repo (versionados, clonables), pero
# se arrancan como un proyecto de compose aparte. La identidad la da `-p`, no la
# ruta del archivo: por eso cada repo puede llevar su copia y aun así todos
# comparten UN solo juego de contenedores.
#
# Va en backend/docker/ y NO en la raíz del monorepo a propósito: `backend` es un
# submódulo con su propio repo, y su compose.yaml declara la red `devshared` como
# externa. Si el archivo que la crea viviera en la raíz, quien clone SOLO el
# backend se quedaría sin forma de levantarla y `sail up` fallaría sin salida.
SHARED_COMPOSE="$BACKEND/docker/devshared.yaml"
SHARED_PROJECT="devshared"
# devshared.yaml le fija a su red `default` el nombre fijo `devshared`, que es el
# que cada proyecto declara como `external: true`. Esa red — no los servicios —
# es lo único que el compose del proyecto necesita para arrancar.
SHARED_NETWORK="devshared"
shared_compose() { docker compose -p "$SHARED_PROJECT" -f "$SHARED_COMPOSE" "$@"; }

CHECK_ONLY=false
RUN_FRONTEND=true
FRESH=false
for arg in "$@"; do
  case "$arg" in
    --check)    CHECK_ONLY=true ;;
    --no-front) RUN_FRONTEND=false ;;
    --fresh)    FRESH=true ;;
    -h|--help)  sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)          printf "Opción desconocida: %s\n" "$arg"; exit 1 ;;
  esac
done

if [ -t 1 ]; then
  BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
  BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi

step() { printf "\n${BOLD}▸ %s${RESET}\n" "$1"; }
ok()   { printf "  ${GREEN}✓${RESET} %s\n" "$1"; }
skip() { printf "  ${DIM}•${RESET} ${DIM}%s${RESET}\n" "$1"; }
warn() { printf "  ${YELLOW}!${RESET} %s\n" "$1"; }
fail() { printf "  ${RED}✗${RESET} %s\n" "$1"; }
die()  { printf "\n  ${RED}✗ %s${RESET}\n\n" "$1"; exit 1; }

problems=0

sail() { ( cd "$BACKEND" && ./vendor/bin/sail "$@" ); }

# Lee un valor de backend/.env sin comillas.
env_value() {
  [ -f "$BACKEND/.env" ] || return 0
  grep -E "^${1}=" "$BACKEND/.env" 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"'\'
}

# Query de una sola celda contra el Postgres del proyecto.
db_query() {
  ( cd "$BACKEND" && docker compose exec -T pgsql \
      psql -U "$(env_value DB_USERNAME)" -d "$DB_NAME" -tAc "$1" 2>/dev/null ) | tr -d '[:space:]'
}

# Un puerto ocupado por un contenedor NUESTRO no es un choque (es un reinicio).
port_owner() {
  local port="$1" name
  name=$(docker ps --format '{{.Names}}\t{{.Ports}}' 2>/dev/null | awk -v p=":$port->" '$0 ~ p {print $1; exit}')
  [[ -n "$name" ]] && { echo "$name"; return; }
  lsof -nP -iTCP:"$port" -sTCP:LISTEN >/dev/null 2>&1 && echo "otro proceso"
}

# ¿Hay algún proceso de Vite de ESTE frontend ya escuchando? Se usa para no
# imprimir el panel de URLs antes de que el dev server bindee su puerto.
frontend_listening() {
  local pid
  for pid in $(pgrep -f "vite" 2>/dev/null); do
    ps -o args= -p "$pid" 2>/dev/null | grep -q "$FRONTEND" || continue
    lsof -nP -iTCP -sTCP:LISTEN -a -p "$pid" >/dev/null 2>&1 && return 0
  done
  return 1
}

pids=()
# Corre un comando en background con su salida prefijada por [etiqueta].
#
# ── POR QUÉ LAS VARIABLES DE COLOR ───────────────────────────────────────────
# El pipe a `sed` hace que la salida deje de ser un TTY, y casi toda herramienta
# moderna apaga los colores automáticamente cuando detecta eso. Resultado: Vite,
# Horizon y Reverb salían en gris plano. Estas variables se lo fuerzan igual:
#   FORCE_COLOR    — ecosistema Node (Vite usa picocolors, que la respeta)
#   CLICOLOR_FORCE — convención de varias herramientas Unix
#   TERM           — algunas exigen una terminal con capacidad de color
# Los comandos de artisan NO se arreglan acá: viajan a través de
# `docker compose exec -T`, que tampoco da TTY, y el env del host no cruza al
# contenedor. Esos necesitan `--ansi`, que se pasa en project_processes.
run() {
  local label="$1" dir="$2"; shift 2
  (
    cd "$dir" || exit 1
    FORCE_COLOR=1 CLICOLOR_FORCE=1 TERM="${TERM:-xterm-256color}" "$@" 2>&1 \
      | sed "s/^/[$label] /"
  ) &
  pids+=("$!")
}

# ─────────────────────────────────────────────────────────────────────────────
step "Requisitos"

# Dentro de WSL, que falte `docker` casi siempre significa que Docker Desktop
# está en Windows pero su integración con esta distro está apagada.
if ! command -v docker >/dev/null 2>&1; then
  if grep -qi microsoft /proc/version 2>/dev/null; then
    die "Docker no está disponible en esta distro de WSL.
      Abrí Docker Desktop > Settings > Resources > WSL Integration y activala."
  fi
  die "Docker no está instalado. Instalá Docker Desktop y volvé a correr esto."
fi
docker info >/dev/null 2>&1 || die "Docker está instalado pero no responde. Abrí Docker Desktop y reintentá."
ok "Docker corriendo"

# ─────────────────────────────────────────────────────────────────────────────
if [ "$HAS_SUBMODULES" = true ]; then
  step "Submódulos"

  # `git clone` sin --recursive deja los directorios presentes pero VACÍOS, así
  # que testear el directorio no sirve: hay que buscar un archivo que sólo
  # existe cuando el contenido está realmente checkouteado.
  SUBMODULES_READY=true
  [ -f "$BACKEND/composer.json" ] || SUBMODULES_READY=false
  [ -f "$FRONTEND/package.json" ] || SUBMODULES_READY=false

  if [ "$SUBMODULES_READY" = true ]; then
    skip "backend/ y frontend/ ya están checkouteados"
  elif ! command -v git >/dev/null 2>&1; then
    die "Faltan backend/ y/o frontend/, y git no está instalado."
  elif [ ! -e "$ROOT/.git" ]; then
    die "Faltan backend/ y/o frontend/, y esto no parece un repo git.
      Cloná con: git clone --recursive <url>"
  else
    ok "Descargando submódulos"
    git -C "$ROOT" submodule update --init --recursive || die \
"Falló la descarga de submódulos.
      Las URLs de .gitmodules son SSH, así que necesitás una llave SSH cargada
      y con acceso a esa organización. Probá: ssh -T git@github.com"
    [ -f "$BACKEND/composer.json" ] || die "backend/ sigue vacío."
    [ -f "$FRONTEND/package.json" ] || die "frontend/ sigue vacío."
  fi
fi

# ─────────────────────────────────────────────────────────────────────────────
step "Configuración (backend/.env)"

if [ ! -f "$BACKEND/.env" ]; then
  [ -f "$BACKEND/.env.example" ] || die "No existe backend/.env ni backend/.env.example"
  cp "$BACKEND/.env.example" "$BACKEND/.env" || die "No pude crear backend/.env"
  ok ".env creado desde .env.example"
  warn "Ajustá estas variables antes de seguir — los defaults del example NO sirven:"
  printf "    ${DIM}%s${RESET}\n" "$ENV_HINTS"
  printf "\n  Editá ${BOLD}backend/.env${RESET} y volvé a correr este script.\n\n"
  exit 0
fi

# ── LA TRAMPA QUE ESTO EVITA ────────────────────────────────────────────────
# Si COMPOSE_PROJECT_NAME no coincide, `sail up` NO crea los contenedores de
# este proyecto: reutiliza los de OTRO checkout, bind-montados sobre SU código.
# Cualquier `sail artisan` se ejecuta entonces contra ese otro proyecto — ya
# pasó una vez: creó módulos y sembró en la base equivocada.
project="$(env_value COMPOSE_PROJECT_NAME)"
if [ "$project" = "$PROJECT_NAME" ]; then
  ok "COMPOSE_PROJECT_NAME=$PROJECT_NAME"
else
  fail "COMPOSE_PROJECT_NAME=${project:-<vacío>} — debe ser '$PROJECT_NAME', o sail levanta los contenedores de OTRO proyecto"
  problems=1
fi

# DB_NAME vacío = el proyecto no fija un nombre esperado; se toma el del .env.
db="$(env_value DB_DATABASE)"
if [ -z "$DB_NAME" ]; then
  DB_NAME="$db"
  [ -n "$DB_NAME" ] && skip "DB_DATABASE=$DB_NAME" || { fail "DB_DATABASE sin definir"; problems=1; }
elif [ "$db" = "$DB_NAME" ]; then
  ok "DB_DATABASE=$DB_NAME"
else
  fail "DB_DATABASE=${db:-<vacío>} — debe ser '$DB_NAME'"
  problems=1
fi

for var in $PORT_VARS; do
  val="$(env_value "$var")"
  if [ -z "$val" ]; then
    fail "$var sin definir — tomaría el puerto por defecto y chocaría con otro proyecto"
    problems=1
    continue
  fi
  owner="$(port_owner "$val")"
  if [ -z "$owner" ] || [[ "$owner" == "$PROJECT_NAME"-* ]]; then
    ok "$var=$val"
  else
    fail "$var=$val ya está ocupado por '$owner' — elegí otro"
    problems=1
  fi
done

if [ "$USES_SHARED_STACK" = true ]; then
  if [ -f "$SHARED_COMPOSE" ]; then
    ok "backend/docker/devshared.yaml (valkey, mailpit, rustfs)"
  else
    fail "falta $SHARED_COMPOSE — ahí viven valkey/mailpit/rustfs"
    problems=1
  fi
fi

declare -F project_env_checks >/dev/null && project_env_checks

if [ "$problems" = "1" ]; then
  printf "\n  Corregí lo marcado con ${RED}✗${RESET} en backend/.env y volvé a correr.\n\n"
  exit 1
fi

if [ "$CHECK_ONLY" = true ]; then
  printf "\n  Todo listo. Corré ${BOLD}./start.sh${RESET} para arrancar.\n\n"
  exit 0
fi

# ─────────────────────────────────────────────────────────────────────────────
step "Backend — dependencias"

# Sail vive en vendor/, así que las dependencias tienen que existir antes de
# poder invocarlo. Se usa la imagen oficial para no exigir PHP en el host.
# installed.json es el marcador correcto: Composer lo reescribe en cada install,
# mientras que vendor/autoload.php queda intacto si el autoloader no cambió.
if [ -f "$SAIL" ] && [ ! "$BACKEND/composer.lock" -nt "$BACKEND/vendor/composer/installed.json" ]; then
  skip "Dependencias de Composer ya instaladas"
else
  [ -f "$SAIL" ] && warn "composer.lock cambió — reinstalando"
  ok "Instalando dependencias de Composer (en contenedor, no hace falta PHP acá)"
  # COMPOSER_HOME es obligatorio: el contenedor corre con `-u $(id -u)`, que no
  # tiene home, así que Composer cae a `/.composer` y no puede escribir ahí.
  # Mientras todo baje de dist eso es sólo un warning, pero apenas un paquete
  # tiene que clonarse desde source el caché VCS pasa a ser obligatorio y el
  # install muere con "could not create leading directories of /.composer/cache".
  if docker run --rm \
    -u "$(id -u):$(id -g)" \
    -e COMPOSER_HOME=/tmp/composer \
    -v "$BACKEND:/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs --no-interaction
  then
    ok "Dependencias de Composer instaladas"
  elif [ -d "$BACKEND/vendor" ]; then
    # Falla NO fatal si ya hay un vendor/: el caso real que motivó esto es un
    # paquete cuyo tag fue recreado upstream (el commit del lock desapareció),
    # así que la re-descarga falla aunque el código YA esté instalado y en la
    # versión correcta. Tumbar el entorno entero por eso es desproporcionado.
    # Sin vendor/ sí es fatal: no arrancaría nada.
    warn "composer install falló, pero vendor/ ya existe — se sigue con lo instalado"
    warn "Si se repite, revisá el paquete que falla: puede necesitar 'composer update <paquete>'"
  else
    die "Falló composer install y no hay vendor/ — el proyecto no puede arrancar"
  fi
fi

# ─────────────────────────────────────────────────────────────────────────────
step "Contenedores"

# El stack compartido va PRIMERO: el compose del proyecto declara su red como
# externa y no arranca si todavía no existe.
#
# Se compara cuántos servicios están corriendo contra cuántos declara el archivo
# y sólo se llama a `up` si falta alguno. `up -d` ya sería idempotente, pero
# saltearlo del todo evita que un proyecto pueda recrear los contenedores que
# levantó otro si su copia del archivo quedó desincronizada.
# Cuenta servicios corriendo. Va con `grep -c .` y NO con `wc -l` porque, sin
# nada arriba, `compose ps --services` igual imprime una línea vacía: `wc -l`
# devolvería 1 en vez de 0 y un stack de un solo servicio se daría por arriba
# estando caído.
shared_running() { shared_compose ps --services --status running 2>/dev/null | grep -c . | tr -d ' '; }

if [ "$USES_SHARED_STACK" = true ]; then
  shared_want=$(docker compose -f "$SHARED_COMPOSE" config --services 2>/dev/null | grep -c . | tr -d ' ')
  shared_have=$(shared_running)
  if [ "$shared_have" = "$shared_want" ] && [ "$shared_want" != "0" ]; then
    skip "stack compartido ya arriba ($shared_have/$shared_want) — no se toca"
  else
    # La salida se GUARDA en vez de mandarla a /dev/null: cuando esto falla, el
    # mensaje de Docker (un puerto ocupado, una imagen inaccesible) es lo único
    # que sirve para arreglarlo, y antes se perdía dejando sólo un "no se pudo
    # levantar" que no dice nada.
    if shared_err=$(shared_compose up -d 2>&1); then
      ok "stack compartido levantado (valkey, mailpit, rustfs)"
    else
      # Un `up` PARCIAL no justifica tumbar el arranque. Del stack compartido el
      # proyecto sólo necesita la red `devshared`; los servicios son comodidades
      # (mailpit para ver correos, rustfs para archivos) y el día a día se puede
      # trabajar sin alguno. Caso real: un php-fpm del host ocupando el 9000 deja
      # a rustfs abajo, y no hay razón para que eso impida levantar la app.
      #
      # Por eso lo fatal es sólo que la red no exista: ahí `sail up` moriría de
      # todos modos con "network devshared declared as external".
      shared_have=$(shared_running)
      shared_down=$(comm -23 \
        <(docker compose -f "$SHARED_COMPOSE" config --services 2>/dev/null | sort) \
        <(shared_compose ps --services --status running 2>/dev/null | sort) \
        | tr '\n' ' ' | sed 's/ *$//')

      print_shared_err() {
        printf "%s\n" "$shared_err" | grep -iE 'error|cannot|failed' | head -3 \
          | while IFS= read -r line; do printf "      ${DIM}%s${RESET}\n" "$line"; done
      }

      if ! docker network inspect "$SHARED_NETWORK" >/dev/null 2>&1; then
        print_shared_err
        die "No se pudo levantar $SHARED_COMPOSE — sin la red '$SHARED_NETWORK' el proyecto no arranca"
      fi

      warn "stack compartido parcial ($shared_have/$shared_want) — se sigue igual"
      warn "sin levantar: ${shared_down:-—}"
      print_shared_err
    fi
  fi
fi

sail up -d || die "Falló sail up"
ok "Contenedores del proyecto arriba"

printf "  ${DIM}esperando a Postgres...${RESET}"
DB_READY=false
for _ in $(seq 1 45); do
  if sail exec -T pgsql pg_isready -q -U "$(env_value DB_USERNAME)" >/dev/null 2>&1; then
    printf "\r  ${GREEN}✓${RESET} Postgres listo                    \n"
    DB_READY=true
    break
  fi
  sleep 1
  printf "."
done
[ "$DB_READY" = true ] || die "Postgres no respondió. Revisá: cd backend && docker compose logs pgsql"

# ─────────────────────────────────────────────────────────────────────────────
step "Backend — aplicación"

if [ -n "$(env_value APP_KEY)" ]; then
  skip "APP_KEY ya generada"
else
  sail artisan key:generate --no-interaction >/dev/null && ok "APP_KEY generada"
fi

# Se decide ANTES de migrar: después de migrar las tablas ya existen y el
# criterio de "base vacía" dejaría de detectarse.
NEEDS_SEED=false
if [ "$FRESH" = true ]; then
  warn "--fresh: borrando y recreando la base"
  sail artisan migrate:fresh --force --no-interaction || die "Falló migrate:fresh"
  NEEDS_SEED=true
else
  if declare -F project_needs_seed >/dev/null && project_needs_seed; then
    NEEDS_SEED=true
  fi
  sail artisan migrate --force --no-interaction >/dev/null || die "Falló migrate"
  ok "Migraciones al día"
fi

# Los seeders no son idempotentes: re-correrlos sobre una base poblada no es seguro.
if [ "$NEEDS_SEED" = true ]; then
  sail artisan db:seed --force --no-interaction || die "Falló db:seed"
  ok "Seeders ejecutados"
else
  skip "Base ya poblada — no se re-siembra"
fi

if [ -e "$BACKEND/public/storage" ]; then
  skip "storage:link ya creado"
else
  sail artisan storage:link >/dev/null 2>&1 && ok "storage:link creado"
fi

declare -F project_post_migrate >/dev/null && project_post_migrate

# ─────────────────────────────────────────────────────────────────────────────
step "Frontend"

declare -F project_frontend_env >/dev/null && project_frontend_env

# NODE_MAJOR vacío = el proyecto NO fija versión de Node (sin .nvmrc, sin
# `engines`, sin `packageManager`): se usa la que esté activa y no se toca nada.
# Con un valor, es obligatorio y se activa vía fnm o nvm, que leen .nvmrc.
CURRENT_MAJOR="$(node -v 2>/dev/null | sed 's/^v\([0-9]*\).*/\1/')"
if [ -z "$CURRENT_MAJOR" ]; then
  die "No hay Node instalado. Instalá fnm o nvm, o bajá Node a mano."
elif [ -z "$NODE_MAJOR" ]; then
  skip "Node $(node -v) — el proyecto no fija una versión"
elif [ "$CURRENT_MAJOR" = "$NODE_MAJOR" ]; then
  skip "Node $(node -v) ya activo"
elif command -v fnm >/dev/null 2>&1; then
  # `fnm env` pone el symlink de fnm en PATH y `fnm use` lo reapunta, así que
  # los dos tienen que correr en ESTA shell — un subshell perdería el cambio.
  eval "$(fnm env)"
  cd "$FRONTEND" && fnm use --install-if-missing >/dev/null 2>&1
  cd "$ROOT"
  ok "Node $(node -v) activado con fnm"
elif [ -s "${NVM_DIR:-$HOME/.nvm}/nvm.sh" ]; then
  # shellcheck disable=SC1091
  . "${NVM_DIR:-$HOME/.nvm}/nvm.sh"
  cd "$FRONTEND" && nvm install >/dev/null 2>&1 && nvm use >/dev/null 2>&1
  cd "$ROOT"
  ok "Node $(node -v) activado con nvm"
else
  die "Necesitás Node ${NODE_MAJOR}.x y tenés $(node -v 2>/dev/null || echo 'ninguno'). Instalá fnm o nvm."
fi

if [ -n "$NODE_MAJOR" ]; then
  [ "$(node -v | sed 's/^v\([0-9]*\).*/\1/')" = "$NODE_MAJOR" ] \
    || die "No pude activar Node ${NODE_MAJOR}.x (activo: $(node -v))."
fi

command -v corepack >/dev/null 2>&1 && corepack enable >/dev/null 2>&1

# pnpm escribe .modules.yaml en cada install; el mtime de node_modules no sirve
# porque pnpm mayormente enlaza en vez de escribir adentro.
if [ -f "$FRONTEND/node_modules/.modules.yaml" ] && [ ! "$FRONTEND/pnpm-lock.yaml" -nt "$FRONTEND/node_modules/.modules.yaml" ]; then
  skip "node_modules ya instalado"
else
  [ -d "$FRONTEND/node_modules" ] && warn "pnpm-lock.yaml cambió — reinstalando"
  ( cd "$FRONTEND" && pnpm install ) || die "Falló pnpm install"
  ok "Dependencias del frontend instaladas"
fi

# ─────────────────────────────────────────────────────────────────────────────
step "Procesos"

cleanup() {
  printf "\n  Deteniendo procesos...\n"
  for pid in "${pids[@]:-}"; do kill "$pid" 2>/dev/null || true; done
  wait 2>/dev/null || true

  # Matar los PID del host NO alcanza cuando el proceso real corre DENTRO del
  # contenedor: `sail artisan X` es un `docker compose exec`, y matar ese wrapper
  # deja al proceso de adentro vivo. Consecuencia real: al re-arrancar, Reverb
  # choca con "Address already in use" contra su propia instancia anterior, y los
  # Horizon se van acumulando de a uno por corrida.
  declare -F project_stop >/dev/null && project_stop

  printf "  Listo. Los contenedores siguen arriba (sail down para bajarlos).\n"
}
trap cleanup EXIT INT TERM

printf "  ${DIM}Ctrl+C detiene los procesos, no los contenedores.${RESET}\n\n"

declare -F project_processes >/dev/null && project_processes

if [ "$RUN_FRONTEND" = true ]; then
  # Limpieza defensiva, mismo criterio que project_stop para el backend: un dev
  # server de una corrida anterior que no murió bien sigue ocupando su puerto, y
  # Vite NO falla — salta al siguiente libre. Así se acumulan en silencio
  # (5193, 5194, 5195...) y terminás sin saber cuál es el bueno.
  # Se filtra por la ruta de ESTE frontend, así no toca la de otros proyectos.
  for _stale in $(pgrep -f "vite" 2>/dev/null); do
    ps -o args= -p "$_stale" 2>/dev/null | grep -q "$FRONTEND" \
      && { kill "$_stale" 2>/dev/null; skip "dev server viejo cortado (pid $_stale)"; }
  done

  run vite "$FRONTEND" $FRONTEND_DEV_CMD

  # Esperar a que Vite escuche ANTES de imprimir el panel de URLs. Sin esto el
  # panel lo reporta como "-- no levantado --": lee puertos reales, y en ese
  # instante el proceso todavía no bindeó.
  for _ in $(seq 1 30); do frontend_listening && break; sleep 0.5; done
else
  skip "Frontend omitido (--no-front). Para levantarlo: cd frontend && $FRONTEND_DEV_CMD"
fi

# El panel va al final, con todo ya arriba, para que refleje el estado real.
declare -F project_urls >/dev/null && project_urls

wait
