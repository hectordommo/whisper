import AppLayout from '@/layouts/app-layout';
import { TranscriptDisplay } from '@/components/transcribe/TranscriptDisplay';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface Session {
    id: number;
    title: string;
    status: 'recording' | 'processing' | 'ready';
    created_at: string;
    transcript: {
        text: string;
        type: string;
        meta: {
            words?: Array<{
                text: string;
                confidence?: number;
                alternatives?: Array<{ text: string; confidence: number }>;
            }>;
            uncertain_words?: Array<any>;
        };
    } | null;
}

interface Props {
    session: Session;
}

const statusConfig = {
    recording: {
        label: 'Recording',
        variant: 'default' as const,
    },
    processing: {
        label: 'Processing',
        variant: 'secondary' as const,
    },
    ready: {
        label: 'Ready',
        variant: 'default' as const,
    },
};

export default function Show({ session }: Props) {
    const [isProcessing, setIsProcessing] = useState(
        session.status === 'processing',
    );
    const [transcript, setTranscript] = useState(
        session.transcript?.text || '',
    );
    const [words, setWords] = useState(session.transcript?.meta?.words || []);

    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            month: 'long',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    const handleFinalize = async () => {
        setIsProcessing(true);

        try {
            const response = await fetch(
                `/transcribe/sessions/${session.id}/finalize`,
                {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') || '',
                    },
                },
            );

            if (response.ok) {
                // Poll for the final transcript
                const pollInterval = setInterval(async () => {
                    const transcriptResponse = await fetch(
                        `/transcribe/sessions/${session.id}/transcript`,
                    );
                    const data = await transcriptResponse.json();

                    if (data.final) {
                        setTranscript(data.final.text);
                        setWords(data.final.meta?.words || []);
                        setIsProcessing(false);
                        clearInterval(pollInterval);
                    }
                }, 3000);

                // Stop polling after 2 minutes
                setTimeout(() => {
                    clearInterval(pollInterval);
                    setIsProcessing(false);
                }, 120000);
            }
        } catch (err) {
            console.error('Error finalizing transcript:', err);
            setIsProcessing(false);
        }
    };

    const handleAcceptWord = async (
        wordIndex: number,
        acceptedText: string,
    ) => {
        try {
            const response = await fetch(
                `/transcribe/sessions/${session.id}/accept-word`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        transcript_id: session.transcript?.id,
                        word_index: wordIndex,
                        accepted_text: acceptedText,
                    }),
                },
            );

            if (response.ok) {
                const data = await response.json();
                setTranscript(data.transcript.text);
                setWords(data.transcript.meta?.words || []);
            }
        } catch (err) {
            console.error('Error accepting word:', err);
        }
    };

    const handleDelete = async () => {
        try {
            await router.delete(`/transcribe/sessions/${session.id}`, {
                onSuccess: () => {
                    router.visit('/transcribe');
                },
            });
        } catch (err) {
            console.error('Error deleting session:', err);
        }
    };

    const statusInfo = statusConfig[session.status];

    return (
        <AppLayout>
            <Head title={session.title} />
            <div className="space-y-6">
            {/* Header */}
            <div className="flex items-start justify-between">
                <div className="space-y-1">
                    <div className="flex items-center gap-3">
                        <Link href="/transcribe">
                            <Button variant="ghost" size="sm">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back
                            </Button>
                        </Link>
                    </div>
                    <div className="flex items-center gap-3">
                        <h1 className="text-3xl font-bold">{session.title}</h1>
                        <Badge variant={statusInfo.variant}>
                            {statusInfo.label}
                        </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {formatDate(session.created_at)}
                    </p>
                </div>

                <AlertDialog>
                    <AlertDialogTrigger asChild>
                        <Button variant="destructive" size="sm">
                            <Trash2 className="mr-2 h-4 w-4" />
                            Delete
                        </Button>
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Delete this session?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                This action cannot be undone. This will
                                permanently delete the recording session and all
                                associated transcripts.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction onClick={handleDelete}>
                                Delete
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>

            {/* Transcript */}
            <TranscriptDisplay
                text={transcript}
                words={words}
                isProcessing={isProcessing}
                hasFinal={session.transcript?.type === 'llm_final'}
                onFinalize={
                    session.transcript?.type !== 'llm_final'
                        ? handleFinalize
                        : undefined
                }
                onAcceptWord={handleAcceptWord}
            />

            {/* Empty State */}
            {!transcript && (
                <div className="rounded-lg border bg-muted/50 p-8 text-center">
                    <p className="text-muted-foreground">
                        No transcript available yet. The recording may still be
                        processing.
                    </p>
                </div>
            )}
            </div>
        </AppLayout>
    );
}
