import { Fragment, type KeyboardEvent, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { router, usePage, usePoll } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowRight,
    Check,
    CheckCheck,
    Clock,
    Download,
    ExternalLink,
    FileText,
    Lock,
    MapPin,
    Search,
    SendHorizontal,
    User,
    X,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Conversation {
    id: number;
    name: string | null;
    contact_name: string | null;
    wa_id: string;
    customer: { id: number; name: string } | null;
    last_message_at: string | null;
    last_preview: string | null;
    last_direction: 'inbound' | 'outbound' | null;
    last_status: string | null;
    unread_count: number;
    window_open: boolean;
    window_closes_at?: string | null;
}

interface Message {
    id: number;
    direction: 'inbound' | 'outbound';
    type: string;
    body: string | null;
    status: string;
    at: string | null;
    media_url: string | null;
    media_mime: string | null;
    media_filename: string | null;
    failure_reason: string | null;
    sent_by_name: string | null;
    url: string | null;
    location: { latitude?: number; longitude?: number; name?: string; address?: string } | null;
}

interface Thread {
    conversation: Conversation;
    has_more: boolean;
    messages: Message[];
}

interface Props {
    conversations: Conversation[];
    thread: Thread | null;
    filters: { q: string; filter: 'all' | 'unread'; c: number | null };
    configured: boolean;
    may: { send: boolean };
}

/*
 * ألوانُ واتساب ويب كما هي — لوحةٌ واحدة تقرؤها الشاشةُ كلُّها، فلا يفترق
 * أخضرُ الشارة عن أخضر الفلتر.
 */
const WA = {
    panel: '#f0f2f5',
    border: '#e9edef',
    text: '#111b21',
    muted: '#667781',
    icon: '#54656f',
    green: '#00a884',
    badge: '#25d366',
    out: '#d9fdd3',
    in: '#ffffff',
    chat: '#efeae2',
    blue: '#53bdeb',
    link: '#027eb5',
    hover: '#f5f6f6',
    active: '#f0f2f5',
} as const;

/* خلفيّةُ المحادثة: نقشٌ خفيف فوق البيج — مرسومٌ هنا لا منسوخ */
const DOODLE =
    "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120' viewBox='0 0 120 120'%3E%3Cg fill='none' stroke='%23000' stroke-opacity='.06' stroke-width='1.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M14 18h20a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H22l-6 5v-5h-2a4 4 0 0 1-4-4V22a4 4 0 0 1 4-4z'/%3E%3Cpath d='M86 14l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z'/%3E%3Cpath d='M60 64c-4-6-14-4-14 3 0 6 14 13 14 13s14-7 14-13c0-7-10-9-14-3z'/%3E%3Ccircle cx='98' cy='76' r='8'/%3E%3Cpath d='M18 88c6 0 6 8 12 8s6-8 12-8'/%3E%3Cpath d='M78 100h14M85 93v14'/%3E%3Cpath d='M46 32l6-6M58 108l-4-8 8 2z'/%3E%3C/g%3E%3C/svg%3E\")";

const bubbleShadow = '0 1px 0.5px rgba(11,20,26,.13)';

function useLocale() {
    const { locale } = usePage<PageProps>().props;
    return locale === 'en' ? 'en-GB' : 'ar-u-nu-latn';
}

function sameDay(a: Date, b: Date) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

/** «الأمس» يُحسب من منتصف الليل، لا من أربعٍ وعشرين ساعة */
function dayOffset(d: Date) {
    const start = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    return Math.round((start(new Date()) - start(d)) / 86_400_000);
}

export default function WhatsAppIndex({ conversations, thread, filters, configured, may }: Props) {
    const t = useTranslate();
    const locale = useLocale();
    const [q, setQ] = useState(filters.q ?? '');
    const openId = thread?.conversation.id ?? null;

    /* الواردُ بلا تحديث: القائمةُ والمحادثةُ وحدَهما كلَّ خمس ثوانٍ */
    usePoll(5000, { only: ['conversations', 'thread'] });

    const visit = (params: Record<string, string | number | undefined>) =>
        router.get(
            route('admin.whatsapp.index'),
            { q: filters.q || undefined, filter: filters.filter === 'unread' ? 'unread' : undefined, c: filters.c ?? undefined, ...params },
            { preserveState: true, preserveScroll: true, replace: true, only: ['conversations', 'thread', 'filters'] },
        );

    /* البحثُ بعد أن يكفّ عن الكتابة — لا طلبَ لكلّ حرف */
    useEffect(() => {
        if (q === (filters.q ?? '')) return;
        const id = window.setTimeout(() => visit({ q: q || undefined }), 300);
        return () => window.clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    const time = (iso: string | null) =>
        iso ? new Intl.DateTimeFormat(locale, { hour: 'numeric', minute: '2-digit' }).format(new Date(iso)) : '';

    const listDate = (iso: string | null) => {
        if (!iso) return '';
        const d = new Date(iso);
        const off = dayOffset(d);
        if (off === 0) return time(iso);
        if (off === 1) return t('أمس');
        if (off < 7) return new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(d);
        return new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d);
    };

    return (
        <AdminLayout title="واتساب" bare>
            <div
                className="flex h-[calc(100dvh-4rem-2rem)] min-h-[520px] overflow-hidden rounded-lg border lg:h-[calc(100dvh-4rem-3rem)]"
                style={{ borderColor: WA.border, color: WA.text, fontFamily: 'inherit' }}
            >
                {/* ─────────── قائمة المحادثات ─────────── */}
                <aside
                    className={`${openId ? 'hidden md:flex' : 'flex'} w-full shrink-0 flex-col border-e bg-white md:w-[38%] md:max-w-[420px] md:min-w-[300px]`}
                    style={{ borderColor: WA.border }}
                >
                    <header className="flex h-[64px] shrink-0 items-center justify-between px-4">
                        <h1 className="text-[22px] font-bold" style={{ color: WA.text }}>
                            {t('الدردشات')}
                        </h1>
                    </header>

                    {!configured && (
                        <div className="mx-3 mb-2 flex items-start gap-2 rounded-lg px-3 py-2 text-[13px]" style={{ background: '#fff4ce', color: '#54656f' }}>
                            <AlertCircle className="mt-0.5 size-4 shrink-0" style={{ color: '#e9a100' }} />
                            <span>{t('واتساب غير موصول على الخادم — لن تصل رسائل جديدة ولا يُرسَل رد حتى تُضبط مفاتيح Meta.')}</span>
                        </div>
                    )}

                    <div className="px-3 pb-2">
                        <label className="flex h-[35px] items-center gap-3 rounded-lg px-3" style={{ background: WA.panel }}>
                            <Search className="size-[18px] shrink-0" style={{ color: WA.icon }} />
                            <input
                                type="search"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder={t('ابحث عن دردشة')}
                                className="w-full border-0 bg-transparent p-0 text-[14px] outline-none placeholder:text-[#667781] focus:ring-0 [&::-webkit-search-cancel-button]:hidden"
                            />
                            {q && (
                                <button type="button" onClick={() => setQ('')} aria-label={t('مسح')}>
                                    <X className="size-4" style={{ color: WA.icon }} />
                                </button>
                            )}
                        </label>
                    </div>

                    <div className="flex gap-2 px-3 pb-2">
                        {(['all', 'unread'] as const).map((f) => {
                            const active = filters.filter === f;
                            return (
                                <button
                                    key={f}
                                    type="button"
                                    onClick={() => visit({ filter: f === 'unread' ? 'unread' : undefined })}
                                    className="rounded-full px-3 py-1 text-[14px] transition-colors"
                                    style={active ? { background: '#d9fdd3', color: '#008069' } : { background: WA.panel, color: WA.icon }}
                                >
                                    {f === 'all' ? t('الكل') : t('غير مقروءة')}
                                </button>
                            );
                        })}
                    </div>

                    <ul className="min-h-0 flex-1 overflow-y-auto">
                        {conversations.length === 0 && (
                            <li className="px-6 py-10 text-center text-[14px]" style={{ color: WA.muted }}>
                                {filters.q || filters.filter === 'unread' ? t('لا دردشات تطابق البحث.') : t('لا دردشات بعد — تظهر هنا حين يكتب عميلٌ إلى رقم الشركة أو تُرسل إليه رسالة.')}
                            </li>
                        )}
                        {conversations.map((c) => {
                            const selected = c.id === openId;
                            const unread = c.unread_count > 0;
                            return (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        onClick={() => visit({ c: c.id })}
                                        className="flex h-[72px] w-full items-center gap-3 ps-3 text-start transition-colors"
                                        style={{ background: selected ? WA.active : undefined }}
                                        onMouseEnter={(e) => !selected && (e.currentTarget.style.background = WA.hover)}
                                        onMouseLeave={(e) => !selected && (e.currentTarget.style.background = '')}
                                    >
                                        <Avatar size={49} />
                                        <div className="flex h-full min-w-0 flex-1 flex-col justify-center border-b pe-4" style={{ borderColor: WA.border }}>
                                            <div className="flex items-baseline justify-between gap-2">
                                                <span className="truncate text-[17px]" style={{ color: WA.text }}>
                                                    {c.name || <span dir="ltr">+{c.wa_id}</span>}
                                                </span>
                                                <span className="shrink-0 text-[12px]" style={{ color: unread ? '#1fa855' : WA.muted }}>
                                                    {listDate(c.last_message_at)}
                                                </span>
                                            </div>
                                            <div className="mt-0.5 flex items-center justify-between gap-2">
                                                <span className="flex min-w-0 items-center gap-1 text-[14px]" style={{ color: WA.muted }}>
                                                    {c.last_direction === 'outbound' && <Ticks status={c.last_status} size={16} />}
                                                    <span className="truncate">{c.last_preview}</span>
                                                </span>
                                                {unread && (
                                                    <span
                                                        className="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1.5 text-[12px] font-semibold text-white"
                                                        style={{ background: WA.badge }}
                                                    >
                                                        {c.unread_count}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </aside>

                {/* ─────────── المحادثة ─────────── */}
                <section className={`${openId ? 'flex' : 'hidden md:flex'} min-w-0 flex-1 flex-col`}>
                    {thread ? (
                        <ChatPane
                            key={thread.conversation.id}
                            thread={thread}
                            canSend={may.send && configured}
                            time={time}
                            onBack={() => visit({ c: undefined })}
                        />
                    ) : (
                        <EmptyPane />
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}

/* ─────────────────────────── المحادثة المفتوحة ─────────────────────────── */

function ChatPane({ thread, canSend, time, onBack }: { thread: Thread; canSend: boolean; time: (iso: string | null) => string; onBack: () => void }) {
    const t = useTranslate();
    const locale = useLocale();
    const { conversation, messages } = thread;
    const scroller = useRef<HTMLDivElement>(null);
    const stick = useRef(true);
    const [draft, setDraft] = useState('');
    const [pending, setPending] = useState<{ key: number; body: string }[]>([]);
    const input = useRef<HTMLTextAreaElement>(null);

    /* يبقى في القاع ما دام فيه — ومن صعد يقرأ لا يُسحب إلى الأسفل مع كلّ وارد */
    useLayoutEffect(() => {
        const el = scroller.current;
        if (el && stick.current) el.scrollTop = el.scrollHeight;
    }, [messages.length, pending.length]);

    const onScroll = () => {
        const el = scroller.current;
        if (el) stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    };

    const dayLabel = (iso: string | null) => {
        if (!iso) return '';
        const d = new Date(iso);
        const off = dayOffset(d);
        if (off === 0) return t('اليوم');
        if (off === 1) return t('أمس');
        if (off < 7) return new Intl.DateTimeFormat(locale, { weekday: 'long' }).format(d);
        return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', year: 'numeric' }).format(d);
    };

    const send = () => {
        const body = draft.trim();
        if (!body) return;
        const key = Date.now();
        setPending((p) => [...p, { key, body }]);
        setDraft('');
        stick.current = true;
        router.post(
            route('admin.whatsapp.reply', conversation.id),
            { body },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['thread', 'conversations', 'flash'],
                onFinish: () => setPending((p) => p.filter((x) => x.key !== key)),
                onError: () => setDraft(body),
            },
        );
        input.current?.focus();
    };

    const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
            e.preventDefault();
            send();
        }
    };

    /* الحقلُ يطول مع النصّ إلى ستّة أسطر ثمّ يمرّر — كما في واتساب */
    useLayoutEffect(() => {
        const el = input.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 6 * 20 + 18)}px`;
    }, [draft]);

    const rows = useMemo(() => {
        const out: { day?: string; msg: Message; first: boolean }[] = [];
        let prev: Message | null = null;
        for (const m of messages) {
            const d = m.at ? new Date(m.at) : null;
            const newDay = !prev || !prev.at || !d || !sameDay(new Date(prev.at), d);
            out.push({ day: newDay ? dayLabel(m.at) : undefined, msg: m, first: newDay || prev?.direction !== m.direction });
            prev = m;
        }
        return out;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [messages, locale]);

    const title = conversation.name || `+${conversation.wa_id}`;

    return (
        <>
            <header className="flex h-[59px] shrink-0 items-center gap-3 px-4" style={{ background: WA.panel, borderInlineStart: `1px solid ${WA.border}` }}>
                <button type="button" onClick={onBack} className="md:hidden" aria-label={t('رجوع')}>
                    <ArrowRight className="size-6 rtl:rotate-0 ltr:rotate-180" style={{ color: WA.icon }} />
                </button>
                <Avatar size={40} />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[16px]" style={{ color: WA.text }}>
                        {title}
                    </div>
                    <div className="truncate text-[13px]" style={{ color: WA.muted }}>
                        <span dir="ltr">+{conversation.wa_id}</span>
                        {conversation.customer && conversation.contact_name && conversation.contact_name !== conversation.customer.name && (
                            <> · ~{conversation.contact_name}</>
                        )}
                        {!conversation.customer && <> · {t('ليس مربوطًا بعميل')}</>}
                    </div>
                </div>
            </header>

            <div
                ref={scroller}
                onScroll={onScroll}
                className="min-h-0 flex-1 overflow-y-auto px-[4%] py-3 lg:px-[7%]"
                style={{ backgroundColor: WA.chat, backgroundImage: DOODLE }}
            >
                {thread.has_more && (
                    <p className="mb-2 text-center text-[12px]" style={{ color: WA.muted }}>
                        {t('تُعرض أحدث 300 رسالة.')}
                    </p>
                )}

                <div className="mx-auto mb-3 flex max-w-[520px] items-start justify-center gap-1.5 rounded-lg px-3 py-1.5 text-center text-[12.5px]" style={{ background: '#ffeecd', color: '#54656f', boxShadow: bubbleShadow }}>
                    <Lock className="mt-0.5 size-3 shrink-0" />
                    <span>{t('هذه المحادثة عبر رقم الشركة على واتساب للأعمال، ويراها من يملك قسم المراسلات.')}</span>
                </div>

                {rows.map(({ day, msg, first }) => (
                    <Fragment key={msg.id}>
                        {day && (
                            <div className="my-3 flex justify-center">
                                <span className="rounded-lg bg-white px-3 py-1.5 text-[12.5px]" style={{ color: WA.icon, boxShadow: bubbleShadow }}>
                                    {day}
                                </span>
                            </div>
                        )}
                        <Bubble msg={msg} first={first} time={time} />
                    </Fragment>
                ))}

                {pending.map((p) => (
                    <Bubble
                        key={`p${p.key}`}
                        first={false}
                        time={time}
                        msg={{ id: -p.key, direction: 'outbound', type: 'text', body: p.body, status: 'queued', at: new Date().toISOString(), media_url: null, media_mime: null, media_filename: null, failure_reason: null, sent_by_name: null, url: null, location: null }}
                    />
                ))}
            </div>

            {/* ─────────── الكتابة ─────────── */}
            <footer className="shrink-0 px-4 py-[5px]" style={{ background: WA.panel }}>
                {!conversation.window_open ? (
                    <div className="flex items-center justify-center gap-2 py-3 text-center text-[13.5px]" style={{ color: WA.muted }}>
                        <Clock className="size-4 shrink-0" />
                        <span>{t('مضى يومٌ على آخر رسالةٍ من العميل — لا يُرسَل الآن إلّا قالبٌ معتمَد من شاشة المستند.')}</span>
                    </div>
                ) : !canSend ? (
                    <div className="py-3 text-center text-[13.5px]" style={{ color: WA.muted }}>
                        {t('لا تملك صلاحية الرد، أو واتساب غير موصول.')}
                    </div>
                ) : (
                    <div className="flex min-h-[52px] items-end gap-2 py-[5px]">
                        <div className="flex min-h-[42px] flex-1 items-center rounded-lg bg-white px-3 py-[9px]">
                            <textarea
                                ref={input}
                                rows={1}
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                onKeyDown={onKey}
                                placeholder={t('اكتب رسالة')}
                                maxLength={4096}
                                className="block max-h-[138px] w-full resize-none border-0 bg-transparent p-0 text-[15px] leading-5 outline-none placeholder:text-[#667781] focus:ring-0"
                                autoFocus
                            />
                        </div>
                        <button
                            type="button"
                            onClick={send}
                            disabled={!draft.trim()}
                            aria-label={t('إرسال')}
                            className="mb-[3px] flex size-[42px] shrink-0 items-center justify-center rounded-full text-white transition-opacity disabled:opacity-40"
                            style={{ background: WA.green }}
                        >
                            <SendHorizontal className="size-5 rtl:rotate-180" />
                        </button>
                    </div>
                )}
            </footer>
        </>
    );
}

/* ─────────────────────────── الفقاعة ─────────────────────────── */

function Bubble({ msg, first, time }: { msg: Message; first: boolean; time: (iso: string | null) => string }) {
    const t = useTranslate();
    const out = msg.direction === 'outbound';
    const bg = out ? WA.out : WA.in;
    const isImage = (msg.type === 'image' || msg.type === 'sticker') && msg.media_url;

    const meta = (
        <span className="ms-2 inline-flex translate-y-[3px] items-center gap-[3px] whitespace-nowrap align-bottom text-[11px]" style={{ color: WA.muted, float: 'inline-end' }}>
            {time(msg.at)}
            {out && <Ticks status={msg.status} size={16} />}
        </span>
    );

    return (
        <div className={`flex ${out ? 'justify-end' : 'justify-start'} ${first ? 'mt-3' : 'mt-[2px]'}`}>
            <div
                className={`relative max-w-[85%] rounded-[7.5px] md:max-w-[65%] ${first ? (out ? 'rounded-se-none' : 'rounded-ss-none') : ''} ${isImage ? 'p-[3px]' : 'px-[9px] pt-[6px] pb-[8px]'}`}
                style={{ background: bg, boxShadow: bubbleShadow }}
            >
                {first && <Tail side={out ? 'end' : 'start'} color={bg} />}

                {msg.type === 'template' && (
                    <div className="mb-1 text-[12px] font-medium" style={{ color: '#008069' }}>
                        {t('رسالة قالب')}
                        {msg.sent_by_name ? ` · ${msg.sent_by_name}` : ''}
                    </div>
                )}
                {out && msg.type === 'text' && msg.sent_by_name && first && (
                    <div className="mb-0.5 text-[12px] font-medium" style={{ color: '#008069' }}>
                        {msg.sent_by_name}
                    </div>
                )}

                <Content msg={msg} />

                {isImage ? (
                    <div className="px-[6px] pb-[3px]">
                        {msg.body && <span className="whitespace-pre-wrap break-words text-[14.2px] leading-[19px]">{msg.body}</span>}
                        {meta}
                        <span className="clear-both block" />
                    </div>
                ) : (
                    <div className="text-[14.2px] leading-[19px]">
                        {msg.body && msg.type !== 'location' && <span className="whitespace-pre-wrap break-words">{msg.body}</span>}
                        {meta}
                        <span className="clear-both block" />
                    </div>
                )}

                {msg.type === 'template' && msg.url && (
                    <a
                        href={msg.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="-mx-[9px] -mb-[8px] mt-1.5 flex items-center justify-center gap-1.5 border-t py-2.5 text-[14px]"
                        style={{ borderColor: 'rgba(0,0,0,.08)', color: WA.link }}
                    >
                        <ExternalLink className="size-4" />
                        {t('فتح الرابط')}
                    </a>
                )}

                {msg.status === 'failed' && (
                    <div className="mt-1 flex items-start gap-1 text-[12px]" style={{ color: '#ea0038' }}>
                        <AlertCircle className="mt-px size-3.5 shrink-0" />
                        <span>{msg.failure_reason || t('لم تُرسَل')}</span>
                    </div>
                )}
            </div>
        </div>
    );
}

function Content({ msg }: { msg: Message }) {
    const t = useTranslate();

    if ((msg.type === 'image' || msg.type === 'sticker') && msg.media_url) {
        return (
            <a href={msg.media_url} target="_blank" rel="noopener noreferrer" className="block">
                <img
                    src={msg.media_url}
                    alt=""
                    loading="lazy"
                    className={`block rounded-[6px] object-cover ${msg.type === 'sticker' ? 'size-[190px] bg-transparent object-contain' : 'max-h-[330px] w-[330px] max-w-full'}`}
                />
            </a>
        );
    }

    if (msg.type === 'video' && msg.media_url) {
        return <video src={msg.media_url} controls preload="metadata" className="mb-1 block max-h-[330px] w-[330px] max-w-full rounded-[6px] bg-black" />;
    }

    if (msg.type === 'audio' && msg.media_url) {
        return <audio src={msg.media_url} controls preload="none" className="mb-1 block h-[40px] w-[260px] max-w-full" />;
    }

    if (msg.type === 'document' && msg.media_url) {
        return (
            <a
                href={msg.media_url}
                className="mb-1 flex w-[280px] max-w-full items-center gap-3 rounded-[6px] px-3 py-2.5"
                style={{ background: 'rgba(11,20,26,.05)' }}
            >
                <FileText className="size-8 shrink-0" style={{ color: '#e5484d' }} />
                <span className="min-w-0 flex-1 truncate text-[14px]">{msg.media_filename || t('مستند')}</span>
                <Download className="size-5 shrink-0" style={{ color: WA.icon }} />
            </a>
        );
    }

    if (msg.type === 'location' && msg.location?.latitude != null) {
        const href = `https://maps.google.com/?q=${msg.location.latitude},${msg.location.longitude}`;
        return (
            <a href={href} target="_blank" rel="noopener noreferrer" className="mb-1 flex w-[260px] max-w-full items-start gap-2 rounded-[6px] px-3 py-2.5" style={{ background: 'rgba(11,20,26,.05)' }}>
                <MapPin className="mt-0.5 size-5 shrink-0" style={{ color: '#e5484d' }} />
                <span className="min-w-0 text-[14px]">
                    <span className="block truncate">{msg.location.name || t('موقع')}</span>
                    {msg.location.address && (
                        <span className="block truncate text-[12px]" style={{ color: WA.muted }}>
                            {msg.location.address}
                        </span>
                    )}
                </span>
            </a>
        );
    }

    if (!msg.body && msg.type !== 'text' && msg.type !== 'template') {
        return (
            <div className="mb-1 text-[13.5px] italic" style={{ color: WA.muted }}>
                {t('رسالة غير مدعومة — افتحها من هاتف الشركة.')}
            </div>
        );
    }

    return null;
}

/* ذيلُ الفقاعة الأولى في كلّ مجموعة — في زاويتها العليا من جهة صاحبها */
function Tail({ side, color }: { side: 'start' | 'end'; color: string }) {
    return (
        <svg
            viewBox="0 0 8 13"
            width="8"
            height="13"
            aria-hidden
            className={`absolute top-0 ${side === 'end' ? '-end-2 ltr:-scale-x-100' : '-start-2 rtl:-scale-x-100'}`}
        >
            <path d="M0 0h8v11.5C6.5 8 3.5 3.5 0 0z" fill={color} />
        </svg>
    );
}

/* ✓ أُرسلت · ✓✓ وصلت · ✓✓ زرقاء قُرئت · ساعةٌ في الطريق · تنبيهٌ فشلت */
function Ticks({ status, size }: { status: string | null; size: number }) {
    const s = { width: size, height: size };
    if (status === 'queued') return <Clock style={{ ...s, width: size - 3, height: size - 3, color: WA.muted }} />;
    if (status === 'failed' || status === 'bounced') return <AlertCircle style={{ ...s, width: size - 2, height: size - 2, color: '#ea0038' }} />;
    if (status === 'read') return <CheckCheck style={{ ...s, color: WA.blue }} />;
    if (status === 'delivered') return <CheckCheck style={{ ...s, color: WA.muted }} />;
    if (status === 'sent') return <Check style={{ ...s, color: WA.muted }} />;
    return null;
}

function Avatar({ size }: { size: number }) {
    return (
        <span className="flex shrink-0 items-center justify-center overflow-hidden rounded-full" style={{ width: size, height: size, background: '#dfe5e7' }}>
            <User style={{ width: size * 0.6, height: size * 0.6, color: '#ffffff' }} fill="#ffffff" strokeWidth={0} />
        </span>
    );
}

function EmptyPane() {
    const t = useTranslate();
    return (
        <div className="flex h-full flex-col items-center justify-center border-b-[6px] px-8 text-center" style={{ background: WA.panel, borderColor: WA.green }}>
            <div className="mb-7 flex size-[120px] items-center justify-center rounded-full" style={{ background: '#e2e7ea' }}>
                <SendHorizontal className="size-14 rtl:rotate-180" style={{ color: '#aebac1' }} />
            </div>
            <h2 className="text-[32px] font-light" style={{ color: '#41525d' }}>
                {t('واتساب الشركة')}
            </h2>
            <p className="mt-4 max-w-[460px] text-[14px] leading-6" style={{ color: WA.muted }}>
                {t('اختر دردشة لقراءتها والرد عليها. ما يكتبه العملاء إلى رقم الشركة يصل هنا، ورسائل المستندات تظهر في محادثة كل عميل.')}
            </p>
            <p className="mt-10 flex items-center gap-1.5 text-[13px]" style={{ color: '#8696a0' }}>
                <Lock className="size-3" />
                {t('الرد النصي متاح خلال 24 ساعة من آخر رسالة للعميل.')}
            </p>
        </div>
    );
}
