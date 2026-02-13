<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../TherapyBaseController.php";

/**
 * Therapy Chat Controller (Subject/Patient)
 *
 * Thin controller: validates input, delegates to TherapyChatModel.
 * All business logic lives in the model.
 *
 * Shared infrastructure (JSON response, section routing, audio validation,
 * error handlers) is in TherapyBaseController.
 *
 * @package LLM Therapy Chat Plugin
 */
class TherapyChatController extends TherapyBaseController
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
            $this->handlePostRequest($_POST['action'] ?? 'send_message');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->handleGetRequest($_GET['action'] ?? null);
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
                $this->validatePatientOrFail();
                $this->processSpeechTranscription();
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
            case 'get_config':       $this->handleGetConfig(); break;
            case 'get_conversation': $this->handleGetConversation(); break;
            case 'get_messages':     $this->handleGetMessages(); break;
            case 'get_therapists':   $this->handleGetTherapists(); break;
            case 'check_updates':    $this->handleCheckUpdates(); break;
            default: break;
        }
    }

    /* =========================================================================
     * POST HANDLERS
     * ========================================================================= */

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
            $conversationId = (int)$conversationId;
        }

        try {
            $result = $this->model->sendPatientMessage($userId, $message, $conversationId);

            if (isset($result['blocked']) || isset($result['error'])) {
                $statusCode = isset($result['blocked']) ? 200 : 500;
                $this->json($result, $statusCode);
                return;
            }

            $this->json($result);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

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
     * GET HANDLERS
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
            $this->json(['therapists' => $this->model->getFormattedTherapists($userId)]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetConversation()
    {
        $userId = $this->validatePatientOrFail();

        $conversationId = $_GET['conversation_id'] ?? null;
        if ($conversationId) {
            $conversationId = (int)$conversationId;
        }

        try {
            $therapyService = $this->model->getTherapyService();
            $conversation = null;

            if ($conversationId) {
                $conversation = $therapyService->getTherapyConversation($conversationId);
                if ($conversation && (int)$conversation['id_users'] !== (int)$userId) {
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
            $this->json([
                'latest_message_id' => $therapyService->getLatestMessageIdForConversation($cid),
                'unread_count' => (int)$therapyService->getUnreadCountForUser($userId)
            ]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    private function handleGetMessages()
    {
        $userId = $this->validatePatientOrFail();

        $conversationId = $_GET['conversation_id'] ?? null;
        $afterId = isset($_GET['after_id']) ? (int)$_GET['after_id'] : null;

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
            $messages = $therapyService->getTherapyMessages($conversationId, THERAPY_DEFAULT_MESSAGE_LIMIT, $afterId);
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
     * VALIDATION
     * ========================================================================= */

    private function validatePatientOrFail()
    {
        $userId = $this->model->getUserId();
        if (!$userId) {
            $this->json(['error' => 'User not authenticated'], 401);
            exit;
        }

        $therapyService = $this->model->getTherapyService();
        if ($therapyService && $therapyService->isTherapist($userId)) {
            $this->json(['error' => 'Access denied'], 403);
            exit;
        }

        return $userId;
    }
}
?>
