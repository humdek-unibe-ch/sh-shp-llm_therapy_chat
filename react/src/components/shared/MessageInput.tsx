/**
 * Message Input Component
 * =======================
 * 
 * Input area for composing and sending messages.
 * Supports @therapist mentions and #topic tagging using react-mentions.
 * Includes speech-to-text input via microphone button.
 * Built with Bootstrap 4.6 styling.
 */

import React, { useState, useCallback, useRef, useEffect } from 'react';
import { Button, Alert } from 'react-bootstrap';
import { MentionsInput, Mention, SuggestionDataItem } from 'react-mentions';
import type { TherapyChatLabels, TherapistDashboardLabels, TagReason, TagUrgency } from '../../types';
import './MessageInput.css';

// Types for therapist and topic suggestions
interface TherapistSuggestion extends SuggestionDataItem {
  id: string | number;
  display: string;
  email?: string;
  name?: string;
}

interface TopicSuggestion extends SuggestionDataItem {
  id: string | number;
  display: string;
  code?: string;
  urgency?: string;
}

interface MessageInputProps {
  onSend: (message: string, mentions?: MentionData) => void;
  disabled?: boolean;
  placeholder?: string;
  buttonLabel?: string;
  labels?: TherapyChatLabels | TherapistDashboardLabels;
  tagReasons?: TagReason[];
  onTagTherapist?: (reason?: string, urgency?: TagUrgency, therapistId?: number) => void;
  therapists?: TherapistSuggestion[];
  onLoadTherapists?: () => Promise<TherapistSuggestion[]>;
  maxLength?: number;
  // Speech-to-text configuration
  speechToTextEnabled?: boolean;
  speechToTextModel?: string;
  speechToTextLanguage?: string;
  sectionId?: number;
}

export interface MentionData {
  therapists: Array<{ id: string | number; display: string }>;
  topics: Array<{ id: string | number; display: string; code?: string; urgency?: string }>;
}

// Mention style configuration for react-mentions
const mentionStyle = {
  control: {
    fontSize: '1rem',
    lineHeight: '1.5',
    minHeight: '38px',
  },
  input: {
    margin: 0,
    padding: '0.375rem 0',
    border: 'none',
    outline: 'none',
  },
  highlighter: {
    padding: '0.375rem 0',
    border: 'none',
  },
  suggestions: {
    list: {
      backgroundColor: '#ffffff',
      border: '1px solid rgba(0, 0, 0, 0.15)',
      borderRadius: '0.25rem',
      boxShadow: '0 0.5rem 1rem rgba(0, 0, 0, 0.175)',
      fontSize: '0.875rem',
      maxHeight: '250px',
      overflowY: 'auto',
    },
    item: {
      padding: '0.5rem 1rem',
      '&focused': {
        backgroundColor: '#f8f9fa',
      },
    },
  },
};

// Maximum recording duration (60 seconds) to prevent payload too large errors
const MAX_RECORDING_DURATION_MS = 60000;

export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  disabled = false,
  placeholder,
  buttonLabel,
  labels,
  tagReasons = [],
  onTagTherapist,
  therapists: externalTherapists = [],
  onLoadTherapists,
  maxLength = 4000,
  speechToTextEnabled = false,
  speechToTextModel,
  speechToTextLanguage = 'auto',
  sectionId,
}) => {
  const [message, setMessage] = useState('');
  const [plainText, setPlainText] = useState('');
  const [therapistSuggestions, setTherapistSuggestions] = useState<TherapistSuggestion[]>(externalTherapists);
  const [isLoadingTherapists, setIsLoadingTherapists] = useState(false);
  const [mentions, setMentions] = useState<MentionData>({ therapists: [], topics: [] });
  const inputRef = useRef<HTMLTextAreaElement>(null);
  const labelsTyped = labels as (TherapyChatLabels & TherapistDashboardLabels) | undefined;
  
  // Speech-to-text state
  const [isRecording, setIsRecording] = useState(false);
  const [isProcessingSpeech, setIsProcessingSpeech] = useState(false);
  const [speechError, setSpeechError] = useState<string | null>(null);
  
  // Speech-to-text refs
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioStreamRef = useRef<MediaStream | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  
  // Ref to always have current message value (avoids stale closure issues)
  const messageRef = useRef<string>(message);
  
  // Keep messageRef in sync with message state
  useEffect(() => {
    messageRef.current = message;
  }, [message]);
  
  // Check if speech-to-text is available
  const isSpeechAvailable = speechToTextEnabled &&
    speechToTextModel &&
    typeof navigator !== 'undefined' &&
    navigator.mediaDevices &&
    typeof navigator.mediaDevices.getUserMedia === 'function';

  // Cleanup audio stream and timeout on unmount
  useEffect(() => {
    return () => {
      if (audioStreamRef.current) {
        audioStreamRef.current.getTracks().forEach(track => track.stop());
      }
      if (recordingTimeoutRef.current) {
        clearTimeout(recordingTimeoutRef.current);
      }
    };
  }, []);

  // Support both direct props and labels object
  const defaultPlaceholder = placeholder || labelsTyped?.placeholder || labelsTyped?.sendPlaceholder || 'Type your message...';
  const sendLabel = buttonLabel || labelsTyped?.send_button || labelsTyped?.sendButton || 'Send';

  // Convert tag reasons to topic suggestions
  const topicSuggestions: TopicSuggestion[] = tagReasons.map(reason => ({
    id: reason.code,
    display: reason.label,
    code: reason.code,
    urgency: reason.urgency,
  }));

  /**
   * Load therapists when @ is triggered
   * Includes special @therapists option to tag all therapists in group
   */
  const loadTherapists = useCallback(async (query: string, callback: (data: TherapistSuggestion[]) => void) => {
    // Create the "all therapists" option
    const allTherapistsOption: TherapistSuggestion = {
      id: 'all_therapists',
      display: 'All Therapists',
      name: 'All Therapists',
      email: 'Notify all therapists in your group'
    };

    // If we have external therapists or already loaded, filter them
    if (therapistSuggestions.length > 0 || externalTherapists.length > 0) {
      const source = therapistSuggestions.length > 0 ? therapistSuggestions : externalTherapists;
      
      // Debug: Log available therapists
      console.log('[TherapyChat] Available therapists for @mention:', source);
      
      // Start with "all therapists" option if it matches query
      const results: TherapistSuggestion[] = [];
      if ('all therapists'.includes(query.toLowerCase()) || 'therapists'.includes(query.toLowerCase())) {
        results.push(allTherapistsOption);
      }
      
      // Add filtered individual therapists
      const filtered = source.filter(t => 
        t.display.toLowerCase().includes(query.toLowerCase()) ||
        (t.email && t.email.toLowerCase().includes(query.toLowerCase()))
      );
      results.push(...filtered);
      
      console.log('[TherapyChat] Filtered therapists for query "' + query + '":', results);
      callback(results);
      return;
    }

    // Load therapists from API if callback provided
    if (onLoadTherapists && !isLoadingTherapists) {
      setIsLoadingTherapists(true);
      try {
        const loaded = await onLoadTherapists();
        console.log('[TherapyChat] Loaded therapists from API:', loaded);
        setTherapistSuggestions(loaded);
        
        // Start with "all therapists" option if it matches query
        const results: TherapistSuggestion[] = [];
        if ('all therapists'.includes(query.toLowerCase()) || 'therapists'.includes(query.toLowerCase())) {
          results.push(allTherapistsOption);
        }
        
        const filtered = loaded.filter(t => 
          t.display.toLowerCase().includes(query.toLowerCase()) ||
          (t.email && t.email.toLowerCase().includes(query.toLowerCase()))
        );
        results.push(...filtered);
        
        console.log('[TherapyChat] Filtered therapists for query "' + query + '":', results);
        callback(results);
      } catch (err) {
        console.error('[TherapyChat] Failed to load therapists:', err);
        callback([]);
      } finally {
        setIsLoadingTherapists(false);
      }
    } else {
      // Still show "all therapists" option even without loaded data
      if ('all therapists'.includes(query.toLowerCase()) || 'therapists'.includes(query.toLowerCase())) {
        console.log('[TherapyChat] No therapist data, showing "All Therapists" option');
        callback([allTherapistsOption]);
      } else {
        console.log('[TherapyChat] No therapist data available and query does not match "all therapists"');
        callback([]);
      }
    }
  }, [therapistSuggestions, externalTherapists, onLoadTherapists, isLoadingTherapists]);

  /**
   * Filter topics/tag reasons
   * Debug logging to browser console for troubleshooting
   */
  const loadTopics = useCallback((query: string, callback: (data: TopicSuggestion[]) => void) => {
    // Debug: Log all available tag reasons
    console.log('[TherapyChat] Available tag reasons (#):', topicSuggestions);
    
    const filtered = topicSuggestions.filter(t =>
      t.display.toLowerCase().includes(query.toLowerCase()) ||
      (t.code && t.code.toLowerCase().includes(query.toLowerCase()))
    );
    
    console.log('[TherapyChat] Filtered reasons for query "' + query + '":', filtered);
    callback(filtered);
  }, [topicSuggestions]);

  /**
   * Handle message change with mention tracking
   */
  const handleChange = useCallback((
    _event: { target: { value: string } },
    newValue: string,
    newPlainTextValue: string,
    mentionsList: Array<{ id: string | number; display: string; type?: string | null }>
  ) => {
    setMessage(newValue);
    setPlainText(newPlainTextValue);

    // Track mentions by type
    const therapistMentions = mentionsList
      .filter(m => m.type === 'therapist' || !m.type)
      .map(m => ({ id: m.id, display: m.display }));
    
    const topicMentions = mentionsList
      .filter(m => m.type === 'topic')
      .map(m => {
        const topic = topicSuggestions.find(t => t.id === m.id);
        return {
          id: m.id,
          display: m.display,
          code: topic?.code,
          urgency: topic?.urgency,
        };
      });

    setMentions({ therapists: therapistMentions, topics: topicMentions });
  }, [topicSuggestions]);

  /**
   * Handle form submission
   */
  const handleSubmit = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    
    const trimmedMessage = plainText.trim();
    if (trimmedMessage && !disabled && trimmedMessage.length <= maxLength) {
      // Check if there are therapist mentions - trigger tag callback
      if (mentions.therapists.length > 0 && onTagTherapist) {
        const topicWithUrgency = mentions.topics.find(t => t.urgency);
        onTagTherapist(
          topicWithUrgency?.code,
          topicWithUrgency?.urgency as TagUrgency | undefined,
          mentions.therapists[0]?.id as number
        );
      }
      
      onSend(trimmedMessage, mentions);
      setMessage('');
      setPlainText('');
      setMentions({ therapists: [], topics: [] });
    }
  }, [plainText, disabled, maxLength, mentions, onSend, onTagTherapist]);

  /**
   * Handle key press (Enter to send, Shift+Enter for new line)
   */
  const handleKeyDown = useCallback((e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSubmit(e as unknown as React.FormEvent);
    }
  }, [handleSubmit]);

  /**
   * Clear message
   */
  const handleClear = useCallback(() => {
    setMessage('');
    setPlainText('');
    setMentions({ therapists: [], topics: [] });
  }, []);

  /**
   * Update therapist suggestions when external prop changes
   */
  useEffect(() => {
    if (externalTherapists.length > 0) {
      setTherapistSuggestions(externalTherapists);
    }
  }, [externalTherapists]);

  // Character count styling
  const charCountClass = plainText.length > maxLength * 0.9 
    ? (plainText.length > maxLength ? 'text-danger' : 'text-warning')
    : '';

  // ===== Speech-to-Text Handlers =====

  /**
   * Safely append transcribed text to the message at cursor position
   */
  const appendTranscribedText = useCallback((transcribedText: string) => {
    // Get the CURRENT message from ref (not stale closure)
    const currentMessage = messageRef.current;
    const currentPlainText = plainText;
    
    // Determine spacing needed
    const needsSpaceBefore = currentPlainText.length > 0 && 
      !/[\s]$/.test(currentPlainText);
    
    // Build the new text with proper spacing
    const spaceBefore = needsSpaceBefore ? ' ' : '';
    const trailingSpace = ' ';
    const newPlainText = currentPlainText + spaceBefore + transcribedText + trailingSpace;
    
    // For mentions input, we work with plain text and let react-mentions handle markup
    // Append to the existing message value
    const newMessage = currentMessage + spaceBefore + transcribedText + trailingSpace;
    
    // Update state
    setMessage(newMessage);
    setPlainText(newPlainText);
    
    // Update ref immediately for any subsequent operations
    messageRef.current = newMessage;
  }, [plainText]);

  /**
   * Start recording audio from the microphone
   */
  const handleStartRecording = useCallback(async () => {
    if (!isSpeechAvailable || isRecording) return;

    setSpeechError(null);

    try {
      // Request microphone permission
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          sampleRate: 16000
        }
      });

      audioStreamRef.current = stream;
      audioChunksRef.current = [];

      // Create MediaRecorder with WebM/Opus format (widely supported)
      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm')
          ? 'audio/webm'
          : 'audio/mp4';

      // Configure MediaRecorder with lower bitrate for smaller file sizes
      const mediaRecorder = new MediaRecorder(stream, { 
        mimeType,
        audioBitsPerSecond: 16000 // 16 kbps - optimized for speech
      });
      mediaRecorderRef.current = mediaRecorder;

      mediaRecorder.ondataavailable = (event) => {
        if (event.data.size > 0) {
          audioChunksRef.current.push(event.data);
        }
      };

      mediaRecorder.onstop = async () => {
        // Process the recorded audio
        if (audioChunksRef.current.length > 0) {
          const audioBlob = new Blob(audioChunksRef.current, { type: mimeType });
          await processAudioBlob(audioBlob);
        }
        
        // Cleanup
        audioChunksRef.current = [];
      };

      // Start recording
      mediaRecorder.start();
      setIsRecording(true);
      
      // Auto-stop recording after max duration
      recordingTimeoutRef.current = setTimeout(() => {
        if (mediaRecorderRef.current && mediaRecorderRef.current.state === 'recording') {
          console.log('[TherapyChat] Auto-stopping recording after max duration');
          handleStopRecording();
        }
      }, MAX_RECORDING_DURATION_MS);

    } catch (error: unknown) {
      console.error('[TherapyChat] Failed to start recording:', error);
      const errorMessage = error instanceof Error ? error.message : 'Unknown error';
      
      if (errorMessage.includes('Permission denied') || errorMessage.includes('NotAllowedError')) {
        setSpeechError('Microphone access denied. Please allow microphone access in your browser settings.');
      } else {
        setSpeechError('Failed to start recording: ' + errorMessage);
      }
    }
  }, [isSpeechAvailable, isRecording]);

  /**
   * Stop recording and process the audio
   */
  const handleStopRecording = useCallback(() => {
    if (!isRecording || !mediaRecorderRef.current) return;

    // Clear the auto-stop timeout
    if (recordingTimeoutRef.current) {
      clearTimeout(recordingTimeoutRef.current);
      recordingTimeoutRef.current = null;
    }

    // Stop the MediaRecorder
    if (mediaRecorderRef.current.state === 'recording') {
      mediaRecorderRef.current.stop();
    }

    // Stop all tracks in the stream
    if (audioStreamRef.current) {
      audioStreamRef.current.getTracks().forEach(track => track.stop());
      audioStreamRef.current = null;
    }

    setIsRecording(false);
  }, [isRecording]);

  /**
   * Process the recorded audio blob and send to server for transcription
   */
  const processAudioBlob = useCallback(async (audioBlob: Blob) => {
    if (audioBlob.size === 0) {
      setSpeechError('No audio recorded');
      return;
    }

    setIsProcessingSpeech(true);
    setSpeechError(null);

    try {
      // Create form data for the API request
      const formData = new FormData();
      formData.append('audio', audioBlob, 'recording.webm');
      formData.append('action', 'speech_transcribe');
      if (sectionId) {
        formData.append('section_id', sectionId.toString());
      }
      if (speechToTextLanguage) {
        formData.append('language', speechToTextLanguage);
      }

      // Send to the server for transcription
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (result.success && result.text) {
        // Get the transcribed text (trimmed)
        const transcribedText = result.text.trim();
        
        if (transcribedText) {
          // Use the safe append function - NEVER overwrites, ALWAYS appends
          appendTranscribedText(transcribedText);
        }
      } else if (result.success && !result.text) {
        setSpeechError('No speech detected. Please try again.');
      } else {
        setSpeechError(result.error || 'Speech transcription failed');
      }

    } catch (error: unknown) {
      console.error('[TherapyChat] Speech processing error:', error);
      const errorMessage = error instanceof Error ? error.message : 'Unknown error';
      setSpeechError('Speech processing failed: ' + errorMessage);
    } finally {
      setIsProcessingSpeech(false);
    }
  }, [sectionId, appendTranscribedText]);

  /**
   * Toggle recording state
   */
  const handleMicrophoneClick = useCallback(() => {
    if (isRecording) {
      handleStopRecording();
    } else {
      handleStartRecording();
    }
  }, [isRecording, handleStartRecording, handleStopRecording]);

  // Render therapist suggestion item
  const renderTherapistSuggestion = (
    suggestion: SuggestionDataItem,
    _search: string,
    highlightedDisplay: React.ReactNode,
    _index: number,
    focused: boolean
  ) => {
    const therapist = suggestion as TherapistSuggestion;
    const isAllTherapists = therapist.id === 'all_therapists';
    
    return (
      <div className={`therapy-suggestion-item ${focused ? 'focused' : ''}`}>
        <div className={`suggestion-icon ${isAllTherapists ? 'all-therapists' : 'therapist'}`}>
          <i className={`fas ${isAllTherapists ? 'fa-users' : 'fa-user-md'}`}></i>
        </div>
        <div className="suggestion-content">
          <div className="suggestion-name">{highlightedDisplay}</div>
          {therapist.email && (
            <div className="suggestion-meta">{therapist.email}</div>
          )}
        </div>
      </div>
    );
  };

  // Render topic suggestion item
  const renderTopicSuggestion = (
    suggestion: SuggestionDataItem,
    _search: string,
    highlightedDisplay: React.ReactNode,
    _index: number,
    focused: boolean
  ) => {
    const topic = suggestion as TopicSuggestion;
    const urgencyBadge = topic.urgency === 'emergency' 
      ? 'badge-danger'
      : topic.urgency === 'urgent'
        ? 'badge-warning'
        : 'badge-secondary';

    return (
      <div className={`therapy-suggestion-item ${focused ? 'focused' : ''}`}>
        <div className="suggestion-icon topic">
          <i className="fas fa-hashtag"></i>
        </div>
        <div className="suggestion-content">
          <div className="suggestion-name">{highlightedDisplay}</div>
          {topic.urgency && (
            <span className={`badge ${urgencyBadge} ml-1`} style={{ fontSize: '0.65rem' }}>
              {topic.urgency}
            </span>
          )}
        </div>
      </div>
    );
  };

  return (
    <form onSubmit={handleSubmit} className="therapy-message-input">
      {/* Speech error alert */}
      {speechError && (
        <Alert 
          variant="warning" 
          dismissible 
          onClose={() => setSpeechError(null)}
          className="therapy-speech-error mb-2"
        >
          <i className="fas fa-exclamation-triangle mr-2"></i>
          {speechError}
        </Alert>
      )}
      
      <div className="therapy-input-container">
        <div className="therapy-input-wrapper">
          <div className="therapy-input-area">
            <MentionsInput
              value={message}
              onChange={handleChange}
              onKeyDown={handleKeyDown}
              placeholder={defaultPlaceholder}
              disabled={disabled || isProcessingSpeech}
              className="therapy-mentions-input"
              style={mentionStyle as any}
              inputRef={inputRef}
              allowSpaceInQuery
              a11ySuggestionsListLabel="Suggestions"
            >
              {/* Therapist mentions with @ trigger */}
              {/* @ts-ignore */}
              <Mention
                trigger="@"
                data={loadTherapists}
                markup="@[__display__](__id__)"
                displayTransform={(_id, display) => `@${display}`}
                className="therapy-mention-therapist"
                renderSuggestion={renderTherapistSuggestion}
                appendSpaceOnAdd
              />
              {/* Topic/reason mentions with # trigger */}
              {/* @ts-ignore */}
              <Mention
                trigger="#"
                data={loadTopics}
                markup="#[__display__](__id__)"
                displayTransform={(_id, display) => `#${display}`}
                className="therapy-mention-topic"
                renderSuggestion={renderTopicSuggestion}
                appendSpaceOnAdd
              />
            </MentionsInput>
          </div>
          
          {/* Character counter */}
          <div className="therapy-input-footer">
            <small className={`therapy-char-counter ${charCountClass}`}>
              {plainText.length}/{maxLength}
            </small>
          </div>
        </div>
        
        {/* Microphone button - only show when speech-to-text is available */}
        {isSpeechAvailable && (
          <Button
            type="button"
            variant={isRecording ? 'danger' : 'outline-secondary'}
            onClick={handleMicrophoneClick}
            disabled={disabled || isProcessingSpeech}
            className={`therapy-mic-button ${isRecording ? 'recording' : ''}`}
            title={isRecording ? 'Stop recording' : 'Start voice input'}
          >
            {isProcessingSpeech ? (
              <i className="fas fa-spinner fa-spin"></i>
            ) : isRecording ? (
              <i className="fas fa-stop"></i>
            ) : (
              <i className="fas fa-microphone"></i>
            )}
          </Button>
        )}
        
        {/* Clear button - only show when there's content */}
        {plainText.length > 0 && (
          <Button
            type="button"
            variant="outline-secondary"
            onClick={handleClear}
            className="therapy-clear-button"
            title="Clear"
          >
            <i className="fas fa-times"></i>
          </Button>
        )}
        
        {/* Send button */}
        <Button
          type="submit"
          variant="primary"
          disabled={disabled || isProcessingSpeech || !plainText.trim() || plainText.length > maxLength}
          title={sendLabel}
          className="therapy-send-button"
        >
          <i className="fas fa-paper-plane"></i>
          <span className="sr-only">{sendLabel}</span>
        </Button>
      </div>
    </form>
  );
};

export default MessageInput;
