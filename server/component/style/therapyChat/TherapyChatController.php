<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapy Chat Controller (Subject/Patient)
 *
 * Thin controller: validates input and delegates to TherapyChatModel.
 * All business logic (LLM calls, DB operations, danger detection, email
 * notifications) lives in the model.
 *
 * API Actions:
 * - send_message: Send a new message (delegates to model->sendPatientMessage)
 * - get_messages: Get messages for polling
 * - get_conversation: Get conversation data
 * - tag_therapist: Tag therapist(s) via alert system (delegates to model->tagTherapist)
 * - get_config: Get React configuration
 * - speech_transcribe: Transcribe audio (delegates to model->transcribeSpeech)
 * - mark_messages_read: Mark messages as read
 * - check_updates: Lightweight polling endpoint
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatController extends BaseController
{
    /**
     * Constructor
     */
    public function __construct($model)
    {
        parent::__construct($model);

        if (!$this->isRequestForThisSection() || $model->get_services()->get_router()->current_keyword == 'admin') {
            return;
        }

        $this->setupJsonErrorHandler();
        $this->handleRequest();
    }

    /**
     * Check if the incoming request is for this section
     */
    private function isRequestForThisSection()
    {
        $requested_section_id = $_GET['section_id'] ?? $_POST['section_id'] ?? null;
        $model_section_id = $this->model->getSectionId();

        if ($requested_section_id === null) {
            $action = $_GET['action'] ?? $_POST['action'] ?? null;
            return $action === null;
        }

        return (int) $requested_section_id === (int) $model_section_id;
    }

    /**
     * Set up error handler to return JSON for AJAX requests
     */
    private function setupJsonErrorHandler()
    {
        $action = $_GET['action'] ?? $_POST['action'] ?? null;
        if ($action === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            error_log("TherapyChat Error [$errno]: $errstr in $errfile:$errline");
            if (!(error_reporting() & $errno)) {
                return false;
            }
            if (in_array($errno, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR])) {
                $this->json([
                    'error' => DEBUG ? "$errstr in $errfile:$errline" : 'An internal error occurred'
                ], 500);
            }
            return true;
        });

        set_exception_handler(function ($exception) {
            error_log("TherapyChat Exception: " . $exception->getMessage());
            $this->json([
                'error' => DEBUG ? $exception->getMessage() : 'An internal error occurred'
            ], 500);
        });
    }

    /**
     * Route incoming request
     */
    private function handleRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? 'send_message';
            $this->handlePostRequest($action);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $action = $_GET['action'] ?? null;
            $this->handleGetRequest($action);
            return;
        }
    }

    private function handlePostRequest($action)
    {
        switch ($action) {
            case 'send_message':
                $this->handleSendMessage();
                break;
            case 'tag_therapist':
                $this->handleTagTherapist();
                break;
            case 'speech_transcribe':
                $this->handleSpeechTranscribe();
                break;
            case 'mark_messages_read':
                $this->handleMarkMessagesRead();
                break;
            default:
                if (isset($_POST['message'])) {
                    $this->handleSendMessage();
                }
                break;
        }
    }

    private function handleGetRequest($action)
    {
        switch ($action) {
            case 'get_config':
                $this->handleGetConfig();
                break;
            case 'get_conversation':
                $this->handleGetConversation();
                break;
            case 'get_messages':
                $this->handleGetMessages();
                break;
            case 'send_message':
                $this->handleSendMessage();
                break;
            case 'get_therapists':
                $this->handleGetTherapists();
                break;
            case 'get_tag_reasons':
                $this->handleGetTagReasons();
                break;
            case 'check_updates':
                $this->handleCheckUpdates();
                break;
            default:
                break;
        }
    }

    /* =========================================================================
     * POST HANDLERS — validate input, delegate to model
     * ========================================================================= */

    /**
     * Send a message from the patient.
     * All business logic (danger detection, AI response, email notification)
     * is handled by model->sendPatientMessage().
     */
    private function handleSendMessage()
    {
        $userId = $this->validatePatientOrFail();

        $message = trim($_POST['message'] ?? $_GET['message'] ?? '');
        if (empty($message)) {
            $this->json(['error' => 'Message cannot be empty'], 400);
            return;
        }

        $conversationId = $_POST['conversation_id'] ?? $_GET['conversation_id'] ?? null;
        if ($conversationId) {
            $conversationId = (int) $conversationId;
        }

        try {
            $result = $this->model->sendPatientMessage($userId, $message, $conversationId);

            if (isset($result['blocked'])) {
                $this->json($result);
                return;
            }
            if (isset($result['error'])) {
                $this->json(['error' => $result['error']], 500);
                return;
            }

            $this->json($result);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle tag therapist request.
     * Delegates to model->tagTherapist().
     */
    private function handleTagTherapist()
    {
        $userId = $this->validatePatientOrFail();

        if (!$this->model->isTaggingEnabled()) {
            $this->json(['error' => 'Tagging is disabled'], 400);
            return;
        }

        $conversationId = $_POST['conversation_id'] ?? null;
        $reason = $_POST['reason'] ?? null;
        $urgency = $_POST['urgency'] ?? THERAPY_URGENCY_NORMAL;

        if (!in_array($urgency, THERAPY_VALID_URGENCIES)) {
            $urgency = THERAPY_URGENCY_NORMAL;
        }

        if (!$conversationId) {
            $this->json(['error' => 'Conversation ID is required'], 400);
            return;
        }

        try {
            $result = $this->model->tagTherapist($userId, $conversationId, $reason, $urgency);
            if (isset($result['error'])) {
                $this->json(['error' => $result['error']], 400);
                return;
            }
            $this->json($result);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle speech transcription.
     * Validates audio file, delegates to model->transcribeSpeech().
     */
    private function handleSpeechTranscribe()
    {
        $this->validatePatientOrFail();

        if (!$this->model->isSpeechToTextEnabled()) {
            $this->json(['error' => 'Speech-to-text is not enabled'], 400);
            return;
        }

        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No audio file uploaded'], 400);
            return;
        }

        $audioFile = $_FILES['audio'];
        if ($audioFile['size'] > 25 * 1024 * 1024) {
            $this->json(['error' => 'Audio file too large (max 25MB)'], 400);
            return;
        }

        $mimeType = $audioFile['type'] ?? '';
        $baseMime = explode(';', $mimeType)[0];
        $allowedTypes = [
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
        if (!in_array($mimeType, $allowedTypes) && !in_array($baseMime, $allowedTypes)) {
            $this->json([
                'error' => 'Invalid audio format: ' . $mimeType . '. Supported: WebM, WAV, MP3, OGG, FLAC'
            ], 400);
            return;
        }

        try {
            $result = $this->model->transcribeSpeech($audioFile['tmp_name']);
            if (isset($result['error'])) {
                $this->json($result, 500);
                return;
            }
            $this->json($result);
        } catch (Exception $e) {
            error_log("TherapyChat speech transcription error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'error' => DEBUG ? $e->getMessage() : 'Speech transcription failed'
            ], 500);
        }
    }

    /**
     * Mark messages as read for the current patient.
     */
    private function handleMarkMessagesRead()
    {
        $this->validatePatientOrFail();

        try {
            $userId = $this->model->getUserId();
            $conversationId = $_POST['conversation_id'] ?? null;

            if (!$conversationId) {
                $conversation = $this->model->getOrCreateConversation();
                $conversationId = $conversation['id'] ?? null;
            }

            if ($conversationId) {
                $this->model->getTherapyService()->markMessagesAsSeen($conversationId, $userId);
            }

            $remaining = $this->model->getTherapyService()->getUnreadCountForUser($userId);
            $this->json(['success' => true, 'unread_count' => $remaining]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /* =========================================================================
     * GET HANDLERS — validate input, delegate to model
     * ========================================================================= */

    private function handleGetConfig()
    {
        $this->validatePatientOrFail();
        try {
            $this->json(['config' => $this->model->getReactConfig()]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetTherapists()
    {
        $userId = $this->validatePatientOrFail();

        try {
            $therapists = $this->model->getTherapyService()->getTherapistsForPatient($userId);
            $formatted = array();
            foreach ($therapists as $t) {
                $formatted[] = array(
                    'id' => (int) $t['id'],
                    'display' => $t['name'],
                    'name' => $t['name'],
                    'email' => $t['email'] ?? null
                );
            }
            $this->json(['therapists' => $formatted]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetTagReasons()
    {
        $this->validatePatientOrFail();

        try {
            $tagReasons = $this->model->getTagReasons();
            $formatted = array();
            if (is_array($tagReasons)) {
                foreach ($tagReasons as $reason) {
                    $formatted[] = array(
                        'code' => $reason['key'] ?? $reason['code'] ?? '',
                        'label' => $reason['label'] ?? '',
                        'urgency' => $reason['urgency'] ?? THERAPY_URGENCY_NORMAL
                    );
                }
            }
            $this->json(['tag_reasons' => $formatted]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversation()
    {
        $userId = $this->validatePatientOrFail();

        $conversationId = $_GET['conversation_id'] ?? null;
        if ($conversationId) {
            $conversationId = (int) $conversationId;
        }

        try {
            $therapyService = $this->model->getTherapyService();
            $conversation = null;

            if ($conversationId) {
                $conversation = $therapyService->getTherapyConversation($conversationId);

                if ($conversation && (int) $conversation['id_users'] !== (int) $userId) {
                    $this->json(['error' => 'Access denied'], 403);
                    return;
                }
            }

            if (!$conversation) {
                $conversation = $this->model->getOrCreateConversation();
            }

            if (!$conversation) {
                $this->json(['error' => 'Could not create or find conversation'], 500);
                return;
            }

            $messages = $therapyService->getTherapyMessages($conversation['id']);
            $therapyService->updateLastSeen($conversation['id'], 'subject');
            $therapyService->markMessagesAsSeen($conversation['id'], $userId);

            $this->json([
                'conversation' => $conversation,
                'messages' => $messages
            ]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleCheckUpdates()
    {
        $userId = $this->validatePatientOrFail();

        try {
            $therapyService = $this->model->getTherapyService();
            $conversation = $this->model->getOrCreateConversation();
            if (!$conversation) {
                $this->json(['latest_message_id' => null, 'unread_count' => 0]);
                return;
            }

            $cid = $conversation['id'];
            $latestId = $therapyService->getLatestMessageIdForConversation($cid);
            $unread = $therapyService->getUnreadCountForUser($userId);

            $this->json([
                'latest_message_id' => $latestId,
                'unread_count' => (int) $unread
            ]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetMessages()
    {
        $userId = $this->validatePatientOrFail();

        $conversationId = $_GET['conversation_id'] ?? null;
        $afterId = isset($_GET['after_id']) ? (int) $_GET['after_id'] : null;

        if (!$conversationId) {
            $conversation = $this->model->getOrCreateConversation();
            if ($conversation) {
                $conversationId = $conversation['id'];
            }
        }

        if (!$conversationId) {
            $this->json(['messages' => []]);
            return;
        }

        try {
            $therapyService = $this->model->getTherapyService();
            $messages = $therapyService->getTherapyMessages($conversationId, 100, $afterId);
            $therapyService->updateLastSeen($conversationId, 'subject');
            $therapyService->markMessagesAsSeen($conversationId, $userId);

            $this->json([
                'messages' => $messages,
                'conversation_id' => $conversationId
            ]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /* =========================================================================
     * VALIDATION HELPERS
     * ========================================================================= */

    private function validateUserOrFail()
    {
        $userId = $this->model->getUserId();
        if (!$userId) {
            $this->json(['error' => 'User not authenticated'], 401);
            exit;
        }
        return $userId;
    }

    private function validatePatientOrFail()
    {
        $userId = $this->validateUserOrFail();

        $therapyService = $this->model->getTherapyService();
        if ($therapyService && $therapyService->isTherapist($userId)) {
            $this->json(['error' => 'Access denied'], 403);
            exit;
        }

        return $userId;
    }

    private function json($data, $statusCode = 200)
    {
        // Log user activity before exiting so it is recorded in user_activity table.
        $this->model->get_services()->get_router()->log_user_activity();

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit;
    }
}
?>