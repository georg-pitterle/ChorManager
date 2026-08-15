import fs from 'node:fs';
import path from 'node:path';

// Auf Windows löst ein blankes `bash` je nach Terminal/Kontext auf WSL-bash auf - z. B. im
// VS-Code-Terminal gewinnt C:\Windows\System32\bash.exe (WSL), dessen `/bin/bash` hier fehlt:
//   "WSL ... execvpe(/bin/bash) failed: No such file or directory".
// Für fresh-db.sh und die ddev-Helfer brauchen wir aber Git Bash. Diese Funktion liefert den
// Pfad zu Git Bash (per E2E_BASH überschreibbar) und fällt nur zur Not auf blankes `bash` zurück.
export function resolveBash() {
    if (process.platform !== 'win32') {
        return 'bash';
    }
    const candidates = [
        process.env.E2E_BASH,
        'C:\\Program Files\\Git\\bin\\bash.exe',
        'C:\\Program Files\\Git\\usr\\bin\\bash.exe',
        'C:\\Program Files (x86)\\Git\\bin\\bash.exe',
        process.env.LOCALAPPDATA && path.join(process.env.LOCALAPPDATA, 'Programs', 'Git', 'bin', 'bash.exe'),
    ].filter(Boolean);
    for (const candidate of candidates) {
        if (fs.existsSync(candidate)) {
            return candidate;
        }
    }
    return 'bash';
}
