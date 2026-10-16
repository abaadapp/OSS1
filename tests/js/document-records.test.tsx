import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DocumentDialog from '@/Pages/Admin/Documents/partials/DocumentDialog';
import { documentTone, type DocumentCatalog, type DocumentRow } from '@/lib/documents';

/**
 * مستنداتُ الشركة — النموذجُ يرسل ملفًّا إلى بابه، والحالُ لونٌ لا يُخمَّن.
 *
 * الإضافةُ تطلب الملفّ وتُرسَل نموذجًا بملفّ (`forceFormData`) إلى `documents.store`؛
 * والتعديلُ يُرسَل إلى `documents.update` بلا ملفٍّ إلزاميّ، ويحمل الربطَ كما هو.
 */

const catalog: DocumentCatalog = {
    categories: [{ id: 1, name: 'تأمين', active: true, documents: 2 }, { id: 2, name: 'قديم', active: false, documents: 0 }],
    statuses: { active: 'نشط', expiring: 'قريب الانتهاء', expired: 'منتهٍ', archived: 'مؤرشف' },
    link_types: { project: 'مشروع', supplier: 'مورد' },
    users: [{ id: 7, name: 'أمين المخزن' }],
    extensions: ['pdf', 'png'],
    links: { project: [{ id: 3, label: 'EP-2026-003 — ملتقى' }], supplier: [] },
};

const row: DocumentRow = {
    id: 5, title: 'وثيقة التأمين', category_id: 1, category: 'تأمين', document_number: 'INS-1', issuer: 'شركة التأمين',
    issued_at: '2026-01-01', expires_at: '2026-10-10', days_left: 20, status: 'expiring', notes: null,
    link_type: 'project', link_id: 3, link_label: 'EP-2026-003 — ملتقى', responsible_id: 7, responsible: 'أمين المخزن',
    file_name: 'x.pdf', file_size: 1000, file_url: '/admin.documents.file/5', archived: false,
};

describe('DocumentDialog', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    it('adds a document with its file as form data to the store door', async () => {
        const user = userEvent.setup();
        render(<DocumentDialog open onClose={() => {}} document={null} catalog={catalog} />);

        expect(screen.getByText('مستند جديد')).toBeInTheDocument();
        expect(screen.queryByText('اختر')).not.toBeInTheDocument();
        await user.type(screen.getAllByRole('textbox')[0], 'السجل التجاري');
        const file = new File(['%PDF'], 'سجل.pdf', { type: 'application/pdf' });
        await user.upload(document.querySelector('input[type="file"]') as HTMLInputElement, file);
        const input = document.querySelector('input[type="file"]') as HTMLInputElement;
        expect(input.accept).toBe('.pdf,.png');
        expect(input.required).toBe(true);
        /* jsdom لا يعدّ حقلَ الملف ممتلئًا بعد الرفع — فيُرسَل النموذجُ نفسُه؛ والإلزامُ يُفحص في المتصفّح */
        fireEvent.submit(screen.getByRole('button', { name: /حفظ/ }).closest('form') as HTMLFormElement);

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = vi.mocked(router.post).mock.calls[0] as [string, Record<string, unknown>, Record<string, unknown>];
        expect(url).toBe('/admin.documents.store');
        expect(data.title).toBe('السجل التجاري');
        expect(data.file).toBe(file);
        expect(options.forceFormData).toBe(true);
    });

    it('edits to the update door, keeps the link and needs no new file', async () => {
        const user = userEvent.setup();
        render(<DocumentDialog open onClose={() => {}} document={row} catalog={catalog} />);

        expect(screen.getByText('تعديل المستند')).toBeInTheDocument();
        expect(screen.getAllByRole('textbox')[0]).toHaveValue('وثيقة التأمين');
        expect(screen.getByText('نسخة جديدة من الملف')).toBeInTheDocument();
        expect((document.querySelector('input[type="file"]') as HTMLInputElement).required).toBe(false);
        await user.click(screen.getByRole('button', { name: /حفظ/ }));

        const [url, data] = vi.mocked(router.post).mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/admin.documents.update/5');
        expect(data).toMatchObject({ link_type: 'project', link_id: '3', responsible_id: '7', category_id: '1', expires_at: '2026-10-10' });
        expect(data).not.toHaveProperty('file');
    });
});

describe('documentTone', () => {
    it('reads the status the server computed — expired red, expiring amber, archived grey', () => {
        expect(documentTone('expired')).toContain('#b91c1c');
        expect(documentTone('expiring')).toContain('#b45309');
        expect(documentTone('archived')).toContain('#71717a');
        expect(documentTone('active')).toContain('#047857');
    });
});
