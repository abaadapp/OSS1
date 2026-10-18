import { useRef, useState, type MouseEvent } from 'react';
import { Download, FileDown, FileSpreadsheet, FileText } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { withFilters, withParam } from '@/lib/exportLink';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

interface Props {
    /** أي منها اختياري — يظهر البند فقط إذا مُرِّر رابطه */
    xlsx?: string;
    pdf?: string;
    csv?: string;
    label?: string;
    /**
     * رابطُ PDF يُسأل عن طوله قبل التنزيل (`?probe=1`) — بابا التصدير الموحّدان
     * (القوائمُ والتقارير) يعرفانه؛ وغيرُهما يُفتح كما هو.
     */
    checkPdf?: boolean;
}

/** جوابُ الخادم: كم صفًّا سيحمل الملفّ، وحدُّ PDF */
interface Probe {
    rows: number;
    limit: number;
    fits: boolean;
}

/**
 * قائمة تصدير موحّدة بثلاث صيغ — بديل partials/export-menu.
 *
 * روابط تنزيل حقيقية لا روابط Inertia: الاستجابة ملف لا صفحة،
 * وزيارتها عبر <Link> تُفشل التنزيل.
 *
 * ═══ وPDF لا يُقدَّم كاملًا وهو ليس كاملًا ═══
 *
 * إكسل وCSV يحملان كلَّ ما طابق، وورقةُ PDF لها حدّ. فحين تُفتح القائمة يُسأل
 * الخادمُ عن عدد الصفوف، ومن ضغط PDF والنتائجُ فوق الحدّ يقرأ تنبيهًا قبل أن
 * ينزّل شيئًا: كم النتائج، وكم يتّسع PDF، وزرّا إكسل وCSV الكاملين — ومعهما PDF
 * لأوّل الصفوف لمن أراده مطبوعًا، يقول اسمُه إنّه جزء.
 */
export default function ExportMenu({ xlsx, pdf, csv, label = 'تصدير', checkPdf = false }: Props) {
    const t = useTranslate();
    const [tooLong, setTooLong] = useState<Probe | null>(null);
    const [menuOpen, setMenuOpen] = useState(false);
    const asked = useRef<{ url: string; answer: Promise<Probe | null>; probe?: Probe | null } | null>(null);

    if (!xlsx && !pdf && !csv) return null;

    const pdfUrl = pdf ? withFilters(pdf) : undefined;
    const guarded = checkPdf && pdfUrl !== undefined;

    /* سؤالٌ واحدٌ لكلّ رابط — والرابطُ يتغيّر بتغيّر المرشّحات فيُسأل من جديد */
    const ask = (): Promise<Probe | null> => {
        if (!guarded || !pdfUrl) return Promise.resolve(null);
        if (asked.current?.url === pdfUrl) return asked.current.answer;

        const entry: { url: string; answer: Promise<Probe | null>; probe?: Probe | null } = {
            url: pdfUrl,
            answer: Promise.resolve(null),
        };
        entry.answer = fetch(withParam(pdfUrl, 'probe', '1'), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? (response.json() as Promise<Probe>) : null))
            .catch(() => null)
            .then((probe) => {
                entry.probe = probe;
                return probe;
            });
        asked.current = entry;

        return entry.answer;
    };

    /* التنبيهُ بعد أن تُغلق القائمة — نافذتان تتنازعان التركيز تُجمّدان الصفحة */
    const warn = (probe: Probe) => window.setTimeout(() => setTooLong(probe), 0);

    const openPdf = (event: MouseEvent<HTMLAnchorElement>) => {
        if (!guarded || !pdfUrl) return;

        const known = asked.current?.url === pdfUrl ? asked.current.probe : undefined;
        if (known?.fits) return; // يتّسع: الرابطُ يُفتح كما هو
        // منعُ الرابط يمنع القائمةَ أن تُغلق نفسَها — فتُغلق هنا قبل التنبيه
        event.preventDefault();
        setMenuOpen(false);
        if (known) {
            warn(known);
            return;
        }

        // لم يصل الجوابُ بعد (أو تعذّر): يُنتظر — وإن تعذّر فُتح الرابط ليقول الخادمُ كلمته
        void ask().then((probe) => {
            if (probe && !probe.fits) {
                warn(probe);
                return;
            }
            const tab = window.open(pdfUrl, '_blank');
            if (tab) tab.opener = null;
            else window.location.assign(pdfUrl);
        });
    };

    const close = () => setTooLong(null);

    return (
        <>
            <DropdownMenu
                open={menuOpen}
                onOpenChange={(open) => {
                    setMenuOpen(open);
                    if (open) void ask();
                }}
            >
                <DropdownMenuTrigger asChild>
                    <Button variant="outline">
                        <Download />
                        {t(label)}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    {xlsx && (
                        <DropdownMenuItem asChild>
                            <a href={withFilters(xlsx)}>
                                <FileSpreadsheet className="text-[#9ca3af]" />
                                {t('تصدير كملف إكسل')}
                            </a>
                        </DropdownMenuItem>
                    )}
                    {pdfUrl && (
                        <DropdownMenuItem asChild>
                            <a href={pdfUrl} target="_blank" rel="noreferrer" onClick={openPdf}>
                                <FileText className="text-[#9ca3af]" />
                                {t('تصدير كملف PDF')}
                            </a>
                        </DropdownMenuItem>
                    )}
                    {csv && (
                        <DropdownMenuItem asChild>
                            <a href={withFilters(csv)}>
                                <FileDown className="text-[#9ca3af]" />
                                {t('تصدير كملف CSV')}
                            </a>
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={tooLong !== null} onOpenChange={(open) => !open && close()}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('ملفّ PDF لا يتّسع لكلّ النتائج')}</DialogTitle>
                        <DialogDescription>
                            {t('النتائج :rows صفًّا، وملفّ PDF يتّسع لـ:limit صفّ على الأكثر. لتحصل على كلّ النتائج صدّر Excel أو CSV.', {
                                rows: number(tooLong?.rows ?? 0),
                                limit: number(tooLong?.limit ?? 0),
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    {/* والحشوُ على الجسم: `DialogContent` بلا حشو — انظر ui/dialog */}
                    <div className="flex flex-col gap-2 px-5 pb-5 pt-1 sm:flex-row sm:flex-wrap">
                        {xlsx && (
                            <Button asChild className="w-full sm:w-auto">
                                <a href={withFilters(xlsx)} onClick={close}>
                                    <FileSpreadsheet />
                                    {t('تصدير Excel كاملًا')}
                                </a>
                            </Button>
                        )}
                        {csv && (
                            <Button asChild variant="outline" className="w-full sm:w-auto">
                                <a href={withFilters(csv)} onClick={close}>
                                    <FileDown />
                                    {t('تصدير CSV كاملًا')}
                                </a>
                            </Button>
                        )}
                        {pdfUrl && (
                            <Button asChild variant="ghost" className="w-full sm:w-auto">
                                <a href={withParam(pdfUrl, 'partial', '1')} target="_blank" rel="noreferrer" onClick={close}>
                                    <FileText />
                                    {t('PDF لأوّل :limit صفّ فقط', { limit: number(tooLong?.limit ?? 0) })}
                                </a>
                            </Button>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
