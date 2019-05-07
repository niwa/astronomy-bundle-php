<?php

namespace Andrmoel\AstronomyBundle\Eclipses;

use Andrmoel\AstronomyBundle\Location;
use Andrmoel\AstronomyBundle\TimeOfInterest;

class SolarEclipse
{
    const TYPE_NONE = 'none';
    const TYPE_PARTIAL = 'partial';
    const TYPE_ANNULAR = 'annular';
    const TYPE_TOTAL = 'total';

    const EVENT_C1 = 'c1';
    const EVENT_C2 = 'c2';
    const EVENT_MAX = 'max';
    const EVENT_C3 = 'c3';
    const EVENT_C4 = 'c4';

    const EVENT_VISIBILITY_ABOVE_HORIZON = 0;
    const EVENT_VISIBILITY_BELOW_HORIZON = 1;
    const EVENT_VISIBILITY_SUNRISE = 2;
    const EVENT_VISIBILITY_SUNSET = 3;
    const EVENT_VISIBILITY_BELOW_HORIZON_DISREGARD = 4;

    const gRefractionHeight = -0.00524; // Take Sun radius into account

    private $besselianElements = array();

    private $dT = 0.0;

    // Observer
    /** @var Location */
    private $location;


    public function __construct(BesselianElements $besselianElements)
    {
        $this->besselianElements = $besselianElements;
        $this->dT = $besselianElements->getDeltaT();

        $this->location = new Location();
    }

    public function setLocation(Location $location): void
    {
        $this->location = $location;

        $this->lat = $location->getLatitude();
        $this->lon = $location->getLongitude();
    }

    public function getTimeOfInterest(SolarEclipseCircumstances $circumstances = null): TimeOfInterest
    {
        if (!isset($circumstances)) {
            $circumstances = $this->getCircumstancesMax();
        }

        // Time of greatest eclipse
        $tMax = $this->besselianElements->getTMax();
        $t0 = $this->besselianElements->getT0();
        $deltaT = $this->besselianElements->getDeltaT();

        $t = $circumstances->getT();

        // JD for noon (TDT) the day before the day that contains T0
        $jd = floor($tMax - $t0 / 24.0);

        // Local time (ie the offset in hours since midnight TDT on the day containing T0) to the nearest 0.1 sec
        $t = $t + $t0 - (($deltaT - 0.05) / 3600.0);

        if ($t < 0.0) {
            $jd--;
        } elseif ($t >= 24.0) {
            $jd++;
        }

        $jd += ($t + 12) / 24;

        $toi = new TimeOfInterest();
        $toi->setJulianDay($jd);

        return $toi;
    }

    public function getEclipseType(SolarEclipseCircumstances $circumstances = null): string
    {
        if (!isset($circumstances)) {
            $circumstances = $this->getCircumstancesMax();
        }

        $l2s = $circumstances->getL2s();
        $m = $circumstances->getM();
        $magnitude = $circumstances->getMagnitude();

        // Check if sun is under horizon
        if ($circumstances->getSunAltitude() < 0) {
            return self::TYPE_NONE;
        }

        if ($magnitude > 0.0) {
            if (($m < $l2s) || ($m < -$l2s)) {
                if ($l2s < 0.0) {
                    return self::TYPE_TOTAL;
                } else {
                    return self::TYPE_ANNULAR;
                }
            } else {
                return self::TYPE_PARTIAL;
            }
        } else {
            return self::TYPE_NONE;
        }
    }

    /**
     * Get eclipse duration in seconds
     */
    public function getEclipseDuration(): float
    {
        $c1 = $this->getCircumstancesC1();
        $c4 = $this->getCircumstancesC4();

        $duration = $c4->getT() - $c1->getT();

        if ($duration < 0.0) {
            $duration += 24.0;
        } elseif ($duration >= 24.0) {
            $duration -= 24.0;
        }

        $duration = $duration * 3600;

        return $duration;
    }

    /**
     * Get eclipse duration in umbra in seconds
     */
    public function getEclipseUmbraDuration(): float
    {
        $c2 = $this->getCircumstancesC2();
        $c3 = $this->getCircumstancesC3();

        $duration = $c3->getT() - $c2->getT();

        if ($duration < 0.0) {
            $duration += 24.0;
        } elseif ($duration >= 24.0) {
            $duration -= 24.0;
        }

        $duration = $duration * 3600;

        return $duration;
    }

    public function getCoverage(SolarEclipseCircumstances $circumstances = null): float
    {
        if (!isset($circumstances)) {
            $circumstances = $this->getCircumstancesMax();
        }

        $type = $this->getEclipseType($circumstances);
        $l1s = $circumstances->getL1s();
        $l2s = $circumstances->getL2s();
        $m = $circumstances->getM();
        $magnitude = $circumstances->getMagnitude();
        $moonSunRatio = $circumstances->getMoonSunRatio();

        if ($magnitude <= 0.0) {
            return 0.0; // No eclipse
        } elseif ($magnitude >= 1.0) {
            return 1.0; // Total eclipse
        }

        if ($type == self::TYPE_ANNULAR) {
            $coverage = pow($moonSunRatio, 2);
        } else {
            $c = acos((pow($l1s, 2) + pow($l2s, 2) - 2.0 * pow($m, 2)) / (pow($l1s, 2) - pow($l2s, 2)));
            $b = acos(($l1s * $l2s + pow($m, 2)) / $m / ($l1s + $l2s));
            $a = M_PI - $b - $c;

            $coverage = (pow($moonSunRatio, 2) * $a + $b - $moonSunRatio * sin($c)) / M_PI;
        }

        return $coverage;
    }

    // ---- Calculate eclipse circumstances ----------------------------------------------------------------------------

    public function getCircumstancesMax(): SolarEclipseCircumstances
    {
        $t = 0.0;
        $tmp = 1.0;

        $circumstances = $this->getTimeDependentCircumstances(self::EVENT_MAX, $t);

        $cnt = 0;
        while ($tmp > 0.000001 || $tmp < -0.000001 && $cnt < 50) {
            $u = $circumstances->getU();
            $v = $circumstances->getV();
            $a = $circumstances->getA();
            $b = $circumstances->getB();
            $n2 = $circumstances->getN2();

            $tmp = ($u * $a + $v * $b) / $n2;
            $t -= $tmp;

            $circumstances = $this->getTimeDependentCircumstances(self::EVENT_MAX, $t);
            $cnt++;
        }

        // m, magnitude & moon sun ratio
        $u = $circumstances->getU();
        $v = $circumstances->getV();
        $l1s = $circumstances->getL1s();
        $l2s = $circumstances->getL2s();

        $m = sqrt(pow($u, 2) + pow($v, 2));
        $magnitude = ($l1s - $m) / ($l1s + $l2s);
        $moonSunRatio = ($l1s - $l2s) / ($l1s + $l2s);

        $circumstances->setM($m);
        $circumstances->setMagnitude($magnitude);
        $circumstances->setMoonSunRatio($moonSunRatio);

        $this->getObservationalCircumstances(self::EVENT_MAX, $circumstances);

        return $circumstances;
    }

    public function getCircumstancesC1(): SolarEclipseCircumstances
    {
        $circumstancesMaxEclipse = $this->getCircumstancesMax();

        $t = $circumstancesMaxEclipse->getT();
        $u = $circumstancesMaxEclipse->getU();
        $v = $circumstancesMaxEclipse->getV();
        $a = $circumstancesMaxEclipse->getA();
        $b = $circumstancesMaxEclipse->getB();
        $l1s = $circumstancesMaxEclipse->getL1s();
        $n2 = $circumstancesMaxEclipse->getN2();

        $n = sqrt($n2);
        $tmp = ($a * $v - $u * $b) / ($n * $l1s);

        if (abs($tmp) <= 1.0) {
            $tmp = sqrt(1.0 - pow($tmp, 2)) * $l1s / $n;
        } else {
            $tmp = 0.0;
        }

        $t = $t - $tmp;

        // Iterate
        $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C1, $t);
        $sign = -1.0;
        $tau = 1.0;

        $cnt = 0;
        while (abs($tau) > 0.000001 && $cnt < 50) {
            $t = $circumstances->getT();
            $u = $circumstances->getU();
            $v = $circumstances->getV();
            $a = $circumstances->getA();
            $b = $circumstances->getB();
            $l1s = $circumstances->getL1s();
            $n2 = $circumstances->getN2();
            $n = sqrt($n2);

            $tau = ($a * $v - $u * $b) / ($n * $l1s);

            if (abs($tau) <= 1.0) {
                $tau = $sign * sqrt(1.0 - pow($tau, 2)) * $l1s / $n;
            } else {
                $tau = 0.0;
            }

            $tau = ($u * $a + $v * $b) / $n2 - $tau;
            $t -= $tau;

            $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C1, $t);
            $cnt++;
        }

        $this->getObservationalCircumstances(self::EVENT_C1, $circumstances);

        return $circumstances;
    }

    public function getCircumstancesC2(): SolarEclipseCircumstances
    {
        $circumstancesMaxEclipse = $this->getCircumstancesMax();

        $t = $circumstancesMaxEclipse->getT();
        $u = $circumstancesMaxEclipse->getU();
        $v = $circumstancesMaxEclipse->getV();
        $a = $circumstancesMaxEclipse->getA();
        $b = $circumstancesMaxEclipse->getB();
        $l2s = $circumstancesMaxEclipse->getL2s();
        $n2 = $circumstancesMaxEclipse->getN2();
        $n = sqrt($n2);

        $tau = ($a * $v - $u * $b) / ($n * $l2s);

        if (abs($tau) <= 1.0) {
            $tau = sqrt(1.0 - pow($tau, 2)) * $l2s / $n;
        } else {
            $tau = 0.0;
        }

        if ($l2s < 0.0) {
            $t = $t + $tau;
        } else {
            $t = $t - $tau;
        }

        // Iterate
        $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C2, $t);
        $l2s = $circumstances->getL2s();
        $sign = -1.0;
        $sign = $l2s < 0.0 ? -1 * $sign : $sign;
        $tau = 1.0;

        $cnt = 0;
        while (($tau > 0.000001 || $tau < -0.000001) && $cnt < 50) {
            $t = $circumstances->getT();
            $u = $circumstances->getU();
            $v = $circumstances->getV();
            $a = $circumstances->getA();
            $b = $circumstances->getB();
            $n2 = $circumstances->getN2();
            $n = sqrt($n2);

            $tau = ($a * $v - $u * $b) / ($n * $l2s);

            if (abs($tau) <= 1.0) {
                $tau = $sign * sqrt(1.0 - pow($tau, 2)) * $l2s / $n;
            } else {
                $tau = 0.0;
            }

            $tau = ($u * $a + $v * $b) / $n2 - $tau;
            $t -= $tau;

            $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C2, $t);
            $cnt++;
        }

        $this->getObservationalCircumstances(self::EVENT_C2, $circumstances);

        return $circumstances;
    }

    public function getCircumstancesC3(): SolarEclipseCircumstances
    {
        $circumstancesMaxEclipse = $this->getCircumstancesMax();

        $t = $circumstancesMaxEclipse->getT();
        $u = $circumstancesMaxEclipse->getU();
        $v = $circumstancesMaxEclipse->getV();
        $a = $circumstancesMaxEclipse->getA();
        $b = $circumstancesMaxEclipse->getB();
        $l2s = $circumstancesMaxEclipse->getL2s();
        $n2 = $circumstancesMaxEclipse->getN2();
        $n = sqrt($n2);

        $tau = ($a * $v - $u * $b) / ($n * $l2s);

        if (abs($tau) <= 1.0) {
            $tau = sqrt(1.0 - pow($tau, 2)) * $l2s / $n;
        } else {
            $tau = 0.0;
        }

        if ($l2s < 0.0) {
            $t = $t - $tau;
        } else {
            $t = $t + $tau;
        }

        // Iterate
        $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C2, $t);
        $l2s = $circumstances->getL2s();
        $sign = 1.0;
        $sign = $l2s < 0.0 ? -1 * $sign : $sign;
        $tau = 1.0;

        $cnt = 0;
        while (($tau > 0.000001 || $tau < -0.000001) && $cnt < 50) {
            $t = $circumstances->getT();
            $u = $circumstances->getU();
            $v = $circumstances->getV();
            $a = $circumstances->getA();
            $b = $circumstances->getB();
            $n2 = $circumstances->getN2();
            $n = sqrt($n2);

            $tau = ($a * $v - $u * $b) / ($n * $l2s);

            if (abs($tau) <= 1.0) {
                $tau = $sign * sqrt(1.0 - pow($tau, 2)) * $l2s / $n;
            } else {
                $tau = 0.0;
            }

            $tau = ($u * $a + $v * $b) / $n2 - $tau;
            $t -= $tau;

            $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C2, $t);
            $cnt++;
        }

        $this->getObservationalCircumstances(self::EVENT_C3, $circumstances);

        return $circumstances;
    }

    public function getCircumstancesC4(): SolarEclipseCircumstances
    {
        $circumstancesMaxEclipse = $this->getCircumstancesMax();

        $t = $circumstancesMaxEclipse->getT();
        $u = $circumstancesMaxEclipse->getU();
        $v = $circumstancesMaxEclipse->getV();
        $a = $circumstancesMaxEclipse->getA();
        $b = $circumstancesMaxEclipse->getB();
        $l1s = $circumstancesMaxEclipse->getL1s();
        $n2 = $circumstancesMaxEclipse->getN2();
        $n = sqrt($n2);

        $tau = ($a * $v - $u * $b) / ($n * $l1s);

        if (abs($tau) <= 1.0) {
            $tau = sqrt(1.0 - pow($tau, 2)) * $l1s / $n;
        } else {
            $tau = 0.0;
        }

        $t = $t + $tau;

        // Iterate
        $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C4, $t);
        $sign = 1.0;
        $tau = 1.0;

        $cnt = 0;
        while (abs($tau) > 0.000001 && $cnt < 50) {
            $t = $circumstances->getT();
            $u = $circumstances->getU();
            $v = $circumstances->getV();
            $a = $circumstances->getA();
            $b = $circumstances->getB();
            $l1s = $circumstances->getL1s();
            $n2 = $circumstances->getN2();
            $n = sqrt($n2);

            $tau = ($a * $v - $u * $b) / ($n * $l1s);

            if (abs($tau) <= 1.0) {
                $tau = $sign * sqrt(1.0 - pow($tau, 2)) * $l1s / $n;
            } else {
                $tau = 0.0;
            }

            $tau = ($u * $a + $v * $b) / $n2 - $tau;
            $t -= $tau;

            $circumstances = $this->getTimeDependentCircumstances(self::EVENT_C4, $t);
            $cnt++;
        }

        $this->getObservationalCircumstances(self::EVENT_C4, $circumstances);

        return $circumstances;
    }

    private function getTimeDependentCircumstances(string $eventType, float $t): SolarEclipseCircumstances
    {
        $x = $this->besselianElements->getX($t);
        $dX = $this->besselianElements->getDX($t);
        $y = $this->besselianElements->getY($t);
        $dY = $this->besselianElements->getDY($t);
        $d = $this->besselianElements->getD($t);
        $dRad = deg2rad($d);
        $sinD = sin($dRad);
        $cosD = cos($dRad);

        $dD = $this->besselianElements->getDD($t);
        $dDRad = deg2rad($dD);

        $mu = $this->besselianElements->getMu($t);
        if ($mu >= 360.0) {
            $mu -= 360.0;
        }
        $muRad = deg2rad($mu);

        $dMu = $this->besselianElements->getDMu($t);
        $dMuRad = deg2rad($dMu);

        $l1 = $this->besselianElements->getL1($t);
        $l2 = $this->besselianElements->getL2($t);

        $tanF1 = $this->besselianElements->getTanF1();
        $f1 = atan($tanF1);

        $tanF2 = $this->besselianElements->getTanF2();
        $f2 = atan($tanF2);

        $lonRad = $this->location->getLongitudePositiveWestRad();
        $h = $muRad - $lonRad - ($this->dT / 13713.440924999626077);
        $sinH = sin($h);
        $cosH = cos($h);

        $rhoSinOs = $this->location->getRhoSinOs();
        $rhoCosOs = $this->location->getRhoCosOs();

        $xi = $rhoCosOs * $sinH;
        $eta = $rhoSinOs * $cosD - $rhoCosOs * $cosH * $sinD;
        $zeta = $rhoSinOs * $sinD + $rhoCosOs * $cosH * $cosD;
        $dxi = $dMuRad * $rhoCosOs * $cosH;
        $deta = $dMuRad * $xi * $sinD - $zeta * $dDRad;

        $u = $x - $xi;
        $v = $y - $eta;
        $a = $dX - $dxi;
        $b = $dY - $deta;

        $l1s = 0.0;
        if ($eventType == self::EVENT_C1 || $eventType == self::EVENT_MAX || $eventType == self::EVENT_C4) {
            $l1s = $l1 - $zeta * $f1;
        }

        $l2s = 0.0;
        if ($eventType == self::EVENT_C2 || $eventType == self::EVENT_MAX || $eventType == self::EVENT_C3) {
            $l2s = $l2 - $zeta * $f2;
        }

        $n2 = pow($a, 2) + pow($b, 2);

        // Set circumstances
        $circumstances = new SolarEclipseCircumstances();

        $circumstances->setT($t);
        $circumstances->setSinD($sinD);
        $circumstances->setCosD($cosD);
        $circumstances->setSinH($sinH);
        $circumstances->setCosH($cosH);
        $circumstances->setEta($eta);
        $circumstances->setU($u);
        $circumstances->setV($v);
        $circumstances->setA($a);
        $circumstances->setB($b);
        $circumstances->setL1s($l1s);
        $circumstances->setL2s($l2s);
        $circumstances->setN2($n2);

        return $circumstances;
    }

    /**
     * TODO Code...
     * Get observational circumstances
     * @param int $eventType
     * @param SolarEclipseCircumstances $circumstances
     */
    public function getObservationalCircumstances(
        $eventType,
        SolarEclipseCircumstances &$circumstances
    ): SolarEclipseCircumstances
    {
        $sinD = $circumstances->getSinD();
        $cosD = $circumstances->getCosD();
        $sinH = $circumstances->getSinH();
        $cosH = $circumstances->getCosH();

        $eta = $circumstances->getEta();

        $u = $circumstances->getU();
        $v = $circumstances->getV();
        $calculatedLocalEventType = 0; // TODO ... ... ...

        /* We are looking at an "external" contact UNLESS this is a total solar eclipse AND we are looking at
         * c2 or c3, in which case it is an INTERNAL contact! Note that if we are looking at maximum eclipse,
         * then we may not have determined the type of eclipse (mid[39]) just yet!
        */
        if ($eventType == self::EVENT_MAX) {
            $contacttype = 1.0;
        } else {
            if ($calculatedLocalEventType == 3 && ($eventType == self::EVENT_C2 || $eventType == self::EVENT_C3)) {
                $contacttype = -1.0;
            } else {
                $contacttype = 1.0;
            }
        }

        $p = atan2($contacttype * $u, $contacttype * $v);

        $lat = $this->location->getLatitudeRad();
        $sinLat = sin($lat);
        $cosLat = cos($lat);

        // Altitude and azimuth of the sun on the observers surface
        $alt = asin($sinD * $sinLat + $cosD * $cosLat * $cosH);
        $azi = atan2(-1 * $sinH * $cosD, $sinD * $cosLat - $cosH * $sinLat * $cosD);

        $q = asin($cosLat * $sinH / cos($alt));
        if ($eta < 0.0) {
            $q = M_PI - $q;
        }
        $v = $p - $q;


        // Visibility (take sun radius and/or refraction into account)
        if ($alt > self::gRefractionHeight) {
            $eventVisibility = self::EVENT_VISIBILITY_ABOVE_HORIZON;
        } else {
            $eventVisibility = self::EVENT_VISIBILITY_BELOW_HORIZON;
        }

        // Set data
        $circumstances->setSunAltitude($alt);
        $circumstances->setSunAzimuth($azi);

        return $circumstances;
    }
}
