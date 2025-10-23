import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Mic, Pause, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import axios from '@/lib/axios';

interface AudioRecorderProps {
    sessionId: number | null;
    onSessionCreated: (sessionId: number) => void;
    onTranscriptUpdate: (text: string) => void;
}

export function AudioRecorder({
    sessionId,
    onSessionCreated,
    onTranscriptUpdate,
}: AudioRecorderProps) {
    const [isRecording, setIsRecording] = useState(false);
    const [isPaused, setIsPaused] = useState(false);
    const [duration, setDuration] = useState(0);
    const [error, setError] = useState<string | null>(null);

    const mediaRecorderRef = useRef<MediaRecorder | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const chunksRef = useRef<Blob[]>([]);
    const startTimeRef = useRef<number>(0);
    const currentChunkStartRef = useRef<number>(0);
    const timerRef = useRef<NodeJS.Timeout | null>(null);
    const sessionIdRef = useRef<number | null>(sessionId);

    // Keep sessionIdRef in sync with sessionId prop
    useEffect(() => {
        sessionIdRef.current = sessionId;
        console.log('sessionId updated:', sessionId);
    }, [sessionId]);

    const formatTime = (seconds: number) => {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    };

    const startRecording = async () => {
        try {
            // Request microphone access
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    sampleRate: 44100,
                },
            });

            streamRef.current = stream;

            // Create MediaRecorder with appropriate MIME type
            const mimeType = MediaRecorder.isTypeSupported('audio/webm')
                ? 'audio/webm'
                : 'audio/mp4';

            const mediaRecorder = new MediaRecorder(stream, {
                mimeType,
            });

            mediaRecorderRef.current = mediaRecorder;
            chunksRef.current = [];
            startTimeRef.current = Date.now();
            currentChunkStartRef.current = 0;

            // Handle data available (chunk ready)
            mediaRecorder.ondataavailable = async (event) => {
                const currentSessionId = sessionIdRef.current;
                console.log('MediaRecorder data available:', {
                    size: event.data.size,
                    type: event.data.type,
                    sessionId: currentSessionId
                });

                if (!currentSessionId) {
                    console.error('Session ID not available when chunk ready');
                    return;
                }

                if (event.data.size > 0) {
                    chunksRef.current.push(event.data);

                    // Upload chunk to backend
                    const blob = event.data;
                    const chunkEnd = (Date.now() - startTimeRef.current) / 1000;

                    console.log('Uploading chunk:', {
                        size: blob.size,
                        startTime: currentChunkStartRef.current,
                        endTime: chunkEnd,
                        sessionId: currentSessionId
                    });

                    await uploadChunk(
                        blob,
                        currentChunkStartRef.current,
                        chunkEnd,
                    );

                    currentChunkStartRef.current = chunkEnd;
                } else {
                    console.warn('MediaRecorder data available but size is 0');
                }
            };

            // Start recording with 10-second chunks
            console.log('Starting MediaRecorder with 10-second chunks', {
                sessionIdProp: sessionId,
                sessionIdRef: sessionIdRef.current
            });
            mediaRecorder.start(10000);

            console.log('MediaRecorder started, state:', mediaRecorder.state);

            setIsRecording(true);
            setIsPaused(false);
            setError(null);

            // Start timer
            timerRef.current = setInterval(() => {
                setDuration((prev) => prev + 1);
            }, 1000);
        } catch (err) {
            console.error('Error starting recording:', err);
            setError(
                'Could not access microphone. Please check your permissions.',
            );
        }
    };

    const pauseRecording = () => {
        if (mediaRecorderRef.current && isRecording) {
            mediaRecorderRef.current.pause();
            setIsPaused(true);
            if (timerRef.current) {
                clearInterval(timerRef.current);
            }
        }
    };

    const resumeRecording = () => {
        if (mediaRecorderRef.current && isPaused) {
            mediaRecorderRef.current.resume();
            setIsPaused(false);
            timerRef.current = setInterval(() => {
                setDuration((prev) => prev + 1);
            }, 1000);
        }
    };

    const stopRecording = () => {
        if (mediaRecorderRef.current) {
            mediaRecorderRef.current.stop();
            setIsRecording(false);
            setIsPaused(false);

            if (timerRef.current) {
                clearInterval(timerRef.current);
            }

            // Stop all tracks
            if (streamRef.current) {
                streamRef.current.getTracks().forEach((track) => track.stop());
            }
        }
    };

    const uploadChunk = async (
        blob: Blob,
        startTime: number,
        endTime: number,
    ) => {
        if (!sessionId) {
            console.error('Cannot upload chunk: sessionId is null');
            setError('Recording session not initialized. Please try again.');
            return;
        }

        console.log('uploadChunk called with sessionId:', sessionId);

        const formData = new FormData();
        formData.append('file', blob, 'chunk.webm');
        formData.append('start_time', startTime.toString());
        formData.append('end_time', endTime.toString());

        console.log('FormData created:', {
            fileSize: blob.size,
            startTime,
            endTime,
            fileName: 'chunk.webm'
        });

        try {
            // Let axios automatically set Content-Type with boundary for FormData
            const response = await axios.post(
                `/api/transcribe/sessions/${sessionId}/chunks`,
                formData
            );

            console.log('Chunk uploaded successfully:', response.data);

            // Poll for transcript update with retry logic
            startPollingForTranscript();
        } catch (err: any) {
            console.error('Error uploading chunk:', err);
            const errorMessage = err.response?.data?.message || err.response?.data?.error || 'Failed to upload audio chunk';
            console.error('Full error response:', err.response?.data);
            setError(`Upload failed: ${errorMessage}`);
        }
    };

    const startPollingForTranscript = () => {
        let pollAttempts = 0;
        const maxAttempts = 10; // Poll for up to 30 seconds (10 attempts * 3 seconds)

        const poll = async () => {
            if (!sessionId || pollAttempts >= maxAttempts) return;

            try {
                const response = await axios.get(
                    `/api/transcribe/sessions/${sessionId}/transcript`
                );
                const data = response.data;

                console.log('Poll response:', data);

                if (data.partials && data.partials.length > 0) {
                    const fullText = data.partials
                        .map((p: any) => p.text)
                        .join(' ');
                    console.log('Updating transcript with text:', fullText.substring(0, 50) + '...', 'Full length:', fullText.length);
                    onTranscriptUpdate(fullText);
                    console.log('Transcript updated successfully');
                } else {
                    console.log('No partials found, retrying...');
                    // No transcript yet, try again
                    pollAttempts++;
                    if (pollAttempts < maxAttempts) {
                        setTimeout(poll, 3000); // Poll every 3 seconds
                    }
                }
            } catch (err: any) {
                console.error('Error polling transcript:', err);
                // Retry on error
                pollAttempts++;
                if (pollAttempts < maxAttempts) {
                    setTimeout(poll, 3000);
                }
            }
        };

        // Start polling after 3 seconds (give the queue worker time to start)
        setTimeout(poll, 3000);
    };

    const pollTranscript = async () => {
        if (!sessionId) return;

        try {
            const response = await axios.get(
                `/api/transcribe/sessions/${sessionId}/transcript`
            );
            const data = response.data;

            if (data.partials && data.partials.length > 0) {
                const fullText = data.partials
                    .map((p: any) => p.text)
                    .join(' ');
                onTranscriptUpdate(fullText);
            }
        } catch (err: any) {
            console.error('Error polling transcript:', err);
        }
    };

    const handleStart = async () => {
        if (!sessionId) {
            // Create session first
            try {
                console.log('Creating new session...');
                const response = await axios.post('/api/transcribe/sessions', {
                    title: `Recording ${new Date().toLocaleString()}`,
                });

                const newSessionId = response.data.session_id;
                console.log('Session created:', newSessionId);

                onSessionCreated(newSessionId);

                // Wait for state to update, then start recording
                // Note: We need to wait for the sessionId prop to update from parent
                console.log('Waiting for sessionId to be set...');
                setTimeout(() => {
                    console.log('Starting recording after session creation...');
                    startRecording();
                }, 200);
            } catch (err: any) {
                console.error('Error creating session:', err);
                const errorMessage = err.response?.data?.message || 'Failed to create recording session';
                setError(errorMessage);
            }
        } else {
            console.log('Session already exists, starting recording...');
            await startRecording();
        }
    };

    useEffect(() => {
        return () => {
            if (timerRef.current) {
                clearInterval(timerRef.current);
            }
            if (streamRef.current) {
                streamRef.current.getTracks().forEach((track) => track.stop());
            }
        };
    }, []);

    return (
        <div className="flex flex-col items-center space-y-6">
            {/* Timer Display */}
            <div className="text-6xl font-mono font-bold tabular-nums">
                {formatTime(duration)}
            </div>

            {/* Status */}
            <div className="text-sm text-muted-foreground">
                {!isRecording && !isPaused && 'Ready to record'}
                {isRecording && !isPaused && (
                    <span className="flex items-center gap-2">
                        <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-red-500" />
                        Recording...
                    </span>
                )}
                {isPaused && 'Paused'}
            </div>

            {/* Error */}
            {error && (
                <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                    {error}
                </div>
            )}

            {/* Controls */}
            <div className="flex gap-3">
                {!isRecording && !isPaused && (
                    <Button
                        size="lg"
                        onClick={handleStart}
                        className="h-16 w-16 rounded-full"
                    >
                        <Mic className="h-6 w-6" />
                    </Button>
                )}

                {isRecording && !isPaused && (
                    <>
                        <Button
                            size="lg"
                            variant="outline"
                            onClick={pauseRecording}
                            className="h-16 w-16 rounded-full"
                        >
                            <Pause className="h-6 w-6" />
                        </Button>
                        <Button
                            size="lg"
                            variant="destructive"
                            onClick={stopRecording}
                            className="h-16 w-16 rounded-full"
                        >
                            <Square className="h-6 w-6" />
                        </Button>
                    </>
                )}

                {isPaused && (
                    <>
                        <Button
                            size="lg"
                            onClick={resumeRecording}
                            className="h-16 w-16 rounded-full"
                        >
                            <Mic className="h-6 w-6" />
                        </Button>
                        <Button
                            size="lg"
                            variant="destructive"
                            onClick={stopRecording}
                            className="h-16 w-16 rounded-full"
                        >
                            <Square className="h-6 w-6" />
                        </Button>
                    </>
                )}
            </div>

            {/* Limits warning */}
            {duration > 240 && duration < 300 && (
                <div className="rounded-md bg-yellow-500/10 p-3 text-sm text-yellow-600 dark:text-yellow-400">
                    Recording will stop at 5 minutes
                </div>
            )}

            {duration >= 300 && (
                <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
                    Maximum recording time reached (5 minutes)
                </div>
            )}
        </div>
    );
}
