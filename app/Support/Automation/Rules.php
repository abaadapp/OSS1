<?php

namespace App\Support\Automation;

use App\Models\AutomationRule;
use App\Models\User;
use App\Support\Activity;
use App\Support\Communication\Templates;
use RuntimeException;

/**
 * القواعدُ الجاهزة — ما تحتاجه افنت بلس، لا مصنعُ قواعد.
 *
 * ═══ الداخليُّ مفعَّل، والخارجيُّ موقوف ═══
 *
 * ما يُقال للموظّفين يُفعَّل من أوّل يوم على وضع «مراقبة» للشركة (لا يُنفَّذ
 * حتى تُشغَّل الشركةُ الأتمتة). وما يبلغ العميل — متابعةُ العرض، وتذكيرُ
 * الفاتورة، ومتابعةُ التسليم — يُكتب موقوفًا وعلى «مراقبة»: الشركةُ هي التي
 * تفعّله بيدها وتشغّله بيدها، خطوتين لا خطوة.
 *
 * ═══ والقاعدةُ تُكتب مرّةً ═══
 *
 * `ensure()` تُنشئ الناقصَ ولا تمسّ ما كُتب: قاعدةٌ عدّلتها الشركةُ تبقى
 * كما عدّلتها بعد كلّ نشر.
 */
final class Rules
{
    /** @var array<string, array<string, mixed>> */
    public const PRESETS = [
        'project.start' => [
            'name' => 'اقتراب موعد المشروع', 'trigger' => 'project_start', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => -7, 'audience' => 'owner'], ['offset' => -3, 'audience' => 'owner'], ['offset' => -1, 'audience' => 'owner'], ['offset' => -1, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 20, 'business_hours' => false,
        ],
        'task.due' => [
            'name' => 'موعد المهمّة', 'trigger' => 'task_due', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => -1, 'audience' => 'assignee'], ['offset' => 0, 'audience' => 'assignee'], ['offset' => 1, 'audience' => 'assignee'], ['offset' => 3, 'audience' => 'assignee'], ['offset' => 5, 'audience' => 'owner']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 20, 'business_hours' => false,
        ],
        'project.closable' => [
            'name' => 'مشروع جاهز للإقفال', 'trigger' => 'project_closable', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => 0, 'audience' => 'owner'], ['offset' => 3, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 48, 'business_hours' => false,
        ],
        'supplier_invoice.due' => [
            'name' => 'استحقاق سند مورّد', 'trigger' => 'supplier_invoice_due', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => -3, 'audience' => 'finance'], ['offset' => 0, 'audience' => 'finance'], ['offset' => 3, 'audience' => 'finance'], ['offset' => 7, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 48, 'business_hours' => false,
        ],
        'expense.unpaid' => [
            'name' => 'مصروف غير مسدَّد', 'trigger' => 'expense_unpaid', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => 0, 'audience' => 'finance'], ['offset' => 3, 'audience' => 'finance']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 48, 'business_hours' => false,
        ],
        'purchase_order.late' => [
            'name' => 'تأخّر تسليم أمر شراء', 'trigger' => 'purchase_order_late', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => 1, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 72, 'business_hours' => false,
        ],
        'quotation.expiry' => [
            'name' => 'صلاحية عرض السعر', 'trigger' => 'quotation_expiry', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => -2, 'audience' => 'creator'], ['offset' => 0, 'audience' => 'creator']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 20, 'business_hours' => false,
        ],
        'communication.failed' => [
            'name' => 'رسالة لم تصل', 'trigger' => 'communication_failed', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => 0, 'audience' => 'initiator'], ['offset' => 0, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 1, 'business_hours' => false, 'retry_once' => false,
        ],
        'brief.morning' => [
            'name' => 'الموجز الصباحي', 'trigger' => 'morning_brief', 'kind' => 'internal', 'channel' => 'email',
            'steps' => [['offset' => 0, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'live', 'cooldown_hours' => 20, 'business_hours' => false, 'send_time' => '07:30',
        ],
        'quotation.followup' => [
            'name' => 'متابعة عرض السعر مع العميل', 'trigger' => 'quotation_awaiting', 'kind' => 'external', 'channel' => 'email', 'template_key' => 'quotation.reminder',
            'steps' => [['offset' => 3, 'audience' => 'customer'], ['offset' => 7, 'audience' => 'customer']],
            'enabled' => false, 'mode' => 'observe', 'cooldown_hours' => 72, 'business_hours' => true,
        ],
        'invoice.due' => [
            'name' => 'تذكير باستحقاق الفاتورة', 'trigger' => 'invoice_due', 'kind' => 'external', 'channel' => 'email', 'template_key' => 'invoice.reminder',
            'steps' => [['offset' => -3, 'audience' => 'customer'], ['offset' => 0, 'audience' => 'customer']],
            'enabled' => false, 'mode' => 'observe', 'cooldown_hours' => 48, 'business_hours' => true,
        ],
        'invoice.overdue' => [
            'name' => 'تذكير بفاتورة متأخّرة', 'trigger' => 'invoice_due', 'kind' => 'external', 'channel' => 'email', 'template_key' => 'invoice.reminder',
            'steps' => [['offset' => 3, 'audience' => 'customer'], ['offset' => 7, 'audience' => 'customer'], ['offset' => 7, 'audience' => 'finance'], ['offset' => 14, 'audience' => 'management']],
            'enabled' => false, 'mode' => 'observe', 'cooldown_hours' => 72, 'business_hours' => true,
        ],
        /*
         * التشغيلُ — المرحلة 10: داخليٌّ كلُّه، ويُكتب على «مراقبة».
         *
         * الشركةُ تشغّله بيدها بعد أن تقرأ ما كان سيُقال — قاعدةٌ جديدة لا
         * تبدأ بالكلام. ولا خطوةَ فيها إلى العميل.
         */
        'operation.start' => [
            'name' => 'اقتراب موعد الفعاليّة', 'trigger' => 'operation_start', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => -7, 'audience' => 'lead'], ['offset' => -3, 'audience' => 'lead'], ['offset' => -1, 'audience' => 'lead'], ['offset' => -1, 'audience' => 'management'], ['offset' => 0, 'audience' => 'lead']],
            'enabled' => true, 'mode' => 'observe', 'cooldown_hours' => 20, 'business_hours' => false,
        ],
        'crew.assignment' => [
            'name' => 'تذكير الفريق بتعيينه', 'trigger' => 'crew_assignment', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => -1, 'audience' => 'assignee'], ['offset' => 0, 'audience' => 'assignee']],
            'enabled' => true, 'mode' => 'observe', 'cooldown_hours' => 20, 'business_hours' => false,
        ],
        'equipment.checkout' => [
            'name' => 'تذكير بتسليم المعدّات', 'trigger' => 'equipment_checkout', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => -1, 'audience' => 'lead'], ['offset' => 0, 'audience' => 'lead']],
            'enabled' => true, 'mode' => 'observe', 'cooldown_hours' => 20, 'business_hours' => false,
        ],
        'equipment.unreturned' => [
            'name' => 'معدّات لم تُردّ', 'trigger' => 'equipment_unreturned', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => 1, 'audience' => 'lead'], ['offset' => 3, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'observe', 'cooldown_hours' => 48, 'business_hours' => false,
        ],
        'operation.closeout' => [
            'name' => 'فعاليّة انتهت ولم تُتمّ', 'trigger' => 'operation_closeout', 'kind' => 'internal', 'channel' => 'internal',
            'steps' => [['offset' => 1, 'audience' => 'lead'], ['offset' => 3, 'audience' => 'management']],
            'enabled' => true, 'mode' => 'observe', 'cooldown_hours' => 48, 'business_hours' => false,
        ],
        'delivery.followup' => [
            'name' => 'متابعة حزمة التسليم مع العميل', 'trigger' => 'delivery_awaiting', 'kind' => 'external', 'channel' => 'email', 'template_key' => 'delivery.reminder',
            'steps' => [['offset' => 2, 'audience' => 'customer'], ['offset' => 5, 'audience' => 'customer']],
            'enabled' => false, 'mode' => 'observe', 'cooldown_hours' => 72, 'business_hours' => true,
        ],
    ];

    /** ما تسمح القاعدةُ بتغييره من الشاشة — وكلُّه قيمٌ من قوائم */
    public const EDITABLE = ['name', 'enabled', 'mode', 'channel', 'template_key', 'steps', 'send_time', 'cooldown_hours', 'business_hours', 'language', 'recipients', 'retry_once'];

    public const MAX_STEPS = 8;

    public const MAX_OFFSET = 60;

    /** إنشاءُ الناقص من القواعد الجاهزة — بلا مسّ ما كُتب */
    public static function ensure(int $businessId): void
    {
        $existing = AutomationRule::where('business_id', $businessId)->pluck('key')->flip();

        foreach (self::PRESETS as $key => $preset) {
            if (isset($existing[$key])) {
                continue;
            }

            AutomationRule::create(['business_id' => $businessId, 'key' => $key] + self::defaults($preset));
        }
    }

    /** @return array<string, mixed> */
    private static function defaults(array $preset): array
    {
        return [
            'name' => $preset['name'],
            'kind' => $preset['kind'],
            'trigger' => $preset['trigger'],
            'enabled' => (bool) $preset['enabled'],
            'mode' => $preset['mode'],
            'channel' => $preset['channel'],
            'template_key' => $preset['template_key'] ?? null,
            'steps' => $preset['steps'],
            'send_time' => $preset['send_time'] ?? null,
            'cooldown_hours' => (int) $preset['cooldown_hours'],
            'business_hours' => (bool) $preset['business_hours'],
            'language' => 'ar',
            'recipients' => null,
            'retry_once' => (bool) ($preset['retry_once'] ?? false),
        ];
    }

    /**
     * حفظُ تعديلٍ من الشاشة — بفحصٍ صارمٍ لكلّ قيمة، وأثرٍ لكلّ تغيير.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(AutomationRule $rule, array $data, ?User $actor = null): AutomationRule
    {
        $trigger = Triggers::for($rule->trigger);
        $before = $rule->only(self::EDITABLE);
        $update = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '' || mb_strlen($name) > 120) {
                throw new RuntimeException(__('اسمُ القاعدة مطلوب — حتى 120 حرفًا.'));
            }
            $update['name'] = $name;
        }

        if (array_key_exists('enabled', $data)) {
            $update['enabled'] = (bool) $data['enabled'];
        }

        if (array_key_exists('mode', $data)) {
            if (! in_array($data['mode'], AutomationRule::MODES, true)) {
                throw new RuntimeException(__('وضعٌ غير معروف.'));
            }
            $update['mode'] = $data['mode'];
        }

        if (array_key_exists('channel', $data)) {
            if (! in_array($data['channel'], AutomationRule::CHANNELS, true)) {
                throw new RuntimeException(__('قناةٌ غير معروفة.'));
            }
            $update['channel'] = $data['channel'];
        }

        if (array_key_exists('steps', $data)) {
            $update['steps'] = self::steps((array) $data['steps'], $trigger);
        }

        $steps = $update['steps'] ?? $rule->steps();
        $external = collect($steps)->contains(fn ($s) => Audience::isExternal($s['audience']));
        $update['kind'] = $external ? AutomationRule::EXTERNAL : AutomationRule::INTERNAL;
        $channel = $update['channel'] ?? $rule->channel;

        if (array_key_exists('template_key', $data) || $external) {
            $key = array_key_exists('template_key', $data) ? (filled($data['template_key']) ? (string) $data['template_key'] : null) : $rule->template_key;

            if ($external) {
                if ($key === null || ! Templates::exists($key)) {
                    throw new RuntimeException(__('الخطوةُ إلى العميل تحتاج قالبًا من كتالوج المراسلات.'));
                }
                $catalog = Templates::describe($key);
                if ($catalog['subject'] !== $trigger->subjectType() || ! empty($catalog['internal'])) {
                    throw new RuntimeException(__('هذا القالب لا يخصّ مستندَ هذا المُطلِق.'));
                }
                if ($channel === 'internal' || ! in_array($channel, $catalog['channels'], true)) {
                    throw new RuntimeException(__('هذا القالب لا يُرسَل عبر هذه القناة.'));
                }
            }

            $update['template_key'] = $key;
        }

        if (array_key_exists('send_time', $data)) {
            $t = filled($data['send_time']) ? (string) $data['send_time'] : null;
            if ($t !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) !== 1) {
                throw new RuntimeException(__('الوقتُ بصيغة HH:MM.'));
            }
            $update['send_time'] = $t;
        }

        if (array_key_exists('cooldown_hours', $data)) {
            $h = (int) $data['cooldown_hours'];
            if ($h < 1 || $h > 720) {
                throw new RuntimeException(__('فترةُ الهدوء بين ساعةٍ و720 ساعة.'));
            }
            $update['cooldown_hours'] = $h;
        }

        if (array_key_exists('business_hours', $data)) {
            $update['business_hours'] = (bool) $data['business_hours'];
        }

        if (array_key_exists('language', $data)) {
            if (! in_array($data['language'], ['ar', 'en'], true)) {
                throw new RuntimeException(__('لغةٌ غير مدعومة.'));
            }
            $update['language'] = $data['language'];
        }

        if (array_key_exists('recipients', $data)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['recipients'] ?? [])))));
            $known = User::where('business_id', $rule->business_id)->whereIn('id', $ids)->pluck('id')->all();
            if (count($known) !== count($ids)) {
                throw new RuntimeException(__('متلقٍّ ليس من موظّفي شركتك.'));
            }
            $update['recipients'] = $ids === [] ? null : $ids;
        }

        if (array_key_exists('retry_once', $data)) {
            $update['retry_once'] = (bool) $data['retry_once'];
        }

        $update['updated_by'] = $actor?->id;
        $rule->update($update);
        $rule->refresh();

        $changed = array_keys(array_filter($before, fn ($v, $k) => $v != ($rule->{$k} ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $what = in_array('enabled', $changed, true) ? ($rule->enabled ? 'فعّل' : 'أوقف') : 'عدّل';
            Activity::log('settings', $what.' قاعدة الأتمتة «'.$rule->name.'» ('.implode('، ', $changed).')'
                .($rule->isExternal() ? ' — تبلغ العميل' : ''), [
                    'business_id' => (int) $rule->business_id, 'subject_id' => $rule->id, 'subject_type' => 'automation_rule', 'icon' => 'zap',
                    'color' => $rule->isExternal() && $rule->enabled ? 'warning' : 'primary',
                ]);
        }

        return $rule;
    }

    /**
     * الخطواتُ بعد الفحص: أرقامُ أيّامٍ محدودة وجمهورٌ يعرفه المُطلِق — ولا تكرار.
     *
     * @return list<array{offset: int, audience: string}>
     */
    private static function steps(array $raw, Trigger $trigger): array
    {
        $steps = [];

        foreach ($raw as $s) {
            if (! is_array($s) || ! isset($s['offset'], $s['audience'])) {
                throw new RuntimeException(__('خطوةٌ ناقصة.'));
            }
            $offset = (int) $s['offset'];
            $audience = (string) $s['audience'];

            if ($offset < -self::MAX_OFFSET || $offset > self::MAX_OFFSET) {
                throw new RuntimeException(__('الأيّامُ بين -:n و:n.', ['n' => self::MAX_OFFSET]));
            }
            if (! in_array($audience, Audience::ALL, true) || ! in_array($audience, $trigger->audiences(), true)) {
                throw new RuntimeException(__('هذا الجمهور لا يصلح لهذا المُطلِق.'));
            }

            $steps[$offset.'|'.$audience] = ['offset' => $offset, 'audience' => $audience];
        }

        if ($steps === [] || count($steps) > self::MAX_STEPS) {
            throw new RuntimeException(__('خطوةٌ واحدة على الأقلّ، و:n على الأكثر.', ['n' => self::MAX_STEPS]));
        }

        usort($steps, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        return array_values($steps);
    }
}
