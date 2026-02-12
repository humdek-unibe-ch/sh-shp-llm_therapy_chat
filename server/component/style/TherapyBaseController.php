<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../component/BaseController.php";
require_once __DIR__ . "/../../constants/TherapyLookups.php";

/**
 * Shared base controller for Therapy Chat controllers.
 *
 * Extracts duplicated logic from TherapyChatController and
 * TherapistDashboardController:
 *   - Section routing check
 *   - JSON response helper with activity logging
 *   - JSON error/exception handlers for AJAX mode
 *   - Audio upload validation for speech-to-text
 *
 * @package LLM Therapy Chat Plugin
 */
abstract class TherapyBaseController extends BaseController
{
    /** Allowed audio MIME types for speech-to-text */
    private const AUDIO_MIME_TYPES = [
        'audio/webm',
        'audio/webm;codecs=opus',
        'audio/wav',
        'audio/mp3',
        'audio/mpeg',
        'audio/mp4',
        'audio/ogg',
        'audio/flac',
        'video/webm',
    ];

    /** Max audio upload size (25 MB) */
    private const MAX_AUDIO_SIZE = 25 * 1024 * 1024;

    /* =========================================================================
     * SECTION ROUTING
     * ========================================================================= */

    /**
     * Check if the incoming request targets this component's section.
     *
     * When no section_id is sent AND no action is sent, the request is a
     * normal page load (not an AJAX call) — return true so the component
     * renders normally.
     */
    protected function isRequestForThisSection()
    {
        $requestedSectionId = $_GET['section_id'] ?? $_POST['section_id'] ?? null;
        $modelSectionId = $this->model->getSectionId();

        if ($requestedSectionId === null) {
            $action = $_GET['action'] ?? $_POST['action'] ?? null;
            return $action === null;
        }

        return (int)$requestedSectionId === (int)$modelSectionId;
    }

    /* =========================================================================
     * JSON RESPONSE
     * ========================================================================= */

    /**
     * Send a JSON response and terminate.
     * Logs user activity before exiting.
     */
    protected function json($data, $statusCode = 200)
    {
        $this->model->get_services()->get_router()->log_user_activity();

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit;
    }

    /* =========================================================================
     * ERROR HANDLERS
     * ========================================================================= */

    /**
     * Install error + exception handlers that return JSON for AJAX requests.
     * Only activates when an action parameter is present (or POST method).
     */
    protected function setupJsonErrorHandler()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        if ($action === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $controllerName = static::class;

        set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($controllerName) {
            error_log("$controllerName Error [$errno]: $errstr in $errfile:$errline");
            if (!(error_reporting() & $errno)) {
                return false;
            }
            if (in_array($errno, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
                $this->json([
                    'error' => (defined('DEBUG') && DEBUG) ? "$errstr in $errfile:$errline" : 'An internal error occurred'
                ], 500);
            }
            return true;
        });

        set_exception_handler(function ($exception) use ($controllerName) {
            error_log("$controllerName Exception: " . $exception->getMessage());
            $this->json([
                'error' => (defined('DEBUG') && DEBUG) ? $exception->getMessage() : 'An internal error occurred'
            ], 500);
        });
    }

    /* =========================================================================
     * AUDIO UPLOAD VALIDATION
     * ========================================================================= */

    /**
     * Validate an uploaded audio file for speech-to-text transcription.
     *
     * Checks:
     *  1. Model has speech-to-text enabled
     *  2. $_FILES['audio'] exists with no upload error
     *  3. File size within limit
     *  4. MIME type in whitelist
     *
     * Sends a JSON error and terminates on failure.
     *
     * @return string The temp path of the validated audio file
     */
    protected function validateAudioUpload()
    {
        if (!$this->model->isSpeechToTextEnabled()) {
            $this->json(['error' => 'Speech-to-text is not enabled'], 400);
        }

        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No audio file uploaded'], 400);
        }

        $audioFile = $_FILES['audio'];
        if ($audioFile['size'] > self::MAX_AUDIO_SIZE) {
            $this->json(['error' => 'Audio file too large (max 25MB)'], 400);
        }

        $mimeType = $audioFile['type'] ?? '';
        $baseMime = explode(';', $mimeType)[0];
        if (!in_array($mimeType, self::AUDIO_MIME_TYPES) && !in_array($baseMime, self::AUDIO_MIME_TYPES)) {
            $this->json([
                'error' => 'Invalid audio format: ' . $mimeType . '. Supported: WebM, WAV, MP3, OGG, FLAC'
            ], 400);
        }

        return $audioFile['tmp_name'];
    }

    /**
     * Full speech transcription handler: validate + delegate to model.
     * Subclasses call this from their handleSpeechTranscribe() method.
     */
    protected function processSpeechTranscription()
    {
        $tempPath = $this->validateAudioUpload();

        try {
            $result = $this->model->transcribeSpeech($tempPath);
            if (isset($result['error'])) {
                $this->json($result, 500);
                return;
            }
            $this->json($result);
        } catch (Exception $e) {
            error_log(static::class . " speech transcription error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'error' => (defined('DEBUG') && DEBUG) ? $e->getMessage() : 'Speech transcription failed'
            ], 500);
        }
    }
}
?>
