---
applyTo: "**"
---

# Line Endings

- Create repository text files with LF line endings by default.
- Keep new and edited text files consistent with `.gitattributes` line ending rules.
- Use CRLF only for Windows command script files such as `.bat`, `.cmd`, and `.ps1`.
- After any file-writing operation on Windows (`create_file`, `apply_patch`, scripted edit, or terminal-based write), normalize all touched text files to LF unless they are `.bat`, `.cmd`, or `.ps1`.
- After every `create_file` call on Windows, immediately convert the created file from CRLF to LF using:
  ```powershell
  $f = "<absolute-path>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
  ```
- For edited files, use the same normalization pattern per touched file path and re-stage the file when required.
