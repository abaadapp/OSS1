import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import EventTasksTab, { type EventTasks } from '@/Pages/Admin/Operations/partials/EventTasksTab';

/**
 * تبويبُ «المهام» في الفعاليّة — مهامُّ المشروع نفسُها مجموعةً بأصحابها.
 *
 * يقرأ ما أُرسل إلى كلّ موظّف بحاله، و«نشر المهام» يطلب من بابه (لا يُرسل من
 * الشاشة)، والرابطُ يُنسخ لمن يدير الفريق وحدَه.
 */

afterEach(() => vi.clearAllMocks());

const data = (over: Partial<EventTasks> = {}): EventTasks => ({
    published_at: '2026-09-20 10:00',
    automation_mode: 'live',
    rules: [{ trigger: 'event_tasks_assigned', enabled: true, mode: 'live', channel: 'whatsapp' }],
    people: [
        {
            user_id: 7, name: 'محمد', has_phone: true, open: 2, done: 1,
            tasks: [
                { id: 1, title: 'تجهيز شاشة الاستقبال', status: 'open', priority: 'high', due_at: '2026-10-01', completed_at: null },
                { id: 2, title: 'التأكد من الصوت', status: 'done', priority: 'normal', due_at: null, completed_at: '2026-09-21 09:00' },
            ],
            last_dispatch: { trigger: 'event_tasks_assigned', status: 'sent', channel: 'whatsapp', open_count: 3, failure: null, at: '2026-09-20 10:15' },
            link: 'https://hadath.om/t/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        },
        {
            user_id: 8, name: 'سارة', has_phone: false, open: 1, done: 0,
            tasks: [{ id: 3, title: 'اختبار العرض', status: 'open', priority: 'normal', due_at: null, completed_at: null }],
            last_dispatch: { trigger: 'event_tasks_assigned', status: 'failed', channel: 'whatsapp', open_count: 1, failure: 'لا رقم هاتف صالحًا للموظّف', at: '2026-09-20 10:15' },
            link: null,
        },
    ],
    ...over,
});

describe('EventTasksTab', () => {
    it('reads each employee with their tasks and what was sent to them', () => {
        render(<EventTasksTab operationId={4} projectId={9} active data={data()} canManage />);

        const mohammed = screen.getByText('محمد').closest('.p-4') as HTMLElement;
        expect(within(mohammed).getByText('تجهيز شاشة الاستقبال')).toBeInTheDocument();
        expect(within(mohammed).queryByText('اختبار العرض')).not.toBeInTheDocument();
        expect(screen.getByText('أُرسلت على واتساب')).toBeInTheDocument();
        expect(screen.getByText('تعذّر الإرسال')).toBeInTheDocument();
        expect(screen.getByText('لا رقم هاتف صالحًا للموظّف')).toBeInTheDocument();
        expect(screen.getByText('بلا رقم هاتف')).toBeInTheDocument();
        expect(screen.queryByText('اختبار العرض', { selector: 'span' })).toBeInTheDocument();
    });

    it('publishes through its door and offers links only to who manages the crew', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<EventTasksTab operationId={4} projectId={9} active data={data()} canManage />);

        await user.click(screen.getByRole('button', { name: /إعادة النشر/ }));
        expect(router.post).toHaveBeenCalledWith('/admin.operations.tasks.publish/4', {}, expect.anything());
        expect(screen.getAllByRole('button', { name: /نسخ الرابط/ })).toHaveLength(1);
        expect(screen.getByRole('button', { name: /إنشاء رابط/ })).toBeInTheDocument();

        rerender(<EventTasksTab operationId={4} projectId={9} active data={data()} canManage={false} />);
        expect(screen.queryByRole('button', { name: /نسخ الرابط/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /إعادة النشر/ })).not.toBeInTheDocument();
    });

    it('asks to add tasks when none are assigned yet', () => {
        render(<EventTasksTab operationId={4} projectId={9} active data={data({ people: [], published_at: null })} canManage />);
        expect(screen.getByText(/لا مهام معيّنة لموظفين على هذه الفعالية/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /نشر المهام على الفريق/ })).toBeDisabled();
    });
});
