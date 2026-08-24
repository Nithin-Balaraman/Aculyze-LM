<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Makes a ChartWidget clickable, opening a large detail modal (see
 * App\Filament\Widgets\ChartDetailModal and resources/views/filament/
 * widgets/clickable-chart-widget.blade.php) instead of Filament's own,
 * uninteractive chart-widget view. Every affected widget needs:
 * `use HasClickableChartDetail;`, its own
 * `protected static string $view = 'filament.widgets.clickable-chart-widget';`,
 * and a `getChartKey()` implementation — the key ChartDetailModal's own
 * getDetail() switches on to decide what breakdown/summary content to
 * compute and show.
 *
 * `$view` can't live on the trait itself: ChartWidget (the parent every
 * using class extends) already declares its own `$view` with a different
 * default, and PHP's trait-composition rules fatal-error on a property a
 * trait and an inherited parent both declare with conflicting values —
 * only a property declared directly on the class itself resolves that
 * unambiguously.
 *
 * `getChartEmployeeId()` defaults to null (company-wide) since most chart
 * widgets on the admin-only Main Dashboard have no per-employee scoping at
 * all (e.g. GrowthTrendChart, ConversionTrendChart); widgets that DO carry
 * a `$this->employeeId` (the same property/convention KpiBand and the
 * per-employee chart widgets already use, populated via each Dashboard
 * page's own getWidgetData()) override this to return it, so the detail
 * modal queries the same scope the small chart card itself is showing.
 */
trait HasClickableChartDetail
{
    abstract protected function getChartKey(): string;

    protected function getChartEmployeeId(): ?int
    {
        return null;
    }
}
