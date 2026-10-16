import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import ProjectInventoryForecast, { type ProjectForecast } from '@/Components/InventoryForecast';

/**
 * توقّعُ مخزون المشروع — يعرض ما يحسبه الخادم ولا يحسب شيئًا.
 *
 * المتوفّرُ وما قبله في الجدول والاحتياجُ والمتوقَّع، و«نقص 3» بلون التحذير، والمصدرُ
 * المبدئيّ يُقال «غير مؤكد تشغيليًّا»، والخطُّ الزمنيّ يُفتح بيد.
 */

const data = (over: Partial<ProjectForecast> = {}): ProjectForecast => ({
    days: 14, from: '2026-09-20', to: '2026-10-04', in_window: true, date: '2026-10-01', shortages: 1,
    lines: [
        { product_id: 1, name: 'شاشة', sku: 'SC-1', available: 10, committed: 7, required: 6, projected: -3, shortage: 3, source: 'scope' },
        { product_id: 2, name: 'كرسي', sku: 'CH-1', available: 20, committed: 0, required: 8, projected: 12, shortage: 0, source: 'operation' },
    ],
    timelines: {
        1: [
            { project_id: 4, code: 'EP-A', name: 'مشروع A', date: '2026-09-25', required: 7, source: 'scope', remaining: 3, shortage: 0 },
            { project_id: 5, code: 'EP-B', name: 'مشروع B', date: '2026-10-01', required: 6, source: 'scope', remaining: -3, shortage: 3 },
        ],
        2: [],
    },
    ...over,
});

describe('ProjectInventoryForecast', () => {
    it('reads available, committed, required and projected — and names a shortage', () => {
        render(<ProjectInventoryForecast data={data()} projectId={5} />);

        expect(screen.getByText('شاشة')).toBeInTheDocument();
        expect(screen.getByText('نقص 3')).toBeInTheDocument();
        expect(screen.getByText('كافٍ')).toBeInTheDocument();
        expect(screen.getByText('من نطاق المشروع — غير مؤكد تشغيليًّا')).toBeInTheDocument();
        expect(screen.getByText('مؤكد تشغيليًّا')).toBeInTheDocument();
        expect(screen.getByText('-3')).toBeInTheDocument();
    });

    it('opens the timeline of a product on demand', async () => {
        const user = userEvent.setup();
        render(<ProjectInventoryForecast data={data()} projectId={5} />);

        expect(screen.queryByText(/مشروع A/)).not.toBeInTheDocument();
        await user.click(screen.getByText('شاشة'));
        expect(screen.getByText(/مشروع A/)).toBeInTheDocument();
        expect(screen.getByText('المتوفر الآن: 10')).toBeInTheDocument();
        expect(screen.getAllByText('نقص 3').length).toBeGreaterThan(1);
    });

    it('says why there is nothing to show', () => {
        const { rerender } = render(<ProjectInventoryForecast data={data({ in_window: false, lines: [] })} projectId={5} />);
        expect(screen.getByText(/خارج نافذة التوقّع \(14 يومًا\)/)).toBeInTheDocument();

        rerender(<ProjectInventoryForecast data={data({ lines: [] })} projectId={5} />);
        expect(screen.getByText('لا يحتاج هذا المشروع منتجات مخزّنة في التوقّع.')).toBeInTheDocument();
    });
});
