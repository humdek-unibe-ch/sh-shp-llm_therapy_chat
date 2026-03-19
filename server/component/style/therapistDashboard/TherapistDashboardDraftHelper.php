<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php

/**
 * Therapist Dashboard Draft & Summary Helper
 *
 * Extracted from TherapistDashboardModel to keep files focused.
 * Contains AI draft generation and conversation summarization logic.
 *
 * @package LLM Therapy Chat Plugin
 */
trait TherapistDashboardDraftTrait
{
    /**
     * Generate an AI draft response for a conversation.
     *
     * Builds context from conversation history, calls the LLM API using
     * the model configured on this style, and saves both to llmMessages
     * (via the parent LLM plugin's addMessage) and to therapyDraftMessages.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @return array {success, draft: {id, ai_content, edited_content, status}} or {error}
     */
    public function generateDraft($conversationId, $therapistId)
    {
        // Build AI context from the conversation history
        $systemContext = $this->get_db_field('conversation_context', '');
        $contextMessages = $this->messageService->buildAIContext($conversationId, $systemContext, 50);

        // Add a draft-specific instruction using the configurable context field
        $draftContext = $this->get_db_field('therapy_draft_context', '');
        $draftInstruction = 'Generate a thoughtful, empathetic therapeutic response draft for the therapist to review and edit before sending to the patient. Focus on being supportive and clinically appropriate.';
        if (!empty($draftContext)) {
            $draftInstruction .= "\n\nAdditional context and instructions from the therapist:\n" . $draftContext;
        }
        $contextMessages[] = array(
            'role' => 'system',
            'content' => $draftInstruction
        );

        // Inject the unified JSON response schema so the LLM returns
        // structured JSON with safety assessment (same as patient chat).
        $contextMessages = $this->messageService->injectResponseSchema($contextMessages);

        // Get LLM config from style fields
        $model = $this->getLlmModel();
        $temperature = $this->getLlmTemperature();
        $maxTokens = $this->getLlmMaxTokens();

        // Get the tools conversation BEFORE calling the API so we can pass it
        // as log_options (callLlmApi auto-logs the assistant message).
        $toolsConvId = $this->messageService->getOrCreateTherapistToolsConversation(
            $therapistId, $this->getSectionId(), 'draft'
        );
        if (!$toolsConvId) {
            return array('error' => 'Failed to create tools conversation for draft.');
        }

        // Log the user prompt in the tools conversation
        $this->messageService->addMessage(
            $toolsConvId,
            'user',
            'Generate draft response for therapy conversation #' . $conversationId,
            null, null, null, null,
            array(
                'therapy_sender_type' => 'therapist',
                'draft_for_conversation' => $conversationId,
                'is_draft' => true
            )
        );

        // callLlmApi auto-logs the assistant message to llmMessages
        $response = $this->messageService->callLlmApi($contextMessages, $model, $temperature, $maxTokens, [
            'conversation_id' => $toolsConvId,
            'sent_context' => $contextMessages,
            'is_validated' => true
        ]);

        if (!$response || empty($response['content'])) {
            return array('error' => 'AI did not generate a response. Please try again.');
        }

        // Extract human-readable text from structured JSON response.
        $rawContent = $response['content'];
        $aiContent = $this->messageService->extractDisplayContent($rawContent);

        // Update the auto-logged message with human-readable content
        $messageId = $response['logged_message_id'] ?? null;
        if ($messageId) {
            $this->messageService->updateMessage($messageId, [
                'content' => $aiContent
            ]);
        }

        // Also save in therapyDraftMessages for draft workflow tracking
        $draftId = $this->messageService->createDraft($conversationId, $therapistId, $aiContent);

        if (!$draftId) {
            return array('error' => 'Failed to save draft to database. Check lookup values for therapyDraftStatus.');
        }

        return array(
            'success' => true,
            'draft' => array(
                'id' => (int)$draftId,
                'ai_content' => $aiContent,
                'edited_content' => null,
                'status' => THERAPY_DRAFT_DRAFT
            )
        );
    }

    /**
     * Update a draft's edited content
     */
    public function updateDraft($draftId, $editedContent)
    {
        return $this->messageService->updateDraft($draftId, $editedContent);
    }

    /**
     * Send a draft as a real message
     */
    public function sendDraft($draftId, $therapistId, $conversationId)
    {
        $result = $this->messageService->sendDraft($draftId, $therapistId, $conversationId);

        // Send email notification to patient when draft is sent
        if (isset($result['success'])) {
            $draft = $this->messageService->getActiveDraft($conversationId, $therapistId);
            $content = $draft ? ($draft['edited_content'] ?: $draft['ai_generated_content']) : '';
            $this->notifyPatientNewMessage($conversationId, $therapistId, $content);
        }

        return $result;
    }

    /**
     * Discard a draft
     */
    public function discardDraft($draftId, $therapistId)
    {
        return $this->messageService->discardDraft($draftId, $therapistId);
    }

    /**
     * Generate a conversation summary using LLM.
     * Uses the LLM model configured on this therapistDashboard style.
     * Saves to llmMessages for full audit trail.
     *
     * @param int $conversationId
     * @param int $therapistId
     * @return array {success, summary, summary_conversation_id, tokens_used} or {error}
     */
    public function generateSummary($conversationId, $therapistId)
    {
        // Get the customizable summarization context from the style field
        $summaryContext = $this->get_db_field('therapy_summary_context', '');

        // Build a complete conversation history for summarization
        $messages = $this->messageService->getTherapyMessages($conversationId, THERAPY_SUMMARY_MESSAGE_LIMIT);
        $conversation = $this->messageService->getTherapyConversation($conversationId);

        if (!$conversation) {
            return array('error' => 'Conversation not found');
        }

        // Build LLM messages for summarization
        $llmMessages = array();

        // System instruction for summarization
        $systemPrompt = "You are a clinical summarization assistant. Your task is to produce a concise, professional therapeutic summary of the conversation below.\n\n";
        if (!empty($summaryContext)) {
            $systemPrompt .= "Additional context and instructions from the therapist:\n" . $summaryContext . "\n\n";
        }
        $systemPrompt .= "Include: key topics discussed, patient emotional state, therapeutic interventions used, progress indicators, risk flags if any, and recommended next steps.";

        $llmMessages[] = array('role' => 'system', 'content' => $systemPrompt);

        // Add conversation history
        foreach ($messages as $msg) {
            if (!empty($msg['is_deleted'])) continue;

            $senderLabel = '';
            switch ($msg['sender_type'] ?? '') {
                case 'subject': $senderLabel = '[Patient]'; break;
                case 'therapist': $senderLabel = '[Therapist]'; break;
                case 'ai': $senderLabel = '[AI Assistant]'; break;
                case 'system': $senderLabel = '[System]'; break;
                default: $senderLabel = '[Unknown]';
            }

            $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
            $llmMessages[] = array(
                'role' => $role,
                'content' => $senderLabel . ' ' . $msg['content']
            );
        }

        // Final user prompt requesting the summary
        $llmMessages[] = array(
            'role' => 'user',
            'content' => 'Please generate a clinical summary of the above therapy conversation.'
        );

        // Inject the unified JSON response schema so the LLM returns
        // structured JSON with safety assessment (same as patient chat).
        $llmMessages = $this->messageService->injectResponseSchema($llmMessages);

        // Call LLM using the model configured on THIS style (therapistDashboard)
        $model = $this->getLlmModel();
        $temperature = $this->getLlmTemperature();
        $maxTokens = $this->getLlmMaxTokens();

        // Get the tools conversation BEFORE calling the API
        $toolsConvId = $this->messageService->getOrCreateTherapistToolsConversation(
            $therapistId, $this->getSectionId(), 'summary'
        );
        if (!$toolsConvId) {
            return array('error' => 'Failed to create tools conversation for summary.');
        }

        // Log the user prompt in the tools conversation
        $this->messageService->addMessage(
            $toolsConvId,
            'user',
            'Generate clinical summary for therapy conversation #' . $conversationId,
            null, null, null, null,
            array(
                'therapy_sender_type' => 'therapist',
                'summary_for_conversation' => $conversationId
            )
        );

        // callLlmApi auto-logs the assistant response
        $response = $this->messageService->callLlmApi($llmMessages, $model, $temperature, $maxTokens, [
            'conversation_id' => $toolsConvId,
            'sent_context' => $llmMessages,
            'is_validated' => true
        ]);

        if (!$response || empty($response['content'])) {
            return array('error' => 'AI did not generate a summary. Please try again.');
        }

        // Extract human-readable text from structured JSON response
        $rawContent = $response['content'];
        $displayContent = $this->messageService->extractDisplayContent($rawContent);

        // Update the auto-logged message with human-readable content
        $messageId = $response['logged_message_id'] ?? null;
        if ($messageId) {
            $this->messageService->updateMessage($messageId, [
                'content' => $displayContent
            ]);
        }

        return array(
            'success' => true,
            'summary' => $displayContent,
            'summary_conversation_id' => $toolsConvId,
            'tokens_used' => $response['tokens_used'] ?? null
        );
    }
}
