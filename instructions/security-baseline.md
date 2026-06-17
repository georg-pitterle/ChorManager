# Security Baseline

- Follow OWASP principles.
- Rotate session ID after successful login.
- Use secure cookie settings (HttpOnly, Secure where applicable, SameSite).
- Validate all user input.
- Apply least privilege.
- Never store secrets or credentials in the repository.
- Do not load frontend libraries or components from external CDNs or third-party URLs at runtime; use locally managed and locally served assets only.
