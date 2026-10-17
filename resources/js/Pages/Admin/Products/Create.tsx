import { usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ProductForm, { type PackageData } from './partials/ProductForm';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Category } from '@/types/models';

export default function ProductCreate() {
    const { categories, package: pkg, context } =
        usePage<PageProps<{ categories: Category[]; package: PackageData }>>().props;
    const t = useTranslate();

    return (
        <AdminLayout title="إضافة منتج">
            <PageHeader
                title="إضافة منتج"
                subtitle={t('صنفٌ أو خدمةٌ أو باقة في كتالوج الشركة')}
            />

            <ProductForm
                categories={categories}
                currencyLabel={context!.currency.symbol || context!.currency.code}
                package={pkg}
                currency={context!.currency}
            />
        </AdminLayout>
    );
}
