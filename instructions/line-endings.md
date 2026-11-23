# Line Endings

- Text files use LF; CRLF only for Windows command scripts (`.bat`, `.cmd`, `.ps1`), as set in `.gitattributes`.
- `.githooks/pre-commit` normalizes staged files to LF (`bin/normalize_lf_staged.php`) and checks the repo
  (`bin/check_lf_repo.php`); never bypass it with `--no-verify`.
