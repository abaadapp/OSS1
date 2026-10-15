import { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, Mail, MessageCircle, Pencil, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { CHANNEL_LABEL, PURPOSE_LABEL, SUBJECT_LABEL, TEMPLATE_STATUS_LABEL, templateStatusTone } from '@/lib/communications';

interface Template {
    id: number | null;
    key: string;
    channel: string;
    language: string;
    name: string;
    catalog_name: string;
    purpose: string;
    subject_type: string;
    internal: boolean;
    status: string;
    sendable: boolean;
    provider_template: string | null;
    provider_language: string | null;
    variables: string[];
    allowed_variables: string[];
    /** خاناتُ ترويسة قالب Meta وزرُّه ذو الرابط — لواتساب */
    header_variables?: string[];
    button_variable?: string | null;
    subject: string | null;
    body: string | null;
    default_body: string | null;
    customized: boolean;
    updated_at: string | null;
}

interface Props {
    templates: Template[];
    channels: { email: boolean; whatsapp: boolean };
    statuses: string[];
    sample: Record<string, string>;
    may: { manage: boolean };
}

interface Preview {
    title: string;
    body: string;
    parameters: string[];
}

/**
 * قوالبُ الرسائل — الكتالوجُ كاملًا، وما قرّرته الشركةُ فوقه.
 *
 * البريدُ قالبُه في الكود ويُسمّى هنا ليُوقَف أو يُبدَّل نصُّه؛ وواتساب لا
 * يُرسَل به حتى يُكتب اسمُ القالب المعتمَد عند Meta ولغتُه وترتيبُ خاناته.
 */
export default function CommunicationTemplates({ templates, channels, statuses, may }: Props) {
    const t = useTranslate();
    const { errors } = usePage().props as { errors?: Record<string, string> };
    const [channel, setChannel] = useState<'email' | 'whatsapp'>('email');
    const [language, setLanguage] = useState<'ar' | 'en'>('ar');
    const [editing, setEditing] = useState<Template | null>(null);

    const rows = useMemo(
        () => templates.filter((x) => x.channel === channel && x.language === language),
        [templates, channel, language],
    );

    const groups = useMemo(() => {
        const out: Record<string, Template[]> = {};
        for (const r of rows) (out[r.purpose] ??= []).push(r);
        return out;
    }, [rows]);

    return (
        <AdminLayout title={t('قوالب الرسائل')}>
            <BackLink routeName="admin.communications.index" href={route('admin.communications.index')} label="المراسلات" />

            <PageHeader
                title={t('قوالب الرسائل')}
                subtitle={t('ما تقوله الشركة لعملائها بالبريد وواتساب — بالعربية والإنجليزية.')}
            />

            {errors?.template && <div className="mb-4 rounded-lg bg-[#fef2f2] px-4 py-3 text-[13px] text-[#b91c1c]">{errors.template}</div>}

            {!channels.whatsapp && channel === 'whatsapp' && (
                <div className="mb-4 rounded-lg bg-[#fffbeb] px-4 py-3 text-[13px] text-[#92400e]">
                    {t('واتساب غير موصول على الخادم — تُحفظ القوالب ولا يُرسَل بها حتى يُضبط رمزُ الوصول ومعرّفُ الرقم.')}
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Tabs
                    tabs={[
                        { key: 'email', label: 'البريد', icon: Mail },
                        { key: 'whatsapp', label: 'واتساب', icon: MessageCircle },
                    ]}
                    current={channel}
                    onChange={(k) => setChannel(k as 'email' | 'whatsapp')}
                />
                <Tabs
                    tabs={[
                        { key: 'ar', label: 'العربية' },
                        { key: 'en', label: 'الإنجليزية' },
                    ]}
                    current={language}
                    onChange={(k) => setLanguage(k as 'ar' | 'en')}
                />
            </div>

            <div className="space-y-4">
                {Object.entries(groups).map(([purpose, list]) => (
                    <Card key={purpose} className="p-4">
                        <h2 className="mb-2 font-bold text-[#111]">{t(PURPOSE_LABEL[purpose] ?? purpose)}</h2>
                        <ul className="divide-y divide-[#f3f4f6] text-[13px]">
                            {list.map((x) => (
                                <li key={x.key} className="flex items-center justify-between gap-3 py-2">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium text-[#111]">{x.name}</span>
                                            {x.customized && x.name !== x.catalog_name && (
                                                <span className="text-[11px] text-[#9ca3af]">({x.catalog_name})</span>
                                            )}
                                            {x.internal && <span className="rounded-full bg-[#f3f4f6] px-2 py-0.5 text-[11px] text-[#6b7280]">{t('إشعار داخلي')}</span>}
                                            <span className={'rounded-full px-2 py-0.5 text-[11px] ' + templateStatusTone(x.status)}>
                                                {t(TEMPLATE_STATUS_LABEL[x.status] ?? x.status)}
                                            </span>
                                        </div>
                                        <div className="truncate text-[12px] text-[#6b7280]">
                                            <span dir="ltr">{x.key}</span> · {t(SUBJECT_LABEL[x.subject_type] ?? x.subject_type)}
                                            {x.channel === 'whatsapp' && (
                                                <>
                                                    {' · '}
                                                    {x.provider_template ? (
                                                        <span dir="ltr">{x.provider_template}</span>
                                                    ) : (
                                                        <span className="text-[#b45309]">{t('بلا اسم معتمَد — لا يُرسَل')}</span>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        {x.sendable ? (
                                            <span className="text-[11px] text-[#047857]">{t('جاهز')}</span>
                                        ) : (
                                            <span className="text-[11px] text-[#9ca3af]">{t('غير جاهز')}</span>
                                        )}
                                        <Button size="sm" variant="outline" onClick={() => setEditing(x)}>
                                            {may.manage ? <Pencil /> : <Eye />}
                                            {may.manage ? t('ضبط') : t('عرض')}
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                ))}
            </div>

            {editing && <Editor template={editing} statuses={statuses} manage={may.manage} onClose={() => setEditing(null)} />}
        </AdminLayout>
    );
}

function Editor({ template: x, statuses, manage, onClose }: { template: Template; statuses: string[]; manage: boolean; onClose: () => void }) {
    const t = useTranslate();
    const [name, setName] = useState(x.name);
    const [status, setStatus] = useState(x.status);
    const [providerTemplate, setProviderTemplate] = useState(x.provider_template ?? '');
    const [providerLanguage, setProviderLanguage] = useState(x.provider_language ?? (x.language === 'en' ? 'en_US' : 'ar'));
    const [variables, setVariables] = useState<string[]>(x.variables);
    const [headerVariables, setHeaderVariables] = useState<string[]>(x.header_variables ?? []);
    const [buttonVariable, setButtonVariable] = useState(x.button_variable ?? '');
    const [subject, setSubject] = useState(x.subject ?? '');
    const [body, setBody] = useState(x.body ?? '');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [saving, setSaving] = useState(false);
    const whatsapp = x.channel === 'whatsapp';

    const unused = x.allowed_variables.filter((v) => !variables.includes(v));
    const move = (i: number, d: -1 | 1) => {
        const next = [...variables];
        const j = i + d;
        if (j < 0 || j >= next.length) return;
        [next[i], next[j]] = [next[j], next[i]];
        setVariables(next);
    };

    const doPreview = () => {
        fetch(route('admin.communications.templates.preview'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
            },
            body: JSON.stringify({ key: x.key, channel: x.channel, language: x.language, variables, subject: subject || null, body: body || null }),
        })
            .then(async (r) => (r.ok ? ((await r.json()) as Preview) : null))
            .then((p) => p && setPreview(p))
            .catch(() => undefined);
    };

    useEffect(() => {
        doPreview();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const save = () => {
        setSaving(true);
        router.post(
            route('admin.communications.templates.update'),
            {
                key: x.key,
                channel: x.channel,
                language: x.language,
                name,
                status,
                provider_template: whatsapp ? providerTemplate : null,
                provider_language: whatsapp ? providerLanguage : null,
                variables,
                ...(whatsapp ? { header_variables: headerVariables, button_variable: buttonVariable || null } : {}),
                subject: subject || null,
                body: body || null,
            },
            { preserveScroll: true, onSuccess: onClose, onFinish: () => setSaving(false) },
        );
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {x.catalog_name} — {t(CHANNEL_LABEL[x.channel] ?? x.channel)} · {x.language === 'en' ? t('الإنجليزية') : t('العربية')}
                    </DialogTitle>
                    <DialogDescription>
                        <span dir="ltr">{x.key}</span> · {t(SUBJECT_LABEL[x.subject_type] ?? x.subject_type)}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 px-5 md:grid-cols-2">
                    <div className="space-y-3">
                        <Field label="الاسم">
                            <Input value={name} onChange={(e) => setName(e.target.value)} disabled={!manage} />
                        </Field>
                        <Field label="الحالة">
                            <Select
                                value={status}
                                onChange={(e) => setStatus(e.target.value)}
                                disabled={!manage}
                                options={statuses.map((s) => ({ label: t(TEMPLATE_STATUS_LABEL[s] ?? s), value: s }))}
                            />
                        </Field>

                        {whatsapp && (
                            <>
                                <Field label="اسم القالب عند Meta" hint="كما اعتمدته Meta — حروف لاتينية صغيرة وأرقام وشرطات سفلية" required>
                                    <Input dir="ltr" value={providerTemplate} onChange={(e) => setProviderTemplate(e.target.value)} disabled={!manage} placeholder="quotation_link" />
                                </Field>
                                <Field label="رمز اللغة عند Meta" hint="ar · en · en_US">
                                    <Input dir="ltr" value={providerLanguage} onChange={(e) => setProviderLanguage(e.target.value)} disabled={!manage} />
                                </Field>
                                {/* وترويسةُ القالب وزرُّه ذو الرابط — بما اعتمدته Meta: بلا ترويسةٍ متغيّرة تُترك فارغة */}
                                <Field label="خانات الترويسة" hint="إن كانت ترويسة القالب عند Meta فيها {{1}} — بترتيبها">
                                    <div className="flex flex-wrap gap-1">
                                        {x.allowed_variables.map((v) => {
                                            const on = headerVariables.includes(v);
                                            return (
                                                <button key={v} type="button" disabled={!manage} dir="ltr"
                                                    onClick={() => setHeaderVariables(on ? headerVariables.filter((z) => z !== v) : [...headerVariables, v])}
                                                    className={`rounded-full border px-2 py-0.5 font-mono text-[11px] ${on ? 'border-[#111] bg-[#111] text-white' : 'border-dashed border-[#d1d5db] text-[#6b7280]'}`}>
                                                    {v}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </Field>
                                <Field label="خانة زر الرابط" hint="لاحقة الرابط في زر Meta الديناميكي — مثل tasks_token لرابط ينتهي بـ /t/{{1}}">
                                    <Select value={buttonVariable} onChange={(e) => setButtonVariable(e.target.value)} disabled={!manage} placeholder="بلا زر رابط"
                                        options={x.allowed_variables.map((v) => ({ label: v, value: v }))} />
                                </Field>
                            </>
                        )}

                        {!whatsapp && (
                            <Field label="عنوان البريد" hint="اختياري — يُكتب فوق العنوان الافتراضي. المتغيّرات بين {{ }}">
                                <Input value={subject} onChange={(e) => setSubject(e.target.value)} disabled={!manage} />
                            </Field>
                        )}

                        <Field
                            label="نص القالب"
                            hint={whatsapp ? 'نصّ القالب كما اعتمدته Meta — للمعاينة. {{1}} {{2}} تُملأ بترتيب المتغيّرات أدناه' : 'اختياري — يُكتب فوق النصّ الافتراضي. المتغيّرات بين {{ }}'}
                        >
                            <Textarea rows={5} value={body} onChange={(e) => setBody(e.target.value)} disabled={!manage} placeholder={x.default_body ?? ''} />
                        </Field>
                    </div>

                    <div className="space-y-3">
                        <Field label="المتغيّرات بترتيبها" hint={whatsapp ? 'الأوّل يملأ {{1}} والثاني {{2}} — طابق ترتيب خانات القالب عند Meta' : 'ما يُملأ في القالب'}>
                            <ol className="space-y-1 rounded-lg border border-[#e5e7eb] p-2 text-[13px]">
                                {variables.map((v, i) => (
                                    <li key={v} className="flex items-center gap-1">
                                        <span className="w-6 text-[11px] text-[#9ca3af]" dir="ltr">
                                            {`{{${i + 1}}}`}
                                        </span>
                                        <span className="flex-1 font-mono text-[12px]" dir="ltr">
                                            {v}
                                        </span>
                                        {manage && (
                                            <>
                                                <button type="button" className="text-[#9ca3af] hover:text-[#111]" onClick={() => move(i, -1)} aria-label={t('أعلى')}>
                                                    <ArrowUp className="size-3.5" />
                                                </button>
                                                <button type="button" className="text-[#9ca3af] hover:text-[#111]" onClick={() => move(i, 1)} aria-label={t('أسفل')}>
                                                    <ArrowDown className="size-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-[#9ca3af] hover:text-[#b91c1c]"
                                                    onClick={() => setVariables(variables.filter((z) => z !== v))}
                                                    aria-label={t('حذف')}
                                                >
                                                    <X className="size-3.5" />
                                                </button>
                                            </>
                                        )}
                                    </li>
                                ))}
                                {variables.length === 0 && <li className="text-[12px] text-[#9ca3af]">{t('بلا متغيّرات')}</li>}
                            </ol>
                            {manage && unused.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {unused.map((v) => (
                                        <button
                                            key={v}
                                            type="button"
                                            onClick={() => setVariables([...variables, v])}
                                            className="rounded-full border border-dashed border-[#d1d5db] px-2 py-0.5 font-mono text-[11px] text-[#6b7280] hover:border-[#111] hover:text-[#111]"
                                            dir="ltr"
                                        >
                                            + {v}
                                        </button>
                                    ))}
                                </div>
                            )}
                        </Field>

                        <div className="rounded-lg bg-[#f9fafb] p-3">
                            <div className="mb-2 flex items-center justify-between">
                                <span className="text-[12px] font-bold text-[#374151]">{t('معاينة بقيم نموذجية')}</span>
                                <Button size="sm" variant="ghost" onClick={doPreview}>
                                    <Eye />
                                    {t('حدّث')}
                                </Button>
                            </div>
                            {preview ? (
                                <div className="space-y-2 text-[13px]" dir={x.language === 'en' ? 'ltr' : 'rtl'}>
                                    {!whatsapp && <div className="font-medium text-[#111]">{preview.title}</div>}
                                    <div className="whitespace-pre-line text-[#374151]">{preview.body || t('— لا نصّ —')}</div>
                                    {whatsapp && preview.parameters.length > 0 && (
                                        <ol className="mt-2 space-y-0.5 border-t border-[#e5e7eb] pt-2 text-[11px] text-[#6b7280]" dir="ltr">
                                            {preview.parameters.map((p, i) => (
                                                <li key={i}>
                                                    {`{{${i + 1}}}`} = {p}
                                                </li>
                                            ))}
                                        </ol>
                                    )}
                                </div>
                            ) : (
                                <p className="text-[12px] text-[#9ca3af]">{t('جارٍ التحميل…')}</p>
                            )}
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        {manage ? t('تراجع') : t('إغلاق')}
                    </Button>
                    {manage && (
                        <Button onClick={save} loading={saving} disabled={saving || (whatsapp && status === 'active' && providerTemplate.trim() === '')}>
                            {t('حفظ')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
