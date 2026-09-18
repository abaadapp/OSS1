import logoUrl from '@/assets/event-plus-logo.png';

/**
 * شعار افنت بلس — العلامة والاسم في وحدة واحدة.
 *
 * الرسمُ مصدرُه ملفُّ العلامة المتجهيّ الرسميّ (Event plus.ai)، مُصدَّرًا
 * قناعَ تغطيةٍ أحاديَّ اللون. ونُرسَم بـ`mask` لا بـ`img`: فالتعبئة
 * `currentColor` تبقى — يرث الشعارُ لونَ النص المحيط به، كُحليًّا على
 * الفاتح وأبيضَ على الداكن — وهي الخاصّيّةُ التي كانت للرسم القديم، ولا
 * تُنالُ من صورةٍ بلونٍ مثبَّت.
 *
 * والنسبةُ 788:256 من صندوق العلامة نفسِه، فيكفي ضبطُ الارتفاع (className)
 * ويتبعه العرضُ عبر `aspect-ratio`.
 */
export default function Logo({ className }: { className?: string }) {
    return (
        <span
            role="img"
            aria-label="Event plus — افنت بلس"
            className={className}
            style={{
                display: 'inline-block',
                aspectRatio: '788 / 256',
                backgroundColor: 'currentColor',
                WebkitMaskImage: `url(${logoUrl})`,
                maskImage: `url(${logoUrl})`,
                WebkitMaskRepeat: 'no-repeat',
                maskRepeat: 'no-repeat',
                WebkitMaskPosition: 'center',
                maskPosition: 'center',
                WebkitMaskSize: 'contain',
                maskSize: 'contain',
            }}
        />
    );
}
