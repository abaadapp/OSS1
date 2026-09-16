import { type FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Lock, Mail, TriangleAlert } from 'lucide-react';

import AuthLayout from '@/Layouts/AuthLayout';
import AuthShowcase from '@/Components/auth/AuthShowcase';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Props {
    year: number;
    /** هل يستطيع النظام إرسال بريدٍ فعلًا — انظر App\Support\Mailer */
    canRecover: boolean;
}

/**
 * أول ما يراه المستخدم من النظام: بريد وكلمة مرور، لا أكثر.
 *
 * ═══ وما تغيّر في هذه النسخة ═══
 *
 * كانت بطاقةً في وسط صفحةٍ رماديّة، تحمل خلفيّتَها وشعارَها ومبدّلَ لغتها
 * بيدها. فصارت على `AuthLayout` المشترك — هو نفسُه غلافُ التسجيل والتهيئة —
 * وإلى جانبها لوحةُ `AuthShowcase`: رسمُ افنت بلس على الشاشة الواسعة.
 *
 * ثمّ رُفع منها بابان لا يقودان إلى شيءٍ في هذا النظام: رابطُ «إنشاء حساب»،
 * وسطرُ «راجع مدير النظام». وهذا نظامُ شركةٍ واحدة تُفتح حساباتُه من
 * **الموظفين**، لا منصّةٌ يسجّل فيها الغرباءُ أنفسَهم. فبابُ التسجيل
 * الذاتيّ باقٍ في `RegisterController` خلف `Signup::open`، لكن لا يُشار
 * إليه من هنا.
 *
 * ولم يُمسّ شيءٌ من منطق الدخول: «تذكّرني»، والإرسالُ مرّةً واحدة، وبابُ
 * الاستعادة الذي يظهر بحسب وجود مُرسِل بريد.
 */
export default function Login() {
    const { year, canRecover, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({ email: '', password: '', remember: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();

        /* ومنعُ الإرسال المزدوج: الطلبُ قائمٌ فلا يُرسَل ثانٍ فوقه */
        if (form.processing) {
            return;
        }

        form.post(route('login.attempt'), { onFinish: () => form.reset('password') });
    };

    // التحقق يردّ الخطأ على حقل البريد؛ نعرضه شريطًا واحدًا فوق النموذج
    const failure = errors.email ?? errors.password;

    return (
        <AuthLayout title={t('تسجيل الدخول')} year={year} showcase={<AuthShowcase />}>
            <h1 className="text-[26px] font-bold leading-tight text-[#111]">
                {t('مرحبًا بك مجددًا')} <span aria-hidden>👋</span>
            </h1>
            <p className="mt-1.5 text-[14px] text-[#6b7280]">{t('أدخل بريدك وكلمة المرور للمتابعة')}</p>

            {failure && (
                <div
                    role="alert"
                    className="mt-6 flex items-start gap-2 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]"
                >
                    <TriangleAlert className="mt-px size-4 shrink-0" />
                    <span>{failure}</span>
                </div>
            )}

            {/* «اذهب» على مفتاح الآيباد تُرسل هنا: حقلان وزرٌّ واحد،
                ومنعُ الإرسال إزعاجٌ لا حماية — انظر lib/enter-key */}
            <form onSubmit={submit} data-enter-submits className="mt-6 space-y-4">
                <Field label="البريد الإلكتروني" required htmlFor="email">
                    {/*
                     * الغلاف بالاتجاه نفسه لا الصفحة: البريد قيمة لاتينية دائمًا،
                     * وكان الحقل dir="ltr" بينما غلافه يتبع الصفحة — فتُحسب حشوة
                     * الأيقونة (ps) يسارًا وتُرسم الأيقونة (start) يمينًا في العربية،
                     * فيمرّ البريد الطويل تحتها. توحيد الاتجاه يجعلهما جهة واحدة.
                     */}
                    <span className="relative block" dir="ltr">
                        <Mail className="pointer-events-none absolute start-3 top-3 size-4 text-[#9ca3af]" />
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            autoComplete="username"
                            autoFocus
                            required
                            placeholder="you@example.com"
                            className="ps-10 text-start"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                        />
                    </span>
                </Field>

                <Field label="كلمة المرور" required htmlFor="password">
                    {/* نفس السبب، وليبقى القفل في جهة أيقونة البريد لا مقابلها */}
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="current-password"
                        required
                        placeholder="••••••••"
                        className="px-10 text-start"
                        leading={
                            <Lock className="pointer-events-none absolute start-3 top-3 z-10 size-4 text-[#9ca3af]" />
                        }
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                </Field>

                {/*
                    «نسيت كلمة المرور» مسارٌ حقيقيّ، ويختفي حين لا يكون البريد
                    مضبوطًا فلا يبقى مكانَه شيء.

                    كان مكانَه سطرٌ رماديّ: «نسيت كلمتك؟ راجع مدير النظام» —
                    رُفع بطلب صاحب النظام. وهو سطرٌ يَعِد بما لا يملكه الموظّف:
                    مديرُ النظام في هذه الشركة هو صاحبُ الحساب نفسِه أحيانًا،
                    فيُقرأ إحالةً إلى لا أحد. وصمتُ المكان أهونُ من إحالةٍ
                    مغلقة — والعلاجُ الحقيقيّ ضبطُ البريد لا تحسينُ الاعتذار.
                */}
                {/* ويلتفّان عند الضيق بدل أن ينضغط أحدهما سطرين */}
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <label className="flex w-fit shrink-0 cursor-pointer items-center gap-2 text-[13px] text-[#4b4b4b]">
                        <input
                            type="checkbox"
                            checked={form.data.remember}
                            onChange={(e) => form.setData('remember', e.target.checked)}
                            className="size-4 rounded-[4px] border-[#d1d5db] text-[#111] accent-[#111] focus:ring-0"
                        />
                        {t('تذكرني')}
                    </label>

                    {canRecover && (
                        <Link
                            href={route('password.request')}
                            className="text-[13px] font-medium text-[#6b7280] transition-colors hover:text-[#111]"
                        >
                            {t('نسيت كلمة المرور؟')}
                        </Link>
                    )}
                </div>

                <Button type="submit" size="lg" className="w-full" loading={form.processing}>
                    {t('تسجيل الدخول')}
                </Button>
            </form>
        </AuthLayout>
    );
}
