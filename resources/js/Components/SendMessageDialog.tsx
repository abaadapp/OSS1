import { useEffect, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Mail, MessageCircle, Send } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL } from '@/lib/communications';

/** اسمُ المسار لكلّ نوع — كما سُجّل في `routes/web.php` */
export const SUBJECT_ROUTES: Record<string, string> = {
    quotation: 'admin.quotations',
    project: 'admin.projects',
    customer_invoice: 'admin.customerInvoices',
    delivery_package: 'admin.deliveries',
    change_order: 'admin.changeOrders',
    customer: 'admin.customers',
};

interface Compose {
    subject: { type: string; id: number; label: string | null };
    channels: { email: boolean; whatsapp: boolean };
    templates: {
        key: string;
        name: string;
        channels: string[];
        purpose: string;
        available: Record<string, Record<string, boolean>>;
    }[];
    recipients: { email: { value: string; label: string }[]; phone: { value: string; label: string }[] };
    languages: string[];
    blocked: string | null;
}

interface Props {
    subjectType: string;
    subjectId: number;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** بعد إرسالٍ نجح — لتُعاد قراءةُ البطاقة */
    onSent?: () => void;
    /** قناةٌ تُفتح عليها النافذة — وإلّا أوّلُ الموصول */
    channel?: string;
}

const OTHER = '__other__';

/**
 * نافذةُ «أرسل للعميل» — واحدةٌ لكلّ مستند.
 *
 * تقرأ من الخادم ما يُرسَل عن هذا المستند: القوالبَ التي تخصّه، والقنواتِ
 * الموصولةَ فعلًا، ومن يُراسَل فيه (جهاتُ اتصاله وعميلُه — لا غير). ولا
 * تعرض قناةً لا تُوصِل ولا قالبًا لا يُرسَل به: الاختيارُ ممّا يعمل.
 */
export default function SendMessageDialog({ subjectType, subjectId, open, onOpenChange, onSent, channel: initialChannel }: Props) {
    const t = useTranslate();
    const [compose, setCompose] = useState<Compose | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [channel, setChannel] = useState<string>(initialChannel ?? 'email');
    const [template, setTemplate] = useState('');
    const [language, setLanguage] = useState('ar');
    const [pick, setPick] = useState('');
    const [custom, setCustom] = useState('');
    const [note, setNote] = useState('');
    const [sending, setSending] = useState(false);

    const base = SUBJECT_ROUTES[subjectType];

    useEffect(() => {
        if (!open || !base) return;
        let alive = true;
        setLoading(true);
        setError(null);
        fetch(route(`${base}.communications.compose`, subjectId), { headers: { Accept: 'application/json' } })
            .then(async (r) => {
                if (!r.ok) throw new Error(String(r.status));
                return (await r.json()) as Compose;
            })
            .then((data) => {
                if (!alive) return;
                setCompose(data);
                const first = initialChannel && data.channels[initialChannel as 'email' | 'whatsapp'] ? initialChannel : data.channels.email ? 'email' : data.channels.whatsapp ? 'whatsapp' : 'email';
                setChannel(first);
            })
            .catch(() => alive && setError(t('تعذّر تحميل خيارات الإرسال')))
            .finally(() => alive && setLoading(false));
        return () => {
            alive = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, subjectType, subjectId]);

    const templates = useMemo(
        () => (compose?.templates ?? []).filter((x) => x.channels.includes(channel)),
        [compose, channel],
    );

    /* أوّلُ قالبٍ متاحٍ في القناة — ويُعاد اختيارُه حين تتبدّل القناة */
    useEffect(() => {
        if (templates.length === 0) {
            setTemplate('');
            return;
        }
        if (!templates.some((x) => x.key === template)) {
            const usable = templates.find((x) => x.available[channel]?.[language]);
            setTemplate((usable ?? templates[0]).key);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [templates, channel, language]);

    const recipients = channel === 'whatsapp' ? (compose?.recipients.phone ?? []) : (compose?.recipients.email ?? []);

    useEffect(() => {
        setPick(recipients[0]?.value ?? OTHER);
        setCustom('');
    }, [channel, compose]); // eslint-disable-line react-hooks/exhaustive-deps

    const chosen = templates.find((x) => x.key === template);
    const usable = chosen ? Boolean(chosen.available[channel]?.[language]) : false;
    const to = pick === OTHER ? custom.trim() : pick;
    const configured = compose ? compose.channels.email || compose.channels.whatsapp : false;

    const submit = () => {
        if (!base || !to || !template) return;
        setSending(true);
        router.post(
            route(`${base}.communications.send`, subjectId),
            { channel, template, to, language, note },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    setNote('');
                    onSent?.();
                },
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('إرسال للعميل')}</DialogTitle>
                    <DialogDescription>
                        {compose?.subject.label ?? t('رسالةٌ عن هذا المستند — تُقيَّد في سجلّ المراسلات.')}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3 px-5">
                    {loading && <p className="text-[13px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>}
                    {error && <p className="text-[13px] text-[#b91c1c]">{error}</p>}

                    {compose && compose.blocked && (
                        <div className="rounded-lg bg-[#fffbeb] px-3 py-2 text-[13px] text-[#92400e]">{compose.blocked}</div>
                    )}

                    {compose && !configured && (
                        <div className="rounded-lg bg-[#f3f4f6] px-3 py-2 text-[13px] text-[#6b7280]">
                            {t('لا قناةَ موصولةً على الخادم — لا بريد ولا واتساب.')}
                        </div>
                    )}

                    {compose && configured && !compose.blocked && (
                        <>
                            <div className="flex gap-2">
                                {(['email', 'whatsapp'] as const).map((c) => (
                                    <button
                                        key={c}
                                        type="button"
                                        disabled={!compose.channels[c]}
                                        onClick={() => setChannel(c)}
                                        className={
                                            'flex flex-1 items-center justify-center gap-2 rounded-lg border px-3 py-2 text-[13px] transition ' +
                                            (channel === c
                                                ? 'border-[#111] bg-[#111] text-white'
                                                : 'border-[#e5e7eb] bg-white text-[#374151] hover:bg-[#fafafa]') +
                                            (compose.channels[c] ? '' : ' cursor-not-allowed opacity-40')
                                        }
                                        title={compose.channels[c] ? undefined : t('غير موصول على الخادم')}
                                    >
                                        {c === 'email' ? <Mail className="size-4" /> : <MessageCircle className="size-4" />}
                                        {t(CHANNEL_LABEL[c])}
                                    </button>
                                ))}
                            </div>

                            <Field label="القالب" required>
                                <Select
                                    value={template}
                                    onChange={(e) => setTemplate(e.target.value)}
                                    options={templates.map((x) => ({
                                        label: x.available[channel]?.[language] ? x.name : `${x.name} — ${t('غير مضبوط لهذه القناة')}`,
                                        value: x.key,
                                    }))}
                                />
                            </Field>

                            <Field label="اللغة">
                                <Select
                                    value={language}
                                    onChange={(e) => setLanguage(e.target.value)}
                                    options={[
                                        { label: t('العربية'), value: 'ar' },
                                        { label: t('الإنجليزية'), value: 'en' },
                                    ]}
                                />
                            </Field>

                            <Field label={channel === 'whatsapp' ? 'رقم المُرسَل إليه' : 'بريد المُرسَل إليه'} required>
                                <Select
                                    value={pick}
                                    onChange={(e) => setPick(e.target.value)}
                                    options={[
                                        ...recipients.map((r) => ({ label: `${r.label} — ${r.value}`, value: r.value })),
                                        { label: t('عنوانٌ آخر…'), value: OTHER },
                                    ]}
                                />
                                {pick === OTHER && (
                                    <Input
                                        dir="ltr"
                                        className="mt-2"
                                        type={channel === 'whatsapp' ? 'tel' : 'email'}
                                        placeholder={channel === 'whatsapp' ? '+968 9XXX XXXX' : 'name@example.com'}
                                        value={custom}
                                        onChange={(e) => setCustom(e.target.value)}
                                    />
                                )}
                            </Field>

                            {channel === 'email' && (
                                <Field label="رسالة مرافقة" hint="اختياري — تُكتب فوق الرابط">
                                    <Textarea rows={3} value={note} onChange={(e) => setNote(e.target.value)} />
                                </Field>
                            )}

                            {chosen && !usable && (
                                <p className="text-[12px] text-[#b45309]">
                                    {channel === 'whatsapp'
                                        ? t('لا قالب واتساب معتمَدًا لهذه الرسالة بهذه اللغة — يُضبط من «المراسلات ‹ القوالب».')
                                        : t('هذا القالب موقوف.')}
                                </p>
                            )}
                        </>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('تراجع')}
                    </Button>
                    <Button
                        disabled={!compose || !!compose.blocked || !configured || !usable || to === '' || sending}
                        loading={sending}
                        onClick={submit}
                    >
                        <Send />
                        {t('إرسال')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
