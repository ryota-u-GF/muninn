import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type DemoUser = { id: number; name: string; role: string; role_label: string };

export default function Login({
    googleConfigured,
    demoEnabled,
    demoUsers,
    error,
}: {
    googleConfigured: boolean;
    demoEnabled: boolean;
    demoUsers: DemoUser[];
    error?: string | null;
}) {
    const [busy, setBusy] = useState(false);
    const form = useForm({ email: '', password: '' });

    return (
        <AuthLayout
            title="Muninn"
            description="メールアドレスとパスワード、または Google アカウントでログインしてください"
        >
            <Head title="ログイン" />
            <div className="flex flex-col gap-6">
                {error && (
                    <div
                        role="alert"
                        className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
                    >
                        {error}
                    </div>
                )}

                <form
                    className="space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/password-login', {
                            onFinish: () => form.reset('password'),
                        });
                    }}
                >
                    <div className="space-y-1">
                        <Label htmlFor="email">メールアドレス</Label>
                        <Input
                            id="email"
                            type="email"
                            autoComplete="username"
                            required
                            value={form.data.email}
                            onChange={(e) =>
                                form.setData('email', e.target.value)
                            }
                        />
                        {form.errors.email && (
                            <p className="text-xs text-red-700 dark:text-red-300">
                                {form.errors.email}
                            </p>
                        )}
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="password">パスワード</Label>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            required
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                        {form.errors.password && (
                            <p className="text-xs text-red-700 dark:text-red-300">
                                {form.errors.password}
                            </p>
                        )}
                    </div>
                    <Button
                        type="submit"
                        className="w-full"
                        disabled={form.processing}
                    >
                        ログイン
                    </Button>
                </form>

                <div className="space-y-2">
                    {googleConfigured ? (
                        <Button asChild className="w-full">
                            <a href="/auth/google/redirect">
                                Google でログイン
                            </a>
                        </Button>
                    ) : (
                        <>
                            <Button className="w-full" disabled>
                                Google でログイン（未接続）
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                Google
                                ログインは接続準備のみ完了しています。接続情報（OAuth
                                クライアント）が未設定のため、まだ利用できません。実アカウントでの動作確認も未実施です。
                            </p>
                        </>
                    )}
                </div>

                {demoEnabled && (
                    <section className="space-y-3 rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
                        <h2 className="text-sm font-semibold text-amber-900 dark:text-amber-100">
                            開発用デモログイン（本番環境では利用できません）
                        </h2>
                        <div className="grid gap-2">
                            {demoUsers.map((u) => (
                                <Button
                                    key={u.id}
                                    variant="outline"
                                    className="justify-between"
                                    disabled={busy}
                                    data-test={`demo-login-${u.id}`}
                                    onClick={() => {
                                        setBusy(true);
                                        router.post(
                                            '/demo-login',
                                            { user_id: u.id },
                                            { onFinish: () => setBusy(false) },
                                        );
                                    }}
                                >
                                    <span>{u.name}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {u.role_label}
                                    </span>
                                </Button>
                            ))}
                            {demoUsers.length === 0 && (
                                <p className="text-sm">
                                    利用者がいません。`php artisan migrate:fresh
                                    --seed` でデモデータを投入してください。
                                </p>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </AuthLayout>
    );
}
