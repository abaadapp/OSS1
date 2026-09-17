<?php

namespace App\Support\Policy;

use App\Models\CompanyPolicy;
use App\Models\Quotation;

/**
 * فحصُ سياسةٍ — يجيب سؤالًا واحدًا ولا يكتب شيئًا.
 *
 * يُعيد `Finding` إن خُولفت السياسة، وnull إن لم تُخالَف. والكتابةُ —
 * استثناءٌ يُسجَّل، وحالٌ تتغيّر — شأنُ من يناديه. انظر §18.
 */
interface Check
{
    public function evaluate(CompanyPolicy $policy, Quotation $subject): ?Finding;
}
