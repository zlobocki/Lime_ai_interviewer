# Active Context: Next.js Starter Template

## Current State

**Template Status**: ✅ Ready for development

The template is a clean Next.js 16 starter with TypeScript and Tailwind CSS 4. It's ready for AI-assisted expansion to build any type of application.

## Recently Completed

- [x] Base Next.js 16 setup with App Router
- [x] TypeScript configuration with strict mode
- [x] Tailwind CSS 4 integration
- [x] ESLint configuration
- [x] Memory bank documentation
- [x] Recipe system for common features

## Current Structure

| File/Directory | Purpose | Status |
|----------------|---------|--------|
| `src/app/page.tsx` | Home page | ✅ Ready |
| `src/app/layout.tsx` | Root layout | ✅ Ready |
| `src/app/globals.css` | Global styles | ✅ Ready |
| `.kilocode/` | AI context & recipes | ✅ Ready |

## Current Focus

The template is ready. Next steps depend on user requirements:

1. What type of application to build
2. What features are needed
3. Design/branding preferences

## Quick Start Guide

### To add a new page:

Create a file at `src/app/[route]/page.tsx`:
```tsx
export default function NewPage() {
  return <div>New page content</div>;
}
```

### To add components:

Create `src/components/` directory and add components:
```tsx
// src/components/ui/Button.tsx
export function Button({ children }: { children: React.ReactNode }) {
  return <button className="px-4 py-2 bg-blue-600 text-white rounded">{children}</button>;
}
```

### To add a database:

Follow `.kilocode/recipes/add-database.md`

### To add API routes:

Create `src/app/api/[route]/route.ts`:
```tsx
import { NextResponse } from "next/server";

export async function GET() {
  return NextResponse.json({ message: "Hello" });
}
```

## Available Recipes

| Recipe | File | Use Case |
|--------|------|----------|
| Add Database | `.kilocode/recipes/add-database.md` | Data persistence with Drizzle + SQLite |

## Pending Improvements

- [ ] Add more recipes (auth, email, etc.)
- [ ] Add example components
- [ ] Add testing setup recipe

## Session History

| Date | Changes |
|------|---------|
| Initial | Template created with base setup |
| 2026-02-24 | Built LimeSurvey 6.x AI Interview plugin (AIInterview/) — custom question type with OpenAI ChatGPT integration, server-side API proxy, chat UI, plain-text transcript storage. Packaged as AIInterview.zip. |
| 2026-02-24 | Fixed question type registration: changed type code from 'I' (conflicts with LimeSurvey built-in Date type) to 'Z'; added question_themes/AIInterview/config.xml required by LimeSurvey 6.x for question types to appear in the editor UI. Repackaged AIInterview.zip. |
| 2026-02-25 | Major fix: rewrote question type registration to use LimeSurvey 6.x QuestionTheme system correctly. Plugin now extends type T (Long Free Text), copies theme to upload/themes/question/ on activation, imports theme into DB via importManifest(). Added Twig template at question_themes/AIInterview/survey/questions/answer/longfreetext/answer.twig. Plugin uses beforeQuestionRender to inject config data attributes. Removed non-existent listQuestionPlugins event. Updated JS to use SGQA-based DOM IDs. Updated README with correct installation steps. |
| 2026-02-25 | Fix: replaced importManifest() with direct QuestionTheme model save. importManifest() was silently failing because its path-comparison logic for setting coreTheme didn't match the destDir. Now directly sets all required DB fields (name, visible, xml_path, title, api_version, question_type, core_theme=0, extends='T', group, settings, theme_type) and calls save(false). Repackaged AIInterview.zip. |
| 2026-02-26 | Fix: changed extends from 'T' to '' so AI Interview appears as a standalone entry in the question type selector grid (not as a hidden theme variant of Long Free Text). Added /plugins/direct?function=reinstall and ?function=debug endpoints for admin diagnostics. Added newAdminMenu event warning if config.xml is not readable. Always delete+re-register DB record on activation. Repackaged AIInterview.zip. |
| 2026-02-26 | Fix question preview mode: beforeQuestionRender fires before Twig renders so data attributes were never injected. Switched to afterRenderQuestion (fires post-render, including in admin preview). Moved data attribute output (prompt, ajaxUrl, surveyId, maxTokens, language, mandatory) into the Twig template directly using question_attributes variable and createUrl() Twig global — widget now initialises in both live survey and admin question preview. Repackaged AIInterview.zip. |
| 2026-02-26 | Fix widget not rendering at all (plain long text shown): Twig template was not being used. Rewrote rendering to use dual approach: (1) afterRenderQuestion replaces the standard textarea with full widget HTML via PHP regex substitution; (2) beforeQuestionRender injects a JS script that transforms the textarea into the widget after DOM load (fallback for preview mode). Both approaches detect AI Interview questions by question_theme_name='AIInterview' OR presence of ai_interview_prompt attribute. Removed SurveyDynamic DB call (fails in preview). Repackaged AIInterview.zip. |
| 2026-02-26 | Fix AI not responding in chat (v1.3.0): The chat AJAX endpoint was rejecting requests in admin preview mode with 403 "No active survey session" because there is no $_SESSION['survey_{id}'] when an admin previews a question. Fixed by allowing logged-in admins (Permission::hasGlobalPermission('surveys','read')) to bypass the session check. Also relaxed the surveyId>0 requirement (messages alone are sufficient). Repackaged AIInterview.zip. |
| 2026-02-26 | Debug/fix AI still returning zero text (v1.4.0): Added CSRF token support to XHR requests (reads from meta tag, hidden input, window.LS.csrfToken, or cookie). Added console.log/error throughout JS for browser-console debugging. Added /testchat endpoint that returns plugin config status without calling OpenAI. Improved error messages to show raw server response in console. Added handleTestChatRequest() PHP method. Repackaged AIInterview.zip. |
| 2026-02-26 | Fix v1.5.0: Two bugs fixed. (1) Live survey Next button did nothing — LimeSurvey mandatory-field JS validation was blocking navigation because the hidden answer textarea was empty (display:none elements may be skipped by some validators). Fixed by: pre-populating the hidden textarea with "[AI Interview in progress]" placeholder on widget init; changing textarea from display:none to position:absolute/opacity:0 so validators can read it. (2) Added Retry button to error banner so respondents can re-attempt the opening AI call without reloading. Bumped to v1.5.0 / JS v1.3.0. Repackaged AIInterview.zip. Committed as 5c2b192. |
| 2026-02-26 | Fix v1.6.0: Widget not initialising in preview — JS logged "No widgets found on page" because initAllWidgets() ran at DOMContentLoaded before the inline PHP-injected script had replaced the textarea with the widget div. Fixed by: (1) exposing window.AIInterviewInitAll globally so the inline script can call it after DOM injection; (2) adding data-ai-initialized guard to prevent double-initialisation; (3) inline script now calls AIInterviewInitAll() after replacing the textarea. JS bumped to v1.4.0. Repackaged AIInterview.zip. |
