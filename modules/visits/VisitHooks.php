<?php
declare(strict_types=1);

namespace App\Modules\Visits;

/**
 * Plug-in point of the department modules into the one-page consultation
 * (Doc 6 §2: a specialty template SUPPLEMENTS the generic EHR, never replaces
 * it). Each module that is installed may:
 *   - validate(array $payload, int $doctorId, int $patientId): string[]  errors
 *   - afterCheckout(int $visitId, array $payload, array $ctx): void      inside the checkout transaction
 *   - screen(array $ctx): array   extra data for the consultation screen (merged into config['modules'])
 */
final class VisitHooks
{
    public const HANDLERS = [
        'App\\Modules\\Lab\\LabHooks',
        'App\\Modules\\Radiology\\RadiologyHooks',
        'App\\Modules\\Pharmacy\\PharmacyHooks',
        'App\\Modules\\Specialty\\SpecialtyHooks',
        'App\\Modules\\Dental\\DentalHooks',
        'App\\Modules\\Obgyn\\ObgynHooks',
    ];

    /** Off while historical demo visits are generated (department activity is created separately). */
    public static bool $enabled = true;

    /** @return string[] */
    public static function validate(array $payload, int $doctorId, int $patientId): array
    {
        $errors = [];
        foreach (self::handlers() as $h) {
            if (method_exists($h, 'validate')) {
                $errors = array_merge($errors, $h::validate($payload, $doctorId, $patientId));
            }
        }
        return $errors;
    }

    public static function afterCheckout(int $visitId, array $payload, array $ctx): void
    {
        foreach (self::handlers() as $h) {
            if (method_exists($h, 'afterCheckout')) {
                $h::afterCheckout($visitId, $payload, $ctx);
            }
        }
    }

    /** Extra consultation-screen data keyed by module. */
    public static function screen(array $ctx): array
    {
        $out = [];
        foreach (self::handlers() as $h) {
            if (method_exists($h, 'screen')) {
                $out = array_merge($out, $h::screen($ctx));
            }
        }
        return $out;
    }

    /** Panels for the visit view page (HTML strings). */
    public static function panels(array $visit): array
    {
        $out = [];
        foreach (self::handlers() as $h) {
            if (method_exists($h, 'panel')) {
                $html = $h::panel($visit);
                if ($html !== '') {
                    $out[] = $html;
                }
            }
        }
        return $out;
    }

    private static function handlers(): array
    {
        if (!self::$enabled) {
            return [];
        }
        return array_values(array_filter(self::HANDLERS, 'class_exists'));
    }
}
