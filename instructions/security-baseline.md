# Security Baseline

- Follow OWASP principles.
- Rotate session ID after successful login.
- Use secure cookie settings (HttpOnly, Secure where applicable, SameSite).
- Validate all user input.
- Apply least privilege.
- Never store secrets or credentials in the repository.
- Do not load frontend libraries or components from external CDNs or third-party URLs at runtime; use locally managed and locally served assets only.

## Exception: embedded explainer videos

- The sponsoring overview (`/sponsoring`) may embed the explainer videos, which are hosted
  unlisted on YouTube. This is the only external runtime source.
- The page itself loads nothing from YouTube: it shows a local poster image, and only a
  click on "Video laden" inserts the frame (`public/js/video-embed.js`).
- The frame uses the cookieless origin `https://www.youtube-nocookie.com`; the CSP opens
  `frame-src` for that origin on that page only (`SecurityHeadersMiddleware::embedsVideo()`).
  Scripts, styles, images and connections stay `'self'`.
- Do not widen this to other pages or origins without the same click-to-load pattern.
