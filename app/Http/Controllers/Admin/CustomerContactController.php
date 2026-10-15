<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Support\CustomerContacts;
use App\Support\Demo;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * أشخاصُ اتصال الجهة — من صفحتها.
 *
 * والقاعدةُ في `CustomerContacts`: أساسيٌّ واحد تحت قفل، وحذفٌ آمن، ومعطَّلٌ
 * يبقى في مستنداته. وهنا يُسأل عن الشركة ويُردّ الجواب.
 */
class CustomerContactController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    private function customer(int|string $id): Customer
    {
        return Customer::where('business_id', $this->bid())->whereKey($id)->firstOrFail();
    }

    private function contact(Customer $customer, int|string $contactId): CustomerContact
    {
        return CustomerContact::where('business_id', $this->bid())->where('customer_id', $customer->id)->whereKey($contactId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }

    private function names(): array
    {
        return ['name' => __('الاسم'), 'job_title' => __('المسمى الوظيفي'), 'email' => __('البريد الإلكتروني'), 'phone' => __('الهاتف')];
    }

    public function store(Request $request, int|string $id)
    {
        $customer = $this->customer($id);
        $data = $request->validate($this->rules(), [], $this->names());

        return $this->attempt(fn () => CustomerContacts::save($customer, $data, auth()->user()), __('أُضيف شخص الاتصال'));
    }

    public function update(Request $request, int|string $id, int|string $contactId)
    {
        $customer = $this->customer($id);
        $contact = $this->contact($customer, $contactId);
        $data = $request->validate($this->rules(), [], $this->names());

        return $this->attempt(fn () => CustomerContacts::save($customer, $data, auth()->user(), $contact), __('حُفظ شخص الاتصال'));
    }

    public function primary(int|string $id, int|string $contactId)
    {
        $contact = $this->contact($this->customer($id), $contactId);

        return $this->attempt(fn () => CustomerContacts::setPrimary($contact, auth()->user()), __('صار شخص الاتصال الأساسي'));
    }

    public function active(Request $request, int|string $id, int|string $contactId)
    {
        $contact = $this->contact($this->customer($id), $contactId);
        $active = $request->boolean('active');

        return $this->attempt(fn () => CustomerContacts::setActive($contact, $active, auth()->user()),
            $active ? __('أُعيد تفعيل شخص الاتصال') : __('عُطّل شخص الاتصال'));
    }

    public function destroy(int|string $id, int|string $contactId)
    {
        $contact = $this->contact($this->customer($id), $contactId);

        return $this->attempt(fn () => CustomerContacts::delete($contact, auth()->user()), __('حُذف شخص الاتصال'));
    }

    private function attempt(callable $fn, string $message)
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withErrors(['contact' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => $message, 'type' => 'success']);
    }
}
