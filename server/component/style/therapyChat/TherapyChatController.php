<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../service/TherapyTaggingService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

// Include LLM plugin services - only if LLM plugin is available
$llmDangerDetectionPath = __DIR__ . "/../../../../sh-shp-llm/server/service/LlmDangerDetectionService.php";

if (file_exists($llmDangerDetectionPath)) {
    require_once $llmDangerDetectionPath;
}

/**
 * Therapy Chat Controller
 * 
 * Handles API requests for the subject therapy chat.
 * 
 * API Actions:
 * - send_message: Send a new message
 * - get_messages: Get messages for polling
 * - get_conversation: Get conversation data
 * - tag_therapist: Tag the therapist
 * - get_config: Get React configuration
 * 
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatController extends BaseController
{
    /** @var TherapyTaggingService */
    private $therapy_service;

    /** @var LlmDangerDetectionService|null */
    private $danger_service;

    /** @var string|null Current action being processed */
    private $current_action;

    /**
     * Constructor
     *
     * @param object $model
     */
    public function __construct($model)
    {
        parent::__construct($model);

        // Validate section ID for multi-instance support
        if (!$this->isRequestForThisSection()) {
            return;
        }

        // Initialize services
        $this->initializeServices();

        // Route the request
        $this->handleRequest();
    }

    /**
     * Check if the incoming request is for this section
     *
     * @return bool
     */
    private function isRequestForThisSection()
    {
        $requested_section_id = $_GET['section_id'] ?? $_POST['section_id'] ?? null;
        $model_section_id = $this->model->getSectionId();

        if ($requested_section_id === null) {
            $action = $_GET['action'] ?? $_POST['action'] ?? null;
            return $action === null; // Allow page loads without section_id
        }

        return (int)$requested_section_id === (int)$model_section_id;
    }

    /**
     * Initialize services
     */
    private function initializeServices()
    {
        $services = $this->model->get_services();
        $this->therapy_service = new TherapyTaggingService($services);
        
        // Initialize danger detection if enabled
        if ($this->model->isDangerDetectionEnabled()) {
            $this->danger_service = new LlmDangerDetectionService($services, $this->model);
        }
    }

    /**
     * Route incoming request
     */
    private function handleRequest()
    {
        // Handle POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? 'send_message';
            $this->current_action = $action;
            $this->handlePostRequest($action);
            return;
        }

        // Handle GET requests
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $action = $_GET['action'] ?? null;
            $this->current_action = $action;
            $this->handleGetRequest($action);
            return;
        }
    }

    /**
     * Handle POST requests
     *
     * @param string $action
     */
    private function handlePostRequest($action)
    {
        switch ($action) {
            case 'send_message':
                $this->handleSendMessage();
                break;
            case 'tag_therapist':
                $this->handleTagTherapist();
                break;
            default:
                // Legacy: handle direct message POST
                if (isset($_POST['message'])) {
                    $this->handleSendMessage();
                }
                break;
        }
    }

    /**
     * Handle GET requests
     *
     * @param string|null $action
     */
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
            default:
                // Regular page load
                break;
        }
    }

    /**
     * Handle send message request
     */
    private function handleSendMessage()
    {
        $user_id = $this->validatePatientOrFail();
        
        $message = trim($_POST['message'] ?? '');
        if (empty($message)) {
            $this->sendJsonResponse(['error' => 'Message cannot be empty'], 400);
            return;
        }

        $conversation_id = $_POST['conversation_id'] ?? null;

        // Check danger detection
        if ($this->danger_service && $this->danger_service->isEnabled()) {
            $danger_result = $this->danger_service->checkMessage($message, $user_id, $conversation_id);
            
            if (!$danger_result['safe']) {
                // Create alert for therapist
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
            // Get or create conversation
            if (!$conversation_id) {
                $conversation = $this->model->getOrCreateConversation();
                if (!$conversation) {
                    $this->sendJsonResponse(['error' => 'Could not create conversation'], 500);
                    return;
                }
                $conversation_id = $conversation['id'];
            }

            // Send user message
            $result = $this->therapy_service->sendTherapyMessage(
                $conversation_id,
                $user_id,
                $message,
                'subject'
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
     *
     * @param int $conversation_id
     * @param array $conversation
     * @return array|null
     */
    private function processAIResponse($conversation_id, $conversation)
    {
        // Build context messages
        $context_messages = [];
        
        // Add system context
        $system_context = $this->model->getConversationContext();
        if ($system_context) {
            $context_messages[] = ['role' => 'system', 'content' => $system_context];
        }

        // Add therapy-specific instructions
        $context_messages[] = ['role' => 'system', 'content' => $this->getTherapySystemPrompt()];

        // Add danger detection context if enabled
        if ($this->danger_service && $this->danger_service->isEnabled()) {
            $safety_context = $this->danger_service->getCriticalSafetyContext();
            if ($safety_context) {
                $context_messages[] = ['role' => 'system', 'content' => $safety_context];
            }
        }

        // Add conversation history
        $messages = $this->therapy_service->getTherapyMessages($conversation_id, 50);
        foreach ($messages as $msg) {
            $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
            $context_messages[] = ['role' => $role, 'content' => $msg['content']];
        }

        // Get AI response
        return $this->therapy_service->processAIResponse(
            $conversation_id,
            $context_messages,
            $this->model->getLlmModel() ?: $conversation['model'],
            $this->model->getLlmTemperature(),
            $this->model->getLlmMaxTokens()
        );
    }

    /**
     * Get therapy-specific system prompt
     *
     * @return string
     */
    private function getTherapySystemPrompt()
    {
        return <<<PROMPT
You are a supportive AI assistant in a mental health therapy context.

Your role:
- Provide empathetic, non-judgmental responses
- Use evidence-based techniques like validation, reflection, and grounding
- Encourage the user while respecting their boundaries
- Suggest professional support when appropriate

Important boundaries:
- You are NOT a therapist or mental health professional
- You cannot provide diagnoses or treatment recommendations
- Always encourage users to speak with their assigned therapist for clinical matters
- If a user seems in crisis, encourage them to reach out to their therapist or emergency services

Keep responses warm, supportive, and focused on the user's emotional well-being.
PROMPT;
    }

    /**
     * Handle tag therapist request
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

        // Validate urgency
        if (!in_array($urgency, THERAPY_VALID_URGENCIES)) {
            $urgency = THERAPY_URGENCY_NORMAL;
        }

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID is required'], 400);
            return;
        }

        try {
            // Create tag message
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

            // Send the tag message
            $msg_result = $this->therapy_service->sendTherapyMessage(
                $conversation_id,
                $user_id,
                $tag_message,
                'subject'
            );

            if (isset($msg_result['error'])) {
                $this->sendJsonResponse(['error' => $msg_result['error']], 400);
                return;
            }

            // Create tag with alert
            $result = $this->therapy_service->tagConversationTherapist(
                $conversation_id,
                $msg_result['message_id'],
                $reason,
                $urgency
            );

            $this->sendJsonResponse($result);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get config request
     */
    private function handleGetConfig()
    {
        $this->validatePatientOrFail();

        try {
            $config = json_decode($this->model->get_view()->getReactConfig(), true);
            $this->sendJsonResponse(['config' => $config]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get conversation request
     */
    private function handleGetConversation()
    {
        $user_id = $this->validatePatientOrFail();
        
        $conversation_id = $_GET['conversation_id'] ?? null;

        try {
            if ($conversation_id) {
                $conversation = $this->therapy_service->getTherapyConversation($conversation_id);
            } else {
                $conversation = $this->model->getOrCreateConversation();
            }

            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            // Verify access
            if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation['id'])) {
                $this->sendJsonResponse(['error' => 'Access denied'], 403);
                return;
            }

            $messages = $this->therapy_service->getTherapyMessages($conversation['id']);

            $this->sendJsonResponse([
                'conversation' => $conversation,
                'messages' => $messages
            ]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get messages request (for polling)
     */
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

        // Verify access
        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $messages = $this->therapy_service->getTherapyMessages($conversation_id, 100, $after_id);
            
            // Update last seen
            $this->therapy_service->updateLastSeen($conversation_id, 'subject');

            $this->sendJsonResponse([
                'messages' => $messages,
                'conversation_id' => $conversation_id
            ]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate user is authenticated
     *
     * @return int User ID
     */
    private function validateUserOrFail()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            exit;
        }
        return $user_id;
    }

    /**
     * Validate user has patient access (not therapist)
     *
     * @return int User ID
     */
    private function validatePatientOrFail()
    {
        $user_id = $this->validateUserOrFail();

        // Check if user is a therapist (they shouldn't access patient chat)
        if ($this->therapy_service->isTherapist($user_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            exit;
        }

        return $user_id;
    }

    /**
     * Send JSON response
     *
     * @param array $data
     * @param int $status_code
     */
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
