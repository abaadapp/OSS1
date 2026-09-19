<?php

namespace App\Support\Policy;

use App\Models\ChangeOrder;
use App\Models\CompanyPolicy;
use App\Models\Quotation;

/**
 * فحصُ سياسةٍ — يجيب سؤالًا واحدًا ولا يكتب شيئًا.
 *
 * يُعيد `Finding` إن خُولفت السياسة، وnull إن لم تُخالَف. والكتابةُ —
 * استثناءٌ يُسجَّل، وحالٌ تتغيّر — شأنُ من يناديه. انظر §18.
 *
 * والموضوعُ عرضُ سعرٍ أو أمرُ تغيير: كلاهما ورقةٌ تخرج إلى العميل وتُختم
 * سياساتُها فيها. وفحصٌ لا يعني أحدَهما يردّ null له.
 */
interface Check
{
    public function evaluate(CompanyPolicy $policy, Quotation|ChangeOrder $subject): ?Finding;
}
