import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Copy, Download, Sparkles } from 'lucide-react';
import { useState } from 'react';

interface Word {
    text: string;
    confidence?: number;
    alternatives?: Array<{ text: string; confidence: number }>;
    user_edited?: boolean;
}

interface TranscriptDisplayProps {
    text: string;
    words?: Word[];
    isProcessing?: boolean;
    hasFinal?: boolean;
    onFinalize?: () => void;
    onAcceptWord?: (wordIndex: number, acceptedText: string) => void;
}

export function TranscriptDisplay({
    text,
    words = [],
    isProcessing = false,
    hasFinal = false,
    onFinalize,
    onAcceptWord,
}: TranscriptDisplayProps) {
    const [selectedWord, setSelectedWord] = useState<{
        index: number;
        word: Word;
    } | null>(null);

    // Debug logging
    console.log('TranscriptDisplay render:', {
        textLength: text?.length || 0,
        textPreview: text ? text.substring(0, 50) : 'empty',
        hasText: !!text
    });

    const copyToClipboard = async () => {
        try {
            await navigator.clipboard.writeText(text);
            // Could add a toast notification here
        } catch (err) {
            console.error('Failed to copy:', err);
        }
    };

    const downloadText = () => {
        const blob = new Blob([text], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `transcript-${Date.now()}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    const renderText = () => {
        if (!words || words.length === 0) {
            return <p className="whitespace-pre-wrap">{text}</p>;
        }

        return (
            <p className="whitespace-pre-wrap leading-relaxed">
                {words.map((word, index) => {
                    const isUncertain =
                        word.confidence !== undefined && word.confidence < 0.7;
                    const hasAlternatives =
                        word.alternatives && word.alternatives.length > 0;

                    if (isUncertain || hasAlternatives) {
                        return (
                            <span
                                key={index}
                                className="cursor-pointer underline decoration-dotted decoration-orange-500 underline-offset-2 hover:bg-orange-50 dark:hover:bg-orange-950"
                                onClick={() =>
                                    setSelectedWord({ index, word })
                                }
                                title={`Confidence: ${Math.round((word.confidence || 0) * 100)}%`}
                            >
                                {word.text}
                            </span>
                        );
                    }

                    return <span key={index}>{word.text}</span>;
                })}
            </p>
        );
    };

    return (
        <>
            <Card>
                <CardHeader>
                    <div className="flex items-center justify-between">
                        <CardTitle>Transcript</CardTitle>
                        <div className="flex gap-2">
                            {!hasFinal && !isProcessing && onFinalize && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={onFinalize}
                                >
                                    <Sparkles className="mr-2 h-4 w-4" />
                                    Polish Transcript
                                </Button>
                            )}
                            {text && (
                                <>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={copyToClipboard}
                                    >
                                        <Copy className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={downloadText}
                                    >
                                        <Download className="h-4 w-4" />
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    {isProcessing && (
                        <div className="mb-4 flex items-center gap-2 rounded-md bg-blue-50 p-3 text-sm text-blue-600 dark:bg-blue-950 dark:text-blue-400">
                            <div className="h-4 w-4 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
                            Processing with AI...
                        </div>
                    )}

                    {!text ? (
                        <p className="text-sm text-muted-foreground">
                            Start recording to see your transcript here...
                        </p>
                    ) : (
                        <div className="rounded-md bg-muted p-4">
                            {renderText()}
                        </div>
                    )}

                    {words && words.length > 0 && (
                        <div className="mt-4 text-xs text-muted-foreground">
                            <span className="underline decoration-dotted decoration-orange-500 underline-offset-2">
                                Uncertain words
                            </span>{' '}
                            can be clicked to see alternatives
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Word Alternatives Dialog */}
            <Dialog
                open={selectedWord !== null}
                onOpenChange={(open) => !open && setSelectedWord(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Word Alternatives</DialogTitle>
                    </DialogHeader>
                    {selectedWord && (
                        <div className="space-y-4">
                            <div>
                                <div className="text-sm text-muted-foreground">
                                    Current word:
                                </div>
                                <div className="text-2xl font-semibold">
                                    {selectedWord.word.text}
                                </div>
                                {selectedWord.word.confidence !== undefined && (
                                    <div className="text-sm text-muted-foreground">
                                        Confidence:{' '}
                                        {Math.round(
                                            selectedWord.word.confidence * 100,
                                        )}
                                        %
                                    </div>
                                )}
                            </div>

                            {selectedWord.word.alternatives &&
                                selectedWord.word.alternatives.length > 0 && (
                                    <div>
                                        <div className="mb-2 text-sm font-medium">
                                            Alternatives:
                                        </div>
                                        <div className="space-y-2">
                                            {selectedWord.word.alternatives.map(
                                                (alt, idx) => (
                                                    <Button
                                                        key={idx}
                                                        variant="outline"
                                                        className="w-full justify-between"
                                                        onClick={() => {
                                                            onAcceptWord?.(
                                                                selectedWord.index,
                                                                alt.text,
                                                            );
                                                            setSelectedWord(
                                                                null,
                                                            );
                                                        }}
                                                    >
                                                        <span>{alt.text}</span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {Math.round(
                                                                alt.confidence *
                                                                    100,
                                                            )}
                                                            %
                                                        </span>
                                                    </Button>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}

                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    className="flex-1"
                                    onClick={() => setSelectedWord(null)}
                                >
                                    Keep Current
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
