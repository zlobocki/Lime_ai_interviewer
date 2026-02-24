# AI Interview — LimeSurvey Plugin

**Version:** 1.0.0  
**Compatibility:** LimeSurvey 6.x (tested on 6.13.2)  
**License:** GPL v2

---

## What It Does

This plugin adds an **"AI Interview"** question type to LimeSurvey. When a respondent reaches this question, they are presented with a chat interface where an AI interviewer (powered by OpenAI ChatGPT) conducts a structured interview. The full conversation transcript is saved as the answer to the question.

---

## Features

- 💬 **Chat-style interface** — resembles ChatGPT, familiar and easy to use
- 🔒 **Secure API key** — the OpenAI API key is stored server-side and never exposed to respondents
- 🌐 **Multilingual** — the AI automatically uses the survey's active language
- 📝 **Plain-text transcript** — saved as `Interviewer: ... / User: ...` format
- ⚙️ **Configurable per question** — custom prompt, token budget, mandatory flag
- 🛑 **Graceful error handling** — if OpenAI is unreachable, respondents can skip the question
- 🔢 **Token budget** — automatically ends the interview when the configured token limit is reached

---

## Installation

### Step 1 — Upload the plugin

Upload the entire `AIInterview` folder to your LimeSurvey installation:

```
<limesurvey_root>/plugins/AIInterview/
```

The folder structure should look like:

```
plugins/
└── AIInterview/
    ├── AIInterview.php       ← Main plugin file
    ├── config.xml            ← Plugin manifest
    ├── README.md             ← This file
    └── assets/
        ├── ai-interview.css  ← Chat widget styles
        └── ai-interview.js   ← Chat widget logic
```

### Step 2 — Activate the plugin

1. Log in to LimeSurvey Admin
2. Go to **Configuration → Plugin Manager**
3. Find **AIInterview** in the list
4. Click **Activate**

### Step 3 — Configure the API key

1. In Plugin Manager, click the **Settings** (gear) icon next to AIInterview
2. Enter your **OpenAI API Key** (starts with `sk-...`)
3. Optionally change the **OpenAI Model** (default: `gpt-4o`)
4. Click **Save**

> ⚠️ **Security note:** The API key is stored in LimeSurvey's plugin settings database table. It is never included in any HTML page or JavaScript file served to respondents. All OpenAI API calls are made server-side.

---

## Creating an AI Interview Question

1. Open or create a survey
2. Add a new question
3. Set the **Question type** to **AI Interview** (under the plugin question types)
4. In the **Advanced** tab, configure:

| Setting | Description | Default |
|---------|-------------|---------|
| **AI Interviewer Prompt / Instructions** | The system prompt that instructs the AI how to conduct the interview | See below |
| **Maximum Token Budget** | Total tokens (prompt + conversation) before auto-ending | 6000 |
| **Mandatory Interaction** | Whether the respondent must send at least one message | No |

---

## Writing a Good Prompt

The prompt is the most important configuration. It tells the AI what to ask and how to behave.

**Template:**

```
You are a professional interviewer conducting a structured interview on behalf of a researcher.

Your goal is to explore the respondent's experiences and opinions on [YOUR TOPIC HERE].

Guidelines:
- Begin by introducing yourself briefly and asking your first question.
- Ask [N] open-ended questions, one at a time.
- Follow up on interesting or unclear answers with probing questions.
- Be warm, professional, and neutral.
- When you have finished all questions, thank the respondent and tell them:
  "Please press the Finish Interview button to save your responses."

Start the interview now.
```

**Tips:**
- Replace `[YOUR TOPIC HERE]` with your specific research topic
- Replace `[N]` with the number of questions (5–7 is typical)
- Always include the instruction to press "Finish Interview" at the end
- Be specific about the depth of follow-up you want

---

## Answer Format

The answer is stored as a plain-text transcript:

```
Interviewer: Hello! I'm here to learn about your experience with remote work. To start, could you tell me how long you have been working remotely?

User: I've been working from home for about three years now, since the pandemic.

Interviewer: That's interesting. How has your productivity changed compared to working in an office?

User: Honestly, I think I'm more productive at home. Fewer interruptions.

...

--- Interview concluded ---
```

---

## Token Budget Guide

| Budget | Approximate capacity |
|--------|---------------------|
| 3000   | ~5 short exchanges |
| 6000   | ~8–10 exchanges (default) |
| 10000  | ~15–20 exchanges |
| 16000  | ~25–30 exchanges |

Note: The actual number of exchanges depends on message length. The system prompt also consumes tokens.

---

## Troubleshooting

### "The AI service is not configured"
→ The OpenAI API key has not been set. Go to Plugin Manager → AIInterview → Settings.

### "AI service error: ..."
→ The OpenAI API returned an error. Check that your API key is valid and has sufficient credits.

### "Network error contacting AI service"
→ Your server cannot reach `api.openai.com`. Check firewall rules and outbound HTTPS access on your hosting.

### The question type doesn't appear in the question editor
→ Make sure the plugin is **activated** (not just installed) in Plugin Manager.

### OVH Shared Hosting Notes
- Ensure **cURL** is enabled (it is on most OVH shared plans)
- Ensure outbound HTTPS (port 443) is not blocked
- If you see SSL errors, your server's CA bundle may be outdated — contact OVH support

---

## Privacy & Data

- Respondent messages are sent to OpenAI's API for processing
- OpenAI's data usage policies apply: https://openai.com/policies/api-data-usage-policies
- It is the survey administrator's responsibility to inform respondents via the survey disclaimer
- No conversation data is stored by this plugin beyond LimeSurvey's standard response storage

---

## Changelog

### 1.0.0 (2026-02-24)
- Initial release
- AI Interview question type with ChatGPT integration
- Server-side API key proxy
- Multilingual support
- Token budget management
- Plain-text transcript storage
