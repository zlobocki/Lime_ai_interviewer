<?php
/**
 * AIInterview - LimeSurvey Plugin
 *
 * Adds a custom "AI Interview" question type to LimeSurvey 6.x.
 * Respondents engage in a conversational AI-driven interview powered by OpenAI.
 * The full conversation is saved as a plain-text transcript.
 *
 * Installation:
 *   1. Upload the AIInterview folder to <limesurvey>/plugins/
 *   2. Activate the plugin in Admin → Configuration → Plugin Manager
 *   3. Enter your OpenAI API key in the plugin settings
 *   4. Create a new question and select "AI Interview" as the question type
 *
 * @author      AI Interview Plugin
 * @license     GPL v2
 * @version     1.0.0
 * @since       LimeSurvey 6.0
 */

class AIInterview extends PluginBase
{
    protected $storage = 'DbStorage';

    static protected $description = 'Adds an AI Interview question type powered by OpenAI ChatGPT.';
    static protected $name = 'AIInterview';

    /**
     * Plugin-level settings (stored in plugin settings table, NEVER exposed to frontend)
     */
    protected $settings = [
        'openai_api_key' => [
            'type'    => 'string',
            'label'   => 'OpenAI API Key',
            'help'    => 'Your OpenAI API key. Stored securely on the server and never sent to survey respondents. Keep this confidential.',
            'default' => '',
        ],
        'openai_model' => [
            'type'    => 'string',
            'label'   => 'OpenAI Model',
            'help'    => 'The OpenAI model to use. Recommended: gpt-4o (best quality), gpt-4-turbo (fast), gpt-3.5-turbo (economical).',
            'default' => 'gpt-4o',
        ],
    ];

    /**
     * Register all event subscriptions
     */
    public function init()
    {
        // Question type registration (LimeSurvey 6.x)
        $this->subscribe('listQuestionPlugins');
        $this->subscribe('newQuestionAttributes');
        $this->subscribe('beforeQuestionRender');

        // Server-side AJAX proxy endpoint
        $this->subscribe('newDirectRequest');
    }

    // =========================================================================
    // QUESTION TYPE REGISTRATION (LimeSurvey 6.x)
    // =========================================================================

    /**
     * Register this plugin as providing a custom question type.
     * LimeSurvey 6.x fires this event to discover available question types.
     */
    public function listQuestionPlugins()
    {
        $event = $this->getEvent();
        $event->append('questionplugins', [
            'AIInterview' => [
                'name'        => gT('AI Interview'),
                'description' => gT('An AI-powered conversational interview. The respondent chats with an AI interviewer and the full transcript is saved as the answer.'),
                'class'       => 'AIInterview',
                'type'        => 'I',
            ],
        ]);
    }

    /**
     * Register per-question attributes (shown in the question editor under "Advanced" tab)
     */
    public function newQuestionAttributes()
    {
        $event = $this->getEvent();

        $questionAttributes = [
            'ai_interview_prompt' => [
                'types'    => 'I',
                'category' => gT('AI Interview Settings'),
                'sortorder'=> 1,
                'inputtype'=> 'textarea',
                'default'  => $this->getDefaultPrompt(),
                'help'     => gT(
                    'Instructions for the AI interviewer. '
                    . 'Tip: Specify the topic, the number of questions to ask, the depth of follow-up expected, '
                    . 'and include an explicit instruction such as: '
                    . '"When you have finished all questions, thank the respondent and tell them to press the Finish Interview button."'
                ),
                'caption'  => gT('AI Interviewer Prompt / Instructions'),
            ],
            'ai_interview_max_tokens' => [
                'types'    => 'I',
                'category' => gT('AI Interview Settings'),
                'sortorder'=> 2,
                'inputtype'=> 'integer',
                'default'  => 6000,
                'help'     => gT(
                    'Maximum total tokens (prompt + all messages + AI replies) for this interview. '
                    . 'When this budget is reached the interview ends automatically. Default: 6000.'
                ),
                'caption'  => gT('Maximum Token Budget'),
            ],
            'ai_interview_mandatory' => [
                'types'    => 'I',
                'category' => gT('AI Interview Settings'),
                'sortorder'=> 3,
                'inputtype'=> 'singleselect',
                'options'  => [
                    '0' => gT('No – respondent may skip'),
                    '1' => gT('Yes – respondent must send at least one message'),
                ],
                'default'  => '0',
                'help'     => gT('Whether the respondent must interact with the AI before they can proceed to the next page.'),
                'caption'  => gT('Mandatory Interaction'),
            ],
        ];

        $event->append('questionAttributes', $questionAttributes);
    }

    // =========================================================================
    // QUESTION RENDERING
    // =========================================================================

    /**
     * Render the AI Interview widget when LimeSurvey renders a question of type 'I'
     *
     * In LimeSurvey 6.x, plugin-registered question types use the plugin class name
     * as the type identifier. We check for both the class name and the short code.
     */
    public function beforeQuestionRender()
    {
        $event = $this->getEvent();

        // Only handle our custom question type
        // LimeSurvey 6.x uses the plugin class name as the type for plugin question types
        $type = $event->get('type');
        if ($type !== 'AIInterview' && $type !== 'I') {
            return;
        }

        $questionId = (int) $event->get('qid');
        $surveyId   = (int) $event->get('surveyId');
        $sgqaCode   = (string) $event->get('sgqa');

        // Retrieve per-question attributes
        $attributes = QuestionAttribute::model()->getQuestionAttributes($questionId);

        $prompt    = isset($attributes['ai_interview_prompt'])
                     ? trim((string) $attributes['ai_interview_prompt'])
                     : $this->getDefaultPrompt();

        $maxTokens = isset($attributes['ai_interview_max_tokens'])
                     ? max(500, (int) $attributes['ai_interview_max_tokens'])
                     : 6000;

        $mandatory = isset($attributes['ai_interview_mandatory'])
                     ? (int) $attributes['ai_interview_mandatory']
                     : 0;

        // Detect the active survey language for this session
        $language = $this->getSessionLanguage($surveyId);

        // Build the AJAX URL for the server-side OpenAI proxy
        $ajaxUrl = Yii::app()->createUrl(
            'plugins/direct',
            ['plugin' => 'AIInterview', 'function' => 'chat']
        );

        // Retrieve any previously saved answer (supports back-navigation)
        $existingAnswer = '';
        $sessionKey = 'survey_' . $surveyId;
        if (isset($_SESSION[$sessionKey][$sgqaCode])) {
            $existingAnswer = (string) $_SESSION[$sessionKey][$sgqaCode];
        }

        // Publish and register assets
        $assetPath = dirname(__FILE__) . '/assets';
        $assetUrl  = Yii::app()->assetManager->publish($assetPath);

        Yii::app()->clientScript->registerCssFile(
            $assetUrl . '/ai-interview.css',
            'screen'
        );
        Yii::app()->clientScript->registerScriptFile(
            $assetUrl . '/ai-interview.js',
            CClientScript::POS_END
        );

        // Build and inject the widget HTML
        $html = $this->buildWidgetHtml(
            $questionId,
            $sgqaCode,
            $surveyId,
            $ajaxUrl,
            $prompt,
            $maxTokens,
            $mandatory,
            $language,
            $existingAnswer
        );

        // Append our widget HTML after the question text
        $event->set('questiontext', $event->get('questiontext') . $html);
    }

    // =========================================================================
    // AJAX PROXY ENDPOINT  (server-side — API key NEVER leaves the server)
    // =========================================================================

    /**
     * Handle direct plugin requests.
     * Routed via: /index.php/plugins/direct?plugin=AIInterview&function=chat
     */
    public function newDirectRequest()
    {
        $event    = $this->getEvent();
        $function = $event->get('function');

        if ($function !== 'chat') {
            return;
        }

        $this->handleChatRequest();
        $event->set('success', true);
    }

    /**
     * Process an incoming chat message and proxy it to OpenAI.
     *
     * Expected JSON POST body:
     * {
     *   "surveyId":  123,
     *   "messages":  [{"role":"system","content":"..."}, ...],
     *   "maxTokens": 6000,
     *   "language":  "en"
     * }
     */
    private function handleChatRequest(): void
    {
        // Only accept POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(['error' => 'Method not allowed'], 405);
            return;
        }

        // Parse JSON body
        $rawBody = file_get_contents('php://input');
        $body    = json_decode($rawBody, true);

        if (!is_array($body)) {
            $this->sendJsonResponse(['error' => 'Invalid JSON body'], 400);
            return;
        }

        $surveyId  = isset($body['surveyId'])  ? (int)    $body['surveyId']  : 0;
        $messages  = isset($body['messages'])  ? (array)  $body['messages']  : [];
        $maxTokens = isset($body['maxTokens']) ? (int)    $body['maxTokens'] : 6000;
        $language  = isset($body['language'])  ? (string) $body['language']  : 'en';

        // Validate required fields
        if ($surveyId <= 0 || empty($messages)) {
            $this->sendJsonResponse(['error' => 'Missing required fields: surveyId and messages'], 400);
            return;
        }

        // Security: verify an active survey session exists for this survey
        // This prevents the endpoint from being used outside of an active survey
        if (!isset($_SESSION['survey_' . $surveyId])) {
            $this->sendJsonResponse(['error' => 'No active survey session. Please start the survey first.'], 403);
            return;
        }

        // Retrieve API credentials from plugin settings (server-side only — never in HTML/JS)
        $apiKey = trim((string) $this->get('openai_api_key', null, null, ''));
        $model  = trim((string) $this->get('openai_model',   null, null, 'gpt-4o'));

        if (empty($apiKey)) {
            $this->sendJsonResponse([
                'error' => 'The AI service is not configured. Please contact the survey administrator.'
            ], 503);
            return;
        }

        // Sanitize and validate messages
        $sanitizedMessages = $this->sanitizeMessages($messages);

        if (empty($sanitizedMessages)) {
            $this->sendJsonResponse(['error' => 'No valid messages provided'], 400);
            return;
        }

        // Inject language instruction into the system message
        $this->injectLanguageInstruction($sanitizedMessages, $language);

        // Call OpenAI API (server-side)
        $result = $this->callOpenAI($apiKey, $model, $sanitizedMessages, $maxTokens);

        if (isset($result['error'])) {
            $this->sendJsonResponse(['error' => $result['error']], 502);
            return;
        }

        $this->sendJsonResponse([
            'reply'        => $result['content'],
            'tokensUsed'   => $result['tokens_used'],
            'finishReason' => $result['finish_reason'],
        ]);
    }

    /**
     * Sanitize the messages array — only allow known roles and cap content length
     */
    private function sanitizeMessages(array $messages): array
    {
        $allowed = ['system', 'user', 'assistant'];
        $result  = [];

        foreach ($messages as $msg) {
            if (!is_array($msg)) continue;
            if (!isset($msg['role'], $msg['content'])) continue;
            if (!in_array($msg['role'], $allowed, true)) continue;

            $result[] = [
                'role'    => $msg['role'],
                'content' => mb_substr(trim((string) $msg['content']), 0, 8000),
            ];
        }

        return $result;
    }

    /**
     * Prepend a language instruction to the first system message (or create one)
     */
    private function injectLanguageInstruction(array &$messages, string $language): void
    {
        // Sanitise the language code to prevent injection
        $lang = preg_replace('/[^a-zA-Z\-]/', '', $language);
        if (empty($lang)) $lang = 'en';

        $instruction = "IMPORTANT: You must conduct this entire interview in the language with BCP-47 code '{$lang}'. "
                     . "All your responses must be in that language, regardless of what language the user writes in.";

        foreach ($messages as &$msg) {
            if ($msg['role'] === 'system') {
                $msg['content'] = $instruction . "\n\n" . $msg['content'];
                return;
            }
        }
        unset($msg);

        // No system message found — prepend one
        array_unshift($messages, ['role' => 'system', 'content' => $instruction]);
    }

    /**
     * Call the OpenAI Chat Completions API via cURL (server-side only)
     *
     * @param  string $apiKey
     * @param  string $model
     * @param  array  $messages
     * @param  int    $maxTokens  Total token budget; half is reserved for completion
     * @return array  On success: ['content', 'tokens_used', 'finish_reason']
     *                On failure: ['error' => string]
     */
    private function callOpenAI(string $apiKey, string $model, array $messages, int $maxTokens): array
    {
        // Reserve roughly half the budget for the AI's reply
        $maxCompletionTokens = max(256, (int) ($maxTokens / 2));

        $payload = json_encode([
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $maxCompletionTokens,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return ['error' => 'Failed to encode request payload'];
        }

        $ch = curl_init('https://api.openai.com/v1/chat/completions');

        if ($ch === false) {
            return ['error' => 'cURL initialisation failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'User-Agent: LimeSurvey-AIInterview/1.0',
            ],
            // Verify SSL certificate (important for security)
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['error' => 'Network error contacting AI service: ' . $curlError];
        }

        if ($response === false || $response === '') {
            return ['error' => 'Empty response from AI service'];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errMsg = isset($data['error']['message'])
                      ? $data['error']['message']
                      : 'HTTP ' . $httpCode;
            return ['error' => 'AI service error: ' . $errMsg];
        }

        if (empty($data['choices'][0]['message']['content'])) {
            return ['error' => 'The AI returned an empty response. Please try again.'];
        }

        return [
            'content'      => $data['choices'][0]['message']['content'],
            'tokens_used'  => (int) ($data['usage']['total_tokens'] ?? 0),
            'finish_reason'=> (string) ($data['choices'][0]['finish_reason'] ?? 'stop'),
        ];
    }

    // =========================================================================
    // HTML WIDGET BUILDER
    // =========================================================================

    /**
     * Build the complete HTML for the AI Interview chat widget
     */
    private function buildWidgetHtml(
        int    $questionId,
        string $sgqaCode,
        int    $surveyId,
        string $ajaxUrl,
        string $prompt,
        int    $maxTokens,
        int    $mandatory,
        string $language,
        string $existingAnswer
    ): string {
        // Escape all values that go into HTML attributes
        $ePrompt    = htmlspecialchars($prompt,    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eAjaxUrl   = htmlspecialchars($ajaxUrl,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eLanguage  = htmlspecialchars($language,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $eAnswer    = htmlspecialchars($existingAnswer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $mandatoryAttr   = $mandatory ? 'data-mandatory="1"' : 'data-mandatory="0"';

        $mandatoryNotice = $mandatory
            ? '<p class="ai-interview-mandatory-notice">'
              . gT('You must send at least one message before you can proceed.')
              . '</p>'
            : '';

        $finishLabel      = htmlspecialchars(gT('Finish Interview'),                    ENT_QUOTES, 'UTF-8');
        $inputPlaceholder = htmlspecialchars(gT('Type your response here…'),            ENT_QUOTES, 'UTF-8');
        $sendLabel        = htmlspecialchars(gT('Send'),                                ENT_QUOTES, 'UTF-8');
        $typingLabel      = htmlspecialchars(gT('Interviewer is typing…'),              ENT_QUOTES, 'UTF-8');
        $errorLabel       = htmlspecialchars(
            gT('The AI service is currently unavailable. You may skip this question or try again later.'),
            ENT_QUOTES, 'UTF-8'
        );
        $skipLabel        = htmlspecialchars(gT('Skip this question'),                  ENT_QUOTES, 'UTF-8');
        $tokenWarning     = htmlspecialchars(
            gT('The interview has reached its maximum length and has been automatically concluded.'),
            ENT_QUOTES, 'UTF-8'
        );

        return <<<HTML
<div class="ai-interview-widget"
     id="ai-interview-{$questionId}"
     data-qid="{$questionId}"
     data-sgqa="{$sgqaCode}"
     data-survey-id="{$surveyId}"
     data-ajax-url="{$eAjaxUrl}"
     data-prompt="{$ePrompt}"
     data-max-tokens="{$maxTokens}"
     data-language="{$eLanguage}"
     {$mandatoryAttr}>

    {$mandatoryNotice}

    <!-- Chat message display area -->
    <div class="ai-interview-messages"
         id="ai-messages-{$questionId}"
         role="log"
         aria-live="polite"
         aria-label="Interview conversation">
    </div>

    <!-- Typing indicator (shown while waiting for AI response) -->
    <div class="ai-interview-typing"
         id="ai-typing-{$questionId}"
         style="display:none;"
         aria-live="polite"
         aria-label="{$typingLabel}">
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-label">{$typingLabel}</span>
    </div>

    <!-- Error banner (shown when OpenAI is unreachable) -->
    <div class="ai-interview-error"
         id="ai-error-{$questionId}"
         style="display:none;"
         role="alert">
        <span class="ai-error-text">{$errorLabel}</span>
        <button type="button"
                class="ai-btn ai-btn-secondary ai-btn-skip"
                data-qid="{$questionId}">
            {$skipLabel}
        </button>
    </div>

    <!-- Token budget exhausted notice -->
    <div class="ai-interview-token-warning"
         id="ai-token-warning-{$questionId}"
         style="display:none;"
         role="status">
        {$tokenWarning}
    </div>

    <!-- User input area -->
    <div class="ai-interview-input-area" id="ai-input-area-{$questionId}">
        <textarea
            class="ai-interview-input"
            id="ai-input-{$questionId}"
            placeholder="{$inputPlaceholder}"
            rows="3"
            aria-label="{$inputPlaceholder}"
        ></textarea>
        <div class="ai-interview-actions">
            <button type="button"
                    class="ai-btn ai-btn-primary ai-btn-send"
                    id="ai-send-{$questionId}"
                    data-qid="{$questionId}">
                {$sendLabel}
            </button>
            <button type="button"
                    class="ai-btn ai-btn-finish ai-btn-finish-interview"
                    id="ai-finish-{$questionId}"
                    data-qid="{$questionId}"
                    style="display:none;">
                {$finishLabel}
            </button>
        </div>
    </div>

    <!--
        Hidden textarea — holds the plain-text transcript.
        Submitted with the survey form and stored by LimeSurvey as the answer.
        LimeSurvey uses the sgqa code directly as the form field name.
    -->
    <textarea
        name="{$sgqaCode}"
        id="answer-{$sgqaCode}"
        class="ai-interview-answer-field"
        style="display:none;"
        aria-hidden="true"
    >{$eAnswer}</textarea>

    <!-- Running token counter (hidden, used by JS) -->
    <input type="hidden" id="ai-tokens-used-{$questionId}" value="0" />

</div>
HTML;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Detect the active survey language from the session
     */
    private function getSessionLanguage(int $surveyId): string
    {
        $sessionKey = 'survey_' . $surveyId;
        if (isset($_SESSION[$sessionKey]['s_lang'])) {
            return (string) $_SESSION[$sessionKey]['s_lang'];
        }
        // Fall back to the application language
        return (string) Yii::app()->language;
    }

    /**
     * Return the default system prompt displayed in the question editor
     */
    private function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
You are a professional interviewer conducting a structured interview on behalf of a researcher.

Your goal is to explore the respondent's experiences and opinions on [TOPIC — replace this with your topic].

Guidelines:
- Begin by introducing yourself briefly and asking your first question.
- Ask 5–7 open-ended questions, one at a time. Wait for the respondent's answer before asking the next question.
- Follow up on interesting, unclear, or incomplete answers with probing questions (e.g. "Can you tell me more about that?").
- Be warm, professional, and neutral — do not express personal opinions or judgements.
- When you have gathered sufficient information on all your questions, thank the respondent warmly and explicitly instruct them: "Please press the Finish Interview button to save your responses."

Start the interview now by introducing yourself and asking your first question.
PROMPT;
    }

    /**
     * Send a JSON HTTP response and terminate execution
     */
    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        Yii::app()->end();
    }
}
