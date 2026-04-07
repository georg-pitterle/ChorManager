---
applyTo: "templates/**/*.twig"
---

# Template Hygiene And Responsive UI

- Do not use inline JavaScript in templates.
- Do not use inline CSS (`style="..."`) in templates. Use dedicated CSS classes in `public/css/style.css` or Bootstrap
  utility classes instead.
  - **Exception – HTML e-mail templates** (`templates/emails/`): E-mail clients do not support external stylesheets,
    so inline styles are required there.
  - **Exception – dynamic numeric values**: A CSS custom property passed via `style` (e.g.
    `style="--progress-value: {{ val }}%"`) is acceptable when the value is truly dynamic and has no static
    CSS-class equivalent. The consuming CSS rule must read the custom property (`width: var(--progress-value, 0%)`).
- Use dedicated JS and CSS files.
- Use only locally served scripts, styles, and web components; do not reference CDNs or other external runtime asset URLs.
- Keep UI responsive on mobile and desktop.
- Do not modify `vendor/` files.