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
 *   - The afterRenderQuestion event replaces the standard textarea with the AI chat widget.
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
 * @version     1.4.0
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

        // Replace the rendered question HTML with the AI chat widget.
        // afterRenderQuestion fires after the Twig template has produced HTML,
        // including in question preview mode (admin).
        $this->subscribe('afterRenderQuestion');

        // Fallback: inject a script-based widget initialiser before rendering.
        // This handles cases where afterRenderQuestion does not fire or the HTML
        // replacement regex does not match (e.g. in some admin preview contexts).
        $this->subscribe('beforeQuestionRender');

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
    // QUESTION RENDERING — INJECT AI WIDGET INTO RENDERED HTML
    // =========================================================================

    /**
     * After the question HTML is rendered, check if this is an AIInterview question.
     * If so, replace the standard textarea with the AI chat widget HTML, and
     * register the CSS/JS assets.
     *
     * This approach is more robust than relying on the Twig template system because:
     * 1. It works regardless of whether the question theme Twig template is found
     * 2. It works in both live survey and admin question preview modes
     * 3. It works even if question_theme_name is not set correctly
     *
     * Detection: a question is an AI Interview question if it has the
     * ai_interview_prompt attribute set (non-empty), OR if its question_theme_name
     * is 'AIInterview'.
     */
    public function afterRenderQuestion()
    {
        $event = $this->getEvent();

        $questionId = (int) $event->get('qid');
        if ($questionId <= 0) {
            return;
        }

        // Load the question
        $oQuestion = Question::model()->findByPk($questionId);
        if (empty($oQuestion)) {
            return;
        }

        // Check if this is an AI Interview question.
        // Primary check: question_theme_name = 'AIInterview'
        // Fallback check: has ai_interview_prompt attribute set
        $isAIInterview = ($oQuestion->question_theme_name === 'AIInterview');

        if (!$isAIInterview) {
            // Check for the attribute as a fallback
            $promptAttr = QuestionAttribute::model()->findByAttributes([
                'qid'       => $questionId,
                'attribute' => 'ai_interview_prompt',
            ]);
            if (!empty($promptAttr) && !empty(trim($promptAttr->value))) {
                $isAIInterview = true;
            }
        }

        if (!$isAIInterview) {
            return;
        }

        // Load question attributes
        $prompt    = $this->getQuestionAttribute($questionId, 'ai_interview_prompt',    $this->getDefaultPrompt());
        $maxTokens = (int) $this->getQuestionAttribute($questionId, 'ai_interview_max_tokens', 6000);
        $mandatory = (string) $this->getQuestionAttribute($questionId, 'ai_interview_mandatory', '0');

        // Build the AJAX URL for the server-side proxy
        $ajaxUrl = Yii::app()->createUrl('plugins/direct', [
            'plugin'   => 'AIInterview',
            'function' => 'chat',
        ]);

        // Get the survey ID and language
        $surveyId = (int) $oQuestion->sid;
        $language = $this->getSessionLanguage($surveyId);

        // Build the SGQA code (field name for the answer)
        // In LimeSurvey, the SGQA code is: {surveyId}X{groupId}X{questionId}
        $sgqa = $surveyId . 'X' . $oQuestion->gid . 'X' . $questionId;

        // Get the existing answer value (for back-navigation).
        // We read it from the session rather than the DB to avoid errors in preview mode
        // (where the survey response table may not exist).
        $dispVal = '';
        $sessionKey = 'survey_' . $surveyId;
        if (isset($_SESSION[$sessionKey][$sgqa])) {
            $dispVal = htmlspecialchars((string) $_SESSION[$sessionKey][$sgqa], ENT_QUOTES, 'UTF-8');
        }

        // Register CSS and JS assets
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

        // Build the widget HTML
        $widgetHtml = $this->buildWidgetHtml(
            $sgqa,
            $surveyId,
            $ajaxUrl,
            $prompt,
            $maxTokens,
            $language,
            $mandatory,
            $dispVal
        );

        // Get the current rendered HTML
        $html = $event->get('html');

        if (!empty($html)) {
            // Replace the standard textarea (id="answer{sgqa}") with our widget.
            // The standard LimeSurvey long free text textarea has id="answer{sgqa}"
            // and name="{sgqa}". We replace the entire textarea element.
            $escapedSgqa = preg_quote($sgqa, '/');

            // Match the textarea element with this SGQA code
            $pattern = '/<textarea[^>]+(?:id=["\']answer' . $escapedSgqa . '["\']|name=["\']' . $escapedSgqa . '["\'])[^>]*>.*?<\/textarea>/si';

            if (preg_match($pattern, $html)) {
                $newHtml = preg_replace($pattern, $widgetHtml, $html);
                if ($newHtml !== null) {
                    $event->set('html', $newHtml);
                    return;
                }
            }

            // Fallback: if we couldn't find the textarea by SGQA, try to find any
            // textarea inside the question answer area and replace it
            $fallbackPattern = '/<textarea[^>]+class=["\'][^"\']*(?:ls-answers|answer)[^"\']*["\'][^>]*>.*?<\/textarea>/si';
            if (preg_match($fallbackPattern, $html)) {
                $newHtml = preg_replace($fallbackPattern, $widgetHtml, $html, 1);
                if ($newHtml !== null) {
                    $event->set('html', $newHtml);
                    return;
                }
            }

            // Last resort: append the widget to the HTML
            // (the hidden textarea in the widget will handle form submission)
            $event->set('html', $html . $widgetHtml);
        } else {
            // No existing HTML — set the widget as the entire HTML
            $event->set('html', $widgetHtml);
        }
    }

    /**
     * Fallback handler: fires before the Twig template renders.
     *
     * If afterRenderQuestion did not replace the HTML (e.g. in some admin preview
     * contexts), this method injects a <script> block that runs after DOM load and
     * transforms the standard textarea into the AI widget using JavaScript.
     *
     * The script checks if the widget div already exists (injected by afterRenderQuestion)
     * and skips if so, to avoid double-initialisation.
     */
    public function beforeQuestionRender()
    {
        $event = $this->getEvent();

        $questionId = (int) $event->get('qid');
        if ($questionId <= 0) {
            return;
        }

        $oQuestion = Question::model()->findByPk($questionId);
        if (empty($oQuestion)) {
            return;
        }

        // Check if this is an AI Interview question
        $isAIInterview = ($oQuestion->question_theme_name === 'AIInterview');
        if (!$isAIInterview) {
            $promptAttr = QuestionAttribute::model()->findByAttributes([
                'qid'       => $questionId,
                'attribute' => 'ai_interview_prompt',
            ]);
            if (!empty($promptAttr) && !empty(trim($promptAttr->value))) {
                $isAIInterview = true;
            }
        }

        if (!$isAIInterview) {
            return;
        }

        // Load question attributes
        $prompt    = $this->getQuestionAttribute($questionId, 'ai_interview_prompt',    $this->getDefaultPrompt());
        $maxTokens = (int) $this->getQuestionAttribute($questionId, 'ai_interview_max_tokens', 6000);
        $mandatory = (string) $this->getQuestionAttribute($questionId, 'ai_interview_mandatory', '0');

        $ajaxUrl  = Yii::app()->createUrl('plugins/direct', [
            'plugin'   => 'AIInterview',
            'function' => 'chat',
        ]);

        $surveyId = (int) $oQuestion->sid;
        $language = $this->getSessionLanguage($surveyId);

        // Use the sgqa from the event if available (more reliable than constructing it)
        $sgqa = (string) $event->get('sgqa');
        if (empty($sgqa)) {
            $sgqa = $surveyId . 'X' . $oQuestion->gid . 'X' . $questionId;
        }

        // Register CSS and JS assets
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

        // Build the widget HTML for injection via JS
        $widgetHtml = $this->buildWidgetHtml(
            $sgqa,
            $surveyId,
            $ajaxUrl,
            $prompt,
            $maxTokens,
            $language,
            $mandatory,
            ''
        );

        // Escape the widget HTML for embedding in a JS string
        $widgetHtmlJs = json_encode($widgetHtml);

        $eSgqa = json_encode($sgqa);

        // Inject a script that replaces the textarea with the widget after DOM load.
        // The script checks if the widget already exists (from afterRenderQuestion)
        // to avoid double-initialisation.
        $script = <<<JS
(function() {
    function aiInterviewInit() {
        var sgqa = {$eSgqa};
        // Skip if widget already injected by afterRenderQuestion
        if (document.getElementById('ai-interview-widget-' + sgqa)) {
            return;
        }
        // Find the standard textarea for this question
        var textarea = document.getElementById('answer' + sgqa);
        if (!textarea) {
            // Try by name attribute
            textarea = document.querySelector('textarea[name="' + sgqa + '"]');
        }
        if (textarea) {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = {$widgetHtmlJs};
            var widget = wrapper.firstElementChild;
            // Restore any existing answer value
            var answerField = widget.querySelector('.ai-interview-answer-field');
            if (answerField && textarea.value) {
                answerField.value = textarea.value;
            }
            textarea.parentNode.replaceChild(widget, textarea);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', aiInterviewInit);
    } else {
        aiInterviewInit();
    }
}());
JS;

        Yii::app()->clientScript->registerScript(
            'ai-interview-init-' . $questionId,
            $script,
            CClientScript::POS_END
        );
    }

    /**
     * Get a question attribute value, with a fallback default.
     */
    private function getQuestionAttribute(int $questionId, string $attribute, $default = '')
    {
        $attr = QuestionAttribute::model()->findByAttributes([
            'qid'       => $questionId,
            'attribute' => $attribute,
        ]);
        if (!empty($attr) && $attr->value !== null && $attr->value !== '') {
            return $attr->value;
        }
        return $default;
    }

    /**
     * Build the full HTML for the AI Interview widget.
     *
     * @param string $sgqa       SGQA field name
     * @param int    $surveyId   Survey ID
     * @param string $ajaxUrl    URL for the server-side OpenAI proxy
     * @param string $prompt     AI system prompt
     * @param int    $maxTokens  Maximum token budget
     * @param string $language   BCP-47 language code
     * @param string $mandatory  '1' if mandatory, '0' otherwise
     * @param string $dispVal    Previously saved answer (for back-navigation)
     * @return string            HTML for the widget
     */
    private function buildWidgetHtml(
        string $sgqa,
        int    $surveyId,
        string $ajaxUrl,
        string $prompt,
        int    $maxTokens,
        string $language,
        string $mandatory,
        string $dispVal
    ): string {
        $eSgqa      = htmlspecialchars($sgqa,      ENT_QUOTES, 'UTF-8');
        $eAjaxUrl   = htmlspecialchars($ajaxUrl,   ENT_QUOTES, 'UTF-8');
        $ePrompt    = htmlspecialchars($prompt,    ENT_QUOTES, 'UTF-8');
        $eLanguage  = htmlspecialchars($language,  ENT_QUOTES, 'UTF-8');
        $eMandatory = htmlspecialchars($mandatory, ENT_QUOTES, 'UTF-8');
        $eDispVal   = htmlspecialchars($dispVal,   ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="ai-interview-widget"
     id="ai-interview-widget-{$eSgqa}"
     data-sgqa="{$eSgqa}"
     data-survey-id="{$surveyId}"
     data-ajax-url="{$eAjaxUrl}"
     data-prompt="{$ePrompt}"
     data-max-tokens="{$maxTokens}"
     data-language="{$eLanguage}"
     data-mandatory="{$eMandatory}">

    <!-- Chat message display area -->
    <div class="ai-interview-messages"
         id="ai-messages-{$eSgqa}"
         role="log"
         aria-live="polite"
         aria-label="Interview conversation">
    </div>

    <!-- Typing indicator -->
    <div class="ai-interview-typing"
         id="ai-typing-{$eSgqa}"
         style="display:none;"
         aria-live="polite">
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-dot"></span>
        <span class="ai-typing-label">Interviewer is typing&hellip;</span>
    </div>

    <!-- Error banner -->
    <div class="ai-interview-error"
         id="ai-error-{$eSgqa}"
         style="display:none;"
         role="alert">
        <span class="ai-error-text">The AI service is currently unavailable. You may skip this question or try again later.</span>
        <button type="button"
                class="ai-btn ai-btn-secondary ai-btn-skip"
                data-sgqa="{$eSgqa}">
            Skip this question
        </button>
    </div>

    <!-- Token budget exhausted notice -->
    <div class="ai-interview-token-warning"
         id="ai-token-warning-{$eSgqa}"
         style="display:none;"
         role="status">
        The interview has reached its maximum length and has been automatically concluded.
    </div>

    <!-- User input area -->
    <div class="ai-interview-input-area" id="ai-input-area-{$eSgqa}">
        <textarea
            class="ai-interview-input"
            id="ai-input-{$eSgqa}"
            placeholder="Type your response here&hellip;"
            rows="3"
            aria-label="Type your response here&hellip;"
        ></textarea>
        <div class="ai-interview-actions">
            <button type="button"
                    class="ai-btn ai-btn-primary ai-btn-send"
                    id="ai-send-{$eSgqa}"
                    data-sgqa="{$eSgqa}">
                Send
            </button>
            <button type="button"
                    class="ai-btn ai-btn-finish ai-btn-finish-interview"
                    id="ai-finish-{$eSgqa}"
                    data-sgqa="{$eSgqa}"
                    style="display:none;">
                Finish Interview
            </button>
        </div>
    </div>

    <!--
        Hidden textarea — holds the plain-text transcript.
        Submitted with the survey form and stored by LimeSurvey as the answer.
        LimeSurvey uses the sgqa code directly as the form field name.
    -->
    <textarea
        name="{$eSgqa}"
        id="answer{$eSgqa}"
        class="ai-interview-answer-field"
        style="display:none;"
        aria-hidden="true"
    >{$eDispVal}</textarea>

    <!-- Running token counter (hidden, used by JS) -->
    <input type="hidden" id="ai-tokens-used-{$eSgqa}" value="0" />

</div>
HTML;
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

        if ($function === 'testchat') {
            $this->handleTestChatRequest();
            $event->set('success', true);
            return;
        }
    }

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
     * Test endpoint — verifies the plugin endpoint is reachable and the API key is set.
     * Accessible at: /index.php/plugins/direct?plugin=AIInterview&function=testchat
     * Does NOT call OpenAI — just returns configuration status.
     */
    private function handleTestChatRequest(): void
    {
        $apiKey = trim((string) $this->get('openai_api_key', null, null, ''));
        $model  = trim((string) $this->get('openai_model',   null, null, 'gpt-4o'));

        $isAdmin = false;
        try {
            $isAdmin = Permission::model()->hasGlobalPermission('surveys', 'read');
        } catch (Exception $e) {
            // ignore
        }

        $this->sendJsonResponse([
            'status'       => 'ok',
            'endpoint'     => 'AIInterview chat endpoint is reachable',
            'method'       => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'api_key_set'  => !empty($apiKey),
            'model'        => $model ?: 'gpt-4o',
            'is_admin'     => $isAdmin,
            'php_version'  => PHP_VERSION,
            'curl_enabled' => function_exists('curl_init'),
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
        // Accept both POST and GET (LimeSurvey may route differently in some versions)
        // For GET requests, parameters come from $_GET; for POST, from php://input
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'POST') {
            $rawBody = file_get_contents('php://input');
            $body    = json_decode($rawBody, true);
        } else {
            // Fallback: try to read from query string (for debugging)
            $this->sendJsonResponse(['error' => 'Method not allowed. Use POST.'], 405);
            return;
        }

        if (!is_array($body)) {
            // Try to parse as form data if JSON fails
            $rawBody = file_get_contents('php://input');
            Yii::log('AIInterview: Invalid JSON body. Raw: ' . substr($rawBody, 0, 200), CLogger::LEVEL_WARNING);
            $this->sendJsonResponse(['error' => 'Invalid JSON body. Received: ' . substr($rawBody, 0, 100)], 400);
            return;
        }

        $surveyId  = isset($body['surveyId'])  ? (int)    $body['surveyId']  : 0;
        $messages  = isset($body['messages'])  ? (array)  $body['messages']  : [];
        $maxTokens = isset($body['maxTokens']) ? (int)    $body['maxTokens'] : 6000;
        $language  = isset($body['language'])  ? (string) $body['language']  : 'en';

        // Validate required fields
        if (empty($messages)) {
            $this->sendJsonResponse(['error' => 'Missing required field: messages'], 400);
            return;
        }

        // Security: verify an active survey session exists for this survey,
        // OR that the requester is a logged-in LimeSurvey admin (for question preview).
        // This prevents the endpoint from being used by anonymous users outside of a survey.
        $isAdmin = false;
        try {
            $isAdmin = Permission::model()->hasGlobalPermission('surveys', 'read');
        } catch (Exception $e) {
            // Permission check failed — treat as non-admin
        }

        $hasSession = ($surveyId > 0 && isset($_SESSION['survey_' . $surveyId]));

        if (!$hasSession && !$isAdmin) {
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
