---
name: plan-skill
description: Generate a minimal execution plan for non-trivial tasks in ask and agent mode.
---

# plan-skill

Use for any non-trivial request in ask mode and agent mode.

Rules:
1. Output only a numbered plan with 3-7 steps.
2. Keep each step to one short, outcome-focused sentence.
3. Add `Assumption:` lines only when strictly needed.
4. If blocked, output one `Blocked:` line with the missing input.
5. In ask mode, stay read-only and mention no write actions.
6. End with `Next:` and exactly one immediate next step.
7. Write in clear, concise language focused on outcomes, not implementation details.
8. Plan must be copilot-friendly and actionable without further interpretation.