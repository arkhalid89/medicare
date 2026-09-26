<?php
declare(strict_types=1);

namespace App\Core;

/** Page maths for listings. Page size comes from settings or ?per_page. */
final class Paginator
{
    public const SIZES = [10, 25, 50, 100];

    public int $page;
    public int $perPage;
    public int $total;

    public function __construct(int $total, ?int $perPage = null, ?int $page = null)
    {
        $this->total = max(0, $total);
        $requested = (int) ($_GET['per_page'] ?? 0);
        $this->perPage = $perPage ?? (in_array($requested, self::SIZES, true) ? $requested : max(5, (int) setting('records_per_page', 25)));
        $this->page = max(1, min($page ?? (int) ($_GET['page'] ?? 1), $this->pages()));
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** "LIMIT x OFFSET y" — both are integers, safe to inline. */
    public function limitSql(): string
    {
        return ' LIMIT ' . $this->perPage . ' OFFSET ' . $this->offset();
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : $this->offset() + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->offset() + $this->perPage);
    }
}
