#!/usr/bin/env bash
# Sestaví instalační balíček dist/nordvarr-<verze>.zip s adresářem nordvarr/.
# Balíček obsahuje config.example.php, nikdy config.php, data, vendor/ ani testy.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "$ROOT/VERSION")"
DIST="${DIST_DIR:-$ROOT/dist}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

if [[ ! "$VERSION" =~ ^[0-9A-Za-z.+-]+$ ]]; then
    echo "Neplatná verze v souboru VERSION: $VERSION" >&2
    exit 1
fi

PKG="$STAGE/nordvarr"
mkdir -p "$PKG/bin"
cp -R "$ROOT/public" "$ROOT/src" "$ROOT/templates" "$ROOT/lang" "$ROOT/migrations" "$ROOT/agent" "$PKG/"
cp "$ROOT/bin/nordvarr.php" "$PKG/bin/"
cp "$ROOT/config.example.php" "$ROOT/VERSION" "$ROOT/.htaccess" "$PKG/"
if [[ -f "$ROOT/docs/NAVOD.md" ]]; then
    mkdir -p "$PKG/docs"
    cp "$ROOT/docs/NAVOD.md" "$PKG/docs/"
fi

# Pojistka: do balíčku nesmí projít konfigurace ani data.
rm -f "$PKG/config.php"
if find "$PKG" \( -name 'config.php' -o -name '*.sqlite' -o -name '*.sqlite-wal' \) | grep -q .; then
    echo "Balíček obsahuje konfiguraci nebo data, sestavení přerušeno." >&2
    exit 1
fi

mkdir -p "$DIST"
ZIP="$DIST/nordvarr-$VERSION.zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -qrX "$ZIP" nordvarr)
echo "$ZIP"
