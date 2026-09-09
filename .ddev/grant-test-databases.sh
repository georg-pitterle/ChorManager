#!/usr/bin/env bash
# Erlaubt dem Anwendungsbenutzer, eigene Testdatenbanken anzulegen.
#
# Die Testsuite arbeitet nicht mehr auf der Entwicklungsdatenbank, sondern auf einer
# abgeleiteten ("db_test", bei parallelen Prozessen "db_test_2" und so weiter). Anlegen
# darf die nur, wer das Recht dazu auf dem Namensmuster hat - der Anwendungsbenutzer
# hat es sonst nur auf seiner eigenen Datenbank.
set -euo pipefail

mysql -uroot -proot -e "GRANT ALL PRIVILEGES ON \`db\_test%\`.* TO 'db'@'%'; FLUSH PRIVILEGES;"
