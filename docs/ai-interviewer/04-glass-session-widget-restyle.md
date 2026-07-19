# Handoff: Glass Session Widget Restyle

**Audience:** Cursor agent working on the AI Interview LimeSurvey widget  
**Scope:** Restyle the **chat / interview widget only** (not a full LimeSurvey survey theme)  
**Selected direction:** Glass Session  
**Constraint:** **No branding text** in the theme (no product name, no “Allie”, no logos, no hardcoded interviewer identity in CSS/markup chrome)

Reference mockup concept (visual target): frosted translucent panels over a soft mint → ice-blue aurora, thin hairlines, airy SaaS product feel — not purple neon, not cyberpunk HUD.

---

## Copy-paste prompt for the widget agent

```text
Restyle the LimeSurvey AI Interview widget to the "Glass Session" visual system.

Scope
- Widget CSS (and minimal markup/class tweaks only if needed for styling).
- Do NOT hardcode branding text, product names, logos, or interviewer names (e.g. no "Allie") into theme chrome.
- Survey title / question text / interviewer identity come from LimeSurvey content or runtime config — leave those alone.
- Keep existing JS behavior, API calls, accessibility attributes, and class hooks unless a class rename is required for styling (then update JS + CSS together).

Primary files to update (keep plugin + question theme copies in sync if both exist):
- AIInterview/assets/ai-interview.css
- AIInterview/question_themes/AIInterview/assets/ai-interview.css
- If voice UI is in scope for this branch: AIInterview/assets/ai-interview-voice.css
- Do not invent a full LimeSurvey survey theme in this task.

Design tokens (use CSS variables on .ai-interview-widget)
--ai-font: "DM Sans", "Avenir Next", "Segoe UI", sans-serif
  (load DM Sans via Google Fonts or @font-face only if the plugin already has a clean asset-loading path; otherwise use a distinctive non-default stack that still feels product-like — avoid Inter/Roboto/Arial as the sole face)
--ai-bg-aurora: soft layered radial gradients mint (#d8f5ef) → ice (#e8f1ff) → pale mist (#f7fafc)
--ai-glass: rgba(255,255,255,0.55) with backdrop-filter: blur(16px) saturate(1.2)
--ai-glass-strong: rgba(255,255,255,0.72)
--ai-ink: #0f172a
--ai-ink-muted: #475569
--ai-hairline: rgba(15, 23, 42, 0.10)
--ai-user-bubble: #0f172a (white text)
--ai-assistant-bubble: rgba(255,255,255,0.70) with hairline border
--ai-accent: #0d9488 (teal-mint, NOT purple/indigo)
--ai-accent-soft: rgba(13, 148, 136, 0.16)
--ai-danger-bg / --ai-warn-bg / --ai-ok-bg: soft glass-tinted variants, not harsh flat Bootstrap colors
--ai-radius-panel: 20px
--ai-radius-bubble: 18px
--ai-shadow: 0 10px 40px rgba(15, 23, 42, 0.08)

Visual requirements
1. Widget container: frosted glass panel over a subtle aurora background (inside the widget or as the widget shell). Thin hairline border, soft shadow, large radius. No heavy gray "card in a card" look.
2. Message list: transparent / very light mist, not #f9fafb flat gray.
3. Assistant bubbles: translucent white glass, left-aligned, soft asymmetric radius.
4. User bubbles: deep ink, right-aligned, no bright #2563eb blue.
5. Labels above bubbles: quiet muted caps/small text OK, but do not inject brand names.
6. Composer: glass strip, textarea with hairline + accent focus ring (teal soft glow, not blue Bootstrap ring).
7. Buttons:
   - Primary (Send): solid teal accent
   - Finish: darker ink or secondary solid (not bright green Bootstrap)
   - Secondary: glass/hairline
8. Typing indicator: soft teal pulses / fade, not gray bouncing dots only.
9. Error / warning / finished banners: glass-compatible soft tints matching the system.
10. Motion (2–3 intentional):
    - message enter: fade + 8px rise (~220ms, ease-out)
    - send/focus: accent ring breathe
    - typing: gentle opacity pulse
    Avoid noise: no continuous background animation, no glow orbs, no particle fields.

Anti-patterns (do not ship)
- Purple / indigo AI cliché gradients
- Neon cyberpunk grids
- Default system-only Inter/Roboto look
- Floating badges, stickers, promo chips on the chat
- Hardcoded "Allie" / product branding in CSS content or decorative labels
- Breaking mobile layout (keep stacked actions under ~480px)

Acceptance checks
- Text + (if present) voice widget feel like the same Glass Session family.
- Contrast remains readable on mint/ice backgrounds (WCAG AA for body text / buttons).
- Existing selectors used by JS still work (.ai-interview-widget, .ai-message, .ai-btn-primary, etc.).
- Plugin asset copy and question_themes asset copy stay visually identical.
```

---

## Visual target (Glass Session)

| Element | Target |
|--------|--------|
| Atmosphere | Soft mint → ice-blue aurora (light mode) |
| Surfaces | Frosted glass + hairlines |
| Accent | Teal-mint `#0d9488` (not blue `#2563eb`, not purple) |
| User bubble | Deep ink `#0f172a` |
| Assistant bubble | Translucent white glass |
| Tone | Premium SaaS / Linear-adjacent, calm futurism |
| Branding | None in theme chrome |

---

## Current widget structure (do not break)

Rendered classes (from existing CSS / JS):

- `.ai-interview-widget`
- `.ai-interview-messages` → `.ai-message.ai-message-assistant|ai-message-user` → `.ai-message-label` + `.ai-message-bubble`
- `.ai-interview-typing` / `.ai-typing-dot` / `.ai-typing-label`
- `.ai-interview-error` / `.ai-interview-token-warning` / `.ai-interview-mandatory-notice`
- `.ai-interview-input-area` → `.ai-interview-input` + `.ai-interview-actions` → `.ai-btn*`
- `.ai-interview-finished` / `.ai-interview-finished-notice`

Voice (separate stylesheet, align if in scope):

- `.ai-interview-voice-widget`, `.ai-voice-welcome`, `.ai-voice-stage`, etc. in `AIInterview/assets/ai-interview-voice.css`
- Welcome stage is currently near-black; for Glass Session, either:
  - **A (preferred):** restyle welcome to light glass/aurora for consistency, or
  - **B:** keep a darker stage only for avatar video/PNG contrast, but reuse the same accent, radius, button, and type tokens

---

## Implementation notes

1. Prefer CSS variables at the widget root so text + voice can share tokens.
2. `backdrop-filter` needs a semi-opaque background; provide a solid fallback without blur for older browsers.
3. LimeSurvey admin/survey CSS can fight widget styles — keep selectors scoped under `.ai-interview-widget` / `.ai-interview-voice-widget` and increase specificity only when necessary.
4. Question Twig (`answer.twig`) only outputs a textarea placeholder; widget HTML is injected by PHP/JS. Prefer CSS-only restyle; change injection markup only if required for structure (e.g. aurora layer div).
5. Keep `AIInterview/assets/ai-interview.css` and `AIInterview/question_themes/AIInterview/assets/ai-interview.css` identical (or generate one from the other) — activation copies the question theme into `upload/themes/question/`.

---

## Out of scope for the widget agent

- Full LimeSurvey **survey** theme (welcome page, progress bar, Next/Exit chrome)
- Changing interview prompts, API proxy, or transcript format
- Adding brand wordmarks or interviewer marketing copy

Survey theme (same tokens): `themes/survey/glass_session/` — LimeSurvey theme extending `fruity_twentythree`. Install under `upload/themes/survey/glass_session/`.
