<?php
/**
 * AIInterview - LimeSurvey Plugin
 *
 * Adds a custom "AI Interview" question type to LimeSurvey 6.x.
 * Respondents engage in a conversational AI-driven interview powered by OpenAI.
 * The full conversation is saved as a plain-text transcript.
 *
 * How it works (LimeSurvey 6.x):
 *   - The plugin registers a question theme that extends the Long Free Text (T) type.
 *   - On activation, the theme is copied to upload/themes/question/ and imported
 *     into the question_themes database table so it appears in the question type selector.
 *   - The theme's Twig template replaces the standard textarea with the AI chat widget.
 *   - The plugin provides a server-side AJAX proxy for OpenAI (API key never exposed).
 *
 * Installation:
 *   1. Upload the AIInterview folder to <limesurvey>/plugins/
 *   2. Activate the plugin in Admin → Configuration → Plugin Manager
 *   3. Enter your OpenAI API key in the plugin settings
 *   4. Create a new question and select "AI Interview" as the question type
 *
 * @author      AI Interview Plugin
 * @license     GPL v2
 * @version     1.1.0
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
        // Plugin lifecycle hooks — install/uninstall the question theme
        $this->subscribe('beforeActivate');
        $this->subscribe('beforeDeactivate');

        // Server-side AJAX proxy endpoint (chat + debug)
        $this->subscribe('newDirectRequest');

        // Per-question attribute registration (shown in question editor Advanced tab)
        $this->subscribe('newQuestionAttributes');

        // Inject configuration data attributes into the rendered widget HTML.
        // afterRenderQuestion fires after the Twig template has produced HTML,
        // so the widget div exists and we can inject data attributes via regex.
        // This also fires in question preview mode (admin), unlike beforeQuestionRender.
        $this->subscribe('afterRenderQuestion');

        // Admin notification if theme is not properly registered
        $this->subscribe('newAdminMenu');
    }

    // =========================================================================
    // PLUGIN LIFECYCLE — QUESTION THEME INSTALLATION
    // =========================================================================

    /**
     * On plugin activation:
     *   1. Copy the question theme to upload/themes/question/AIInterview/
     *   2. Import the theme into the question_themes database table
     *
     * This makes "AI Interview" appear in the question type selector.
     */
    public function beforeActivate()
    {
        $this->installQuestionTheme();
    }

    /**
     * On plugin deactivation: remove the question theme from the database.
     * (Files in upload/ are left in place so existing surveys still work.)
     */
    public function beforeDeactivate()
    {
        $this->uninstallQuestionTheme();
    }

    /**
     * Copy the question theme files to upload/themes/question/AIInterview/
     * and register the theme in the database.
     *
     * We directly set all QuestionTheme attributes rather than relying on
     * importManifest(), which has fragile path-comparison logic that can
     * silently fail when the theme directory is outside the expected paths.
     */
    private function installQuestionTheme(): void
    {
        $rootDir   = Yii::app()->getConfig('rootdir');

        // Get the user question theme root directory exactly as LimeSurvey stores it.
        // We must NOT normalise this with realpath() because QuestionTheme::getQuestionMetaData()
        // uses a substr() prefix comparison against this exact value to determine coreTheme.
        // If we normalise and LimeSurvey doesn't, the comparison fails and the theme is
        // silently skipped by findAllQuestionMetaDataForSelector().
        $uploadDir = Yii::app()->getConfig('userquestionthemerootdir');

        // Resolve relative paths to absolute (needed for file operations)
        if (!is_dir($uploadDir)) {
            $uploadDir = $rootDir . DIRECTORY_SEPARATOR . $uploadDir;
        }

        $sourceDir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'question_themes' . DIRECTORY_SEPARATOR . 'AIInterview';
        $destDir   = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . 'AIInterview';

        // Copy theme files to upload/themes/question/AIInterview/
        $this->copyDirectory($sourceDir, $destDir);

        // Verify the config.xml was copied successfully
        if (!is_file($destDir . DIRECTORY_SEPARATOR . 'config.xml')) {
            Yii::log(
                'AIInterview: ERROR — config.xml not found at ' . $destDir
                . ' after copy. Theme will not appear in the question type selector.',
                CLogger::LEVEL_ERROR
            );
        }

        // Delete any existing DB record so we always re-register with the correct path.
        // This handles the case where the plugin was previously installed with a different
        // path (e.g., before a LimeSurvey migration or directory change).
        $existing = QuestionTheme::model()->findByAttributes(['name' => 'AIInterview']);
        if (!empty($existing)) {
            $existing->delete();
        }

        // We use a direct DB insert rather than importManifest() because:
        // 1. importManifest() sets extends='T' (since a base T theme exists), which makes
        //    the theme appear only as a hidden variant of T in the selector, not as a
        //    standalone entry.
        // 2. We want extends='' so the theme appears as its own entry in the selector grid.
        //    With extends='', LimeSurvey falls back to the core T theme for any templates
        //    our theme does not provide (question wrapper, help text, etc.).
        $extends = '';

        // Build the settings JSON (mirrors what getMetaDataArray produces)
        $settings = json_encode([
            'subquestions'     => 0,
            'other'            => false,
            'answerscales'     => 0,
            'hasdefaultvalues' => 0,
            'assessable'       => 0,
            'class'            => 'ai-interview',
        ]);

        $oTheme = new QuestionTheme();
        $oTheme->setAttributes([
            'name'          => 'AIInterview',
            'visible'       => 'Y',
            'xml_path'      => $destDir,
            'image_path'    => '',
            'title'         => 'AI Interview',
            'creation_date' => date('Y-m-d H:i:s'),
            'author'        => 'LimeSurvey AI Interview Plugin',
            'author_email'  => '',
            'author_url'    => '',
            'copyright'     => '',
            'license'       => 'GPL v2',
            'version'       => '1.0.0',
            'api_version'   => '1',
            'description'   => 'An AI-powered conversational interview question type.',
            'last_update'   => date('Y-m-d H:i:s'),
            'owner_id'      => 1,
            'theme_type'    => 'question_theme',
            'question_type' => 'T',
            'core_theme'    => 0,
            'extends'       => $extends,
            'group'         => 'Text questions',
            'settings'      => $settings,
        ], false);

        if (!$oTheme->save()) {
            Yii::log(
                'AIInterview: Failed to register question theme: ' . json_encode($oTheme->errors),
                CLogger::LEVEL_WARNING
            );
        } else {
            Yii::log('AIInterview: Question theme registered via direct DB insert', CLogger::LEVEL_INFO);
        }
    }

    /**
     * Remove the question theme from the database.
     */
    private function uninstallQuestionTheme(): void
    {
        $oTheme = QuestionTheme::model()->findByAttributes(['name' => 'AIInterview']);
        if (!empty($oTheme)) {
            try {
                QuestionTheme::uninstall($oTheme);
            } catch (Exception $e) {
                Yii::log('AIInterview: Failed to uninstall question theme: ' . $e->getMessage(), CLogger::LEVEL_WARNING);
            }
        }
    }

    /**
     * Add a "Reinstall AI Interview Theme" link to the admin menu.
     * This allows admins to force re-registration of the question theme
     * without deactivating/reactivating the plugin.
     */
    public function newAdminMenu()
    {
        $event = $this->getEvent();

        // Check if the theme is properly registered
        $oTheme = QuestionTheme::model()->findByAttributes(['name' => 'AIInterview']);
        $xmlOk  = !empty($oTheme) && is_file($oTheme->xml_path . DIRECTORY_SEPARATOR . 'config.xml');

        if (!$xmlOk) {
            // Theme is not properly registered — add a warning menu item
            $menuItems = $event->get('menuItems') ?? [];
            $menuItems[] = [
                'label' => 'AIInterview: Theme not registered — click to reinstall',
                'href'  => Yii::app()->createUrl(
                    'plugins/direct',
                    ['plugin' => 'AIInterview', 'function' => 'reinstall']
                ),
                'class' => 'warning',
            ];
            $event->set('menuItems', $menuItems);
        }
    }

    /**
     * Recursively copy a directory.
     */
    private function copyDirectory(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $dir = opendir($src);
        if ($dir === false) return;
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $srcPath = $src . DIRECTORY_SEPARATOR . $file;
            $dstPath = $dst . DIRECTORY_SEPARATOR . $file;
            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }

    // =========================================================================
    // QUESTION ATTRIBUTE REGISTRATION
    // =========================================================================

    /**
     * Register per-question attributes (shown in the question editor under "Advanced" tab).
     * These attributes are available for questions using the AIInterview theme.
     */
    public function newQuestionAttributes()
    {
        $event = $this->getEvent();

        $questionAttributes = [
            'ai_interview_prompt' => [
                'types'    => 'T',
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
                'types'    => 'T',
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
                'types'    => 'T',
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
    // QUESTION RENDERING — INJECT CONFIGURATION DATA ATTRIBUTES
    // =========================================================================

    /**
     * After the Twig template renders the AI Interview widget, register the
     * CSS/JS assets so they are loaded on the page.
     *
     * The data attributes (prompt, maxTokens, surveyId, ajaxUrl, language,
     * mandatory) are now output directly by the Twig template using the
     * question_attributes variable, so they are present in both live survey
     * and question preview (admin) modes.
     *
     * This event fires after the Twig template has produced HTML, so it is
     * the correct place to register assets. It also fires in preview mode.
     *
     * This is called for ALL questions; we only act on questions that use
     * the AIInterview question theme.
     */
    public function afterRenderQuestion()
    {
        $event = $this->getEvent();

        $questionId = (int) $event->get('qid');

        // Check if this question uses the AIInterview theme
        $oQuestion = Question::model()->findByPk($questionId);
        if (empty($oQuestion) || $oQuestion->question_theme_name !== 'AIInterview') {
            return;
        }

        // Register CSS and JS assets from the plugin's assets/ directory.
        // These are also declared in config.xml (for the question theme loader),
        // but registering them here ensures they load even if the theme loader
        // does not pick them up (e.g. in some preview contexts).
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

        if ($function === 'chat') {
            $this->handleChatRequest();
            $event->set('success', true);
            return;
        }

        if ($function === 'reinstall') {
            $this->handleReinstallRequest();
            $event->set('success', true);
            return;
        }

        if ($function === 'debug') {
            $this->handleDebugRequest();
            $event->set('success', true);
            return;
        }
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
    /**
     * Handle a reinstall request from an admin.
     * Accessible at: /index.php/plugins/direct?plugin=AIInterview&function=reinstall
     * Requires admin login (LimeSurvey checks this for direct requests).
     */
    private function handleReinstallRequest(): void
    {
        // Only allow GET requests from admins
        if (!Permission::model()->hasGlobalPermission('superadmin', 'read')) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }

        $this->installQuestionTheme();

        $oTheme = QuestionTheme::model()->findByAttributes(['name' => 'AIInterview']);
        $xmlOk  = !empty($oTheme) && is_file($oTheme->xml_path . DIRECTORY_SEPARATOR . 'config.xml');

        $this->sendJsonResponse([
            'success'    => true,
            'registered' => !empty($oTheme),
            'xml_ok'     => $xmlOk,
            'xml_path'   => !empty($oTheme) ? $oTheme->xml_path : null,
            'extends'    => !empty($oTheme) ? $oTheme->extends  : null,
            'message'    => $xmlOk
                ? 'Theme reinstalled successfully. Refresh the question type selector.'
                : 'Theme registered in DB but config.xml not found at xml_path. Check file permissions.',
        ]);
    }

    /**
     * Return diagnostic information about the theme registration.
     * Accessible at: /index.php/plugins/direct?plugin=AIInterview&function=debug
     */
    private function handleDebugRequest(): void
    {
        if (!Permission::model()->hasGlobalPermission('superadmin', 'read')) {
            $this->sendJsonResponse(['error' => 'Unauthorized'], 403);
            return;
        }

        $rootDir   = Yii::app()->getConfig('rootdir');
        $uploadDir = Yii::app()->getConfig('userquestionthemerootdir');

        if (!is_dir($uploadDir)) {
            $uploadDir = $rootDir . DIRECTORY_SEPARATOR . $uploadDir;
        }

        $destDir = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . 'AIInterview';
        $oTheme  = QuestionTheme::model()->findByAttributes(['name' => 'AIInterview']);

        $allThemes = QuestionTheme::model()->findAll(['condition' => "visible='Y'"]);
        $themeList = [];
        foreach ($allThemes as $t) {
            $themeList[] = [
                'name'          => $t->name,
                'question_type' => $t->question_type,
                'extends'       => $t->extends,
                'xml_path'      => $t->xml_path,
                'xml_exists'    => is_file($t->xml_path . DIRECTORY_SEPARATOR . 'config.xml'),
            ];
        }

        $this->sendJsonResponse([
            'userquestionthemerootdir' => Yii::app()->getConfig('userquestionthemerootdir'),
            'resolved_uploadDir'       => $uploadDir,
            'expected_destDir'         => $destDir,
            'destDir_exists'           => is_dir($destDir),
            'config_xml_exists'        => is_file($destDir . DIRECTORY_SEPARATOR . 'config.xml'),
            'db_record'                => !empty($oTheme) ? [
                'id'            => $oTheme->id,
                'name'          => $oTheme->name,
                'xml_path'      => $oTheme->xml_path,
                'extends'       => $oTheme->extends,
                'question_type' => $oTheme->question_type,
                'visible'       => $oTheme->visible,
                'core_theme'    => $oTheme->core_theme,
                'xml_exists'    => is_file($oTheme->xml_path . DIRECTORY_SEPARATOR . 'config.xml'),
            ] : null,
            'all_visible_themes'       => $themeList,
        ]);
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
    // HELPERS
    // =========================================================================

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
