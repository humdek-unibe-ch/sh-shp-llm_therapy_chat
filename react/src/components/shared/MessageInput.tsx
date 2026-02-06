/**
 * Message Input Component
 * =======================
 *
 * Redesigned input area matching the sh-shp-llm plugin pattern:
 *   - Textarea sits in a bordered container (no border on the textarea itself)
 *   - Action bar below textarea with: mic button | char count | send button
 *   - Speech-to-text inserts at cursor position (never overwrites)
 *   - Auto-grow up to 120 px then scroll
 *   - Recording animation (pulsing red) + processing spinner
 *
 * Bootstrap 4.6 classes + minimal custom CSS.
 */

import React, { useState, useCallback, useRef, useEffect } from 'react';

interface MessageInputProps {
  onSend: (message: string) => void;
  disabled?: boolean;
  placeholder?: string;
  buttonLabel?: string;
  /** Speech-to-text configuration */
  speechToTextEnabled?: boolean;
  sectionId?: number;
}

const MAX_LENGTH = 4000;
const MAX_RECORDING_MS = 60_000; // 60 s

export const MessageInput: React.FC<MessageInputProps> = ({
  onSend,
  disabled = false,
  placeholder = 'Type your message...',
  buttonLabel = 'Send',
  speechToTextEnabled = false,
  sectionId,
}) => {
  // ---- State ----
  const [text, setText] = useState('');
  const [isRecording, setIsRecording] = useState(false);
  const [isProcessingSpeech, setIsProcessingSpeech] = useState(false);
  const [speechError, setSpeechError] = useState<string | null>(null);

  // ---- Refs ----
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioStreamRef = useRef<MediaStream | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const messageRef = useRef(text);

  // Keep ref in sync
  useEffect(() => { messageRef.current = text; }, [text]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (audioStreamRef.current) {
        audioStreamRef.current.getTracks().forEach((t) => t.stop());
      }
      if (recordingTimeoutRef.current) {
        clearTimeout(recordingTimeoutRef.current);
      }
    };
  }, []);

  const isSpeechAvailable =
    speechToTextEnabled &&
    typeof navigator !== 'undefined' &&
    navigator.mediaDevices &&
    typeof navigator.mediaDevices.getUserMedia === 'function';

  // ---- Textarea helpers ----

  const autoResize = useCallback((el: HTMLTextAreaElement) => {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }, []);

  const handleInputChange = useCallback(
    (e: React.ChangeEvent<HTMLTextAreaElement>) => {
      setText(e.target.value);
      autoResize(e.target);
    },
    [autoResize],
  );

  // ---- Send ----

  const handleSend = useCallback(() => {
    const trimmed = text.trim();
    if (!trimmed || disabled) return;
    onSend(trimmed);
    setText('');
    if (textareaRef.current) {
      textareaRef.current.style.height = 'auto';
    }
  }, [text, disabled, onSend]);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSend();
      }
    },
    [handleSend],
  );

  // ===========================================================================
  //  Speech-to-Text  (matches sh-shp-llm plugin implementation)
  // ===========================================================================

  /**
   * Safely append transcribed text at cursor position.
   *
   * RULES (from LLM plugin):
   * 1. NEVER overwrite existing text
   * 2. ALWAYS append at the current cursor position
   * 3. ALWAYS move cursor to end of appended text
   * 4. ALWAYS add proper spacing
   */
  const appendTranscribedText = useCallback((transcribedText: string) => {
    const textarea = textareaRef.current;
    if (!textarea) return;

    const currentMessage = messageRef.current;
    const cursorPos = textarea.selectionStart ?? currentMessage.length;

    const textBefore = currentMessage.substring(0, cursorPos);
    const textAfter = currentMessage.substring(cursorPos);

    const needsSpaceBefore = textBefore.length > 0 && !/[\s]$/.test(textBefore);
    const spaceBefore = needsSpaceBefore ? ' ' : '';
    const trailingSpace = ' ';

    const newMessage = textBefore + spaceBefore + transcribedText + trailingSpace + textAfter;
    const newCursorPos =
      textBefore.length + spaceBefore.length + transcribedText.length + trailingSpace.length;

    setText(newMessage);
    messageRef.current = newMessage;

    requestAnimationFrame(() => {
      if (textarea) {
        textarea.focus();
        textarea.setSelectionRange(newCursorPos, newCursorPos);
        autoResize(textarea);
      }
    });
  }, [autoResize]);

  /**
   * Send audio blob to server for transcription.
   * Uses same endpoint pattern as the LLM plugin.
   */
  const processAudioBlob = useCallback(
    async (audioBlob: Blob) => {
      if (audioBlob.size === 0) {
        setSpeechError('No audio recorded');
        return;
      }

      setIsProcessingSpeech(true);
      setSpeechError(null);

      try {
        const fd = new FormData();
        fd.append('audio', audioBlob, 'recording.webm');
        fd.append('action', 'speech_transcribe');
        if (sectionId != null) fd.append('section_id', String(sectionId));

        const response = await fetch(window.location.href, {
          method: 'POST',
          body: fd,
        });
        const result = await response.json();

        if (result.success && result.text) {
          const trimmed = result.text.trim();
          if (trimmed) appendTranscribedText(trimmed);
        } else if (result.success && !result.text) {
          setSpeechError('No speech detected. Please try again.');
        } else {
          setSpeechError(result.error || 'Speech transcription failed');
        }
      } catch (err) {
        console.error('Speech processing error:', err);
        setSpeechError('Speech processing failed: ' + (err instanceof Error ? err.message : String(err)));
      } finally {
        setIsProcessingSpeech(false);
      }
    },
    [sectionId, appendTranscribedText],
  );

  const handleStartRecording = useCallback(async () => {
    if (!isSpeechAvailable || isRecording) return;
    setSpeechError(null);

    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, sampleRate: 16000 },
      });
      audioStreamRef.current = stream;
      audioChunksRef.current = [];

      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm')
          ? 'audio/webm'
          : 'audio/mp4';

      const recorder = new MediaRecorder(stream, { mimeType, audioBitsPerSecond: 16000 });
      mediaRecorderRef.current = recorder;

      recorder.ondataavailable = (ev) => {
        if (ev.data.size > 0) audioChunksRef.current.push(ev.data);
      };
      recorder.onstop = async () => {
        if (audioChunksRef.current.length > 0) {
          const blob = new Blob(audioChunksRef.current, { type: mimeType });
          await processAudioBlob(blob);
        }
        audioChunksRef.current = [];
      };

      recorder.start();
      setIsRecording(true);

      // Auto-stop after max duration
      recordingTimeoutRef.current = setTimeout(() => {
        if (mediaRecorderRef.current?.state === 'recording') handleStopRecording();
      }, MAX_RECORDING_MS);
    } catch (err) {
      console.error('Failed to start recording:', err);
      const msg = err instanceof Error ? err.message : String(err);
      setSpeechError(
        msg.includes('Permission denied') || msg.includes('NotAllowedError')
          ? 'Microphone access denied. Please allow microphone access in your browser settings.'
          : 'Failed to start recording: ' + msg,
      );
    }
  }, [isSpeechAvailable, isRecording, processAudioBlob]);

  const handleStopRecording = useCallback(() => {
    if (!isRecording || !mediaRecorderRef.current) return;

    if (recordingTimeoutRef.current) {
      clearTimeout(recordingTimeoutRef.current);
      recordingTimeoutRef.current = null;
    }
    if (mediaRecorderRef.current.state === 'recording') {
      mediaRecorderRef.current.stop();
    }
    if (audioStreamRef.current) {
      audioStreamRef.current.getTracks().forEach((t) => t.stop());
      audioStreamRef.current = null;
    }
    setIsRecording(false);
  }, [isRecording]);

  const handleMicClick = useCallback(() => {
    if (isRecording) handleStopRecording();
    else handleStartRecording();
  }, [isRecording, handleStartRecording, handleStopRecording]);

  // ---- Derived ----
  const charCount = text.length;
  const isNearLimit = charCount > MAX_LENGTH * 0.9;

  // ---- Render ----
  return (
    <div className="tc-input">
      {/* Speech error */}
      {speechError && (
        <div className="alert alert-warning alert-dismissible fade show py-1 px-2 mb-2 small" role="alert">
          <i className="fas fa-microphone-slash mr-1" />
          {speechError}
          <button type="button" className="close p-1" onClick={() => setSpeechError(null)}>
            <span>&times;</span>
          </button>
        </div>
      )}

      {/* Input container (border wraps textarea + action bar) */}
      <div className="tc-input-container border rounded">
        {/* Textarea */}
        <textarea
          ref={textareaRef}
          className="form-control border-0 tc-input-textarea"
          value={text}
          onChange={handleInputChange}
          onKeyDown={handleKeyDown}
          placeholder={placeholder}
          disabled={disabled}
          rows={1}
          maxLength={MAX_LENGTH}
          style={{ resize: 'none', minHeight: 44, maxHeight: 120 }}
        />

        {/* Action bar */}
        <div className="d-flex justify-content-between align-items-center px-2 py-1 border-top bg-light tc-input-actions">
          {/* Left: mic */}
          <div className="d-flex align-items-center">
            {isSpeechAvailable && (
              <button
                type="button"
                className={`btn btn-sm ${
                  isRecording
                    ? 'btn-danger tc-speech-recording-active'
                    : 'btn-outline-secondary'
                } tc-action-btn`}
                onClick={handleMicClick}
                disabled={disabled || isProcessingSpeech}
                title={isRecording ? 'Stop recording' : 'Start voice input'}
              >
                {isProcessingSpeech ? (
                  <i className="fas fa-spinner fa-spin" />
                ) : isRecording ? (
                  <i className="fas fa-stop" />
                ) : (
                  <i className="fas fa-microphone" />
                )}
              </button>
            )}
          </div>

          {/* Center: char count */}
          <small className={isNearLimit ? 'text-warning' : 'text-muted'}>
            {charCount}/{MAX_LENGTH}
          </small>

          {/* Right: send */}
          <button
            type="button"
            className="btn btn-primary btn-sm tc-action-btn tc-send-btn"
            onClick={handleSend}
            disabled={disabled || !text.trim()}
            title="Send message"
          >
            {disabled ? (
              <i className="fas fa-spinner fa-spin" />
            ) : (
              <i className="fas fa-paper-plane" />
            )}
          </button>
        </div>
      </div>
    </div>
  );
};

export default MessageInput;
