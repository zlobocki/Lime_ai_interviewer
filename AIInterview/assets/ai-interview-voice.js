/**
 * AI Interview — Voice widget (M1 + M1.5 + M2a + M2b + M2c TTS)
 * Welcome/mic check, speak or type answers, Allie asks questions on screen.
 */
(function () {
    'use strict';

    var STRINGS = {
        en: {
            preparing: 'Preparing your interview…',
            thinking: 'Allie is thinking…',
            speakPrompt: 'Tap "Start speaking", then tap "Done" when finished.',
            listening: 'Listening… speak now.',
            transcribing: 'Transcribing…',
            yourTurn: 'Your turn — tap Start speaking.',
            finish: 'Finish interview',
            startSpeak: 'Start speaking',
            doneSpeak: 'Done speaking',
            micDenied: 'Microphone access denied. Check your browser settings and reload the page.',
            noSpeech: 'No speech detected. Please try again.',
            errorGeneric: 'Something went wrong. Please try again.',
            complete: 'Interview complete. Thank you.',
            autoComplete: 'Interview automatically concluded.',
            you: 'You',
            allie: 'Allie',
            welcomeMicChecking: 'Requesting microphone access…',
            welcomeMicReady: 'Microphone looks good — you can continue.',
            welcomeMicGranted: 'Microphone enabled. Continue when ready, or speak to test the meter.',
            welcomeMicDeniedHelp: 'Microphone blocked. Allow access in your browser bar, then reload this page.',
            welcomeMicUnsupported: 'This browser does not support microphone recording.',
            transcriptShow: 'Show transcript',
            transcriptHide: 'Hide transcript',
            inputModeSpeak: 'Speak',
            inputModeType: 'Type',
            typePlaceholder: 'Type your response here…',
            send: 'Send',
            typePrompt: 'Type your answer and tap Send.',
            yourTurnType: 'Your turn — type your answer.',
            mandatoryHint: 'Please answer at least once before continuing.',
            reviewPrompt: 'Review your answer, edit if needed, then tap Send.',
            sendAnswer: 'Send answer',
            livePreviewLabel: 'What you say will appear here live.',
            allieSpeaking: 'Allie is speaking…'
        },
        pl: {
            preparing: 'Przygotowuję wywiad…',
            thinking: 'Allie analizuje…',
            speakPrompt: 'Kliknij „Zacznij mówić”, a po odpowiedzi „Gotowe”.',
            listening: 'Słucham… mów teraz.',
            transcribing: 'Transkrybuję…',
            yourTurn: 'Twoja kolej — kliknij Zacznij mówić.',
            finish: 'Zakończ wywiad',
            startSpeak: 'Zacznij mówić',
            doneSpeak: 'Gotowe',
            micDenied: 'Brak dostępu do mikrofonu. Sprawdź ustawienia przeglądarki i odśwież stronę.',
            noSpeech: 'Nie wykryto mowy. Spróbuj ponownie.',
            errorGeneric: 'Coś poszło nie tak. Spróbuj ponownie.',
            complete: 'Wywiad zakończony. Dziękuję.',
            autoComplete: 'Wywiad został automatycznie zakończony.',
            you: 'Ty',
            allie: 'Allie',
            welcomeMicChecking: 'Proszę o dostęp do mikrofonu…',
            welcomeMicReady: 'Mikrofon działa — możesz kontynuować.',
            welcomeMicGranted: 'Mikrofon włączony. Kontynuuj, lub powiedz coś, aby sprawdzić wskaźnik.',
            welcomeMicDeniedHelp: 'Mikrofon zablokowany. Zezwól w pasku przeglądarki i odśwież stronę.',
            welcomeMicUnsupported: 'Ta przeglądarka nie obsługuje nagrywania z mikrofonu.',
            transcriptShow: 'Pokaż transkrypcję',
            transcriptHide: 'Ukryj transkrypcję',
            inputModeSpeak: 'Mów',
            inputModeType: 'Pisz',
            typePlaceholder: 'Wpisz swoją odpowiedź…',
            send: 'Wyślij',
            typePrompt: 'Wpisz odpowiedź i kliknij Wyślij.',
            yourTurnType: 'Twoja kolej — wpisz odpowiedź.',
            mandatoryHint: 'Odpowiedz przynajmniej raz, zanim przejdziesz dalej.',
            reviewPrompt: 'Sprawdź odpowiedź, popraw jeśli trzeba, potem kliknij Wyślij.',
            sendAnswer: 'Wyślij odpowiedź',
            livePreviewLabel: 'To, co mówisz, pojawi się tutaj na żywo.',
            allieSpeaking: 'Allie mówi…'
        }
    };

    function getSpeechRecognitionCtor() {
        return window.SpeechRecognition || window.webkitSpeechRecognition || null;
    }

    function speechLocaleFromLanguage(lang) {
        var code = (lang || 'en').toLowerCase().slice(0, 2);
        return code === 'pl' ? 'pl-PL' : 'en-GB';
    }

    function t(lang, key) {
        var code = (lang || 'en').toLowerCase().slice(0, 2);
        var pack = STRINGS[code] || STRINGS.en;
        return pack[key] || STRINGS.en[key] || key;
    }

    function initVoiceWidget(widget) {
        var sgqa = widget.dataset.sgqa;
        var chatUrl = widget.dataset.ajaxUrl;
        var transcribeUrl = widget.dataset.transcribeUrl;
        var synthesizeUrl = widget.dataset.synthesizeUrl || '';
        var language = widget.dataset.language || 'en';
        var mandatory = widget.dataset.mandatory === '1';
        var surveyId = widget.dataset.surveyId || getSurveyIdFromPage();
        var prompt = widget.dataset.prompt || '';
        var maxTokens = parseInt(widget.dataset.maxTokens, 10) || 6000;
        var submitPromptEnabled = widget.dataset.submitPrompt === '1';
        var liveTranscriptEnabled = widget.dataset.liveTranscript === '1';
        var aiSpeechEnabled = widget.dataset.aiSpeech === '1' && synthesizeUrl !== '';

        var welcomeEl = document.getElementById('ai-welcome-' + sgqa);
        var welcomeMeterEl = document.getElementById('ai-welcome-meter-' + sgqa);
        var welcomeStatusEl = document.getElementById('ai-welcome-status-' + sgqa);
        var welcomeContinueBtn = document.getElementById('ai-welcome-continue-' + sgqa);
        var interviewPanelEl = document.getElementById('ai-interview-panel-' + sgqa);
        var avatarEl = document.getElementById('ai-avatar-' + sgqa);
        var questionEl = document.getElementById('ai-question-' + sgqa);
        var statusEl = document.getElementById('ai-voice-status-' + sgqa);
        var meterEl = document.getElementById('ai-voice-meter-' + sgqa);
        var transcriptEl = document.getElementById('ai-voice-transcript-' + sgqa);
        var transcriptToggleBtn = document.getElementById('ai-transcript-toggle-' + sgqa);
        var errorEl = document.getElementById('ai-error-' + sgqa);
        var tokenWarnEl = document.getElementById('ai-token-warning-' + sgqa);
        var speakBtn = document.getElementById('ai-speak-' + sgqa);
        var stopSpeakBtn = document.getElementById('ai-stop-speak-' + sgqa);
        var finishBtn = document.getElementById('ai-finish-' + sgqa);
        var speakAreaEl = document.getElementById('ai-speak-area-' + sgqa);
        var typeAreaEl = document.getElementById('ai-type-area-' + sgqa);
        var modeSpeakBtn = document.getElementById('ai-mode-speak-' + sgqa);
        var modeTypeBtn = document.getElementById('ai-mode-type-' + sgqa);
        var typeInputEl = document.getElementById('ai-voice-input-' + sgqa);
        var typeSendBtn = document.getElementById('ai-voice-send-' + sgqa);
        var livePreviewLabelEl = document.getElementById('ai-live-label-' + sgqa);
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
        var chatLoading = false;
        var mediaRecorder = null;
        var mediaStream = null;
        var audioChunks = [];
        var meterControl = null;
        var recorderMimeType = '';
        var welcomeStream = null;
        var welcomeMeterControl = null;
        var micVerified = false;
        var transcriptVisible = false;
        var inputMode = 'speak';
        var isRecording = false;
        var isReviewingSpeech = false;
        var isSpeaking = false;
        var speechRecognition = null;
        var liveTranscriptFinal = '';
        var typeSendDefaultLabel = typeSendBtn ? typeSendBtn.textContent : '';
        var ttsAudio = null;
        var ttsObjectUrl = '';

        if (answerField && !answerField.value.trim()) {
            answerField.value = '[AI Interview in progress]';
        }

        if (answerField && answerField.value.trim()
                && answerField.value.trim() !== '[AI Interview in progress]') {
            showInterviewPanel();
            restoreFromTranscript(answerField.value.trim());
            return;
        }

        if (!prompt) {
            showInterviewPanel();
            showError('AI Interview is not configured. Please contact the survey administrator.');
            return;
        }

        startWelcomeFlow();

        speakBtn.addEventListener('click', startRecording);
        stopSpeakBtn.addEventListener('click', stopRecording);
        if (modeSpeakBtn) {
            modeSpeakBtn.addEventListener('click', function () { setInputMode('speak'); });
        }
        if (modeTypeBtn) {
            modeTypeBtn.addEventListener('click', function () { setInputMode('type'); });
        }
        if (typeSendBtn) {
            typeSendBtn.addEventListener('click', sendTypedMessage);
        }
        if (typeInputEl) {
            typeInputEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendTypedMessage();
                }
            });
        }
        if (finishBtn) {
            finishBtn.addEventListener('click', function () { finishInterview(false); });
        }
        if (welcomeContinueBtn) {
            welcomeContinueBtn.addEventListener('click', beginInterview);
        }
        if (transcriptToggleBtn) {
            transcriptToggleBtn.addEventListener('click', toggleTranscript);
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
                startInterviewChat();
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
                        setStatus(t(language, 'mandatoryHint'));
                        if (inputMode === 'type' && typeInputEl) {
                            typeInputEl.focus();
                        }
                    }
                }, true);
            }
        }

        function startWelcomeFlow() {
            setWelcomeStatus(t(language, 'welcomeMicChecking'));
            startWelcomeMicCheck();
        }

        function startWelcomeMicCheck() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                setWelcomeStatus(t(language, 'welcomeMicUnsupported'));
                return;
            }

            navigator.mediaDevices.getUserMedia({ audio: true })
                .then(function (stream) {
                    welcomeStream = stream;
                    welcomeMeterControl = window.AIInterviewAudio.startLevelMeter(
                        stream,
                        welcomeMeterEl,
                        {
                            onLevel: function (rms) {
                                if (rms > 0.08) {
                                    micVerified = true;
                                    if (welcomeContinueBtn) welcomeContinueBtn.disabled = false;
                                    setWelcomeStatus(t(language, 'welcomeMicReady'));
                                }
                            }
                        }
                    );

                    setTimeout(function () {
                        if (!welcomeContinueBtn || !welcomeStream) return;
                        welcomeContinueBtn.disabled = false;
                        if (!micVerified) {
                            setWelcomeStatus(t(language, 'welcomeMicGranted'));
                        }
                    }, 2500);
                })
                .catch(function () {
                    setWelcomeStatus(t(language, 'welcomeMicDeniedHelp'));
                });
        }

        function stopWelcomeMicCheck() {
            if (welcomeMeterControl) {
                welcomeMeterControl.stop();
                welcomeMeterControl = null;
            }
            if (welcomeStream) {
                welcomeStream.getTracks().forEach(function (track) { track.stop(); });
                welcomeStream = null;
            }
        }

        function setWelcomeStatus(text) {
            if (welcomeStatusEl) welcomeStatusEl.textContent = text;
        }

        function showInterviewPanel() {
            if (welcomeEl) welcomeEl.style.display = 'none';
            if (interviewPanelEl) interviewPanelEl.style.display = 'block';
        }

        function beginInterview() {
            stopWelcomeMicCheck();
            showInterviewPanel();
            updateInputModeUI();
            var unlock = (window.AIInterviewAudio && window.AIInterviewAudio.unlockPlayback)
                ? window.AIInterviewAudio.unlockPlayback()
                : Promise.resolve();
            unlock.then(function () {
                startInterviewChat();
            });
        }

        function startInterviewChat() {
            setAvatar('thinking');
            setStatus(t(language, 'preparing'));
            questionEl.textContent = '…';
            chatLoading = true;
            refreshControls();

            callChat(function (reply, newTokens) {
                chatLoading = false;
                tokensUsed += newTokens;
                if (tokensUsedEl) tokensUsedEl.value = tokensUsed;
                showAssistantMessage(reply);
                afterAssistantReply(reply).then(function () {
                    if (finishBtn) finishBtn.style.display = 'inline-block';
                    checkTokenBudget();
                });
            }, function (errMsg) {
                chatLoading = false;
                showError(errMsg);
                refreshControls();
            });
        }

        function setInputMode(mode) {
            if (finished || chatLoading || isRecording || isReviewingSpeech || isSpeaking) return;
            if (mode !== 'speak' && mode !== 'type') return;
            inputMode = mode;
            updateInputModeUI();
            refreshControls();
            setTurnStatus();
        }

        function shouldShowTypeArea() {
            if (inputMode === 'type') return true;
            return liveTranscriptEnabled && inputMode === 'speak'
                && (isRecording || isReviewingSpeech);
        }

        function updateInputModeUI() {
            var speakActive = inputMode === 'speak';
            var showTypeArea = shouldShowTypeArea();

            if (modeSpeakBtn) {
                modeSpeakBtn.classList.toggle('is-active', speakActive);
                modeSpeakBtn.setAttribute('aria-selected', speakActive ? 'true' : 'false');
            }
            if (modeTypeBtn) {
                modeTypeBtn.classList.toggle('is-active', !speakActive);
                modeTypeBtn.setAttribute('aria-selected', !speakActive ? 'true' : 'false');
            }
            if (speakAreaEl) {
                speakAreaEl.classList.toggle('is-hidden', !speakActive);
                speakAreaEl.hidden = !speakActive;
            }
            if (typeAreaEl) {
                typeAreaEl.classList.toggle('is-hidden', !showTypeArea);
                typeAreaEl.hidden = !showTypeArea;
                typeAreaEl.classList.toggle(
                    'is-live-preview',
                    liveTranscriptEnabled && speakActive && isRecording
                );
                typeAreaEl.classList.toggle('is-review', isReviewingSpeech);
            }
            if (livePreviewLabelEl) {
                livePreviewLabelEl.hidden = !(liveTranscriptEnabled && speakActive && isRecording);
            }
            if (typeSendBtn) {
                typeSendBtn.textContent = isReviewingSpeech
                    ? t(language, 'sendAnswer')
                    : typeSendDefaultLabel;
            }
        }

        function setTurnStatus() {
            if (finished || chatLoading) return;
            if (inputMode === 'type') {
                setStatus(t(language, 'typePrompt'));
                if (typeInputEl) typeInputEl.focus();
            } else {
                setStatus(t(language, 'speakPrompt'));
            }
        }

        function refreshControls() {
            var canInteract = !finished && !chatLoading && !isSpeaking;
            updateInputModeUI();

            if (speakBtn) {
                speakBtn.disabled = !canInteract || inputMode !== 'speak' || isRecording || isReviewingSpeech;
            }
            if (stopSpeakBtn) {
                stopSpeakBtn.disabled = !isRecording;
            }

            if (typeInputEl) {
                if (isRecording && liveTranscriptEnabled) {
                    typeInputEl.readOnly = true;
                    typeInputEl.disabled = false;
                } else if (inputMode === 'type' && canInteract) {
                    typeInputEl.readOnly = false;
                    typeInputEl.disabled = false;
                } else if (isReviewingSpeech && canInteract) {
                    typeInputEl.readOnly = false;
                    typeInputEl.disabled = false;
                } else {
                    typeInputEl.readOnly = false;
                    typeInputEl.disabled = true;
                }
            }

            if (typeSendBtn) {
                var showSend = inputMode === 'type' || isReviewingSpeech;
                typeSendBtn.hidden = !showSend;
                typeSendBtn.disabled = !canInteract || isRecording
                    || (inputMode !== 'type' && !isReviewingSpeech);
            }

            var canSwitchMode = canInteract && !isRecording && !isReviewingSpeech;
            if (modeSpeakBtn) modeSpeakBtn.disabled = !canSwitchMode;
            if (modeTypeBtn) modeTypeBtn.disabled = !canSwitchMode;
        }

        function toggleTranscript() {
            transcriptVisible = !transcriptVisible;
            if (transcriptEl) {
                transcriptEl.classList.toggle('is-collapsed', !transcriptVisible);
            }
            if (transcriptToggleBtn) {
                transcriptToggleBtn.textContent = transcriptVisible
                    ? t(language, 'transcriptHide')
                    : t(language, 'transcriptShow');
                transcriptToggleBtn.setAttribute('aria-expanded', transcriptVisible ? 'true' : 'false');
            }
        }

        function setAvatar(state) {
            if (!avatarEl) return;
            var visualState = (state === 'speaking') ? 'idle' : state;
            var url = avatars[visualState] || avatars.idle || avatars.thinking;
            if (url) avatarEl.src = url;
            avatarEl.alt = 'Allie — ' + visualState;
        }

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text;
        }

        function enableSpeakControls(enabled) {
            refreshControls();
        }

        function submitUserMessage(text) {
            text = (text || '').trim();
            if (!text || finished || chatLoading) return;

            if (errorEl) errorEl.style.display = 'none';

            appendTranscript('user', text);
            conversationHistory.push({ role: 'user', content: text });
            transcriptLines.push('User: ' + text);
            updateAnswerField();

            setAvatar('thinking');
            setStatus(t(language, 'thinking'));
            chatLoading = true;
            refreshControls();

            callChat(function (reply, newTokens) {
                chatLoading = false;
                tokensUsed += newTokens;
                if (tokensUsedEl) tokensUsedEl.value = tokensUsed;
                showAssistantMessage(reply);
                afterAssistantReply(reply).then(function () {
                    checkTokenBudget();
                });
            }, function (errMsg) {
                chatLoading = false;
                showError(errMsg);
                refreshControls();
                setAvatar('speaking');
            });
        }

        function cleanupTtsObjectUrl() {
            if (ttsObjectUrl) {
                URL.revokeObjectURL(ttsObjectUrl);
                ttsObjectUrl = '';
            }
        }

        function stopAssistantSpeech() {
            if (ttsAudio) {
                ttsAudio.pause();
                ttsAudio.onended = null;
                ttsAudio.onerror = null;
                ttsAudio.removeAttribute('src');
                try { ttsAudio.load(); } catch (e) { /* ignore */ }
            }
            cleanupTtsObjectUrl();
            isSpeaking = false;
        }

        function playTtsBlob(blob) {
            return new Promise(function (resolve) {
                stopAssistantSpeech();
                if (!ttsAudio) ttsAudio = new Audio();
                ttsObjectUrl = URL.createObjectURL(blob);
                ttsAudio.src = ttsObjectUrl;
                isSpeaking = true;
                refreshControls();
                ttsAudio.onended = function () {
                    cleanupTtsObjectUrl();
                    isSpeaking = false;
                    resolve();
                };
                ttsAudio.onerror = function () {
                    cleanupTtsObjectUrl();
                    isSpeaking = false;
                    resolve();
                };
                var playPromise = ttsAudio.play();
                if (playPromise && typeof playPromise.catch === 'function') {
                    playPromise.catch(function () {
                        cleanupTtsObjectUrl();
                        isSpeaking = false;
                        resolve();
                    });
                }
            });
        }

        function playAssistantSpeechIfEnabled(text) {
            if (!aiSpeechEnabled || !text.trim()) {
                return Promise.resolve();
            }

            setAvatar('listening');
            setStatus(t(language, 'allieSpeaking'));

            var form = new FormData();
            form.append('text', text);
            form.append('language', language.slice(0, 2));
            form.append('surveyId', surveyId);
            var csrf = getCsrfToken();
            if (csrf) form.append('YII_CSRF_TOKEN', csrf);

            return fetch(synthesizeUrl, {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.text().then(function (raw) {
                    var data;
                    try {
                        data = JSON.parse(raw);
                    } catch (e) {
                        throw new Error(t(language, 'errorGeneric'));
                    }
                    if (!response.ok || data.error) {
                        throw new Error(data.error || t(language, 'errorGeneric'));
                    }
                    return data;
                });
            }).then(function (data) {
                var binary = atob(data.audioBase64 || '');
                var bytes = new Uint8Array(binary.length);
                for (var i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                var blob = new Blob([bytes], { type: data.contentType || 'audio/mpeg' });
                return playTtsBlob(blob);
            }).catch(function () {
                isSpeaking = false;
                return Promise.resolve();
            });
        }

        function afterAssistantReply(text) {
            return playAssistantSpeechIfEnabled(text).then(function () {
                setAvatar('speaking');
                setTurnStatus();
                refreshControls();
            });
        }

        function sendTypedMessage() {
            if (finished || chatLoading || !typeInputEl) return;
            if (inputMode !== 'type' && !isReviewingSpeech) return;
            var text = typeInputEl.value.trim();
            if (!text) return;
            typeInputEl.value = '';
            isReviewingSpeech = false;
            updateInputModeUI();
            submitUserMessage(text);
        }

        function startLiveTranscript() {
            if (!liveTranscriptEnabled) return;

            var SpeechRecognition = getSpeechRecognitionCtor();
            if (!SpeechRecognition) return;

            stopLiveTranscript();

            liveTranscriptFinal = '';
            if (typeInputEl) typeInputEl.value = '';

            speechRecognition = new SpeechRecognition();
            speechRecognition.lang = speechLocaleFromLanguage(language);
            speechRecognition.continuous = true;
            speechRecognition.interimResults = true;

            speechRecognition.onresult = function (event) {
                var interim = '';
                var finalText = liveTranscriptFinal;
                for (var i = event.resultIndex; i < event.results.length; i++) {
                    var piece = event.results[i][0].transcript;
                    if (event.results[i].isFinal) {
                        finalText += piece + ' ';
                    } else {
                        interim += piece;
                    }
                }
                liveTranscriptFinal = finalText;
                if (typeInputEl) {
                    typeInputEl.value = (finalText + interim).trim();
                }
            };

            speechRecognition.onerror = function () {
                /* Non-fatal — Azure transcription remains authoritative on Done. */
            };

            try {
                speechRecognition.start();
            } catch (err) {
                speechRecognition = null;
            }
        }

        function stopLiveTranscript() {
            if (!speechRecognition) return;
            try {
                speechRecognition.stop();
            } catch (err) {
                /* ignore */
            }
            speechRecognition = null;
        }

        function enterSpeechReview(text) {
            isReviewingSpeech = true;
            if (typeInputEl) typeInputEl.value = text;
            refreshControls();
            setAvatar('speaking');
            setStatus(t(language, 'reviewPrompt'));
            if (typeInputEl) {
                typeInputEl.focus();
                var len = typeInputEl.value.length;
                if (typeof typeInputEl.setSelectionRange === 'function') {
                    typeInputEl.setSelectionRange(len, len);
                }
            }
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
            if (finished || chatLoading || isSpeaking || !navigator.mediaDevices) return;

            stopAssistantSpeech();
            refreshControls();

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
                    isRecording = true;
                    if (typeInputEl && liveTranscriptEnabled) typeInputEl.value = '';
                    startLiveTranscript();
                    refreshControls();
                    setAvatar('listening');
                    setStatus(t(language, 'listening'));
                    speakBtn.classList.add('is-recording');
                })
                .catch(function () {
                    showError(t(language, 'micDenied'));
                });
        }

        function stopRecording() {
            stopLiveTranscript();
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                if (typeof mediaRecorder.requestData === 'function') {
                    mediaRecorder.requestData();
                }
                mediaRecorder.stop();
            }
            speakBtn.classList.remove('is-recording');
            isRecording = false;
            refreshControls();
            setAvatar('thinking');
            setStatus(t(language, 'transcribing'));
        }

        function processRecording(recordedBlob) {
            if (!recordedBlob || recordedBlob.size === 0) {
                showError(t(language, 'noSpeech'));
                isReviewingSpeech = false;
                refreshControls();
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
                    if (!text && typeInputEl) {
                        text = typeInputEl.value.trim();
                    }
                    if (!text) {
                        showError(t(language, 'noSpeech'));
                        isReviewingSpeech = false;
                        refreshControls();
                        setAvatar('speaking');
                        setStatus(t(language, 'yourTurn'));
                        return;
                    }

                    if (liveTranscriptEnabled) {
                        enterSpeechReview(text);
                        return;
                    }

                    submitUserMessage(text);
                })
                .catch(function (err) {
                    showError(err.message || t(language, 'errorGeneric'));
                    isReviewingSpeech = false;
                    refreshControls();
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
                sgqa: sgqa,
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
            chatLoading = false;
            isRecording = false;
            isReviewingSpeech = false;
            stopLiveTranscript();
            stopAssistantSpeech();
            refreshControls();
            if (typeInputEl) typeInputEl.disabled = true;
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
            chatLoading = false;
            isRecording = false;
            isReviewingSpeech = false;
            stopLiveTranscript();
            stopAssistantSpeech();
            stopWelcomeMicCheck();
            answerField.value = '[Interview skipped — AI service unavailable]';
            refreshControls();
            if (typeInputEl) typeInputEl.disabled = true;
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
            refreshControls();
            if (typeInputEl) typeInputEl.disabled = true;
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
