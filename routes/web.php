<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\AssetController;
use App\Http\Controllers\Admin\AutomationController;
use App\Http\Controllers\Admin\BudgetController;
use App\Http\Controllers\Admin\BankStatementController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CatalogQuickAddController;
use App\Http\Controllers\Admin\ChangeOrderController;
use App\Http\Controllers\Admin\ChequeController;
use App\Http\Controllers\Admin\CommandCenterController;
use App\Http\Controllers\Admin\CommunicationController;
use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Admin\CommunicationTemplateController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomAlertController;
use App\Http\Controllers\Admin\CustomerContactController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DeliveryPackageController;
use App\Http\Controllers\Admin\CustomerImportExportController;
use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Http\Controllers\Admin\DataImportController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\DocumentPrintController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EventOperationController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ExpenseTypeController;
use App\Http\Controllers\Admin\Finance\BankAccountController;
use App\Http\Controllers\Admin\Finance\ChartController;
use App\Http\Controllers\Admin\Finance\FixedAssetController;
use App\Http\Controllers\Admin\Finance\JournalController;
use App\Http\Controllers\Admin\Finance\OverviewController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\FinancialAttachmentController;
use App\Http\Controllers\Admin\ForecastController;
use App\Http\Controllers\Admin\GoalController;
use App\Http\Controllers\Admin\Inventory\GoodsReceiptNoteController;
use App\Http\Controllers\Admin\Inventory\StockAdjustmentController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\JobTitleController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\ManagementController;
use App\Http\Controllers\Admin\ManagementReviewController;
use App\Http\Controllers\Admin\OrganizerController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\Payroll\PayrollPaymentController;
use App\Http\Controllers\Admin\Payroll\PayrollRunController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProjectBillingController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\ProjectWorkflowController;
use App\Http\Controllers\Admin\ProductImportExportController;
use App\Http\Controllers\Admin\ProductPackageController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\QuotationController;
use App\Http\Controllers\Admin\ScopeOfWorkController;
use App\Http\Controllers\Admin\Purchasing\PurchaseRegisterController;
use App\Http\Controllers\Admin\Purchasing\SupplierInvoiceController;
use App\Http\Controllers\Admin\ReceivablesController;
use App\Http\Controllers\Admin\RecoveryEmailController;
use App\Http\Controllers\Admin\ReportDownloadController;
use App\Http\Controllers\Admin\ReportExportController;
use App\Http\Controllers\Admin\ReportFeedController;
use App\Http\Controllers\Admin\ReportPageController;
use App\Http\Controllers\Admin\SetupController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\SupplierExportController;
use App\Http\Controllers\Admin\TaskController;
use App\Http\Controllers\Admin\TemplateController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\WasteAnalyticsController;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EventTaskLinkController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicDeliveryController;
use App\Http\Controllers\PublicDocumentController;
use App\Http\Controllers\Webhooks\PostmarkWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Http\Controllers\SearchController;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مسارات Event Plus OSS
|--------------------------------------------------------------------------
| صفحات العرض تُقدّم عبر Route::view وتقرأ بياناتها الحقيقية من قاعدة البيانات
| عبر App\Support\Demo (حسب المستأجر). أفعال الحفظ/التعديل/الحذف عبر Controllers.
*/

/*
 * كل {id} في هذا الملف مفتاحٌ أساسيٌّ صحيح. بلا هذا القيد يصل نصٌّ مثل
 * "HOLD-JAR-1" إلى findOrFail، فيبتلعه SQLite صامتًا (يحوّله إلى 0 فيرجع 404)
 * بينما PostgreSQL يرمي 22P02 فيصير 500 — أي أن عنوانًا معطوبًا واحدًا يُظهر
 * صفحة خطأ للتاجر في الإنتاج بدل «غير موجود». القيد يجعل السلوك واحدًا على
 * المحرّكين: المسار لا يُطابَق أصلًا، فيرجع 404 كما ينبغي.
 * ونتيجةً جانبية: /products/export ونظائرها لم تعد تُبتلع كمعرّفات.
 */
Route::pattern('id', '[0-9]+');
Route::pattern('addressId', '[0-9]+');

/* ----------------------------- المصادقة ----------------------------- */
Route::get('/', [LoginController::class, 'showLogin'])->name('login');
Route::get('/login', [LoginController::class, 'showLogin'])->name('login.form');
Route::post('/login', [LoginController::class, 'attempt'])->name('login.attempt');

/*
 * فتحُ نشاطٍ جديد — تسجيلٌ ذاتيّ.
 *
 * و`guest` عليهما: من دخل بحسابه لا يفتح معالجَ تسجيلٍ ثانٍ فيخرج بنشاطٍ
 * ثانٍ لا يقصده. والخنقُ في المتحكّم لا هنا: مفتاحُه العنوانُ وحده، وهو
 * ما لا يبلغه `throttle` بصيغته الافتراضيّة على الجلسة.
 *
 * والبابُ يُقفل من الإعدادات (`self_signup = 0`) فيردّ 404 —
 * انظر Support\Signup::open.
 */
Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.store');
});

/*
 * نقطة صحّة لمراقبٍ خارجي — بلا مصادقة.
 *
 * من يراقب لا يملك حسابًا، والعطب الذي نريد اكتشافه يمنع الدخول أصلًا. وهي
 * تفحص القاعدة والتخزين والذاكرة وتردّ 503 إن سقط واحد: صفحةٌ تعيد 200 لأن
 * nginx حيّ لا تقول شيئًا عن قاعدةٍ ساقطة، والمتجر بلا قاعدة متجرٌ ساقط.
 */
Route::get('/health', HealthController::class)->name('health');

/*
 * استعادة كلمة المرور — الباب الذي لا يمرّ بالدعم.
 *
 * الرمز في المسار لا في الاستعلام: الرابط يُنسخ من الرسالة كاملًا، وحصرُه
 * بستّين محرفًا يمنع أن يبتلع مسارًا آخر.
 */
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'send'])->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])
    ->where('token', '[A-Za-z0-9]{32,128}')->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'store'])->name('password.update');

/*
 * استعادة الحساب برمزٍ إلى بريدٍ موثّق — الطريق الأحدث.
 *
 * وتبقى مسارات الرابط أعلاه: من في صندوقه رسالةٌ قديمة يجد رابطها يعمل.
 * والباب الجديد لا يقبل عنوانًا يكتبه الطالب — انظر AccountRecoveryController.
 */
Route::post('/recovery/start', [AccountRecoveryController::class, 'start'])->name('recovery.start');
Route::get('/recovery/verify/{challenge}', [AccountRecoveryController::class, 'verify'])
    ->where('challenge', '[A-Za-z0-9]{32,64}')->name('recovery.verify');
Route::post('/recovery/verify', [AccountRecoveryController::class, 'check'])->name('recovery.check');
Route::post('/recovery/resend', [AccountRecoveryController::class, 'resend'])->name('recovery.resend');
Route::get('/recovery/password/{challenge}', [AccountRecoveryController::class, 'password'])
    ->where('challenge', '[A-Za-z0-9]{32,64}')->name('recovery.password');
Route::post('/recovery/password', [AccountRecoveryController::class, 'store'])->name('recovery.password.store');
/*
 * الدخول التجريبي: يمنح جلسة كاملة بلا كلمة مرور، فلا يُسجَّل إلا حيث يُسمح به صراحةً.
 * تركُه مفتوحًا يعني أن أي زائر مجهول يصير مدير منصة بطلب GET واحد.
 * يُفعَّل محليًا افتراضيًا، ولا يُسجَّل في الإنتاج مهما كانت قيمة المتغيّر.
 */
if (config('app.demo_login')) {
    Route::get('/demo-login/{role}', [LoginController::class, 'demo'])->name('demo.login');
}

// تبديل لغة شاشة الدخول — متاح للزائر، ويكتب في الجلسة فقط
Route::post('/language', [LanguageController::class, 'guest'])->name('language.guest');

Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');

/* ------------------------- الملف الشخصي (كل الأدوار) ------------------------- */
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

/* ------------------------------- Admin ----------------------------- */
Route::prefix('admin')->name('admin.')->middleware(['auth', 'tenant', 'business', 'panel', 'ability', 'branch'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'admin'])->name('dashboard');

    /*
     * تهيئةُ المتجر أوّلَ مرّة — اسمٌ يُطبع على الورق قبل أن يُطبع.
     *
     * ومن لم يُقرَّ هويّتُه تُحوَّل لوحتُه إليها، ويصله إليها الشريطُ في أعلى
     * كلّ شاشة. وبعد الإقرار تُعدَّل البيانات من «الإعدادات ‹ بيانات النشاط» —
     * فهذه شاشةُ أوّل مرّة لا شاشةٌ ثانيةٌ تكتب ما تكتبه الإعدادات.
     */
    Route::get('/setup', [SetupController::class, 'index'])->name('setup.index');
    Route::post('/setup/confirm', [SetupController::class, 'confirm'])->name('setup.confirm');
    Route::get('/dashboard/stats', [DashboardController::class, 'adminStats'])->name('dashboard.stats');

    // الفروع
    Route::get('/branches', [PageController::class, 'branchesIndex'])->name('branches.index');
    Route::get('/branch/{branch}/switch', [BranchController::class, 'switch'])->name('branch.switch');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');

    // أجهزة نقطة البيع — تسقط على صلاحية الإعدادات (انظر Permissions::ALIASES)

    // الأجهزة الملحقة بكل صندوق: طابعة، ماسح، درج… — تحت صلاحية الإعدادات
    // نفسها: من يبدّل طابعة صندوق يوجّه إيصالات فرعٍ إلى ورق فرعٍ آخر

    Route::delete('/branches/{id}', [BranchController::class, 'destroy'])->name('branches.destroy');
    /*
     * التراجع تحت صلاحية القسم الذي حُذف منه، لا تحت «الإعدادات».
     *
     * وضعُه تحت الإعدادات كان يجعل زرّ «تراجع» في الإشعار عديم النفع لمن
     * ضغط الحذف: من يملك حذف الفروع ولا يملك الإعدادات يرى الزرّ ويُردّ ٤٠٣.
     * ومن يُؤذن له بالحذف يُؤذن له بردّه — الردّ أقلّ خطرًا من الحذف نفسه.
     */
    Route::post('/branches/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'branch')->name('branches.restore');

    // البحث الموحّد
    Route::get('/search', [SearchController::class, 'admin'])->name('search');

    // المنتجات
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [PageController::class, 'productsCreate'])->name('products.create');
    // يجب أن يسبق products/{id} وإلا التقطه كمعرّف
    Route::get('/products/xlsx', [ReportExportController::class, 'productsXlsx'])->name('products.xlsx');
    Route::get('/products/export-pdf', [PdfController::class, 'productsReport'])->name('products.exportPdf');
    // تغذية كميات للوحة — بإجمالي الشركة كما تعرضه جداولها، لا برصيد فرع
    Route::get('/products/stock-feed', [ProductController::class, 'stockFeed'])->name('products.stockFeed');
    // تصدير/استيراد المنتجات — بيانات قابلة للدوران، لنقل التاجر من نظامه السابق
    Route::get('/products/export/xlsx', [ProductImportExportController::class, 'exportXlsx'])->name('products.export.xlsx');
    Route::get('/products/export/pdf', [ProductImportExportController::class, 'exportPdf'])->name('products.export.pdf');
    Route::post('/products/import', [ProductImportExportController::class, 'upload'])->name('products.import.upload');
    Route::get('/products/import/preview', [ProductImportExportController::class, 'preview'])->name('products.import.preview');
    Route::post('/products/import/remap', [ProductImportExportController::class, 'remap'])->name('products.import.remap');
    Route::post('/products/import/undo', [ProductImportExportController::class, 'undo'])->name('products.import.undo');
    Route::post('/products/import/confirm', [ProductImportExportController::class, 'confirm'])->name('products.import.confirm');
    Route::post('/products/import/cancel', [ProductImportExportController::class, 'cancel'])->name('products.import.cancel');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    // الإجراء الجماعي قبل نمط المعرّف حتى لا يبتلعه
    Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
    Route::get('/products/{id}', [PageController::class, 'productsShow'])->name('products.show');
    Route::post('/products/{id}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
    // تعطيلُ صنفٍ وتفعيلُه — البديلُ الذي يقصده أكثرُ من يضغط «حذف»
    Route::post('/products/{id}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
    Route::patch('/products/{id}/quick', [ProductController::class, 'quickUpdate'])->name('products.quick');
    Route::get('/products/{id}/edit', [PageController::class, 'productsEdit'])->name('products.edit');
    Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

    /*
     * وصورُ المنتج وتركيبُه (المقاسات والوصفة والإضافات) خرجت من تجربة المنتج
     * بأبوابها: بقايا متجر التجزئة لا كتالوجُ معارضَ وفعاليّات. وصفوفُها
     * وملفّاتُها القديمة باقيةٌ لا تُمحى — تاريخٌ تحمله بنودُ الطلبات
     * القديمة (`order_item_addons`)، لا شاشة.
     *
     * وقسمٌ يُنشأ من جانب حقله في نموذج المنتج — تحت `/products/` كي يقيسه
     * الحارس بصلاحية «المنتجات» بلا صلاحيةٍ جديدة.
     */
    Route::post('/products/categories', [CatalogQuickAddController::class, 'storeCategory'])->name('products.categories.store');
    /*
     * بنودُ الباقة — ما يشمله هذا العرض، ويفكّه عرضُ السعر إلى بنوده.
     * وتُدار من شاشة المنتج نفسِها بصلاحية «المنتجات».
     */
    Route::post('/products/{id}/package', [ProductPackageController::class, 'storePackageItem'])->name('products.package.store');
    Route::put('/products/{id}/package/{item}', [ProductPackageController::class, 'updatePackageItem'])->name('products.package.update');
    Route::delete('/products/{id}/package/{item}', [ProductPackageController::class, 'destroyPackageItem'])->name('products.package.destroy');
    Route::post('/products/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'product')->name('products.restore');
    // المحو النهائي يتبع صلاحية الحذف نفسها: من أخفاه يمحوه، ولا أحد سواه
    Route::delete('/products/{id}/purge', [TrashController::class, 'purge'])
        ->defaults('type', 'product')->name('products.purge');

    // التصنيفات

    // الإضافات

    /*
     * «المبيعات» — الطلباتُ القديمة بقائمتها وصفحتها وتصحيحها وأوراقها
     * وتصديرها — خرجت من النظام بأبوابها: لا مسارَ يُفتح باليد فيعيد الشاشة.
     * وصفوفُها باقيةٌ في جداولها تاريخًا ماليًّا (قيودٌ وضريبةٌ وحركةُ مخزون)
     * لا تُمحى — انظر `Order`.
     */

    // العملات وأسعار الصرف
    Route::get('/currency/{code}/switch', [CurrencyController::class, 'switch'])->name('currency.switch');

    // العملاء
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    /* الشركاتُ والجهات — العملاءُ الاعتباريّون في تبويبٍ وحدَهم، والأفرادُ في «العملاء» */
    Route::get('/customers/organizations', [CustomerController::class, 'organizations'])->name('customers.organizations');
    // تصدير/استيراد العملاء (Excel/PDF + معاينة قبل التأكيد)
    Route::get('/customers/export/xlsx', [CustomerImportExportController::class, 'exportXlsx'])->name('customers.export.xlsx');
    Route::get('/customers/export/pdf', [CustomerImportExportController::class, 'exportPdf'])->name('customers.export.pdf');
    Route::post('/customers/import', [CustomerImportExportController::class, 'upload'])->name('customers.import.upload');
    Route::get('/customers/import/preview', [CustomerImportExportController::class, 'preview'])->name('customers.import.preview');
    Route::post('/customers/import/confirm', [CustomerImportExportController::class, 'confirm'])->name('customers.import.confirm');
    Route::post('/customers/import/cancel', [CustomerImportExportController::class, 'cancel'])->name('customers.import.cancel');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::post('/customers/{id}/note', [CustomerController::class, 'saveNote'])->name('customers.note');
    Route::put('/customers/{id}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    Route::post('/customers/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'customer')->name('customers.restore');
    Route::delete('/customers/{id}/purge', [TrashController::class, 'purge'])
        ->defaults('type', 'customer')->name('customers.purge');
    Route::post('/customers/{id}/addresses', [CustomerController::class, 'saveAddress'])->name('customers.addresses.save');
    Route::post('/customers/{id}/addresses/{addressId}/default', [CustomerController::class, 'defaultAddress'])->name('customers.addresses.default');
    Route::delete('/customers/{id}/addresses/{addressId}', [CustomerController::class, 'deleteAddress'])->name('customers.addresses.delete');
    /*
     * و«كشف حساب» العميل من الذمم (`admin.finance.customerStatement`) — فواتيرُه
     * وتحصيلُه ورصيدُه. والكشفُ الذي كان هنا يُطبع من الطلبات القديمة وحدها،
     * فخرج معها.
     */
    /* أشخاصُ اتصال الجهة — صفٌّ لكلّ شخص وأساسيٌّ واحد (المرحلة 14) */
    Route::post('/customers/{id}/contacts', [CustomerContactController::class, 'store'])->whereNumber('id')->name('customers.contacts.store');
    Route::put('/customers/{id}/contacts/{contactId}', [CustomerContactController::class, 'update'])->whereNumber('id')->whereNumber('contactId')->name('customers.contacts.update');
    Route::post('/customers/{id}/contacts/{contactId}/primary', [CustomerContactController::class, 'primary'])->whereNumber('id')->whereNumber('contactId')->name('customers.contacts.primary');
    Route::post('/customers/{id}/contacts/{contactId}/active', [CustomerContactController::class, 'active'])->whereNumber('id')->whereNumber('contactId')->name('customers.contacts.active');
    Route::delete('/customers/{id}/contacts/{contactId}', [CustomerContactController::class, 'destroy'])->whereNumber('id')->whereNumber('contactId')->name('customers.contacts.destroy');
    Route::get('/customers/{id}', [PageController::class, 'customersShow'])->name('customers.show');

    // الموظفون
    Route::get('/employees', [PageController::class, 'employeesIndex'])->name('employees.index');
    Route::get('/employees/create', [PageController::class, 'employeesCreate'])->name('employees.create');

    /*
     * الرواتب — مسيرةٌ تُعتمد ثمّ تُصرف.
     *
     * الاعتماد يقيّد المستحقّ والصرف يُخرج المال، وهما قيدان لا واحد: راتبُ
     * شهرٍ اعتُمد ولم يُصرف التزامٌ قائم، ودمجُهما يُخفيه حتى يخرج المال
     * فيُقرأ الشهر ربحًا وهو مدين برواتبه.
     */
    Route::get('/payroll', [PayrollRunController::class, 'index'])->name('payroll.index');
    Route::post('/payroll', [PayrollRunController::class, 'store'])->name('payroll.store');
    Route::post('/payroll/{id}/approve', [PayrollRunController::class, 'approve'])->name('payroll.approve');
    Route::delete('/payroll/{id}', [PayrollRunController::class, 'destroy'])->name('payroll.destroy');
    Route::put('/payroll/lines/{id}', [PayrollRunController::class, 'updateLine'])->name('payroll.lines.update');
    Route::delete('/payroll/lines/{id}', [PayrollRunController::class, 'destroyLine'])->name('payroll.lines.destroy');
    Route::get('/payroll/payments', [PayrollPaymentController::class, 'index'])->name('payroll.payments');
    Route::post('/payroll/{id}/pay', [PayrollPaymentController::class, 'pay'])->name('payroll.pay');

    // الوظائف
    Route::post('/job-titles', [JobTitleController::class, 'store'])->name('jobTitles.store');
    Route::put('/job-titles/{id}', [JobTitleController::class, 'update'])->name('jobTitles.update');
    Route::delete('/job-titles/{id}', [JobTitleController::class, 'destroy'])->name('jobTitles.destroy');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{id}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{id}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::post('/employees/{id}/toggle', [EmployeeController::class, 'toggleStatus'])->name('employees.toggle');
    Route::post('/employees/{id}/reset-password', [EmployeeController::class, 'resetPassword'])->name('employees.resetPassword');
    Route::get('/employees/{id}', [PageController::class, 'employeesShow'])->name('employees.show');

    // المخزون
    // نظرة عامة على المخزون — لا تسبق inventory.index في المطابقة لأن مسارها أخصّ
    Route::get('/inventory', [PageController::class, 'inventoryIndex'])->name('inventory.index');
    Route::get('/inventory/xlsx', [ReportExportController::class, 'inventoryXlsx'])->name('inventory.xlsx');
    Route::get('/inventory/export-pdf', [PdfController::class, 'inventoryReport'])->name('inventory.exportPdf');
    /*
     * «عمليات جرد المخزون» و«سجل المخزون» و«إشعار استلام بضاعة» خرجت من الواجهة:
     * قسمُ المخزون لرصيد المنتجات وحده. وعناوينُها القديمة تعود إليه بدل أن تنكسر.
     *
     * وما تحتها باقٍ لا يُمحى: صفوفُ الجرد والتسويات وسنداتُ الاستلام وقيودُها،
     * وبابا الكتابة اليدويّة (`stocktake.apply` · `adjustments.store`) بلا شاشة —
     * فيهما وحدَهما منطقُ قيد الفاقد والزيادة في الدفترين (`StockLosses`)، وحذفُه
     * قرارٌ مستقلّ. والاستلامُ يُعتمد من صفحة سنده (`receipts.show`) كما كان.
     */
    Route::get('/inventory/stocktake', fn () => redirect()->route('admin.inventory.index'))->name('inventory.stocktake');
    Route::post('/inventory/stocktake', [InventoryController::class, 'applyStocktake'])->name('inventory.stocktake.apply');
    // التحويل بين الفروع — حركة واحدة بدل «صرف» ثم «إضافة»

    /*
     * التسوية تُنقص المخزون وتُقيّد الخسارة معًا: الاكتفاء بتنقيص العدد يُبقي
     * قيمة المخزون في الميزانية كما كانت. (بلا شاشة — انظر أعلاه.)
     */
    Route::get('/inventory/adjustments', fn () => redirect()->route('admin.inventory.index'))->name('inventory.adjustments');
    Route::post('/inventory/adjustments', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
    /*
     * سنداتُ الاستلام — لا تُكتب بيدٍ ولا تُحذف: تُنشئها لحظةُ استلام أمر
     * الشراء، وتُعتمد من صفحة السند الواحد فتدخل البضاعةُ الرفّ. وقائمتُها
     * خرجت من الواجهة: ما ينتظر الاعتماد يُعرض في «المخزون» وفي الجرس.
     */
    Route::get('/inventory/receipts', fn () => redirect()->route('admin.inventory.index'))->name('inventory.receipts');
    Route::get('/inventory/receipts/{id}/pdf', [DocumentPrintController::class, 'grn'])->name('inventory.receipts.pdf');
    /*
     * مرفقاتُ المستندات المالية — على القرص الخاصّ، وتُقرأ ببابٍ يسأل.
     *
     * والقديمةُ على `public`: رابطُها يُفتح بلا تسجيل دخول. وورقةُ مورّدٍ
     * فيها أسعارُ شرائك — أثمنُ ما في متجرك عند منافسك.
     */
    Route::get('/inventory/receipts/{id}/attachment', [FinancialAttachmentController::class, 'receipt'])->name('inventory.receipts.attachment');
    /*
     * صفحةُ السند الواحد — بعد `/{id}/pdf` و`/{id}/attachment` لا قبلهما.
     *
     * المُطابِقُ يأخذ أوّلَ ما ينطبق، و`/{id}` يبتلع المقطعَ الواحد وحده
     * فلا ينازع البابين. والقيدُ الرقميّ إعلانُ نيّةٍ كأخيه في المشتريات.
     */
    Route::get('/inventory/receipts/{id}', [GoodsReceiptNoteController::class, 'show'])
        ->whereNumber('id')->name('inventory.receipts.show');
    Route::post('/inventory/movements', [InventoryController::class, 'store'])->name('inventory.store');

    // المورّدون
    Route::get('/suppliers', [PageController::class, 'suppliersIndex'])->name('suppliers.index');
    // التصدير قبل {id}: لو جاء بعده لابتلع «export» مسارَ المورّد الواحد
    Route::get('/suppliers/export/xlsx', [SupplierExportController::class, 'xlsx'])->name('suppliers.export.xlsx');
    Route::get('/suppliers/export/pdf', [SupplierExportController::class, 'pdf'])->name('suppliers.export.pdf');
    Route::post('/suppliers/import', [SupplierExportController::class, 'upload'])->name('suppliers.import.upload');
    Route::get('/suppliers/import/preview', [SupplierExportController::class, 'preview'])->name('suppliers.import.preview');
    Route::post('/suppliers/import/confirm', [SupplierExportController::class, 'confirm'])->name('suppliers.import.confirm');
    Route::post('/suppliers/import/cancel', [SupplierExportController::class, 'cancel'])->name('suppliers.import.cancel');
    Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    /*
     * وكتالوجُ خدمات الموردين لم يعد له باب: ما يقدّمه المورّد نصٌّ يُكتب في
     * نموذجه (`suppliers.service`). وجداولُ الكتالوج باقيةٌ بصفوفها — انظر `Supplier`.
     */
    Route::get('/suppliers/{id}', [SupplierController::class, 'show'])->whereNumber('id')->name('suppliers.show');
    Route::put('/suppliers/{id}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

    /*
     * المنظّمون الميدانيّون — المرحلة 14. القسمُ `organizers` يقرأ السجلّ،
     * والكتابةُ بـ`organizers.manage`. والتصنيفاتُ قبل {id}.
     */
    Route::get('/organizers', [OrganizerController::class, 'index'])->name('organizers.index');
    Route::post('/organizers', [OrganizerController::class, 'store'])->name('organizers.store');
    Route::post('/organizers/categories', [OrganizerController::class, 'storeCategory'])->name('organizers.categories.store');
    Route::put('/organizers/categories/{id}', [OrganizerController::class, 'updateCategory'])->whereNumber('id')->name('organizers.categories.update');
    Route::delete('/organizers/categories/{id}', [OrganizerController::class, 'destroyCategory'])->whereNumber('id')->name('organizers.categories.destroy');
    Route::get('/organizers/{id}', [OrganizerController::class, 'show'])->whereNumber('id')->name('organizers.show');
    Route::put('/organizers/{id}', [OrganizerController::class, 'update'])->whereNumber('id')->name('organizers.update');
    Route::post('/organizers/{id}/status', [OrganizerController::class, 'status'])->whereNumber('id')->name('organizers.status');
    Route::delete('/organizers/{id}', [OrganizerController::class, 'destroy'])->whereNumber('id')->name('organizers.destroy');

    /*
     * مستنداتُ الشركة الداخليّة وانتهاؤها. القسمُ `documents` يقرأ ويفتح الملفّات،
     * والكتابةُ بـ`documents.manage`. والتصنيفاتُ قبل {id}، والتعديلُ POST لأنّه يحمل ملفًّا.
     */
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::post('/documents/categories', [DocumentController::class, 'storeCategory'])->name('documents.categories.store');
    Route::put('/documents/categories/{id}', [DocumentController::class, 'updateCategory'])->whereNumber('id')->name('documents.categories.update');
    Route::delete('/documents/categories/{id}', [DocumentController::class, 'destroyCategory'])->whereNumber('id')->name('documents.categories.destroy');
    Route::get('/documents/{id}', [DocumentController::class, 'show'])->whereNumber('id')->name('documents.show');
    Route::post('/documents/{id}', [DocumentController::class, 'update'])->whereNumber('id')->name('documents.update');
    Route::post('/documents/{id}/archive', [DocumentController::class, 'archive'])->whereNumber('id')->name('documents.archive');
    Route::post('/documents/{id}/restore', [DocumentController::class, 'restore'])->whereNumber('id')->name('documents.restore');
    Route::get('/documents/{id}/file', [DocumentController::class, 'file'])->whereNumber('id')->name('documents.file');
    Route::get('/documents/{id}/files/{fid}', [DocumentController::class, 'version'])->whereNumber('id')->whereNumber('fid')->name('documents.version');

    /*
     * المشتريات — ثلاث شاشات على بابين.
     *
     * القائمة تجمع ما اشتُري من البابين معًا (أمرٌ استُلم، وسندٌ بلا أمر)،
     * والسندات تحمل ما على المتجر لمورّديه، والأوامر تحمل ما طُلب ولم يصل.
     */
    Route::get('/purchases', [PurchaseRegisterController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/invoices', [SupplierInvoiceController::class, 'index'])->name('purchases.invoices');
    Route::post('/purchases/invoices', [SupplierInvoiceController::class, 'store'])->name('purchases.invoices.store');
    /*
     * اعتمادُ السند ورفضُه — وهما بابُ الذمّة لا بابُ السداد.
     *
     * والسدادُ بعدهما: مالٌ يخرج مقابل دَينٍ نشأ، لا مقابل ورقةٍ وصلت.
     */
    Route::get('/purchases/invoices/{id}/attachment', [FinancialAttachmentController::class, 'supplierInvoice'])->name('purchases.invoices.attachment');
    // وإيصالُ كلِّ دفعةٍ على السند — غيرُ مرفقه، والدفعةُ تُسأل عن سندها وشركتها معًا
    Route::get('/purchases/invoices/{id}/payments/{payment}/receipt', [FinancialAttachmentController::class, 'supplierInvoicePayment'])
        ->whereNumber('id')->whereNumber('payment')->name('purchases.invoices.payments.receipt');
    // وورقةُ السند نفسِه — غيرُ مرفق المورّد: ذاك ما وصل، وهذه ما سجّلناه
    Route::get('/purchases/invoices/{id}/pdf', [DocumentPrintController::class, 'supplierInvoice'])->name('purchases.invoices.pdf');
    Route::post('/purchases/invoices/{id}/approve', [SupplierInvoiceController::class, 'approve'])->name('purchases.invoices.approve');
    Route::post('/purchases/invoices/{id}/reject', [SupplierInvoiceController::class, 'reject'])->name('purchases.invoices.reject');
    // والمعتمَدُ يُلغى بعكس قيده لا يُحذف — مستندٌ ماليٌّ صدر يبقى مقروءًا
    Route::post('/purchases/invoices/{id}/cancel', [SupplierInvoiceController::class, 'cancel'])->name('purchases.invoices.cancel');
    Route::post('/purchases/invoices/{id}/pay', [SupplierInvoiceController::class, 'pay'])->name('purchases.invoices.pay');
    Route::delete('/purchases/invoices/{id}', [SupplierInvoiceController::class, 'destroy'])->name('purchases.invoices.destroy');
    /*
     * وصفحةُ السند بعد أفعاله جميعًا — فلا يبتلع `{id}` مسارًا ثابتًا.
     *
     * و`whereNumber` تحرسها: بدونها يلتقط هذا المسارُ كلَّ ما يأتي بعد
     * `/purchases/invoices/` من كلماتٍ لا أرقام.
     */
    Route::get('/purchases/invoices/{id}', [SupplierInvoiceController::class, 'show'])
        ->whereNumber('id')->name('purchases.invoices.show');
    Route::get('/purchases/orders', [PurchaseOrderController::class, 'index'])->name('purchases.orders');
    Route::get('/purchases/create', [PageController::class, 'purchasesCreate'])->name('purchases.create');
    Route::post('/purchases', [PurchaseOrderController::class, 'store'])->name('purchases.store');
    /*
     * معاينةُ أمر الشراء قبل حفظه — تُرسم ولا تكتب.
     *
     * و`POST` لا `GET`: النموذجُ كلُّه يرحل معها — بنودٌ وأسعارُ شراءٍ
     * وملاحظات — وشريطُ عنوانٍ يحمل أسعارَ شرائك يُسجَّل في كلّ سجلّ وصولٍ
     * بينه وبين الخادم.
     */
    Route::post('/purchases/preview', [PurchaseOrderController::class, 'preview'])->name('purchases.preview');
    /*
     * والمورّدُ الافتراضيّ: مقبضٌ في شاشة الأمر وأثرُه إعدادُ متجر — فيُقاس
     * بقسم «الإعدادات» داخل المتحكّم لا بقسم المشتريات.
     */
    Route::post('/purchases/default-supplier', [PurchaseOrderController::class, 'defaultSupplier'])->name('purchases.defaultSupplier');
    // ورفعُ وحدةِ شراءٍ من قائمة المتجر — لا من أوامره التي كُتبت بها
    Route::delete('/purchases/units', [PurchaseOrderController::class, 'hideUnit'])->name('purchases.units.destroy');
    Route::post('/purchases/{id}/receipt', [PurchaseOrderController::class, 'uploadReceipt'])->name('purchases.receipt');
    // وإيصالُ الدفع يُقرأ من هنا — وهو غيرُ ورقة الشحنة عمودًا وبابًا
    Route::get('/purchases/{id}/receipt-file', [FinancialAttachmentController::class, 'purchaseReceipt'])
        ->name('purchases.receiptFile');
    // ومرفقُ الأمر نفسِه: عرضُ سعرٍ أو مستندُ طلب، لا إيصالُ دفع
    Route::get('/purchases/{id}/attachment', [FinancialAttachmentController::class, 'purchaseOrder'])
        ->name('purchases.attachment');
    Route::post('/purchases/{id}/receive', [PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    /*
     * اعتمادُ الاستلام ورفضُه — على الإشعار لا على الأمر.
     *
     * الأمرُ الواحد له أوراقُ استلامٍ عدّة، وكلٌّ تُعتمد وحدَها: دفعةٌ وصلت
     * سليمةً تدخل الرفّ، وأخرى وصلت ناقصةً تُرفض — ولا يُعلَّق الأمرُ كلُّه
     * على أسوئهما.
     */
    Route::post('/purchases/receipts/{id}/approve', [PurchaseOrderController::class, 'approveReceipt'])->name('purchases.receipts.approve');
    Route::post('/purchases/receipts/{id}/reject', [PurchaseOrderController::class, 'rejectReceipt'])->name('purchases.receipts.reject');
    Route::delete('/purchases/{id}', [PurchaseOrderController::class, 'destroy'])->name('purchases.destroy');
    /* أمرٌ استُلم وفُوتر بأقلّ يُقفَل بسببٍ — فيخرج باقيه من ملتزَم المشروع */
    Route::post('/purchases/{id}/close', [PurchaseOrderController::class, 'close'])->name('purchases.close');
    Route::get('/purchases/{id}/pdf', [DocumentPrintController::class, 'purchase'])->name('purchases.pdf');
    /*
     * صفحةُ الأمر الواحد.
     *
     * والقيدُ الرقميّ إعلانُ نيّةٍ لا حارس: جرّبتُ نزعَه فبقي `/purchases/abc`
     * بابًا مغلقًا — `findOrFail` يصبّ المفتاح إلى عدد قبل أن يبلغ القاعدة،
     * على SQLite وPostgreSQL معًا. وجرّبتُ نقلَ السطر فوق `/purchases/orders`
     * فبقيت القائمةُ تُفتح: المُطابِقُ يقدّم الثابتَ على المتغيّر. فلا يُقال
     * عن هذا السطر إنّه يحرس شيئًا — يقول ما يقبله الباب لا غير.
     */
    Route::get('/purchases/{id}', [PurchaseOrderController::class, 'show'])
        ->whereNumber('id')->name('purchases.show');

    // تحليلات الربحية

    // إنشاء متجر التاجر على الإنترنت — من صفحة الإعدادات نفسها

    /*
     * بريد الاستعادة — يضبطه صاحب الحساب وهو داخل، قبل أن يحتاج إليه.
     * ويُشترط معه كلمةُ المرور الحالية: جلسةٌ مفتوحة وحدها لا تكفي.
     */
    Route::post('/settings/recovery-email', [RecoveryEmailController::class, 'start'])->name('settings.recovery.start');
    Route::post('/settings/recovery-email/confirm', [RecoveryEmailController::class, 'confirm'])->name('settings.recovery.confirm');

    /*
     * المالية — خمس شاشات على دفترٍ واحد.
     *
     * الحسابات البنكية والقيود اليومية وشجرة الحسابات والمصاريف الشهرية
     * والأصول الثابتة. وكلّها تكتب من باب `Ledger::post` وحده، فلا يدخل
     * الدفترَ قيدٌ لم يُفحص توازنه.
     */
    Route::get('/finance', [BankAccountController::class, 'index'])->name('finance.index');
    Route::post('/finance/banks', [BankAccountController::class, 'store'])->name('finance.banks.store');
    Route::put('/finance/banks/{id}', [BankAccountController::class, 'update'])->name('finance.banks.update');
    Route::post('/finance/banks/{id}/primary', [BankAccountController::class, 'primary'])->name('finance.banks.primary');
    Route::delete('/finance/banks/{id}', [BankAccountController::class, 'destroy'])->name('finance.banks.destroy');
    // كشف الحساب البنكي والمطابقة — بلا معرّف: الحساب الرئيسيّ
    Route::get('/finance/statement/{id?}', [BankAccountController::class, 'statement'])->name('finance.statement');

    /*
     * عروضُ الأسعار — بابُ الإدخال الأوّل في Event Plus.
     *
     * وقسمُها `quotations` مستقلٌّ عن «المبيعات»: البائعُ يكتب العروضَ ولا
     * يلزم أن يفتح دفترَ الفواتير، والمحاسبُ يفتح الفواتيرَ ولا شأنَ له
     * بعرضٍ لم يُقبل بعد. وهو ما يُشتقّ من اسم المسار — انظر
     * `Permissions::sectionFromRoute`.
     */
    Route::get('/quotations', [QuotationController::class, 'index'])->name('quotations.index');
    /*
     * و«create» تسبق «{id}» — والذي يحميها فعلًا `Route::pattern('id', '[0-9]+')`
     * في أعلى الملفّ. والترتيبُ هنا احتياطٌ ثانٍ لا الحارسَ الأوّل.
     */
    Route::get('/quotations/create', [QuotationController::class, 'create'])->name('quotations.create');
    /*
     * معاينةُ الورقة قبل حفظها — تُرسم ولا تُكتب.
     *
     * و`POST` لا `GET`: النموذجُ كلُّه يرحل معها — بنودٌ وأسعارٌ وشروط —
     * وشريطُ عنوانٍ يحمل عرضَ جهةٍ كاملًا يُسجَّل في كلّ سجلّ وصول.
     */
    Route::post('/quotations/preview', [QuotationController::class, 'preview'])->name('quotations.preview');
    Route::post('/quotations', [QuotationController::class, 'store'])->name('quotations.store');
    Route::get('/quotations/{id}', [QuotationController::class, 'show'])->name('quotations.show');
    Route::put('/quotations/{id}', [QuotationController::class, 'update'])->name('quotations.update');
    Route::post('/quotations/{id}/approve', [QuotationController::class, 'approve'])->name('quotations.approve');
    Route::post('/quotations/{id}/send', [QuotationController::class, 'send'])->name('quotations.send');
    Route::post('/quotations/{id}/revise', [QuotationController::class, 'revise'])->name('quotations.revise');
    Route::post('/quotations/{id}/duplicate', [QuotationController::class, 'duplicate'])->name('quotations.duplicate');
    Route::post('/quotations/{id}/cancel', [QuotationController::class, 'cancel'])->name('quotations.cancel');
    Route::post('/quotations/{id}/decision', [QuotationController::class, 'decision'])->name('quotations.decision');
    /* وإرسالُ الرابط بالبريد إيصالٌ لا تحويلُ حال — انظر `Quotations::email` */
    Route::post('/quotations/{id}/email', [QuotationController::class, 'email'])->name('quotations.email');
    Route::get('/quotations/{id}/pdf', [PdfController::class, 'quotation'])->name('quotations.pdf');

    /*
     * المشاريع — المرحلة 4. القسمُ `projects`، وأوامرُ التغيير تحته
     * (`ALIASES['changeOrders']`). ولا `create` للمشروع: يُنشأ من قبول العرض.
     */
    // المهامّ: مهامُّ المشاريع كلِّها في شاشةٍ واحدة — وتُكتب من مسارات المشروع
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('projects.show');
    Route::put('/projects/{id}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{id}/baseline', [ProjectController::class, 'baseline'])->name('projects.baseline');
    Route::post('/projects/{id}/transition', [ProjectController::class, 'transition'])->name('projects.transition');
    Route::post('/projects/{id}/close', [ProjectController::class, 'close'])->name('projects.close');
    Route::post('/projects/{id}/reopen', [ProjectController::class, 'reopen'])->name('projects.reopen');
    Route::post('/projects/{id}/requirements', [ProjectController::class, 'storeRequirement'])->name('projects.requirements.store');
    Route::put('/projects/{id}/requirements/{rid}', [ProjectController::class, 'updateRequirement'])->name('projects.requirements.update');
    Route::delete('/projects/{id}/requirements/{rid}', [ProjectController::class, 'destroyRequirement'])->name('projects.requirements.destroy');
    Route::post('/projects/{id}/members', [ProjectController::class, 'storeMember'])->name('projects.members.store');
    Route::delete('/projects/{id}/members/{mid}', [ProjectController::class, 'destroyMember'])->name('projects.members.destroy');
    Route::post('/projects/{id}/tasks', [ProjectController::class, 'storeTask'])->name('projects.tasks.store');
    Route::put('/projects/{id}/tasks/{tid}', [ProjectController::class, 'updateTask'])->name('projects.tasks.update');
    Route::delete('/projects/{id}/tasks/{tid}', [ProjectController::class, 'destroyTask'])->name('projects.tasks.destroy');

    /*
     * مسارُ المشروع — نطاقُ العمل وتوقيعُه، والدفعةُ المقدّمة والفاتورةُ
     * النهائيّة، و«تم — الانتقال» و«رجوع». كلُّها تحت `admin.projects.*`
     * فتتبع قسمَ المشاريع، والأفعالُ تُسأل في متحكّماتها.
     */
    Route::get('/projects/{id}/scope', [ScopeOfWorkController::class, 'show'])->name('projects.scope.show');
    Route::post('/projects/{id}/scope', [ScopeOfWorkController::class, 'store'])->name('projects.scope.store');
    Route::put('/projects/{id}/scope/{scope}', [ScopeOfWorkController::class, 'update'])->whereNumber('scope')->name('projects.scope.update');
    Route::post('/projects/{id}/scope/{scope}/ready', [ScopeOfWorkController::class, 'ready'])->whereNumber('scope')->name('projects.scope.ready');
    Route::post('/projects/{id}/scope/{scope}/draft', [ScopeOfWorkController::class, 'draft'])->whereNumber('scope')->name('projects.scope.draft');
    Route::post('/projects/{id}/scope/{scope}/send', [ScopeOfWorkController::class, 'send'])->whereNumber('scope')->name('projects.scope.send');
    Route::get('/projects/{id}/scope/{scope}/preview', [ScopeOfWorkController::class, 'preview'])->whereNumber('scope')->name('projects.scope.preview');
    Route::post('/projects/{id}/workflow/move', [ProjectWorkflowController::class, 'move'])->name('projects.workflow.move');
    Route::get('/projects/{id}/billing/deposit/preview', [ProjectBillingController::class, 'preview'])->name('projects.billing.preview');
    Route::post('/projects/{id}/billing/deposit', [ProjectBillingController::class, 'deposit'])->name('projects.billing.deposit');
    Route::post('/projects/{id}/billing/waive-deposit', [ProjectBillingController::class, 'waive'])->name('projects.billing.waive');
    Route::post('/projects/{id}/billing/final', [ProjectBillingController::class, 'final'])->name('projects.billing.final');
    Route::get('/projects/{id}/change-orders/create', [ChangeOrderController::class, 'create'])->name('changeOrders.create');
    Route::post('/projects/{id}/change-orders', [ChangeOrderController::class, 'store'])->name('changeOrders.store');
    Route::get('/change-orders/{id}', [ChangeOrderController::class, 'show'])->name('changeOrders.show');
    Route::get('/change-orders/{id}/edit', [ChangeOrderController::class, 'edit'])->name('changeOrders.edit');
    Route::put('/change-orders/{id}', [ChangeOrderController::class, 'update'])->name('changeOrders.update');
    Route::post('/change-orders/{id}/approve', [ChangeOrderController::class, 'approve'])->name('changeOrders.approve');
    Route::post('/change-orders/{id}/send', [ChangeOrderController::class, 'send'])->name('changeOrders.send');
    Route::post('/change-orders/{id}/cancel', [ChangeOrderController::class, 'cancel'])->name('changeOrders.cancel');
    Route::post('/change-orders/{id}/decision', [ChangeOrderController::class, 'decision'])->name('changeOrders.decision');
    Route::post('/change-orders/{id}/email', [ChangeOrderController::class, 'email'])->name('changeOrders.email');

    /*
     * فواتيرُ العملاء والذمم.
     *
     * والفواتيرُ في «المبيعات» لأنّها مستندُ بيع، والذممُ في «المالية» لأنّها
     * رصيد. وبابٌ واحدٌ لكلٍّ منهما: تكرارُ الميزة في موضعين يجعل التاجر
     * يعدّل في أحدهما ويقرأ من الآخر.
     */
    Route::get('/customer-invoices', [CustomerInvoiceController::class, 'index'])->name('customerInvoices.index');
    /*
     * و«create» تسبق «{id}» — والذي يحميها فعلًا `Route::pattern('id', '[0-9]+')`
     * في أعلى الملفّ: بلا ذلك القيد يبتلع المعرّفُ كلَّ كلمةٍ تليه، فتُقرأ
     * «create» رقمَ فاتورةٍ ويُردّ ٤٠٤ على بابٍ معروضٍ في الشاشة. والترتيبُ
     * هنا احتياطٌ ثانٍ لا الحارسَ الأوّل.
     */
    Route::get('/customer-invoices/create', [CustomerInvoiceController::class, 'create'])->name('customerInvoices.create');
    /*
     * معاينةُ الورقة قبل حفظها — تُرسم ولا تُكتب.
     *
     * و`POST` لا `GET`: النموذجُ كلُّه يرحل معها — بنودٌ وملاحظاتٌ وتواريخ —
     * وشريطُ عنوانٍ يحمل فاتورةَ جهةٍ كاملة يُسجَّل في كلّ سجلّ وصولٍ بينه
     * وبين الخادم. ولا تُحمى بـ`customerInvoices.create` وحدها: هي في نطاق
     * القسم نفسِه فتقرأ حارسَه.
     */
    Route::post('/customer-invoices/preview', [CustomerInvoiceController::class, 'preview'])->name('customerInvoices.preview');
    Route::post('/customer-invoices/customers', [CustomerInvoiceController::class, 'storeCustomer'])->name('customerInvoices.storeCustomer');
    /*
     * هويّةُ الورقة والعميلُ الافتراضيّ — مقبضان في شاشة الفاتورة وأثرُهما
     * إعدادُ متجر. فيُقاسان بقسم «الإعدادات» داخل المتحكّم لا بقسم المبيعات:
     * من مُنح كتابةَ الفواتير لم يُمنح تبديلَ ما يُطبع على كلّ ورقةٍ قادمة.
     */
    Route::post('/customer-invoices/branding', [CustomerInvoiceController::class, 'branding'])->name('customerInvoices.branding');
    Route::post('/customer-invoices/default-customer', [CustomerInvoiceController::class, 'defaultCustomer'])->name('customerInvoices.defaultCustomer');
    /*
     * إشعاراتُ الدائن — وصفحةٌ لكلٍّ منها.
     *
     * وتسبق `/{id}`: بلا ذلك يُقرأ «credit-notes» رقمَ فاتورة. والذي يحرسها
     * فعلًا `Route::pattern('id', '[0-9]+')` في أعلى الملفّ، والترتيبُ
     * احتياطٌ ثانٍ — كما في «create».
     */
    Route::get('/customer-invoices/credit-notes/{note}', [CustomerInvoiceController::class, 'creditNoteShow'])
        ->whereNumber('note')->name('customerInvoices.creditNotes.show');
    Route::get('/customer-invoices/credit-notes/{note}/pdf', [DocumentPrintController::class, 'creditNote'])
        ->whereNumber('note')->name('customerInvoices.creditNotes.pdf');
    Route::get('/customer-invoices/{id}', [CustomerInvoiceController::class, 'show'])->name('customerInvoices.show');
    Route::post('/customer-invoices', [CustomerInvoiceController::class, 'store'])->name('customerInvoices.store');
    Route::post('/customer-invoices/{id}/issue', [CustomerInvoiceController::class, 'issue'])->name('customerInvoices.issue');
    Route::post('/customer-invoices/{id}/cancel', [CustomerInvoiceController::class, 'cancel'])->name('customerInvoices.cancel');
    Route::post('/customer-invoices/{id}/credit-note', [CustomerInvoiceController::class, 'creditNote'])->name('customerInvoices.creditNote');
    Route::get('/customer-invoices/{id}/pdf', [PdfController::class, 'customerInvoice'])->name('customerInvoices.pdf');
    /*
     * مرفقاتُ الفاتورة — على القرص الخاصّ، وتُقرأ ببابٍ يسأل.
     *
     * والقراءةُ من `FinancialAttachmentController` كأخواتها: حارسٌ واحد
     * يسأل عن المتجر وعن صلاحية فتح المرفقات، لا حارسٌ سادس يفترق يومًا.
     */
    Route::post('/customer-invoices/{id}/attachments', [CustomerInvoiceController::class, 'attach'])
        ->name('customerInvoices.attach');
    Route::get('/customer-invoices/{id}/attachments/{attachment}', [FinancialAttachmentController::class, 'customerInvoice'])
        ->name('customerInvoices.attachment');
    Route::delete('/customer-invoices/{id}/attachments/{attachment}', [CustomerInvoiceController::class, 'detach'])
        ->name('customerInvoices.detach');
    Route::post('/customer-payments', [CustomerInvoiceController::class, 'pay'])->name('customerPayments.store');
    Route::post('/customer-payments/{id}/cancel', [CustomerInvoiceController::class, 'cancelPayment'])->name('customerPayments.cancel');
    /*
     * سندُ القبض — صفحةٌ وورقة.
     *
     * والسندُ كان سطرًا في كلّ فاتورةٍ سُدِّدت منه ولا يُفتح: من دفع بمئةٍ
     * وُزّعت على ثلاثٍ يُقرأ ثلاثَ مرّاتٍ ولا يُقرأ مرّةً كاملًا.
     */
    Route::get('/customer-payments/{id}', [CustomerInvoiceController::class, 'paymentShow'])
        ->whereNumber('id')->name('customerPayments.show');
    Route::get('/customer-payments/{id}/pdf', [DocumentPrintController::class, 'customerReceipt'])
        ->whereNumber('id')->name('customerPayments.pdf');

    /*
     * الشيكات — تحت «المالية» لأنّ من يُقرّ أنّ شيكًا صُرِف يكتب في الدفتر.
     */
    Route::get('/finance/cheques', [ChequeController::class, 'index'])->name('finance.cheques');
    Route::post('/finance/cheques/{id}/clear', [ChequeController::class, 'clear'])->name('finance.cheques.clear');
    Route::post('/finance/cheques/{id}/bounce', [ChequeController::class, 'bounce'])->name('finance.cheques.bounce');

    Route::get('/finance/receivables', [ReceivablesController::class, 'index'])->name('finance.receivables');
    /*
     * و`finance.statement` مأخوذٌ لكشف الحساب البنكيّ — فاسمٌ آخر.
     *
     * اسمان لمسارين يجعل `route()` تُخرج عنوانَ أحدهما للآخر، ولا يُكتشف
     * ذلك إلّا حين يضغط أحدٌ زرًّا فيجد نفسه في شاشةٍ لم يقصدها.
     */
    Route::get('/finance/receivables/{customer}/statement', [ReceivablesController::class, 'statement'])->name('finance.customerStatement');
    Route::get('/finance/receivables/{customer}/statement/pdf', [PdfController::class, 'customerAccountStatement'])->name('finance.customerStatement.pdf');
    /*
     * ═══ وهما تحت «المالية» لأنّ شاشتَهما تحتها ═══
     *
     * كان اسماهما `customers.credit` و`customers.bill`، فيشتقّ الحارسُ منهما
     * قسم «العملاء» — والنموذجُ الذي يرسلهما يعيش في شاشة كشف الحساب، وهي
     * تحت «المالية». فالبابُ والغرفةُ بمفتاحين مختلفين:
     *
     * ١) من مُنح «العملاء» ولا يملك «المالية» لا يفتح الشاشة أصلًا — ويكتب
     *    شروطَ الائتمان بطلبٍ مباشر. والبائع من هؤلاء.
     * ٢) ومن مُنح «المالية» ولا يملك «العملاء» يرى النموذج على شاشته ويُردّ
     *    عند الحفظ بـ٤٠٣ — مقبضٌ مرسومٌ لا يُدير شيئًا.
     *
     * فصار اسمُهما وعنوانُهما تحت المالية: ما يُفتح هناك يُحفظ هناك.
     */
    Route::put('/finance/receivables/{customer}/credit', [ReceivablesController::class, 'credit'])->name('finance.customerCredit');
    // فوترةُ الشهر: ورقةٌ واحدة على طلباتٍ آجلةٍ لم تُفوتَر بعد
    Route::post('/finance/receivables/{customer}/bill', [ReceivablesController::class, 'bill'])->name('finance.customerBill');

    // الحركة المالية — ما دخل وما خرج، وبابُ تسجيل ما لا مستند له
    Route::get('/finance/transactions', [FinanceController::class, 'index'])->name('finance.transactions');

    // الملخّص المالي والمبالغ المستحقة — قراءتان لا تكتبان شيئًا
    Route::get('/finance/summary', [OverviewController::class, 'summary'])->name('finance.summary');
    Route::get('/finance/dues', [OverviewController::class, 'dues'])->name('finance.dues');

    // شجرة الحسابات
    Route::get('/finance/chart', [ChartController::class, 'index'])->name('finance.chart');
    Route::post('/finance/chart', [ChartController::class, 'store'])->name('finance.chart.store');
    Route::put('/finance/chart/{id}', [ChartController::class, 'update'])->name('finance.chart.update');
    Route::post('/finance/chart/{id}/toggle', [ChartController::class, 'toggle'])->name('finance.chart.toggle');
    Route::delete('/finance/chart/{id}', [ChartController::class, 'destroy'])->name('finance.chart.destroy');

    // القيود اليومية
    Route::get('/finance/journal', [JournalController::class, 'index'])->name('finance.journal');
    Route::post('/finance/journal', [JournalController::class, 'store'])->name('finance.journal.store');
    /*
     * وعكسُ قيدٍ يدويّ — البابُ الذي لم يكن.
     *
     * `Ledger::reverse` كانت تُستدعى من إلغاء المستندات وحدَها، والقيدُ الذي
     * يكتبه التاجر بيده لا سبيلَ إلى تصحيحه من أيّ شاشة.
     */
    Route::post('/finance/journal/{id}/reverse', [JournalController::class, 'reverse'])
        ->name('finance.journal.reverse');

    // الأصول الثابتة وإهلاكها
    Route::get('/finance/assets', [FixedAssetController::class, 'index'])->name('finance.assets');
    Route::post('/finance/assets', [FixedAssetController::class, 'store'])->name('finance.assets.store');
    // ورفعُ تصنيفٍ من قائمة المتجر — لا من أصلٍ سُجّل به
    Route::delete('/finance/assets/categories', [FixedAssetController::class, 'hideCategory'])->name('finance.assets.categories.destroy');
    Route::post('/finance/assets/depreciate', [FixedAssetController::class, 'depreciate'])->name('finance.assets.depreciate');
    Route::post('/finance/assets/{id}/dispose', [FixedAssetController::class, 'dispose'])->name('finance.assets.dispose');
    Route::delete('/finance/assets/{id}', [FixedAssetController::class, 'destroy'])->name('finance.assets.destroy');
    // الورديات المُقفلة وفروقها — يقرؤها من يملك «المالية» (sectionFromRoute)
    // تقرير إقفال الوردية (Z) — على ورق الإيصال، يُوقَّع عند تسليم الدرج
    // إقفال وردية نسيها الكاشير — بلا عدّ، وفرقُها يبقى مجهولًا
    Route::post('/bank/import', [BankStatementController::class, 'import'])->name('bank.import');
    Route::post('/bank/rematch', [BankStatementController::class, 'rematch'])->name('bank.rematch');
    Route::delete('/bank/clear', [BankStatementController::class, 'clear'])->name('bank.clear');
    Route::get('/finance/xlsx', [ReportExportController::class, 'financeXlsx'])->name('finance.xlsx');
    Route::get('/finance/pdf', [PdfController::class, 'financeReport'])->name('finance.pdf');
    Route::post('/finance/transactions', [FinanceController::class, 'store'])->name('finance.store');
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/xlsx', [ReportExportController::class, 'expensesXlsx'])->name('expenses.xlsx');
    Route::get('/expenses/export-pdf', [PdfController::class, 'expensesReport'])->name('expenses.exportPdf');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::post('/expenses/{id}/paid', [ExpenseController::class, 'markPaid'])->name('expenses.paid');
    // وفاتورةُ المصروف تُقرأ ببابٍ يسأل — لا من `public/storage` بلا سؤال
    Route::get('/expenses/{id}/attachment', [FinancialAttachmentController::class, 'expense'])
        ->name('expenses.attachment');
    Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    Route::post('/expenses/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'expense')->name('expenses.restore');
    Route::delete('/expenses/{id}/purge', [TrashController::class, 'purge'])
        ->defaults('type', 'expense')->name('expenses.purge');
    // أنواع المصروفات
    Route::post('/expense-types', [ExpenseTypeController::class, 'store'])->name('expenseTypes.store');
    Route::put('/expense-types/{id}', [ExpenseTypeController::class, 'update'])->name('expenseTypes.update');
    Route::delete('/expense-types/{id}', [ExpenseTypeController::class, 'destroy'])->name('expenseTypes.destroy');
    // ملخّص المبيعات — كان محتوى /reports نفسه قبل أن يصير الفهرس بابها

    // تغذية التقارير (Polling) — صفحة تُترك مفتوحة لا يجوز أن تتجمّد على أرقام الصباح

    Route::post('/goals', [GoalController::class, 'update'])->name('goals.update');

    // إشعارات المتصفح (Polling)
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('/notifications/dismiss', [NotificationController::class, 'dismiss'])->name('notifications.dismiss');
    Route::post('/notifications/clear', [NotificationController::class, 'clear'])->name('notifications.clear');

    // النسخ الاحتياطي والاستعادة
    Route::get('/backup/download', [BackupController::class, 'download'])->name('backup.download');
    Route::post('/backup/restore', [BackupController::class, 'restore'])->name('backup.restore');

    // تصدير CSV
    Route::get('/export/reports', [ExportController::class, 'reports'])->name('export.reports');
    Route::get('/export/products', [ExportController::class, 'products'])->name('export.products');
    Route::get('/export/customers', [ExportController::class, 'customers'])->name('export.customers');
    Route::get('/export/suppliers', [ExportController::class, 'suppliers'])->name('export.suppliers');
    Route::get('/export/transactions', [ExportController::class, 'transactions'])->name('export.transactions');
    Route::get('/export/expenses', [ExportController::class, 'expenses'])->name('export.expenses');
    Route::get('/export/inventory', [ExportController::class, 'inventory'])->name('export.inventory');

    /*
     * التقارير — أُعيدت بعد حذفها في d34f32e.
     *
     * الفهرس بابٌ لا شاشةُ أرقام: يجمع ما تفرّق في اثنتي عشرة شاشة ويقود
     * إليها. وملخّص المبيعات وحده تقريرٌ قائمٌ بذاته تحته.
     */
    Route::get('/reports', [PageController::class, 'reportsIndex'])->name('reports.index');
    Route::get('/reports/sales', [PageController::class, 'reportsSales'])->name('reports.sales');
    /*
     * ولكلِّ تقريرٍ صفحته.
     *
     * كانت هذه الثلاثة تُعرض في نافذةٍ واحدة بقالبٍ واحد: بلا مبدّل فترة —
     * محسوبةً على الشهر وحده ولا شيء يقول ذلك — وبلا مؤشّرات، وبلا رابطٍ
     * يُفتح أو يُرسَل. وصلاحيةُ كلٍّ قسمُه لا «التقارير» (انظر الحارس في
     * ReportPageController): فيها مبيعاتُ الموظفين وإنفاقُ العملاء.
     */
    Route::get('/reports/payments', [ReportPageController::class, 'payments'])->name('reports.payments');
    Route::get('/reports/staff', [ReportPageController::class, 'staff'])->name('reports.staff');
    Route::get('/reports/customers', [ReportPageController::class, 'customers'])->name('reports.customers');
    /*
     * وبقيّةُ الفهرس تبعتها.
     *
     * كانت بطاقاتُها تقود إلى **شاشات الأقسام**: «تقرير الطلبات» يفتح شاشة
     * إدارة الطلبات وفيها زرُّ تعديلٍ وزرُّ حذف — فمن دخل ليقرأ وجد نفسه في
     * موضع الكتابة. وشاشةُ القسم لا تُجيب سؤال التقرير أصلًا: لا فترةَ
     * تُختار ولا مؤشّراتٍ فوق الجدول.
     *
     * وشاشاتُ الأقسام باقيةٌ في القائمة الجانبية كما هي — تغيّرت وجهةُ
     * البطاقة لا الشاشة.
     */
    Route::get('/reports/finance', [ReportPageController::class, 'finance'])->name('reports.finance');
    Route::get('/reports/expenses', [ReportPageController::class, 'expenses'])->name('reports.expenses');
    Route::get('/reports/bank', [ReportPageController::class, 'bank'])->name('reports.bank');
    Route::get('/reports/invoices', [ReportPageController::class, 'invoices'])->name('reports.invoices');
    Route::get('/reports/products', [ReportPageController::class, 'products'])->name('reports.products');
    Route::get('/reports/inventory', [ReportPageController::class, 'inventory'])->name('reports.inventory');
    Route::get('/reports/purchases', [ReportPageController::class, 'purchases'])->name('reports.purchases');
    Route::get('/reports/suppliers', [ReportPageController::class, 'suppliers'])->name('reports.suppliers');
    Route::get('/reports/activity', [ReportPageController::class, 'activity'])->name('reports.activity');

    // عمليات جرد المخزون — أين فارق الدفترُ الواقع، وبكم
    Route::get('/reports/stocktake', [ReportPageController::class, 'stocktake'])->name('reports.stocktake');

    /*
     * تنزيلُ أيّ تقريرٍ بالصيغ الثلاث المعتمدة.
     *
     * وبابٌ واحد لا ثلاثةٌ لكل تقرير: ستّةَ عشرَ تقريرًا في ثلاث صيغ تعني
     * ثمانيةً وأربعين مسارًا تتفرّق أعمدتُها عن أعمدة الشاشة. والحارس على
     * قسم التقرير نفسه لا على «التقارير» — انظر ReportDownloadController.
     */
    Route::get('/reports/{report}/export/xlsx', [ReportDownloadController::class, 'xlsx'])->name('reports.export.xlsx');
    Route::get('/reports/{report}/export/csv', [ReportDownloadController::class, 'csv'])->name('reports.export.csv');
    Route::get('/reports/{report}/export/pdf', [ReportDownloadController::class, 'pdf'])->name('reports.export.pdf');
    // تغذية: صفحةٌ تُترك مفتوحة لا يجوز أن تتجمّد على أرقام الصباح
    Route::get('/reports/feed', [ReportFeedController::class, 'reports'])->name('reports.feed');
    // والتصدير يتبع الفترة المعروضة — ملفٌّ يحمل غير ما على الشاشة يُقرأ خطأً
    Route::get('/reports/xlsx', [ReportExportController::class, 'xlsx'])->name('reports.xlsx');
    Route::get('/reports/pdf', [PdfController::class, 'salesReport'])->name('reports.pdf');
    /*
     * تحليلات الهالك.
     *
     * تحت `reports` لا تحت `inventory` كي يقيسها حارس المسار بصلاحية
     * التقارير — وهي قراءةٌ لا كتابة. ومن يملك المخزون يملك تسجيل الهالك
     * من شاشته، ومن يملك التقارير يقرأ أثره.
     */
    Route::get('/reports/waste', [WasteAnalyticsController::class, 'index'])->name('reports.waste');
    Route::get('/reports/vat', [ReportPageController::class, 'vat'])->name('reports.vat');

    Route::get('/activity', [ActivityController::class, 'adminIndex'])->name('activity.index');
    Route::get('/settings', [PageController::class, 'settingsIndex'])->name('settings.index');
    Route::post('/language', [LanguageController::class, 'update'])->name('language.update');
    Route::post('/settings', [App\Http\Controllers\Admin\SettingController::class, 'update'])->name('settings.update');
    // الشعار وحده: ملفٌّ يحتاج multipart، وخلطُه بنموذج الإعدادات يُرسل كل مقبضٍ نصًّا
    Route::post('/settings/logo', [App\Http\Controllers\Admin\SettingController::class, 'logo'])->name('settings.logo');

    // المحذوفات — استعادة ما أذهبته ضغطة (انظر TrashController)
    /*
     * محرّرُ القوالب — صفحةٌ لكلّ ورقة لا بطاقةٌ في قائمة.
     *
     * الورقةُ تُعاين بحجمها، والإعدادات تُقرأ بجانبها. وحشرُهما في تبويبٍ
     * ضمن صفحةٍ فيها عشرون بطاقةً يجعل المعاينة صندوقًا لا يُرى فيه شيء.
     */
    Route::get('/settings/templates/{type}', [TemplateController::class, 'edit'])->name('settings.templates.edit');
    Route::post('/settings/templates/{type}', [TemplateController::class, 'update'])->name('settings.templates.update');
    Route::post('/settings/templates/{type}/preview', [TemplateController::class, 'preview'])->name('settings.templates.preview');
    /*
     * وهويّةُ الأوراق بابان مستقلّان عن القالب.
     *
     * لأنّها تخصّ المتجر كلَّه لا ورقةً بعينها — ولأنّ الغلافَ ملفٌّ يُرفع
     * بـmultipart، وخلطُه بحقولِ JSON يكسر مصادقةَ الأعلام.
     */
    Route::post('/settings/documents/brand', [TemplateController::class, 'brand'])->name('settings.documents.brand');
    Route::post('/settings/documents/cover', [TemplateController::class, 'cover'])->name('settings.documents.cover');

    Route::get('/settings/trash', [TrashController::class, 'index'])->name('settings.trash');

    /*
     * سياساتُ الشركة — نصٌّ يراه العميل على كلّ عرض، ومعاملاتٌ يفحصها النظام.
     *
     * وتحت «الإعدادات» لا «عروض الأسعار»: من يكتب العرضَ لا يكتب شروطَ
     * الشركة. انظر `Policy\Registry` و§18.
     */
    Route::get('/settings/policies', [PolicyController::class, 'index'])->name('settings.policies.index');
    Route::post('/settings/policies', [PolicyController::class, 'store'])->name('settings.policies.store');
    Route::put('/settings/policies/{id}', [PolicyController::class, 'update'])->name('settings.policies.update');
    Route::delete('/settings/policies/{id}', [PolicyController::class, 'destroy'])->name('settings.policies.destroy');

    /*
     * حزمُ التسليم — المرحلة 5. ما تسلّمه الشركةُ للعميل ويقبله من رابطه.
     *
     * وقسمُها `quotations` (`ALIASES['deliveries']`): من يعرض على العميل
     * يسلّمه. والدورةُ في `App\Support\DeliveryPackages`.
     */
    Route::get('/deliveries', [DeliveryPackageController::class, 'index'])->name('deliveries.index');
    Route::get('/deliveries/create', [DeliveryPackageController::class, 'create'])->name('deliveries.create');
    Route::post('/deliveries', [DeliveryPackageController::class, 'store'])->name('deliveries.store');
    Route::get('/deliveries/{id}', [DeliveryPackageController::class, 'show'])->name('deliveries.show');
    Route::put('/deliveries/{id}', [DeliveryPackageController::class, 'update'])->name('deliveries.update');
    Route::post('/deliveries/{id}/items', [DeliveryPackageController::class, 'addItem'])->name('deliveries.items.store');
    Route::delete('/deliveries/{id}/items/{item}', [DeliveryPackageController::class, 'removeItem'])->name('deliveries.items.destroy');
    Route::get('/deliveries/{id}/files/{item}', [DeliveryPackageController::class, 'file'])->name('deliveries.file');
    Route::post('/deliveries/{id}/ready', [DeliveryPackageController::class, 'ready'])->name('deliveries.ready');
    Route::post('/deliveries/{id}/reopen', [DeliveryPackageController::class, 'reopen'])->name('deliveries.reopen');
    Route::post('/deliveries/{id}/send', [DeliveryPackageController::class, 'send'])->name('deliveries.send');
    Route::post('/deliveries/{id}/email', [DeliveryPackageController::class, 'email'])->name('deliveries.email');
    Route::post('/deliveries/{id}/decision', [DeliveryPackageController::class, 'decision'])->name('deliveries.decision');
    Route::post('/deliveries/{id}/cancel', [DeliveryPackageController::class, 'cancel'])->name('deliveries.cancel');
    Route::post('/deliveries/{id}/revise', [DeliveryPackageController::class, 'revise'])->name('deliveries.revise');

    /*
     * مركزُ العمليات — المرحلة 7: ما يحتاج يدًا اليوم، من كلّ الأقسام في شاشةٍ واحدة.
     * قسمٌ باسمه: للمالك والمدير بـ`*`، ويُمنح لغيرهما بالاسم — انظر `CommandCenter`.
     */
    Route::get('/command', [CommandCenterController::class, 'index'])->name('command.index');

    /*
     * الأتمتة — المرحلة 8: القواعدُ وإعداداتُها وسجلُّ ما فعله المحرّك.
     * القسمُ يقرأ؛ والضبطُ `automation.manage`، والإيقافُ `automation.pause`.
     */
    Route::get('/automation', [AutomationController::class, 'index'])->name('automation.index');
    Route::put('/automation/rules/{id}', [AutomationController::class, 'updateRule'])->whereNumber('id')->name('automation.rules.update');
    Route::post('/automation/settings', [AutomationController::class, 'settings'])->name('automation.settings');
    Route::post('/automation/pause', [AutomationController::class, 'pause'])->name('automation.pause');
    Route::post('/automation/evaluate', [AutomationController::class, 'evaluate'])->name('automation.evaluate');
    Route::get('/automation/history', [AutomationController::class, 'history'])->name('automation.history');
    Route::get('/automation/history/{id}', [AutomationController::class, 'show'])->whereNumber('id')->name('automation.history.show');
    Route::post('/automation/history/{id}/cancel', [AutomationController::class, 'cancel'])->whereNumber('id')->name('automation.runs.cancel');
    Route::post('/notifications/internal/{id}/read', [AutomationController::class, 'read'])->whereNumber('id')->name('notifications.internal.read');

    /*
     * استيرادُ البيانات التاريخيّة — المرحلة 9. القسمُ `imports` يقرأ؛ والتسجيلُ
     * والربطُ والتجربةُ `import.manage`، والاعتمادُ والتنفيذُ `import.execute`،
     * والماليُّ `import.financial`، والتراجعُ `import.rollback`. ولا مسارٌ يعطي الملفَّ نفسَه.
     */
    Route::get('/imports', [DataImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [DataImportController::class, 'store'])->name('imports.store');
    Route::put('/imports/cutover', [DataImportController::class, 'cutover'])->name('imports.cutover');
    Route::get('/imports/{id}', [DataImportController::class, 'show'])->whereNumber('id')->name('imports.show');
    Route::get('/imports/{id}/preview', [DataImportController::class, 'preview'])->whereNumber('id')->name('imports.preview');
    Route::get('/imports/{id}/runs/{run}/rows', [DataImportController::class, 'rows'])->whereNumber('id')->whereNumber('run')->name('imports.rows');
    Route::get('/imports/{id}/report', [DataImportController::class, 'report'])->whereNumber('id')->name('imports.report');
    Route::post('/imports/{id}/profile', [DataImportController::class, 'profile'])->whereNumber('id')->name('imports.profile');
    Route::put('/imports/{id}/mapping', [DataImportController::class, 'mapping'])->whereNumber('id')->name('imports.mapping');
    Route::put('/imports/{id}/strategy', [DataImportController::class, 'strategy'])->whereNumber('id')->name('imports.strategy');
    Route::put('/imports/{id}/resolve', [DataImportController::class, 'resolve'])->whereNumber('id')->name('imports.resolve');
    Route::post('/imports/{id}/dry-run', [DataImportController::class, 'dryRun'])->whereNumber('id')->name('imports.dryRun');
    Route::post('/imports/{id}/approve', [DataImportController::class, 'approve'])->whereNumber('id')->name('imports.approve');
    Route::post('/imports/{id}/import', [DataImportController::class, 'import'])->whereNumber('id')->name('imports.import');
    Route::post('/imports/{id}/rollback', [DataImportController::class, 'rollback'])->whereNumber('id')->name('imports.rollback');
    Route::post('/imports/{id}/cancel', [DataImportController::class, 'cancel'])->whereNumber('id')->name('imports.cancel');
    Route::post('/imports/{id}/templates', [DataImportController::class, 'saveTemplate'])->whereNumber('id')->name('imports.templates.store');
    Route::post('/imports/{id}/templates/apply', [DataImportController::class, 'applyTemplate'])->whereNumber('id')->name('imports.templates.apply');

    /*
     * تشغيلُ الفعاليّات — المرحلة 10. القسمُ `operations` يقرأ ويفتح شاشةَ
     * الموقع؛ والإنشاءُ والجدولُ والمتطلّباتُ بـ`operations.manage`، ونقلُ
     * الحال بـ`operations.status`، والحوادثُ بـ`operations.incidents`، والفريقُ
     * بـ`crew.manage`، والحجزُ والتسليمُ والردُّ بأفعال المعدّات، والإعفاءُ
     * بـ`checklist.manage`. وكلُّ فعلٍ يمرّ بخدمته لا بالمتحكّم.
     */
    Route::get('/operations', [EventOperationController::class, 'index'])->name('operations.index');
    Route::post('/operations', [EventOperationController::class, 'store'])->name('operations.store');
    Route::get('/operations/templates', [EventOperationController::class, 'templates'])->name('operations.templates');
    Route::post('/operations/templates', [EventOperationController::class, 'storeTemplate'])->name('operations.templates.store');
    Route::put('/operations/templates/{tid}', [EventOperationController::class, 'updateTemplate'])->whereNumber('tid')->name('operations.templates.update');
    Route::delete('/operations/templates/{tid}', [EventOperationController::class, 'destroyTemplate'])->whereNumber('tid')->name('operations.templates.destroy');
    Route::get('/operations/{id}', [EventOperationController::class, 'show'])->whereNumber('id')->name('operations.show');
    Route::put('/operations/{id}', [EventOperationController::class, 'update'])->whereNumber('id')->name('operations.update');
    Route::get('/operations/{id}/onsite', [EventOperationController::class, 'onsite'])->whereNumber('id')->name('operations.onsite');
    Route::post('/operations/{id}/transition', [EventOperationController::class, 'transition'])->whereNumber('id')->name('operations.transition');
    Route::post('/operations/{id}/complete', [EventOperationController::class, 'complete'])->whereNumber('id')->name('operations.complete');
    Route::post('/operations/{id}/cancel', [EventOperationController::class, 'cancel'])->whereNumber('id')->name('operations.cancel');
    Route::post('/operations/{id}/schedule', [EventOperationController::class, 'storeScheduleItem'])->whereNumber('id')->name('operations.schedule.store');
    Route::put('/operations/{id}/schedule/{sid}', [EventOperationController::class, 'updateScheduleItem'])->whereNumber('id')->whereNumber('sid')->name('operations.schedule.update');
    Route::delete('/operations/{id}/schedule/{sid}', [EventOperationController::class, 'destroyScheduleItem'])->whereNumber('id')->whereNumber('sid')->name('operations.schedule.destroy');
    Route::post('/operations/{id}/schedule/{sid}/status', [EventOperationController::class, 'scheduleStatus'])->whereNumber('id')->whereNumber('sid')->name('operations.schedule.status');
    Route::post('/operations/{id}/requirements', [EventOperationController::class, 'storeRequirement'])->whereNumber('id')->name('operations.requirements.store');
    Route::put('/operations/{id}/requirements/{rid}', [EventOperationController::class, 'updateRequirement'])->whereNumber('id')->whereNumber('rid')->name('operations.requirements.update');
    Route::delete('/operations/{id}/requirements/{rid}', [EventOperationController::class, 'destroyRequirement'])->whereNumber('id')->whereNumber('rid')->name('operations.requirements.destroy');
    Route::post('/operations/{id}/reservations', [EventOperationController::class, 'reserve'])->whereNumber('id')->name('operations.reservations.store');
    Route::post('/operations/{id}/reservations/{rid}/cancel', [EventOperationController::class, 'cancelReservation'])->whereNumber('id')->whereNumber('rid')->name('operations.reservations.cancel');
    Route::post('/operations/{id}/reservations/{rid}/checkout', [EventOperationController::class, 'checkOut'])->whereNumber('id')->whereNumber('rid')->name('operations.reservations.checkout');
    Route::post('/operations/{id}/reservations/{rid}/return', [EventOperationController::class, 'giveBack'])->whereNumber('id')->whereNumber('rid')->name('operations.reservations.return');
    Route::post('/operations/{id}/crew', [EventOperationController::class, 'storeCrew'])->whereNumber('id')->name('operations.crew.store');
    Route::put('/operations/{id}/crew/{cid}', [EventOperationController::class, 'updateCrew'])->whereNumber('id')->whereNumber('cid')->name('operations.crew.update');
    Route::delete('/operations/{id}/crew/{cid}', [EventOperationController::class, 'destroyCrew'])->whereNumber('id')->whereNumber('cid')->name('operations.crew.destroy');
    Route::post('/operations/{id}/crew/{cid}/check-in', [EventOperationController::class, 'crewCheckIn'])->whereNumber('id')->whereNumber('cid')->name('operations.crew.checkIn');
    Route::post('/operations/{id}/crew/{cid}/check-out', [EventOperationController::class, 'crewCheckOut'])->whereNumber('id')->whereNumber('cid')->name('operations.crew.checkOut');
    Route::post('/operations/{id}/crew/{cid}/notify', [EventOperationController::class, 'notifyCrew'])->whereNumber('id')->whereNumber('cid')->name('operations.crew.notify');
    Route::post('/operations/{id}/crew-alert', [EventOperationController::class, 'alertCrew'])->whereNumber('id')->name('operations.crew.alert');
    /* المنظّمون على الفعاليّة ومسؤولُهم — بفعل الفريق نفسِه (المرحلة 14) */
    Route::post('/operations/{id}/organizers', [EventOperationController::class, 'storeOrganizer'])->whereNumber('id')->name('operations.organizers.store');
    Route::put('/operations/{id}/organizers/{oid}', [EventOperationController::class, 'updateOrganizer'])->whereNumber('id')->whereNumber('oid')->name('operations.organizers.update');
    Route::post('/operations/{id}/organizers-lead', [EventOperationController::class, 'organizersLead'])->whereNumber('id')->name('operations.organizersLead');
    /* مهامُّ الفعاليّة إلى أصحابها — النشرُ مرساةُ رسالة كلّ موظّف، وتجديدُ رابطه بيد */
    Route::post('/operations/{id}/tasks/publish', [EventOperationController::class, 'publishTasks'])->whereNumber('id')->name('operations.tasks.publish');
    Route::post('/operations/{id}/tasks/links/{userId}', [EventOperationController::class, 'rotateTaskLink'])->whereNumber('id')->whereNumber('userId')->name('operations.tasks.rotate');
    Route::post('/operations/{id}/checklists', [EventOperationController::class, 'attachChecklist'])->whereNumber('id')->name('operations.checklists.store');
    Route::post('/operations/{id}/checklists/{clid}/items', [EventOperationController::class, 'storeChecklistItem'])->whereNumber('id')->whereNumber('clid')->name('operations.checklists.items.store');
    Route::post('/operations/{id}/checklist-items/{iid}/complete', [EventOperationController::class, 'completeChecklistItem'])->whereNumber('id')->whereNumber('iid')->name('operations.checklistItems.complete');
    Route::post('/operations/{id}/checklist-items/{iid}/reopen', [EventOperationController::class, 'reopenChecklistItem'])->whereNumber('id')->whereNumber('iid')->name('operations.checklistItems.reopen');
    Route::post('/operations/{id}/checklist-items/{iid}/block', [EventOperationController::class, 'blockChecklistItem'])->whereNumber('id')->whereNumber('iid')->name('operations.checklistItems.block');
    Route::post('/operations/{id}/checklist-items/{iid}/waive', [EventOperationController::class, 'waiveChecklistItem'])->whereNumber('id')->whereNumber('iid')->name('operations.checklistItems.waive');
    Route::post('/operations/{id}/incidents', [EventOperationController::class, 'storeIncident'])->whereNumber('id')->name('operations.incidents.store');
    Route::put('/operations/{id}/incidents/{iid}', [EventOperationController::class, 'updateIncident'])->whereNumber('id')->whereNumber('iid')->name('operations.incidents.update');
    Route::post('/operations/{id}/incidents/{iid}/resolve', [EventOperationController::class, 'resolveIncident'])->whereNumber('id')->whereNumber('iid')->name('operations.incidents.resolve');
    Route::post('/operations/{id}/incidents/{iid}/close', [EventOperationController::class, 'closeIncident'])->whereNumber('id')->whereNumber('iid')->name('operations.incidents.close');
    Route::post('/operations/{id}/incidents/{iid}/reopen', [EventOperationController::class, 'reopenIncident'])->whereNumber('id')->whereNumber('iid')->name('operations.incidents.reopen');
    Route::post('/operations/{id}/incidents/{iid}/carry-forward', [EventOperationController::class, 'carryForwardIncident'])->whereNumber('id')->whereNumber('iid')->name('operations.incidents.carryForward');

    /*
     * المعدّاتُ ذاتُ الهويّة — المرحلة 10. القسمُ `assets` يقرأ السجلَّ
     * والتقويم؛ والتسجيلُ وتغييرُ الحال بـ`assets.manage`، والردُّ من بطاقة
     * القطعة بـ`assets.return`.
     */
    Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
    Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
    Route::get('/assets/calendar', [AssetController::class, 'calendar'])->name('assets.calendar');
    Route::get('/assets/{id}', [AssetController::class, 'show'])->whereNumber('id')->name('assets.show');
    Route::put('/assets/{id}', [AssetController::class, 'update'])->whereNumber('id')->name('assets.update');
    Route::post('/assets/{id}/status', [AssetController::class, 'status'])->whereNumber('id')->name('assets.status');
    Route::post('/assets/{id}/reservations/{rid}/return', [AssetController::class, 'giveBack'])->whereNumber('id')->whereNumber('rid')->name('assets.reservations.return');

    /* المستهلَكاتُ من الرفّ إلى الفعاليّة وعودتُها — المرحلة 11؛ من باب المخزون نفسِه */
    Route::post('/operations/{id}/consumables', [EventOperationController::class, 'issueConsumable'])->whereNumber('id')->name('operations.consumables.store');
    Route::post('/operations/{id}/consumables/{cid}/return', [EventOperationController::class, 'returnConsumable'])->whereNumber('id')->whereNumber('cid')->name('operations.consumables.return');

    /*
     * الإدارة — المرحلة 11. القسمُ `management` يفتح الشاشات؛ والأرقامُ
     * الماليّة بأفعالها (`analytics.*`)، والموازنةُ والتوقّعُ والسيناريو
     * والمراجعةُ بأفعالها. وكلُّ رقمٍ من `Intelligence\*` لا من المتحكّم.
     */
    Route::get('/management', [ManagementController::class, 'index'])->name('management.index');
    Route::get('/management/financial', [ManagementController::class, 'financial'])->name('management.financial');
    Route::get('/management/projects', [ManagementController::class, 'projects'])->name('management.projects');
    Route::get('/management/customers', [ManagementController::class, 'customers'])->name('management.customers');
    Route::get('/management/services', [ManagementController::class, 'services'])->name('management.services');
    Route::get('/management/cash', [ManagementController::class, 'cash'])->name('management.cash');
    Route::get('/management/operations', [ManagementController::class, 'operations'])->name('management.operations');
    Route::get('/management/reports', [ManagementController::class, 'reports'])->name('management.reports');
    Route::put('/management/thresholds', [ManagementController::class, 'thresholds'])->name('management.thresholds');
    Route::get('/management/budgets', [BudgetController::class, 'index'])->name('management.budgets');
    Route::post('/management/budgets', [BudgetController::class, 'store'])->name('management.budgets.store');
    Route::put('/management/budgets/{id}', [BudgetController::class, 'update'])->whereNumber('id')->name('management.budgets.update');
    Route::post('/management/budgets/{id}/approve', [BudgetController::class, 'approve'])->whereNumber('id')->name('management.budgets.approve');
    Route::post('/management/budgets/{id}/revise', [BudgetController::class, 'revise'])->whereNumber('id')->name('management.budgets.revise');
    Route::post('/management/budgets/{id}/archive', [BudgetController::class, 'archive'])->whereNumber('id')->name('management.budgets.archive');
    Route::post('/management/forecasts', [ForecastController::class, 'store'])->name('management.forecasts.store');
    Route::post('/management/forecasts/{id}/approve', [ForecastController::class, 'approve'])->whereNumber('id')->name('management.forecasts.approve');
    Route::delete('/management/forecasts/{id}', [ForecastController::class, 'destroy'])->whereNumber('id')->name('management.forecasts.destroy');
    Route::post('/management/scenarios', [ForecastController::class, 'storeScenario'])->name('management.scenarios.store');
    Route::put('/management/scenarios/{id}', [ForecastController::class, 'updateScenario'])->whereNumber('id')->name('management.scenarios.update');
    Route::delete('/management/scenarios/{id}', [ForecastController::class, 'destroyScenario'])->whereNumber('id')->name('management.scenarios.destroy');
    Route::get('/management/reviews', [ManagementReviewController::class, 'index'])->name('management.reviews');
    Route::post('/management/reviews', [ManagementReviewController::class, 'store'])->name('management.reviews.store');
    Route::get('/management/reviews/{id}', [ManagementReviewController::class, 'show'])->whereNumber('id')->name('management.reviews.show');
    Route::put('/management/reviews/{id}', [ManagementReviewController::class, 'update'])->whereNumber('id')->name('management.reviews.update');
    Route::post('/management/reviews/{id}/refresh', [ManagementReviewController::class, 'refresh'])->whereNumber('id')->name('management.reviews.refresh');
    Route::post('/management/reviews/{id}/review', [ManagementReviewController::class, 'review'])->whereNumber('id')->name('management.reviews.review');
    Route::post('/management/reviews/{id}/close', [ManagementReviewController::class, 'close'])->whereNumber('id')->name('management.reviews.close');
    Route::post('/management/reviews/{id}/decisions', [ManagementReviewController::class, 'storeDecision'])->whereNumber('id')->name('management.reviews.decisions.store');
    Route::post('/management/reviews/{id}/decisions/{did}/status', [ManagementReviewController::class, 'decisionStatus'])->whereNumber('id')->whereNumber('did')->name('management.reviews.decisions.status');

    /*
     * المراسلات — المرحلة 6.
     *
     * سجلُّ الشركة كلُّه في قسم «المراسلات»؛ ونافذةُ كلّ مستندٍ (مراسلاتُه،
     * ونافذةُ الإرسال منه) تحت مسار المستند نفسِه فتتبع قسمَه: البائعُ يرسل
     * من عرضه بلا أن يُمنح السجلَّ كلَّه — انظر `CommunicationController`.
     */
    Route::get('/communications', [CommunicationController::class, 'index'])->name('communications.index');
    Route::get('/communications/templates', [CommunicationTemplateController::class, 'index'])->name('communications.templates');
    Route::post('/communications/templates', [CommunicationTemplateController::class, 'update'])->name('communications.templates.update');
    Route::post('/communications/templates/preview', [CommunicationTemplateController::class, 'preview'])->name('communications.templates.preview');
    Route::get('/communications/{id}', [CommunicationController::class, 'show'])->name('communications.show');
    Route::post('/communications/{id}/retry', [CommunicationController::class, 'retry'])->name('communications.retry');

    /*
     * واتساب — صندوقُ الوارد. قسمُه «المراسلات» (`Permissions::ALIASES`)،
     * والردُّ فعلُ «الإرسال» يُسأل عنه في المتحكّم.
     */
    Route::get('/whatsapp', [WhatsAppController::class, 'index'])->name('whatsapp.index');
    Route::post('/whatsapp/{id}/reply', [WhatsAppController::class, 'reply'])->whereNumber('id')->name('whatsapp.reply');
    Route::get('/whatsapp/media/{id}', [WhatsAppController::class, 'media'])->whereNumber('id')->name('whatsapp.media');

    /* اسمُ المسار · مسارُه في العنوان · نوعُ الموضوع في `Subjects::TYPES` */
    foreach ([
        ['quotations', 'quotations', 'quotation'],
        ['projects', 'projects', 'project'],
        ['customerInvoices', 'customer-invoices', 'customer_invoice'],
        ['deliveries', 'deliveries', 'delivery_package'],
        ['changeOrders', 'change-orders', 'change_order'],
        ['customers', 'customers', 'customer'],
        /* ونطاقُ العمل — رابطُ توقيعه يُرسَل من نافذة المراسلات نفسِها */
        ['projects.scopes', 'projects/scopes', 'scope_of_work'],
    ] as [$name, $path, $type]) {
        Route::get("/{$path}/{id}/communications", [CommunicationController::class, 'history'])
            ->whereNumber('id')->name("{$name}.communications")->defaults('type', $type);
        Route::get("/{$path}/{id}/communications/compose", [CommunicationController::class, 'compose'])
            ->whereNumber('id')->name("{$name}.communications.compose")->defaults('type', $type);
        Route::post("/{$path}/{id}/communications", [CommunicationController::class, 'send'])
            ->whereNumber('id')->name("{$name}.communications.send")->defaults('type', $type);
    }
    Route::get('/projects/{id}/timeline', [CommunicationController::class, 'timeline'])->name('projects.timeline');

    // تنبيهات يعرّفها صاحب النشاط — قواعد على مقاييس النظام، وتذكيرات بموعد
    Route::post('/alerts', [CustomAlertController::class, 'store'])->name('alerts.store');
    Route::put('/alerts/{id}', [CustomAlertController::class, 'update'])->name('alerts.update');
    Route::delete('/alerts/{id}', [CustomAlertController::class, 'destroy'])->name('alerts.destroy');
});

/*
 * الورقةُ أونلاين — ما يفتحه من مسح الرمز أسفل إيصاله.
 *
 * والعنوانُ قصيرٌ عمدًا: كلُّ حرفٍ فيه يزيد كثافةَ الرمز المطبوع، ورمزٌ
 * كثيف على ورقٍ حراريّ باهت لا تقرؤه كاميرا هاتف. و`/i/` والرمزُ وحدهما
 * ستّةٌ وعشرون حرفًا بعد اسم النطاق.
 *
 * وبلا حارس: الواقف أمامه زبونٌ لا حساب له — انظر PublicDocumentController.
 */
Route::get('/i/{token}', [PublicDocumentController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{22}')
    ->name('paper.show');

/*
 * وقرارُ العميل من الرابط نفسِه — قبولٌ أو اعتذار.
 *
 * بلا حارسٍ كذلك: الواقفُ أمامه عميلٌ لا حسابَ له. ويحرسه الرمزُ الذي لا
 * يُخمَّن، وخنقٌ بالعنوان في المتحكّم، وقفلٌ في `Quotations::decide`.
 */
Route::post('/i/{token}/decision', [PublicDocumentController::class, 'decide'])
    ->where('token', '[A-Za-z0-9]{22}')
    ->name('paper.decide');

/*
 * «مهامي في الفعاليّة» — ما يفتحه زرُّ رسالة واتساب إلى الموظّف.
 *
 * بلا حساب: الرمزُ (٤٠ حرفًا، يُبحث ببصمته، وله انتهاء) يفتح مهامَّ صاحبه في
 * فعاليّته وحدها، و«تمّت» لا تقبل إلّا مهمّةً له فيها — انظر EventTaskLinkController.
 */
Route::get('/t/{token}', [EventTaskLinkController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->name('event-tasks.show');
Route::post('/t/{token}/tasks/{task}/done', [EventTaskLinkController::class, 'done'])
    ->where(['token' => '[A-Za-z0-9]{40}', 'task' => '[0-9]+'])->middleware('throttle:60,10')
    ->name('event-tasks.done');
Route::post('/t/{token}/tasks/{task}/reopen', [EventTaskLinkController::class, 'reopen'])
    ->where(['token' => '[A-Za-z0-9]{40}', 'task' => '[0-9]+'])->middleware('throttle:60,10')
    ->name('event-tasks.reopen');

/*
 * حزمةُ التسليم من رابط العميل — تُقرأ ويُقرَّر منها وتُفتح ملفّاتُها.
 *
 * الرمزُ رمزُ `document_links` نفسُه، والبابُ مستقلٌّ عن `/i/` كي لا يُمسّ
 * ذاك — انظر `PublicDeliveryController`.
 */
Route::get('/d/{token}', [PublicDeliveryController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{22}')
    ->name('delivery.show');
Route::post('/d/{token}/decision', [PublicDeliveryController::class, 'decide'])
    ->where('token', '[A-Za-z0-9]{22}')
    ->name('delivery.decide');
Route::get('/d/{token}/files/{item}', [PublicDeliveryController::class, 'file'])
    ->where(['token' => '[A-Za-z0-9]{22}', 'item' => '[0-9]+'])
    ->name('delivery.file');

/*
 * أخبارُ المزوّدين عن رسائلنا — المرحلة 6.
 *
 * بلا جلسةٍ ولا CSRF (انظر `bootstrap/app.php`): المنادي خادمٌ لا متصفّح.
 * ويحرسهما سرٌّ: Postmark بهويّةٍ في عنوانها، وMeta بتوقيع HMAC على الجسد.
 * والخنقُ يمنع أن يُغرق مجهولٌ السجلَّ بأخبارٍ لا تُقبل أصلًا.
 */
Route::middleware('throttle:240,1')->group(function () {
    Route::post('/webhooks/postmark', [PostmarkWebhookController::class, 'handle'])->name('webhooks.postmark');
    Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
    Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp');
});
