<?php
/** Azimuth conversion tests for the four cardinal directions. */

use SolarPlanner\Forecast\PvModel;

T::group('Azimuth module -> Open-Meteo (0=S,-90=E,+90=W,180=N)');

// Module: 0=N, 90=E, 180=S, 270=W.
T::approx(PvModel::azimuthModuleToOpenMeteo(180), 0.0, 1e-9, 'South: module 180 -> OM 0');
T::approx(PvModel::azimuthModuleToOpenMeteo(90), -90.0, 1e-9, 'East: module 90 -> OM -90');
T::approx(PvModel::azimuthModuleToOpenMeteo(270), 90.0, 1e-9, 'West: module 270 -> OM +90');
T::approx(abs(PvModel::azimuthModuleToOpenMeteo(0)), 180.0, 1e-9, 'North: module 0 -> OM +/-180');
T::approx(abs(PvModel::azimuthModuleToOpenMeteo(360)), 180.0, 1e-9, 'North: module 360 -> OM +/-180');
