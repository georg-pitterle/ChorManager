import { execFileSync } from 'node:child_process';
import { resolveBash } from './shell.mjs';

// DB-Helfer fuer den Autorisierungstest. Beide gehen bewusst NICHT ueber die UI:
//  - setMemberPassword: das /users-Formular vergibt kein Passwort; fuer den Login als Mitglied
//    setzen wir eins direkt in der DB mit dem app-eigenen Hash (password_hash / PASSWORD_DEFAULT),
//    das /login danach ueber password_verify prueft.
//  - readRolePermissions: liest die tatsaechlichen (nach allen Migrationen/Backfills gueltigen)
//    Rechte-Spalten je Rolle als Quelle der Wahrheit fuer die erwartete Zugriffsmatrix.
// Beide laufen ueber `ddev php` (PDO), damit keine SQL-/Shell-Quoting-Fallen mit dem Hash entstehen.
// Nur kontrollierte Literale (feste E-Mails/Passwoerter aus den Fixtures) werden interpoliert.

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
