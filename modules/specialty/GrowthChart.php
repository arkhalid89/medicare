<?php
declare(strict_types=1);

namespace App\Modules\Specialty;

/**
 * Approximate WHO Child Growth Standards (0–60 months): medians at key ages,
 * interpolated, with a fixed coefficient of variation. Good enough to flag a
 * child below the 3rd / above the 97th centile on screen; the printed WHO
 * chart remains the reference.
 */
final class GrowthChart
{
    private const MONTHS = [0, 3, 6, 9, 12, 18, 24, 36, 48, 60];
    private const WEIGHT = [
        'M' => [3.3, 6.4, 7.9, 8.9, 9.6, 10.9, 12.2, 14.3, 16.3, 18.3],
        'F' => [3.2, 5.8, 7.3, 8.2, 8.9, 10.2, 11.5, 13.9, 16.1, 18.2],
    ];
    private const HEIGHT = [
        'M' => [49.9, 61.4, 67.6, 72.0, 75.7, 82.3, 87.8, 96.1, 103.3, 110.0],
        'F' => [49.1, 59.8, 65.7, 70.1, 74.0, 80.7, 86.4, 95.1, 102.7, 109.4],
    ];
    private const CV_WEIGHT = 0.12;
    private const CV_HEIGHT = 0.037;

    /** @return array{weight_pct?: float, height_pct?: float, weight_z?: float, height_z?: float} */
    public static function percentiles(string $gender, float $months, ?float $weight, ?float $height): array
    {
        if ($months < 0 || $months > 60) {
            return [];
        }
        $sex = $gender === 'Female' ? 'F' : 'M';
        $out = [];
        if ($weight !== null) {
            $m = self::median(self::WEIGHT[$sex], $months);
            $z = ($weight - $m) / ($m * self::CV_WEIGHT);
            $out += ['weight_z' => round($z, 2), 'weight_pct' => round(self::cdf($z) * 100, 1), 'weight_median' => round($m, 1)];
        }
        if ($height !== null) {
            $m = self::median(self::HEIGHT[$sex], $months);
            $z = ($height - $m) / ($m * self::CV_HEIGHT);
            $out += ['height_z' => round($z, 2), 'height_pct' => round(self::cdf($z) * 100, 1), 'height_median' => round($m, 1)];
        }
        return $out;
    }

    /** Median curve points for drawing (months => value). */
    public static function curve(string $gender, string $measure): array
    {
        $sex = $gender === 'Female' ? 'F' : 'M';
        return array_combine(self::MONTHS, $measure === 'height' ? self::HEIGHT[$sex] : self::WEIGHT[$sex]);
    }

    public static function cv(string $measure): float
    {
        return $measure === 'height' ? self::CV_HEIGHT : self::CV_WEIGHT;
    }

    private static function median(array $values, float $months): float
    {
        foreach (self::MONTHS as $i => $m) {
            if ($months <= $m) {
                if ($i === 0) {
                    return $values[0];
                }
                $m0 = self::MONTHS[$i - 1];
                return $values[$i - 1] + ($values[$i] - $values[$i - 1]) * ($months - $m0) / ($m - $m0);
            }
        }
        return end($values);
    }

    /** Standard normal CDF (Abramowitz–Stegun 7.1.26). */
    private static function cdf(float $z): float
    {
        $t = 1 / (1 + 0.3275911 * abs($z) / M_SQRT2);
        $erf = 1 - ((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-($z * $z) / 2);
        return $z >= 0 ? (1 + $erf) / 2 : (1 - $erf) / 2;
    }
}
