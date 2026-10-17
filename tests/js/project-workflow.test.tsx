import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProjectStepper from '@/Components/ProjectStepper';
import { focusDone, STAGES, toStages, type Workflow } from '@/lib/projectStages';
import { StageNav } from '@/Pages/Admin/Projects/partials/ProcessTab';

/**
 * مسارُ المشروع في الواجهة — يرسم ما يقوله الخادم ولا يحكم، والتنقّلُ حرّ.
 *
 * أيُّ مرحلةٍ تمّت يحسبه `ProjectWorkflow` في الخادم، وأين يقف الفريق
 * اختيارُه. وهنا يُثبَت أنّ الواجهة لا تضيف حكمًا ولا قفلًا: سبعُ مراحل
 * بترتيبها، والحالُ كما وصل، وكلُّ مرحلةٍ تُختار — للأمام أو للخلف أو قفزًا
 * — بلا زرٍّ معطَّلٍ ولا رسالةِ منع.
 */

const workflow = (over: Partial<Workflow> = {}): Workflow => ({
    stages: [
        { key: 'quote', state: 'done', focus: false, note: null },
        { key: 'scope', state: 'done', focus: true, note: null },
        { key: 'deposit', state: 'todo', focus: false, note: null },
        { key: 'execution', state: 'todo', focus: false, note: null },
        { key: 'delivery', state: 'todo', focus: false, note: null },
        { key: 'final', state: 'todo', focus: false, note: null },
        { key: 'closing', state: 'todo', focus: false, note: null },
    ],
    focus: 'scope',
    next: 'deposit',
    previous: 'quote',
    ...over,
});

afterEach(() => vi.restoreAllMocks());

describe('toStages', () => {
    it('keeps the seven stages in their order and takes each state from the server', () => {
        const stages = toStages(workflow());

        expect(stages.map((s) => s.key)).toEqual(['quote', 'scope', 'deposit', 'execution', 'delivery', 'final', 'closing']);
        expect(stages.map((s) => s.label)).toEqual(STAGES.map((s) => s.label));
        expect(stages[1]).toMatchObject({ state: 'done', focus: true });
        expect(stages[2]).toMatchObject({ state: 'todo', focus: false });
    });

    it('does not invent a state for a stage the server did not send', () => {
        const stages = toStages({ stages: [{ key: 'quote', state: 'done', focus: true, note: null }] });

        expect(stages[0].state).toBe('done');
        expect(stages.slice(1).every((s) => s.state === 'todo' && !s.focus)).toBe(true);
    });
});

describe('ProjectStepper', () => {
    it('marks where the team stands and shows the stop on a cancelled project', () => {
        const cancelled = workflow({
            stages: workflow().stages.map((s) => (s.key === 'scope' ? { ...s, state: 'failed', note: 'ملغى' } : s)),
        });
        render(<ProjectStepper stages={toStages(cancelled)} />);

        const items = screen.getAllByRole('listitem');
        expect(items).toHaveLength(7);
        expect(items[1]).toHaveAttribute('aria-current', 'step');
        expect(within(items[1]).getByText('ملغى')).toBeInTheDocument();
        expect(within(items[6]).getByText('الإغلاق')).toBeInTheDocument();
    });

    it('lets a manager press any stage — done, current, skipped or ahead — and none is disabled', async () => {
        const user = userEvent.setup();
        const onMove = vi.fn();
        const onPick = vi.fn();
        /* الفريقُ على «التنفيذ» وقد تجاوز «الدفعة» ناقصةً، و«التسليم» تمّ قبله */
        const jumped = workflow({
            focus: 'execution',
            stages: [
                { key: 'quote', state: 'done', focus: false, note: null },
                { key: 'scope', state: 'done', focus: false, note: null },
                { key: 'deposit', state: 'skipped', focus: false, note: null },
                { key: 'execution', state: 'current', focus: true, note: null },
                { key: 'delivery', state: 'done', focus: false, note: null },
                { key: 'final', state: 'todo', focus: false, note: null },
                { key: 'closing', state: 'todo', focus: false, note: null },
            ],
        });
        render(<ProjectStepper stages={toStages(jumped)} onPick={onPick} onMove={onMove} />);

        const buttons = screen.getAllByRole('button');
        expect(buttons).toHaveLength(7);
        expect(buttons.every((b) => !b.hasAttribute('disabled'))).toBe(true);

        for (const [i, key] of [[0, 'quote'], [2, 'deposit'], [4, 'delivery'], [6, 'closing']] as const) {
            await user.click(buttons[i]);
            expect(onMove).toHaveBeenLastCalledWith(key);
        }
        expect(onPick).not.toHaveBeenCalled();

        /* ✓ على التامّة — قبل الحاليّة وبعدها — وتحذيرٌ على المتجاوَزة الناقصة */
        const items = screen.getAllByRole('listitem');
        expect([0, 1, 4].every((i) => items[i].querySelector('svg.lucide-check'))).toBe(true);
        expect(items[2].querySelector('svg.lucide-triangle-alert, svg.lucide-alert-triangle')).not.toBeNull();
        expect(buttons[2]).toHaveAttribute('title', expect.stringContaining('لم تكتمل'));
        expect(buttons[5]).toHaveAttribute('title', expect.stringContaining('اضغط لنقل المشروع إليها'));
        expect(items[3]).toHaveAttribute('aria-current', 'step');
    });

    it('only opens a stage for whoever cannot move it', async () => {
        const user = userEvent.setup();
        const onPick = vi.fn();
        render(<ProjectStepper stages={toStages(workflow())} onPick={onPick} />);

        await user.click(screen.getAllByRole('button')[4]);
        expect(onPick).toHaveBeenCalledWith('delivery');
        expect(screen.getAllByRole('button')[4]).not.toHaveAttribute('title', expect.stringContaining('اضغط لنقل'));
    });
});

describe('StageNav', () => {
    it('steps back or forward from the current stage whether or not it is done — nothing is disabled', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const user = userEvent.setup();
        const notDone = workflow({ stages: workflow().stages.map((s) => (s.key === 'scope' ? { ...s, state: 'current' } : s)) });
        render(<StageNav wf={notDone} projectId={7} stage="scope" />);

        expect(focusDone(notDone)).toBe(false);
        const forward = screen.getByRole('button', { name: 'الانتقال إلى الدفعة المقدمة' });
        expect(forward).toBeEnabled();
        await user.click(forward);
        expect(post).toHaveBeenLastCalledWith('/admin.projects.workflow.move/7', { stage: 'deposit' }, expect.anything());

        await user.click(screen.getByRole('button', { name: 'رجوع إلى عرض السعر' }));
        expect(post).toHaveBeenLastCalledWith('/admin.projects.workflow.move/7', { stage: 'quote' }, expect.anything());
        /* ولا نافذةَ تأكيد — والنصُّ تحته يقول إنّه لا يمسّ مستندًا ولا حالة */
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByText(/لا يغيّر حالة المشروع، ولا يُلغي فاتورة، ولا يمسّ توقيعًا ولا قيدًا/)).toBeInTheDocument();
    });

    it('moves straight to a stage that is not the current one', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const moved = vi.fn();
        const user = userEvent.setup();
        render(<StageNav wf={workflow()} projectId={7} stage="closing" onMoved={moved} />);

        await user.click(screen.getByRole('button', { name: 'الانتقال إلى هذه المرحلة' }));
        expect(post).toHaveBeenCalledWith('/admin.projects.workflow.move/7', { stage: 'closing' }, expect.anything());
        expect(moved).toHaveBeenCalledWith('closing');
    });

    it('offers no step back from the first stage, and no step forward from the last', () => {
        const { unmount } = render(<StageNav wf={workflow({ focus: 'quote', previous: null, next: 'scope' })} projectId={7} stage="quote" />);
        expect(screen.queryByRole('button', { name: /رجوع إلى/ })).not.toBeInTheDocument();
        unmount();

        render(<StageNav wf={workflow({ focus: 'closing', previous: 'final', next: null })} projectId={7} stage="closing" />);
        expect(screen.queryByRole('button', { name: /^الانتقال إلى/ })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'رجوع إلى الفاتورة النهائية' })).toBeEnabled();
    });
});
