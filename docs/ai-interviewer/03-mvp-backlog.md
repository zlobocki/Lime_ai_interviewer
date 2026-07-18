# AI Interviewer — MVP Backlog (360° Pilot)

**Version:** 0.1 (draft)  
**Date:** 2026-06-16  
**Goal:** Ship a pilot-ready voice interview on `https://forms.aisurvey.eu/` for one 360° project (~20 participants)  
**Base:** AIInterview LimeSurvey plugin v1.11.0  

---

## 1. MVP definition of done

The pilot is **done** when an HR/OD consultant can:

1. Create a 360° feedback interview project in LimeSurvey with script, competency model, and anonymity mode  
2. Import 20 participants and send bulk email invitations  
3. Have respondents complete a **voice** interview on desktop or mobile (English or Polish)  
4. Pause and resume via the same link  
5. View all results in one admin table with transcript + AI summary + tagged quotes  
6. Export CSV for analysis  
7. Configure one reminder for non-completers  
8. Confirm all speech processing uses **EU-hosted** Azure Speech  

---

## 2. Prioritized backlog

Estimates are **story points** (1 ≈ 0.5 day, 8 ≈ 1 week).  
Priority: **P0** = pilot blocker, **P1** = pilot important, **P2** = post-pilot.

---

### Phase 0 — Foundation (Week 1)

| ID | Priority | Story | Points | Depends on |
|----|----------|-------|--------|------------|
| F-01 | P0 | DB migrations: `project`, `session`, `result`, `quote` tables | 3 | — |
| F-02 | P0 | Plugin upgrade path from v1.11; version bump, activation hook runs migrations | 2 | F-01 |
| F-03 | P0 | Azure Speech EU client in PHP (recognizeOnce from temp file) | 5 | — |
| F-04 | P0 | Plugin settings: Azure Speech key, region, Azure OpenAI endpoint/key/deployment | 2 | — |
| F-05 | P0 | Extend `chat` endpoint: accept `token`, `qid`, cumulative token tracking | 3 | F-01 |
| F-06 | P1 | Feature flag `ai_interview_mode`: `voice` vs `chat` (default voice for new) | 1 | F-02 |

**Milestone M0:** Plugin installs on forms.aisurvey.eu staging; Azure STT returns text from test audio POST.

---

### Phase 1 — Voice respondent experience (Weeks 2–3)

| ID | Priority | Story | Points | Depends on |
|----|----------|-------|--------|------------|
| V-01 | P0 | Voice widget shell: avatar area, question display, status bar | 5 | F-06 |
| V-02 | P0 | Static avatar state switching (idle/listening/thinking/speaking) | 2 | V-01 |
| V-03 | P0 | Microphone check screen with level meter | 3 | V-01 |
| V-04 | P0 | `MediaRecorder` capture + client VAD (silence → stop) | 5 | V-03 |
| V-05 | P0 | `voiceTranscribe` endpoint integration from browser | 3 | F-03, V-04 |
| V-06 | P0 | Turn loop: STT → append user message → `chat` → show question | 5 | F-05, V-05 |
| V-07 | P0 | Write transcript to hidden LS answer field (preserve v1.11 compatibility) | 2 | V-06 |
| V-08 | P0 | Finish button + token budget auto-complete (extend v1.11 logic) | 2 | V-06 |
| V-09 | P0 | Mobile-responsive layout (CSS); test iOS Safari + Android Chrome | 3 | V-01 |
| V-10 | P1 | Hold-to-speak fallback button | 2 | V-04 |
| V-11 | P1 | Welcome screen with privacy text from project anonymity mode | 2 | A-03 |
| V-12 | P2 | Optional transcript panel toggle | 1 | V-01 |

**Milestone M1:** Internal team completes full voice interview on staging; transcript in LS response.

---

### Phase 2 — Session persistence & pause/resume (Week 3)

| ID | Priority | Story | Points | Depends on |
|----|----------|-------|--------|------------|
| S-01 | P0 | `sessionGet` / `sessionSave` endpoints | 5 | F-01 |
| S-02 | P0 | Auto-save after each turn | 2 | S-01, V-06 |
| S-03 | P0 | Pause & continue later UI + pause token in URL | 3 | S-01 |
| S-04 | P0 | Resume loads conversation + transcript; skip welcome if in progress | 3 | S-01, S-03 |
| S-05 | P1 | Partial data status `in_progress` / `abandoned` in session table | 2 | S-01 |
| S-06 | P1 | Back-navigation within same LS session (restore from session DB) | 2 | S-04 |

**Milestone M2:** Start interview, pause mid-way, close browser, resume via same token URL.

---

### Phase 3 — Project admin UI (Weeks 4–5)

| ID | Priority | Story | Points | Depends on |
|----|----------|-------|--------|------------|
| A-01 | P0 | Admin menu: AI Interviewer → Projects list (surveys with AI question) | 3 | F-02 |
| A-02 | P0 | Project settings — General: language (en/pl), anonymity mode, retention date, status | 5 | F-01, A-01 |
| A-03 | P0 | Project settings — Script: extend existing prompt editor + research brief field | 3 | A-02 |
| A-04 | P0 | Project settings — AI: token budget, probing max (inherits question attr) | 2 | A-02 |
| A-05 | P1 | Project settings — Avatar: upload 4 images, interviewer name, preview | 5 | A-02, V-02 |
| A-06 | P0 | Participants view: read LS tokens, show status (sync from session table) | 5 | S-01, A-01 |
| A-07 | P0 | Bulk send invitations (wrap LS token email send) | 3 | A-06 |
| A-08 | P0 | Email template editor: invitation body with placeholders | 3 | A-07 |
| A-09 | P1 | Reminder: one template + schedule (days after invite) + manual send button | 5 | A-07 |
| A-10 | P0 | Close project: block new starts, allow in-flight to complete | 2 | A-02 |
| A-11 | P1 | Plugin permissions: register `aiinterview_admin`, `aiinterview_view` | 3 | A-01 |
| A-12 | P2 | CSV import participants → create tokens | 3 | A-06 |

**Milestone M3:** Consultant configures 360 project end-to-end without DB access.

---

### Phase 4 — Analysis & results (Week 5–6)

| ID | Priority | Story | Points | Depends on |
|----|----------|-------|--------|------------|
| R-01 | P0 | `analyze` endpoint: generate summary from transcript + research brief | 5 | F-01, V-08 |
| R-02 | P0 | Tag quotes to competencies/themes (structured LLM JSON output) | 5 | R-01 |
| R-03 | P0 | Trigger analyze on interview complete; retry on failure (cron) | 3 | R-01 |
| R-04 | P0 | Results table: one row per participant (status, dates, summary snippet) | 5 | R-01, A-01 |
| R-05 | P0 | Participant detail view: full transcript, summary, quotes list | 3 | R-04 |
| R-06 | P0 | CSV export: participants.csv + quotes.csv | 3 | R-04 |
| R-07 | P1 | Anonymity: suppress identity in UI/export for anonymous mode | 5 | A-02, R-04 |
| R-08 | P1 | Anonymous n ≥ 5 gate on quote drill-down | 2 | R-07 |
| R-09 | P1 | Manual delete participant data (GDPR) | 3 | R-04 |
| R-10 | P1 | Retention cron: auto-delete past retention_date | 3 | A-02 |

**Milestone M4:** Completed pilot interview shows summary + tagged quotes; CSV downloads correctly.

---

### Phase 5 — Polish, GDPR, pilot hardening (Week 6–7)

| ID | Priority | Story | Points | Depends on |
|----|----------|-------|--------|------------|
| H-01 | P0 | Polish language: `pl-PL` STT locale + Polish system prompt injection | 3 | F-03, A-02 |
| H-02 | P0 | Rate limiting on voiceTranscribe per token | 2 | V-05 |
| H-03 | P0 | Error UX: Azure down, mic denied, network timeout (retry paths) | 3 | V-05 |
| H-04 | P1 | Audit log for admin views/deletes (confidential mode) | 3 | R-09 |
| H-05 | P1 | Admin debug page: test STT, test LLM, session inspect (extend existing `debug`) | 2 | F-03 |
| H-06 | P1 | Documentation: admin guide + respondent troubleshooting | 2 | — |
| H-07 | P1 | Pilot deployment checklist for forms.aisurvey.eu production | 1 | — |
| H-08 | P2 | Structured script JSON editor (mandatory flags per question) | 5 | A-03 |

**Milestone M5 (PILOT GO):** 20-participant 360° project live on forms.aisurvey.eu.

---

## 3. Sprint plan (suggested 7-week timeline)

| Week | Focus | Deliverable |
|------|-------|-------------|
| 1 | Phase 0 | M0 — Azure STT working server-side |
| 2 | Phase 1a | V-01–V-05 — record + transcribe |
| 3 | Phase 1b + 2 | M1 + M2 — full voice loop + pause/resume |
| 4 | Phase 3a | Project settings + participants |
| 5 | Phase 3b + 4a | Invites/reminders + analyze pipeline |
| 6 | Phase 4b | Results table + CSV export |
| 7 | Phase 5 | Polish, GDPR hardening, pilot launch |

**Buffer:** Week 8 for pilot feedback fixes before scaling features.

---

## 4. What we are NOT building in MVP

- TTS / AI spoken voice  
- Zoom integration  
- Node WebSocket sidecar (unless STT latency fails acceptance in Week 3)  
- PDF reports  
- Multi-reminder sequences  
- Branching script logic UI  
- SaaS multi-tenancy  
- Real-time admin dashboard analytics  

---

## 5. Pilot test plan

### Pre-launch (internal)

| # | Test | Pass criteria |
|---|------|---------------|
| T1 | Mic check on Chrome desktop | Level meter responds; proceed enabled |
| T2 | Mic check on iPhone Safari | Permission flow works; interview completes |
| T3 | Full 5-question interview in English | Transcript accurate; summary + ≥3 quotes generated |
| T4 | Full interview in Polish | STT + LLM in Polish |
| T5 | Pause at Q2, resume next day | Continues at Q3; no data loss |
| T6 | Token budget set to 500 | Interview ends gracefully with message |
| T7 | Anonymous mode + 3 completes | No identity in export; drill-down blocked |
| T8 | Anonymous mode + 5 completes | Aggregate quotes visible |
| T9 | Reminder email | Only sent to non-complete tokens |
| T10 | CSV export | Opens in Excel; quotes linked by participant_id |
| T11 | Retention date passed | Cron deletes records |
| T12 | Manual delete | All session/result/quote rows removed |

### Pilot (20 participants)

| Metric | Target |
|--------|--------|
| Completion rate | ≥ 70% within 2 weeks |
| Median duration | 10–20 minutes |
| STT failure rate | < 5% of turns (retry succeeds) |
| Respondent satisfaction (optional 1-question) | ≥ 4/5 |
| Admin time to set up project | < 2 hours (excluding script writing) |

---

## 6. Risk register

| Risk | Impact | Mitigation |
|------|--------|------------|
| Mobile Safari mic quirks | High | Hold-to-speak fallback (V-10); test early Week 2 |
| STT latency > 5 s | Medium | Utterance length cap; Phase 2 streaming sidecar |
| LLM cost overrun | Medium | Token budget per interview; admin monitoring in results table |
| LimeSurvey admin UI complexity | Medium | Start with minimal plugin pages; reuse LS token UI where possible |
| Anonymous mode re-identification via quotes | High | LLM instruction to avoid names; admin review warning |
| Azure EU outage | Low | Graceful error + retry; pause preserves state |

---

## 7. Mapping to existing plugin files

| Backlog area | Files to modify/create |
|--------------|------------------------|
| Foundation | `AIInterview.php`, `migrations/`, `config.xml` |
| Voice UI | `assets/ai-interview-voice.js`, `assets/ai-interview-voice.css`, `buildWidgetHtml()` |
| STT | `components/AzureSpeechClient.php` (new) |
| Session | `models/AiInterviewSession.php`, direct endpoints |
| Admin | `controllers/AdminController.php`, `views/admin/*` |
| Analysis | `components/InterviewAnalyzer.php` |
| Avatar | `upload/ai_interview/{sid}/`, project settings |

**Preserve:** Existing chat mode in `assets/ai-interview.js` for rollback and admin preview.

---

## 8. First implementation ticket (recommended start)

**Ticket F-03 + V-05 spike:** Prove EU Azure Speech loop on forms.aisurvey.eu.

**Tasks:**
1. Add Azure Speech settings to plugin config  
2. Implement `voiceTranscribe` endpoint  
3. Minimal HTML test page (admin-only) that records 5 s audio and displays transcription  
4. Document Azure region and GDPR settings in admin help text  

**Acceptance:** Super-admin records English and Polish audio; correct transcription returned; no audio persisted to disk after processing.

---

## 9. Post-MVP backlog (prioritized preview)

| ID | Feature | Priority |
|----|---------|----------|
| PM-01 | Structured script JSON UI with mandatory per question | P1 |
| PM-02 | Node WebSocket streaming STT | P1 |
| PM-03 | TTS for question read-aloud | P2 |
| PM-04 | Multiple reminder templates | P2 |
| PM-05 | Thematic clustering across participants | P2 |
| PM-06 | PDF client report | P2 |
| PM-07 | Redis session store for scale | P1 (before 6000 participants) |
| PM-08 | LimeSurvey 6.x public API for external dashboards | P3 |

---

*Documents in this folder: `01-product-spec.md`, `02-technical-architecture.md`, `03-mvp-backlog.md`*
