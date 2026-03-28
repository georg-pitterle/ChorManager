---
applyTo: "**"
---

# Line Endings

- Create repository text files with LF line endings by default.
- Keep new and edited text files consistent with `.gitattributes` line ending rules.
- Use CRLF only for Windows command script files such as `.bat`, `.cmd`, and `.ps1`.
- After every `create_file` call on Windows, immediately convert the created file from CRLF to LF using:
  ```powershell
  $f = "<absolute-path>"; [System.IO.File]::WriteAllText($f, ((Get-Content $f -Raw) -replace "`r`n", "`n"), [System.Text.UTF8Encoding]::new($false))
  ```