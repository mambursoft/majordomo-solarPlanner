<?php
/**
 * ExplanationBuilder — produce a human-readable, reproducible explanation.
 *
 * The explanation must be reproducible from the archived inputs and must not
 * invent a percentage "confidence" that is not calibrated on history.
 *
 * PHP 7.3 compatible.
 *
 * @package solarplanner
 * @license MIT
 */

namespace SolarPlanner\Planning;

class ExplanationBuilder
{
    /**
     * @param array $plan output of NightChargePlanner::plan()
     * @param array $ctx context: forecast_quality, active_providers,
     *        unavailable_providers, tou_changed (bool)
     * @return array ['text'=>string,'lines'=>array]
     */
    public static function build(array $plan, array $ctx = array())
    {
        $lines = array();

        if (isset($plan['status']) && $plan['status'] === 'infeasible') {
            $lines[] = 'План недосяжний за поточними обмеженнями.';
            $lines[] = 'Причина: ' . (isset($plan['reason']) ? $plan['reason'] : 'невідомо') . '.';
            if (isset($plan['min_expected_day_import_kwh'])) {
                $lines[] = sprintf('Мінімальний очікуваний денний імпорт: %.2f кВт·год.', $plan['min_expected_day_import_kwh']);
            }
            if (isset($plan['suggested_safe_compromise_soc'])) {
                $lines[] = sprintf('Запропонований безпечний компроміс TargetSocAt07: %.0f%%.', $plan['suggested_safe_compromise_soc']);
            }
            $lines[] = 'Резерв не знижено автоматично. Потрібне ручне підтвердження зміни політики резерву.';
            return array('text' => implode(' ', $lines), 'lines' => $lines);
        }

        $lines[] = sprintf('Чому саме TargetSocAt07 = %.1f%%:', $plan['target_soc_at_07']);
        $lines[] = sprintf('— нічний заряд з мережі: %.2f кВт·год;', $plan['planned_grid_charge_kwh']);
        $lines[] = sprintf('— загальний нічний імпорт: %.2f кВт·год;', $plan['planned_night_import_kwh']);
        $lines[] = sprintf('— очікуваний денний імпорт: %.2f кВт·год;', $plan['expected_day_import_kwh']);
        $lines[] = sprintf('— резерв утримано на %.0f%%;', $plan['reserve_soc']);

        if (!empty($plan['full_reached'])) {
            $lines[] = '— повний заряд досяжний у заданому вікні завдяки PV;';
        } else {
            $lines[] = '— ризик недосягнення 100% за центральним прогнозом;';
        }
        if ($plan['expected_curtailment_kwh'] > 0.05) {
            $lines[] = sprintf('— можливий невикористаний надлишок ~%.2f кВт·год (перевірити керовані навантаження/експорт);',
                $plan['expected_curtailment_kwh']);
        }
        if (!empty($ctx['unavailable_providers'])) {
            $lines[] = '— недоступні джерела: ' . implode(', ', $ctx['unavailable_providers']) . ';';
        }
        if (!empty($ctx['forecast_quality'])) {
            $lines[] = '— рівень довіри прогнозу: ' . $ctx['forecast_quality'] . ';';
        }
        if (!empty($ctx['tou_changed'])) {
            $lines[] = '— план змінився після компіляції TOU (виконано повторну симуляцію).';
        }

        return array('text' => implode(' ', $lines), 'lines' => $lines);
    }
}
