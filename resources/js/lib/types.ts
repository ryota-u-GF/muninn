export type TaskStatus = 'todo' | 'doing' | 'done';
export type Visibility = 'shared' | 'private';

export type Task = {
    id: number;
    project_id: number | null;
    title: string;
    description: string | null;
    status: TaskStatus;
    visibility: Visibility;
    start_date: string | null;
    due_date: string | null;
    sort_order: number;
    assignee_ids: number[];
    revision: number;
    completed_at: string | null;
    updated_at: string | null;
    deleted: boolean;
    is_mine: boolean;
    created_by?: number;
    source_type?: string | null;
    source_url?: string | null;
    created_via?: string;
    can: {
        update: boolean;
        delete: boolean;
        restore: boolean;
        change_visibility: boolean;
        change_project: boolean;
        change_assignees: boolean;
    };
};

export type Project = {
    id: number;
    name: string;
    sort_order: number;
    archived: boolean;
    revision: number;
    can_edit_content: boolean;
    can_manage: boolean;
};

export type Member = {
    id: number;
    name: string;
    role: 'admin' | 'employee' | 'contractor' | 'tv';
    role_label: string;
    is_active: boolean;
    email?: string;
};

export type DocumentKind = 'document' | 'minutes';

export type DocumentItem = {
    id: number;
    project_id: number | null;
    kind: DocumentKind;
    visibility: Visibility;
    title: string;
    body?: string;
    excerpt: string;
    meeting_date: string | null;
    participants: string | null;
    revision: number;
    updated_at: string | null;
    deleted: boolean;
    is_mine: boolean;
    can: {
        update: boolean;
        delete: boolean;
        restore: boolean;
        change_visibility: boolean;
        change_project: boolean;
    };
};

export type RevisionItem = {
    id: number;
    entity_type: 'task' | 'document' | 'project' | 'project_access' | 'user';
    entity_id: number;
    version: number;
    action: string;
    actor: { id: number | null; name: string | null };
    created_at: string;
    title: string | null;
    project_id: number | null;
    visibility: Visibility | null;
    before_hidden: boolean;
    changed: string[];
    before?: Record<string, unknown> | null;
    after?: Record<string, unknown> | null;
};

export type Paginated<T> = {
    data: T[];
    meta: {
        page: number;
        per_page: number;
        total: number;
        last_page: number;
        limit: number;
    };
};

export type CandidatePayload = {
    title: string;
    description: string | null;
    project_id: number | null;
    assignee_ids: number[];
    visibility: Visibility;
    start_date: string | null;
    due_date: string | null;
    source_type: string;
};

export type Candidate = {
    id: number;
    source: string;
    status: 'pending' | 'confirmed' | 'rejected';
    payload: CandidatePayload | null;
    warnings: string[];
    revision: number;
    task_id: number | null;
    mail: {
        subject: string | null;
        sender: string | null;
        received_at: string | null;
        url: string;
    } | null;
    created_at: string | null;
};

export const STATUS_LABELS: Record<TaskStatus, string> = {
    todo: '未着手',
    doing: '進行中',
    done: '完了',
};

export const ACTION_LABELS: Record<string, string> = {
    create: '作成',
    update: '更新',
    delete: '削除',
    restore: '復元',
    archive: 'アーカイブ',
    unarchive: 'アーカイブ解除',
    grant: '権限付与',
    change: '権限変更',
    revoke: '権限取消',
    security_change: '役割・有効状態の変更',
};

export const FIELD_LABELS: Record<string, string> = {
    title: 'タイトル',
    description: '説明',
    status: '状態',
    visibility: '公開区分',
    project_id: '案件',
    start_date: '開始日',
    due_date: '期限',
    sort_order: '並び順',
    assignee_ids: '担当者',
    source_type: '情報源の種類',
    source_url: '情報源URL',
    created_via: '作成経路',
    deleted: '削除',
    body: '本文',
    kind: '種類',
    meeting_date: '会議日',
    participants: '参加者',
    name: '名称',
    archived: 'アーカイブ',
    permission: '権限',
    role: '役割',
    is_active: '有効',
};
