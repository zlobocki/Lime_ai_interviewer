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
        var input = document.querySelector('input[name="YII_CSRF_TOKEN"]');
        if (input) return input.value;
        return null;
    }

    function startMeter(stream) {
        audioContext = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioContext.createAnalyser();
        var source = audioContext.createMediaStreamSource(stream);
        source.connect(analyser);
        analyser.fftSize = 256;
        var data = new Uint8Array(analyser.frequencyBinCount);

        function tick() {
            analyser.getByteFrequencyData(data);
            var sum = 0;
            for (var i = 0; i < data.length; i++) sum += data[i];
            var avg = sum / data.length / 255;
            if (meter) meter.value = avg;
            meterFrame = requestAnimationFrame(tick);
        }
        tick();
    }

    function stopMeter() {
        if (meterFrame) cancelAnimationFrame(meterFrame);
        meterFrame = null;
        if (audioContext) {
            audioContext.close();
            audioContext = null;
        }
        if (meter) meter.value = 0;
    }

    function fetchStatus() {
        if (!cfg.statusUrl) return;
        fetch(cfg.statusUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
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
            .catch(function () {
                setStatus('Could not load plugin status.', true);
            });
    }

    recordBtn.addEventListener('click', function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('Microphone API not available in this browser.', true);
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function (stream) {
                audioChunks = [];
                mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm;codecs=opus' });
                mediaRecorder.ondataavailable = function (e) {
                    if (e.data && e.data.size > 0) audioChunks.push(e.data);
                };
                mediaRecorder.onstop = function () {
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    stopMeter();
                    transcribe(new Blob(audioChunks, { type: 'audio/webm' }));
                };
                mediaRecorder.start();
                startMeter(stream);
                recordBtn.disabled = true;
                stopBtn.disabled = false;
                setStatus('Recording... click Stop when finished.', false);
            })
            .catch(function (err) {
                setStatus('Microphone access denied: ' + err.message, true);
            });
    });

    stopBtn.addEventListener('click', function () {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
        recordBtn.disabled = false;
        stopBtn.disabled = true;
        setStatus('Transcribing...', false);
    });

    function transcribe(blob) {
        var form = new FormData();
        form.append('audio', blob, 'utterance.webm');
        form.append('language', langSelect ? langSelect.value : 'en');
        form.append('surveyId', '0');
        var csrf = getCsrfToken();
        if (csrf) form.append('YII_CSRF_TOKEN', csrf);

        fetch(cfg.transcribeUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    setStatus(data.error, true);
                    return;
                }
                var line = data.text || '(empty transcription)';
                if (data.confidence != null) {
                    line += '\n\n[confidence: ' + Math.round(data.confidence * 100) + '%, locale: ' + (data.locale || '') + ']';
                }
                resultEl.textContent = line;
                setStatus('Transcription complete.', false);
            })
            .catch(function (err) {
                setStatus('Transcription request failed: ' + err.message, true);
            });
    }

    fetchStatus();
}());
