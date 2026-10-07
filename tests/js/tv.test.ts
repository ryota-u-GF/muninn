import { describe, expect, it } from 'vite-plus/test';
import {
    BoundaryNotifier,
    DEFAULT_TV_SETTINGS,
    MemoryFiredStore,
    nextRestState,
    pageCount,
    pageSize,
    resolveScreen,
    sanitizeSettings,
    scheduleScreen,
    TEST_AUTO_EXIT_MS,
    tokyoParts,
    validateSettings,
} from '@/lib/tv';

/** 東京時刻を UTC エポックに変換して注入する（端末の時計は使わない） */
const jst = (s: string) => Date.parse(`${s}+09:00`);
const S = DEFAULT_TV_SETTINGS;

describe('tokyoParts', () => {
    it('端末のタイムゾーンに依存せず東京時刻を返す', () => {
        const p = tokyoParts(Date.parse('2026-10-06T23:30:00Z'));
        expect(p.ymd).toBe('2026-10-07');
        expect(p.hh).toBe(8);
        expect(p.mm).toBe(30);
        expect(p.weekday).toBe(3);
    });
});

describe('scheduleScreen（時刻画面）', () => {
    it('平日の各時間帯', () => {
        expect(scheduleScreen(jst('2026-10-07T08:29:59'), S)).toBeNull();
        expect(scheduleScreen(jst('2026-10-07T08:30:00'), S)).toBe('start');
        expect(scheduleScreen(jst('2026-10-07T08:32:59'), S)).toBe('start');
        expect(scheduleScreen(jst('2026-10-07T08:33:00'), S)).toBeNull();
        expect(scheduleScreen(jst('2026-10-07T11:59:59'), S)).toBeNull();
        expect(scheduleScreen(jst('2026-10-07T12:00:00'), S)).toBe('lunch');
        expect(scheduleScreen(jst('2026-10-07T12:59:59'), S)).toBe('lunch');
        expect(scheduleScreen(jst('2026-10-07T13:00:00'), S)).toBe('resume');
        expect(scheduleScreen(jst('2026-10-07T13:03:00'), S)).toBeNull();
        expect(scheduleScreen(jst('2026-10-07T17:29:59'), S)).toBeNull();
        expect(scheduleScreen(jst('2026-10-07T17:30:00'), S)).toBe('end');
        expect(scheduleScreen(jst('2026-10-07T23:59:59'), S)).toBe('end');
        expect(scheduleScreen(jst('2026-10-08T00:00:00'), S)).toBeNull();
    });

    it('土日は休日画面、毎日運用なら通常', () => {
        expect(scheduleScreen(jst('2026-10-10T10:00:00'), S)).toBe('holiday'); // 土
        expect(scheduleScreen(jst('2026-10-11T10:00:00'), S)).toBe('holiday'); // 日
        expect(
            scheduleScreen(jst('2026-10-10T10:00:00'), {
                ...S,
                days: 'everyday',
            }),
        ).toBeNull();
        expect(
            scheduleScreen(jst('2026-10-10T08:31:00'), {
                ...S,
                days: 'everyday',
            }),
        ).toBe('start');
    });
});

describe('設定の検証', () => {
    it('HH:mm と時刻順を必須にする', () => {
        expect(validateSettings(S)).toEqual([]);
        expect(validateSettings({ ...S, start: '8:30' })).toHaveLength(1);
        expect(validateSettings({ ...S, end: '24:00' })).toHaveLength(1);
        expect(
            validateSettings({ ...S, lunchStart: '13:00', lunchEnd: '12:00' }),
        ).toHaveLength(1);
        expect(validateSettings({ ...S, start: '12:00' })).toHaveLength(1);
    });
    it('不正な保存値は初期値に戻す', () => {
        expect(sanitizeSettings({ start: 'xx' })).toEqual(S);
        expect(sanitizeSettings(null)).toEqual(S);
        expect(sanitizeSettings({ days: 'everyday' }).days).toBe('everyday');
    });
});

describe('BoundaryNotifier（時刻境界の一回通知）', () => {
    const make = () => new BoundaryNotifier(new MemoryFiredStore(), () => S);

    it('境界を通過したときに一度だけ鳴る', () => {
        const n = make();
        expect(n.observe(jst('2026-10-07T08:29:50'))).toBeNull(); // 起動直後の初回観測
        expect(n.observe(jst('2026-10-07T08:30:01'))).toBe('start');
        expect(n.observe(jst('2026-10-07T08:30:05'))).toBeNull();
        expect(n.observe(jst('2026-10-07T11:59:59'))).toBeNull(); // 間隔が 90 秒超
        expect(n.observe(jst('2026-10-07T12:00:00'))).toBe('lunch');
        expect(n.observe(jst('2026-10-07T12:59:58'))).toBeNull();
        expect(n.observe(jst('2026-10-07T13:00:10'))).toBe('resume');
        expect(n.observe(jst('2026-10-07T17:29:30'))).toBeNull();
        expect(n.observe(jst('2026-10-07T17:30:20'))).toBe('end');
    });

    it('起動直後に過去の境界を後追いしない', () => {
        const n = make();
        expect(n.observe(jst('2026-10-07T08:30:10'))).toBeNull();
        expect(n.observe(jst('2026-10-07T08:30:20'))).toBeNull();
    });

    it('スリープ復帰（観測間隔 90 秒超）では古い通知を鳴らさない', () => {
        const n = make();
        n.observe(jst('2026-10-07T08:00:00'));
        expect(n.observe(jst('2026-10-07T08:30:30'))).toBeNull();
        expect(n.observe(jst('2026-10-07T08:30:40'))).toBeNull();
    });

    it('境界から 60 秒以上経っていたら鳴らさない', () => {
        const n = make();
        n.observe(jst('2026-10-07T08:29:50'));
        expect(n.observe(jst('2026-10-07T08:31:05'))).toBeNull();
    });

    it('時計の巻き戻しで重複発火しない', () => {
        const n = make();
        n.observe(jst('2026-10-07T08:29:55'));
        expect(n.observe(jst('2026-10-07T08:30:05'))).toBe('start');
        expect(n.observe(jst('2026-10-07T08:29:50'))).toBeNull(); // 巻き戻り
        expect(n.observe(jst('2026-10-07T08:30:02'))).toBeNull(); // 同日・同種は発火済み
    });

    it('休日は鳴らさない／毎日運用なら鳴る', () => {
        const n = make();
        n.observe(jst('2026-10-10T08:29:55'));
        expect(n.observe(jst('2026-10-10T08:30:05'))).toBeNull();
        const every = new BoundaryNotifier(new MemoryFiredStore(), () => ({
            ...S,
            days: 'everyday',
        }));
        every.observe(jst('2026-10-10T08:29:55'));
        expect(every.observe(jst('2026-10-10T08:30:05'))).toBe('start');
    });

    it('発火済みキーは再起動後も重複を防ぐ（共有ストア）', () => {
        const store = new MemoryFiredStore();
        const a = new BoundaryNotifier(store, () => S);
        a.observe(jst('2026-10-07T08:29:55'));
        expect(a.observe(jst('2026-10-07T08:30:05'))).toBe('start');
        const b = new BoundaryNotifier(store, () => S);
        b.observe(jst('2026-10-07T08:29:58'));
        expect(b.observe(jst('2026-10-07T08:30:06'))).toBeNull();
        expect([...store.keys]).toEqual(['2026-10-07:start']);
    });
});

describe('画面休憩と優先順位', () => {
    it('通常 300 秒 → 休憩 30 秒 → 通常', () => {
        const t0 = jst('2026-10-07T10:00:00');
        let st = {
            mode: 'normal' as const,
            since: t0,
            manual: false,
        } as Parameters<typeof nextRestState>[0];
        st = nextRestState(st, t0 + 299_000);
        expect(st.mode).toBe('normal');
        st = nextRestState(st, t0 + 300_000);
        expect(st.mode).toBe('rest');
        st = nextRestState(st, t0 + 329_000);
        expect(st.mode).toBe('rest');
        st = nextRestState(st, t0 + 330_000);
        expect(st.mode).toBe('normal');
    });

    it('手動の画面休憩は自動では戻らない／時計の巻き戻りは数え直す', () => {
        const t0 = jst('2026-10-07T10:00:00');
        const manual = nextRestState(
            { mode: 'rest', since: t0, manual: true },
            t0 + 120_000,
        );
        expect(manual.mode).toBe('rest');
        const rewound = nextRestState(
            { mode: 'normal', since: t0, manual: false },
            t0 - 5000,
        );
        expect(rewound.since).toBe(t0 - 5000);
    });

    it('時刻画面は画面休憩より優先、テストは時刻画面より優先し 5 分で自動終了', () => {
        const rest = { mode: 'rest' as const, since: 0, manual: false };
        const lunch = jst('2026-10-07T12:10:00');
        expect(resolveScreen(null, lunch, S, rest).screen).toBe('lunch');
        expect(
            resolveScreen(null, jst('2026-10-07T10:00:00'), S, rest).screen,
        ).toBe('rest');
        const test = { screen: 'holiday' as const, startedAt: lunch };
        expect(resolveScreen(test, lunch + 1000, S, rest)).toEqual({
            screen: 'holiday',
            testing: true,
        });
        expect(resolveScreen(test, lunch + TEST_AUTO_EXIT_MS, S, rest)).toEqual(
            { screen: 'lunch', testing: false },
        );
    });
});

describe('ページ割り', () => {
    it('画面サイズでカード数を決める', () => {
        expect(pageSize(700, 1080)).toBe(1);
        expect(pageSize(1920, 600)).toBe(1);
        expect(pageSize(1280, 800)).toBe(4);
        expect(pageSize(1920, 1080)).toBe(6);
        expect(pageCount(0, 6)).toBe(1);
        expect(pageCount(13, 6)).toBe(3);
    });
});
