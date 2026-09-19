<?php

namespace App\Support\Automation;

use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Business;
use App\Models\InternalNotification;
use App\Models\User;
use App\Support\CommandCenter;
use App\Support\Messaging\MessageIntent;
use App\Support\Messaging\Notifiers;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * الموجزُ الصباحيّ — ما يحتاج يدًا اليوم، في سطورٍ قليلة، من مركز العمليات.
 *
 * لا تُفرَّغ اللوحةُ كلُّها: ستّةُ أرقامٍ ثمّ أهمُّ عشرة أسطر. ولكلّ متلقٍّ
 * موجزُه بصلاحيّاته: من لا يرى المال لا يبلغه رقمُ المال.
 */
final class Brief
{
    public const TEMPLATE = 'automation.brief';

    private const LINES = 10;

    /**
     * @return array{subject: string, paragraphs: list<string>, url: string, actionable: int}
     */
    public static function for(int $businessId, User $user): array
    {
        $c = CommandCenter::for($businessId, $user);
        $business = Business::find($businessId);
        $today = collect($c['upcoming']['rows'])->filter(fn ($p) => $p['running'] || $p['days_to_start'] === 0)->count();
        $lateTasks = collect($c['late']['rows'])->where('kind', 'task')->count();

        $numbers = [
            __('مشاريع اليوم: :n', ['n' => $today]),
            __('يحتاج قرارًا: :n', ['n' => $c['summary']['decisions']]),
            __('مهامّ متأخّرة: :n', ['n' => $lateTasks]),
        ];
        if ($c['sees_money'] && $c['money'] !== null) {
            $numbers[] = __('مال يُحصَّل (متأخّر): :amount', ['amount' => Money::format((float) $c['money']['receivables']['overdue'], Money::of($businessId))]);
        }
        $numbers[] = __('تسليمات تنتظر: :n', ['n' => $c['summary']['delivery']]);
        $numbers[] = __('رسائل لم تصل: :n', ['n' => $c['summary']['communications']]);

        $items = [];
        foreach (['decisions', 'late', 'delivery', 'communications'] as $section) {
            foreach ($c[$section]['rows'] as $row) {
                $items[] = '• '.$row['title'].' — '.$row['action'];
                if (count($items) >= self::LINES) {
                    break 2;
                }
            }
        }

        $paragraphs = [__('افنت بلس — اليوم :date', ['date' => Settings::for($businessId)->now()->format('Y-m-d')]), implode("\n", $numbers)];
        if ($items !== []) {
            $paragraphs[] = implode("\n", $items);
        } else {
            $paragraphs[] = __('لا شيء يحتاج يدًا اليوم.');
        }

        return [
            'subject' => __('الموجز الصباحي — :company', ['company' => (string) ($business?->name ?? '')]),
            'paragraphs' => $paragraphs,
            'url' => route('admin.command.index'),
            'actionable' => array_sum($c['summary']),
        ];
    }

    /**
     * إيصالُ الموجز — تنبيهٌ داخليّ لكلّ متلقٍّ، وبريدٌ إن كانت القناةُ بريدًا وكان موصولًا.
     *
     * @param  Collection<int, User>  $users
     * @return array{result: string, notification_count: int}
     */
    public static function deliver(int $businessId, AutomationRule $rule, AutomationRun $run, Collection $users): array
    {
        $business = Business::find($businessId);
        $count = 0;
        $emailed = 0;

        foreach ($users->unique('id') as $user) {
            $brief = self::for($businessId, $user);

            InternalNotification::create([
                'business_id' => $businessId,
                'user_id' => $user->id,
                'kind' => 'morning_brief',
                'title' => $brief['subject'],
                'body' => implode("\n\n", $brief['paragraphs']),
                'url' => $brief['url'],
                'automation_run_id' => $run->id,
            ]);
            $count++;

            if ($rule->channel === 'email' && filled($user->email) && Notifiers::email()->configured()) {
                $result = Notifiers::email()->send(new MessageIntent(
                    to: (string) $user->email,
                    subject: $brief['subject'],
                    paragraphs: $brief['paragraphs'],
                    url: $brief['url'],
                    action: __('افتح مركز العمليات'),
                    templateKey: self::TEMPLATE,
                    senderName: $business?->name,
                    footer: $business?->name,
                    businessId: $businessId,
                    recipientName: $user->name,
                ));
                if ($result->sent) {
                    $emailed++;
                }
            }
        }

        return [
            'result' => __('وصل الموجز إلى :n — بالبريد: :e', ['n' => $count, 'e' => $emailed]),
            'notification_count' => $count,
        ];
    }
}
