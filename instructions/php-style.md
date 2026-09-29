# PHP Style and Quality Gate

Applies to: `**/*.php`

- Standard und Grenzen stehen in `phpcs.xml` und werden dort erzwungen (PSR-12,
  4 Leerzeichen, Zeilenlänge 130).
- For substantial PHP changes, run `ddev composer phpcs`.
- If formatting fixes are needed, run `ddev composer phpcbf`.
- Format also files that are not changed.
