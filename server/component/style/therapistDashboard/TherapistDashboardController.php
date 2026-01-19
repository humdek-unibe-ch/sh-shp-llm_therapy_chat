<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../service/TherapyTaggingService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapist Dashboard Controller
 * 
 * Handles API requests for the therapist dashboard.
 * 
 * API Actions:
 * - get_config: Get React configuration
 * - get_conversations: Get all conversations for therapist
 * - get_conversation: Get specific conversation with messages
 * - get_messages: Get messages for polling
 * - send_message: Send therapist message
 * - toggle_ai: Enable/disable AI for conversation
 * - set_risk: Set risk level
 * - add_note: Add private note
 * - acknowledge_tag: Acknowledge a patient tag
 * - mark_alert_read: Mark alert as read
 * - get_alerts: Get alerts for therapist
 * - get_stats: Get dashboard statistics
 * 
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardController extends BaseController
{
    /** @var TherapyTaggingService */
    private $therapy_service;

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

        // Validate section ID
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
        $this->therapy_service = new TherapyTaggingService($services);
    }

    /**
     * Route incoming request
     */
    private function handleRequest()
    {
        // Handle POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? null;
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
     * @param string|null $action
     */
    private function handlePostRequest($action)
    {
        switch ($action) {
            case 'send_message':
                $this->handleSendMessage();
                break;
            case 'toggle_ai':
                $this->handleToggleAI();
                break;
            case 'set_risk':
                $this->handleSetRisk();
                break;
            case 'add_note':
                $this->handleAddNote();
                break;
            case 'acknowledge_tag':
                $this->handleAcknowledgeTag();
                break;
            case 'mark_alert_read':
                $this->handleMarkAlertRead();
                break;
            case 'mark_all_read':
                $this->handleMarkAllRead();
                break;
            default:
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
            case 'get_conversations':
                $this->handleGetConversations();
                break;
            case 'get_conversation':
                $this->handleGetConversation();
                break;
            case 'get_messages':
                $this->handleGetMessages();
                break;
            case 'get_alerts':
                $this->handleGetAlerts();
                break;
            case 'get_tags':
                $this->handleGetTags();
                break;
            case 'get_stats':
                $this->handleGetStats();
                break;
            case 'get_notes':
                $this->handleGetNotes();
                break;
            default:
                break;
        }
    }

    /* POST Handlers **********************************************************/

    /**
     * Handle send message (therapist to subject)
     */
    private function handleSendMessage()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_POST['conversation_id'] ?? null;
        $message = trim($_POST['message'] ?? '');

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID is required'], 400);
            return;
        }

        if (empty($message)) {
            $this->sendJsonResponse(['error' => 'Message cannot be empty'], 400);
            return;
        }

        // Verify access
        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $result = $this->therapy_service->sendTherapyMessage(
                $conversation_id,
                $user_id,
                $message,
                'therapist'
            );

            if (isset($result['error'])) {
                $this->sendJsonResponse(['error' => $result['error']], 400);
                return;
            }

            // Update last seen
            $this->therapy_service->updateLastSeen($conversation_id, 'therapist');

            $this->sendJsonResponse([
                'success' => true,
                'message_id' => $result['message_id']
            ]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle toggle AI responses
     */
    private function handleToggleAI()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_POST['conversation_id'] ?? null;
        $enabled = isset($_POST['enabled']) ? (bool)$_POST['enabled'] : true;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID is required'], 400);
            return;
        }

        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $result = $this->therapy_service->setAIEnabled($conversation_id, $enabled);
            $this->sendJsonResponse(['success' => $result, 'ai_enabled' => $enabled]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle set risk level
     */
    private function handleSetRisk()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_POST['conversation_id'] ?? null;
        $risk_level = $_POST['risk_level'] ?? null;

        if (!$conversation_id || !$risk_level) {
            $this->sendJsonResponse(['error' => 'Conversation ID and risk level are required'], 400);
            return;
        }

        if (!in_array($risk_level, THERAPY_VALID_RISK_LEVELS)) {
            $this->sendJsonResponse(['error' => 'Invalid risk level'], 400);
            return;
        }

        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $result = $this->therapy_service->updateRiskLevel($conversation_id, $risk_level);
            $this->sendJsonResponse(['success' => $result, 'risk_level' => $risk_level]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle add note
     */
    private function handleAddNote()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_POST['conversation_id'] ?? null;
        $content = trim($_POST['content'] ?? '');

        if (!$conversation_id || empty($content)) {
            $this->sendJsonResponse(['error' => 'Conversation ID and content are required'], 400);
            return;
        }

        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $db = $this->model->get_services()->get_db();
            
            $note_id = $db->insert('therapyNotes', [
                'id_llmConversations' => $conversation_id,
                'id_users' => $user_id,
                'content' => $content
            ]);

            $this->sendJsonResponse([
                'success' => (bool)$note_id,
                'note_id' => $note_id
            ]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle acknowledge tag
     */
    private function handleAcknowledgeTag()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $tag_id = $_POST['tag_id'] ?? null;

        if (!$tag_id) {
            $this->sendJsonResponse(['error' => 'Tag ID is required'], 400);
            return;
        }

        try {
            $result = $this->therapy_service->acknowledgeTag($tag_id, $user_id);
            $this->sendJsonResponse(['success' => $result]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle mark alert read
     */
    private function handleMarkAlertRead()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $alert_id = $_POST['alert_id'] ?? null;

        if (!$alert_id) {
            $this->sendJsonResponse(['error' => 'Alert ID is required'], 400);
            return;
        }

        try {
            $result = $this->therapy_service->markAlertRead($alert_id, $user_id);
            $this->sendJsonResponse(['success' => $result]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle mark all alerts read
     */
    private function handleMarkAllRead()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_POST['conversation_id'] ?? null;

        try {
            $result = $this->therapy_service->markAllAlertsRead($user_id, $conversation_id);
            $this->sendJsonResponse(['success' => $result]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /* GET Handlers ***********************************************************/

    /**
     * Handle get config request
     */
    private function handleGetConfig()
    {
        $this->validateTherapistOrFail();

        try {
            $config = json_decode($this->model->get_view()->getReactConfig(), true);
            $this->sendJsonResponse(['config' => $config]);
        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get conversations
     */
    private function handleGetConversations()
    {
        $user_id = $this->validateTherapistOrFail();

        try {
            $filters = [];
            
            if (isset($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (isset($_GET['risk_level'])) {
                $filters['risk_level'] = $_GET['risk_level'];
            }
            if (isset($_GET['group_id'])) {
                $filters['group_id'] = (int)$_GET['group_id'];
            }

            $conversations = $this->therapy_service->getTherapyConversationsByTherapist($user_id, $filters);

            $this->sendJsonResponse(['conversations' => $conversations]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get conversation
     */
    private function handleGetConversation()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_GET['conversation_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID is required'], 400);
            return;
        }

        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $conversation = $this->therapy_service->getTherapyConversation($conversation_id);
            
            if (!$conversation) {
                $this->sendJsonResponse(['error' => 'Conversation not found'], 404);
                return;
            }

            $messages = $this->therapy_service->getTherapyMessages($conversation_id);
            $notes = $this->model->getNotes($conversation_id);
            $tags = $this->therapy_service->getTagsForConversation($conversation_id);
            $alerts = $this->therapy_service->getAlertsForConversation($conversation_id);

            // Update last seen
            $this->therapy_service->updateLastSeen($conversation_id, 'therapist');

            $this->sendJsonResponse([
                'conversation' => $conversation,
                'messages' => $messages,
                'notes' => $notes,
                'tags' => $tags,
                'alerts' => $alerts
            ]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get messages (for polling)
     */
    private function handleGetMessages()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_GET['conversation_id'] ?? null;
        $after_id = isset($_GET['after_id']) ? (int)$_GET['after_id'] : null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID is required'], 400);
            return;
        }

        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $messages = $this->therapy_service->getTherapyMessages($conversation_id, 100, $after_id);
            
            // Update last seen
            $this->therapy_service->updateLastSeen($conversation_id, 'therapist');

            $this->sendJsonResponse([
                'messages' => $messages,
                'conversation_id' => $conversation_id
            ]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get alerts
     */
    private function handleGetAlerts()
    {
        $user_id = $this->validateTherapistOrFail();

        try {
            $filters = [];
            
            if (isset($_GET['unread_only']) && $_GET['unread_only']) {
                $filters['unread_only'] = true;
            }
            if (isset($_GET['alert_type'])) {
                $filters['alert_type'] = $_GET['alert_type'];
            }

            $alerts = $this->therapy_service->getAlertsForTherapist($user_id, $filters);

            $this->sendJsonResponse(['alerts' => $alerts]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get pending tags
     */
    private function handleGetTags()
    {
        $user_id = $this->validateTherapistOrFail();

        try {
            $tags = $this->therapy_service->getPendingTagsForTherapist($user_id);
            $this->sendJsonResponse(['tags' => $tags]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get stats
     */
    private function handleGetStats()
    {
        $user_id = $this->validateTherapistOrFail();

        try {
            $stats = $this->therapy_service->getTherapistStats($user_id);
            $this->sendJsonResponse(['stats' => $stats]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle get notes
     */
    private function handleGetNotes()
    {
        $user_id = $this->validateTherapistOrFail();
        
        $conversation_id = $_GET['conversation_id'] ?? null;

        if (!$conversation_id) {
            $this->sendJsonResponse(['error' => 'Conversation ID is required'], 400);
            return;
        }

        if (!$this->therapy_service->canAccessTherapyConversation($user_id, $conversation_id)) {
            $this->sendJsonResponse(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $notes = $this->model->getNotes($conversation_id);
            $this->sendJsonResponse(['notes' => $notes]);

        } catch (Exception $e) {
            $this->sendJsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /* Helpers ****************************************************************/

    /**
     * Validate user is an authenticated therapist
     *
     * @return int User ID
     */
    private function validateTherapistOrFail()
    {
        $user_id = $this->model->getUserId();
        if (!$user_id) {
            $this->sendJsonResponse(['error' => 'User not authenticated'], 401);
            exit;
        }

        if (!$this->model->hasAccess()) {
            $this->sendJsonResponse(['error' => 'Access denied - therapist role required'], 403);
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
