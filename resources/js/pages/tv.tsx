import { Head } from '@inertiajs/react';
import { Bell, BellOff, Coffee, Moon, Settings, Sun, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { api, ApiError } from '@/lib/api';
import type {
    RestState,
    ScheduleScreen,
    TestScreen,
    TvSettings,
} from '@/lib/tv';
import {
    BoundaryNotifier,
    DEFAULT_TV_SETTINGS,
    FETCH_TIMEOUT_MS,
    nextRestState,
    PAGE_ROTATE_MS,
    pageCount,
    pageSize,
    REFRESH_MS,
    resolveScreen,
    sanitizeSettings,
    shiftFor,
    TEST_AUTO_EXIT_MS,
    tokyoParts,
    validateSettings,
} from '@/lib/tv';
import { cn } from '@/lib/utils';

type TvTask = {
    id: number;
    title: string;
    status: 'todo' | 'doing' | 'done';
    project_id: number | null;
    due_date: string | null;
    assignee_ids: number[];
};
type TvData = {
    generated_at: string;
    today: string;
    users: { id: number; name: string }[];
    projects: { id: number; name: string }[];
    tasks: TvTask[];
};

const SETTINGS_KEY = 'gf.tv.settings.v1';
const FIRED_KEY = 'gf.tv.fired.v1';
const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

function readStorage<T>(key: string, fallback: T): T {
    try {
        const raw = window.localStorage.getItem(key);

        return raw ? (JSON.parse(raw) as T) : fallback;
    } catch {
        return fallback;
    }
}

function writeStorage(key: string, value: unknown) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // 保存できない環境でも表示は続ける
    }
}

/** 端末ごとの発火済みキー（直近 3 日分だけ保持） */
class LocalFiredStore {
    has(key: string) {
        return readStorage<string[]>(FIRED_KEY, []).includes(key);
    }
    add(key: string) {
        const keep = readStorage<string[]>(FIRED_KEY, []).slice(-20);
        writeStorage(FIRED_KEY, [...keep, key]);
    }
}

/** Web Audio のチャイム（ユーザーが有効化した後だけ） */
function playChime(ctx: AudioContext) {
    const t = ctx.currentTime;
    [659.25, 523.25, 587.33, 392.0].forEach((freq, i) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0.0001, t + i * 0.45);
        gain.gain.exponentialRampToValueAtTime(0.25, t + i * 0.45 + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.45 + 0.9);
        osc.connect(gain).connect(ctx.destination);
        osc.start(t + i * 0.45);
        osc.stop(t + i * 0.45 + 1);
    });
}

const SCREEN_TEXT: Record<
    ScheduleScreen,
    { title: string; sub: string; className: string }
> = {
    start: {
        title: 'おはようございます',
        sub: '始業の時間です',
        className: 'bg-gradient-to-br from-sky-500 to-indigo-600 text-white',
    },
    lunch: {
        title: '昼休憩',
        sub: 'しっかり休みましょう',
        className: 'bg-gradient-to-br from-amber-400 to-orange-500 text-white',
    },
    resume: {
        title: '午後の業務を再開します',
        sub: '再開',
        className: 'bg-gradient-to-br from-emerald-500 to-teal-600 text-white',
    },
    end: {
        title: 'お疲れさまでした',
        sub: '終業時刻です。帰宅しましょう',
        className: 'bg-gradient-to-br from-indigo-800 to-slate-900 text-white',
    },
    holiday: {
        title: '本日は休日です',
        sub: 'ゆっくり休みましょう',
        className: 'bg-gradient-to-br from-slate-600 to-slate-800 text-white',
    },
};

const TEST_BUTTONS: { screen: TestScreen; label: string }[] = [
    { screen: 'start', label: '始業' },
    { screen: 'lunch', label: '昼休憩' },
    { screen: 'resume', label: '再開' },
    { screen: 'end', label: '終業' },
    { screen: 'holiday', label: '休日' },
    { screen: 'rest', label: '画面休憩' },
];

function hhmmss(nowMs: number) {
    const p = tokyoParts(nowMs);

    return `${String(p.hh).padStart(2, '0')}:${String(p.mm).padStart(2, '0')}:${String(p.ss).padStart(2, '0')}`;
}

function dateLabel(nowMs: number) {
    const p = tokyoParts(nowMs);
    const [y, m, d] = p.ymd.split('-').map(Number);

    return `${y}年${m}月${d}日（${WEEKDAYS[p.weekday]}）`;
}

export default function TvPage() {
    const [now, setNow] = useState(() => Date.now());
    const [settings, setSettings] = useState<TvSettings>(() =>
        sanitizeSettings(readStorage(SETTINGS_KEY, DEFAULT_TV_SETTINGS)),
    );
    const [data, setData] = useState<TvData | null>(null);
    const [fetchState, setFetchState] = useState<
        'loading' | 'ok' | 'error' | 'unauthenticated'
    >('loading');
    const [rest, setRest] = useState<RestState>(() => ({
        mode: 'normal',
        since: Date.now(),
        manual: false,
    }));
    const [test, setTest] = useState<{
        screen: TestScreen;
        startedAt: number;
    } | null>(null);
    const [panelOpen, setPanelOpen] = useState(false);
    const [draft, setDraft] = useState<TvSettings>(settings);
    const [soundOn, setSoundOn] = useState(false);
    const [page, setPage] = useState(0);
    const [viewport, setViewport] = useState(() => ({
        w: window.innerWidth,
        h: window.innerHeight,
    }));
    const [reducedMotion, setReducedMotion] = useState(
        () =>
            window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ??
            false,
    );
    const audio = useRef<AudioContext | null>(null);
    const settingsRef = useRef(settings);
    useEffect(() => {
        settingsRef.current = settings;
    }, [settings]);
    const notifier = useMemo(
        () =>
            new BoundaryNotifier(
                new LocalFiredStore(),
                () => settingsRef.current,
            ),
        [],
    );

    // 1 秒ごとの時計（Date.now を使うため、ブラウザ試験では時計を注入できる）
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    // 時刻境界の通知音（テスト中も本番の発火判定は独立して動き、テスト操作は発火済みキーを変更しない）
    useEffect(() => {
        const fired = notifier.observe(now);
        if (fired && soundOn && audio.current) {
            playChime(audio.current);
        }
        setRest((r) => nextRestState(r, now));
    }, [now, notifier, soundOn]);

    // テストの 5 分自動終了
    useEffect(() => {
        if (
            test &&
            (now - test.startedAt >= TEST_AUTO_EXIT_MS || now < test.startedAt)
        ) {
            setTest(null);
        }
    }, [now, test]);

    // データ取得：30 秒ごと、15 秒でタイムアウト。失敗時は古いタスクを表示しない。
    const inFlight = useRef(false);
    const load = useCallback(async () => {
        if (inFlight.current) return;
        inFlight.current = true;
        try {
            const res = await api<TvData>('GET', '/api/tv', undefined, {
                timeoutMs: FETCH_TIMEOUT_MS,
            });
            setData(res);
            setFetchState('ok');
        } catch (e) {
            setData(null);
            setFetchState(
                e instanceof ApiError && e.isUnauthenticated
                    ? 'unauthenticated'
                    : 'error',
            );
        } finally {
            inFlight.current = false;
        }
    }, []);

    useEffect(() => {
        void load();
        const timer = window.setInterval(() => void load(), REFRESH_MS);
        const onVisible = () =>
            document.visibilityState === 'visible' && void load();
        const onOnline = () => void load();
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener('online', onOnline);
        const onResize = () =>
            setViewport({ w: window.innerWidth, h: window.innerHeight });
        window.addEventListener('resize', onResize);
        const mq = window.matchMedia?.('(prefers-reduced-motion: reduce)');
        const onMotion = () => setReducedMotion(mq?.matches ?? false);
        mq?.addEventListener('change', onMotion);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener('online', onOnline);
            window.removeEventListener('resize', onResize);
            mq?.removeEventListener('change', onMotion);
        };
    }, [load]);

    // カードのページ巡回（15 秒）
    const size = pageSize(viewport.w, viewport.h);
    const tasks = data?.tasks ?? [];
    const pages = pageCount(tasks.length, size);
    useEffect(() => {
        const timer = window.setInterval(
            () => setPage((p) => p + 1),
            PAGE_ROTATE_MS,
        );

        return () => window.clearInterval(timer);
    }, []);
    const currentPage = page % pages;
    const visible = tasks.slice(currentPage * size, currentPage * size + size);

    const { screen, testing } = resolveScreen(test, now, settings, rest);
    const [dx, dy] = shiftFor(now);
    const userName = (id: number) =>
        data?.users.find((u) => u.id === id)?.name ?? '';
    const projectName = (id: number | null) =>
        id === null
            ? '会社共通'
            : (data?.projects.find((p) => p.id === id)?.name ?? '');

    const enableSound = async () => {
        try {
            audio.current ??= new AudioContext();
            await audio.current.resume();
            setSoundOn(true);
        } catch {
            setSoundOn(false);
        }
    };

    const startTest = (s: TestScreen) => {
        setTest({ screen: s, startedAt: Date.now() });
        // 有効化済みの音だけテスト再生（本番の発火済みキーは変更しない）
        if (soundOn && audio.current && s !== 'rest' && s !== 'holiday') {
            playChime(audio.current);
        }
    };

    const draftErrors = validateSettings(draft);

    return (
        <div
            className="fixed inset-0 overflow-hidden bg-slate-950 text-white select-none"
            data-testid="tv-root"
            data-screen={screen}
            data-testing={testing ? '1' : '0'}
        >
            <Head title="TV表示" />

            <div
                className={cn(
                    'absolute inset-0',
                    !reducedMotion &&
                        'transition-transform duration-[6000ms] ease-in-out',
                )}
                style={{ transform: `translate(${dx}px, ${dy}px)` }}
            >
                {screen === 'normal' && (
                    <div className="flex h-full flex-col bg-slate-50 p-6 text-slate-900">
                        <header className="mb-4 flex items-end justify-between">
                            <div className="flex items-center gap-3">
                                <AppLogoIcon className="size-10 fill-current text-slate-800" />
                                <div>
                                    <div className="text-2xl font-bold">
                                        今日の進行中タスク
                                    </div>
                                    <div className="text-sm text-slate-500">
                                        {dateLabel(now)}
                                    </div>
                                </div>
                            </div>
                            <div className="text-right">
                                <div
                                    className="font-mono text-5xl font-bold tabular-nums"
                                    data-testid="tv-clock"
                                >
                                    {hhmmss(now)}
                                </div>
                                {fetchState === 'ok' && (
                                    <div className="text-sm text-slate-500">
                                        {tasks.length}件・{currentPage + 1}/
                                        {pages}ページ
                                    </div>
                                )}
                            </div>
                        </header>
                        {fetchState === 'unauthenticated' ? (
                            <div
                                className="flex flex-1 flex-col items-center justify-center gap-4 text-center"
                                role="alert"
                            >
                                <div className="text-4xl font-bold">
                                    ログインが必要です
                                </div>
                                <p className="text-xl">
                                    セッションの有効期限が切れました。再ログインしてください。
                                </p>
                                <a
                                    href="/login"
                                    className="rounded bg-slate-900 px-6 py-3 text-xl text-white"
                                >
                                    ログイン画面へ
                                </a>
                            </div>
                        ) : fetchState === 'error' ? (
                            <div
                                className="flex flex-1 flex-col items-center justify-center gap-2 text-center"
                                role="alert"
                                data-testid="tv-error"
                            >
                                <div className="text-4xl font-bold text-red-600">
                                    通信エラー
                                </div>
                                <p className="text-xl">
                                    最新のタスクを取得できません。古い情報は表示していません。自動的に再接続します。
                                </p>
                            </div>
                        ) : fetchState === 'loading' ? (
                            <div className="flex flex-1 items-center justify-center text-2xl">
                                読み込み中…
                            </div>
                        ) : tasks.length === 0 ? (
                            <div className="flex flex-1 items-center justify-center text-3xl text-slate-500">
                                表示するタスクはありません
                            </div>
                        ) : (
                            <div
                                className={cn(
                                    'grid flex-1 gap-4',
                                    size === 1
                                        ? 'grid-cols-1'
                                        : size === 4
                                          ? 'grid-cols-2 grid-rows-2'
                                          : 'grid-cols-3 grid-rows-2',
                                )}
                                data-testid="tv-cards"
                                data-page-size={size}
                            >
                                {visible.map((t) => (
                                    <div
                                        key={t.id}
                                        className="flex flex-col justify-between rounded-2xl border-l-8 bg-white p-5 shadow"
                                        style={{
                                            borderColor:
                                                t.status === 'doing'
                                                    ? '#3b82f6'
                                                    : '#94a3b8',
                                        }}
                                        data-testid="tv-card"
                                    >
                                        <div>
                                            <div className="text-sm text-slate-500">
                                                {projectName(t.project_id)}
                                            </div>
                                            <div className="mt-1 line-clamp-3 text-3xl font-bold">
                                                {t.title}
                                            </div>
                                        </div>
                                        <div className="mt-3 flex flex-wrap items-end justify-between gap-2 text-xl">
                                            <span>
                                                {t.assignee_ids
                                                    .map(userName)
                                                    .filter(Boolean)
                                                    .join('・') || '未割当'}
                                            </span>
                                            <span
                                                className={cn(
                                                    'font-semibold',
                                                    t.due_date &&
                                                        data &&
                                                        t.due_date < data.today
                                                        ? 'text-red-600'
                                                        : t.due_date ===
                                                            data?.today
                                                          ? 'text-orange-600'
                                                          : 'text-slate-500',
                                                )}
                                            >
                                                {t.status === 'doing'
                                                    ? '進行中'
                                                    : '未着手'}
                                                {t.due_date &&
                                                    `・期限 ${Number(t.due_date.slice(5, 7))}/${Number(t.due_date.slice(8))}`}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {screen !== 'normal' && screen !== 'rest' && (
                    <div
                        className={cn(
                            'flex h-full flex-col items-center justify-center gap-6 text-center',
                            SCREEN_TEXT[screen].className,
                        )}
                        data-testid={`tv-screen-${screen}`}
                    >
                        {screen === 'start' ? (
                            <Sun className="size-24" />
                        ) : screen === 'lunch' ? (
                            <Coffee className="size-24" />
                        ) : screen === 'end' || screen === 'holiday' ? (
                            <Moon className="size-24" />
                        ) : (
                            <Sun className="size-24" />
                        )}
                        <div className="text-7xl font-bold">
                            {SCREEN_TEXT[screen].title}
                        </div>
                        <div className="text-4xl">
                            {SCREEN_TEXT[screen].sub}
                        </div>
                        <div className="font-mono text-6xl tabular-nums">
                            {hhmmss(now)}
                        </div>
                        <div className="text-2xl opacity-80">
                            {dateLabel(now)}
                        </div>
                        {fetchState === 'unauthenticated' && (
                            <a
                                href="/login"
                                className="rounded bg-white/20 px-4 py-2"
                            >
                                再ログインが必要です
                            </a>
                        )}
                    </div>
                )}

                {screen === 'rest' && (
                    <div
                        className="relative h-full bg-black"
                        data-testid="tv-screen-rest"
                    >
                        <div
                            className={cn(
                                'absolute flex flex-col items-center gap-3 text-slate-400',
                                !reducedMotion &&
                                    'animate-[gf-drift_30s_ease-in-out_infinite]',
                            )}
                            style={{ left: '30%', top: '35%' }}
                        >
                            <AppLogoIcon className="size-16 fill-current text-slate-600" />
                            <div className="font-mono text-7xl tabular-nums">
                                {hhmmss(now).slice(0, 5)}
                            </div>
                            <div className="text-xl">画面休憩中</div>
                        </div>
                    </div>
                )}
            </div>

            {/* テスト中のバー（常に最前面。設定パネルと重ならないよう左下） */}
            {testing && test && (
                <div
                    className="fixed bottom-4 left-4 z-50 flex flex-wrap items-center gap-2 rounded-lg bg-black/80 p-2 text-sm"
                    data-testid="tv-test-bar"
                >
                    <span className="px-2">
                        画面テスト中（
                        {Math.max(
                            0,
                            Math.ceil(
                                (TEST_AUTO_EXIT_MS - (now - test.startedAt)) /
                                    1000,
                            ),
                        )}
                        秒で自動終了）
                    </span>
                    {TEST_BUTTONS.map((b) => (
                        <button
                            key={b.screen}
                            className={cn(
                                'rounded px-3 py-1',
                                test.screen === b.screen
                                    ? 'bg-white text-black'
                                    : 'bg-white/20',
                            )}
                            onClick={() => startTest(b.screen)}
                        >
                            {b.label}
                        </button>
                    ))}
                    <button
                        className="rounded bg-emerald-500 px-3 py-1 font-semibold"
                        onClick={() => setTest(null)}
                    >
                        通常表示に戻る
                    </button>
                </div>
            )}

            {/* 設定・テストパネル（非モーダル、右側。テストボタンは常に操作できる） */}
            <button
                aria-label="設定とテスト"
                className="fixed top-3 right-3 z-50 rounded-full bg-black/30 p-2 text-white opacity-40 hover:opacity-100 focus:opacity-100"
                onClick={() => {
                    setDraft(settings);
                    setPanelOpen((o) => !o);
                }}
            >
                <Settings className="size-5" />
            </button>
            {panelOpen && (
                <aside
                    className="fixed top-14 right-3 bottom-3 z-40 w-80 overflow-y-auto rounded-lg bg-white p-4 text-sm text-slate-900 shadow-2xl"
                    aria-label="TV設定とテスト"
                >
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="font-semibold">
                            TV設定（この端末のみ）
                        </h2>
                        <button
                            aria-label="閉じる"
                            onClick={() => setPanelOpen(false)}
                        >
                            <X className="size-4" />
                        </button>
                    </div>

                    <section className="mb-4 space-y-2">
                        <h3 className="font-semibold">通知音</h3>
                        <button
                            className={cn(
                                'flex w-full items-center justify-center gap-2 rounded px-3 py-2',
                                soundOn
                                    ? 'bg-emerald-100 text-emerald-900'
                                    : 'bg-slate-900 text-white',
                            )}
                            onClick={() => void enableSound()}
                            data-testid="tv-enable-sound"
                        >
                            {soundOn ? (
                                <Bell className="size-4" />
                            ) : (
                                <BellOff className="size-4" />
                            )}
                            {soundOn
                                ? '通知音は有効です'
                                : '通知音を有効にする'}
                        </button>
                        <p className="text-xs text-slate-500">
                            ブラウザの制限により、このボタンを押した後だけ音が鳴ります。実機スピーカーでの確認は別途行ってください。
                        </p>
                    </section>

                    <section className="mb-4 space-y-2">
                        <h3 className="font-semibold">画面テスト</h3>
                        <p className="text-xs text-slate-500">
                            実際の時刻・設定・本番の通知済み状態は変更しません。5分で自動的に終了します。
                        </p>
                        <div className="grid grid-cols-3 gap-2">
                            {TEST_BUTTONS.map((b) => (
                                <button
                                    key={b.screen}
                                    className="rounded bg-slate-100 px-2 py-2 hover:bg-slate-200"
                                    onClick={() => startTest(b.screen)}
                                    data-testid={`tv-test-${b.screen}`}
                                >
                                    {b.label}
                                </button>
                            ))}
                        </div>
                        <button
                            className="w-full rounded bg-emerald-600 px-3 py-2 text-white disabled:opacity-40"
                            disabled={!testing}
                            onClick={() => setTest(null)}
                        >
                            通常表示に戻る
                        </button>
                        <div className="flex gap-2">
                            <button
                                className="flex-1 rounded bg-slate-100 px-2 py-2"
                                onClick={() =>
                                    setRest({
                                        mode: 'rest',
                                        since: Date.now(),
                                        manual: true,
                                    })
                                }
                            >
                                手動で画面休憩
                            </button>
                            <button
                                className="flex-1 rounded bg-slate-100 px-2 py-2"
                                onClick={() =>
                                    setRest({
                                        mode: 'normal',
                                        since: Date.now(),
                                        manual: false,
                                    })
                                }
                            >
                                休憩から戻る
                            </button>
                        </div>
                    </section>

                    <section className="space-y-2">
                        <h3 className="font-semibold">
                            時刻設定（Asia/Tokyo）
                        </h3>
                        <label className="flex items-center justify-between gap-2">
                            稼働日
                            <select
                                className="rounded border px-2 py-1"
                                value={draft.days}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        days: e.target
                                            .value as TvSettings['days'],
                                    })
                                }
                            >
                                <option value="weekdays">
                                    平日のみ（土日は休日画面）
                                </option>
                                <option value="everyday">毎日</option>
                            </select>
                        </label>
                        {(
                            [
                                ['start', '始業'],
                                ['lunchStart', '昼休憩開始'],
                                ['lunchEnd', '昼休憩終了'],
                                ['end', '終業'],
                            ] as const
                        ).map(([key, label]) => (
                            <label
                                key={key}
                                className="flex items-center justify-between gap-2"
                            >
                                {label}
                                <input
                                    className="w-24 rounded border px-2 py-1 font-mono"
                                    value={draft[key]}
                                    placeholder="HH:mm"
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            [key]: e.target.value,
                                        })
                                    }
                                    aria-label={label}
                                />
                            </label>
                        ))}
                        {draftErrors.length > 0 && (
                            <ul className="list-disc pl-5 text-xs text-red-600">
                                {draftErrors.map((e) => (
                                    <li key={e}>{e}</li>
                                ))}
                            </ul>
                        )}
                        <div className="flex gap-2">
                            <button
                                className="flex-1 rounded bg-slate-900 px-3 py-2 text-white disabled:opacity-40"
                                disabled={draftErrors.length > 0}
                                onClick={() => {
                                    setSettings(draft);
                                    writeStorage(SETTINGS_KEY, draft);
                                }}
                            >
                                保存
                            </button>
                            <button
                                className="flex-1 rounded bg-slate-100 px-3 py-2"
                                onClick={() =>
                                    setDraft({ ...DEFAULT_TV_SETTINGS })
                                }
                            >
                                初期値
                            </button>
                        </div>
                        <p className="text-xs text-slate-500">
                            祝日カレンダーとは連携しません。焼き付き防止を保証するものではないため、TV本体の設定も確認してください。
                        </p>
                    </section>
                </aside>
            )}
        </div>
    );
}
