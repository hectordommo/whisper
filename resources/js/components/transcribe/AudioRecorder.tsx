import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Mic, Pause, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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
                if (event.data.size > 0) {
                    chunksRef.current.push(event.data);

                    // Upload chunk to backend
                    const blob = event.data;
                    const chunkEnd = (Date.now() - startTimeRef.current) / 1000;

                    await uploadChunk(
                        blob,
                        currentChunkStartRef.current,
                        chunkEnd,
                    );

                    currentChunkStartRef.current = chunkEnd;
                }
            };

            // Start recording with 10-second chunks
            mediaRecorder.start(10000);

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
        if (!sessionId) return;

        const formData = new FormData();
        formData.append('file', blob, 'chunk.webm');
        formData.append('start_time', startTime.toString());
        formData.append('end_time', endTime.toString());

        try {
            const response = await fetch(
                `/transcribe/sessions/${sessionId}/chunks`,
                {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') || '',
                    },
                },
            );

            if (!response.ok) {
                throw new Error('Failed to upload chunk');
            }

            // Poll for transcript update
            setTimeout(() => pollTranscript(), 2000);
        } catch (err) {
            console.error('Error uploading chunk:', err);
        }
    };

    const pollTranscript = async () => {
        if (!sessionId) return;

        try {
            const response = await fetch(
                `/transcribe/sessions/${sessionId}/transcript`,
            );
            const data = await response.json();

            if (data.partials && data.partials.length > 0) {
                const fullText = data.partials
                    .map((p: any) => p.text)
                    .join(' ');
                onTranscriptUpdate(fullText);
            }
        } catch (err) {
            console.error('Error polling transcript:', err);
        }
    };

    const handleStart = async () => {
        if (!sessionId) {
            // Create session first
            try {
                const response = await fetch('/transcribe/sessions', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        title: `Recording ${new Date().toLocaleString()}`,
                    }),
                });

                const data = await response.json();
                onSessionCreated(data.session_id);

                // Start recording after session created
                setTimeout(startRecording, 100);
            } catch (err) {
                console.error('Error creating session:', err);
                setError('Failed to create recording session');
            }
        } else {
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
