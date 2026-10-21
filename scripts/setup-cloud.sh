#!/usr/bin/env bash
# Příprava vývojového prostředí v cloud VM (Ubuntu 24.04) pro Nordvarr.
# Idempotentní: co už je nainstalované, přeskočí. Spouštět jako root z kořene repozitáře
# nebo odkudkoli (skript si najde repozitář sám).
#
# Potřeba: PHP 8.4 (pdo_sqlite, curl, openssl, mbstring, dom/xml pro PHPUnit), Composer,
# Node.js LTS, Playwright s Chromiem.
#
# PHP 8.4 se zkouší:
#   1. z PPA ondrej/php (potřebuje přístup na keyserver.ubuntu.com a ppa.launchpadcontent.net),
#   2. jinak z oficiálního Docker obrazu php:8.4-cli (mirror.gcr.io nebo public.ecr.aws):
#      souborový systém obrazu se rozbalí do /opt/php84-root a binárka se přes patchelf
#      přepne na vlastní knihovny z obrazu, takže běží nativně bez kontejneru.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1

log() { echo "[setup] $*"; }

php_ok() {
    command -v php >/dev/null 2>&1 || return 1
    php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") && version_compare(PHP_VERSION, "8.5.0", "<") ? 0 : 1);' || return 1
    for ext in pdo_sqlite curl openssl mbstring dom xml xmlwriter tokenizer; do
        php -r "exit(extension_loaded('$ext') ? 0 : 1);" || return 1
    done
}

install_php_ppa() {
    log "Zkouším PHP 8.4 z PPA ondrej/php"
    mkdir -p /etc/apt/keyrings
    if [[ ! -s /etc/apt/keyrings/ondrej-php.gpg ]]; then
        curl -fsSL --max-time 30 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14AA40EC0831756756D7F66C4F4EA0AAE5267A6C' \
            | gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg || return 1
    fi
    echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" \
        > /etc/apt/sources.list.d/ondrej-php.list
    apt-get update || true
    if apt-get install -y php8.4-cli php8.4-sqlite3 php8.4-curl php8.4-mbstring php8.4-xml; then
        update-alternatives --set php /usr/bin/php8.4 || true
        return 0
    fi
    # PPA není dostupné: zdroj odebrat, aby nerozbíjel další apt-get update.
    rm -f /etc/apt/sources.list.d/ondrej-php.list
    apt-get update >/dev/null 2>&1 || true
    return 1
}

install_php_docker() {
    log "Zkouším PHP 8.4 z Docker obrazu php:8.4-cli"
    local root=/opt/php84-root
    command -v patchelf >/dev/null 2>&1 || apt-get install -y patchelf
    command -v docker >/dev/null 2>&1 || return 1
    if ! docker info >/dev/null 2>&1; then
        (dockerd >/var/log/dockerd-setup.log 2>&1 &)
        for _ in $(seq 1 30); do docker info >/dev/null 2>&1 && break; sleep 1; done
    fi
    if [[ ! -x "$root/usr/local/bin/php" ]]; then
        local image=""
        for candidate in mirror.gcr.io/library/php:8.4-cli public.ecr.aws/docker/library/php:8.4-cli php:8.4-cli; do
            if docker pull "$candidate"; then image="$candidate"; break; fi
        done
        [[ -n "$image" ]] || return 1
        rm -rf "$root"
        mkdir -p "$root"
        local cid
        cid="$(docker create "$image")"
        docker export "$cid" | tar -x -C "$root"
        docker rm "$cid" >/dev/null
    fi
    local rpath="$root/usr/local/lib:$root/usr/lib/x86_64-linux-gnu"
    # DT_RPATH (ne RUNPATH), aby se vlastní knihovny použily i pro nepřímé závislosti.
    patchelf --set-interpreter "$root/lib64/ld-linux-x86-64.so.2" --force-rpath --set-rpath "$rpath" "$root/usr/local/bin/php"
    find "$root/usr/local/lib/php/extensions" -name '*.so' -exec patchelf --force-rpath --set-rpath "$rpath" {} \;
    # Cesty k php.ini a rozšířením jsou v binárce pevně /usr/local/..., proto odkazy.
    mkdir -p /usr/local/etc /usr/local/lib
    ln -sfn "$root/usr/local/etc/php" /usr/local/etc/php
    ln -sfn "$root/usr/local/lib/php" /usr/local/lib/php
    ln -sf "$root/usr/local/bin/php" /usr/local/bin/php
    hash -r
}

if php_ok; then
    log "PHP $(php -r 'echo PHP_VERSION;') je v pořádku"
else
    install_php_ppa || install_php_docker || { log "PHP 8.4 se nepodařilo nainstalovat"; exit 1; }
    hash -r
    php_ok || { log "PHP 8.4 po instalaci neprošlo kontrolou"; exit 1; }
    log "Nainstalováno PHP $(php -r 'echo PHP_VERSION;')"
fi

if ! command -v zip >/dev/null 2>&1 || ! command -v unzip >/dev/null 2>&1; then
    apt-get install -y zip unzip
fi

# Systémový ping pro kontrolu typu ping (lokální testy ho nepotřebují, používají falešný ping).
if ! command -v ping >/dev/null 2>&1; then
    apt-get install -y iputils-ping
fi

if ! command -v composer >/dev/null 2>&1; then
    log "Instaluji Composer"
    apt-get install -y composer
fi

if ! command -v node >/dev/null 2>&1; then
    log "Instaluji Node.js"
    apt-get install -y nodejs npm
fi

cd "$ROOT"
log "composer install"
composer install --no-interaction --no-progress

log "npm ci"
npm ci --no-audit --no-fund

if [[ -n "${PLAYWRIGHT_BROWSERS_PATH:-}" ]] && compgen -G "$PLAYWRIGHT_BROWSERS_PATH/chromium-*" >/dev/null; then
    log "Chromium pro Playwright je předinstalované v $PLAYWRIGHT_BROWSERS_PATH"
else
    log "Instaluji Chromium pro Playwright"
    npx playwright install --with-deps chromium
fi

log "Hotovo. Testy: composer test && npm run e2e"
