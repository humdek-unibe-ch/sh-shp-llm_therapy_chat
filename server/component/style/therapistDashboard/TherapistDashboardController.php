<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapist Dashboard Controller
 *
 * Thin controller: validates input and delegates to TherapistDashboardModel.
 * All business logic (LLM calls, DB operations, email notifications) lives in the model.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardController extends BaseController
{
    public function __construct($model)
    {
        parent::__construct($model);

        if (!$this->isRequestForThisSection() || $model->get_services()->get_router()->current_keyword == 'admin') {
            return;
        }

        $this->handleRequest();
    }

    private function isRequestForThisSection()
    {
        $requested = $_GET['section_id'] ?? $_POST['section_id'] ?? null;
        $model_id = $this->model->getSectionId();

        if ($requested === null) {
            $action = $_GET['action'] ?? $_POST['action'] ?? null;
            return $action === null;
        }

        return (int)$requested === (int)$model_id;
    }

    private function handleRequest()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? null;
            $this->handlePostRequest($action);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $action = $_GET['action'] ?? null;
            $this->handleGetRequest($action);
        }
    }

    private function handlePostRequest($action)
    {
        switch ($action) {
            case 'send_message': $this->handleSendMessage(); break;
            case 'edit_message': $this->handleEditMessage(); break;
            case 'delete_message': $this->handleDeleteMessage(); break;
            case 'toggle_ai': $this->handleToggleAI(); break;
            case 'set_risk': $this->handleSetRisk(); break;
            case 'set_status': $this->handleSetStatus(); break;
            case 'add_note': $this->handleAddNote(); break;
            case 'edit_note': $this->handleEditNote(); break;
            case 'delete_note': $this->handleDeleteNote(); break;
            case 'mark_alert_read': $this->handleMarkAlertRead(); break;
            case 'mark_all_read': $this->handleMarkAllRead(); break;
            case 'mark_messages_read': $this->handleMarkMessagesRead(); break;
            case 'create_draft': $this->handleCreateDraft(); break;
            case 'update_draft': $this->handleUpdateDraft(); break;
            case 'send_draft': $this->handleSendDraft(); break;
            case 'discard_draft': $this->handleDiscardDraft(); break;
            case 'speech_transcribe': $this->handleSpeechTranscribe(); break;
            case 'generate_summary': $this->handleGenerateSummary(); break;
        }
    }

    private function handleGetRequest($action)
    {
        switch ($action) {
            case 'get_config': $this->handleGetConfig(); break;
            case 'get_conversations': $this->handleGetConversations(); break;
            case 'get_conversation': $this->handleGetConversation(); break;
            case 'get_messages': $this->handleGetMessages(); break;
            case 'get_alerts': $this->handleGetAlerts(); break;
            case 'get_stats': $this->handleGetStats(); break;
            case 'get_notes': $this->handleGetNotes(); break;
            case 'get_unread_counts': $this->handleGetUnreadCounts(); break;
            case 'get_groups': $this->handleGetGroups(); break;
            case 'check_updates': $this->handleCheckUpdates(); break;
        }
    }

    /* =========================================================================
     * POST HANDLERS — validate input, delegate to model
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
        $enabled = isset($_POST['enabled']) ? (bool)$_POST['enabled'] : true;

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
            $this->json(['success' => true]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /* =========================================================================
     * DRAFT HANDLERS — validate input, delegate to model
     * ========================================================================= */

    private function handleCreateDraft()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;

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
     * GET HANDLERS — validate input, delegate to model
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
            if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
            if (isset($_GET['risk_level'])) $filters['risk_level'] = $_GET['risk_level'];
            if (isset($_GET['group_id'])) $filters['group_id'] = (int)$_GET['group_id'];
            $this->json(['conversations' => $this->model->getConversations($filters)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversation()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_GET['conversation_id'] ?? null;
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
        $cid = $_GET['conversation_id'] ?? null;
        $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : null;

        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }
        if (!$this->model->canAccessConversation($uid, $cid)) { $this->json(['error' => 'Access denied'], 403); return; }

        try {
            $messages = $this->model->getMessages($cid, 100, $afterId);
            // Mark messages as seen AND update last_seen timestamp.
            // Without markMessagesRead, polled messages stay is_new=1 and
            // unread counts never decrease while the conversation is open.
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
            if (isset($_GET['unread_only']) && $_GET['unread_only']) $filters['unread_only'] = true;
            if (isset($_GET['alert_type'])) $filters['alert_type'] = $_GET['alert_type'];
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
        $cid = $_GET['conversation_id'] ?? null;
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
        $cid = $_POST['conversation_id'] ?? null;
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

    private function handleSpeechTranscribe()
    {
        $this->validateTherapistOrFail();

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
            'audio/webm', 'audio/webm;codecs=opus', 'audio/wav', 'audio/mp3',
            'audio/mpeg', 'audio/mp4', 'audio/ogg', 'audio/flac', 'video/webm',
        ];
        if (!in_array($mimeType, $allowedTypes) && !in_array($baseMime, $allowedTypes)) {
            $this->json(['error' => 'Invalid audio format: ' . $mimeType], 400);
            return;
        }

        try {
            $result = $this->model->transcribeSpeech($audioFile['tmp_name']);
            if (isset($result['error'])) { $this->json($result, 500); return; }
            $this->json($result);
        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => defined('DEBUG') && DEBUG ? $e->getMessage() : 'Speech transcription failed'], 500);
        }
    }

    /* =========================================================================
     * HELPERS
     * ========================================================================= */

    private function validateTherapistOrFail()
    {
        $uid = $this->model->getUserId();
        if (!$uid) { $this->json(['error' => 'User not authenticated'], 401); exit; }
        if (!$this->model->hasAccess()) { $this->json(['error' => 'Access denied - therapist role required'], 403); exit; }
        return $uid;
    }

    private function json($data, $status = 200)
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit;
    }
}
?>
