<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * تنفيذُ الفعاليّة على الأرض — المرحلة 10.
 *
 * المشروعُ حاويةٌ تجاريّة: عميلٌ وعرضٌ ونطاقٌ وقيمةٌ وفواتير. وهذا تشغيلُه:
 * متى وأين، ومن يعمل، وما المعدّات، وما الذي جرى. ومشروعٌ واحد قد يُنفَّذ
 * على أكثر من فعاليّة (يومان في قاعتين). ولا مالَ هنا ولا يجوز.
 *
 * والحالُ قائمةٌ مغلقة تنتقل بخدمةٍ لا من متحكّم — انظر
 * `Operations\EventOperations::transition`.
 */
class EventOperation extends Model
{
    public const PLANNING = 'planning';

    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const SETUP = 'setup';

    public const LIVE = 'live';

    public const TEARDOWN = 'teardown';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [
        self::PLANNING, self::PREPARING, self::READY, self::SETUP, self::LIVE, self::TEARDOWN, self::COMPLETED, self::CANCELLED,
    ];

    /** ما لم يُختم بعد — يقبل عملًا عليه */
    public const ACTIVE = [self::PLANNING, self::PREPARING, self::READY, self::SETUP, self::LIVE, self::TEARDOWN];

    /** على الأرض الآن — التجهيزُ أو البثّ أو الفكّ */
    public const ON_SITE = [self::SETUP, self::LIVE, self::TEARDOWN];

    protected $guarded = [];

    protected $casts = [
        'setup_starts_at' => 'datetime',
        'doors_open_at' => 'datetime',
        'event_starts_at' => 'datetime',
        'event_ends_at' => 'datetime',
        'teardown_starts_at' => 'datetime',
        'teardown_ends_at' => 'datetime',
        'went_live_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'readiness_override' => 'array',
        'tasks_published_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operations_lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scheduleItems(): HasMany
    {
        return $this->hasMany(OperationScheduleItem::class)->orderBy('sort_order')->orderBy('starts_at')->orderBy('id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(OperationEquipmentRequirement::class)->orderBy('sort_order')->orderBy('id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(AssetReservation::class)->orderBy('id');
    }

    /** مسؤولُ المنظّمين — موظّفٌ من الشركة */
    public function organizersLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizers_lead_user_id');
    }

    public function organizers(): HasMany
    {
        return $this->hasMany(OperationOrganizer::class);
    }

    public function crew(): HasMany
    {
        return $this->hasMany(OperationCrewAssignment::class)->orderBy('id');
    }

    /** مهامُّ المشروع التي تقف على هذه الفعاليّة — `ProjectTask` نفسُها لا نسخةٌ عنها */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(OperationChecklist::class)->orderBy('sort_order')->orderBy('id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(OperationIncident::class)->orderByDesc('occurred_at');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isOnSite(): bool
    {
        return in_array($this->status, self::ON_SITE, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /** أوّلُ لحظةٍ تُشغَل فيها المعدّاتُ والفريق — التجهيزُ إن كُتب وإلّا البداية */
    public function windowStart(): ?Carbon
    {
        return $this->setup_starts_at ?? $this->event_starts_at;
    }

    /** آخرُ لحظة — الفكُّ إن كُتب وإلّا النهاية وإلّا البداية */
    public function windowEnd(): ?Carbon
    {
        return $this->teardown_ends_at ?? $this->event_ends_at ?? $this->event_starts_at;
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE);
    }
}
