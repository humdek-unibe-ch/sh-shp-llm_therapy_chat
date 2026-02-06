<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../../../component/BaseController.php";
require_once __DIR__ . "/../../../service/TherapyMessageService.php";
require_once __DIR__ . "/../../../constants/TherapyLookups.php";

/**
 * Therapist Dashboard Controller
 *
 * Handles API requests for the therapist dashboard.
 * Uses TherapyMessageService (top-level) as single service entry point.
 *
 * API Actions:
 * - get_config, get_conversations, get_conversation, get_messages
 * - send_message, edit_message, delete_message
 * - toggle_ai, set_risk, set_status
 * - add_note, get_notes
 * - mark_alert_read, mark_all_read, get_alerts
 * - get_stats, get_unread_counts, mark_messages_read
 * - create_draft, update_draft, send_draft, discard_draft
 * - speech_transcribe
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapistDashboardController extends BaseController
{
    /** @var TherapyMessageService */
    private $service;

    public function __construct($model)
    {
        parent::__construct($model);

        if (!$this->isRequestForThisSection() || $model->get_services()->get_router()->current_keyword == 'admin') {
            return;
        }

        $this->service = new TherapyMessageService($model->get_services());
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

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $result = $this->service->sendTherapyMessage(
                $cid, $uid, $message, TherapyMessageService::SENDER_THERAPIST
            );

            if (isset($result['error'])) {
                $this->json(['error' => $result['error']], 400);
                return;
            }

            $this->service->updateLastSeen($cid, 'therapist');
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

        if (!$messageId || empty($newContent)) {
            $this->json(['error' => 'Message ID and content are required'], 400);
            return;
        }

        try {
            $result = $this->service->editMessage($messageId, $uid, $newContent);
            $this->json(['success' => $result]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleDeleteMessage()
    {
        $uid = $this->validateTherapistOrFail();

        $messageId = $_POST['message_id'] ?? null;
        if (!$messageId) {
            $this->json(['error' => 'Message ID is required'], 400);
            return;
        }

        try {
            $result = $this->service->softDeleteMessage($messageId, $uid);
            $this->json(['success' => $result]);
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

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $result = $this->service->setAIEnabled($cid, $enabled);
            $this->json(['success' => $result, 'ai_enabled' => $enabled]);
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

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $result = $this->service->updateRiskLevel($cid, $risk);
            $this->json(['success' => $result, 'risk_level' => $risk]);
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

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $result = $this->service->updateTherapyStatus($cid, $status);
            $this->json(['success' => $result, 'status' => $status]);
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

        if (!$cid || empty($content)) {
            $this->json(['error' => 'Conversation ID and content are required'], 400);
            return;
        }

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $noteId = $this->service->addNote($cid, $uid, $content, $noteType);
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

        if (!$noteId || empty($content)) {
            $this->json(['error' => 'Note ID and content are required'], 400);
            return;
        }

        try {
            $result = $this->service->updateNote($noteId, $uid, $content);
            $this->json(['success' => $result]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleDeleteNote()
    {
        $uid = $this->validateTherapistOrFail();

        $noteId = $_POST['note_id'] ?? null;
        if (!$noteId) {
            $this->json(['error' => 'Note ID is required'], 400);
            return;
        }

        try {
            $result = $this->service->softDeleteNote($noteId, $uid);
            $this->json(['success' => $result]);
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
            $result = $this->service->markAlertRead($alertId);
            $this->json(['success' => $result]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMarkAllRead()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;

        try {
            $result = $this->service->markAllAlertsRead($uid, $cid);
            $this->json(['success' => $result]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleMarkMessagesRead()
    {
        $uid = $this->validateTherapistOrFail();
        $cid = $_POST['conversation_id'] ?? null;

        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $this->service->updateLastSeen($cid, 'therapist');
            $this->service->markMessagesAsSeen($cid, $uid);
            $this->json(['success' => true]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /* =========================================================================
     * DRAFT HANDLERS
     * ========================================================================= */

    private function handleCreateDraft()
    {
        $uid = $this->validateTherapistOrFail();

        $cid = $_POST['conversation_id'] ?? null;
        $aiContent = trim($_POST['ai_content'] ?? '');

        if (!$cid || empty($aiContent)) {
            $this->json(['error' => 'Conversation ID and AI content are required'], 400);
            return;
        }

        try {
            $draftId = $this->service->createDraft($cid, $uid, $aiContent);
            $this->json(['success' => (bool)$draftId, 'draft_id' => $draftId]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleUpdateDraft()
    {
        $this->validateTherapistOrFail();

        $draftId = $_POST['draft_id'] ?? null;
        $editedContent = trim($_POST['edited_content'] ?? '');

        if (!$draftId) {
            $this->json(['error' => 'Draft ID is required'], 400);
            return;
        }

        try {
            $result = $this->service->updateDraft($draftId, $editedContent);
            $this->json(['success' => $result]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleSendDraft()
    {
        $uid = $this->validateTherapistOrFail();

        $draftId = $_POST['draft_id'] ?? null;
        $cid = $_POST['conversation_id'] ?? null;

        if (!$draftId || !$cid) {
            $this->json(['error' => 'Draft ID and conversation ID are required'], 400);
            return;
        }

        try {
            $result = $this->service->sendDraft($draftId, $uid, $cid);
            $this->json($result);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleDiscardDraft()
    {
        $uid = $this->validateTherapistOrFail();

        $draftId = $_POST['draft_id'] ?? null;
        if (!$draftId) {
            $this->json(['error' => 'Draft ID is required'], 400);
            return;
        }

        try {
            $result = $this->service->discardDraft($draftId, $uid);
            $this->json(['success' => $result]);
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
            $config = $this->model->getReactConfig();
            $this->json(['config' => $config]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversations()
    {
        $uid = $this->validateTherapistOrFail();

        try {
            $filters = [];
            if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
            if (isset($_GET['risk_level'])) $filters['risk_level'] = $_GET['risk_level'];
            if (isset($_GET['group_id'])) $filters['group_id'] = (int)$_GET['group_id'];

            $conversations = $this->service->getTherapyConversationsByTherapist($uid, $filters);
            $this->json(['conversations' => $conversations]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversation()
    {
        $uid = $this->validateTherapistOrFail();

        $cid = $_GET['conversation_id'] ?? null;
        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $conversation = $this->service->getTherapyConversation($cid);
            if (!$conversation) {
                $this->json(['error' => 'Conversation not found'], 404);
                return;
            }

            $messages = $this->service->getTherapyMessages($cid);
            $notes = $this->service->getNotesForConversation($cid);
            $alerts = $this->service->getAlertsForTherapist($uid, ['unread_only' => false]);

            $this->service->updateLastSeen($cid, 'therapist');
            $this->service->markMessagesAsSeen($cid, $uid);

            $this->json([
                'conversation' => $conversation,
                'messages' => $messages,
                'notes' => $notes,
                'alerts' => $alerts
            ]);
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

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $messages = $this->service->getTherapyMessages($cid, 100, $afterId);
            $this->service->updateLastSeen($cid, 'therapist');

            $this->json([
                'messages' => $messages,
                'conversation_id' => $cid
            ]);
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

            $alerts = $this->service->getAlertsForTherapist($uid, $filters);
            $this->json(['alerts' => $alerts]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetStats()
    {
        $uid = $this->validateTherapistOrFail();
        try {
            $stats = $this->service->getTherapistStats($uid);
            $this->json(['stats' => $stats]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetNotes()
    {
        $uid = $this->validateTherapistOrFail();

        $cid = $_GET['conversation_id'] ?? null;
        if (!$cid) { $this->json(['error' => 'Conversation ID is required'], 400); return; }

        if (!$this->service->canAccessTherapyConversation($uid, $cid)) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        try {
            $notes = $this->service->getNotesForConversation($cid);
            $this->json(['notes' => $notes]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetUnreadCounts()
    {
        $uid = $this->validateTherapistOrFail();

        try {
            $unreadMessages = $this->service->getUnreadCountForUser($uid);
            $unreadAlerts = $this->service->getUnreadAlertCount($uid);
            $bySubject = $this->service->getUnreadBySubjectForTherapist($uid);
            $byGroup = $this->service->getUnreadByGroupForTherapist($uid);

            $this->json([
                'unread_counts' => [
                    'total' => $unreadMessages,
                    'totalAlerts' => $unreadAlerts,
                    'bySubject' => $bySubject,
                    'byGroup' => $byGroup
                ]
            ]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetGroups()
    {
        $uid = $this->validateTherapistOrFail();

        try {
            $groups = $this->service->getTherapistAssignedGroups($uid);
            $this->json(['groups' => $groups]);
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
        $tempPath = $audioFile['tmp_name'];

        if ($audioFile['size'] > 25 * 1024 * 1024) {
            $this->json(['error' => 'Audio file too large (max 25MB)'], 400);
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
            'video/webm',
        ];
        if (!in_array($mimeType, $allowedTypes) && !in_array($baseMime, $allowedTypes)) {
            $this->json([
                'error' => 'Invalid audio format: ' . $mimeType . '. Supported: WebM, WAV, MP3, OGG, FLAC'
            ], 400);
            return;
        }

        try {
            $llmSpeechServicePath = __DIR__ . "/../../../../../sh-shp-llm/server/service/LlmSpeechToTextService.php";

            if (!file_exists($llmSpeechServicePath)) {
                $this->json(['error' => 'Speech-to-text service not available'], 500);
                return;
            }

            require_once $llmSpeechServicePath;

            $speechService = new LlmSpeechToTextService($this->model->get_services(), $this->model);

            $result = $speechService->transcribeAudio(
                $tempPath,
                $this->model->getSpeechToTextModel(),
                $this->model->getSpeechToTextLanguage() !== 'auto' ? $this->model->getSpeechToTextLanguage() : null
            );

            if (isset($result['error'])) {
                $this->json(['success' => false, 'error' => $result['error']], 500);
                return;
            }

            $this->json(['success' => true, 'text' => $result['text'] ?? '']);
        } catch (Exception $e) {
            $this->json(['success' => false, 'error' => DEBUG ? $e->getMessage() : 'Speech transcription failed'], 500);
        }
    }

    /* =========================================================================
     * HELPERS
     * ========================================================================= */

    private function validateTherapistOrFail()
    {
        $uid = $this->model->getUserId();
        if (!$uid) {
            $this->json(['error' => 'User not authenticated'], 401);
            exit;
        }

        if (!$this->model->hasAccess()) {
            $this->json(['error' => 'Access denied - therapist role required'], 403);
            exit;
        }

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
