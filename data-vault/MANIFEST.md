# المانيفست — سجلّ مجموعات البيانات

كلُّ ملفٍّ وصل إلى الخزنة له صفٌّ هنا بمعرِّفٍ لا يتغيّر. يُحدَّث الصفُّ
كلّما انتقلت المجموعة مرحلةً — **قبل** نقل الملفّ لا بعده.

المعرِّفات تسلسليّة: `DATA-0001` · `DATA-0002` · `DATA-0003` … ولا يُعاد
استعمال معرِّفٍ ولو حُذفت مجموعتُه (يُعلَّم `Current stage = withdrawn`).

> ⚠️ المانيفست يُتتبَّع في Git والمستودع عامّ: لا تُكتب فيه بياناتٌ من داخل
> الملفّات (أسماءُ عملاء، مبالغ، أرقام). يُكتب **عن** الملفّ لا **منه**.

---

## القيم المسموحة

| الحقل | القيم |
|---|---|
| `Category` | finance · events · clients · suppliers · quotations · invoices · expenses · contacts · team · services · assets · other |
| `Sensitivity` | `low` (عامّ) · `medium` (تجاريّ داخليّ) · `high` (بيانات أشخاص أو مال) — و**لا `secret`**: ما هو سرٌّ لا يدخل الخزنة أصلًا |
| `Current stage` | inbox · raw · cleaned · mapped · ready · imported · withdrawn |
| `Reviewed` / `Cleaned` / `Mapped` / `Ready for import` / `Imported` | yes · no · partial |
| `Verification status` | pending · passed · failed · n/a |

---

## القالب — يُنسخ لكلّ مجموعة

```markdown
### DATA-0000 — <وصف قصير>

| Field | Value |
|---|---|
| Dataset ID | DATA-0000 |
| Original filename | |
| Category | |
| Source | (من أين جاء: نظام قديم · بريد · محاسب · ورق ممسوح) |
| Date received | YYYY-MM-DD |
| Original date range | YYYY-MM-DD → YYYY-MM-DD |
| File format | xlsx · csv · json · pdf · … |
| Record count | (تقريبيّ عند الوصول، دقيق بعد raw) |
| Sensitivity | low · medium · high |
| Current stage | inbox |
| Reviewed | no |
| Cleaned | no |
| Mapped | no |
| Ready for import | no |
| Imported | no |
| Import batch | — |
| Verification status | pending |
| Notes | |
```

---

## السجلّ

| Dataset ID | الوصف | Category | Stage | Sensitivity | Import batch | Verification |
|---|---|---|---|---|---|---|
| _(لا مجموعات بعد)_ | | | | | | |

---

## البطاقات

_(تُضاف بطاقةٌ لكلّ مجموعة من القالب أعلاه، بترتيب المعرِّف)_
