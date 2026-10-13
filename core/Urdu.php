<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Urdu wording for the patient-facing prescription ("noskha") and the dose
 * units / fractions used by the dose dropdowns. Master tables carry their own
 * editable name_ur; these maps are the defaults and the fall-back.
 */
final class Urdu
{
    public const FREQUENCIES = [
        'OD'     => 'دن میں ایک بار',
        'BD'     => 'دن میں دو بار',
        'TDS'    => 'دن میں تین بار',
        'QID'    => 'دن میں چار بار',
        'HS'     => 'رات سونے سے پہلے',
        'SOS'    => 'ضرورت کے وقت',
        'PRN'    => 'حسبِ ضرورت',
        'Weekly' => 'ہفتے میں ایک بار',
        'STAT'   => 'فوری طور پر ایک خوراک',
        'Custom' => 'ڈاکٹر کی ہدایت کے مطابق',
    ];

    public const ROUTES = [
        'Oral'          => 'منہ کے ذریعے',
        'Intravenous'   => 'ورید (ڈرپ) میں',
        'Intramuscular' => 'پٹھے میں انجیکشن',
        'Subcutaneous'  => 'جلد کے نیچے انجیکشن',
        'Topical'       => 'متاثرہ جگہ پر لگائیں',
        'Inhalation'    => 'سانس کے ذریعے',
        'Sublingual'    => 'زبان کے نیچے',
        'Rectal'        => 'مقعد کے ذریعے',
        'Nasal'         => 'ناک میں',
        'Ophthalmic'    => 'آنکھ میں',
    ];

    /** Plain-English route wording for patients. */
    public const ROUTES_EN = [
        'Intravenous'   => 'into the vein (drip / injection)',
        'Intramuscular' => 'injection into the muscle',
        'Subcutaneous'  => 'injection under the skin',
        'Topical'       => 'apply on the affected area',
        'Inhalation'    => 'inhale',
        'Sublingual'    => 'under the tongue',
        'Rectal'        => 'rectally',
        'Nasal'         => 'in the nose',
        'Ophthalmic'    => 'in the eye',
    ];

    public const INSTRUCTIONS = [
        'After meal'            => 'کھانے کے بعد',
        'Before meal'           => 'کھانے سے پہلے',
        'With water'            => 'پانی کے ساتھ',
        'At bedtime'            => 'رات سونے سے پہلے',
        'On empty stomach'      => 'خالی پیٹ',
        'Apply locally'         => 'متاثرہ جگہ پر لگائیں',
        'As directed'           => 'ہدایت کے مطابق',
        'With milk'             => 'دودھ کے ساتھ',
        'Shake well before use' => 'استعمال سے پہلے اچھی طرح ہلائیں',
        'Dissolve in water'     => 'پانی میں گھول کر',
    ];

    /** Dose unit key => [English label, Urdu label, ml per unit (liquids) or 1]. */
    public const UNITS = [
        'tab'         => ['tablet', 'گولی', 1],
        'cap'         => ['capsule', 'کیپسول', 1],
        'tsp'         => ['teaspoon', 'چھوٹا چمچ', 5],
        'tbsp'        => ['tablespoon', 'بڑا چمچ', 15],
        'ml'          => ['ml', 'ملی لیٹر', 1],
        'drop'        => ['drop', 'قطرے', 1],
        'amp'         => ['ampoule', 'ایمپول', 1],
        'vial'        => ['vial', 'وائل', 1],
        'puff'        => ['puff', 'پف', 1],
        'sachet'      => ['sachet', 'ساشے', 1],
        'application' => ['application', 'مرتبہ لگائیں', 1],
    ];

    /**
     * Dose choices per dosage-form type (BRD update: tablet 1,2,3,4,½,⅓,¼;
     * syrup in teaspoon / tablespoon; drops; injection).
     * type => [units, amounts]
     */
    public const DOSE_CHOICES = [
        'solid'     => [['tab', 'cap'], ['0.25', '0.333', '0.5', '1', '1.5', '2', '3', '4']],
        'liquid'    => [['tsp', 'tbsp', 'ml'], ['0.25', '0.333', '0.5', '1', '1.5', '2', '3', '4']],
        'drops'     => [['drop'], ['1', '2', '3', '4', '5', '6', '8', '10']],
        'injection' => [['amp', 'vial', 'ml'], ['0.25', '0.333', '0.5', '1', '2']],
        'inhaler'   => [['puff'], ['1', '2', '3', '4']],
        'sachet'    => [['sachet'], ['0.5', '1', '2']],
        'topical'   => [['application'], ['1']],
        'other'     => [['tab', 'ml', 'application'], ['0.25', '0.333', '0.5', '1', '2', '3', '4']],
    ];

    public const DOSE_TYPES = [
        'solid'     => 'Tablet / Capsule',
        'liquid'    => 'Syrup / Suspension (spoons)',
        'drops'     => 'Drops',
        'injection' => 'Injection',
        'inhaler'   => 'Inhaler',
        'sachet'    => 'Sachet',
        'topical'   => 'Cream / Ointment / Gel',
        'other'     => 'Other',
    ];

    /** Dose type guessed from a dosage-form name (migration and seed). */
    public static function guessDoseType(string $form): string
    {
        $f = strtolower($form);
        foreach (['tablet' => 'solid', 'capsule' => 'solid', 'syrup' => 'liquid', 'suspension' => 'liquid', 'drop' => 'drops',
                  'injection' => 'injection', 'inhaler' => 'inhaler', 'sachet' => 'sachet', 'cream' => 'topical',
                  'ointment' => 'topical', 'gel' => 'topical', 'lotion' => 'topical'] as $needle => $type) {
            if (str_contains($f, $needle)) {
                return $type;
            }
        }
        return 'other';
    }

    /** Legacy unit text (tab, ml, puff …) => unit key. */
    public static function unitKey(?string $unit): string
    {
        $u = strtolower(trim((string) $unit));
        $aliases = ['tablet' => 'tab', 'tabs' => 'tab', 'capsule' => 'cap', 'caps' => 'cap', 'teaspoon' => 'tsp', 'tablespoon' => 'tbsp',
            'drops' => 'drop', 'ampoule' => 'amp', 'ampule' => 'amp', 'puffs' => 'puff', 'applications' => 'application'];
        $u = $aliases[$u] ?? $u;
        return isset(self::UNITS[$u]) ? $u : ($u === '' ? 'tab' : $u);
    }

    /** 0.5 => "½", 1.5 => "1½", 0.333 => "⅓", 2 => "2" */
    public static function fraction(float $n): string
    {
        $whole = (int) floor($n + 0.0001);
        $rest = round($n - $whole, 3);
        $map = ['0.25' => '¼', '0.333' => '⅓', '0.33' => '⅓', '0.5' => '½', '0.667' => '⅔', '0.67' => '⅔', '0.75' => '¾'];
        $frac = $map[(string) $rest] ?? ($rest > 0 ? ltrim(num($rest), '0') : '');
        if ($whole === 0 && $frac !== '') {
            return $frac;
        }
        return (string) $whole . $frac;
    }
}
