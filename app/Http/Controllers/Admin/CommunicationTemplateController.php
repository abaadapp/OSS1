<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Communication;
use App\Models\CommunicationTemplate;
use App\Support\Communication\Messages;
use App\Support\Communication\Templates;
use App\Support\Demo;
use App\Support\Messaging\Notifiers;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * قوالبُ الرسائل — الشاشة.
 *
 * الكتالوجُ في الكود وما قرّرته الشركةُ فوقه في القاعدة — انظر
 * `Communication\Templates`. والشاشةُ تعرض الاثنين معًا وتكتب الثاني وحدَه.
 */
class CommunicationTemplateController extends Controller
{
    private function bid(): int
    {
        return (int) (auth()->user()->business_id ?? Demo::bid());
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Communications/Templates', [
            'templates' => Templates::all($this->bid()),
            'channels' => [
                'email' => Notifiers::email()->configured(),
                'whatsapp' => Notifiers::whatsapp()->configured(),
            ],
            'statuses' => CommunicationTemplate::STATUSES,
            'sample' => Templates::sample(),
            'may' => ['manage' => (bool) auth()->user()?->may(Permissions::COMMUNICATION_TEMPLATES_MANAGE)],
        ]);
    }

    public function update(Request $request)
    {
        abort_if(! auth()->user()?->may(Permissions::COMMUNICATION_TEMPLATES_MANAGE), 403);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'channel' => ['required', Rule::in(Communication::CHANNELS)],
            'language' => ['required', Rule::in(CommunicationTemplate::LANGUAGES)],
            'name' => ['nullable', 'string', 'max:120'],
            'status' => ['required', Rule::in(CommunicationTemplate::STATUSES)],
            'provider_template' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9_]+$/'],
            'provider_language' => ['nullable', 'string', 'max:10', 'regex:/^[a-zA-Z_]+$/'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:4000'],
        ], [
            'provider_template.regex' => __('اسمُ قالب Meta حروفٌ لاتينيّة صغيرة وأرقام وشرطات سفليّة فقط.'),
        ], ['provider_template' => __('اسم القالب عند المزوّد'), 'body' => __('نص القالب')]);

        try {
            $template = Templates::save($this->bid(), $data['key'], $data['channel'], $data['language'], $data, auth()->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['template' => $e->getMessage()]);
        }

        return back()->with('toast', ['msg' => __('حُفظ القالب «:name»', ['name' => $template->name]), 'type' => 'success']);
    }

    /** معاينةٌ بقيمٍ نموذجيّة — كما سيقرؤها العميل */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'channel' => ['required', Rule::in(Communication::CHANNELS)],
            'language' => ['required', Rule::in(CommunicationTemplate::LANGUAGES)],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*' => ['string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:4000'],
        ]);

        try {
            $template = Templates::resolve($this->bid(), $data['key'], $data['channel'], $data['language']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        /* وما في الشاشة الآن يُعايَن قبل أن يُحفظ */
        $template->fill(array_filter([
            'variables' => $data['variables'] ?? null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []));

        return response()->json(Messages::preview($template, null, $data['language']));
    }
}
