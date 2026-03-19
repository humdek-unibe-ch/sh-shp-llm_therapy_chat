/**
 * VoiceRecorder Component
 * =======================
 *
 * Speech-to-text recording: microphone button, recording state, and transcription.
 * Uses MediaRecorder API with silence detection for automatic transcription.
 *
 * Props: onTranscription callback, speechToTextEnabled flag, sectionId for API.
 */

import React, { useCallback, useRef, useEffect, useState } from 'react';

const MAX_RECORDING_MS = 60_000;
const SILENCE_THRESHOLD = 0.01;
const SILENCE_DURATION_MS = 2000;
const SILENCE_CHECK_INTERVAL_MS = 150;
const PREFERRED_AUDIO_MIME_TYPES = [
  'audio/webm;codecs=opus',
  'audio/webm',
  'audio/ogg;codecs=opus',
  'audio/mp4',
];
const MIC_CONSTRAINTS_FALLBACKS: MediaStreamConstraints[] = [
  {
    audio: {
      echoCancellation: true,
      noiseSuppression: true,
      channelCount: { ideal: 1 },
      sampleRate: { ideal: 24000 },
    },
  },
  {
    audio: {
      echoCancellation: true,
      noiseSuppression: true,
    },
  },
  { audio: true },
];

function getPreferredAudioMimeType(): string {
  for (const mime of PREFERRED_AUDIO_MIME_TYPES) {
    if (MediaRecorder.isTypeSupported(mime)) {
      return mime;
    }
  }
  return '';
}

function mimeToExtension(mimeType: string): string {
  const base = mimeType.split(';')[0].trim().toLowerCase();
  if (base === 'audio/mp4') return 'm4a';
  if (base === 'audio/ogg') return 'ogg';
  return 'webm';
}

async function requestMicrophoneStream(): Promise<MediaStream> {
  let lastError: unknown = null;

  for (const constraints of MIC_CONSTRAINTS_FALLBACKS) {
    try {
      return await navigator.mediaDevices.getUserMedia(constraints);
    } catch (err) {
      lastError = err;
      const name = err instanceof DOMException ? err.name : '';
      if (name !== 'OverconstrainedError' && name !== 'NotFoundError') {
        throw err;
      }
    }
  }

  throw lastError || new Error('No supported microphone constraints');
}

export interface VoiceRecorderProps {
  onTranscription: (text: string) => void;
  speechToTextEnabled: boolean;
  sectionId?: number;
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
  const silenceTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const audioContextRef = useRef<AudioContext | null>(null);
  const analyserRef = useRef<AnalyserNode | null>(null);
  const silenceStartRef = useRef<number>(0);
  const hasSpokenRef = useRef(false);

  const isSpeechAvailable =
    speechToTextEnabled &&
    typeof MediaRecorder !== 'undefined' &&
    typeof navigator !== 'undefined' &&
    navigator.mediaDevices &&
    typeof navigator.mediaDevices.getUserMedia === 'function';

  const cleanup = useCallback(() => {
    if (silenceTimerRef.current) {
      clearInterval(silenceTimerRef.current);
      silenceTimerRef.current = null;
    }
    if (audioContextRef.current) {
      audioContextRef.current.close().catch(() => {});
      audioContextRef.current = null;
      analyserRef.current = null;
    }
    if (audioStreamRef.current) {
      audioStreamRef.current.getTracks().forEach((t) => t.stop());
      audioStreamRef.current = null;
    }
    if (recordingTimeoutRef.current) {
      clearTimeout(recordingTimeoutRef.current);
      recordingTimeoutRef.current = null;
    }
  }, []);

  useEffect(() => cleanup, [cleanup]);

  const processAudioBlob = useCallback(
    async (audioBlob: Blob, mimeType: string) => {
      if (audioBlob.size === 0) {
        setSpeechError('No audio recorded');
        return;
      }

      setIsProcessingSpeech(true);
      setSpeechError(null);

      try {
        const extension = mimeToExtension(mimeType);
        const fd = new FormData();
        fd.append('audio', audioBlob, `recording.${extension}`);
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
    if (silenceTimerRef.current) {
      clearInterval(silenceTimerRef.current);
      silenceTimerRef.current = null;
    }
    if (audioContextRef.current) {
      audioContextRef.current.close().catch(() => {});
      audioContextRef.current = null;
      analyserRef.current = null;
    }
    if (recordingTimeoutRef.current) {
      clearTimeout(recordingTimeoutRef.current);
      recordingTimeoutRef.current = null;
    }
    if (mediaRecorderRef.current?.state === 'recording') {
      mediaRecorderRef.current.stop();
    }
    if (audioStreamRef.current) {
      audioStreamRef.current.getTracks().forEach((t) => t.stop());
      audioStreamRef.current = null;
    }
    setIsRecording(false);
  }, []);

  const startSilenceDetection = useCallback(
    (stream: MediaStream) => {
      try {
        const ctx = new (window.AudioContext || (window as any).webkitAudioContext)();
        const source = ctx.createMediaStreamSource(stream);
        const analyser = ctx.createAnalyser();
        analyser.fftSize = 512;
        source.connect(analyser);

        audioContextRef.current = ctx;
        analyserRef.current = analyser;
        silenceStartRef.current = 0;
        hasSpokenRef.current = false;

        const dataArray = new Float32Array(analyser.fftSize);

        silenceTimerRef.current = setInterval(() => {
          if (!analyserRef.current) return;
          analyserRef.current.getFloatTimeDomainData(dataArray);

          let rms = 0;
          for (let i = 0; i < dataArray.length; i++) {
            rms += dataArray[i] * dataArray[i];
          }
          rms = Math.sqrt(rms / dataArray.length);

          if (rms > SILENCE_THRESHOLD) {
            hasSpokenRef.current = true;
            silenceStartRef.current = 0;
          } else if (hasSpokenRef.current) {
            if (silenceStartRef.current === 0) {
              silenceStartRef.current = Date.now();
            } else if (Date.now() - silenceStartRef.current >= SILENCE_DURATION_MS) {
              handleStopRecording();
            }
          }
        }, SILENCE_CHECK_INTERVAL_MS);
      } catch {
        // Silence detection not available — user must stop manually
      }
    },
    [handleStopRecording],
  );

  const handleStartRecording = useCallback(async () => {
    if (!isSpeechAvailable || isRecording) return;
    setSpeechError(null);

    try {
      const mimeType = getPreferredAudioMimeType();
      if (!mimeType) {
        setSpeechError('No supported compressed audio format available.');
        return;
      }

      const stream = await requestMicrophoneStream();
      audioStreamRef.current = stream;
      audioChunksRef.current = [];

      const recorder = new MediaRecorder(stream, { mimeType, audioBitsPerSecond: 16000 });
      mediaRecorderRef.current = recorder;

      recorder.ondataavailable = (ev) => {
        if (ev.data.size > 0) audioChunksRef.current.push(ev.data);
      };
      recorder.onstop = async () => {
        if (audioChunksRef.current.length > 0) {
          const blob = new Blob(audioChunksRef.current, { type: mimeType });
          await processAudioBlob(blob, mimeType);
        }
        audioChunksRef.current = [];
      };

      recorder.start(250);
      setIsRecording(true);

      startSilenceDetection(stream);

      recordingTimeoutRef.current = setTimeout(() => {
        if (mediaRecorderRef.current?.state === 'recording') handleStopRecording();
      }, MAX_RECORDING_MS);
    } catch (err) {
      console.error('Failed to start recording:', err);
      const msg = err instanceof Error ? err.message : String(err);
      setSpeechError(
        msg.includes('Permission denied') || msg.includes('NotAllowedError')
          ? 'Microphone access denied. Please allow microphone access in your browser settings.'
          : msg.includes('OverconstrainedError')
            ? 'Microphone constraints not supported on this device. Retrying with safe defaults failed.'
          : 'Failed to start recording: ' + msg,
      );
    }
  }, [isSpeechAvailable, isRecording, processAudioBlob, handleStopRecording, startSilenceDetection]);

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
