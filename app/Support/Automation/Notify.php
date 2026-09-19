<?php

namespace App\Support\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Business;
use App\Models\InternalNotification;
use App\Models\User;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * التنبيهُ الداخليّ — سطرٌ في جرس الموظّف يقود إلى مستنده، وبريدٌ إن طُلب.
 *
 * والبريدُ الداخليّ يمرّ بالمرحلة 6 كغيره (`Notifiers::email`) فيُقيَّد في
 * المراسلات موسومًا داخليًّا — ولا يُنشأ بابُ بريدٍ ثانٍ.
 */
final class Notify
{
    public const TEMPLATE = 'automation.notice';

    /**
     * @param  Collection<int, User>  $users
     * @return array{count: int, emailed: int}
     */
    public static function users(Collection $users, AutomationRule $rule, AutomationRun $run, string $title, ?string $body, string $url, ?Model $subject): array
    {
        $count = 0;
        $emailed = 0;
        $business = Business::find((int) $rule->business_id);

        foreach ($users->unique('id') as $user) {
            InternalNotification::create([
                'business_id' => (int) $rule->business_id,
                'user_id' => $user->id,
                'kind' => $run->trigger,
                'title' => mb_substr($title, 0, 255),
                'body' => $body,
                'url' => $url,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'automation_run_id' => $run->id,
            ]);
            $count++;

            if ($rule->channel === 'email' && filled($user->email) && Notifiers::email()->configured()) {
                $result = Notifiers::email()->send(new MessageIntent(
                    to: (string) $user->email,
                    subject: mb_substr($title, 0, 200),
                    paragraphs: array_values(array_filter([$title, $body])),
                    url: $url,
                    action: __('افتح في النظام'),
                    templateKey: self::TEMPLATE,
                    senderName: $business?->name,
                    footer: $business?->name,
                    about: $subject,
                    businessId: (int) $rule->business_id,
                    recipientName: $user->name,
                ));
                if ($result->sent) {
                    $emailed++;
                }
            }
        }

        return ['count' => $count, 'emailed' => $emailed];
    }
}
