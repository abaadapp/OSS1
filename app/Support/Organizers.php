<?php

namespace App\Support;

use App\Models\OperationOrganizer;
use App\Models\Organizer;
use App\Models\OrganizerCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * سجلُّ المنظّمين وتصنيفاتُهم.
 *
 * المنظّمُ ليس عميلًا ولا موظّفًا بحساب: شخصٌ يعمل في الميدان من فعاليّةٍ إلى
 * أخرى. والجنسُ والحالُ والمصدرُ قوائمُ مغلقة (`Organizer::*`) لا نصٌّ حرّ،
 * والتصنيفاتُ صفوفٌ يديرها أصحابُ الشركة — كثيرٌ لكثير.
 *
 * وتعيينُه على الفعاليّة في `Operations\OperationOrganizers`.
 */
final class Organizers
{
    public const GENDER_LABELS = [Organizer::MALE => 'ذكر', Organizer::FEMALE => 'أنثى'];

    public const STATUS_LABELS = [
        Organizer::AVAILABLE => 'متاح',
        Organizer::UNAVAILABLE => 'غير متاح',
        Organizer::SUSPENDED => 'موقوف',
    ];

    public const SOURCE_LABELS = ['internal' => 'داخلي', 'freelance' => 'متعاون', 'external' => 'خارجي'];

    /** تصنيفاتٌ تبدأ بها الشركة — تُعدَّل وتُعطَّل ويُزاد عليها */
    public const DEFAULT_CATEGORIES = [
        'استقبال', 'تسجيل', 'بروتوكول', 'VIP', 'تنظيم بوابات', 'ضيافة',
        'Crowd Control', 'إشراف', 'Backstage', 'تنظيم جمهور',
    ];

    /** @return array<string, mixed> */
    public static function rules(int $businessId): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'gender' => ['required', Rule::in(Organizer::GENDERS)],
            'status' => ['nullable', Rule::in(Organizer::STATUSES)],
            'source' => ['nullable', Rule::in(Organizer::SOURCES)],
            'joined_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'category_ids' => ['nullable', 'array', 'max:30'],
            'category_ids.*' => ['integer', Rule::exists('organizer_categories', 'id')->where('business_id', $businessId)],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function create(int $businessId, array $data, ?User $actor = null): Organizer
    {
        if (! in_array($data['gender'] ?? null, Organizer::GENDERS, true)) {
            throw new RuntimeException(__('اختر جنس المنظّم: ذكر أو أنثى.'));
        }

        return DB::transaction(function () use ($businessId, $data, $actor) {
            $organizer = Organizer::create(self::fields($data) + [
                'business_id' => $businessId,
                'status' => $data['status'] ?? Organizer::AVAILABLE,
                'created_by' => $actor?->id, 'updated_by' => $actor?->id,
            ]);
            self::syncCategories($organizer, (array) ($data['category_ids'] ?? []));

            Activity::log('created', 'أضاف المنظّم '.$organizer->name, [
                'business_id' => $businessId, 'subject_id' => $organizer->id, 'subject_type' => 'organizer', 'icon' => 'user-plus',
            ]);

            return $organizer->fresh('categories');
        });
    }

    /** @param  array<string, mixed>  $data */
    public static function update(Organizer $organizer, array $data, ?User $actor = null): Organizer
    {
        return DB::transaction(function () use ($organizer, $data, $actor) {
            $before = $organizer->status;
            $organizer->update(self::fields($data) + ['updated_by' => $actor?->id]);
            if (array_key_exists('category_ids', $data)) {
                self::syncCategories($organizer, (array) ($data['category_ids'] ?? []));
            }

            Activity::log('updated', 'عدّل بيانات المنظّم '.$organizer->name, [
                'business_id' => $organizer->business_id, 'subject_id' => $organizer->id, 'subject_type' => 'organizer', 'icon' => 'pencil',
            ]);

            if (isset($data['status']) && $data['status'] !== $before) {
                self::setStatus($organizer->fresh(), (string) $data['status'], $actor, $before);
            }

            return $organizer->fresh('categories');
        });
    }

    /** تغييرُ حاله — متاح · غير متاح · موقوف */
    public static function setStatus(Organizer $organizer, string $status, ?User $actor = null, ?string $before = null): Organizer
    {
        if (! in_array($status, Organizer::STATUSES, true)) {
            throw new RuntimeException(__('حالةٌ غير معروفة.'));
        }

        $before ??= $organizer->status;
        if ($organizer->status !== $status) {
            $organizer->update(['status' => $status, 'updated_by' => $actor?->id]);
        }

        if ($before !== $status) {
            Activity::log('status', 'غيّر حال المنظّم '.$organizer->name.' من «'.self::STATUS_LABELS[$before].'» إلى «'.self::STATUS_LABELS[$status].'»', [
                'business_id' => $organizer->business_id, 'subject_id' => $organizer->id, 'subject_type' => 'organizer',
                'color' => $status === Organizer::SUSPENDED ? 'danger' : 'primary',
            ]);
        }

        return $organizer->fresh();
    }

    /** حذفٌ آمن — من عُيّن في فعاليّةٍ يُوقَف ولا يُحذف: سجلُّ مشاركاته يبقى */
    public static function delete(Organizer $organizer, ?User $actor = null): void
    {
        if (OperationOrganizer::where('organizer_id', $organizer->id)->exists()) {
            throw new RuntimeException(__('لهذا المنظّم مشاركاتٌ في فعاليّات — أوقفه بدل حذفه ليبقى سجلّه.'));
        }

        $name = $organizer->name;
        $organizer->delete();

        Activity::log('deleted', 'حذف المنظّم '.$name, [
            'business_id' => $organizer->business_id, 'subject_type' => 'organizer', 'icon' => 'user-minus',
        ]);
    }

    /** @param  list<mixed>  $ids */
    public static function syncCategories(Organizer $organizer, array $ids): void
    {
        $valid = OrganizerCategory::where('business_id', $organizer->business_id)
            ->whereIn('id', array_map('intval', $ids))->pluck('id')->all();

        $organizer->categories()->sync(collect($valid)->mapWithKeys(fn ($id) => [$id => ['business_id' => $organizer->business_id]])->all());
    }

    /* ─────────────────────────── التصنيفات ─────────────────────────── */

    /** تصنيفاتُ البداية — مرّةً لكلّ شركة، حين لا تصنيفَ لها بعد */
    public static function ensureCategories(int $businessId): void
    {
        if (OrganizerCategory::where('business_id', $businessId)->exists()) {
            return;
        }

        foreach (self::DEFAULT_CATEGORIES as $i => $name) {
            OrganizerCategory::firstOrCreate(['business_id' => $businessId, 'name' => $name], ['sort_order' => $i + 1, 'active' => true]);
        }
    }

    public static function saveCategory(int $businessId, string $name, bool $active = true, ?OrganizerCategory $category = null, ?User $actor = null): OrganizerCategory
    {
        $name = Projects::text($name, 80) ?? throw new RuntimeException(__('اكتب اسم التصنيف.'));

        $taken = OrganizerCategory::where('business_id', $businessId)->where('name', $name)
            ->when($category, fn ($q) => $q->whereKeyNot($category->id))->exists();
        if ($taken) {
            throw new RuntimeException(__('هذا التصنيف موجود.'));
        }

        if ($category === null) {
            $category = OrganizerCategory::create([
                'business_id' => $businessId, 'name' => $name, 'active' => $active,
                'sort_order' => (int) OrganizerCategory::where('business_id', $businessId)->max('sort_order') + 1,
            ]);
            Activity::log('created', 'أضاف تصنيف المنظّمين «'.$name.'»', ['business_id' => $businessId, 'subject_type' => 'organizer']);
        } else {
            $category->update(['name' => $name, 'active' => $active]);
            Activity::log('updated', 'عدّل تصنيف المنظّمين «'.$name.'»', ['business_id' => $businessId, 'subject_type' => 'organizer']);
        }

        return $category->fresh();
    }

    /** حذفُ تصنيفٍ لم يُستعمل — وما استُعمل يُعطَّل */
    public static function deleteCategory(OrganizerCategory $category): void
    {
        $used = DB::table('organizer_category_organizer')->where('organizer_category_id', $category->id)->exists()
            || OperationOrganizer::where('organizer_category_id', $category->id)->exists();

        if ($used) {
            throw new RuntimeException(__('هذا التصنيف مستعمَل — عطّله بدل حذفه.'));
        }

        $category->delete();
    }

    /** @return array<string, mixed> */
    private static function fields(array $data): array
    {
        $out = [];
        foreach (['name' => 120, 'phone' => 40, 'whatsapp' => 40, 'email' => 120, 'notes' => 2000] as $field => $max) {
            if (array_key_exists($field, $data)) {
                $out[$field] = Projects::text($data[$field], $max);
            }
        }
        if (array_key_exists('gender', $data)) {
            $out['gender'] = in_array($data['gender'], Organizer::GENDERS, true)
                ? $data['gender'] : throw new RuntimeException(__('اختر جنس المنظّم: ذكر أو أنثى.'));
        }
        if (array_key_exists('source', $data)) {
            $out['source'] = in_array($data['source'], Organizer::SOURCES, true) ? $data['source'] : null;
        }
        if (array_key_exists('joined_at', $data)) {
            $out['joined_at'] = Projects::date($data['joined_at']);
        }
        if (array_key_exists('name', $out) && $out['name'] === null) {
            throw new RuntimeException(__('اكتب اسم المنظّم.'));
        }

        return $out;
    }
}
