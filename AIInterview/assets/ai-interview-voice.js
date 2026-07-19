/**
 * AI Interview — Voice widget (M1)
 * Speak answers; Allie asks questions on screen.
 */
(function () {
    'use strict';

    var STRINGS = {
        en: {
            preparing: 'Preparing your interview…',
            thinking: 'Allie is thinking…',
            speakPrompt: 'Tap "Start speaking", then tap "Done" when finished.',
            listening: 'Listening… speak now.',
            processing: 'Processing your answer…',
            transcribing: 'Transcribing…',
            yourTurn: 'Your turn — tap Start speaking.',
            finish: 'Finish interview',
            startSpeak: 'Start speaking',
            doneSpeak: 'Done speaking',
            micDenied: 'Microphone access denied.',
            noSpeech: 'No speech detected. Please try again.',
            errorGeneric: 'Something went wrong. Please try again.',
            complete: 'Interview complete. Thank you.',
            autoComplete: 'Interview automatically concluded.',
            you: 'You',
            allie: 'Allie'
        },
        pl: {
            preparing: 'Przygotowuję wywiad…',
            thinking: 'Allie analizuje…',
            speakPrompt: 'Kliknij „Zacznij mówić”, a po odpowiedzi „Gotowe”.',
            listening: 'Słucham… mów teraz.',
            processing: 'Przetwarzam odpowiedź…',
            transcribing: 'Transkrybuję…',
            yourTurn: 'Twoja kolej — kliknij Zacznij mówić.',
            finish: 'Zakończ wywiad',
            startSpeak: 'Zacznij mówić',
            doneSpeak: 'Gotowe',
            micDenied: 'Brak dostępu do mikrofonu.',
            noSpeech: 'Nie wykryto mowy. Spróbuj ponownie.',
            errorGeneric: 'Coś poszło nie tak. Spróbuj ponownie.',
            complete: 'Wywiad zakończony. Dziękuję.',
            autoComplete: 'Wywiad został automatycznie zakończony.',
            you: 'Ty',
            allie: 'Allie'
        }
    };

    function t(lang, key) {
        var code = (lang || 'en').toLowerCase().slice(0, 2);
        var pack = STRINGS[code] || STRINGS.en;
        return pack[key] || STRINGS.en[key] || key;
    }

    function initVoiceWidget(widget) {
        var sgqa = widget.dataset.sgqa;
        var chatUrl = widget.dataset.ajaxUrl;
        var transcribeUrl = widget.dataset.transcribeUrl;
        var language = widget.dataset.language || 'en';
        var mandatory = widget.dataset.mandatory === '1';
        var surveyId = widget.dataset.surveyId || getSurveyIdFromPage();
        var prompt = widget.dataset.prompt || '';
        var maxTokens = parseInt(widget.dataset.maxTokens, 10) || 6000;
        var submitPromptEnabled = widget.dataset.submitPrompt === '1';

        var avatarEl = document.getElementById('ai-avatar-' + sgqa);
        var questionEl = document.getElementById('ai-question-' + sgqa);
        var statusEl = document.getElementById('ai-voice-status-' + sgqa);
        var meterEl = document.getElementById('ai-voice-meter-' + sgqa);
        var transcriptEl = document.getElementById('ai-voice-transcript-' + sgqa);
        var errorEl = document.getElementById('ai-error-' + sgqa);
        var tokenWarnEl = document.getElementById('ai-token-warning-' + sgqa);
        var speakBtn = document.getElementById('ai-speak-' + sgqa);
        var stopSpeakBtn = document.getElementById('ai-stop-speak-' + sgqa);
        var finishBtn = document.getElementById('ai-finish-' + sgqa);
        var answerField = document.getElementById('answer' + sgqa);
        var tokensUsedEl = document.getElementById('ai-tokens-used-' + sgqa);
        var submitModalEl = document.getElementById('ai-submit-modal-' + sgqa);
        var submitSurveyBtn = document.getElementById('ai-submit-survey-' + sgqa);
        var backSurveyBtn = document.getElementById('ai-back-survey-' + sgqa);

        var avatars = {
            idle: widget.dataset.avatarIdle || '',
            listening: widget.dataset.avatarListening || '',
            speaking: widget.dataset.avatarSpeaking || '',
            thinking: widget.dataset.avatarThinking || ''
        };

        if (!questionEl || !speakBtn || !answerField || !window.AIInterviewAudio) {
            console.warn('AIInterview voice: missing DOM or AIInterviewAudio');
            return;
        }

        var conversationHistory = [];
        if (prompt) {
            conversationHistory.push({ role: 'system', content: prompt });
        }

        var transcriptLines = [];
        var tokensUsed = 0;
        var finished = false;
        var mediaRecorder = null;
        var mediaStream = null;
        var audioChunks = [];
        var meterControl = null;
        var recorderMimeType = '';

        setAvatar('thinking');
        setStatus(t(language, 'preparing'));
        questionEl.textContent = '…';

        if (answerField && !answerField.value.trim()) {
            answerField.value = '[AI Interview in progress]';
        }

        if (answerField && answerField.value.trim()
                && answerField.value.trim() !== '[AI Interview in progress]') {
            restoreFromTranscript(answerField.value.trim());
            return;
        }

        if (prompt) {
            callChat(function (reply, newTokens) {
                tokensUsed += newTokens;
                if (tokensUsedEl) tokensUsedEl.value = tokensUsed;
                showAssistantMessage(reply);
                setAvatar('speaking');
                setStatus(t(language, 'speakPrompt'));
                if (finishBtn) finishBtn.style.display = 'inline-block';
                enableSpeakControls(true);
                checkTokenBudget();
            }, function (errMsg) {
                showError(errMsg);
                enableSpeakControls(false);
            });
        } else {
            showError('AI Interview is not configured. Please contact the survey administrator.');
        }

        speakBtn.addEventListener('click', startRecording);
        stopSpeakBtn.addEventListener('click', stopRecording);
        if (finishBtn) {
            finishBtn.addEventListener('click', function () { finishInterview(false); });
        }

        if (submitSurveyBtn) {
            submitSurveyBtn.addEventListener('click', function () {
                hideSubmitModal();
                triggerSurveySubmit(widget);
            });
        }

        if (backSurveyBtn) {
            backSurveyBtn.addEventListener('click', function () {
                hideSubmitModal();
                focusSurveySubmitButton(widget);
            });
        }

        var retryBtn = document.getElementById('ai-retry-' + sgqa);
        if (retryBtn) {
            retryBtn.addEventListener('click', function () {
                if (errorEl) errorEl.style.display = 'none';
                setAvatar('thinking');
                setStatus(t(language, 'preparing'));
                callChat(function (reply, newTokens) {
                    tokensUsed += newTokens;
                    showAssistantMessage(reply);
                    setAvatar('speaking');
                    setStatus(t(language, 'speakPrompt'));
                    enableSpeakControls(true);
                }, showError);
            });
        }

        widget.querySelectorAll('.ai-btn-skip').forEach(function (btn) {
            btn.addEventListener('click', skipInterview);
        });

        if (mandatory) {
            var form = widget.closest('form');
            if (form) {
                form.addEventListener('submit', function (e) {
                    if (!finished && transcriptLines.length < 2) {
                        e.preventDefault();
                        e.stopPropagation();
                        setStatus(t(language, 'speakPrompt'));
                    }
                }, true);
            }
        }

        function setAvatar(state) {
            if (!avatarEl) return;
            // Use the idle pose while Allie is presenting questions (speaking.png looks odd)
            var visualState = (state === 'speaking') ? 'idle' : state;
            var url = avatars[visualState] || avatars.idle || avatars.thinking;
            if (url) avatarEl.src = url;
            avatarEl.alt = 'Allie — ' + visualState;
        }

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text;
        }

        function enableSpeakControls(enabled) {
            if (finished) {
                speakBtn.disabled = true;
                stopSpeakBtn.disabled = true;
                return;
            }
            speakBtn.disabled = !enabled;
            stopSpeakBtn.disabled = true;
        }

        function showAssistantMessage(text) {
            questionEl.textContent = text;
            appendTranscript('assistant', text);
            conversationHistory.push({ role: 'assistant', content: text });
            transcriptLines.push('Interviewer: ' + text);
            updateAnswerField();
        }

        function appendTranscript(role, text) {
            if (!transcriptEl) return;
            var line = document.createElement('p');
            line.className = 'ai-voice-transcript-line';
            var label = role === 'assistant' ? t(language, 'allie') : t(language, 'you');
            line.innerHTML = '<strong>' + label + ':</strong> ' + escapeHtml(text);
            transcriptEl.appendChild(line);
            transcriptEl.scrollTop = transcriptEl.scrollHeight;
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function startRecording() {
            if (finished || !navigator.mediaDevices) return;

            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(function (stream) {
                    mediaStream = stream;
                    audioChunks = [];
                    recorderMimeType = window.AIInterviewAudio.pickRecorderMimeType();
                    var options = recorderMimeType ? { mimeType: recorderMimeType } : undefined;
                    mediaRecorder = new MediaRecorder(stream, options);

                    mediaRecorder.ondataavailable = function (e) {
                        if (e.data && e.data.size > 0) audioChunks.push(e.data);
                    };

                    mediaRecorder.onstop = function () {
                        if (mediaStream) {
                            mediaStream.getTracks().forEach(function (track) { track.stop(); });
                            mediaStream = null;
                        }
                        if (meterControl) {
                            meterControl.stop();
                            meterControl = null;
                        }

                        var blobType = recorderMimeType || 'audio/webm';
                        var recorded = new Blob(audioChunks, { type: blobType.split(';')[0] });
                        processRecording(recorded);
                    };

                    mediaRecorder.start(250);
                    meterControl = window.AIInterviewAudio.startLevelMeter(stream, meterEl);
                    setAvatar('listening');
                    setStatus(t(language, 'listening'));
                    speakBtn.disabled = true;
                    speakBtn.classList.add('is-recording');
                    stopSpeakBtn.disabled = false;
                })
                .catch(function () {
                    showError(t(language, 'micDenied'));
                });
        }

        function stopRecording() {
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                if (typeof mediaRecorder.requestData === 'function') {
                    mediaRecorder.requestData();
                }
                mediaRecorder.stop();
            }
            speakBtn.classList.remove('is-recording');
            stopSpeakBtn.disabled = true;
            setAvatar('thinking');
            setStatus(t(language, 'transcribing'));
        }

        function processRecording(recordedBlob) {
            if (!recordedBlob || recordedBlob.size === 0) {
                showError(t(language, 'noSpeech'));
                enableSpeakControls(true);
                setAvatar('speaking');
                setStatus(t(language, 'yourTurn'));
                return;
            }

            window.AIInterviewAudio.convertBlobToWav16kMono(recordedBlob)
                .then(function (wavBlob) {
                    return transcribeAudio(wavBlob);
                })
                .then(function (text) {
                    text = (text || '').trim();
                    if (!text) {
                        showError(t(language, 'noSpeech'));
                        enableSpeakControls(true);
                        setAvatar('speaking');
                        setStatus(t(language, 'yourTurn'));
                        return;
                    }

                    appendTranscript('user', text);
                    conversationHistory.push({ role: 'user', content: text });
                    transcriptLines.push('User: ' + text);
                    updateAnswerField();

                    setAvatar('thinking');
                    setStatus(t(language, 'thinking'));

                    callChat(function (reply, newTokens) {
                        tokensUsed += newTokens;
                        if (tokensUsedEl) tokensUsedEl.value = tokensUsed;
                        showAssistantMessage(reply);
                        setAvatar('speaking');
                        setStatus(t(language, 'yourTurn'));
                        enableSpeakControls(true);
                        checkTokenBudget();
                    }, function (errMsg) {
                        showError(errMsg);
                        enableSpeakControls(true);
                        setAvatar('speaking');
                    });
                })
                .catch(function (err) {
                    showError(err.message || t(language, 'errorGeneric'));
                    enableSpeakControls(true);
                    setAvatar('speaking');
                    setStatus(t(language, 'yourTurn'));
                });
        }

        function transcribeAudio(wavBlob) {
            var form = new FormData();
            form.append('audio', wavBlob, 'utterance.wav');
            form.append('language', language.slice(0, 2));
            form.append('surveyId', surveyId);

            var csrf = getCsrfToken();
            if (csrf) form.append('YII_CSRF_TOKEN', csrf);

            return fetch(transcribeUrl, {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error(t(language, 'errorGeneric'));
                    }
                    if (!response.ok || data.error) {
                        throw new Error(data.error || t(language, 'errorGeneric'));
                    }
                    return data.text || '';
                });
            });
        }

        function callChat(onSuccess, onError) {
            var sid = surveyId ? parseInt(surveyId, 10) : 0;
            var payload = JSON.stringify({
                surveyId: sid,
                messages: conversationHistory,
                maxTokens: maxTokens,
                language: language
            });

            var csrf = getCsrfToken();
            var bodyParts = ['payload=' + encodeURIComponent(payload)];
            if (csrf) bodyParts.push('YII_CSRF_TOKEN=' + encodeURIComponent(csrf));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', chatUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.timeout = 90000;

            xhr.onload = function () {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.error) {
                            onError(data.error);
                        } else {
                            onSuccess(data.reply, data.tokensUsed || 0);
                        }
                    } catch (e) {
                        onError(t(language, 'errorGeneric'));
                    }
                } else {
                    try {
                        var errData = JSON.parse(xhr.responseText);
                        onError(errData.error || t(language, 'errorGeneric'));
                    } catch (e2) {
                        onError(t(language, 'errorGeneric'));
                    }
                }
            };
            xhr.onerror = function () { onError(t(language, 'errorGeneric')); };
            xhr.ontimeout = function () { onError(t(language, 'errorGeneric')); };
            xhr.send(bodyParts.join('&'));
        }

        function checkTokenBudget() {
            if (tokensUsed >= maxTokens) {
                if (tokenWarnEl) tokenWarnEl.style.display = 'block';
                finishInterview(true);
            }
        }

        function finishInterview(auto) {
            if (finished) return;
            finished = true;
            enableSpeakControls(false);
            if (finishBtn) finishBtn.style.display = 'none';
            setAvatar('idle');
            setStatus(auto ? t(language, 'autoComplete') : t(language, 'complete'));
            widget.classList.add('ai-interview-finished');
            transcriptLines.push('');
            transcriptLines.push('--- Interview concluded ---');
            updateAnswerField();

            if (submitPromptEnabled) {
                showSubmitModal();
            }
        }

        function showSubmitModal() {
            if (!submitModalEl) return;
            submitModalEl.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            if (submitSurveyBtn) submitSurveyBtn.focus();
        }

        function hideSubmitModal() {
            if (!submitModalEl) return;
            submitModalEl.style.display = 'none';
            document.body.style.overflow = '';
        }

        function focusSurveySubmitButton() {
            var btn = findSurveySubmitButton(widget);
            if (btn) {
                btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (typeof btn.focus === 'function') btn.focus();
            }
        }

        function triggerSurveySubmit() {
            var btn = findSurveySubmitButton(widget);
            if (btn) {
                btn.click();
                return;
            }
            var form = widget.closest('form');
            if (form) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        }

        function skipInterview() {
            finished = true;
            answerField.value = '[Interview skipped — AI service unavailable]';
            enableSpeakControls(false);
            if (errorEl) errorEl.style.display = 'none';
            widget.classList.add('ai-interview-finished');
        }

        function showError(msg) {
            if (errorEl) {
                var errText = errorEl.querySelector('.ai-error-text');
                if (errText) errText.textContent = msg;
                errorEl.style.display = 'flex';
            }
            setStatus(msg);
        }

        function updateAnswerField() {
            if (answerField) {
                answerField.value = transcriptLines.join('\n');
            }
        }

        function restoreFromTranscript(transcript) {
            finished = true;
            widget.classList.add('ai-interview-finished');
            var lines = transcript.split('\n');
            var lastAssistant = '';
            lines.forEach(function (line) {
                if (line.indexOf('Interviewer: ') === 0) {
                    lastAssistant = line.replace('Interviewer: ', '');
                    appendTranscript('assistant', lastAssistant);
                } else if (line.indexOf('User: ') === 0) {
                    appendTranscript('user', line.replace('User: ', ''));
                }
            });
            questionEl.textContent = lastAssistant || '…';
            setAvatar('idle');
            setStatus(t(language, 'complete'));
            enableSpeakControls(false);
        }
    }

    function getSurveyIdFromPage() {
        var form = document.querySelector('form[action*="survey"]');
        if (form) {
            var match = form.action.match(/\/(\d+)\//);
            if (match) return match[1];
        }
        var sidInput = document.querySelector('input[name="sid"]');
        if (sidInput) return sidInput.value;
        return '0';
    }

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var input = document.querySelector('input[name="YII_CSRF_TOKEN"]');
        if (input) return input.value;
        if (window.LS && window.LS.csrfToken) return window.LS.csrfToken;
        return null;
    }

    function findSurveySubmitButton(widget) {
        var form = widget ? widget.closest('form') : document.querySelector('form#limesurvey, form.limesurvey');
        if (!form) {
            form = document.querySelector('form[action*="survey"]');
        }
        if (!form) return null;

        var byId = form.querySelector('#ls-button-submit');
        if (byId) return byId;

        var candidates = form.querySelectorAll('button, input[type="submit"]');
        for (var i = 0; i < candidates.length; i++) {
            var el = candidates[i];
            var label = (el.textContent || el.value || '').trim().toLowerCase();
            if (label === 'submit' || label.indexOf('submit') !== -1) {
                return el;
            }
        }

        return form.querySelector('button[type="submit"], input[type="submit"]');
    }

    function initAllVoiceWidgets() {
        var widgets = document.querySelectorAll('.ai-interview-voice-widget:not([data-ai-voice-init])');
        widgets.forEach(function (widget) {
            widget.setAttribute('data-ai-voice-init', '1');
            initVoiceWidget(widget);
        });
    }

    window.AIInterviewVoiceInitAll = initAllVoiceWidgets;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllVoiceWidgets);
    } else {
        initAllVoiceWidgets();
    }
}());
