/**
 * VoiceRecorder Component
 * =======================
 *
 * Speech-to-text recording: microphone button, recording state, and transcription.
 * Uses MediaRecorder API; sends audio to server for transcription.
 *
 * Props: onTranscription callback, speechToTextEnabled flag, sectionId for API.
 */

import React, { useCallback, useRef, useEffect, useState } from 'react';

const MAX_RECORDING_MS = 60_000; // 60 s

export interface VoiceRecorderProps {
  /** Called when transcription succeeds with the transcribed text */
  onTranscription: (text: string) => void;
  /** Whether speech-to-text is enabled */
  speechToTextEnabled: boolean;
  /** Section ID for the transcription API */
  sectionId?: number;
  /** Disable the microphone button (e.g. when sending) */
  disabled?: boolean;
}

export const VoiceRecorder: React.FC<VoiceRecorderProps> = ({
  onTranscription,
  speechToTextEnabled,
  sectionId,
  disabled = false,
}) => {
  const [isRecording, setIsRecording] = useState(false);
  const [isProcessingSpeech, setIsProcessingSpeech] = useState(false);
  const [speechError, setSpeechError] = useState<string | null>(null);

  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioStreamRef = useRef<MediaStream | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const isSpeechAvailable =
    speechToTextEnabled &&
    typeof navigator !== 'undefined' &&
    navigator.mediaDevices &&
    typeof navigator.mediaDevices.getUserMedia === 'function';

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
          if (trimmed) onTranscription(trimmed);
        } else if (result.success && !result.text) {
          setSpeechError('No speech detected. Please try again.');
        } else {
          setSpeechError(result.error || 'Speech transcription failed');
        }
      } catch (err) {
        console.error('Speech processing error:', err);
        setSpeechError(
          'Speech processing failed: ' + (err instanceof Error ? err.message : String(err)),
        );
      } finally {
        setIsProcessingSpeech(false);
      }
    },
    [sectionId, onTranscription],
  );

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
  }, [isSpeechAvailable, isRecording, processAudioBlob, handleStopRecording]);

  const handleMicClick = useCallback(() => {
    if (isRecording) handleStopRecording();
    else handleStartRecording();
  }, [isRecording, handleStartRecording, handleStopRecording]);

  if (!isSpeechAvailable) return null;

  return (
    <>
      {speechError && (
        <div className="alert alert-warning alert-dismissible fade show py-1 px-2 mb-2 small" role="alert">
          <i className="fas fa-microphone-slash mr-1" />
          {speechError}
          <button type="button" className="close p-1" onClick={() => setSpeechError(null)}>
            <span>&times;</span>
          </button>
        </div>
      )}
      <button
        type="button"
        className={`btn btn-sm ${
          isRecording ? 'btn-danger tc-speech-recording-active' : 'btn-outline-secondary'
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
    </>
  );
};

export default VoiceRecorder;
