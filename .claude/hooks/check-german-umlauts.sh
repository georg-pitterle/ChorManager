#!/usr/bin/env bash
#
# Erzwingt die Umlaut-Regel aus instructions/naming.md:
# Deutscher Text nutzt echte Umlaute (ä ö ü ß), nie ae/oe/ue/ss.
#
# Greift als PreToolUse-Hook, bevor der Text überhaupt geschrieben wird:
#   - Write  -> tool_input.content
#   - Edit   -> tool_input.new_string
#   - Bash   -> tool_input.command (nur `git commit`, siehe "if" in settings.json)
#
# Geprüft wird eine feste Liste transliterierter Wortstämme. Ein allgemeines
# "ue"-Muster wäre unbrauchbar: "neue", "Steuer", "teuer" enthalten es völlig
# zu Recht. Genauso bleibt "ss" ungeprüft, außer wo ß zwingend ist
# ("ausschliesslich", "liess") - "dass", "muss", "Fluss" sind korrekt.
#
# Ausnahme: Zeilen mit dem Marker "naming:ascii" werden übersprungen. Für
# Werte, die technisch ASCII bleiben müssen (E-Mail-Adressen, Passwörter,
# Vergleiche gegen transliterierten Text).

set -uo pipefail

SELF_NAME="check-german-umlauts"

# Die Wortstämme stehen bewusst in einer Datei neben diesem Skript, nicht als
# Literal hier drin: sonst enthielte das Skript seine eigenen Suchbegriffe und
# jede Änderung daran würde am eigenen Hook scheitern.
PATTERN_FILE="$(dirname "$0")/${SELF_NAME}.patterns"

[ -r "$PATTERN_FILE" ] || exit 0

payload=$(cat)
tool=$(printf '%s' "$payload" | jq -r '.tool_name // ""')

case "$tool" in
    Write) text=$(printf '%s' "$payload" | jq -r '.tool_input.content // ""') ;;
    Edit)  text=$(printf '%s' "$payload" | jq -r '.tool_input.new_string // ""') ;;
    Bash)
        # Nur Commit-Nachrichten. Auf das "if" in settings.json ist kein Verlass -
        # der Hook lief auch bei anderen Befehlen an. Ohne diese Schranke würde
        # jede Suche nach Transliterationen am eigenen Hook scheitern.
        text=$(printf '%s' "$payload" | jq -r '.tool_input.command // ""')
        printf '%s' "$text" | grep -qE '(^|[;&|[:space:]])git([[:space:]]+-[^[:space:]]+)*[[:space:]]+commit([[:space:]]|$)' || exit 0
        ;;
    *)     exit 0 ;;
esac

[ -z "$text" ] && exit 0

# Das Prüfskript und seine Wortliste bleiben außen vor - sie führen die
# Suchbegriffe naturgemäß im Klartext.
target=$(printf '%s' "$payload" | jq -r '.tool_input.file_path // ""')
case "$target" in
    *"$SELF_NAME"*) exit 0 ;;
esac

hits=$(printf '%s\n' "$text" \
    | grep -v 'naming:ascii' \
    | grep -niE -f "$PATTERN_FILE" \
    | head -10)

[ -z "$hits" ] && exit 0

reason="instructions/naming.md: deutscher Text nutzt echte Umlaute (ä ö ü ß), nie ae/oe/ue/ss.

Betroffene Zeilen des neuen Textes:
$hits

Schreib die Stellen mit echten Umlauten neu und wiederhole den Aufruf. Muss ein
Wert technisch ASCII bleiben (E-Mail, Passwort, Vergleich gegen transliterierten
Text), gehört an die Zeile ein Kommentar mit dem Marker naming:ascii."

jq -n --arg r "$reason" '{
    hookSpecificOutput: {
        hookEventName: "PreToolUse",
        permissionDecision: "deny",
        permissionDecisionReason: $r
    }
}'
