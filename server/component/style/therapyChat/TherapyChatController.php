<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../service/TherapyMessageService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

// Include LLM plugin services - only if LLM plugin is available
$llmDangerDetectionPath = __DIR__ . "/../../../../sh-shp-llm/server/service/LlmDangerDetectionService.php";

if (file_exists($llmDangerDetectionPath)) {
    require_once $llmDangerDetectionPath;
}

/**
 * Therapy Chat Controller (Subject/Patient)
 *
 * Handles API requests for the subject therapy chat.
 *
 * API Actions:
 * - send_message: Send a new message
 * - get_messages: Get messages for polling
 * - get_conversation: Get conversation data
 * - tag_therapist: Tag therapist(s) via alert system
 * - get_config: Get React configuration
 * - speech_transcribe: Transcribe audio
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatController extends BaseController
{
    /** @var TherapyMessageService */
    private $therapy_service;

    /** @var LlmDangerDetectionService|null */
    private $danger_service;

    /**
     * Constructor
     */
    public function __construct($model)
    {
        parent::__construct($model);

        if (!$this->isRequestForThisSection() || $model->get_services()->get_router()->current_keyword == 'admin') {
            return;
        }

        $this->initializeServices();
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

        return (int)$requested_section_id === (int)$model_section_id;
    }

    /**
     * Initialize services
     */
    private function initializeServices()
    {
        $services = $this->model->get_services();
        $this->therapy_service = new TherapyMessageService($services);

        if ($this->model->isDangerDetectionEnabled()) {
            $this->danger_service = new LlmDangerDetectionService($services, $this->model);
        }

        $this->setupJsonErrorHandler();
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
                $this->sendJsonResponse([
                    'error' => DEBUG ? "$errstr in $errfile:$errline" : 'An internal error occurred'
                ], 500);
            }
            return true;
        });

        set_exception_handler(function ($exception) {
            error_log("TherapyChat Exception: " . $exception->getMessage());
            $this->sendJsonResponse([
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
            default:
                break;
        }
    }

    /* =========================================================================
     * POST HANDLERS
     * ========================================================================= */

    /**
     * Send a message from the patient
     */
    private function handleSendMessage()
    {
        $user_id = $this->validatePatientOrFail();

        $message = trim($_POST['message'] ?? $_GET['message'] ?? '');
        if (empty($message)) {
            $this->sendJsonResponse(['error' => 'Message cannot be empty'], 400);
            return;
        }

        $conversation_id = $_POST['conversation_id'] ?? $_GET['conversation_id'] ?? null;
        if ($conversation_id) {
            $conversation_id = (int)$conversation_id;
        }

        // Danger detection
        if ($this->danger_service && $this->danger_service->isEnabled()) {
            $danger_result = $this->danger_service->checkMessage($message, $user_id, $conversation_id);

            if (!$danger_result['safe']) {
                $this->therapy_service->createDangerAlert(
                    $conversation_id,
                    $danger_result['detected_keywords'],
                    $message
                );

                $this->sendJsonResponse([
                    'blocked' => true,
                    'type' => 'danger_detected',
                    'message' => $this->model->getDangerBlockedMessage(),
                    'detected_keywords' => $danger_result['detected_keywords']
                ]);
                return;
            }
        }

        try {
            $conversation = null;
            if ($conversation_id) {
                $conversation = $this->therapy_service->getTherapyConversation($conversation_id);
            }

            if (!$conversation) {
                $conversation = $this->model->getOrCreateConversation();
                if (!$conversation) {
                    $this->sendJsonResponse(['error' => 'Could not create conversation'], 500);
                    return;
                }
            }
            $conversation_id = $conversation['id'];

            // Send user message
            $result = $this->therapy_service->sendTherapyMessage(
                $conversation_id,
                $user_id,
                $message,
                TherapyMessageService::SENDER_SUBJECT
            );

            if (isset($result['error'])) {
                $this->sendJsonResponse(['error' => $result['error']], 400);
                return;
            }

            $response = [
                'success' => true,
                'message_id' => $result['message_id'],
                'conversation_id' => $conversation_id
            ];

            // Process AI response if enabled
            $conversation = $this->therapy_service->getTherapyConversation($conversation_id);

            if ($conversation && $conversation['ai_enabled'] && $conversation['mode'] === THERAPY_MODE_AI_HYBRID) {
                $ai_response = $this->processAIResponse($conversation_id, $conversation);

                if ($ai_response && !isset($ai_response['error'])) {
                    $response['ai_message'] = [
                        'id' => $ai_response['message_id'],
                        'role' => 'assistant',
                        'content' => $ai_response['content'],
                        'sender_type' => 'ai',
                        'timestamp' => date('c')
                    ];
                }
            }

            $this->sendJsonResponse($response);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Process AI response
     */
    private function processAIResponse($conversation_id, $conversation)
    {
        $system_context = $this->model->getConversationContext();

        // Build AI context with therapist messages as high-priority
        $context_messages = $this->therapy_service->buildAIContext(
            $conversation_id,
            $system_context,
            50
        );

        // Add danger detection context if enabled
        if ($this->danger_service && $this->danger_service->isEnabled()) {
            $safety_context = $this->danger_service->getCriticalSafetyContext();
            if ($safety_context) {
                // Insert safety after system messages
                array_splice($context_messages, 2, 0, [
                    ['role' => 'system', 'content' => $safety_context]
                ]);
            }
        }

        return $this->therapy_service->processAIResponse(
            $conversation_id,
            $context_messages,
            $this->model->getLlmModel() ?: $conversation['model'],
            $this->model->getLlmTemperature(),
            $this->model->getLlmMaxTokens()
        );
    }

    /**
     * Handle tag therapist request -- creates alert via alert system
     */
    private function handleTagTherapist()
    {
        $user_id = $this->validatePatientOrFail();

        if (!$this->model->isTaggingEnabled()) {
            $this->sendJsonResponse(['error' => 'Tagging is disabled'], 400);
            return;
        }

        $conversation_id = $_POST['conversation_id'] ?? null;
        $reason = $_POST['reason'] ?? null;
        $urgency = $_POST['urgency'] ?? THERAPY_URGENCY_NORMAL;

        if (!in_array($urgency, THERAPY_VALID_URGENCIES)) {
            $urgency = THERAPY_URGENCY_NORMAL;
        }

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID is required'], 400);
            return;
        }

        try {
            $conversation = $this->therapy_service->getTherapyConversation($conversation_id);
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            // Build tag message
            $tag_message = "@therapist I would like to speak with my therapist";
            if ($reason) {
                $tagReasons = $this->model->getTagReasons();
                foreach ($tagReasons as $r) {
                    if ($r['key'] === $reason) {
                        $tag_message .= " #" . $r['key'] . ": " . $r['label'];
                        break;
                    }
                }
            }

            // Send the tag message as a patient message
            $msg_result = $this->therapy_service->sendTherapyMessage(
                $conversation_id,
                $user_id,
                $tag_message,
                TherapyMessageService::SENDER_SUBJECT
            );

            if (isset($msg_result['error'])) {
                $this->sendJsonResponse(['error' => $msg_result['error']], 400);
                return;
            }

            // Create tag alert (goes to all assigned therapists)
            $alertId = $this->therapy_service->createTagAlert(
                $conversation['id_llmConversations'],
                null,
                $reason,
                $urgency,
                $msg_result['message_id']
            );

            $this->sendJsonResponse([
                'success' => true,
                'message_id' => $msg_result['message_id'],
                'alert_id' => $alertId
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /* =========================================================================
     * GET HANDLERS
     * ========================================================================= */

    private function handleGetConfig()
    {
        $this->validatePatientOrFail();
        try {
            $config = $this->model->getReactConfig();
            $this->sendJsonResponse(['config' => $config]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetTherapists()
    {
        $user_id = $this->validatePatientOrFail();

        try {
            // Get therapists assigned to monitor this patient
            $therapists = $this->therapy_service->getTherapistsForPatient($user_id);

            $formatted = array();
            foreach ($therapists as $t) {
                $formatted[] = array(
                    'id' => (int)$t['id'],
                    'display' => $t['name'],
                    'name' => $t['name'],
                    'email' => $t['email'] ?? null
                );
            }

            $this->sendJsonResponse(['therapists' => $formatted]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetTagReasons()
    {
        $this->validatePatientOrFail();

        try {
            $tagReasons = $this->model->getTagReasons();

            $formatted = array();
            foreach ($tagReasons as $reason) {
                $formatted[] = array(
                    'code' => $reason['key'],
                    'label' => $reason['label'],
                    'urgency' => $reason['urgency'] ?? THERAPY_URGENCY_NORMAL
                );
            }

            $this->sendJsonResponse(['tag_reasons' => $formatted]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversation()
    {
        $user_id = $this->validatePatientOrFail();

        $conversation_id = $_GET['conversation_id'] ?? null;
        if ($conversation_id) {
            $conversation_id = (int)$conversation_id;
        }

        try {
            $conversation = null;

            if ($conversation_id) {
                $conversation = $this->therapy_service->getTherapyConversation($conversation_id);

                // Verify this is the patient's own conversation
                if ($conversation && (int)$conversation['id_users'] !== (int)$user_id) {
                    $this->sendJsonResponse(['error' => 'Access denied'], 403);
                    return;
                }
            }

            if (!$conversation) {
                $conversation = $this->model->getOrCreateConversation();
            }

            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Could not create or find conversation'], 500);
                return;
            }

            $messages = $this->therapy_service->getTherapyMessages($conversation['id']);
            $this->therapy_service->updateLastSeen($conversation['id'], 'subject');
            // Mark messages as seen so the floating badge clears
            $this->therapy_service->markMessagesAsSeen($conversation['id'], $user_id);

            $this->sendJsonResponse([
                'conversation' => $conversation,
                'messages' => $messages
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetMessages()
    {
        $user_id = $this->validatePatientOrFail();

        $conversation_id = $_GET['conversation_id'] ?? null;
        $after_id = isset($_GET['after_id']) ? (int)$_GET['after_id'] : null;

        if (!$conversation_id) {
            $conversation = $this->model->getOrCreateConversation();
            if ($conversation) {
                $conversation_id = $conversation['id'];
            }
        }

        if (!$conversation_id) {
            $this->sendJsonResponse(['messages' => []]);
            return;
        }

        try {
            $messages = $this->therapy_service->getTherapyMessages($conversation_id, 100, $after_id);
            $this->therapy_service->updateLastSeen($conversation_id, 'subject');
            // Mark messages as seen for this patient
            $this->therapy_service->markMessagesAsSeen($conversation_id, $user_id);

            $this->sendJsonResponse([
                'messages' => $messages,
                'conversation_id' => $conversation_id
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark all messages as read for the current patient in their conversation.
     * Also returns the remaining unread count so the frontend can update the
     * floating chat badge without a full page reload.
     */
    private function handleMarkMessagesRead()
    {
        $this->validatePatientOrFail();

        try {
            $userId = $this->model->getUserId();
            $conversationId = $_POST['conversation_id'] ?? null;

            if (!$conversationId) {
                // Fall back to the patient's active conversation
                $conversation = $this->model->getOrCreateConversation();
                $conversationId = $conversation['id'] ?? null;
            }

            if ($conversationId) {
                $this->therapy_service->markMessagesAsSeen($conversationId, $userId);
            }

            // Return remaining unread count (for badge update)
            $remaining = $this->therapy_service->getUnreadCountForUser($userId);

            $this->sendJsonResponse([
                'success' => true,
                'unread_count' => $remaining
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function handleSpeechTranscribe()
    {
        $this->validatePatientOrFail();

        if (!$this->model->isSpeechToTextEnabled()) {
            $this->sendJsonResponse(['error' => 'Speech-to-text is not enabled'], 400);
            return;
        }

        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            $this->sendJsonResponse(['error' => 'No audio file uploaded'], 400);
            return;
        }

        $audioFile = $_FILES['audio'];
        $tempPath = $audioFile['tmp_name'];

        $maxSize = 25 * 1024 * 1024;
        if ($audioFile['size'] > $maxSize) {
            $this->sendJsonResponse(['error' => 'Audio file too large (max 25MB)'], 400);
            return;
        }

        // Use browser-provided MIME type (same approach as sh-shp-llm plugin).
        // finfo_file() detects WebM containers as "video/webm" even when
        // they only contain audio tracks, so we rely on the upload type instead.
        $mimeType = $audioFile['type'] ?? '';
        $baseMime = explode(';', $mimeType)[0];

        $allowedTypes = [
            'audio/webm', 'audio/webm;codecs=opus',
            'audio/wav', 'audio/mp3', 'audio/mpeg',
            'audio/mp4', 'audio/ogg', 'audio/flac',
            'video/webm', // WebM containers with audio-only tracks
        ];
        if (!in_array($mimeType, $allowedTypes) && !in_array($baseMime, $allowedTypes)) {
            $this->sendJsonResponse([
                'error' => 'Invalid audio format: ' . $mimeType . '. Supported: WebM, WAV, MP3, OGG, FLAC'
            ], 400);
            return;
        }

        try {
            $llmSpeechServicePath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmSpeechToTextService.php";

            if (!file_exists($llmSpeechServicePath)) {
                $this->sendJsonResponse(['error' => 'Speech-to-text service not available.'], 500);
                return;
            }

            require_once $llmSpeechServicePath;

            $services = $this->model->get_services();
            $speechService = new LlmSpeechToTextService($services, $this->model);

            $model = $this->model->getSpeechToTextModel();
            $language = $this->model->getSpeechToTextLanguage();

            $result = $speechService->transcribeAudio(
                $tempPath,
                $model,
                $language !== 'auto' ? $language : null
            );

            if (isset($result['error'])) {
                $this->sendJsonResponse(['success' => false, 'error' => $result['error']], 500);
                return;
            }

            $this->sendJsonResponse([
                'success' => true,
                'text' => $result['text'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("TherapyChat speech transcription error: " . $e->getMessage());
            $this->sendJsonResponse([
                'success' => false,
                'error' => DEBUG ? $e->getMessage() : 'Speech transcription failed'
            ], 500);
        }
    }

    /* =========================================================================
     * VALIDATION HELPERS
     * ========================================================================= */

    private function validateUserOrFail()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            exit;
        }
        return $user_id;
    }

    private function validatePatientOrFail()
    {
        $user_id = $this->validateUserOrFail();

        if ($this->therapy_service && $this->therapy_service->isTherapist($user_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            exit;
        }

        return $user_id;
    }

    private function sendJsonResponse($data, $status_code = 200)
    {
        if (!headers_sent()) {
            http_response_code($status_code);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit;
    }
}
?>
