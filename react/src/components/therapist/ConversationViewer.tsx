/**
 * Conversation Viewer Component
 * ============================
 *
 * Displays the selected conversation with messages, input, and controls.
 * Handles message display, sending, and conversation management.
 */

import React from 'react';
import { MessageList } from '../shared/MessageList';
import { MessageInput } from '../shared/MessageInput';
import { LoadingIndicator } from '../shared/LoadingIndicator';
import { ConversationHeader } from './ConversationHeader';
import type {
  Conversation,
  TherapistDashboardLabels,
  TherapistFeatures,
  TherapistDashboardConfig,
} from '../../types';

// Define the chat state interface based on useChatState return type
export interface ChatState {
  conversation: Conversation | null;
  messages: any[];
  isLoading: boolean;
  isSending: boolean;
  error: string | null;
  loadConversation: (id?: number | string) => Promise<void>;
  sendMessage: (message: string) => Promise<void>;
  pollMessages: (afterId?: number) => Promise<void>;
  clearError: () => void;
  setError: (error: string | null) => void;
  setConversation: (conversation: Conversation | null) => void;
  setMessages: (messages: any[]) => void;
}

export interface ConversationViewerProps {
  conversation: Conversation;
  chat: ChatState;
  unreadCount: number;
  labels: TherapistDashboardLabels;
  features: TherapistFeatures;
  config: TherapistDashboardConfig;
  onMarkRead: () => void | Promise<void>;
  onCreateDraft: () => void | Promise<void>;
  onGenerateSummary: () => void | Promise<void>;
  draftModalOpen: boolean;
  summaryModalOpen: boolean;
}

/**
 * Conversation viewer with message display and input
 */
export const ConversationViewer: React.FC<ConversationViewerProps> = ({
  conversation,
  chat,
  unreadCount,
  labels,
  features,
  config,
  onMarkRead,
  onCreateDraft,
  onGenerateSummary,
  draftModalOpen,
  summaryModalOpen,
}) => {
  return (
    <div className="card border-0 shadow-sm h-100 d-flex flex-column">
      {/* Header – conversation context only; actions stay in right sidebar */}
      <ConversationHeader
        conversation={conversation}
        unreadCount={unreadCount}
        onMarkRead={onMarkRead}
        labels={labels}
        features={features}
      />

      {/* Error Display */}
      {chat.error && (
        <div className="alert alert-danger m-3 mb-0 alert-dismissible fade show" role="alert">
          {chat.error}
          <button type="button" className="close" onClick={chat.clearError}>
            <span>&times;</span>
          </button>
        </div>
      )}

      {/* Messages */}
      <div className="card-body p-0 flex-grow-1 d-flex flex-column overflow-hidden">
        <MessageList
          messages={chat.messages}
          isLoading={chat.isLoading}
          isTherapistView={true}
          currentUserId={config.userId}
          chatColors={config.chatColors}
          emptyText={labels.emptyMessage}
          senderLabels={{
            ai: labels.aiLabel,
            therapist: labels.therapistLabel,
            subject: labels.subjectLabel,
            system: 'System',
            you: 'You',
          }}
        />
        {chat.isSending && (
          <div className="px-3 pb-2">
            <LoadingIndicator text={labels.loading} />
          </div>
        )}
      </div>

      {/* Input and Actions */}
      <div className="card-footer bg-white py-2">
        {/* Action Buttons */}
        <div className="d-flex mb-2 tc-flex-gap-sm">
          <button 
            className="btn btn-outline-info btn-sm" 
            onClick={onCreateDraft} 
            disabled={draftModalOpen}
          >
            <i className="fas fa-magic mr-1" />
            Generate AI Draft
          </button>
          <button 
            className="btn btn-outline-secondary btn-sm" 
            onClick={onGenerateSummary} 
            disabled={summaryModalOpen}
          >
            <i className="fas fa-file-alt mr-1" />
            Summarize
          </button>
        </div>

        {/* Message Input */}
        <MessageInput
          onSend={chat.sendMessage}
          disabled={chat.isSending || chat.isLoading}
          placeholder={labels.sendPlaceholder}
          buttonLabel={labels.sendButton}
          speechToTextEnabled={config.speechToTextEnabled}
          sectionId={config.sectionId}
        />
      </div>
    </div>
  );
};

export default ConversationViewer;
