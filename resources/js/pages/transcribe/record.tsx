import AppLayout from '@/layouts/app-layout';
import { AudioRecorder } from '@/components/transcribe/AudioRecorder';
import { TranscriptDisplay } from '@/components/transcribe/TranscriptDisplay';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import axios from '@/lib/axios';

export default function Record() {
    const [sessionId, setSessionId] = useState<number | null>(null);
    const [transcript, setTranscript] = useState<string>('');
    const [isProcessing, setIsProcessing] = useState(false);
    const [sessionTitle, setSessionTitle] = useState('');

    const handleTranscriptUpdate = (text: string) => {
        console.log('Record page: received transcript update, length:', text.length, 'preview:', text.substring(0, 50));
        setTranscript(text);
        console.log('Record page: state updated');
    };

    const handleFinalize = async () => {
        if (!sessionId) return;

        setIsProcessing(true);

        try {
            await axios.post(`/api/transcribe/sessions/${sessionId}/finalize`);

            // Poll for the final transcript
            const pollInterval = setInterval(async () => {
                try {
                    const response = await axios.get(
                        `/api/transcribe/sessions/${sessionId}/transcript`
                    );
                    const data = response.data;

                    if (data.final) {
                        setTranscript(data.final.text);
                        setIsProcessing(false);
                        clearInterval(pollInterval);
                    }
                } catch (err: any) {
                    console.error('Error polling for final transcript:', err);
                }
            }, 3000);

            // Stop polling after 2 minutes
            setTimeout(() => {
                clearInterval(pollInterval);
                setIsProcessing(false);
            }, 120000);
        } catch (err: any) {
            console.error('Error finalizing transcript:', err);
            setIsProcessing(false);
        }
    };

    const handleSaveTitle = async () => {
        if (!sessionId || !sessionTitle) return;

        // This would be a patch endpoint - for now just noting it
        // In a full implementation, add a PATCH route to update session title
    };

    return (
        <AppLayout>
            <Head title="Record Session" />
            <div className="space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <Link href="/transcribe">
                        <Button variant="ghost" size="sm">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to Sessions
                        </Button>
                    </Link>
                </div>

                {sessionId && (
                    <div className="flex gap-2">
                        <Input
                            placeholder="Session title..."
                            value={sessionTitle}
                            onChange={(e) => setSessionTitle(e.target.value)}
                            className="w-64"
                        />
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleSaveTitle}
                            disabled={!sessionTitle}
                        >
                            Save Title
                        </Button>
                    </div>
                )}
            </div>

            {/* Recording Interface */}
            <div className="mx-auto max-w-2xl">
                <div className="rounded-lg border bg-card p-8 text-card-foreground shadow-sm">
                    <AudioRecorder
                        sessionId={sessionId}
                        onSessionCreated={setSessionId}
                        onTranscriptUpdate={handleTranscriptUpdate}
                    />
                </div>
            </div>

            {/* Transcript Display */}
            {sessionId && (
                <div className="mx-auto max-w-4xl">
                    <TranscriptDisplay
                        text={transcript}
                        isProcessing={isProcessing}
                        onFinalize={handleFinalize}
                    />
                </div>
            )}

            {/* Instructions */}
            {!sessionId && (
                <div className="mx-auto max-w-2xl rounded-lg border bg-muted/50 p-6">
                    <h3 className="mb-3 font-semibold">How it works:</h3>
                    <ol className="space-y-2 text-sm text-muted-foreground">
                        <li>
                            1. Click the microphone button to start recording
                        </li>
                        <li>
                            2. Your audio is transcribed in real-time (updates
                            every ~10 seconds)
                        </li>
                        <li>
                            3. You can pause/resume recording as needed (max 5
                            minutes)
                        </li>
                        <li>
                            4. Click "Polish Transcript" to get an AI-edited
                            version with proper punctuation
                        </li>
                        <li>
                            5. Uncertain words are{' '}
                            <span className="underline decoration-dotted decoration-orange-500 underline-offset-2">
                                underlined
                            </span>{' '}
                            - click to see alternatives
                        </li>
                    </ol>
                </div>
            )}
            </div>
        </AppLayout>
    );
}
