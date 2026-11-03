#!/usr/bin/env bash
# Nordvarr: hostový agent pro Linux.
#
# Každou minutu (cron nebo systemd timer) pošle do Nordvarru stav serveru:
# CPU, load, RAM, swap, zaplnění disků, teploty, NVIDIA GPU, síťový provoz
# a jednou za 15 minut stav disků ze SMART (smartctl, vyžaduje root), volitelně seznam kontejnerů Dockeru.
# Závislosti: bash 4+, curl, coreutils (df, timeout), volitelně smartmontools, nvidia-smi a docker.
#
# Použití:
#   nordvarr-agent.sh            jedno hlášení (pro cron / systemd timer)
#   nordvarr-agent.sh --test     hlášení s výpisem odpovědi serveru
#   nordvarr-agent.sh --dry-run  jen vypíše JSON, nic neodesílá
#   nordvarr-agent.sh --version
#
# Konfigurace: /etc/nordvarr-agent.conf (vlastník root, práva 600), řádky KLÍČ=hodnota:
#   NORDVARR_URL=https://nordvarr.example.com     (bez mod_rewrite: https://nordvarr.example.com/index.php?r=)
#   NORDVARR_TOKEN=nv_...                          (API token se scope agent:write)
#   NORDVARR_EXTERNAL_ID=host:srv1.example.com     (nepovinné, výchozí host:<hostname>)
#   NORDVARR_OBJECT_ID=<id objektu>                (nepovinné: připojí agenta k existujícímu objektu)
#   NORDVARR_HOSTNAME=srv1.example.com             (nepovinné, výchozí hostname -f)
#   NORDVARR_SMART_INTERVAL=900                    (sekundy mezi čteními SMART, 0 = nečíst)
#   NORDVARR_SMARTCTL=smartctl, NORDVARR_NVIDIA_SMI=nvidia-smi   (nepovinné: jiná cesta k příkazům)
#   NORDVARR_DOCKER=docker                         (nepovinné: cesta k příkazu docker, 0 = kontejnery nehlásit)

set -u
umask 077

AGENT_VERSION="1.1.0"
MODE="send"
case "${1:-}" in
    --dry-run) MODE="dry" ;;
    --test) MODE="test" ;;
    --version) echo "nordvarr-agent $AGENT_VERSION"; exit 0 ;;
    "") ;;
    *) echo "Použití: $0 [--test|--dry-run|--version]" >&2; exit 1 ;;
esac

# Konfigurace: proměnné prostředí mají přednost, soubor se jen čte (nespouští).
CONF="${NORDVARR_CONF:-/etc/nordvarr-agent.conf}"
if [ -r "$CONF" ]; then
    while IFS='=' read -r key value || [ -n "$key" ]; do
        key="${key//[[:space:]]/}"
        value="${value%$'\r'}"
        value="${value#\"}"; value="${value%\"}"; value="${value#\'}"; value="${value%\'}"
        case "$key" in
            NORDVARR_URL|NORDVARR_TOKEN|NORDVARR_EXTERNAL_ID|NORDVARR_OBJECT_ID|NORDVARR_HOSTNAME|NORDVARR_SMART_INTERVAL|NORDVARR_STATE_DIR|NORDVARR_SMARTCTL|NORDVARR_NVIDIA_SMI|NORDVARR_DOCKER)
                [ -z "${!key:-}" ] && printf -v "$key" '%s' "$value" ;;
        esac
    done < "$CONF"
fi

# NORDVARR_ROOT slouží jen testům (předpona pro /proc, /sys a /etc).
ROOT="${NORDVARR_ROOT:-}"
STATE_DIR="${NORDVARR_STATE_DIR:-/var/lib/nordvarr-agent}"
SMART_INTERVAL="${NORDVARR_SMART_INTERVAL:-900}"
URL="${NORDVARR_URL:-}"
TOKEN="${NORDVARR_TOKEN:-}"
SMARTCTL="${NORDVARR_SMARTCTL:-smartctl}"
NVIDIA_SMI="${NORDVARR_NVIDIA_SMI:-nvidia-smi}"
DOCKER="${NORDVARR_DOCKER:-docker}"

if [ "$MODE" != "dry" ] && { [ -z "$URL" ] || [ -z "$TOKEN" ]; }; then
    echo "Chybí NORDVARR_URL nebo NORDVARR_TOKEN (soubor $CONF)." >&2
    exit 1
fi
mkdir -p "$STATE_DIR" 2>/dev/null || { echo "Nelze vytvořit $STATE_DIR." >&2; exit 1; }

# --- Pomocné funkce -----------------------------------------------------------

# Text jako řetězec JSON (uvozovky, zpětná lomítka, řídicí znaky).
jstr() {
    local s
    s="$(printf '%s' "$1" | tr -d '\000-\010\013\014\016-\037')"
    s="${s//\\/\\\\}"; s="${s//\"/\\\"}"; s="${s//$'\t'/\\t}"; s="${s//$'\n'/\\n}"; s="${s//$'\r'/\\r}"
    printf '"%s"' "$s"
}

# Číslo pro JSON, jinak null.
jnum() {
    local v="${1//[[:space:]]/}"
    if [[ "$v" =~ ^-?[0-9]+(\.[0-9]+)?$ ]]; then printf '%s' "$v"; else printf 'null'; fi
}

join_by_comma() {
    local IFS=','
    printf '%s' "$*"
}

read_first() {
    local f="$1"
    [ -r "$f" ] && head -n 1 "$f" 2>/dev/null
}

# --- Základní údaje -----------------------------------------------------------

HOSTNAME_VALUE="${NORDVARR_HOSTNAME:-$(hostname -f 2>/dev/null || hostname 2>/dev/null || cat "$ROOT/proc/sys/kernel/hostname" 2>/dev/null)}"
OS_NAME=""
if [ -r "$ROOT/etc/os-release" ]; then
    OS_NAME="$(sed -n 's/^PRETTY_NAME=//p' "$ROOT/etc/os-release" | head -n 1 | tr -d '"')"
fi
KERNEL="$(read_first "$ROOT/proc/sys/kernel/osrelease")"
UPTIME_S="$(awk '{printf "%d", $1}' "$ROOT/proc/uptime" 2>/dev/null)"
CORES="$(grep -c '^processor' "$ROOT/proc/cpuinfo" 2>/dev/null)"
CPU_MODEL="$(sed -n 's/^model name[[:space:]]*:[[:space:]]*//p' "$ROOT/proc/cpuinfo" 2>/dev/null | head -n 1)"
read -r LOAD1 LOAD5 LOAD15 _ < "$ROOT/proc/loadavg" 2>/dev/null || { LOAD1=""; LOAD5=""; LOAD15=""; }

# Paměť (kB -> B).
MEM_JSON="$(awk '
    /^MemTotal:/ {t=$2*1024} /^MemAvailable:/ {a=$2*1024} /^SwapTotal:/ {st=$2*1024} /^SwapFree:/ {sf=$2*1024}
    END {printf "{\"total_b\":%.0f,\"available_b\":%.0f,\"swap_total_b\":%.0f,\"swap_free_b\":%.0f}", t, a, st, sf}
' "$ROOT/proc/meminfo" 2>/dev/null)"
[ -n "$MEM_JSON" ] || MEM_JSON='{}'

# CPU a síť: průměr od minulého spuštění (rozdíl čítačů v /proc), první běh nemá hodnotu.
PREV="$STATE_DIR/prev"
NOW_UP="$(awk '{print $1}' "$ROOT/proc/uptime" 2>/dev/null)"
read -r _ c_user c_nice c_sys c_idle c_iowait c_irq c_softirq c_steal _ < <(head -n 1 "$ROOT/proc/stat" 2>/dev/null)
CPU_TOTAL=$(( ${c_user:-0} + ${c_nice:-0} + ${c_sys:-0} + ${c_idle:-0} + ${c_iowait:-0} + ${c_irq:-0} + ${c_softirq:-0} + ${c_steal:-0} ))
CPU_IDLE=$(( ${c_idle:-0} + ${c_iowait:-0} ))
NET_NOW="$(awk -F'[: ]+' 'NR > 2 { sub(/^ +/, ""); split($0, f, /[: ]+/); print f[1], f[2], f[10] }' "$ROOT/proc/net/dev" 2>/dev/null \
    | grep -Ev '^(lo|veth|docker|br-|virbr|cali|flannel|cni|kube-ipvs)' )"

CPU_USAGE=""
declare -A NET_PREV_RX NET_PREV_TX
PREV_UP=""
if [ -r "$PREV" ]; then
    read -r PREV_UP PREV_TOTAL PREV_IDLE < <(head -n 1 "$PREV")
    while read -r iface rx tx; do
        NET_PREV_RX["$iface"]="$rx"; NET_PREV_TX["$iface"]="$tx"
    done < <(tail -n +2 "$PREV")
    if [ -n "${PREV_TOTAL:-}" ] && [ "$CPU_TOTAL" -gt "$PREV_TOTAL" ]; then
        CPU_USAGE="$(awk -v t="$((CPU_TOTAL - PREV_TOTAL))" -v i="$((CPU_IDLE - PREV_IDLE))" 'BEGIN {printf "%.1f", 100 * (t - i) / t}')"
    fi
fi
ELAPSED="$(awk -v a="$NOW_UP" -v b="$PREV_UP" 'BEGIN { if (b != "" && a > b) printf "%.3f", a - b }')"

NET_ITEMS=()
while read -r iface rx tx; do
    [ -n "$iface" ] || continue
    rx_bps="null"; tx_bps="null"
    if [ -n "$ELAPSED" ] && [ -n "${NET_PREV_RX[$iface]:-}" ] && [ "$rx" -ge "${NET_PREV_RX[$iface]}" ] && [ "$tx" -ge "${NET_PREV_TX[$iface]}" ]; then
        rx_bps="$(awk -v d="$((rx - NET_PREV_RX[$iface]))" -v e="$ELAPSED" 'BEGIN {printf "%.0f", d / e}')"
        tx_bps="$(awk -v d="$((tx - NET_PREV_TX[$iface]))" -v e="$ELAPSED" 'BEGIN {printf "%.0f", d / e}')"
    fi
    NET_ITEMS+=("{\"iface\":$(jstr "$iface"),\"rx_bps\":$(jnum "$rx_bps"),\"tx_bps\":$(jnum "$tx_bps")}")
done <<< "$NET_NOW"

{
    echo "$NOW_UP $CPU_TOTAL $CPU_IDLE"
    printf '%s\n' "$NET_NOW"
} > "$PREV.tmp" && mv "$PREV.tmp" "$PREV"

# --- Disky (zaplnění a inody) -----------------------------------------------

SKIP_FS='^(tmpfs|devtmpfs|squashfs|overlay|ramfs|efivarfs|proc|sysfs|cgroup2?|devpts|securityfs|pstore|bpf|tracefs|debugfs|fusectl|configfs|mqueue|hugetlbfs|autofs|nsfs|iso9660|fuse\..*)$'
declare -A INODE_TOTAL INODE_USED
while read -r _ fstype itotal iused _ _ mount; do
    INODE_TOTAL["$mount"]="$itotal"; INODE_USED["$mount"]="$iused"
done < <(df -P -i -T 2>/dev/null | tail -n +2)

DISK_ITEMS=()
while read -r device fstype size used avail _ mount; do
    [ -n "$mount" ] || continue
    [[ "$fstype" =~ $SKIP_FS ]] && continue
    DISK_ITEMS+=("{\"mount\":$(jstr "$mount"),\"device\":$(jstr "$device"),\"fs\":$(jstr "$fstype"),\"size_b\":$(jnum "$size"),\"used_b\":$(jnum "$used"),\"avail_b\":$(jnum "$avail"),\"inodes_total\":$(jnum "${INODE_TOTAL[$mount]:-}"),\"inodes_used\":$(jnum "${INODE_USED[$mount]:-}")}")
done < <(df -P -B1 -T 2>/dev/null | tail -n +2)

# --- Teploty (hwmon, jinak thermal_zone) --------------------------------------

milli() { awk -v v="$1" 'BEGIN { if (v ~ /^-?[0-9]+$/) printf "%.1f", v / 1000 }'; }

TEMP_ITEMS=()
for dir in "$ROOT"/sys/class/hwmon/hwmon*; do
    [ -d "$dir" ] || continue
    chip="$(read_first "$dir/name")"
    for input in "$dir"/temp*_input; do
        [ -r "$input" ] || continue
        base="${input%_input}"
        c="$(milli "$(read_first "$input")")"
        [ -n "$c" ] || continue
        TEMP_ITEMS+=("{\"chip\":$(jstr "$chip"),\"label\":$(jstr "$(read_first "${base}_label")"),\"c\":$(jnum "$c"),\"max_c\":$(jnum "$(milli "$(read_first "${base}_max")")"),\"crit_c\":$(jnum "$(milli "$(read_first "${base}_crit")")")}")
    done
done
if [ "${#TEMP_ITEMS[@]}" -eq 0 ]; then
    for zone in "$ROOT"/sys/class/thermal/thermal_zone*; do
        [ -r "$zone/temp" ] || continue
        c="$(milli "$(read_first "$zone/temp")")"
        [ -n "$c" ] && TEMP_ITEMS+=("{\"chip\":$(jstr "$(read_first "$zone/type")"),\"label\":\"\",\"c\":$(jnum "$c")}")
    done
fi

# --- NVIDIA GPU ---------------------------------------------------------------

GPU_ITEMS=()
if command -v "$NVIDIA_SMI" >/dev/null 2>&1; then
    while IFS=',' read -r idx name util memused memtotal temp power fan; do
        [ -n "$idx" ] || continue
        GPU_ITEMS+=("{\"index\":$(jnum "$idx"),\"name\":$(jstr "$(echo "$name" | sed 's/^ *//; s/ *$//')"),\"util_pct\":$(jnum "$util"),\"mem_used_mb\":$(jnum "$memused"),\"mem_total_mb\":$(jnum "$memtotal"),\"temp_c\":$(jnum "$temp"),\"power_w\":$(jnum "$power"),\"fan_pct\":$(jnum "$fan")}")
    done < <("$NVIDIA_SMI" --query-gpu=index,name,utilization.gpu,memory.used,memory.total,temperature.gpu,power.draw,fan.speed \
        --format=csv,noheader,nounits 2>/dev/null)
fi

# --- SMART (jednou za NORDVARR_SMART_INTERVAL sekund) --------------------------

SMART_JSON=""
SMART_ERROR=""
SMART_STAMP="$STATE_DIR/smart-at"
NOW_EPOCH="$(date +%s)"
LAST_SMART="$(read_first "$SMART_STAMP")"
if [ "$SMART_INTERVAL" -gt 0 ] && [ $(( NOW_EPOCH - ${LAST_SMART:-0} )) -ge "$SMART_INTERVAL" ]; then
    if ! command -v "$SMARTCTL" >/dev/null 2>&1; then
        SMART_ERROR="smartctl_missing"
    elif [ -z "$ROOT" ] && [ "$(id -u)" -ne 0 ]; then
        SMART_ERROR="not_root"
    else
        SMART_ITEMS=()
        while read -r dev _ dtype _; do
            case "$dev" in /dev/*) ;; *) continue ;; esac
            out="$("$SMARTCTL" -j -i -H -A -l error -d "$dtype" "$dev" 2>/dev/null)"
            # Jen platný začátek objektu JSON a rozumná velikost; rozbor dělá server.
            if [ "${out:0:1}" = "{" ] && [ "${#out}" -lt 200000 ]; then
                SMART_ITEMS+=("$out")
            fi
        done < <("$SMARTCTL" --scan 2>/dev/null)
        SMART_JSON="[$(join_by_comma "${SMART_ITEMS[@]}")]"
        [ "$MODE" = "dry" ] || echo "$NOW_EPOCH" > "$SMART_STAMP"
    fi
fi

# --- Kontejnery Dockeru (je-li docker a má agent k němu přístup) ------------------

CONTAINERS_JSON=""
CONTAINERS_ERROR=""
if [ "$DOCKER" != "0" ] && command -v "$DOCKER" >/dev/null 2>&1; then
    if CT_OUT="$(timeout 10 "$DOCKER" ps -a --format '{{json .}}' 2>/dev/null)"; then
        CT_ITEMS=()
        while IFS= read -r line; do
            # Každý řádek je objekt JSON od dockeru; rozbor dělá server. Nejvýš 200 kontejnerů.
            if [ "${line:0:1}" = "{" ] && [ "${#line}" -lt 20000 ]; then
                CT_ITEMS+=("$line")
            fi
            [ "${#CT_ITEMS[@]}" -ge 200 ] && break
        done <<< "$CT_OUT"
        CONTAINERS_JSON="[$(join_by_comma "${CT_ITEMS[@]}")]"
    else
        # Docker je nainstalovaný, ale agent na něj nedosáhne (práva, neběžící služba).
        CONTAINERS_ERROR="docker_unavailable"
    fi
fi

# --- Hlášení ------------------------------------------------------------------

PAYLOAD="$STATE_DIR/payload.json"
{
    printf '{"agent_version":%s,"hostname":%s,' "$(jstr "$AGENT_VERSION")" "$(jstr "$HOSTNAME_VALUE")"
    [ -n "${NORDVARR_EXTERNAL_ID:-}" ] && printf '"external_id":%s,' "$(jstr "$NORDVARR_EXTERNAL_ID")"
    [ -n "${NORDVARR_OBJECT_ID:-}" ] && printf '"object_id":%s,' "$(jstr "$NORDVARR_OBJECT_ID")"
    printf '"collected_at":%s,"os":%s,"kernel":%s,"uptime_s":%s,' \
        "$(jstr "$(date -u +%Y-%m-%dT%H:%M:%SZ)")" "$(jstr "$OS_NAME")" "$(jstr "$KERNEL")" "$(jnum "$UPTIME_S")"
    printf '"cpu":{"usage_pct":%s,"cores":%s,"model":%s,"load":[%s,%s,%s]},' \
        "$(jnum "$CPU_USAGE")" "$(jnum "$CORES")" "$(jstr "$CPU_MODEL")" "$(jnum "$LOAD1")" "$(jnum "$LOAD5")" "$(jnum "$LOAD15")"
    printf '"memory":%s,' "$MEM_JSON"
    printf '"disks":[%s],' "$(join_by_comma "${DISK_ITEMS[@]}")"
    printf '"temperatures":[%s],' "$(join_by_comma "${TEMP_ITEMS[@]}")"
    printf '"gpus":[%s],' "$(join_by_comma "${GPU_ITEMS[@]}")"
    printf '"network":[%s]' "$(join_by_comma "${NET_ITEMS[@]}")"
    [ -n "$SMART_JSON" ] && printf ',"smart":%s' "$SMART_JSON"
    [ -n "$SMART_ERROR" ] && printf ',"smart_error":%s' "$(jstr "$SMART_ERROR")"
    [ -n "$CONTAINERS_JSON" ] && printf ',"containers":%s' "$CONTAINERS_JSON"
    [ -n "$CONTAINERS_ERROR" ] && printf ',"containers_error":%s' "$(jstr "$CONTAINERS_ERROR")"
    printf '}\n'
} > "$PAYLOAD"

if [ "$MODE" = "dry" ]; then
    cat "$PAYLOAD"
    exit 0
fi

# Token jen v souboru s právy 600, ne v příkazové řádce (byl by vidět v seznamu procesů).
HEADERS="$STATE_DIR/headers"
printf 'Authorization: Bearer %s\nContent-Type: application/json\n' "$TOKEN" > "$HEADERS"
RESPONSE="$STATE_DIR/last-response"
CODE="$(curl -sS -m 20 -o "$RESPONSE" -w '%{http_code}' -X POST -H @"$HEADERS" --data-binary @"$PAYLOAD" \
    "${URL%/}/api/v1/agent/report" 2>"$STATE_DIR/last-error")"
rm -f "$HEADERS"

if [ "$CODE" = "200" ]; then
    rm -f "$STATE_DIR/last-error"
    [ "$MODE" = "test" ] && { echo "OK: $(cat "$RESPONSE")"; }
    exit 0
fi
# Při chybě se SMART zkusí poslat znovu příště.
rm -f "$SMART_STAMP"
echo "Odeslání selhalo (HTTP ${CODE:-000}): $(cat "$RESPONSE" 2>/dev/null) $(cat "$STATE_DIR/last-error" 2>/dev/null)" | tee -a "$STATE_DIR/last-error" >&2
exit 2
