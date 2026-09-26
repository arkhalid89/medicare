<?php
declare(strict_types=1);

namespace App\Modules\Visits;

/**
 * Pure prescription maths — quantity (BRD §41–43), billing (§44) and
 * source-wise Rx grouping (§45). No database access, fully unit-tested.
 * public/assets/js/consult.js mirrors these rules for the live screen; the
 * server always recomputes on checkout, so the stored bill is authoritative.
 */
final class PrescriptionMath
{
    /**
     * Quantity = Dose × Doses-per-day × Days   (daily)
     *          = Dose × Doses-per-week × Weeks (weekly, weeks rounded up)
     * When a billing unit holds several doses (e.g. a 120 ml bottle) the
     * result is converted to whole billing units, rounded up.
     * Returns null for manual frequencies (SOS / PRN / Custom).
     */
    public static function quantity(float $dose, float $dosesPerPeriod, string $calcMode, int $days, float $packSize = 1.0): ?float
    {
        if ($calcMode === 'manual' || $dose <= 0 || $dosesPerPeriod <= 0 || $days <= 0) {
            return null;
        }
        $units = $calcMode === 'weekly'
            ? $dose * $dosesPerPeriod * (int) ceil($days / 7)
            : $dose * $dosesPerPeriod * $days;
        if ($packSize > 1) {
            return (float) ceil(round($units / $packSize, 4));
        }
        return round($units, 2);
    }

    public static function lineTotal(float $quantity, float $unitPrice): float
    {
        return round(max(0, $quantity) * max(0, $unitPrice), 2);
    }

    /**
     * @param array<int, array{quantity: float|int|string, unit_price: float|int|string}> $lines
     * @return array{medicines_total: float, discount_amount: float, tax_amount: float, medicine_net: float, consultation_fee: float, grand_total: float}
     */
    public static function bill(array $lines, string $discountType, float $discountValue, float $taxPercent, float $fee, string $paymentStatus): array
    {
        $total = 0.0;
        foreach ($lines as $line) {
            $total += self::lineTotal((float) $line['quantity'], (float) $line['unit_price']);
        }
        $total = round($total, 2);

        $discountValue = max(0, $discountValue);
        $discount = $discountType === 'percent'
            ? round($total * min(100, $discountValue) / 100, 2)
            : round(min($discountValue, $total), 2);

        $afterDiscount = round($total - $discount, 2);
        $tax = round($afterDiscount * max(0, $taxPercent) / 100, 2);
        $net = round($afterDiscount + $tax, 2);
        $fee = $paymentStatus === 'Free' ? 0.0 : round(max(0, $fee), 2);

        return [
            'medicines_total'  => $total,
            'discount_amount'  => $discount,
            'tax_amount'       => $tax,
            'medicine_net'     => $net,
            'consultation_fee' => $fee,
            'grand_total'      => round($net + $fee, 2),
        ];
    }

    /**
     * Assigns rx_group numbers by medicine source.
     * Groups follow the source display order; lines without a source go last.
     * With splitting disabled, or a single source, everything is Rx 1.
     *
     * @param array<int, array> $lines each with 'source_id' (int|null)
     * @param array<int, int>   $sourceOrder source_id => display order
     * @return array<int, array> lines with 'rx_group' set (original order kept)
     */
    public static function assignGroups(array $lines, bool $enabled, array $sourceOrder): array
    {
        $keys = [];
        foreach ($lines as $line) {
            $keys[self::sourceKey($line['source_id'] ?? null)] = true;
        }
        if (!$enabled || count($keys) <= 1) {
            foreach ($lines as $i => $line) {
                $lines[$i]['rx_group'] = 1;
            }
            return $lines;
        }

        $ordered = array_keys($keys);
        usort($ordered, static function ($a, $b) use ($sourceOrder): int {
            $oa = $a === 0 ? PHP_INT_MAX : ($sourceOrder[$a] ?? PHP_INT_MAX - 1);
            $ob = $b === 0 ? PHP_INT_MAX : ($sourceOrder[$b] ?? PHP_INT_MAX - 1);
            return [$oa, $a] <=> [$ob, $b];
        });
        $groupOf = array_flip($ordered);
        foreach ($lines as $i => $line) {
            $lines[$i]['rx_group'] = $groupOf[self::sourceKey($line['source_id'] ?? null)] + 1;
        }
        return $lines;
    }

    /**
     * Groups saved lines for display/print.
     * @return array<int, array{group:int, source_name:string, lines: array}>
     */
    public static function groups(array $lines, string $unassignedLabel = 'General'): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $g = (int) ($line['rx_group'] ?? 1);
            if (!isset($groups[$g])) {
                $groups[$g] = ['group' => $g, 'source_name' => $line['source_name'] ?: $unassignedLabel, 'lines' => []];
            }
            $groups[$g]['lines'][] = $line;
        }
        ksort($groups);
        return array_values($groups);
    }

    private static function sourceKey(mixed $sourceId): int
    {
        return $sourceId ? (int) $sourceId : 0;
    }
}
