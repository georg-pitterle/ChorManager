#!/usr/bin/env bash
#
# Erzwingt die Umlaut-Regel aus instructions/naming.md:
# Deutscher Text nutzt echte Umlaute (ä ö ü ß), nie ae/oe/ue/ss.
#
# Greift als PreToolUse-Hook, bevor der Text überhaupt geschrieben wird:
#   - Write       -> tool_input.content
#   - Edit        -> tool_input.new_string
#   - Bash        -> tool_input.command (nur `git commit`)
#   - PowerShell  -> tool_input.command (nur `git commit`)
#
# PowerShell steht hier, weil derselbe Commit über beide Werkzeuge laufen kann.
# Fehlte der Zweig, wäre das Werkzeug die offene Tür an der Prüfung vorbei.
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

# JSON-Leser bestimmen. `jq` ist die erste Wahl, fehlt aber auf einem frischen
# Windows-Arbeitsplatz - und genau dort ist der Hook still wirkungslos geworden:
# `jq: command not found` schrieb nur nach stderr, `set -uo pipefail` (ohne -e)
# lief weiter, $tool blieb leer und der Standardzweig liess alles durch. Der
# Hook meldete sich nie, prüfte aber auch nie. Deshalb hier ein zweiter Weg über
# Python und, wenn beides fehlt, eine sichtbare Meldung statt stiller Duldung.
#
# Die Kandidaten werden probeweise ausgeführt statt nur im PATH gesucht: unter
# Windows liegt dort ein `python3`, das nichts weiter tut, als auf den Microsoft
# Store zu verweisen. Es zu finden heisst nicht, es benutzen zu können.
json_reader=""
for candidate in jq python3 python; do
    command -v "$candidate" >/dev/null 2>&1 || continue

    if [ "$candidate" = "jq" ]; then
        printf '{}' | jq -r '.' >/dev/null 2>&1 || continue
    else
        "$candidate" -c 'import json' >/dev/null 2>&1 || continue
    fi

    json_reader="$candidate"
    break
done

if [ -z "$json_reader" ]; then
    jq_hint="Umlaut-Prüfung übersprungen: weder jq noch Python gefunden. Bitte eines von beidem installieren - sonst greift instructions/naming.md nicht."
    printf '{"systemMessage":"%s"}\n' "$jq_hint"
    exit 0
fi

# Liest ein Feld aus der Nutzlast. Der Pfad kommt als Punktnotation herein.
json_field() {
    if [ "$json_reader" = "jq" ]; then
        printf '%s' "$payload" | jq -r ".$1 // \"\""
        return
    fi

    # Ein- und Ausgabe ausdrücklich als UTF-8: Python nimmt unter Windows sonst
    # cp1252 und macht aus jedem Umlaut zwei Zeichen.
    printf '%s' "$payload" | "$json_reader" -c '
import json, sys
path = sys.argv[1].split(".")
value = json.loads(sys.stdin.buffer.read().decode("utf-8"))
for key in path:
    if not isinstance(value, dict):
        value = ""
        break
    value = value.get(key, "")
sys.stdout.buffer.write((value if isinstance(value, str) else "").encode("utf-8"))
' "$1"
}

tool=$(json_field 'tool_name')

case "$tool" in
    Write) text=$(json_field 'tool_input.content') ;;
    Edit)  text=$(json_field 'tool_input.new_string') ;;
    Bash|PowerShell)
        # Nur Commit-Nachrichten. Auf das "if" in settings.json ist kein Verlass -
        # der Hook lief auch bei anderen Befehlen an. Ohne diese Schranke würde
        # jede Suche nach Transliterationen am eigenen Hook scheitern.
        text=$(json_field 'tool_input.command')
        printf '%s' "$text" | grep -qE '(^|[;&|[:space:]])git([[:space:]]+-[^[:space:]]+)*[[:space:]]+commit([[:space:]]|$)' || exit 0
        ;;
    *)     exit 0 ;;
esac

[ -z "$text" ] && exit 0

# Das Prüfskript und seine Wortliste bleiben außen vor - sie führen die
# Suchbegriffe naturgemäß im Klartext.
target=$(json_field 'tool_input.file_path')
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

# Die Antwort muss gültiges JSON sein, der Grund enthält Zeilenumbrüche und
# Anführungszeichen. Deshalb auch hier über denselben Leser statt von Hand.
if [ "$json_reader" = "jq" ]; then
    jq -n --arg r "$reason" '{
        hookSpecificOutput: {
            hookEventName: "PreToolUse",
            permissionDecision: "deny",
            permissionDecisionReason: $r
        }
    }'
else
    printf '%s' "$reason" | "$json_reader" -c '
import json, sys
payload = json.dumps({
    "hookSpecificOutput": {
        "hookEventName": "PreToolUse",
        "permissionDecision": "deny",
        "permissionDecisionReason": sys.stdin.buffer.read().decode("utf-8"),
    }
})
sys.stdout.buffer.write(payload.encode("utf-8"))
'
fi
