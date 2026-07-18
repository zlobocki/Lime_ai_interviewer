<?php
/**
 * Azure Speech-to-Text client (EU regions).
 *
 * Uses the short-audio REST API. Audio is sent once and not stored by this class.
 *
 * @see https://learn.microsoft.com/en-us/azure/ai-services/speech-service/rest-speech-to-text-short
 */
class AzureSpeechClient
{
    /** @var string */
    private $apiKey;

    /** @var string e.g. westeurope, polandcentral */
    private $region;

    public function __construct(string $apiKey, string $region)
    {
        $this->apiKey = $apiKey;
        $this->region = preg_replace('/[^a-z0-9]/', '', strtolower($region));
    }

    /**
     * Transcribe a short audio clip (max ~60 s recommended).
     *
     * @param string $audioBytes Raw audio file contents
     * @param string $locale     BCP-47 locale, e.g. en-GB, pl-PL
     * @param string $mimeType   MIME type of the uploaded file
     * @return array{ text: string, confidence: float|null, durationMs: int|null }|array{ error: string }
     */
    public function recognizeOnce(string $audioBytes, string $locale, string $mimeType): array
    {
        if ($this->apiKey === '' || $this->region === '') {
            return ['error' => 'Azure Speech is not configured.'];
        }

        if ($audioBytes === '') {
            return ['error' => 'Empty audio upload.'];
        }

        $locale = $this->sanitizeLocale($locale);
        $contentType = $this->mapMimeToContentType($mimeType);

        if ($contentType === null) {
            return ['error' => 'Unsupported audio format. Use WAV or WebM/Opus.'];
        }

        $url = sprintf(
            'https://%s.stt.speech.microsoft.com/speech/recognition/conversation/cognitiveservices/v1?language=%s&format=detailed',
            $this->region,
            rawurlencode($locale)
        );

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => 'cURL initialisation failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $audioBytes,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: ' . $contentType,
                'Ocp-Apim-Subscription-Key: ' . $this->apiKey,
                'Accept: application/json',
                'User-Agent: LimeSurvey-AIInterview/1.12',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return ['error' => 'Network error contacting Azure Speech: ' . $curlError];
        }

        if ($response === false || $response === '') {
            return ['error' => 'Empty response from Azure Speech'];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['error' => 'Invalid JSON from Azure Speech (HTTP ' . $httpCode . ')'];
        }

        $status = (string) ($data['RecognitionStatus'] ?? '');
        if ($status !== 'Success') {
            $reason = $status !== '' ? $status : ('HTTP ' . $httpCode);
            return ['error' => 'Azure Speech recognition failed: ' . $reason];
        }

        $text = trim((string) ($data['DisplayText'] ?? ''));
        if ($text === '' && isset($data['NBest'][0]['Display'])) {
            $text = trim((string) $data['NBest'][0]['Display']);
        }

        $confidence = null;
        if (isset($data['NBest'][0]['Confidence'])) {
            $confidence = (float) $data['NBest'][0]['Confidence'];
        }

        $durationMs = null;
        if (isset($data['Duration'])) {
            // Azure returns duration in 100-nanosecond units
            $durationMs = (int) round(((int) $data['Duration']) / 10000);
        }

        return [
            'text'       => $text,
            'confidence' => $confidence,
            'durationMs' => $durationMs,
        ];
    }

    /**
     * Map short language codes from the plugin to Azure locales.
     */
    public static function localeFromLanguage(string $language): string
    {
        $lang = strtolower(preg_replace('/[^a-zA-Z\-]/', '', $language));

        if ($lang === 'pl' || strpos($lang, 'pl-') === 0) {
            return 'pl-PL';
        }

        return 'en-GB';
    }

    private function sanitizeLocale(string $locale): string
    {
        $locale = preg_replace('/[^a-zA-Z\-]/', '', $locale);
        return $locale !== '' ? $locale : 'en-GB';
    }

    /**
     * @return string|null Azure Content-Type header value
     */
    private function mapMimeToContentType(string $mimeType): ?string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType)[0]));

        switch ($mimeType) {
            case 'audio/wav':
            case 'audio/x-wav':
            case 'audio/wave':
                return 'audio/wav; codecs=audio/pcm; samplerate=16000';

            case 'audio/webm':
                return 'audio/webm; codecs=opus';

            case 'audio/ogg':
                return 'audio/ogg; codecs=opus';

            default:
                return null;
        }
    }
}
