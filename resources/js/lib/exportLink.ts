/**
 * يُلحق برابط التصدير ما تنظر إليه الشاشة الآن.
 *
 * زرّ «تصدير» يقف بجانب المُرشِّحات، فمن ضغطه ينتظر ما أمامه. وكان الرابط
 * يخرج عاريًا: يُرشِّح التاجر مصروفات سبتمبر ويصدّر، فيفتح ملفًّا فيه ثلاث
 * سنوات — ولا يعرف أنّه غير ما طلب إلا إن عدّ الصفوف. ويُرشِّح الفواتير
 * الملغاة فلا يجد ملغاةً واحدة.
 *
 * والصفحة ورقمُها لا يُنقلان: الملفّ ليس مرقَّمًا، وحملُ `page=3` إليه يُوهم
 * أنّ له صفحات.
 *
 * وما في الرابط أولى بما فيه: مسارٌ كُتب له `range` صراحةً يعرف ما يريد.
 */
export function withFilters(url: string): string {
    if (typeof window === 'undefined') return url;

    const here = new URLSearchParams(window.location.search);
    here.delete('page');
    here.delete('per_page');
    if (![...here].length) return url;

    const [base, own = ''] = url.split('?');
    const merged = new URLSearchParams(own);
    here.forEach((value, key) => {
        if (!merged.has(key)) merged.append(key, value);
    });

    return `${base}?${merged.toString()}`;
}

/** معاملٌ واحدٌ يُضاف إلى رابطٍ أو يُستبدل — وبقيّةُ الرابط كما هي */
export function withParam(url: string, key: string, value: string): string {
    const [base, own = ''] = url.split('?');
    const params = new URLSearchParams(own);
    params.set(key, value);

    return `${base}?${params.toString()}`;
}

/**
 * روابطُ الصيغ الثلاث لقائمةٍ تشغيليّة — مسارٌ واحدٌ بصيغته معاملًا (`{format}`).
 *
 * والمرشّحاتُ المعروضةُ تلحقها `ExportMenu` من الرابط (`withFilters`)، وما يُمرَّر
 * هنا صراحةً أولى بها: حسابُ الكشف المفتوح، أو حالٌ تُختار في الصفحة. وPDF منها
 * يُسأل عن طوله قبل أن يُنزَّل (`checkPdf`) — بابُ القوائم يجيب.
 */
export function exportLinks(
    name: string,
    params: Record<string, string | number | null | undefined> = {},
): { xlsx: string; pdf: string; csv: string; checkPdf: true } {
    const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));

    return {
        xlsx: route(name, { format: 'xlsx', ...clean }),
        pdf: route(name, { format: 'pdf', ...clean }),
        csv: route(name, { format: 'csv', ...clean }),
        checkPdf: true,
    };
}
