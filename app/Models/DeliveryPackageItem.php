<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بندُ حزمة تسليم — ملفٌّ دليلٌ، أو سطرُ خدمةٍ سُلِّم بكميّة.
 *
 * والملفُّ على القرص الخاصّ ويُقرأ ببابٍ يسأل عن صاحبه: من اللوحة بقسمه،
 * ومن رابط العميل بحزمةٍ خرجت إليه. و`checksum` بصمةُ محتواه يومَ رُفع —
 * فما قُبل يُثبَت أنّه هذا الملفُّ بعينه.
 *
 * و`project_item_id` وصلةُ المرحلة 4 — تُملأ حين يُوصَل المشروع، وتبقى
 * null حتى ذلك.
 */
class DeliveryPackageItem extends Model
{
    /** ملفٌّ يُرفع — تقريرٌ أو صورةٌ أو مخرَجٌ نهائيّ */
    public const FILE = 'file';

    /** سطرُ خدمةٍ أو نطاقٍ سُلِّم — بكميّةٍ ووحدة */
    public const ITEM = 'item';

    /** @var list<string> */
    public const KINDS = [self::FILE, self::ITEM];

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'float',
        'size' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(DeliveryPackage::class, 'delivery_package_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** بندُ النطاق الذي يُسلَّم منه — وتُزاد كميّتُه المسلَّمة عند القبول */
    public function projectItem(): BelongsTo
    {
        return $this->belongsTo(ProjectItem::class);
    }

    public function isFile(): bool
    {
        return $this->kind === self::FILE;
    }

    public function hasFile(): bool
    {
        return $this->isFile() && filled($this->path);
    }
}
