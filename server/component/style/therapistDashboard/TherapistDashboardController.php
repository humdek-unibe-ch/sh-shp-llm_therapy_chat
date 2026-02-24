<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../TherapyBaseController.php";

/**
 * Therapist Dashboard Controller
 *
 * Thin controller: validates input, delegates to TherapistDashboardModel.
 * All business logic lives in the model.
 *
 * Shared infrastructure (JSON response, section routing, audio validation,
 * error handlers) is in TherapyBaseController.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardController extends TherapyBaseController
{
    public function __construct($model)
    {
        parent::__construct($model);

        if (!$this->isRequestForThisSection() || $model->get_services()->get_router()->current_keyword == 'admin') {
            return;
        }

        $this->setupJsonErrorHandler();
        $this->handleRequest();
    }

    /* =========================================================================
     * REQUEST ROUTING
     * ========================================================================= */

    private function handleRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? null;
            if ($action === null || $action === '') {
                return;
            }
            $this->handlePostRequest($action);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $action = $_GET['action'] ?? null;
            if ($action === null || $action === '') {
                return;
            }
            $this->handleGetRequest($action);
        }
    }

    private function handlePostRequest($action)
    {
        $data = $_POST;

        switch ($action) {
            case 'initialize_conversation':
                $this->handleConversationAction($action, $data);
                break;

            case 'send_message':
            case 'edit_message':
            case 'delete_message':
            case 'mark_messages_read':
                $this->handleMessageAction($action, $data);
                break;

            case 'add_note':
            case 'edit_note':
            case 'delete_note':
                $this->handleNoteAction($action, $data);
                break;

            case 'mark_alert_read':
            case 'mark_all_read':
                $this->handleAlertAction($action, $data);
                break;

            case 'create_draft':
            case 'update_draft':
            case 'send_draft':
            case 'discard_draft':
                $this->handleDraftAction($action, $data);
                break;

            case 'toggle_ai':
            case 'set_risk':
            case 'set_status':
                $this->handleControlAction($action, $data);
                break;

            case 'generate_summary':
                $this->handleGenerateSummary();
                break;

            case 'speech_transcribe':
                $this->validateTherapistOrFail();
                $this->processSpeechTranscription();
                break;

            case 'get_unread_counts':
            case 'check_updates':
                $this->handlePollingAction($action, $data);
                break;

            case 'get_conversations':
            case 'get_conversation':
                $this->handleConversationAction($action, $data);
                break;

            case 'get_messages':
                $this->handleGetMessages();
                break;

            case 'get_alerts':
                $this->handleGetAlerts();
                break;

            case 'get_stats':
                $this->handleGetStats();
                break;

            case 'get_notes':
                $this->handleGetNotes();
                break;

            case 'get_config':
                $this->handleGetConfig();
                break;

            case 'get_groups':
                $this->handleGetGroups();
                break;
        }
    }

    private function handleGetRequest($action)
    {
        $data = $_GET;

        switch ($action) {
            case 'get_config':
                $this->handleGetConfig();
                break;

            case 'get_conversations':
            case 'get_conversation':
                $this->handleConversationAction($action, $data);
                break;

            case 'get_messages':
                $this->handleGetMessages();
                break;

            case 'get_alerts':
                $this->handleAlertAction($action, $data);
                break;

            case 'get_stats':
                $this->handleGetStats();
                break;

            case 'get_notes':
                $this->handleGetNotes();
                break;

            case 'get_unread_counts':
            case 'check_updates':
                $this->handlePollingAction($action, $data);
                break;

            case 'get_groups':
                $this->handleGetGroups();
                break;

            case 'export_csv':
                $this->handleExportCsv();
                break;
        }
    }

    /* =========================================================================
     * ACTION GROUP HANDLERS
     * ========================================================================= */

    private function handleConversationAction($action, $data)
    {
        switch ($action) {
            case 'get_conversations':
                $this->handleGetConversations();
                break;
            case 'get_conversation':
                $this->handleGetConversation();
                break;
            case 'initialize_conversation':
                $this->handleInitializeConversation();
                break;
        }
    }

    private function handleMessageAction($action, $data)
    {
        switch ($action) {
            case 'send_message':
                $this->handleSendMessage();
                break;
            case 'edit_message':
                $this->handleEditMessage();
                break;
            case 'delete_message':
                $this->handleDeleteMessage();
                break;
            case 'mark_messages_read':
                $this->handleMarkMessagesRead();
                break;
        }
    }

    private function handleNoteAction($action, $data)
    {
        switch ($action) {
            case 'add_note':
                $this->handleAddNote();
                break;
            case 'edit_note':
                $this->handleEditNote();
                break;
            case 'delete_note':
                $this->handleDeleteNote();
                break;
        }
    }

    private function handleAlertAction($action, $data)
    {
        switch ($action) {
            case 'get_alerts':
                $this->handleGetAlerts();
                break;
            case 'mark_alert_read':
                $this->handleMarkAlertRead();
                break;
            case 'mark_all_read':
                $this->handleMarkAllRead();
                break;
        }
    }

    private function handleDraftAction($action, $data)
    {
        switch ($action) {
            case 'create_draft':
                $this->handleCreateDraft();
                break;
            case 'update_draft':
                $this->handleUpdateDraft();
                break;
            case 'send_draft':
                $this->handleSendDraft();
                break;
            case 'discard_draft':
                $this->handleDiscardDraft();
                break;
        }
    }

    private function handlePollingAction($action, $data)
    {
        switch ($action) {
            case 'check_updates':
                $this->handleCheckUpdates();
                break;
            case 'get_unread_counts':
                $this->handleGetUnreadCounts();
                break;
        }
    }

    private function handleControlAction($action, $data)
    {
        switch ($action) {
            case 'toggle_ai':
                $this->handleToggleAI();
                break;
            case 'set_risk':
                $this->handleSetRisk();
                break;
            case 'set_status':
                $this->handleSetStatus();
                break;
        }
    }

    /* =========================================================================
     * POST HANDLERS
     * ========================================================================= */

    private function handleSendMessage()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;
        $message = trim($_POST['message'] ?? '');

        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (empty($message)) { $this->json(['error' => 'Message cannot be empty'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $result = $this->model->sendMessage($cid, $uid, $message);
            if (isset($result['error'])) { $this->json(['error' => $result['error']], 400); return; }
            $this->json(['success' => true, 'message_id' => $result['message_id']]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleEditMessage()
    {
        $uid = $this->validateTherapistOrFail();
        $messageId = $_POST['message_id'] ?? null;
        $newContent = trim($_POST['content'] ?? '');

        if (!$messageId || empty($newContent)) { $this->json(['error' => 'Message ID and content are required'], 400); return; }

        try {
            $this->json(['success' => $this->model->editMessage($messageId, $uid, $newContent)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleDeleteMessage()
    {
        $uid = $this->validateTherapistOrFail();
        $messageId = $_POST['message_id'] ?? null;
        if (!$messageId) { $this->json(['error' => 'Message ID is required'], 400); return; }

        try {
            $this->json(['success' => $this->model->deleteMessage($messageId, $uid)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleToggleAI()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;
        $enabled = isset($_POST['enabled'])
            ? filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN)
            : true;

        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $this->json(['success' => $this->model->toggleAI($cid, $enabled), 'ai_enabled' => $enabled]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleSetRisk()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;
        $risk = $_POST['risk_level'] ?? null;

        if (!$cid || !$risk) { $this->json(['error' => 'Conversation ID and risk level are required'], 400); return; }
        if (!in_array($risk, THERAPY_VALID_RISK_LEVELS)) { $this->json(['error' => 'Invalid risk level'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $this->json(['success' => $this->model->setRiskLevel($cid, $risk), 'risk_level' => $risk]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleSetStatus()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;
        $status = $_POST['status'] ?? null;

        if (!$cid || !$status) { $this->json(['error' => 'Conversation ID and status are required'], 400); return; }
        if (!in_array($status, THERAPY_VALID_STATUSES)) { $this->json(['error' => 'Invalid status'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $this->json(['success' => $this->model->setStatus($cid, $status), 'status' => $status]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleAddNote()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;
        $content = trim($_POST['content'] ?? '');
        $noteType = $_POST['note_type'] ?? THERAPY_NOTE_MANUAL;

        if (!$cid || empty($content)) { $this->json(['error' => 'Conversation ID and content are required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $noteId = $this->model->addNote($cid, $uid, $content, $noteType);
            $this->json(['success' => (bool)$noteId, 'note_id' => $noteId]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleEditNote()
    {
        $uid = $this->validateTherapistOrFail();
        $noteId = $_POST['note_id'] ?? null;
        $content = trim($_POST['content'] ?? '');

        if (!$noteId || empty($content)) { $this->json(['error' => 'Note ID and content are required'], 400); return; }

        try {
            $this->json(['success' => $this->model->editNote($noteId, $uid, $content)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleDeleteNote()
    {
        $uid = $this->validateTherapistOrFail();
        $noteId = $_POST['note_id'] ?? null;
        if (!$noteId) { $this->json(['error' => 'Note ID is required'], 400); return; }

        try {
            $this->json(['success' => $this->model->deleteNote($noteId, $uid)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMarkAlertRead()
    {
        $this->validateTherapistOrFail();
        $alertId = $_POST['alert_id'] ?? null;
        if (!$alertId) { $this->json(['error' => 'Alert ID is required'], 400); return; }

        try {
            $this->json(['success' => $this->model->markAlertRead($alertId)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMarkAllRead()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;

        try {
            $this->json(['success' => $this->model->markAllAlertsRead($uid, $cid)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMarkMessagesRead()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;

        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $this->model->markMessagesRead($cid, $uid);
            $unreadCount = $this->model->getTherapyService()
                ->getUnreadCountForUser($uid, true);
            $this->json(['success' => true, 'unread_count' => $unreadCount]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ---- Drafts ---- */

    private function handleCreateDraft()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? $_GET['conversation_id'] ?? null;

        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $result = $this->model->generateDraft($cid, $uid);
            if (isset($result['error'])) { $this->json(['error' => $result['error']], 500); return; }
            $this->json($result);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleUpdateDraft()
    {
        $this->validateTherapistOrFail();
        $draftId = $_POST['draft_id'] ?? null;
        $editedContent = trim($_POST['edited_content'] ?? '');

        if (!$draftId) { $this->json(['error' => 'Draft ID is required'], 400); return; }

        try {
            $this->json(['success' => $this->model->updateDraft($draftId, $editedContent)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleSendDraft()
    {
        $uid = $this->validateTherapistOrFail();
        $draftId = $_POST['draft_id'] ?? null;
        $cid = $_POST['conversation_id'] ?? null;

        if (!$draftId || !$cid) { $this->json(['error' => 'Draft ID and conversation ID are required'], 400); return; }

        try {
            $this->json($this->model->sendDraft($draftId, $uid, $cid));
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleDiscardDraft()
    {
        $uid = $this->validateTherapistOrFail();
        $draftId = $_POST['draft_id'] ?? null;
        if (!$draftId) { $this->json(['error' => 'Draft ID is required'], 400); return; }

        try {
            $this->json(['success' => $this->model->discardDraft($draftId, $uid)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /* =========================================================================
     * GET HANDLERS
     * ========================================================================= */

    private function handleGetConfig()
    {
        $this->validateTherapistOrFail();
        try {
            $this->json(['config' => $this->model->getReactConfig()]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversations()
    {
        $this->validateTherapistOrFail();
        try {
            $filters = [];
            $status = $_POST['status'] ?? $_GET['status'] ?? null;
            $risk = $_POST['risk_level'] ?? $_GET['risk_level'] ?? null;
            $gid = $_POST['group_id'] ?? $_GET['group_id'] ?? null;
            if ($status) $filters['status'] = $status;
            if ($risk) $filters['risk_level'] = $risk;
            if ($gid) $filters['group_id'] = (int)$gid;
            $this->json(['conversations' => $this->model->getConversations($filters)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversation()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? $_GET['conversation_id'] ?? null;
        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $data = $this->model->loadFullConversation($cid, $uid);
            if (!$data) { $this->json(['error' => 'Conversation not found'], 404); return; }
            $this->json($data);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetMessages()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? $_GET['conversation_id'] ?? null;
        $afterId = isset($_POST['after_id']) ? (int)$_POST['after_id'] : (isset($_GET['after_id']) ? (int)$_GET['after_id'] : null);

        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $messages = $this->model->getMessages($cid, 100, $afterId);
            $this->model->markMessagesRead($cid, $uid);
            $this->json(['messages' => $messages, 'conversation_id' => $cid]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetAlerts()
    {
        $uid = $this->validateTherapistOrFail();
        try {
            $filters = [];
            $unread = $_POST['unread_only'] ?? $_GET['unread_only'] ?? null;
            $type = $_POST['alert_type'] ?? $_GET['alert_type'] ?? null;
            if ($unread) $filters['unread_only'] = true;
            if ($type) $filters['alert_type'] = $type;
            $this->json(['alerts' => $this->model->getAlerts($filters)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetStats()
    {
        $this->validateTherapistOrFail();
        try {
            $this->json(['stats' => $this->model->getStats()]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetNotes()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? $_GET['conversation_id'] ?? null;
        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $this->json(['notes' => $this->model->getNotes($cid)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetUnreadCounts()
    {
        $uid = $this->validateTherapistOrFail();
        try {
            $this->json(['unread_counts' => $this->model->getUnreadCounts($uid)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetGroups()
    {
        $this->validateTherapistOrFail();
        try {
            $this->json(['groups' => $this->model->getAssignedGroups()]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleCheckUpdates()
    {
        $uid = $this->validateTherapistOrFail();
        try {
            $this->json($this->model->checkUpdates($uid));
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGenerateSummary()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? $_GET['conversation_id'] ?? null;
        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $result = $this->model->generateSummary($cid, $uid);
            if (isset($result['error'])) { $this->json(['error' => $result['error']], 500); return; }
            $this->json($result);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleInitializeConversation()
    {
        $uid = $this->validateTherapistOrFail();
        $patientId = $_POST['patient_id'] ?? null;

        if (!$patientId) { $this->json(['error' => 'Patient ID is required'], 400); return; }

        try {
            $result = $this->model->initializeConversation((int)$patientId, $uid);
            if (isset($result['error'])) {
                $this->json(['error' => $result['error']], $result['status'] ?? 400);
                return;
            }
            $this->json($result);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /* ---- Export ---- */

    private function handleExportCsv()
    {
        $this->validateTherapistOrFail();

        $scope = $_GET['scope'] ?? 'patient';
        $conversationId = $_GET['conversation_id'] ?? null;
        $groupId = $_GET['group_id'] ?? null;

        if (!in_array($scope, array('patient', 'group', 'all'))) {
            $this->json(array('error' => 'Invalid scope'), 400);
            return;
        }

        try {
            $result = $this->model->exportConversations($scope, $conversationId, $groupId);

            if (isset($result['error'])) {
                $this->json(array('error' => $result['error']), 400);
                return;
            }

            // Clean any buffered output (whitespace from PHP close tags, etc.)
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: 0');

            $output = fopen('php://output', 'w');

            // Use semicolon delimiter for better Excel compatibility
            fputcsv($output, $result['headers'], ',');

            foreach ($result['rows'] as $row) {
                fputcsv($output, $row, ',');
            }

            fclose($output);
            exit;
        } catch (Exception $e) {
            $this->json(array('error' => $e->getMessage()), 500);
        }
    }

    /* =========================================================================
     * VALIDATION
     * ========================================================================= */

    private function validateTherapistOrFail()
    {
        $uid = $this->model->getUserId();
        if (!$uid) { $this->json(['error' => 'User not authenticated'], 401); exit; }
        if (!$this->model->hasAccess()) { $this->json(['error' => 'Access denied - therapist role required'], 403); exit; }
        return $uid;
    }
}
?>
