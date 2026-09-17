<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\BankStatementController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CatalogQuickAddController;
use App\Http\Controllers\Admin\ChequeController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\CustomAlertController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\CustomerImportExportController;
use App\Http\Controllers\Admin\CustomerInvoiceController;
use App\Http\Controllers\Admin\DocumentPrintController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\ExpenseTypeController;
use App\Http\Controllers\Admin\Finance\BankAccountController;
use App\Http\Controllers\Admin\Finance\ChartController;
use App\Http\Controllers\Admin\Finance\FixedAssetController;
use App\Http\Controllers\Admin\Finance\JournalController;
use App\Http\Controllers\Admin\Finance\OverviewController;
use App\Http\Controllers\Admin\FinanceController;
use App\Http\Controllers\Admin\FinancialAttachmentController;
use App\Http\Controllers\Admin\GoalController;
use App\Http\Controllers\Admin\Inventory\GoodsReceiptNoteController;
use App\Http\Controllers\Admin\Inventory\StockAdjustmentController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\JobTitleController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\OrderDetailController;
use App\Http\Controllers\Admin\OrderEditController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\Payroll\PayrollPaymentController;
use App\Http\Controllers\Admin\Payroll\PayrollRunController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\ProductCompositionController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductImportExportController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\QuotationController;
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
use App\Http\Controllers\Admin\TemplateController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\WasteAnalyticsController;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicDocumentController;
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
Route::prefix('admin')->name('admin.')->middleware(['auth', 'tenant', 'business', 'panel', 'ability'])->group(function () {
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
     * صور المنتج — مسارٌ بذاته لا حقلٌ في نموذج المنتج.
     *
     * نموذج المنتج يكتب الكمية مطلقةً ويُزيح رصيد الفرع بفارقها، فمن بدّل
     * صورةً بحفظ النموذج كلِّه كتب فوق كلّ ما تغيّر بينه وبين فتحه الشاشة.
     * فالصور تُدار بطلباتٍ صغيرة لا تمسّ عمودًا آخر.
     */
    Route::post('/products/{id}/images', [ProductImageController::class, 'store'])->name('products.images.store');
    Route::post('/products/{id}/images/{imageId}/main', [ProductImageController::class, 'promote'])->name('products.images.promote');
    Route::delete('/products/{id}/images/{imageId}', [ProductImageController::class, 'destroy'])->name('products.images.destroy');
    Route::delete('/products/{id}/image', [ProductImageController::class, 'destroyMain'])->name('products.images.destroyMain');

    /*
     * تركيب المنتج: مقاساتُه ووصفتُه وإضافاتُه.
     *
     * تحت `/products/{id}` عمدًا: الحارس يشتقّ القسم من اسم المسار، فتقع
     * كلّها تحت صلاحية «المنتجات» بلا صلاحيةٍ جديدة تُخترع.
     */
    /*
     * قسمٌ أو إضافةٌ تُنشأ من جانب الحقل الذي يحتاجها.
     *
     * قبل هذا لم يكن للأقسام ولا للإضافات بابُ إنشاءٍ إطلاقًا: تأتي من
     * تهيئة نوع النشاط أو من استيراد ملفّ. وتُوضع تحت `/products/` كي
     * يقيسها الحارس بصلاحية «المنتجات» بلا صلاحيةٍ جديدة.
     */
    Route::post('/products/categories', [CatalogQuickAddController::class, 'storeCategory'])->name('products.categories.store');
    Route::post('/products/addons', [CatalogQuickAddController::class, 'storeAddon'])->name('products.addons.store');
    // تعديل إضافةٍ قائمة — سعرها ومداها وما تأكله من الرفّ. ويسبق مسار
    // «products/{id}/addons» فلا يبتلعه: هذا معرّف إضافةٍ لا معرّف منتج
    Route::put('/products/addons/{addon}', [CatalogQuickAddController::class, 'updateAddon'])->name('products.addons.update');

    Route::post('/products/{id}/variants', [ProductCompositionController::class, 'storeVariant'])->name('products.variants.store');
    Route::put('/products/{id}/variants/{variant}', [ProductCompositionController::class, 'updateVariant'])->name('products.variants.update');
    Route::delete('/products/{id}/variants/{variant}', [ProductCompositionController::class, 'destroyVariant'])->name('products.variants.destroy');
    Route::post('/products/{id}/recipe', [ProductCompositionController::class, 'storeRecipeItem'])->name('products.recipe.store');
    Route::put('/products/{id}/recipe/{item}', [ProductCompositionController::class, 'updateRecipeItem'])->name('products.recipe.update');
    Route::delete('/products/{id}/recipe/{item}', [ProductCompositionController::class, 'destroyRecipeItem'])->name('products.recipe.destroy');
    /*
     * بنودُ الباقة — وهي غيرُ الوصفة.
     *
     * الوصفةُ تقول «كم يُخصم من الرفّ»، والباقةُ تقول «ما يشمله هذا العرض».
     * وتُدار من شاشة المنتج نفسِها بصلاحية «المنتجات» — من يملك تركيبَ
     * المنتج يملك ما تحويه باقتُه.
     */
    Route::post('/products/{id}/package', [ProductCompositionController::class, 'storePackageItem'])->name('products.package.store');
    Route::put('/products/{id}/package/{item}', [ProductCompositionController::class, 'updatePackageItem'])->name('products.package.update');
    Route::delete('/products/{id}/package/{item}', [ProductCompositionController::class, 'destroyPackageItem'])->name('products.package.destroy');
    Route::put('/products/{id}/addons', [ProductCompositionController::class, 'syncAddons'])->name('products.addons.sync');
    Route::delete('/products/{id}/addons/{addon}', [ProductCompositionController::class, 'destroyAddon'])->name('products.addons.destroy');
    Route::post('/products/{id}/restore', [TrashController::class, 'restore'])
        ->defaults('type', 'product')->name('products.restore');
    // المحو النهائي يتبع صلاحية الحذف نفسها: من أخفاه يمحوه، ولا أحد سواه
    Route::delete('/products/{id}/purge', [TrashController::class, 'purge'])
        ->defaults('type', 'product')->name('products.purge');

    // التصنيفات

    // الإضافات

    // الطلبات
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    // تصدير قائمة الطلبات (قبل /orders/{id} حتى لا يبتلعها نمط المعرّف)
    Route::get('/orders/export-xlsx', [ReportExportController::class, 'ordersXlsx'])->name('orders.xlsx');
    Route::get('/orders/export-pdf', [PdfController::class, 'ordersReport'])->name('orders.exportPdf');
    Route::get('/orders/{number}', [PageController::class, 'ordersShow'])->name('orders.show');
    /*
     * تعديل بيانات التنفيذ ونقل الحالة — قسمُهما «المبيعات» يُشتقّ من الاسم.
     *
     * وبابُهما غيرُ باب تصحيح الفاتورة تحته: ذاك يُحرّك المخزون والمال
     * ويشترط سببًا وصلاحيةً باسمها، وهذا يُصحّح رقم هاتفٍ أو موعدًا.
     */
    Route::put('/orders/{number}/details', [OrderDetailController::class, 'update'])->name('orders.details.update');
    /* تصحيح فاتورةٍ صدرت — الحكم على الفعل `order.edit` لا على الشاشة */
    Route::put('/orders/{number}/items/{item}', [OrderEditController::class, 'update'])->name('orders.items.update');
    Route::put('/orders/{number}/payment', [OrderEditController::class, 'payment'])->name('orders.payment.update');
    Route::post('/orders/{number}/status', [OrderDetailController::class, 'status'])->name('orders.status');

    // إرسالُ الفاتورة إلى الزبون — نصٌّ يُكتب في الخادم ويُفتح على واتساب التاجر
    /* إبلاغُ الزبون بحالة طلبه يدويًّا — لا يمرّ بميتا، فيعمل والحظرُ قائم */
    /* طلبُ تقييمٍ على Google — بملفّ فرع الطلب، وبعد التسليم وحده */
    /*
     * ورقتا البيع — فاتورةٌ مقصوصة وإيصالُ صندوق.
     *
     * و`pdf` هي الفاتورة: المستندُ الأساسيّ للبيعة، على A4 بلا شرط. وكانت
     * تُخرج ما يقوله مقاسُ القالب، فمن ضبط صندوقَه على ٨٠مم لم تكن له
     * فاتورةٌ أصلًا — انظر `PdfController::orderReceipt`.
     */
    Route::get('/orders/{number}/pdf', [PdfController::class, 'orderReceipt'])->name('orders.pdf');
    Route::get('/orders/{number}/receipt', [PdfController::class, 'orderThermal'])->name('orders.receipt');
    Route::get('/orders/{number}/delivery-note', [DocumentPrintController::class, 'delivery'])->name('orders.deliveryNote');

    // العملات وأسعار الصرف
    Route::get('/currency/{code}/switch', [CurrencyController::class, 'switch'])->name('currency.switch');

    // العملاء
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
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
    Route::get('/customers/{id}/statement', [PdfController::class, 'customerStatement'])->name('customers.statement');
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
    Route::get('/inventory/stocktake', [InventoryController::class, 'stocktake'])->name('inventory.stocktake');
    Route::post('/inventory/stocktake', [InventoryController::class, 'applyStocktake'])->name('inventory.stocktake.apply');
    // التحويل بين الفروع — حركة واحدة بدل «صرف» ثم «إضافة»

    /*
     * تعديلات المخزون وإشعارات التسليم.
     *
     * التعديل يُنقص المخزون ويُقيّد الخسارة معًا: الاكتفاء بتنقيص العدد يُبقي
     * قيمة المخزون في الميزانية كما كانت، فيظهر المتجر أغنى ممّا هو بقيمة كلّ
     * ما تلف عنده. والإشعار مستند حركةٍ لا مال — ولا يمسّ المخزون إن كان
     * مربوطًا بطلبٍ أنقصه يوم البيع.
     */
    Route::get('/inventory/adjustments', [StockAdjustmentController::class, 'index'])->name('inventory.adjustments');
    Route::post('/inventory/adjustments', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
    /*
     * إشعارات الاستلام — قراءةٌ فقط.
     *
     * لا تُكتب بيدٍ ولا تُحذف: تُنشئها لحظةُ استلام أمر الشراء شاهدةً على
     * واقعةٍ جرت. ونموذجٌ يُنشئ إشعارًا بلا استلامٍ يجعل الورقة تقول ما لم
     * يقله المخزون.
     */
    /*
     * سندات النقل بين الفروع — البابُ الذي لم يكن.
     *
     * وتحت `inventory` كأخواتها: من يملك المخزون ينقل بضاعته بين فروعه.
     */
    Route::get('/inventory/receipts', [GoodsReceiptNoteController::class, 'index'])->name('inventory.receipts');
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
    Route::put('/suppliers/{id}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');

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

    // ضريبة القيمة المضافة
    Route::get('/orders/{number}/tax-invoice', [PdfController::class, 'taxInvoice'])->name('orders.taxInvoice');

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
    Route::get('/export/orders', [ExportController::class, 'orders'])->name('export.orders');
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

