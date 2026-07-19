# Active Context: AI Interview LimeSurvey Plugin

## Current State

Working on modernizing the visual design of the AI Interview LimeSurvey experience. Selected direction: **Glass Session**.

## Recently Completed

- [x] Reviewed live demo survey and existing widget CSS
- [x] Brainstormed five theme directions (Signal Desk, Glass Session, Neural Studio, Orbit Interview, Mono Protocol)
- [x] Generated visual mockups for each direction
- [x] User selected **Glass Session**
- [x] Constraint confirmed: **no branding text** in theme chrome
- [x] Wrote widget restyle handoff for the parallel Cursor agent: `docs/ai-interviewer/04-glass-session-widget-restyle.md`

## Current Focus

- Parallel agent: restyle AI Interview **widget** to Glass Session (see handoff doc)
- Possible follow-up: full LimeSurvey **survey** theme using the same Glass Session tokens (not started)

## Design decisions

| Decision | Choice |
|----------|--------|
| Theme direction | Glass Session |
| Branding in theme | None (no product name / Allie / logos in chrome) |
| Accent | Teal-mint `#0d9488` (not purple/indigo, not Bootstrap blue) |
| Surfaces | Frosted glass + hairlines over mint→ice aurora |
| Split of work | Widget restyle → other agent; survey theme optional later |

## Key files

| Path | Purpose |
|------|---------|
| `AIInterview/assets/ai-interview.css` | Plugin widget styles |
| `AIInterview/question_themes/AIInterview/assets/ai-interview.css` | Question-theme copy (keep in sync) |
| `AIInterview/assets/ai-interview-voice.css` | Voice widget styles |
| `docs/ai-interviewer/04-glass-session-widget-restyle.md` | Handoff prompt + tokens for widget agent |
