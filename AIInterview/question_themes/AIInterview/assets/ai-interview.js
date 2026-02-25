/**
 * AI Interview Widget — LimeSurvey Plugin
 *
 * Manages the chat UI for each AI Interview question on the page.
 * Communicates with the server-side OpenAI proxy endpoint.
 * The API key is NEVER present in this file or in the page HTML.
 *
 * @version 1.1.0
 */

(function () {
    'use strict';

    // =========================================================================
    // Initialise all widgets on the page
    // =========================================================================

    function initAllWidgets() {
        var widgets = document.querySelectorAll('.ai-interview-widget');
        widgets.forEach(function (widget) {
            initWidget(widget);
        });
    }

    // =========================================================================
    // Per-widget state and initialisation
    // =========================================================================

    function initWidget(widget) {
        // The widget uses the SGQA code (name) as the primary identifier
        var sgqa       = widget.dataset.sgqa;
        var ajaxUrl    = widget.dataset.ajaxUrl;
        var language   = widget.dataset.language || 'en';
        var mandatory  = widget.dataset.mandatory === '1';

        // Survey ID — read from the hidden answer field's form or from data attribute
        var surveyId   = widget.dataset.surveyId || getSurveyIdFromPage();

        // Prompt and token budget — read from data attributes (set by PHP/Twig)
        // These are injected by the plugin's beforeQuestionRender or by the Twig template
        var prompt     = widget.dataset.prompt || '';
        var maxTokens  = parseInt(widget.dataset.maxTokens, 10) || 6000;

        // DOM references — all keyed by SGQA code
        var messagesEl    = document.getElementById('ai-messages-'      + sgqa);
        var typingEl      = document.getElementById('ai-typing-'        + sgqa);
        var errorEl       = document.getElementById('ai-error-'         + sgqa);
        var tokenWarnEl   = document.getElementById('ai-token-warning-' + sgqa);
        var inputAreaEl   = document.getElementById('ai-input-area-'    + sgqa);
        var inputEl       = document.getElementById('ai-input-'         + sgqa);
        var sendBtn       = document.getElementById('ai-send-'          + sgqa);
        var finishBtn     = document.getElementById('ai-finish-'        + sgqa);
        var answerField   = document.getElementById('answer'            + sgqa);
        var tokensUsedEl  = document.getElementById('ai-tokens-used-'   + sgqa);

        // Validate required DOM elements
        if (!messagesEl || !inputEl || !sendBtn || !answerField) {
            console.warn('AIInterview: Missing required DOM elements for widget', sgqa);
            return;
        }

        // If no prompt is set on the widget, try to read it from the answer field's
        // data attributes (injected by the PHP plugin via beforeQuestionRender)
        if (!prompt && answerField) {
            prompt = answerField.dataset.prompt || '';
        }

        // Conversation history sent to the API (includes system prompt)
        var conversationHistory = [];
        if (prompt) {
            conversationHistory.push({ role: 'system', content: prompt });
        }

        // Plain-text transcript lines for the answer field
        var transcriptLines = [];

        // Running token count
        var tokensUsed = 0;

        // Whether the interview has been finished
        var finished = false;

        // -----------------------------------------------------------------------
        // Check for existing answer (resume after back-navigation)
        // -----------------------------------------------------------------------
        if (answerField && answerField.value.trim()) {
            restoreFromTranscript(answerField.value.trim());
            return; // Don't re-start the interview
        }

        // -----------------------------------------------------------------------
        // Start the interview — fetch the AI's opening message
        // -----------------------------------------------------------------------
        if (prompt) {
            setLoading(true);
            callAI(function (reply, newTokens, finishReason) {
                setLoading(false);
                tokensUsed += newTokens;
                if (tokensUsedEl) tokensUsedEl.value = tokensUsed;

                appendMessage('assistant', reply);
                conversationHistory.push({ role: 'assistant', content: reply });
                transcriptLines.push('Interviewer: ' + reply);
                updateAnswerField();

                // Show finish button after the first AI message
                if (finishBtn) finishBtn.style.display = 'inline-block';

                checkTokenBudget();
            }, function (errMsg) {
                setLoading(false);
                showError(errMsg);
            });
        } else {
            // No prompt configured — show a configuration error
            showError('AI Interview is not configured. Please contact the survey administrator.');
        }

        // -----------------------------------------------------------------------
        // Send button click
        // -----------------------------------------------------------------------
        sendBtn.addEventListener('click', function () {
            sendUserMessage();
        });

        // -----------------------------------------------------------------------
        // Enter key in textarea (Shift+Enter = newline, Enter = send)
        // -----------------------------------------------------------------------
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendUserMessage();
            }
        });

        // -----------------------------------------------------------------------
        // Finish Interview button
        // -----------------------------------------------------------------------
        if (finishBtn) {
            finishBtn.addEventListener('click', function () {
                finishInterview();
            });
        }

        // -----------------------------------------------------------------------
        // Skip button (inside error banner)
        // -----------------------------------------------------------------------
        var skipBtns = widget.querySelectorAll('.ai-btn-skip');
        skipBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                skipInterview();
            });
        });

        // -----------------------------------------------------------------------
        // Prevent form submission if mandatory and not started/finished
        // -----------------------------------------------------------------------
        if (mandatory) {
            var form = widget.closest('form');
            if (form) {
                form.addEventListener('submit', function (e) {
                    if (!finished && transcriptLines.length < 2) {
                        e.preventDefault();
                        e.stopPropagation();
                        inputEl.focus();
                        inputEl.style.borderColor = '#dc2626';
                        setTimeout(function () {
                            inputEl.style.borderColor = '';
                        }, 2000);
                    }
                }, true);
            }
        }

        // =====================================================================
        // Core functions
        // =====================================================================

        function sendUserMessage() {
            if (finished) return;

            var text = inputEl.value.trim();
            if (!text) return;

            // Append user message to UI
            appendMessage('user', text);
            conversationHistory.push({ role: 'user', content: text });
            transcriptLines.push('User: ' + text);
            updateAnswerField();

            inputEl.value = '';
            setLoading(true);

            // Get AI response
            callAI(function (reply, newTokens, finishReason) {
                setLoading(false);
                tokensUsed += newTokens;
                if (tokensUsedEl) tokensUsedEl.value = tokensUsed;

                appendMessage('assistant', reply);
                conversationHistory.push({ role: 'assistant', content: reply });
                transcriptLines.push('Interviewer: ' + reply);
                updateAnswerField();

                checkTokenBudget();
            }, function (errMsg) {
                setLoading(false);
                showError(errMsg);
            });
        }

        function callAI(onSuccess, onError) {
            var sid = surveyId ? parseInt(surveyId, 10) : 0;
            var payload = JSON.stringify({
                surveyId:  sid,
                messages:  conversationHistory,
                maxTokens: maxTokens,
                language:  language
            });

            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 90000; // 90 second timeout

            xhr.onload = function () {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) {
                            onError(data.error);
                        } else {
                            onSuccess(data.reply, data.tokensUsed || 0, data.finishReason || 'stop');
                        }
                    } catch (e) {
                        onError('Unexpected response from server.');
                    }
                } else {
                    try {
                        var errData = JSON.parse(xhr.responseText);
                        onError(errData.error || 'Server error (' + xhr.status + ')');
                    } catch (e) {
                        onError('Server error (' + xhr.status + '). The AI service may be unavailable.');
                    }
                }
            };

            xhr.onerror = function () {
                onError('Network error. Please check your connection and try again.');
            };

            xhr.ontimeout = function () {
                onError('The request timed out. The AI service may be slow or unavailable.');
            };

            xhr.send(payload);
        }

        function checkTokenBudget() {
            if (tokensUsed >= maxTokens) {
                // Auto-finish when token budget is exhausted
                if (tokenWarnEl) tokenWarnEl.style.display = 'block';
                finishInterview(true);
            }
        }

        function finishInterview(auto) {
            if (finished) return;
            finished = true;

            // Disable input
            inputEl.disabled = true;
            sendBtn.disabled = true;
            if (finishBtn) finishBtn.style.display = 'none';

            // Add a finished notice
            var notice = document.createElement('div');
            notice.className = 'ai-interview-finished-notice';
            notice.textContent = auto
                ? 'The interview has been automatically concluded.'
                : 'Interview complete. Thank you for your responses.';
            if (inputAreaEl) {
                inputAreaEl.parentNode.insertBefore(notice, inputAreaEl);
                inputAreaEl.style.display = 'none';
            }

            widget.classList.add('ai-interview-finished');

            // Append a separator to the transcript
            transcriptLines.push('');
            transcriptLines.push('--- Interview concluded ---');
            updateAnswerField();
        }

        function skipInterview() {
            finished = true;
            if (answerField) answerField.value = '[Interview skipped — AI service unavailable]';
            inputEl.disabled = true;
            sendBtn.disabled = true;
            if (finishBtn) finishBtn.style.display = 'none';
            if (errorEl) errorEl.style.display = 'none';
            if (inputAreaEl) inputAreaEl.style.display = 'none';
            widget.classList.add('ai-interview-finished');

            var notice = document.createElement('div');
            notice.className = 'ai-interview-finished-notice';
            notice.textContent = 'This question has been skipped.';
            if (inputAreaEl) {
                inputAreaEl.parentNode.insertBefore(notice, inputAreaEl);
            }
        }

        // =====================================================================
        // UI helpers
        // =====================================================================

        function appendMessage(role, text) {
            var wrapper = document.createElement('div');
            wrapper.className = 'ai-message ai-message-' + role;

            var label = document.createElement('div');
            label.className = 'ai-message-label';
            label.textContent = role === 'assistant' ? 'Interviewer' : 'You';

            var bubble = document.createElement('div');
            bubble.className = 'ai-message-bubble';
            bubble.textContent = text; // textContent prevents XSS

            wrapper.appendChild(label);
            wrapper.appendChild(bubble);
            messagesEl.appendChild(wrapper);

            // Scroll to bottom
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function setLoading(isLoading) {
            if (typingEl) typingEl.style.display = isLoading ? 'flex' : 'none';
            sendBtn.disabled  = isLoading;
            inputEl.disabled  = isLoading;
            if (isLoading) {
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }
        }

        function showError(msg) {
            if (errorEl) {
                var errText = errorEl.querySelector('.ai-error-text');
                if (errText) errText.textContent = msg;
                errorEl.style.display = 'flex';
            }
        }

        function updateAnswerField() {
            if (answerField) {
                answerField.value = transcriptLines.join('\n');
            }
        }

        // =====================================================================
        // Restore from existing transcript (back-navigation)
        // =====================================================================

        function restoreFromTranscript(transcript) {
            finished = true;
            widget.classList.add('ai-interview-finished');

            var lines = transcript.split('\n');
            lines.forEach(function (line) {
                if (line.startsWith('Interviewer: ')) {
                    appendMessage('assistant', line.replace('Interviewer: ', ''));
                } else if (line.startsWith('User: ')) {
                    appendMessage('user', line.replace('User: ', ''));
                }
            });

            inputEl.disabled = true;
            sendBtn.disabled = true;
            if (finishBtn) finishBtn.style.display = 'none';
            if (inputAreaEl) inputAreaEl.style.display = 'none';

            var notice = document.createElement('div');
            notice.className = 'ai-interview-finished-notice';
            notice.textContent = 'Interview complete. Your responses have been recorded.';
            if (inputAreaEl) {
                inputAreaEl.parentNode.insertBefore(notice, inputAreaEl);
            }
        }
    }

    // =========================================================================
    // Helper: extract survey ID from the page
    // =========================================================================

    function getSurveyIdFromPage() {
        // Try to get survey ID from the form action URL or a hidden field
        var form = document.querySelector('form[action*="survey"]');
        if (form) {
            var match = form.action.match(/\/(\d+)\//);
            if (match) return match[1];
        }
        // Try hidden input
        var sidInput = document.querySelector('input[name="sid"]');
        if (sidInput) return sidInput.value;
        return '0';
    }

    // =========================================================================
    // Bootstrap
    // =========================================================================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllWidgets);
    } else {
        initAllWidgets();
    }

}());
