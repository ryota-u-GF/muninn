import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { ErrorBanner, Loading, Page } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    password_set: boolean;
    revision: number;
};

const PASSWORD_MIN_LENGTH = 12;

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
    const [adding, setAdding] = useState(false);
    const [pwTarget, setPwTarget] = useState<AdminUser | null>(null);
    const [pw, setPw] = useState({ password: '', confirmation: '' });
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
            setAdding(false);
            await load();
        } catch (e) {
            toast.error(
                e instanceof ApiError ? e.firstError() : '登録に失敗しました。',
            );
        } finally {
            setBusy(false);
        }
    };

    const closePassword = () => {
        setPwTarget(null);
        setPw({ password: '', confirmation: '' });
    };

    const savePassword = async () => {
        if (!pwTarget) return;
        setBusy(true);
        try {
            await api('PUT', `/api/users/${pwTarget.id}/password`, {
                password: pw.password,
                password_confirmation: pw.confirmation,
                expected_revision: pwTarget.revision,
            });
            toast.success(
                pwTarget.id === auth.user?.id
                    ? 'パスワードを設定しました'
                    : 'パスワードを設定しました（ログイン中のセッションは失効しました）',
            );
            closePassword();
        } catch (e) {
            toast.error(
                e instanceof ApiError
                    ? e.isConflict
                        ? '他の管理者が先に変更しました。最新の状態を読み込みました。'
                        : e.firstError()
                    : 'パスワードの設定に失敗しました。',
            );
        } finally {
            setBusy(false);
            await load();
        }
    };

    const passwordInvalid =
        pw.password.length < PASSWORD_MIN_LENGTH ||
        pw.password !== pw.confirmation;

    return (
        <Page title="担当者・権限（管理者）">
            <ErrorBanner message={error} onRetry={() => void load()} />
            {!data ? (
                <Loading />
            ) : (
                <>
                    <section className="space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-semibold">
                                担当者（利用者）
                                <span className="ml-2 text-xs font-normal text-muted-foreground">
                                    {users.length}人
                                </span>
                            </h2>
                            {!adding && (
                                <Button
                                    size="sm"
                                    onClick={() => setAdding(true)}
                                >
                                    ＋ 担当者を追加
                                </Button>
                            )}
                        </div>

                        {adding && (
                            <form
                                className="grid gap-3 rounded-md border p-3 sm:grid-cols-[1fr_1.5fr_8rem_auto] sm:items-end"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    void addUser();
                                }}
                            >
                                <div className="grid gap-1">
                                    <Label htmlFor="new-user-name">氏名</Label>
                                    <Input
                                        id="new-user-name"
                                        aria-label="氏名"
                                        value={newUser.name}
                                        maxLength={100}
                                        autoFocus
                                        onChange={(e) =>
                                            setNewUser({
                                                ...newUser,
                                                name: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="new-user-email">
                                        メールアドレス
                                    </Label>
                                    <Input
                                        id="new-user-email"
                                        aria-label="メールアドレス"
                                        type="email"
                                        value={newUser.email}
                                        onChange={(e) =>
                                            setNewUser({
                                                ...newUser,
                                                email: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="new-user-role">役割</Label>
                                    <NativeSelect
                                        id="new-user-role"
                                        aria-label="新しい利用者の役割"
                                        value={newUser.role}
                                        onChange={(e) =>
                                            setNewUser({
                                                ...newUser,
                                                role: e.target.value,
                                            })
                                        }
                                    >
                                        {Object.entries(ROLE_LABELS).map(
                                            ([k, v]) => (
                                                <option key={k} value={k}>
                                                    {v}
                                                </option>
                                            ),
                                        )}
                                    </NativeSelect>
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={
                                            busy ||
                                            !newUser.name ||
                                            !newUser.email
                                        }
                                    >
                                        登録
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setAdding(false)}
                                    >
                                        やめる
                                    </Button>
                                </div>
                            </form>
                        )}

                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted text-left text-xs">
                                    <tr>
                                        <th className="px-3 py-2">氏名</th>
                                        <th className="px-3 py-2">役割</th>
                                        <th className="px-3 py-2">状態</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {users.map((u) => {
                                        const self = u.id === auth.user?.id;

                                        return (
                                            <tr key={u.id}>
                                                <td className="px-3 py-2">
                                                    <div>{u.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {u.email}
                                                        {u.google_linked &&
                                                            '・Google連携済み'}
                                                    </div>
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
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            className={
                                                                u.is_active
                                                                    ? 'text-xs text-emerald-700'
                                                                    : 'text-xs text-red-600'
                                                            }
                                                        >
                                                            {u.is_active
                                                                ? '有効'
                                                                : '無効'}
                                                        </span>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
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
                                                                ? '無効にする'
                                                                : '有効にする'}
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setPwTarget(u)
                                                            }
                                                        >
                                                            {u.password_set
                                                                ? 'パスワード変更'
                                                                : 'パスワード設定'}
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </section>

                    {data.contractors.length > 0 && (
                        <details className="rounded-md border p-3">
                            <summary className="cursor-pointer text-sm font-semibold">
                                外注が見られる案件
                                <span className="ml-2 text-xs font-normal text-muted-foreground">
                                    {data.contractors.length}人
                                </span>
                            </summary>
                            <div className="mt-3 space-y-2">
                                <p className="text-xs text-muted-foreground">
                                    外注ごとに、案件を「権限なし・閲覧・編集」から選びます（変更は確認後に反映）。
                                </p>
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
                                                        .filter(
                                                            (p) => !p.archived,
                                                        )
                                                        .map((p) => {
                                                            const value =
                                                                current(
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
                            </div>
                        </details>
                    )}
                </>
            )}
            <Dialog
                open={pwTarget !== null}
                onOpenChange={(o) => !o && !busy && closePassword()}
            >
                <DialogContent>
                    <form
                        className="grid gap-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!passwordInvalid) void savePassword();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>
                                {pwTarget?.name} のパスワード
                            </DialogTitle>
                            <DialogDescription>
                                {PASSWORD_MIN_LENGTH}文字以上で設定します。
                                {pwTarget?.id !== auth.user?.id &&
                                    '設定すると、この利用者のログイン中のセッションは失効します。'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-1">
                            <Label htmlFor="pw-new">新しいパスワード</Label>
                            <Input
                                id="pw-new"
                                type="password"
                                autoComplete="new-password"
                                value={pw.password}
                                onChange={(e) =>
                                    setPw({ ...pw, password: e.target.value })
                                }
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="pw-confirm">確認用</Label>
                            <Input
                                id="pw-confirm"
                                type="password"
                                autoComplete="new-password"
                                value={pw.confirmation}
                                onChange={(e) =>
                                    setPw({
                                        ...pw,
                                        confirmation: e.target.value,
                                    })
                                }
                            />
                            {pw.confirmation !== '' &&
                                pw.password !== pw.confirmation && (
                                    <p className="text-xs text-red-700 dark:text-red-300">
                                        確認用のパスワードが一致しません。
                                    </p>
                                )}
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={closePassword}
                                disabled={busy}
                            >
                                キャンセル
                            </Button>
                            <Button
                                type="submit"
                                disabled={busy || passwordInvalid}
                            >
                                {busy ? '処理中…' : '設定する'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
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
