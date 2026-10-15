<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * منظّمٌ ميدانيّ — ليس عميلًا ولا موظّفًا بحساب، ويعمل من فعاليّةٍ إلى أخرى.
 *
 * له تصنيفاتٌ كثيرة (استقبال · تسجيل · بروتوكول …) يديرها أصحابُ الشركة،
 * ويُعيَّن على الفعاليّة لا على المشروع — انظر `OperationOrganizer`.
 */
class Organizer extends Model
{
    public const MALE = 'male';

    public const FEMALE = 'female';

    /** @var list<string> */
    public const GENDERS = [self::MALE, self::FEMALE];

    public const AVAILABLE = 'available';

    public const UNAVAILABLE = 'unavailable';

    /** موقوفٌ — لا يُعيَّن على فعاليّةٍ جديدة */
    public const SUSPENDED = 'suspended';

    /** @var list<string> */
    public const STATUSES = [self::AVAILABLE, self::UNAVAILABLE, self::SUSPENDED];

    /** @var list<string> */
    public const SOURCES = ['internal', 'freelance', 'external'];

    protected $guarded = [];

    protected $casts = ['joined_at' => 'date'];

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(OrganizerCategory::class, 'organizer_category_organizer')->withPivot('business_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OperationOrganizer::class);
    }
}
