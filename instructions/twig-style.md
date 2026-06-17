# Twig Style and Quality Gate

Applies to: `templates/**/*.twig`

- Follow the official Twig coding standards.
- For substantial Twig template changes, run `ddev composer twigcs`.
- If formatting fixes are needed, run `ddev composer twigcbf`.
- Report executed Twig lint and fix commands with results.
- Format also files that are not changed.
- Keep line length at a soft limit of 120 and a hard limit of 130.
- Always use double quotes, never single quotes.
- Named argument defaults use no spaces around `=`: `match_id=null`, never `match_id = null`.
- Binary operators (`and`, `or`, `not`) must have exactly 1 space on both sides. Multi-line boolean expressions are forbidden; extract sub-conditions into intermediate `{% set %}` variables so every operator fits on a single line:
  ```twig
  {% set _scope_ok = modal_error.scope is defined and modal_error.scope == modal_scope %}
  {% set _id_ok = match_id is null or _mid or _mgid %}
  {% if modal_error == modal_scope or (_scope_ok and _id_ok) %}
  ```
