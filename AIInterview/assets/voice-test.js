(function () {
    'use strict';

    var cfg = window.AIInterviewVoiceTest || {};
    var statusEl = document.getElementById('voice-test-status');
    var resultEl = document.getElementById('voice-test-result');
    var recordBtn = document.getElementById('voice-test-record');
    var stopBtn = document.getElementById('voice-test-stop');
    var langSelect = document.getElementById('voice-test-language');
    var meter = document.getElementById('voice-test-meter');

    var mediaRecorder = null;
    var mediaStream = null;
    var audioChunks = [];
    var audioContext = null;
    var analyser = null;
    var meterFrame = null;

    function setStatus(msg, isError) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'voice-test-banner' + (isError ? ' voice-test-banner-error' : ' voice-test-banner-ok');
    }

    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var csrfInput = document.getElementById('voice-test-csrf');
        if (csrfInput) return csrfInput.value;
        var input = document.querySelector('input[name="YII_CSRF_TOKEN"]');
        if (input) return input.value;
        if (window.LS && window.LS.csrfToken) return window.LS.csrfToken;
        return null;
    }

    function pickRecorderMimeType() {
        var candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/ogg'
        ];
        for (var i = 0; i < candidates.length; i++) {
            if (MediaRecorder.isTypeSupported(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
    }

    function startMeter(stream) {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        var source = audioContext.createMediaStreamSource(stream);
        source.connect(analyser);
        var data = new Uint8Array(analyser.fftSize);

        function tick() {
            analyser.getByteTimeDomainData(data);
            var sum = 0;
            for (var i = 0; i < data.length; i++) {
                var sample = (data[i] - 128) / 128;
                sum += sample * sample;
            }
            var rms = Math.sqrt(sum / data.length);
            if (meter) {
                meter.value = Math.min(1, rms * 4);
            }
            meterFrame = requestAnimationFrame(tick);
        }

        var startLoop = function () {
            tick();
        };

        if (audioContext.state === 'suspended' && audioContext.resume) {
            audioContext.resume().then(startLoop).catch(startLoop);
        } else {
            startLoop();
        }
    }

    function stopMeter() {
        if (meterFrame) {
            cancelAnimationFrame(meterFrame);
            meterFrame = null;
        }
        if (audioContext) {
            audioContext.close();
            audioContext = null;
        }
        if (meter) {
            meter.value = 0;
        }
    }

    /**
     * Azure short-audio REST API reliably accepts 16 kHz mono PCM WAV.
     * Browser MediaRecorder produces WebM/Opus, which often decodes to silence server-side.
     */
    function audioBufferToWav(audioBuffer) {
        var channelData = audioBuffer.getChannelData(0);
        var sampleRate = audioBuffer.sampleRate;
        var numSamples = channelData.length;
        var bytesPerSample = 2;
        var blockAlign = bytesPerSample;
        var byteRate = sampleRate * blockAlign;
        var dataSize = numSamples * bytesPerSample;
        var buffer = new ArrayBuffer(44 + dataSize);
        var view = new DataView(buffer);

        function writeString(offset, str) {
            for (var i = 0; i < str.length; i++) {
                view.setUint8(offset + i, str.charCodeAt(i));
            }
        }

        writeString(0, 'RIFF');
        view.setUint32(4, 36 + dataSize, true);
        writeString(8, 'WAVE');
        writeString(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, byteRate, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, 16, true);
        writeString(36, 'data');
        view.setUint32(40, dataSize, true);

        var offset = 44;
        for (var j = 0; j < numSamples; j++) {
            var sample = Math.max(-1, Math.min(1, channelData[j]));
            view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7FFF, true);
            offset += 2;
        }

        return buffer;
    }

    function convertBlobToWav16kMono(blob) {
        return blob.arrayBuffer().then(function (arrayBuffer) {
            var decodeContext = new (window.AudioContext || window.webkitAudioContext)();
            return decodeContext.decodeAudioData(arrayBuffer.slice(0)).then(function (decoded) {
                return decodeContext.close().then(function () {
                    var targetSampleRate = 16000;
                    var frameCount = Math.max(1, Math.ceil(decoded.duration * targetSampleRate));
                    var offline = new OfflineAudioContext(1, frameCount, targetSampleRate);
                    var source = offline.createBufferSource();
                    source.buffer = decoded;
                    source.connect(offline.destination);
                    source.start(0);
                    return offline.startRendering();
                });
            }).catch(function (err) {
                try { decodeContext.close(); } catch (e) { /* ignore */ }
                throw err;
            });
        }).then(function (rendered) {
            return new Blob([audioBufferToWav(rendered)], { type: 'audio/wav' });
        });
    }

    function parseJsonResponse(response) {
        return response.text().then(function (text) {
            try {
                return { ok: response.ok, data: JSON.parse(text), raw: text };
            } catch (e) {
                var snippet = text.replace(/\s+/g, ' ').trim().substring(0, 120);
                throw new Error(
                    'Server returned HTML instead of JSON (HTTP ' + response.status + ') for '
                    + (response.url || 'request') + '. Snippet: ' + snippet
                );
            }
        });
    }

    function fetchStatus() {
        if (!cfg.statusUrl) return;
        fetch(cfg.statusUrl, { credentials: 'same-origin' })
            .then(parseJsonResponse)
            .then(function (result) {
                var data = result.data;
                if (data.error) {
                    setStatus(data.error, true);
                    return;
                }
                if (!data.azure_speech_set) {
                    setStatus('Azure Speech key is not configured in plugin settings.', true);
                } else {
                    setStatus('Azure Speech configured (region: ' + (data.azure_speech_region || 'westeurope') + ').', false);
                }
            })
            .catch(function (err) {
                setStatus('Could not load plugin status: ' + err.message, true);
            });
    }

    recordBtn.addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('Microphone API not available in this browser.', true);
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (stream) {
                mediaStream = stream;
                audioChunks = [];

                var mimeType = pickRecorderMimeType();
                var options = mimeType ? { mimeType: mimeType } : undefined;
                mediaRecorder = new MediaRecorder(stream, options);

                mediaRecorder.ondataavailable = function (e) {
                    if (e.data && e.data.size > 0) {
                        audioChunks.push(e.data);
                    }
                };
                mediaRecorder.onstop = function () {
                    if (mediaStream) {
                        mediaStream.getTracks().forEach(function (t) { t.stop(); });
                        mediaStream = null;
                    }
                    stopMeter();

                    var blobType = mimeType || 'audio/webm';
                    var recordedBlob = new Blob(audioChunks, { type: blobType.split(';')[0] });
                    transcribe(recordedBlob);
                };

                mediaRecorder.start(250);
                startMeter(stream);
                recordBtn.disabled = true;
                stopBtn.disabled = false;
                setStatus('Recording... speak now, then click Stop.', false);
            })
            .catch(function (err) {
                setStatus('Microphone access denied: ' + err.message, true);
            });
    });

    stopBtn.addEventListener('click', function () {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            if (typeof mediaRecorder.requestData === 'function') {
                mediaRecorder.requestData();
            }
            mediaRecorder.stop();
        }
        recordBtn.disabled = false;
        stopBtn.disabled = true;
        setStatus('Converting audio and transcribing...', false);
    });

    function transcribe(recordedBlob) {
        if (!recordedBlob || recordedBlob.size === 0) {
            setStatus('No audio captured. Check microphone permissions and try again.', true);
            return;
        }

        convertBlobToWav16kMono(recordedBlob)
            .then(function (wavBlob) {
                var form = new FormData();
                form.append('audio', wavBlob, 'utterance.wav');
                form.append('language', langSelect ? langSelect.value : 'en');
                form.append('surveyId', '0');

                var csrf = getCsrfToken();
                if (csrf) {
                    form.append('YII_CSRF_TOKEN', csrf);
                }

                return fetch(cfg.transcribeUrl, {
                    method: 'POST',
                    body: form,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
            })
            .then(parseJsonResponse)
            .then(function (result) {
                var data = result.data;
                if (!result.ok || data.error) {
                    setStatus(data.error || ('Server error (HTTP ' + result.ok + ')'), true);
                    return;
                }

                var line = data.text || '(empty transcription)';
                if (data.warning) {
                    line += '\n\n[warning: ' + data.warning + ']';
                }
                if (data.confidence != null) {
                    line += '\n\n[confidence: ' + Math.round(data.confidence * 100) + '%, locale: ' + (data.locale || '') + ']';
                }
                if (data.durationMs != null) {
                    line += '\n[audio duration: ' + data.durationMs + ' ms, upload: ' + (data.audioSizeBytes || '?') + ' bytes]';
                }
                resultEl.textContent = line;

                if (!data.text) {
                    setStatus(data.warning || 'Transcription returned no text. Try speaking longer or louder.', true);
                } else {
                    setStatus('Transcription complete.', false);
                }
            })
            .catch(function (err) {
                setStatus('Transcription request failed: ' + err.message, true);
            });
    }

    fetchStatus();
}());
