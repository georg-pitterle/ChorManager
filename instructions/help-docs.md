# Hilfetexte (docs/*.md für /hilfe)

- Bei rollenbasierten Features nie auf konkrete Rollennamen verweisen (z. B. "Vorstand", "Admin", "Kassier"). Rollen sind pro Installation frei konfigurierbar; ihre Rechte können sich jederzeit ändern oder anders heißen.
- Stattdessen auf das tatsächliche Recht verweisen, mit dem Label aus der Rollen-Verwaltung (z. B. "Sponsoring verwalten" für `can_manage_sponsoring` - Labels stehen in `templates/roles/index.twig`).
- Bei Rückfragen zu fehlenden Rechten generisch auf "den Administrator" verweisen, nie auf einen konkreten Rollennamen.
