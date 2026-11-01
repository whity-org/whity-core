<?php

declare(strict_types=1);

namespace Whity\Core\Document;

/**
 * The 13 document templates of the guideline (docs/design/16-documents.md,
 * decision 6.8) as editor starters, each in English and in Arabic (WHIT-843).
 *
 * A document has one language, so each is two starters: `editor.invoice` and
 * `editor.invoice.ar`. The other language rides along as small labels where a
 * label stands alone ("Bill to · العميل", 6.4), and the wording follows the
 * document cards (docs/design/components/TplDoc*).
 *
 * Company facts come from the Company fields and the letterhead (the tenant's
 * settings). Everything else is the template's own field, with Example Bakery's
 * values as samples, so a published template never prints example data.
 *
 * What the editor has no block for yet is a field or a plain table here, not an
 * imitation of the real thing: lines are a fixed number of rows, the amount in
 * words and the running balance are typed in, and there is no JoFotara stamp or
 * reference. Nothing here makes a document compliant with JoFotara; section 16's
 * "Before shipping" review is still open.
 */
final class GuidelineStarters
{
    /** starter key without its language => builder. */
    private const DOCUMENTS = [
        'editor.quote' => 'quote',
        'editor.invoice' => 'invoice',
        'editor.credit-note' => 'creditNote',
        'editor.receipt' => 'receipt',
        'editor.statement' => 'statement',
        'editor.payment-reminder' => 'reminder',
        'editor.delivery-note' => 'deliveryNote',
        'editor.payslip' => 'payslip',
        'editor.employment-letter' => 'employmentLetter',
        'editor.leave-approval' => 'leaveApproval',
        'editor.report' => 'report',
        'editor.letter' => 'letter',
        'editor.purchase-order' => 'purchaseOrder',
    ];

    private string $lang = 'en';

    /** @var array<string, array<string, string>> the current starter's own fields, by key */
    private array $own = [];

    /** @param \Closure(): string $id the next block id */
    public function __construct(private readonly \Closure $id)
    {
    }

    /**
     * The starters by key, English then Arabic.
     *
     * @return array<string, array{kind: string, title: string, lang: string, page: array<string, mixed>,
     *                             own_fields: list<array<string, string>>, blocks: list<array<string, mixed>>}>
     */
    public function all(): array
    {
        $out = [];
        foreach (self::DOCUMENTS as $key => $builder) {
            foreach (['en', 'ar'] as $lang) {
                $this->lang = $lang;
                $this->own = [];
                [$title, $blocks, $options] = $this->{$builder}();
                $out[$lang === 'en' ? $key : $key . '.ar'] = [
                    'kind' => $options['kind'] ?? 'template',
                    'title' => $title,
                    'lang' => $lang,
                    'page' => ['size' => $options['size'] ?? 'A4', 'marginMm' => 16, 'letterhead' => true],
                    'own_fields' => array_values($this->own),
                    'blocks' => $blocks,
                ];
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function quote(): array
    {
        return [$this->t('Quote', 'عرض سعر'), [
            $this->facts([
                [['Quote no.', 'رقم العرض'], $this->f('quote_no', 'Quote number', 'رقم العرض', 'QUO-0318')],
                [['Prepared for', 'مقدَّم إلى'], $this->f('customer', 'Customer', 'العميل', 'Example Clinic')],
                [['Valid until', 'صالح حتى'], $this->f('valid_until', 'Valid until', 'صالح حتى', '3 Nov 2026')],
                [['Delivery', 'التسليم'], $this->f('delivery', 'Delivery', 'التسليم', '28 Oct 2026, 10:00')],
            ]),
            $this->lines(4),
            ...$this->totals(),
            $this->labelled(['Terms', 'الشروط'], $this->t('Prices are in Jordanian dinars. This quote is valid until the date above. Delivery is confirmed when the quote is accepted.', 'الأسعار بالدينار الأردني. هذا العرض صالح حتى التاريخ أعلاه. يتأكد التسليم عند قبول العرض.')),
            $this->signature([[['Accepted by', 'قبِله'], null], [['For', 'عن'], [$this->company('company.name', 'Company name', 'اسم الشركة')]]]),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function invoice(): array
    {
        return [$this->t('Tax invoice', 'فاتورة ضريبية'), [
            $this->facts([
                [['Invoice no.', 'رقم الفاتورة'], $this->f('invoice_no', 'Invoice number', 'رقم الفاتورة', 'INV-1042')],
                [['Bill to', 'العميل'], $this->f('bill_to', 'Bill to', 'العميل', 'Example Clinic')],
                [['Customer tax no.', 'الرقم الضريبي للعميل'], $this->f('bill_to_tax_no', 'Customer tax number', 'الرقم الضريبي للعميل', '[000000000]')],
                [['Due', 'تاريخ الاستحقاق'], $this->f('due_date', 'Due date', 'تاريخ الاستحقاق', '10 Nov 2026')],
            ]),
            $this->lines(4),
            ...$this->totals(),
            $this->labelled(['Payment', 'الدفع'], $this->f('payment', 'How to pay', 'طريقة الدفع', 'Bank transfer to [Bank], IBAN [JO00 0000 0000 0000], or CliQ alias [EXAMPLEBAKERY].', 'حوالة بنكية إلى [البنك]، رقم الحساب [JO00 0000 0000 0000]، أو عبر CliQ [EXAMPLEBAKERY].')),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function creditNote(): array
    {
        return [$this->t('Credit note', 'إشعار دائن'), [
            $this->facts([
                [['Credit note no.', 'رقم الإشعار'], $this->f('credit_note_no', 'Credit note number', 'رقم الإشعار', 'CN-0012')],
                [['Customer', 'العميل'], $this->f('customer', 'Customer', 'العميل', 'Example Clinic')],
                [['Original invoice', 'الفاتورة الأصلية'], $this->f('original_invoice', 'Original invoice', 'الفاتورة الأصلية', 'INV-1042')],
                [['Reason', 'السبب'], $this->f('reason', 'Reason', 'السبب', 'One large tray, damaged in delivery', 'صينية كبيرة واحدة تضررت أثناء التسليم')],
            ]),
            $this->lines(2),
            ...$this->totals(['Credit total JOD', 'إجمالي الإشعار (دينار)']),
            $this->labelled(['Balance', 'الرصيد'], $this->f('balance_after', 'Balance after this credit', 'الرصيد بعد هذا الإشعار', 'JOD 203.000 still due on INV-1042', 'JOD 203.000 مستحقة على INV-1042')),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function receipt(): array
    {
        return [$this->t('Receipt', 'سند قبض'), [
            $this->facts([
                [['Receipt no.', 'رقم السند'], $this->f('receipt_no', 'Receipt number', 'رقم السند', 'RCT-0207')],
                [['Received from', 'استلمنا من'], $this->f('received_from', 'Received from', 'استلمنا من', 'Example Clinic')],
                [['Payment method', 'طريقة الدفع'], $this->f('method', 'Payment method', 'طريقة الدفع', 'Bank transfer (CliQ)', 'حوالة بنكية (CliQ)')],
            ]),
            $this->labelled(['Amount received', 'المبلغ المستلَم'], $this->f('amount', 'Amount received', 'المبلغ المستلَم', 'JOD 203.000')),
            $this->para($this->f('amount_in_words', 'Amount in words', 'المبلغ كتابةً', 'Two hundred and three Jordanian dinars', 'مئتان وثلاثة دنانير أردنية')),
            $this->table(
                [['Invoice', 'الفاتورة'], ['Invoice total', 'قيمة الفاتورة'], ['Credited', 'المُرجَع'], ['Paid now', 'المدفوع الآن']],
                [[$this->f('invoice_no', 'Invoice number', 'رقم الفاتورة', 'INV-1042'), $this->f('invoice_total', 'Invoice total', 'قيمة الفاتورة', '301.600'), $this->f('credited', 'Credited', 'المُرجَع', '98.600'), $this->f('paid_now', 'Paid now', 'المدفوع الآن', '203.000')]],
                ['start', 'end', 'end', 'end'],
            ),
            $this->labelled(['Balance on account', 'الرصيد المتبقي'], $this->f('balance', 'Balance on account', 'الرصيد المتبقي', '0.000')),
            // A5: one language, so the role fits on its line.
            $this->signature([[[$this->t('Received by', 'المستلِم'), null], [$this->f('received_by', 'Received by', 'المستلِم', 'Rana Haddad')]]]),
        ], ['size' => 'A5']];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function statement(): array
    {
        $rows = [];
        foreach ([
            ['27 Oct 2026', 'INV-1042', '301.600', '', '721.600'],
            ['29 Oct 2026', 'CN-0012', '', '98.600', '623.000'],
            ['30 Oct 2026', 'RCT-0207', '', '342.000', '281.000'],
            ['', '', '', '', ''],
        ] as $i => $sample) {
            $n = $i + 1;
            $rows[] = [
                $this->f("line_{$n}_date", "Line {$n} date", "تاريخ السطر {$n}", $sample[0]),
                $this->f("line_{$n}_ref", "Line {$n} reference", "مرجع السطر {$n}", $sample[1]),
                $this->f("line_{$n}_debit", "Line {$n} debit", "مدين السطر {$n}", $sample[2]),
                $this->f("line_{$n}_credit", "Line {$n} credit", "دائن السطر {$n}", $sample[3]),
                $this->f("line_{$n}_balance", "Line {$n} balance", "رصيد السطر {$n}", $sample[4]),
            ];
        }

        return [$this->t('Statement of account', 'كشف حساب'), [
            $this->facts([
                [['Statement no.', 'رقم الكشف'], $this->f('statement_no', 'Statement number', 'رقم الكشف', 'ST-2026-10-031')],
                [['Customer', 'العميل'], $this->f('customer', 'Customer', 'العميل', 'Example Clinic')],
                [['Period', 'الفترة'], $this->f('period', 'Period', 'الفترة', '1–31 Oct 2026')],
                [['Opening balance', 'الرصيد الافتتاحي'], $this->f('opening_balance', 'Opening balance', 'الرصيد الافتتاحي', 'JOD 420.000')],
            ]),
            $this->table(
                [['Date', 'التاريخ'], ['Reference', 'المرجع'], ['Debit', 'مدين'], ['Credit', 'دائن'], ['Balance', 'الرصيد']],
                $rows,
                ['start', 'start', 'end', 'end', 'end'],
            ),
            $this->labelled(['Balance due', 'الرصيد المستحق'], $this->f('balance_due', 'Balance due', 'الرصيد المستحق', 'JOD 281.000')),
            $this->para($this->f('amount_in_words', 'Amount in words', 'المبلغ كتابةً', 'Two hundred and eighty-one Jordanian dinars', 'مئتان وواحد وثمانون دينارًا أردنيًا')),
            $this->heading(['Ageing', 'أعمار الرصيد']),
            $this->table(
                [['Not due', 'غير مستحق'], ['1–30 days', '1–30 يوماً'], ['31–60 days', '31–60 يوماً'], ['61–90 days', '61–90 يوماً'], ['Over 90 days', 'أكثر من 90 يوماً']],
                [[$this->f('age_current', 'Not due', 'غير مستحق', '281.000'), $this->f('age_30', '1–30 days', '1–30 يوماً', '0.000'), $this->f('age_60', '31–60 days', '31–60 يوماً', '0.000'), $this->f('age_90', '61–90 days', '61–90 يوماً', '0.000'), $this->f('age_older', 'Over 90 days', 'أكثر من 90 يوماً', '0.000')]],
                ['end', 'end', 'end', 'end', 'end'],
            ),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function reminder(): array
    {
        $rows = [];
        foreach ([['INV-0998', '1 Oct 2026', '15 Oct 2026', '19', '150.000'], ['INV-1007', '8 Oct 2026', '22 Oct 2026', '12', '94.500'], ['', '', '', '', '']] as $i => $sample) {
            $n = $i + 1;
            $rows[] = [
                $this->f("line_{$n}_invoice", "Invoice {$n}", "الفاتورة {$n}", $sample[0]),
                $this->f("line_{$n}_issued", "Invoice {$n} issued", "إصدار الفاتورة {$n}", $sample[1]),
                $this->f("line_{$n}_due", "Invoice {$n} due", "استحقاق الفاتورة {$n}", $sample[2]),
                $this->f("line_{$n}_days", "Invoice {$n} days late", "أيام تأخر الفاتورة {$n}", $sample[3]),
                $this->f("line_{$n}_amount", "Invoice {$n} amount", "مبلغ الفاتورة {$n}", $sample[4]),
            ];
        }

        return [$this->t('Payment reminder', 'تذكير بالدفع'), [
            $this->facts([
                [['Reminder no.', 'رقم التذكير'], $this->f('reminder_no', 'Reminder number', 'رقم التذكير', 'REM-0044')],
                [['To', 'إلى'], $this->f('customer', 'Customer', 'العميل', 'Example Café')],
                [['Please pay by', 'يرجى الدفع قبل'], $this->f('pay_by', 'Please pay by', 'يرجى الدفع قبل', '10 Nov 2026')],
            ]),
            $this->para($this->txt($this->t('Our records show the invoices below are overdue. If you have already paid, please ignore this reminder. If not, please pay by the date above. If something looks wrong, call us and we will sort it out.', 'تُظهر سجلاتنا أن الفواتير أدناه تجاوزت موعد استحقاقها. إذا كنتم قد دفعتم فيرجى تجاهل هذا التذكير، وإلا فيرجى الدفع قبل التاريخ أعلاه. وإذا بدا لكم أي خطأ فاتصلوا بنا لتسويته.'))),
            $this->table(
                [['Invoice', 'الفاتورة'], ['Issued', 'تاريخ الإصدار'], ['Due', 'تاريخ الاستحقاق'], ['Days late', 'أيام التأخير'], ['Amount', 'المبلغ']],
                $rows,
                ['start', 'start', 'start', 'end', 'end'],
            ),
            $this->labelled(['Total overdue JOD', 'إجمالي المتأخر (دينار)'], $this->f('total', 'Total overdue', 'إجمالي المتأخر', '244.500')),
            $this->para($this->f('amount_in_words', 'Amount in words', 'المبلغ كتابةً', 'Two hundred and forty-four Jordanian dinars and five hundred fils', 'مئتان وأربعة وأربعون دينارًا أردنيًا وخمسمئة فلس')),
            $this->labelled(['How to pay', 'طريقة الدفع'], $this->f('payment', 'How to pay', 'طريقة الدفع', 'Bank transfer to [Bank], IBAN [JO00 0000 0000 0000], or CliQ alias [EXAMPLEBAKERY].', 'حوالة بنكية إلى [البنك]، رقم الحساب [JO00 0000 0000 0000]، أو عبر CliQ [EXAMPLEBAKERY].')),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function deliveryNote(): array
    {
        $rows = [];
        foreach ([['Catering tray, large (12 people)', 'صينية ضيافة كبيرة (12 شخصًا)', '2', '2', 'One damaged', 'واحدة متضررة'], ['Pastry box, assorted', 'علبة معجنات مشكلة', '3', '3', '', ''], ['', '', '', '', '', '']] as $i => $s) {
            $n = $i + 1;
            $rows[] = [
                $this->f("line_{$n}_item", "Line {$n} item", "صنف السطر {$n}", $s[0], $s[1]),
                $this->f("line_{$n}_ordered", "Line {$n} ordered", "المطلوب في السطر {$n}", $s[2]),
                $this->f("line_{$n}_delivered", "Line {$n} delivered", "المسلَّم في السطر {$n}", $s[3]),
                $this->f("line_{$n}_notes", "Line {$n} notes", "ملاحظات السطر {$n}", $s[4], $s[5]),
            ];
        }

        return [$this->t('Delivery note', 'إشعار تسليم'), [
            $this->facts([
                [['Delivery note no.', 'رقم الإشعار'], $this->f('delivery_no', 'Delivery note number', 'رقم الإشعار', 'DN-0311')],
                [['Deliver to', 'التسليم إلى'], $this->f('deliver_to', 'Deliver to', 'التسليم إلى', 'Example Clinic')],
                [['Order', 'الطلب'], $this->f('order', 'Order', 'الطلب', 'QUO-0318')],
                [['Delivered', 'وقت التسليم'], $this->f('delivered_at', 'Delivered', 'وقت التسليم', '28 Oct 2026, 10:05')],
            ]),
            $this->table(
                [['Item', 'الصنف'], ['Ordered', 'المطلوب'], ['Delivered', 'المسلَّم'], ['Notes', 'ملاحظات']],
                $rows,
                ['start', 'end', 'end', 'start'],
            ),
            $this->signature([[['Delivered by', 'سلَّمه'], null], [['Received by (name)', 'استلمه (الاسم)'], null]]),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function payslip(): array
    {
        $rows = [];
        foreach ([['Basic salary', 'الراتب الأساسي', '900.000', ''], ['Transport allowance', 'بدل المواصلات', '100.000', ''], ['Social security', 'الضمان الاجتماعي', '', '67.500'], ['Income tax', 'ضريبة الدخل', '', '12.000'], ['', '', '', '']] as $i => $s) {
            $n = $i + 1;
            $rows[] = [
                $this->f("line_{$n}_item", "Line {$n} item", "بند السطر {$n}", $s[0], $s[1]),
                $this->f("line_{$n}_earning", "Line {$n} earning", "استحقاق السطر {$n}", $s[2]),
                $this->f("line_{$n}_deduction", "Line {$n} deduction", "اقتطاع السطر {$n}", $s[3]),
            ];
        }

        return [$this->t('Payslip', 'قسيمة راتب'), [
            $this->callout($this->t('Confidential', 'سري')),
            $this->facts([
                [['Payslip no.', 'رقم القسيمة'], $this->f('payslip_no', 'Payslip number', 'رقم القسيمة', 'PAY-2026-10-014')],
                [['Employee', 'الموظف'], $this->f('employee', 'Employee', 'الموظف', 'Omar Saleh', 'عمر صالح')],
                [['Pay period', 'فترة الراتب'], $this->f('period', 'Pay period', 'فترة الراتب', '1–31 Oct 2026')],
                [['Paid to', 'طريقة الدفع'], $this->f('paid_to', 'Paid to', 'طريقة الدفع', 'Bank transfer, [Bank]', 'حوالة بنكية، [البنك]')],
            ]),
            $this->table(
                [['Item', 'البند'], ['Earnings', 'الاستحقاقات'], ['Deductions', 'الاقتطاعات']],
                [...$rows, [$this->txt($this->t('Totals', 'المجموع')), $this->f('total_earnings', 'Total earnings', 'مجموع الاستحقاقات', '1,000.000'), $this->f('total_deductions', 'Total deductions', 'مجموع الاقتطاعات', '79.500')]],
                ['start', 'end', 'end'],
            ),
            $this->labelled(['Net pay JOD', 'صافي الراتب (دينار)'], $this->f('net_pay', 'Net pay', 'صافي الراتب', '920.500')),
            $this->para($this->f('amount_in_words', 'Amount in words', 'المبلغ كتابةً', 'Nine hundred and twenty Jordanian dinars and five hundred fils', 'تسعمئة وعشرون دينارًا أردنيًا وخمسمئة فلس')),
            $this->heading(['Leave balance 2026', 'رصيد الإجازات 2026']),
            $this->table(
                [['Leave type', 'نوع الإجازة'], ['Yearly', 'الرصيد السنوي'], ['Used', 'المستخدم'], ['Left', 'المتبقي']],
                [
                    [$this->txt($this->t('Annual', 'سنوية')), $this->f('annual_yearly', 'Annual yearly', 'السنوية: الرصيد', '14'), $this->f('annual_used', 'Annual used', 'السنوية: المستخدم', '5'), $this->f('annual_left', 'Annual left', 'السنوية: المتبقي', '9')],
                    [$this->txt($this->t('Sick', 'مرضية')), $this->f('sick_yearly', 'Sick yearly', 'المرضية: الرصيد', '14'), $this->f('sick_used', 'Sick used', 'المرضية: المستخدم', '1'), $this->f('sick_left', 'Sick left', 'المرضية: المتبقي', '13')],
                ],
                ['start', 'end', 'end', 'end'],
            ),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function employmentLetter(): array
    {
        $employee = $this->f('employee', 'Employee', 'الموظف', 'Omar Saleh', 'عمر صالح');
        $job = $this->f('job_title', 'Job title', 'الوظيفة', 'Accountant, full time', 'محاسب، دوام كامل');
        $joined = $this->f('joined', 'Joined', 'تاريخ التعيين', '1 March 2023', '1 آذار 2023');
        $salary = $this->f('salary', 'Monthly salary', 'الراتب الشهري', 'JOD 1,000.000');
        $words = $this->f('salary_in_words', 'Salary in words', 'الراتب كتابةً', 'one thousand Jordanian dinars', 'ألف دينار أردني');
        $company = $this->company('company.name', 'Company name', 'اسم الشركة');

        return [$this->t('Employment letter', 'كتاب تعريف بالعمل'), [
            $this->facts([
                [['Reference', 'المرجع'], $this->f('reference', 'Reference', 'المرجع', 'HR-2026-118')],
                [['Employee no.', 'الرقم الوظيفي'], $this->f('employee_no', 'Employee number', 'الرقم الوظيفي', 'EMP-014')],
            ]),
            $this->para($this->txt($this->t('To whom it may concern', 'إلى من يهمه الأمر'), true)),
            $this->lang === 'ar'
                ? $this->para($this->txt('نشهد نحن '), $company, $this->txt(' بأن '), $employee, $this->txt(' يعمل لدينا بوظيفة '), $job, $this->txt(' منذ '), $joined, $this->txt('، ويتقاضى راتبًا شهريًا مقداره '), $salary, $this->txt(' ('), $words, $this->txt(').'))
                : $this->para($this->txt('We confirm that '), $employee, $this->txt(' has worked at '), $company, $this->txt(' as '), $job, $this->txt(' since '), $joined, $this->txt(', on a monthly salary of '), $salary, $this->txt(' ('), $words, $this->txt(').')),
            $this->para($this->txt($this->t('This letter is given at their request and carries no other obligation.', 'أُعطي هذا الكتاب بناءً على طلبه دون أي مسؤولية أخرى على الشركة.'))),
            $this->signature([[['Human resources', 'الموارد البشرية'], [$this->company('company.name', 'Company name', 'اسم الشركة')]]], true),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function leaveApproval(): array
    {
        $trail = [];
        foreach ([['Omar Saleh', 'عمر صالح', 'Asked for leave', 'طلب إجازة', '18 Oct 2026, 09:12'], ['Rana Haddad', 'رنا حداد', 'Approved', 'وافق', '20 Oct 2026, 11:40'], ['', '', '', '', '']] as $i => $s) {
            $n = $i + 1;
            $trail[] = [
                $this->f("trail_{$n}_who", "Trail {$n} who", "السجل {$n}: مَن", $s[0], $s[1]),
                $this->f("trail_{$n}_what", "Trail {$n} what", "السجل {$n}: ماذا", $s[2], $s[3]),
                $this->f("trail_{$n}_when", "Trail {$n} when", "السجل {$n}: الوقت", $s[4]),
            ];
        }

        return [$this->t('Leave approval', 'موافقة على إجازة'), [
            $this->facts([
                [['Request no.', 'رقم الطلب'], $this->f('request_no', 'Request number', 'رقم الطلب', 'LV-0093')],
                [['Employee', 'الموظف'], $this->f('employee', 'Employee', 'الموظف', 'Omar Saleh', 'عمر صالح')],
                [['Leave', 'الإجازة'], $this->f('leave', 'Leave', 'الإجازة', 'Annual leave, 25–29 Oct 2026', 'إجازة سنوية، 25–29 Oct 2026')],
                [['Back at work', 'العودة إلى العمل'], $this->f('back_at_work', 'Back at work', 'العودة إلى العمل', '1 Nov 2026')],
            ]),
            $this->table(
                [['Item', 'البند'], ['Days', 'الأيام']],
                [
                    [$this->txt($this->t('Working days (Sunday to Thursday)', 'أيام العمل (الأحد إلى الخميس)')), $this->f('working_days', 'Working days', 'أيام العمل', '5')],
                    [$this->txt($this->t('Balance before', 'الرصيد قبل الإجازة')), $this->f('balance_before', 'Balance before', 'الرصيد قبل الإجازة', '14')],
                    [$this->txt($this->t('Balance after', 'الرصيد بعد الإجازة')), $this->f('balance_after', 'Balance after', 'الرصيد بعد الإجازة', '9')],
                ],
                ['start', 'end'],
            ),
            $this->labelled(['Cover', 'البديل'], $this->f('cover', 'Cover', 'البديل', '[Colleague name]', '[اسم الزميل]')),
            $this->heading(['Approval trail', 'سجل الموافقة']),
            $this->table([['Who', 'مَن'], ['What', 'ماذا'], ['When', 'الوقت']], $trail, ['start', 'start', 'start']),
        ], []];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function report(): array
    {
        $rows = [];
        foreach ([['Catering', 'الضيافة', '6,400', '42%'], ['Bread', 'الخبز', '5,300', '35%'], ['Pastry', 'المعجنات', '3,500', '23%'], ['', '', '', '']] as $i => $s) {
            $n = $i + 1;
            $rows[] = [
                $this->f("line_{$n}_item", "Line {$n} item", "بند السطر {$n}", $s[0], $s[1]),
                $this->f("line_{$n}_value", "Line {$n} value", "قيمة السطر {$n}", $s[2]),
                $this->f("line_{$n}_share", "Line {$n} share", "حصة السطر {$n}", $s[3]),
            ];
        }

        return [$this->t('Report', 'تقرير'), [
            $this->facts([
                [['Report no.', 'رقم التقرير'], $this->f('report_no', 'Report number', 'رقم التقرير', 'RPT-2026-10')],
                [['Period', 'الفترة'], $this->f('period', 'Period', 'الفترة', 'October 2026')],
                [['Prepared for', 'مقدَّم إلى'], $this->f('prepared_for', 'Prepared for', 'مقدَّم إلى', 'Rana Haddad', 'رنا حداد')],
                [['Prepared by', 'أعدَّه'], $this->f('prepared_by', 'Prepared by', 'أعدَّه', 'Omar Saleh', 'عمر صالح')],
            ]),
            $this->heading(['Summary', 'الملخص']),
            $this->para($this->f('summary', 'Summary', 'الملخص', 'Sales rose in October, led by catering. Overdue invoices need attention.', 'ارتفعت المبيعات في تشرين الأول بقيادة الضيافة. وتحتاج الفواتير المتأخرة إلى متابعة.')),
            $this->heading(['Key numbers', 'الأرقام الرئيسية']),
            $this->table(
                [['Sales', 'المبيعات'], ['Orders', 'الطلبات'], ['Average order', 'متوسط الطلب'], ['Overdue', 'المتأخر']],
                [[$this->f('kpi_sales', 'Sales', 'المبيعات', 'JOD 15,200'), $this->f('kpi_orders', 'Orders', 'الطلبات', '212'), $this->f('kpi_average', 'Average order', 'متوسط الطلب', 'JOD 71.700'), $this->f('kpi_overdue', 'Overdue', 'المتأخر', 'JOD 429.500')]],
                ['end', 'end', 'end', 'end'],
            ),
            $this->heading(['Sales by line', 'المبيعات حسب البند']),
            $this->table([['Line', 'البند'], ['Sales (JOD)', 'المبيعات (دينار)'], ['Share', 'الحصة']], $rows, ['start', 'end', 'end']),
            $this->heading(['Notes', 'ملاحظات']),
            $this->para($this->f('notes', 'Notes', 'ملاحظات', 'Figures are for all branches and exclude tax.', 'الأرقام لجميع الفروع ولا تشمل الضريبة.')),
        ], ['kind' => 'report']];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function letter(): array
    {
        $recipient = $this->f('recipient', 'Recipient', 'المستلِم', 'Example Clinic');

        return [$this->t('Letter', 'خطاب'), [
            $this->para($recipient, ['type' => 'break'], $this->f('recipient_address', 'Recipient address', 'عنوان المستلِم', 'Rainbow Street 21, Amman', 'شارع الرينبو 21، عمّان')),
            $this->para($this->f('subject', 'Subject', 'الموضوع', 'Your order for November', 'طلبكم لشهر تشرين الثاني', true)),
            $this->para($this->txt($this->t('Dear ', 'السادة ')), $recipient, $this->txt($this->t(',', '،'))),
            $this->para($this->txt($this->t('Thank you for your order. This letter confirms what we agreed and when we will deliver.', 'شكرًا لطلبكم. يؤكد هذا الخطاب ما اتفقنا عليه وموعد التسليم.'))),
            $this->para($this->txt($this->t('Please reply to this letter if anything needs to change before then.', 'يرجى الرد على هذا الخطاب إذا لزم تغيير أي شيء قبل ذلك الموعد.'))),
            $this->para($this->txt($this->t('Kind regards,', 'مع التحية،'))),
            $this->signature([[[$this->t('Manager', 'المدير'), null], [$this->company('company.name', 'Company name', 'اسم الشركة')]]]),
        ], ['kind' => 'letter']];
    }

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: array<string, string>} */
    private function purchaseOrder(): array
    {
        return [$this->t('Purchase order', 'أمر شراء'), [
            $this->facts([
                [['Order no.', 'رقم الأمر'], $this->f('order_no', 'Order number', 'رقم الأمر', 'PO-0057')],
                [['Supplier', 'المورِّد'], $this->f('supplier', 'Supplier', 'المورِّد', 'Example Mills')],
                [['Deliver to', 'التسليم إلى'], $this->f('deliver_to', 'Deliver to', 'التسليم إلى', '[Street], Amman', '[الشارع]، عمّان')],
                [['Deliver by', 'التسليم قبل'], $this->f('deliver_by', 'Deliver by', 'التسليم قبل', '27 Oct 2026')],
            ]),
            $this->lines(4),
            ...$this->totals(),
            $this->labelled(['Terms', 'الشروط'], $this->f('terms', 'Terms', 'الشروط', 'Payment 30 days after invoice. Please quote this order number on your invoice.', 'الدفع بعد 30 يومًا من الفاتورة. يرجى ذكر رقم هذا الأمر في فاتورتكم.')),
            $this->signature([[['Approved by', 'وافق عليه'], null]]),
        ], []];
    }

    /**
     * The line table of a quote, invoice, credit note or purchase order: a fixed number of rows.
     *
     * @return array<string, mixed>
     */
    private function lines(int $count): array
    {
        $samples = [
            ['Catering tray, large (12 people)', 'صينية ضيافة كبيرة (12 شخصًا)', '2', '85.000', '170.000'],
            ['Catering tray, small (6 people)', 'صينية ضيافة صغيرة (6 أشخاص)', '1', '45.000', '45.000'],
            ['Pastry box, assorted', 'علبة معجنات مشكلة', '3', '12.500', '37.500'],
            ['Delivery, Amman', 'توصيل، عمّان', '1', '7.500', '7.500'],
        ];
        $rows = [];
        for ($n = 1; $n <= $count; $n++) {
            $s = $samples[$n - 1];
            $rows[] = [
                $this->f("line_{$n}_description", "Line {$n} description", "وصف السطر {$n}", $s[0], $s[1]),
                $this->f("line_{$n}_qty", "Line {$n} quantity", "كمية السطر {$n}", $s[2]),
                $this->f("line_{$n}_unit_price", "Line {$n} unit price", "سعر وحدة السطر {$n}", $s[3]),
                $this->f("line_{$n}_amount", "Line {$n} amount", "مبلغ السطر {$n}", $s[4]),
            ];
        }

        return $this->table(
            [['Description', 'الوصف'], ['Qty', 'الكمية'], ['Unit price', 'سعر الوحدة'], ['Amount', 'المبلغ']],
            $rows,
            ['start', 'end', 'end', 'end'],
        );
    }

    /**
     * Subtotal, tax, total and the amount in words. The tax rate is the
     * template's own field: it comes from the accountant, not from here.
     *
     * @param array{0: string, 1: string} $total
     * @return list<array<string, mixed>>
     */
    private function totals(array $total = ['Total JOD', 'الإجمالي (دينار)']): array
    {
        $table = $this->table(
            [['Subtotal', 'المجموع'], ['Sales tax', 'ضريبة المبيعات'], [$total[0], $total[1]]],
            [[$this->f('subtotal', 'Subtotal', 'المجموع', '260.000'), $this->f('tax', 'Sales tax', 'ضريبة المبيعات', '41.600'), $this->f('total', 'Total', 'الإجمالي', '301.600', null, true)]],
            ['end', 'end', 'end'],
        );

        return [$table, $this->para($this->f('amount_in_words', 'Amount in words', 'المبلغ كتابةً', 'Three hundred and one Jordanian dinars and six hundred fils', 'ثلاثمئة وواحد دينار أردني وستمئة فلس'))];
    }

    /**
     * Who, when and the other parties, as one header row and one row of values.
     *
     * @param list<array{0: array{0: string, 1: string}, 1: array<string, mixed>}> $items label => inline value
     * @return array<string, mixed>
     */
    private function facts(array $items): array
    {
        return $this->table(
            array_map(static fn (array $item): array => $item[0], $items),
            [array_map(static fn (array $item): array => $item[1], $items)],
            array_fill(0, count($items), 'start'),
        );
    }

    /**
     * A bold label (with its small label in the other language), then its value.
     *
     * @param array{0: string, 1: string} $label
     * @param array<string, mixed>|string $value
     * @return array<string, mixed>
     */
    private function labelled(array $label, array|string $value): array
    {
        return $this->para($this->txt($this->dual($label), true), ['type' => 'break'], is_string($value) ? $this->txt($value) : $value);
    }

    /**
     * @param array{0: string, 1: string} $label
     * @return array<string, mixed>
     */
    private function heading(array $label): array
    {
        return ['id' => ($this->id)(), 'type' => 'heading', 'level' => 2, 'content' => [$this->txt($this->dual($label))]];
    }

    /** @return array<string, mixed> */
    private function callout(string $text): array
    {
        return ['id' => ($this->id)(), 'type' => 'callout', 'kind' => 'important', 'content' => [$this->txt($text)]];
    }

    /**
     * A signature row. Each signer is [role label, name inline or null to sign by hand].
     *
     * @param list<array{0: array{0: string, 1: string|null}, 1: list<array<string, mixed>>|null}> $signers
     * @return array<string, mixed>
     */
    private function signature(array $signers, bool $stamp = false): array
    {
        return [
            'id' => ($this->id)(),
            'type' => 'signature',
            'signers' => array_map(fn (array $s): array => [
                'name' => $s[1] ?? [],
                'role' => [$this->txt($s[0][1] === null ? $s[0][0] : $this->dual($s[0]))],
            ], $signers),
            'date' => [],
            'stamp' => $stamp,
        ];
    }

    /**
     * A table with a header row; each cell is one inline node.
     *
     * @param list<array{0: string, 1: string}> $header
     * @param list<list<array<string, mixed>>> $rows
     * @param list<'start'|'end'> $align
     * @return array<string, mixed>
     */
    private function table(array $header, array $rows, array $align): array
    {
        return [
            'id' => ($this->id)(),
            'type' => 'table',
            'headerRow' => true,
            'columns' => array_map(static fn (string $a): array => ['align' => $a], $align),
            'rows' => [
                array_map(fn (array $label): array => [$this->txt($this->dual($label))], $header),
                ...array_map(static fn (array $row): array => array_map(static fn (array $cell): array => [$cell], $row), $rows),
            ],
        ];
    }

    /**
     * @param array<string, mixed> ...$content
     * @return array<string, mixed>
     */
    private function para(array ...$content): array
    {
        return ['id' => ($this->id)(), 'type' => 'paragraph', 'content' => $content];
    }

    /** @return array<string, mixed> */
    private function txt(string $text, bool $bold = false): array
    {
        return ['type' => 'text', 'text' => $text] + ($bold ? ['marks' => [['type' => 'bold']]] : []);
    }

    /**
     * The template's own field, in this starter's language. Registered once per key.
     *
     * @return array<string, mixed>
     */
    private function f(string $key, string $en, string $ar, string $sample, ?string $sampleAr = null, bool $bold = false): array
    {
        $label = $this->t($en, $ar);
        $this->own[$key] = ['key' => $key, 'label' => $label, 'sample' => $this->lang === 'ar' ? ($sampleAr ?? $sample) : $sample];

        return ['type' => 'field', 'path' => 'template.' . $key, 'label' => $label] + ($bold ? ['marks' => [['type' => 'bold']]] : []);
    }

    /**
     * A Company or Today field.
     *
     * @return array<string, mixed>
     */
    private function company(string $path, string $en, string $ar): array
    {
        return ['type' => 'field', 'path' => $path, 'label' => $this->t($en, $ar)];
    }

    private function t(string $en, string $ar): string
    {
        return $this->lang === 'ar' ? $ar : $en;
    }

    /**
     * A label in this starter's language with the other language beside it
     * (6.4). A table cell has no small type yet, so it is one line.
     *
     * @param array{0: string, 1: string|null} $label
     */
    private function dual(array $label): string
    {
        return $this->lang === 'ar' ? $label[1] . ' · ' . $label[0] : $label[0] . ' · ' . $label[1];
    }
}
