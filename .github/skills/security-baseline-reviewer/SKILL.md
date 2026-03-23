---
name: security-baseline-reviewer
description: Validate changes against repository OWASP-aligned security requirements.
---

# security-baseline-reviewer

Use for auth, session, input, and permission changes.

Rules:
- Follow OWASP principles.
- Rotate session ID after successful login.
- Use secure cookie settings (HttpOnly, Secure where applicable, SameSite).
- Validate all user input.
- Apply least privilege.
- Never store secrets or credentials in the repository.
