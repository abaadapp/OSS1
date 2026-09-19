<?php

namespace App\Support\Import;

use App\Models\ImportLink;
use Illuminate\Database\Eloquent\Model;

/**
 * المنشأ — من أين جاء هذا الكيان؟
 *
 * «native» وُلد في النظام بدورته: عرضٌ أُرسل وقُبل، فاتورةٌ أُصدرت اليوم.
 * و«historical_import» جاء من الخزنة: مستندٌ كان قبل النظام، له رقمُه
 * وتاريخُه، **ولا دليلَ** على دورةٍ لم يمرّ بها — لا توقيعَ ولا رسالةَ ولا
 * تذكيرًا. والأتمتةُ تسأل هنا قبل أن تُذكّر بشيء.
 */
final class Provenance
{
    public const NATIVE = 'native';

    public const HISTORICAL = 'historical_import';

    /** أجاء هذا الكيانُ من استيرادٍ تاريخيّ؟ — من عموده، وإلّا من أثره */
    public static function isHistorical(Model $model): bool
    {
        if (array_key_exists('origin', $model->getAttributes())) {
            return $model->getAttribute('origin') === self::HISTORICAL;
        }

        return ImportLink::where('linkable_type', $model->getMorphClass())
            ->where('linkable_id', $model->getKey())->where('action', ImportLink::CREATED)->exists();
    }

    /** أثرُ الكيان — «DATA-0001#ROW-42» — أو null لما وُلد هنا */
    public static function sourceRef(Model $model): ?string
    {
        return ImportLink::where('linkable_type', $model->getMorphClass())
            ->where('linkable_id', $model->getKey())->orderBy('id')->value('source_ref');
    }
}
