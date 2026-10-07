import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { ErrorBanner, Loading, Page } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';

type Permission = 'none' | 'view' | 'edit';
type AccessData = {
    contractors: {
        id: number;
        name: string;
        email: string;
        is_active: boolean;
    }[];
    projects: { id: number; name: string; archived: boolean }[];
    grants: {
        user_id: number;
        project_id: number;
        permission: Exclude<Permission, 'none'>;
    }[];
};
type AdminUser = {
    id: number;
    name: string;
    email: string;
    role: string;
    role_label: string;
    is_active: boolean;
    google_linked: boolean;
    revision: number;
};

const PERMISSION_LABELS: Record<Permission, string> = {
    none: '権限なし',
    view: '閲覧',
    edit: '編集',
};
const ROLE_LABELS: Record<string, string> = {
    admin: '管理者',
    employee: '社員',
    contractor: '外注',
    tv: 'TV専用',
};

type Pending =
    | {
          kind: 'grant';
          userId: number;
          userName: string;
          projectId: number;
          projectName: string;
          from: Permission;
          to: Permission;
      }
    | {
          kind: 'user';
          user: AdminUser;
          changes: { role?: string; is_active?: boolean };
          text: string;
      };

export default function AdminAccess() {
    const { auth } = usePage().props;
    const [data, setData] = useState<AccessData | null>(null);
    const [users, setUsers] = useState<AdminUser[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState<Pending | null>(null);
    const [busy, setBusy] = useState(false);
    const [newUser, setNewUser] = useState({
        name: '',
        email: '',
        role: 'employee',
    });

    const load = useCallback(async () => {
        try {
            const [a, u] = await Promise.all([
                api<AccessData>('GET', '/api/project-access'),
                api<{ data: AdminUser[] }>('GET', '/api/users'),
            ]);
            setData(a);
            setUsers(u.data);
            setError(null);
        } catch (e) {
            setError(
                e instanceof ApiError ? e.message : '読み込みに失敗しました。',
            );
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    const current = (userId: number, projectId: number): Permission =>
        data?.grants.find(
            (g) => g.user_id === userId && g.project_id === projectId,
        )?.permission ?? 'none';

    const apply = async () => {
        if (!pending) return;
        setBusy(true);
        try {
            if (pending.kind === 'grant') {
                await api('PUT', '/api/project-access', {
                    user_id: pending.userId,
                    project_id: pending.projectId,
                    permission: pending.to,
                    expected_permission: pending.from,
                });
                toast.success('権限を変更しました（監査記録に保存）');
            } else {
                await api('PATCH', `/api/users/${pending.user.id}`, {
                    ...pending.changes,
                    expected_revision: pending.user.revision,
                });
                toast.success(
                    '利用者を更新しました（既存のセッションは失効しました）',
                );
            }
        } catch (e) {
            toast.error(
                e instanceof ApiError
                    ? e.isConflict
                        ? '他の管理者が先に変更しました。最新の状態を読み込みました。'
                        : e.firstError()
                    : '変更に失敗しました。',
            );
        } finally {
            setBusy(false);
            setPending(null);
            await load();
        }
    };

    const addUser = async () => {
        setBusy(true);
        try {
            await api('POST', '/api/users', newUser);
            toast.success('利用者を登録しました');
            setNewUser({ name: '', email: '', role: 'employee' });
            await load();
        } catch (e) {
            toast.error(
                e instanceof ApiError ? e.firstError() : '登録に失敗しました。',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <Page title="権限・利用者（管理者）">
            <ErrorBanner message={error} onRetry={() => void load()} />
            {!data ? (
                <Loading />
            ) : (
                <>
                    <section className="space-y-2">
                        <h2 className="font-semibold">外注の案件別権限</h2>
                        <p className="text-xs text-muted-foreground">
                            外注は付与された有効案件の共有タスク・共有文書・議事録だけを利用できます。変更は確認後に反映され、監査記録に残ります。取消は即時に反映されます。
                        </p>
                        {data.contractors.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                外注アカウントがありません。
                            </p>
                        ) : (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="text-sm">
                                    <thead className="bg-muted text-xs">
                                        <tr>
                                            <th className="sticky left-0 bg-muted px-3 py-2 text-left">
                                                外注
                                            </th>
                                            {data.projects
                                                .filter((p) => !p.archived)
                                                .map((p) => (
                                                    <th
                                                        key={p.id}
                                                        className="px-3 py-2 text-left whitespace-nowrap"
                                                    >
                                                        {p.name}
                                                    </th>
                                                ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {data.contractors.map((c) => (
                                            <tr key={c.id}>
                                                <td className="sticky left-0 bg-background px-3 py-2 whitespace-nowrap">
                                                    {c.name}
                                                    {!c.is_active && (
                                                        <span className="ml-1 text-xs text-red-600">
                                                            （無効）
                                                        </span>
                                                    )}
                                                </td>
                                                {data.projects
                                                    .filter((p) => !p.archived)
                                                    .map((p) => {
                                                        const value = current(
                                                            c.id,
                                                            p.id,
                                                        );

                                                        return (
                                                            <td
                                                                key={p.id}
                                                                className="px-3 py-2"
                                                            >
                                                                <NativeSelect
                                                                    aria-label={`${c.name} の ${p.name} の権限`}
                                                                    className="w-28"
                                                                    value={
                                                                        value
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setPending(
                                                                            {
                                                                                kind: 'grant',
                                                                                userId: c.id,
                                                                                userName:
                                                                                    c.name,
                                                                                projectId:
                                                                                    p.id,
                                                                                projectName:
                                                                                    p.name,
                                                                                from: value,
                                                                                to: e
                                                                                    .target
                                                                                    .value as Permission,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <option value="none">
                                                                        権限なし
                                                                    </option>
                                                                    <option value="view">
                                                                        閲覧
                                                                    </option>
                                                                    <option value="edit">
                                                                        編集
                                                                    </option>
                                                                </NativeSelect>
                                                            </td>
                                                        );
                                                    })}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="space-y-2">
                        <h2 className="font-semibold">利用者</h2>
                        <p className="text-xs text-muted-foreground">
                            未登録の Google
                            アカウントは自動登録されません（許可ドメイン設定時のみ「社員」として作成）。役割の変更・無効化はその利用者の既存セッションを即時に失効させます。
                        </p>
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2">氏名</th>
                                        <th className="px-3 py-2">メール</th>
                                        <th className="px-3 py-2">役割</th>
                                        <th className="px-3 py-2">状態</th>
                                        <th className="px-3 py-2">Google</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {users.map((u) => {
                                        const self = u.id === auth.user?.id;

                                        return (
                                            <tr key={u.id}>
                                                <td className="px-3 py-2">
                                                    {u.name}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {u.email}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <NativeSelect
                                                        aria-label={`${u.name} の役割`}
                                                        className="w-28"
                                                        value={u.role}
                                                        disabled={self}
                                                        onChange={(e) =>
                                                            setPending({
                                                                kind: 'user',
                                                                user: u,
                                                                changes: {
                                                                    role: e
                                                                        .target
                                                                        .value,
                                                                },
                                                                text: `${u.name} の役割を「${ROLE_LABELS[e.target.value]}」に変更します。`,
                                                            })
                                                        }
                                                    >
                                                        {Object.entries(
                                                            ROLE_LABELS,
                                                        ).map(([k, v]) => (
                                                            <option
                                                                key={k}
                                                                value={k}
                                                            >
                                                                {v}
                                                            </option>
                                                        ))}
                                                    </NativeSelect>
                                                </td>
                                                <td className="px-3 py-2">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={self}
                                                        onClick={() =>
                                                            setPending({
                                                                kind: 'user',
                                                                user: u,
                                                                changes: {
                                                                    is_active:
                                                                        !u.is_active,
                                                                },
                                                                text: u.is_active
                                                                    ? `${u.name} を無効化します。ログイン中のセッションも失効します。`
                                                                    : `${u.name} を有効にします。`,
                                                            })
                                                        }
                                                    >
                                                        {u.is_active
                                                            ? '有効（無効化する）'
                                                            : '無効（有効にする）'}
                                                    </Button>
                                                </td>
                                                <td className="px-3 py-2 text-xs">
                                                    {u.google_linked
                                                        ? '連携済み'
                                                        : '未連携'}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <form
                            className="flex flex-wrap items-end gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void addUser();
                            }}
                        >
                            <Input
                                aria-label="氏名"
                                placeholder="氏名"
                                className="w-40"
                                value={newUser.name}
                                maxLength={100}
                                onChange={(e) =>
                                    setNewUser({
                                        ...newUser,
                                        name: e.target.value,
                                    })
                                }
                            />
                            <Input
                                aria-label="メールアドレス"
                                placeholder="メールアドレス"
                                type="email"
                                className="w-64"
                                value={newUser.email}
                                onChange={(e) =>
                                    setNewUser({
                                        ...newUser,
                                        email: e.target.value,
                                    })
                                }
                            />
                            <NativeSelect
                                aria-label="新しい利用者の役割"
                                className="w-28"
                                value={newUser.role}
                                onChange={(e) =>
                                    setNewUser({
                                        ...newUser,
                                        role: e.target.value,
                                    })
                                }
                            >
                                {Object.entries(ROLE_LABELS).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={
                                    busy || !newUser.name || !newUser.email
                                }
                            >
                                利用者を登録
                            </Button>
                        </form>
                    </section>
                </>
            )}
            <ConfirmDialog
                open={pending !== null}
                title="変更を確認してください"
                confirmLabel="変更する"
                destructive={
                    pending?.kind === 'grant'
                        ? pending.to === 'none'
                        : pending?.changes.is_active === false
                }
                busy={busy}
                onConfirm={() => void apply()}
                onCancel={() => setPending(null)}
            >
                {pending?.kind === 'grant' && (
                    <>
                        {pending.userName} の「{pending.projectName}」の権限を
                        「{PERMISSION_LABELS[pending.from]}」→「
                        {PERMISSION_LABELS[pending.to]}」に変更します。
                    </>
                )}
                {pending?.kind === 'user' && pending.text}
            </ConfirmDialog>
        </Page>
    );
}
