<?php
declare(strict_types=1);

namespace App\Modules\Visits;

/**
 * Pure prescription maths — quantity (BRD §41–43), billing (§44) and the
 * source-wise split into the MAIN Rx and the hospital slip (§45 + PHC update).
 * No database access, fully unit-tested. public/assets/js/consult.js mirrors
 * these rules for the live screen; the server always recomputes on checkout.
 */
final class PrescriptionMath
{
    /**
     * Quantity = Dose × Doses-per-day × Days   (daily)
     *          = Dose × Doses-per-week × Weeks (weekly, weeks rounded up)
     * $unitFactor converts the dose unit to the pack's base unit
     * (teaspoon = 5 ml, tablespoon = 15 ml). When a billing unit holds several
     * base units (a 60 ml bottle, a 100-drop bottle) the result is converted
     * to whole billing units, rounded up.
     * Returns null for manual frequencies (SOS / PRN / STAT / Custom).
     */
    public static function quantity(float $dose, float $dosesPerPeriod, string $calcMode, int $days, float $packSize = 1.0, float $unitFactor = 1.0): ?float
    {
        if ($calcMode === 'manual' || $dose <= 0 || $dosesPerPeriod <= 0 || $days <= 0) {
            return null;
        }
        $units = $calcMode === 'weekly'
            ? $dose * $dosesPerPeriod * (int) ceil($days / 7)
            : $dose * $dosesPerPeriod * $days;
        $units *= $unitFactor > 0 ? $unitFactor : 1.0;
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
     * Only lines marked billable (hospital-supplied medicines) are charged.
     * @param array<int, array{quantity: float|int|string, unit_price: float|int|string, billable?: int|bool}> $lines
     * @return array{medicines_total: float, discount_amount: float, tax_amount: float, medicine_net: float, consultation_fee: float, grand_total: float}
     */
    public static function bill(array $lines, string $discountType, float $discountValue, float $taxPercent, float $fee, string $paymentStatus): array
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if (array_key_exists('billable', $line) && !$line['billable']) {
                continue;
            }
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
     * Splits lines into the MAIN Rx and the hospital slip.
     *   - Main Rx (rx_group 1): medicines of exactly ONE source — the main
     *     source (display order 1, or the one chosen in Settings → Rx Split).
     *     Written on the prescription, NOT billed by the hospital.
     *   - Slip (rx_group 2, 3 …): every other source in display order, then
     *     medicines without a source; supplied and billed by the hospital.
     * With splitting disabled everything is one billed Rx.
     *
     * @param array<int, array> $lines each with 'source_id' (int|null)
     * @param array<int, int>   $sourceOrder source_id => display order
     * @param int|null          $mainSourceId null = lowest display order
     * @return array<int, array> lines with rx_group, is_main_rx and billable set (original order kept)
     */
    public static function assignGroups(array $lines, bool $enabled, array $sourceOrder, ?int $mainSourceId = null): array
    {
        if (!$enabled) {
            foreach ($lines as $i => $line) {
                $lines[$i]['rx_group'] = 1;
                $lines[$i]['is_main_rx'] = 1;
                $lines[$i]['billable'] = 1;
            }
            return $lines;
        }
        $main = self::effectiveMain($lines, $sourceOrder, $mainSourceId);

        $others = [];
        foreach ($lines as $line) {
            $sid = (int) ($line['source_id'] ?? 0);
            if ($sid === 0 || $sid !== $main) {
                $others[$sid] = true;
            }
        }
        $others = array_keys($others);
        // Other sources by display order; medicines without a source (0) last.
        usort($others, static fn ($a, $b) => [$a === 0 ? 1 : 0, $sourceOrder[$a] ?? PHP_INT_MAX, $a] <=> [$b === 0 ? 1 : 0, $sourceOrder[$b] ?? PHP_INT_MAX, $b]);
        $groupOf = [];
        foreach ($others as $i => $sid) {
            $groupOf[$sid] = $i + 2;
        }
        foreach ($lines as $i => $line) {
            $sid = (int) ($line['source_id'] ?? 0);
            $isMain = $sid !== 0 && $sid === $main;
            $lines[$i]['rx_group'] = $isMain ? 1 : $groupOf[$sid];
            $lines[$i]['is_main_rx'] = $isMain ? 1 : 0;
            $lines[$i]['billable'] = $isMain ? 0 : 1;
        }
        return $lines;
    }

    /**
     * The main-Rx source for ONE prescription: the configured / order-1 source
     * when the prescription contains it; otherwise the lowest-order source that
     * IS in the prescription, so the main Rx is never left empty.
     * Active sources rank before inactive ones; "no source" is never main.
     */
    public static function effectiveMain(array $lines, array $sourceOrder, ?int $configured): ?int
    {
        $present = [];
        foreach ($lines as $line) {
            $sid = (int) ($line['source_id'] ?? 0);
            if ($sid !== 0) {
                $present[$sid] = true;
            }
        }
        if (!$present) {
            return null;
        }
        $preferred = self::mainSource($sourceOrder, $configured);
        if ($preferred !== null && isset($present[$preferred])) {
            return $preferred;
        }
        $ids = array_keys($present);
        usort($ids, static fn ($a, $b) => [$sourceOrder[$a] ?? PHP_INT_MAX, $a] <=> [$sourceOrder[$b] ?? PHP_INT_MAX, $b]);
        return (int) $ids[0];
    }

    /** The preferred main-Rx source: the configured one, else the lowest display order. */
    public static function mainSource(array $sourceOrder, ?int $configured): ?int
    {
        if ($configured && isset($sourceOrder[$configured])) {
            return $configured;
        }
        if (!$sourceOrder) {
            return null;
        }
        $ids = array_keys($sourceOrder);
        usort($ids, static fn ($a, $b) => [$sourceOrder[$a], $a] <=> [$sourceOrder[$b], $b]);
        return (int) $ids[0];
    }

    /**
     * Groups saved lines for display/print.
     * @return array<int, array{group:int, source_name:string, is_main:bool, lines: array, total: float}>
     */
    public static function groups(array $lines, string $unassignedLabel = 'General'): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $g = (int) ($line['rx_group'] ?? 1);
            if (!isset($groups[$g])) {
                $groups[$g] = ['group' => $g, 'source_name' => $line['source_name'] ?: $unassignedLabel,
                    'is_main' => (int) ($line['is_main_rx'] ?? ($g === 1 ? 1 : 0)) === 1, 'lines' => [], 'total' => 0.0];
            }
            $groups[$g]['lines'][] = $line;
            $groups[$g]['total'] += (float) ($line['line_total'] ?? 0);
        }
        ksort($groups);
        return array_values($groups);
    }
}
