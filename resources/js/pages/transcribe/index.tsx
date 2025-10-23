import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import { FileText, Mic, Plus } from 'lucide-react';

interface Session {
    id: number;
    title: string;
    status: 'recording' | 'processing' | 'ready';
    created_at: string;
    preview: string;
}

interface Props {
    sessions: Session[];
}

const statusConfig = {
    recording: {
        label: 'Recording',
        variant: 'default' as const,
        color: 'bg-blue-500',
    },
    processing: {
        label: 'Processing',
        variant: 'secondary' as const,
        color: 'bg-yellow-500',
    },
    ready: {
        label: 'Ready',
        variant: 'default' as const,
        color: 'bg-green-500',
    },
};

export default function Index({ sessions }: Props) {
    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    return (
        <AppLayout>
            <Head title="Transcriptions" />
            <div className="space-y-6">
            {/* Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-3xl font-bold">Transcriptions</h1>
                    <p className="text-muted-foreground">
                        Manage your recording sessions
                    </p>
                </div>
                <Link href="/transcribe/record">
                    <Button size="lg">
                        <Plus className="mr-2 h-5 w-5" />
                        New Recording
                    </Button>
                </Link>
            </div>

            {/* Sessions List */}
            {sessions.length === 0 ? (
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-16">
                        <div className="mb-4 rounded-full bg-muted p-6">
                            <Mic className="h-12 w-12 text-muted-foreground" />
                        </div>
                        <h3 className="mb-2 text-lg font-semibold">
                            No recordings yet
                        </h3>
                        <p className="mb-6 text-center text-sm text-muted-foreground">
                            Start your first recording session to see it here
                        </p>
                        <Link href="/transcribe/record">
                            <Button>
                                <Mic className="mr-2 h-4 w-4" />
                                Start Recording
                            </Button>
                        </Link>
                    </CardContent>
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {sessions.map((session) => {
                        const statusInfo = statusConfig[session.status];
                        return (
                            <Link
                                key={session.id}
                                href={`/transcribe/sessions/${session.id}`}
                            >
                                <Card className="transition-shadow hover:shadow-md">
                                    <CardHeader>
                                        <div className="flex items-start justify-between">
                                            <div className="flex-1">
                                                <CardTitle className="line-clamp-1 text-lg">
                                                    {session.title}
                                                </CardTitle>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {formatDate(
                                                        session.created_at,
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-1">
                                                <div
                                                    className={`h-2 w-2 rounded-full ${statusInfo.color}`}
                                                />
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="flex items-start gap-3">
                                            <div className="rounded bg-muted p-2">
                                                <FileText className="h-4 w-4 text-muted-foreground" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="line-clamp-3 text-sm text-muted-foreground">
                                                    {session.preview ||
                                                        'No transcript yet...'}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="mt-4">
                                            <Badge variant={statusInfo.variant}>
                                                {statusInfo.label}
                                            </Badge>
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        );
                    })}
                </div>
            )}
            </div>
        </AppLayout>
    );
}
