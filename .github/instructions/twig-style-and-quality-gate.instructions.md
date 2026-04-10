---
applyTo: "templates/**/*.twig"
---

# Twig Style And Quality Gate

- Follow the official Twig coding standards.
- For substantial Twig template changes, run `ddev composer twigcs`.
- If formatting fixes are needed, run `ddev composer twigcbf`.
- Report the executed Twig lint and fix commands together with the result.
- Format also files that are not changed
- Keep line length at a soft limit of 120 and a hard limit of 130.
- In Twig, always use double quotes, never single quotes.