import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProjectStepper from '@/Components/ProjectStepper';
import { STAGES, toStages, type Workflow } from '@/lib/projectStages';
import { StageNav } from '@/Pages/Admin/Projects/partials/ProcessTab';

/**
 * مسارُ المشروع في الواجهة — يرسم ما يقوله الخادم ولا يحكم.
 *
 * أيُّ مرحلةٍ تمّت وأين يقف الفريق يحسبه `ProjectWorkflow` في الخادم. وهنا
 * يُثبَت أنّ الواجهة لا تضيف حكمًا: سبعُ مراحل بترتيبها، والحالُ كما وصل،
 * و«تم — الانتقال» معطَّلٌ ما لم يقل الخادم إنّ المرحلة تمّت، و«رجوع» يقول
 * قبل الضغط إنّه لا يمحو شيئًا.
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
    can_advance: true,
    can_back: true,
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
});

describe('StageNav', () => {
    it('moves forward only when the server says the stage is done', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const user = userEvent.setup();
        const { rerender } = render(<StageNav wf={workflow({ can_advance: false })} projectId={7} />);

        const advance = screen.getByRole('button', { name: /تم — الانتقال إلى/ });
        expect(advance).toBeDisabled();

        rerender(<StageNav wf={workflow()} projectId={7} />);
        await user.click(screen.getByRole('button', { name: /تم — الانتقال إلى/ }));

        expect(post).toHaveBeenCalledWith('/admin.projects.workflow.advance/7', {}, expect.anything());
    });

    it('says that going back erases nothing before it goes back', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const user = userEvent.setup();
        render(<StageNav wf={workflow()} projectId={7} />);

        await user.click(screen.getByRole('button', { name: /رجوع إلى/ }));
        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText(/لا تُلغى فاتورة، ولا يُنقض توقيع، ولا يُعكس قيد/)).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();

        await user.click(within(dialog).getByRole('button', { name: 'رجوع' }));
        expect(post).toHaveBeenCalledWith('/admin.projects.workflow.back/7', {}, expect.anything());
    });

    it('offers no way back from the first stage', () => {
        render(<StageNav wf={workflow({ focus: 'quote', previous: null, can_back: false, next: 'scope' })} projectId={7} />);

        expect(screen.queryByRole('button', { name: /رجوع إلى/ })).not.toBeInTheDocument();
    });
});
