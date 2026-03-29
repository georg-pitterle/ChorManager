---
applyTo: "templates/**/*.twig"
---

# Template Hygiene And Responsive UI

- Do not use inline JavaScript in templates.
- Do not use inline CSS in templates.
- Use dedicated JS and CSS files.
- Use only locally served scripts, styles, and web components; do not reference CDNs or other external runtime asset URLs.
- Keep UI responsive on mobile and desktop.
- Do not modify `vendor/` files.