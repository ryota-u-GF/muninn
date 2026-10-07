/**
 * TV ダッシュボードの時刻・画面・通知ロジック（純粋関数）。
 * 時刻はすべて引数で受け取り（注入時計）、端末のタイムゾーンに依存せず Asia/Tokyo で判定する。
 */

export type TvDays = 'weekdays' | 'everyday';

export type TvSettings = {
    days: TvDays;
    start: string; // 始業 HH:mm
    lunchStart: string; // 昼休憩開始
    lunchEnd: string; // 昼休憩終了（＝再開）
    end: string; // 終業
};

export const DEFAULT_TV_SETTINGS: TvSettings = {
    days: 'weekdays',
    start: '08:30',
    lunchStart: '12:00',
    lunchEnd: '13:00',
    end: '17:30',
};

export type ScheduleScreen = 'start' | 'lunch' | 'resume' | 'end' | 'holiday';
export type Boundary = 'start' | 'lunch' | 'resume' | 'end';
export type TestScreen = ScheduleScreen | 'rest';

/** 始業・再開の表示時間（分） */
export const SHORT_SCREEN_MINUTES = 3;
export const NORMAL_SECONDS = 300;
export const REST_SECONDS = 30;
export const TEST_AUTO_EXIT_MS = 5 * 60 * 1000;
export const REFRESH_MS = 30_000;
export const FETCH_TIMEOUT_MS = 15_000;
export const PAGE_ROTATE_MS = 15_000;
export const SHIFT_MS = 30_000;
/** 通知：観測間隔がこれを超えたら（スリープ復帰など）古い境界では鳴らさない */
export const MAX_OBSERVATION_GAP_MS = 90_000;
/** 通知：境界からこれ以上経過していたら鳴らさない */
export const MAX_BOUNDARY_LATENESS_MS = 60_000;

const TOKYO_OFFSET_MS = 9 * 60 * 60 * 1000; // Asia/Tokyo は夏時間なし

const HHMM = /^([01]\d|2[0-3]):([0-5]\d)$/;

export function parseHHmm(value: string): number | null {
    const m = HHMM.exec(value);

    return m ? Number(m[1]) * 60 + Number(m[2]) : null;
}

/** 設定の検証。HH:mm 形式と 始業 < 休憩開始 < 休憩終了 < 終業 を必須とする */
export function validateSettings(s: TvSettings): string[] {
    const errors: string[] = [];
    const labels: [keyof TvSettings, string][] = [
        ['start', '始業'],
        ['lunchStart', '昼休憩開始'],
        ['lunchEnd', '昼休憩終了'],
        ['end', '終業'],
    ];
    const values: number[] = [];
    for (const [key, label] of labels) {
        const v = parseHHmm(String(s[key]));
        if (v === null) {
            errors.push(
                `${label}は HH:mm 形式（例 08:30）で入力してください。`,
            );
        } else {
            values.push(v);
        }
    }
    if (errors.length === 0) {
        for (let i = 1; i < values.length; i++) {
            if (values[i - 1] >= values[i]) {
                errors.push(
                    '時刻は 始業 < 昼休憩開始 < 昼休憩終了 < 終業 の順にしてください。',
                );
                break;
            }
        }
    }
    if (s.days !== 'weekdays' && s.days !== 'everyday') {
        errors.push('稼働日の設定が正しくありません。');
    }

    return errors;
}

export function sanitizeSettings(raw: unknown): TvSettings {
    if (!raw || typeof raw !== 'object') {
        return { ...DEFAULT_TV_SETTINGS };
    }
    const candidate = {
        ...DEFAULT_TV_SETTINGS,
        ...(raw as Partial<TvSettings>),
    };

    return validateSettings(candidate).length === 0
        ? candidate
        : { ...DEFAULT_TV_SETTINGS };
}

export type TokyoParts = {
    ymd: string;
    weekday: number; // 0=日
    minutes: number; // 0:00 からの経過分（小数）
    hh: number;
    mm: number;
    ss: number;
};

export function tokyoParts(nowMs: number): TokyoParts {
    const d = new Date(nowMs + TOKYO_OFFSET_MS);
    const hh = d.getUTCHours();
    const mm = d.getUTCMinutes();
    const ss = d.getUTCSeconds();

    return {
        ymd: d.toISOString().slice(0, 10),
        weekday: d.getUTCDay(),
        minutes: hh * 60 + mm + ss / 60 + d.getUTCMilliseconds() / 60000,
        hh,
        mm,
        ss,
    };
}

export function isWorkday(parts: TokyoParts, s: TvSettings): boolean {
    return s.days === 'everyday' || (parts.weekday >= 1 && parts.weekday <= 5);
}

/**
 * 時刻画面。通常表示・画面休憩より優先する。
 * 始業 3 分／昼休憩／再開 3 分／終業〜当日末／非稼働日は休日。
 */
export function scheduleScreen(
    nowMs: number,
    s: TvSettings,
): ScheduleScreen | null {
    const p = tokyoParts(nowMs);
    if (!isWorkday(p, s)) {
        return 'holiday';
    }
    const t = p.minutes;
    const start = parseHHmm(s.start)!;
    const lunchStart = parseHHmm(s.lunchStart)!;
    const lunchEnd = parseHHmm(s.lunchEnd)!;
    const end = parseHHmm(s.end)!;

    if (t >= end) return 'end';
    if (t >= start && t < start + SHORT_SCREEN_MINUTES) return 'start';
    if (t >= lunchStart && t < lunchEnd) return 'lunch';
    if (t >= lunchEnd && t < lunchEnd + SHORT_SCREEN_MINUTES) return 'resume';

    return null;
}

/** ある日（東京）の HH:mm の UTC エポック ms */
export function tokyoInstant(ymd: string, minutes: number): number {
    const [y, m, d] = ymd.split('-').map(Number);

    return Date.UTC(y, m - 1, d, 0, minutes) - TOKYO_OFFSET_MS;
}

export function boundaries(
    ymd: string,
    s: TvSettings,
): { kind: Boundary; at: number }[] {
    return [
        { kind: 'start', at: tokyoInstant(ymd, parseHHmm(s.start)!) },
        { kind: 'lunch', at: tokyoInstant(ymd, parseHHmm(s.lunchStart)!) },
        { kind: 'resume', at: tokyoInstant(ymd, parseHHmm(s.lunchEnd)!) },
        { kind: 'end', at: tokyoInstant(ymd, parseHHmm(s.end)!) },
    ];
}

export interface FiredStore {
    has(key: string): boolean;
    add(key: string): void;
}

/** メモリ上の発火済みキー（localStorage 版は画面側で用意） */
export class MemoryFiredStore implements FiredStore {
    keys = new Set<string>();
    has(key: string) {
        return this.keys.has(key);
    }
    add(key: string) {
        this.keys.add(key);
    }
}

/**
 * 時刻境界の一回通知。
 * - 起動直後の観測では鳴らさない（過去の境界を後追いしない）
 * - 観測間隔が 90 秒を超えた場合（スリープ復帰など）は鳴らさない
 * - 境界から 60 秒以上経っていたら鳴らさない
 * - 時計が巻き戻った場合は鳴らさず、日付＋種類の発火済みキーで重複も防ぐ
 */
export class BoundaryNotifier {
    private last: number | null = null;

    constructor(
        private store: FiredStore,
        private getSettings: () => TvSettings,
    ) {}

    observe(nowMs: number): Boundary | null {
        const last = this.last;
        this.last = nowMs;
        if (
            last === null ||
            nowMs <= last ||
            nowMs - last > MAX_OBSERVATION_GAP_MS
        ) {
            return null;
        }
        const settings = this.getSettings();
        const parts = tokyoParts(nowMs);
        if (!isWorkday(parts, settings)) {
            return null;
        }
        for (const b of boundaries(parts.ymd, settings)) {
            if (
                b.at > last &&
                b.at <= nowMs &&
                nowMs - b.at < MAX_BOUNDARY_LATENESS_MS
            ) {
                const key = `${parts.ymd}:${b.kind}`;
                if (this.store.has(key)) {
                    return null;
                }
                this.store.add(key);

                return b.kind;
            }
        }

        return null;
    }
}

/** 画面サイズごとの 1 ページのカード数 */
export function pageSize(width: number, height: number): number {
    if (width < 760 || height < 620) return 1;
    if (height < 850) return 4;

    return 6;
}

export function pageCount(total: number, size: number): number {
    return Math.max(1, Math.ceil(total / size));
}

/** 通常表示 300 秒 → 画面休憩 30 秒 → 通常表示 の繰り返し */
export type RestState = {
    mode: 'normal' | 'rest';
    since: number;
    manual: boolean;
};

export function nextRestState(state: RestState, nowMs: number): RestState {
    if (nowMs < state.since) {
        // 時計の巻き戻り：その時点から数え直す
        return { ...state, since: nowMs };
    }
    const elapsed = (nowMs - state.since) / 1000;
    if (state.mode === 'normal' && elapsed >= NORMAL_SECONDS) {
        return { mode: 'rest', since: nowMs, manual: false };
    }
    if (state.mode === 'rest' && !state.manual && elapsed >= REST_SECONDS) {
        return { mode: 'normal', since: nowMs, manual: false };
    }

    return state;
}

/**
 * テストが有効か（5 分で自動終了）。表示用の時計は 1 秒ごとに更新されるため、
 * 開始直後のわずかな遅れは許容し、大きな巻き戻り（1 分超）だけ終了扱いにする。
 */
export function isTestActive(startedAt: number, nowMs: number): boolean {
    const elapsed = nowMs - startedAt;

    return elapsed < TEST_AUTO_EXIT_MS && elapsed > -60_000;
}

/** 表示する画面の優先順位：テスト > 時刻画面 > 画面休憩 > 通常 */
export function resolveScreen(
    test: { screen: TestScreen; startedAt: number } | null,
    nowMs: number,
    settings: TvSettings,
    rest: RestState,
): { screen: ScheduleScreen | 'rest' | 'normal'; testing: boolean } {
    if (
        test &&
        nowMs - test.startedAt < TEST_AUTO_EXIT_MS &&
        nowMs >= test.startedAt
    ) {
        return { screen: test.screen, testing: true };
    }
    const scheduled = scheduleScreen(nowMs, settings);
    if (scheduled) {
        return { screen: scheduled, testing: false };
    }

    return { screen: rest.mode === 'rest' ? 'rest' : 'normal', testing: false };
}

/** 焼き付き対策として 30 秒ごとに表示位置を少しずらす */
const SHIFTS: [number, number][] = [
    [0, 0],
    [14, 8],
    [-12, 10],
    [10, -12],
    [-14, -8],
    [6, 14],
];

export function shiftFor(nowMs: number): [number, number] {
    return SHIFTS[Math.floor(nowMs / SHIFT_MS) % SHIFTS.length];
}
