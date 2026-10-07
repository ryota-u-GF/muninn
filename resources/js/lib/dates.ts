/** 業務日付は Asia/Tokyo 基準（端末のタイムゾーンに依存しない） */
export const TZ = 'Asia/Tokyo';

const ymdFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});

export function tokyoYmd(date: Date = new Date()): string {
    return ymdFormatter.format(date);
}

/** 'YYYY-MM-DD' を UTC 正午の Date として扱う（日付計算専用） */
export function parseYmd(ymd: string): Date {
    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d, 12));
}

export function formatYmd(date: Date): string {
    return date.toISOString().slice(0, 10);
}

export function addDays(ymd: string, days: number): string {
    const d = parseYmd(ymd);
    d.setUTCDate(d.getUTCDate() + days);

    return formatYmd(d);
}

export function diffDays(from: string, to: string): number {
    return Math.round(
        (parseYmd(to).getTime() - parseYmd(from).getTime()) / 86400000,
    );
}

const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

export function weekday(ymd: string): number {
    return parseYmd(ymd).getUTCDay();
}

export function formatJa(ymd: string | null, withWeekday = true): string {
    if (!ymd) {
        return '未設定';
    }
    const d = parseYmd(ymd);
    const text = `${d.getUTCMonth() + 1}/${d.getUTCDate()}`;

    return withWeekday ? `${text}（${WEEKDAYS[d.getUTCDay()]}）` : text;
}

export function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat('ja-JP', {
        timeZone: TZ,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

export function startOfWeek(ymd: string): string {
    const day = weekday(ymd);

    return addDays(ymd, day === 0 ? -6 : 1 - day);
}

export function weekdayLabel(ymd: string): string {
    return WEEKDAYS[weekday(ymd)];
}
