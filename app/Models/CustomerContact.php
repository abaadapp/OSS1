<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * شخصُ اتصالٍ في جهة — مديرُ التسويق، والمحاسب، ومنسّقُ الفعاليّة.
 *
 * صفٌّ لكلّ شخص لا أعمدةٌ مرقّمة على العميل، وواحدٌ منهم أساسيٌّ بقيدٍ في
 * القاعدة (`customer_contacts_one_primary`). والمعطَّلُ يبقى مقروءًا في
 * المستندات التي اختارته، ولا يُعرض للاختيار من جديد — انظر `CustomerContacts`.
 */
class CustomerContact extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_primary' => 'boolean',
        'active' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** رقمُ واتساب — رقمُه الخاصّ إن كُتب، وإلّا هاتفُه */
    public function whatsappNumber(): ?string
    {
        return $this->whatsapp ?: $this->phone;
    }
}
