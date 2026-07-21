/**
 * Shared audio helpers for AI Interview voice features.
 */
(function (global) {
    'use strict';

    function pickRecorderMimeType() {
        var candidates = [
            'audio/webm;codecs=opus',
            'audio/webm',
            'audio/ogg;codecs=opus',
            'audio/ogg'
        ];
        for (var i = 0; i < candidates.length; i++) {
            if (global.MediaRecorder && global.MediaRecorder.isTypeSupported(candidates[i])) {
                return candidates[i];
            }
        }
        return '';
    }

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
            var decodeContext = new (global.AudioContext || global.webkitAudioContext)();
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

    function startLevelMeter(stream, meterEl, options) {
        var onLevel = null;
        if (options && typeof options.onLevel === 'function') {
            onLevel = options.onLevel;
        }

        var audioContext = new (global.AudioContext || global.webkitAudioContext)();
        var analyser = audioContext.createAnalyser();
        analyser.fftSize = 2048;
        var source = audioContext.createMediaStreamSource(stream);
        source.connect(analyser);
        var data = new Uint8Array(analyser.fftSize);
        var frameId = null;

        function tick() {
            analyser.getByteTimeDomainData(data);
            var sum = 0;
            for (var i = 0; i < data.length; i++) {
                var sample = (data[i] - 128) / 128;
                sum += sample * sample;
            }
            var rms = Math.sqrt(sum / data.length);
            if (meterEl) {
                meterEl.value = Math.min(1, rms * 4);
            }
            if (onLevel) {
                onLevel(rms);
            }
            frameId = global.requestAnimationFrame(tick);
        }

        function begin() {
            tick();
        }

        if (audioContext.state === 'suspended' && audioContext.resume) {
            audioContext.resume().then(begin).catch(begin);
        } else {
            begin();
        }

        return {
            stop: function () {
                if (frameId) {
                    global.cancelAnimationFrame(frameId);
                }
                if (meterEl) {
                    meterEl.value = 0;
                }
                audioContext.close();
            }
        };
    }

    global.AIInterviewAudio = {
        pickRecorderMimeType: pickRecorderMimeType,
        convertBlobToWav16kMono: convertBlobToWav16kMono,
        startLevelMeter: startLevelMeter,
        unlockPlayback: function () {
            var AudioContextCtor = global.AudioContext || global.webkitAudioContext;
            var unlockPromise = Promise.resolve();
            if (AudioContextCtor) {
                var ctx = new AudioContextCtor();
                unlockPromise = ctx.resume().then(function () {
                    return ctx.close();
                }).catch(function () { /* ignore */ });
            }
            return unlockPromise.then(function () {
                var silent = new Audio();
                silent.preload = 'auto';
                silent.src = 'data:audio/mp3;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4LjI5LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAADhAC7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7//////////////////////////////////////////////////////////////////8AAAAATGF2YzU4LjU0AAAAAAAAAAAAAAAAJAYAAAAAAAAABIRj+AAAAAA=';
                return silent.play().catch(function () { /* ignore */ });
            });
        }
    };
}(window));
