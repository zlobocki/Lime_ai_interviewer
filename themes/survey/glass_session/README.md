# Glass Session — LimeSurvey Survey Theme

Frosted glass panels over a soft mint → ice aurora, with a teal accent (`#0d9488`). Extends LimeSurvey’s **Fruity TwentyThree** core theme.

Designed to match the AI Interview widget **Glass Session** tokens. No product name, logo, or interviewer branding is hardcoded in the theme chrome — survey titles and copy come from LimeSurvey content.

## Requirements

- LimeSurvey **6.x** or **7.x**
- Core theme **fruity_twentythree** available (ships with LimeSurvey)

## Install

1. Copy the `glass_session` folder to:

   ```text
   <limesurvey>/upload/themes/survey/glass_session/
   ```

   Or zip the folder and import via **Configuration → Themes** (if your build supports theme zip import).

2. In **Configuration → Themes**, find **Glass Session** and click **Install** (loads the manifest into the DB).

3. Open your survey → **Theme options** / **Look and feel** → select **Glass Session**.

4. Optional: **Configuration → Global settings → General** → clear asset cache if styles don’t appear.

5. Confirm **Logo** is **No** (theme default) unless you intentionally upload your own logo file.

## Files

| Path | Role |
|------|------|
| `config.xml` | Theme manifest (`extends` fruity_twentythree) |
| `css/custom.css` | Glass Session tokens + survey chrome overrides |
| `scripts/custom.js` | Light focus helpers (no branding) |

## Design tokens

Shared with the widget handoff (`docs/ai-interviewer/04-glass-session-widget-restyle.md`):

- Accent: `#0d9488`
- Ink: `#0f172a`
- Glass: `rgba(255,255,255,0.55)` + blur
- Aurora: mint `#d8f5ef` → ice `#e8f1ff` → mist `#f7fafc`
- Font: DM Sans (Google Fonts) with Avenir Next / Segoe UI fallbacks

## Notes

- Parent Fruity TwentyThree color variations (Apple, etc.) may still load; `custom.css` overrides primary surfaces and buttons.
- The theme also soft-bridges `.ai-interview-widget` styles so page + chat feel related before/without a separate widget CSS update.
- After changing `config.xml` on a server, **Reset** the theme in the Themes list (or use `force_xmlsettings_for_survey_rendering` in debug) so DB options refresh.
