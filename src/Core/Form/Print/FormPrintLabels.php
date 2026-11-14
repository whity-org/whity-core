<?php

declare(strict_types=1);

namespace Whity\Core\Form\Print;

/**
 * The words a form printout prints, in English and Arabic (design 3.4,
 * `DocFormPrintout`, `DocFormPrintoutMore`).
 *
 * In code rather than the request's catalogue because a printout's language is
 * the printout's, not the caller's: the worker that makes a final document has
 * no request, and a preview asked for in Arabic by an English-speaking admin
 * still prints in Arabic.
 *
 * Pure and stateless.
 */
final class FormPrintLabels
{
    private const WORDS = [
        'en' => [
            'notAnswered' => 'Not answered',
            'notApplicable' => 'Not applicable to this project',
            'allNa' => 'All {n} questions answered N/A: {names}.',
            'continued' => 'continued',
            'wordsOf' => '{n} of {max} words',
            'words' => '{n} words',
            'yes' => 'Yes',
            'no' => 'No',
            'na' => 'N/A',
            'item' => 'Item',
            'optional' => 'Optional.',
            'submittedBy' => 'Submitted through {workspace}’s Whity by {name} on {date}.',
            'submittedAnon' => 'Submitted through {workspace}’s Whity on {date}.',
            'approved' => 'Approved',
            'rejected' => 'Not approved',
            'revisions' => 'Revisions required',
            'waiting' => 'Waiting for a decision',
            'reference' => 'Reference',
            'received' => 'Received',
            'decided' => 'Decided',
            'decision' => 'Decision',
            'project' => 'Title',
            'applicant' => 'Submitted by',
            'conditions' => 'Conditions',
            'none' => 'None',
            'fullDecision' => 'Full decision and approvals on the last page',
            'committeeDecision' => 'Decision',
            'approvals' => 'Approvals',
            'approvedWord' => 'Approved',
            'approvedInWhity' => 'Approved in Whity',
            'notCounted' => 'Not counted: {names}. People named on a form as its supervisors never decide on it.',
            'namedAs' => '{name}, named as {question}',
            'confirmed' => 'Confirmed in Whity · {at}',
            'notConfirmed' => 'Not confirmed yet',
            'noDrawing' => 'No drawn signature',
            'signature' => 'Signature · {name}',
            'date' => 'Date',
            'scanToVerify' => 'Scan to verify',
            'officeUse' => 'For official use',
            'by' => 'By',
            'answer' => 'Answer',
            'officeStrip' => 'For office use',
            'filesAttached' => '{n} file attached|{n} files attached',
        ],
        'ar' => [
            'notAnswered' => 'لم تتم الإجابة',
            'notApplicable' => 'لا ينطبق على هذا المشروع',
            'allNa' => 'أُجيب عن الأسئلة الـ{n} كلها بـ«لا ينطبق»: {names}.',
            'continued' => 'تتمة',
            'wordsOf' => '{n} من {max} كلمة',
            'words' => '{n} كلمة',
            'yes' => 'نعم',
            'no' => 'لا',
            'na' => 'لا ينطبق',
            'item' => 'البند',
            'optional' => 'اختياري.',
            'submittedBy' => 'قدّمه {name} عبر Whity لدى {workspace} في {date}.',
            'submittedAnon' => 'قُدّم عبر Whity لدى {workspace} في {date}.',
            'approved' => 'تمت الموافقة',
            'rejected' => 'لم تتم الموافقة',
            'revisions' => 'مطلوب تعديلات',
            'waiting' => 'بانتظار القرار',
            'reference' => 'المرجع',
            'received' => 'الاستلام',
            'decided' => 'القرار',
            'decision' => 'القرار',
            'project' => 'العنوان',
            'applicant' => 'مقدّم الطلب',
            'conditions' => 'الشروط',
            'none' => 'لا يوجد',
            'fullDecision' => 'القرار الكامل والموافقات في الصفحة الأخيرة',
            'committeeDecision' => 'القرار',
            'approvals' => 'الموافقات',
            'approvedWord' => 'موافقة',
            'approvedInWhity' => 'وافق في Whity',
            'notCounted' => 'لم يُحتسب: {names}. من يُسمّى في النموذج مشرفًا عليه لا يصوّت عليه.',
            'namedAs' => '{name}، المُسمّى في «{question}»',
            'confirmed' => 'أُكّد في Whity · {at}',
            'notConfirmed' => 'لم يُؤكَّد بعد',
            'noDrawing' => 'لا توقيع مرسوم',
            'signature' => 'التوقيع · {name}',
            'date' => 'التاريخ',
            'scanToVerify' => 'امسح للتحقق',
            'officeUse' => 'للاستخدام الرسمي',
            'by' => 'من',
            'answer' => 'الإجابة',
            'officeStrip' => 'لاستخدام المكتب',
            'filesAttached' => 'مرفق {n} ملف|مرفقة {n} ملفات',
        ],
    ];

    private const MONTHS = [
        'en' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        'ar' => ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'],
    ];

    public readonly string $lang;

    public function __construct(string $lang)
    {
        $this->lang = $lang === 'ar' ? 'ar' : 'en';
    }

    /** @param array<string, string|int> $with */
    public function get(string $key, array $with = []): string
    {
        $text = self::WORDS[$this->lang][$key] ?? self::WORDS['en'][$key] ?? $key;
        foreach ($with as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /** "1 file attached" or "3 files attached". */
    public function files(int $n): string
    {
        [$one, $many] = explode('|', $this->get('filesAttached'));

        return str_replace('{n}', (string) $n, $n === 1 ? $one : $many);
    }

    /**
     * A date as "12 Nov 2026", with Western digits in both languages (CLAUDE.md:
     * numbers stay Western), or with the time as "12 Nov 2026, 10:14".
     */
    public function date(?string $iso, bool $withTime = false): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        $ts = strtotime($iso);
        if ($ts === false) {
            return $iso;
        }
        $text = gmdate('j', $ts) . ' ' . self::MONTHS[$this->lang][(int) gmdate('n', $ts) - 1] . ' ' . gmdate('Y', $ts);

        return $withTime ? $text . ($this->lang === 'ar' ? '، ' : ', ') . gmdate('H:i', $ts) : $text;
    }

    /** A localized {en, ar} label in this printout's language, falling back to the other. */
    public function pick(mixed $label): string
    {
        if (is_string($label)) {
            return $label;
        }
        if (!is_array($label)) {
            return '';
        }
        foreach ([$this->lang, 'en', 'ar'] as $code) {
            if (is_string($label[$code] ?? null) && trim($label[$code]) !== '') {
                return trim($label[$code]);
            }
        }
        foreach ($label as $text) {
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return '';
    }
}
