import SettingsLayout from '@/layouts/settings/settings-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { Eye, EyeOff, Save } from 'lucide-react';
import { useState } from 'react';

interface Props {
    apiKeys?: {
        openai?: string;
        anthropic?: string;
    };
}

export default function ApiKeys({ apiKeys }: Props) {
    const { data, setData, patch, processing, errors, reset } = useForm({
        openai_api_key: apiKeys?.openai || '',
        anthropic_api_key: apiKeys?.anthropic || '',
    });

    const [showOpenAI, setShowOpenAI] = useState(false);
    const [showAnthropic, setShowAnthropic] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        patch('/settings/api-keys', {
            preserveScroll: true,
            onSuccess: () => {
                // Could add toast notification here
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>API Keys</CardTitle>
                    <CardDescription>
                        Configure your API keys for transcription services. These keys are stored encrypted
                        and never shared.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {/* OpenAI API Key */}
                    <div className="space-y-2">
                        <Label htmlFor="openai_api_key">OpenAI API Key</Label>
                        <div className="flex gap-2">
                            <div className="relative flex-1">
                                <Input
                                    id="openai_api_key"
                                    type={showOpenAI ? 'text' : 'password'}
                                    value={data.openai_api_key}
                                    onChange={(e) =>
                                        setData('openai_api_key', e.target.value)
                                    }
                                    placeholder="sk-..."
                                    className="pr-10"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowOpenAI(!showOpenAI)}
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                >
                                    {showOpenAI ? (
                                        <EyeOff className="h-4 w-4" />
                                    ) : (
                                        <Eye className="h-4 w-4" />
                                    )}
                                </button>
                            </div>
                        </div>
                        {errors.openai_api_key && (
                            <p className="text-sm text-destructive">
                                {errors.openai_api_key}
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Used for Whisper audio transcription. Get your key from{' '}
                            <a
                                href="https://platform.openai.com/api-keys"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline hover:text-foreground"
                            >
                                OpenAI Platform
                            </a>
                        </p>
                    </div>

                    {/* Anthropic API Key */}
                    <div className="space-y-2">
                        <Label htmlFor="anthropic_api_key">
                            Anthropic API Key
                        </Label>
                        <div className="flex gap-2">
                            <div className="relative flex-1">
                                <Input
                                    id="anthropic_api_key"
                                    type={showAnthropic ? 'text' : 'password'}
                                    value={data.anthropic_api_key}
                                    onChange={(e) =>
                                        setData(
                                            'anthropic_api_key',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="sk-ant-..."
                                    className="pr-10"
                                />
                                <button
                                    type="button"
                                    onClick={() =>
                                        setShowAnthropic(!showAnthropic)
                                    }
                                    className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                >
                                    {showAnthropic ? (
                                        <EyeOff className="h-4 w-4" />
                                    ) : (
                                        <Eye className="h-4 w-4" />
                                    )}
                                </button>
                            </div>
                        </div>
                        {errors.anthropic_api_key && (
                            <p className="text-sm text-destructive">
                                {errors.anthropic_api_key}
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            Used for Claude transcript polishing. Get your key from{' '}
                            <a
                                href="https://console.anthropic.com/settings/keys"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline hover:text-foreground"
                            >
                                Anthropic Console
                            </a>
                        </p>
                    </div>

                    {/* Info Box */}
                    <div className="rounded-lg border bg-muted/50 p-4">
                        <h4 className="mb-2 font-semibold text-sm">Security Note</h4>
                        <p className="text-xs text-muted-foreground">
                            Your API keys are encrypted before being stored in the database.
                            They are only decrypted when needed to make API requests and are
                            never exposed in responses.
                        </p>
                    </div>
                </CardContent>
            </Card>

            {/* Save Button */}
            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    <Save className="mr-2 h-4 w-4" />
                    {processing ? 'Saving...' : 'Save API Keys'}
                </Button>
            </div>
        </form>
    );
}

ApiKeys.layout = (page: React.ReactNode) => (
    <SettingsLayout
        title="API Keys"
        children={page}
    />
);
