import { execFileSync } from 'node:child_process';
import { resolveBash } from './shell.mjs';

// DB-Helfer für den Autorisierungstest. Beide gehen bewusst NICHT über die UI:
//  - setMemberPassword: das /users-Formular vergibt kein Passwort; für den Login als Mitglied
//    setzen wir eins direkt in der DB mit dem app-eigenen Hash (password_hash / PASSWORD_DEFAULT),
//    das /login danach über password_verify prüft.
//  - readRolePermissions: liest die tatsächlichen (nach allen Migrationen/Backfills gültigen)
//    Rechte-Spalten je Rolle als Quelle der Wahrheit für die erwartete Zugriffsmatrix.
// Beide laufen über `ddev php` (PDO), damit keine SQL-/Shell-Quoting-Fallen mit dem Hash entstehen.
// Nur kontrollierte Literale (feste E-Mails/Passwörter aus den Fixtures) werden interpoliert.

function ddevPhp(php) {
    return execFileSync(resolveBash(), ['-lc', `ddev php -r '${php}'`], { encoding: 'utf8' });
}

export function setMemberPassword(email, plain) {
    const php = `$p=password_hash("${plain}",PASSWORD_DEFAULT);`
        + `$pdo=new PDO("mysql:host=db;dbname=db","db","db");`
        + `$s=$pdo->prepare("UPDATE users SET password=?, is_active=1 WHERE email=?");`
        + `$s->execute([$p,"${email}"]);echo $s->rowCount();`;
    const out = ddevPhp(php).trim();
    if (out === '0') {
        throw new Error(`setMemberPassword: kein User mit E-Mail ${email} gefunden (rowCount 0).`);
    }
}

export function readRolePermissions() {
    const php = `$pdo=new PDO("mysql:host=db;dbname=db","db","db");`
        + `echo json_encode($pdo->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC));`;
    const rows = JSON.parse(ddevPhp(php).trim());
    const byName = {};
    for (const row of rows) {
        byName[row.name] = row;
    }
    return byName;
}
