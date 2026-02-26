# AIInterview — LimeSurvey Plugin

An AI-powered conversational interview question type for LimeSurvey 6.x.

Respondents engage in a real-time chat with an AI interviewer powered by OpenAI ChatGPT. The full conversation transcript is saved as the question answer.

---

## Features

- **Conversational AI interviews** — respondents chat with an AI interviewer in natural language
- **Secure API proxy** — the OpenAI API key is stored server-side and never exposed to respondents
- **Transcript storage** — the full conversation is saved as plain text in the survey response
- **Configurable per question** — set a custom prompt, token budget, and mandatory interaction flag
- **Multi-language support** — the AI responds in the survey's active language
- **Back-navigation support** — previously saved transcripts are restored when navigating back

---

## Requirements

- LimeSurvey 6.x
- PHP 7.4+ with cURL extension
- An OpenAI API key

---

## Installation

### Step 1 — Upload the plugin

Copy the `AIInterview/` folder to your LimeSurvey `plugins/` directory:

```
<limesurvey>/plugins/AIInterview/
```

### Step 2 — Activate the plugin

1. Log in to LimeSurvey as an administrator
2. Go to **Admin → Configuration → Plugin Manager**
3. Find **AIInterview** in the list and click **Activate**

When the plugin activates, it automatically:
- Copies the question theme to `upload/themes/question/AIInterview/`
- Registers the "AI Interview" question type in the database

### Step 3 — Configure the OpenAI API key

1. In the Plugin Manager, click the **Settings** icon next to AIInterview
2. Enter your **OpenAI API Key**
3. Optionally change the **OpenAI Model** (default: `gpt-4o`)
4. Click **Save**

### Step 4 — Create an AI Interview question

1. Open or create a survey
2. Add a new question
3. In the question type selector, find **"AI Interview"** (under the Text questions group)
4. Set the question text (this is shown above the chat widget)
5. In the **Advanced** tab, configure:
   - **AI Interviewer Prompt / Instructions** — the system prompt for the AI
   - **Maximum Token Budget** — total tokens before the interview auto-concludes (default: 6000)
   - **Mandatory Interaction** — whether the respondent must send at least one message

---

## How It Works

### Question type registration

When the plugin is activated, it copies the question theme to LimeSurvey's user question themes directory (`upload/themes/question/AIInterview/`) and imports it into the `question_themes` database table. This makes "AI Interview" appear in the question type selector.

The question type extends **Long Free Text (T)**, so the answer is stored as a text field — the full conversation transcript.

### Survey rendering

When a respondent reaches an AI Interview question:
1. The Twig template renders the chat widget (message area, input box, Send/Finish buttons)
2. The plugin's `beforeQuestionRender` event injects the configuration (prompt, token budget, etc.) as data attributes
3. The JavaScript initialises the widget and sends the system prompt to OpenAI to get the opening message
4. The respondent chats with the AI; each exchange is appended to the transcript
5. When the respondent clicks "Finish Interview" (or the token budget is exhausted), the transcript is saved to the hidden answer field and submitted with the survey

### Security

- The OpenAI API key is stored in the plugin settings table and **never** sent to the browser
- All OpenAI API calls are made server-side via the plugin's AJAX proxy endpoint
- The proxy endpoint requires an active survey session (prevents abuse outside surveys)

---

## Uninstallation

1. Deactivate the plugin in the Plugin Manager
2. Delete the `AIInterview/` folder from `plugins/`
3. Optionally delete `upload/themes/question/AIInterview/`

---

## Troubleshooting

### "AI Interview" does not appear in the question type selector

1. Make sure the plugin is **activated** (not just installed)
2. Go to **Admin → Configuration → Themes → Question Themes** and check if "AI Interview" is listed
3. If it is listed in Question Themes but not in the question type selector, try the **Reinstall** endpoint:
   - Visit: `https://your-limesurvey.example.com/index.php/plugins/direct?plugin=AIInterview&function=reinstall`
   - This forces re-registration of the theme without deactivating the plugin
4. For detailed diagnostics, visit:
   - `https://your-limesurvey.example.com/index.php/plugins/direct?plugin=AIInterview&function=debug`
   - This returns JSON showing the DB record, file paths, and whether `config.xml` is readable
5. If not listed in Question Themes, try deactivating and reactivating the plugin
6. Check that `upload/themes/question/AIInterview/` exists and contains `config.xml`

### The chat widget shows "AI Interview is not configured"

The question's AI Interviewer Prompt is empty. Go to the question editor → Advanced tab → set the **AI Interviewer Prompt / Instructions**.

### The AI service is unavailable

1. Check that the OpenAI API key is correctly set in the plugin settings
2. Verify the key has sufficient credits/quota
3. Check server logs for cURL errors

---

## License

GPL v2
