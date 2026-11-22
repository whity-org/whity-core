<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

/**
 * The Jordan pack's chart of accounts and journals (WHIT-1318, design section
 * 32 §4, register AC.8). The default for every workspace until other tax packs
 * exist.
 *
 * REVIEW: Bader Ahmad Tamimi, the Jordanian accountant the owner named,
 * reviews this chart and tunes it after A1 merged (owner, 22 Nov 2026: "yes
 * merge tuning can come later"); the tax codes below wait for his review
 * before the sales tax return ships (A6). Change them here, in one place; a
 * workspace that already opened accounting keeps what it got and edits it
 * itself.
 *
 * Four-digit codes; the first digit is the type. Names are plain words first
 * (AC.27), English and Arabic.
 */
final class JordanChart
{
    public const PACK = 'jo';

    /**
     * code => [parent code, type, subtype, is_group, role, allows_manual, en, ar]
     *
     * Numeric codes become int keys in PHP; read them back with (string).
     *
     * @var array<int|string, array{0: ?string, 1: string, 2: string, 3: bool, 4: ?string, 5: bool, 6: string, 7: string}>
     */
    public const ACCOUNTS = [
        '1000' => [null, 'asset', 'other', true, null, true, 'What we own', 'الأصول'],
        '1100' => ['1000', 'asset', 'cash', false, 'cash', true, 'Cash', 'الصندوق'],
        '1110' => ['1000', 'asset', 'bank', false, 'bank', true, 'Bank', 'البنك'],
        '1150' => ['1000', 'asset', 'current_asset', false, 'cheques_to_collect', true, 'Cheques to collect', 'شيكات برسم التحصيل'],
        '1160' => ['1000', 'asset', 'current_asset', false, 'provider_clearing', true, 'Money at payment providers', 'أموال لدى مزودي الدفع'],
        '1200' => ['1000', 'asset', 'receivable', false, 'receivable', false, 'Customers owe us', 'الذمم المدينة'],
        '1250' => ['1000', 'asset', 'current_asset', false, 'supplier_advances', true, 'Advances to suppliers', 'دفعات مقدمة للموردين'],
        '1300' => ['1000', 'asset', 'inventory', false, 'inventory', false, 'Stock', 'المخزون'],
        '1400' => ['1000', 'asset', 'tax', false, 'sales_tax_input', false, 'Sales tax we can reclaim', 'ضريبة المبيعات على المشتريات'],
        '1500' => ['1000', 'asset', 'fixed_asset', false, null, true, 'Equipment and furniture', 'الأثاث والمعدات'],
        '1590' => ['1000', 'asset', 'fixed_asset', false, null, true, 'Accumulated depreciation', 'مجمع الاستهلاك'],
        '2000' => [null, 'liability', 'other', true, null, true, 'What we owe', 'الالتزامات'],
        '2100' => ['2000', 'liability', 'payable', false, 'payable', false, 'We owe suppliers', 'الذمم الدائنة'],
        '2150' => ['2000', 'liability', 'current_liability', false, 'cheques_written', true, 'Cheques we wrote', 'شيكات آجلة الدفع'],
        '2160' => ['2000', 'liability', 'current_liability', false, 'goods_received_not_billed', false, 'Goods received, not yet billed', 'بضاعة مستلمة لم تصل فواتيرها'],
        '2200' => ['2000', 'liability', 'tax', false, 'sales_tax_output', false, 'Sales tax we collected', 'ضريبة المبيعات المستحقة'],
        '2210' => ['2000', 'liability', 'tax', false, 'sales_tax_payable', false, 'Sales tax to pay', 'ضريبة المبيعات واجبة الدفع'],
        '2250' => ['2000', 'liability', 'current_liability', false, 'customer_advances', true, 'Advances from customers', 'دفعات مقدمة من العملاء'],
        '2300' => ['2000', 'liability', 'current_liability', false, null, true, 'Salaries to pay', 'رواتب مستحقة'],
        '2310' => ['2000', 'liability', 'current_liability', false, null, true, 'Social security to pay', 'اقتطاعات الضمان الاجتماعي المستحقة'],
        '2320' => ['2000', 'liability', 'current_liability', false, null, true, 'Income tax withheld to pay', 'ضريبة دخل مقتطعة مستحقة'],
        '2400' => ['2000', 'liability', 'long_term_liability', false, null, true, 'Loans', 'القروض'],
        '3000' => [null, 'equity', 'other', true, null, true, "Owners' money", 'حقوق الملكية'],
        '3100' => ['3000', 'equity', 'other', false, null, true, 'Capital', 'رأس المال'],
        '3200' => ['3000', 'equity', 'other', false, null, true, "Owners' drawings", 'المسحوبات الشخصية'],
        '3300' => ['3000', 'equity', 'other', false, 'retained_earnings', true, 'Profit kept from past years', 'الأرباح المدورة'],
        '3400' => ['3000', 'equity', 'other', false, 'current_year_earnings', false, "This year's profit", 'أرباح السنة الحالية'],
        '3900' => ['3000', 'equity', 'other', false, 'opening_balance', true, 'Opening balances', 'أرصدة افتتاحية'],
        '4000' => [null, 'income', 'other', true, null, true, 'Income', 'الإيرادات'],
        '4100' => ['4000', 'income', 'other', false, null, true, 'Sales', 'المبيعات'],
        '4200' => ['4000', 'income', 'other', false, null, true, 'Services', 'إيرادات الخدمات'],
        '4900' => ['4000', 'income', 'other', false, null, true, 'Other income', 'إيرادات أخرى'],
        '4910' => ['4000', 'income', 'other', false, 'stock_found', true, 'Stock found', 'زيادة المخزون'],
        '5000' => [null, 'expense', 'cost_of_sales', true, null, true, 'Cost of sales', 'تكلفة المبيعات'],
        '5100' => ['5000', 'expense', 'cost_of_sales', false, 'cost_of_sales', true, 'Cost of goods sold', 'تكلفة البضاعة المباعة'],
        '5150' => ['5000', 'expense', 'cost_of_sales', false, 'stock_loss', true, 'Stock lost and wasted', 'تالف وفاقد المخزون'],
        '5160' => ['5000', 'expense', 'cost_of_sales', false, 'price_difference', true, 'Purchase price differences', 'فروقات أسعار الشراء'],
        '6000' => [null, 'expense', 'other', true, null, true, 'Running costs', 'المصاريف'],
        '6100' => ['6000', 'expense', 'other', false, null, true, 'Salaries', 'الرواتب والأجور'],
        '6110' => ['6000', 'expense', 'other', false, null, true, 'Social security (company share)', 'حصة الشركة من الضمان الاجتماعي'],
        '6200' => ['6000', 'expense', 'other', false, null, true, 'Rent', 'الإيجار'],
        '6300' => ['6000', 'expense', 'other', false, null, true, 'Electricity and water', 'الكهرباء والمياه'],
        '6400' => ['6000', 'expense', 'other', false, null, true, 'Phone and internet', 'الهاتف والإنترنت'],
        '6500' => ['6000', 'expense', 'other', false, null, true, 'Marketing', 'التسويق والإعلان'],
        '6600' => ['6000', 'expense', 'other', false, null, true, 'Fuel and transport', 'المحروقات والمواصلات'],
        '6700' => ['6000', 'expense', 'other', false, 'payment_fees', true, 'Bank and payment fees', 'عمولات بنكية ورسوم الدفع'],
        '6800' => ['6000', 'expense', 'other', false, null, true, 'Software and subscriptions', 'البرمجيات والاشتراكات'],
        '6900' => ['6000', 'expense', 'other', false, null, true, 'Other costs', 'مصاريف أخرى'],
        '6950' => ['6000', 'expense', 'other', false, 'rounding', true, 'Rounding', 'فروقات التقريب'],
        '6960' => ['6000', 'expense', 'other', false, null, true, 'Depreciation', 'الاستهلاك'],
    ];

    /**
     * code => [kind, default account code, en, ar]
     *
     * @var array<string, array{0: string, 1: ?string, 2: string, 3: string}>
     */
    public const JOURNALS = [
        'SAL' => ['sale', null, 'Sales', 'المبيعات'],
        'PUR' => ['purchase', null, 'Purchases', 'المشتريات'],
        'BNK1' => ['bank', '1110', 'Bank', 'البنك'],
        'CSH' => ['cash', '1100', 'Cash', 'الصندوق'],
        'GEN' => ['general', null, 'General', 'القيود العامة'],
        'STK' => ['stock', null, 'Stock', 'المخزون'],
        'OPN' => ['opening', null, 'Opening balances', 'الأرصدة الافتتاحية'],
    ];

    /**
     * The Jordan pack's tax codes (design section 32 §7.2, AC.20), made with
     * the chart. Output codes go on sales, input codes on purchases. An item's
     * `tax_category` picks the code: empty or `standard` is the general 16%.
     * Reduced rates follow Odoo's Jordan list (10% and 4%) until the review.
     *
     * code => [direction, rate in basis points, treatment, category, account role, return box, e-invoice category, en, ar]
     *
     * @var array<string, array{0: string, 1: int, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string, 8: string}>
     */
    public const TAX_CODES = [
        'JO-GST-16' => ['output', 1600, 'standard', 'standard', 'sales_tax_output', 'sales_16', 'S', 'Sales tax 16%', 'ضريبة المبيعات 16%'],
        'JO-GST-10' => ['output', 1000, 'reduced', 'reduced_10', 'sales_tax_output', 'sales_10', 'S', 'Sales tax 10%', 'ضريبة المبيعات 10%'],
        'JO-GST-4' => ['output', 400, 'reduced', 'reduced_4', 'sales_tax_output', 'sales_4', 'S', 'Sales tax 4%', 'ضريبة المبيعات 4%'],
        'JO-GST-0' => ['output', 0, 'zero', 'zero', 'sales_tax_output', 'sales_zero', 'Z', 'Zero-rated', 'خاضع بنسبة صفر'],
        'JO-EXEMPT' => ['output', 0, 'exempt', 'exempt', 'sales_tax_output', 'sales_exempt', 'E', 'Exempt', 'معفى'],
        'JO-GST-IN-16' => ['input', 1600, 'standard', 'standard', 'sales_tax_input', 'purchases_16', 'S', 'Sales tax on purchases 16%', 'ضريبة المشتريات 16%'],
        'JO-GST-IN-10' => ['input', 1000, 'reduced', 'reduced_10', 'sales_tax_input', 'purchases_10', 'S', 'Sales tax on purchases 10%', 'ضريبة المشتريات 10%'],
        'JO-GST-IN-4' => ['input', 400, 'reduced', 'reduced_4', 'sales_tax_input', 'purchases_4', 'S', 'Sales tax on purchases 4%', 'ضريبة المشتريات 4%'],
        'JO-GST-IN-0' => ['input', 0, 'zero', 'zero', 'sales_tax_input', 'purchases_zero', 'Z', 'Zero-rated purchases', 'مشتريات خاضعة بنسبة صفر'],
        'JO-EXEMPT-IN' => ['input', 0, 'exempt', 'exempt', 'sales_tax_input', 'purchases_exempt', 'E', 'Exempt purchases', 'مشتريات معفاة'],
    ];
}
