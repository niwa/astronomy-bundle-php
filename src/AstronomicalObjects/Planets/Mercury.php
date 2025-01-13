<?php

namespace Andrmoel\AstronomyBundle\AstronomicalObjects\Planets;

use Andrmoel\AstronomyBundle\Calculations\VSOP87\MercuryRectangularVSOP87;
use Andrmoel\AstronomyBundle\Calculations\VSOP87\MercurySphericalVSOP87;
use Andrmoel\AstronomyBundle\TimeOfInterest;

class Mercury extends Planet
{
    protected $VSOP87_SPHERICAL = MercurySphericalVSOP87::class;
    protected $VSOP87_RECTANGULAR = MercuryRectangularVSOP87::class;

    public static function create(?TimeOfInterest $toi = null): self
    {
        return new self($toi);
    }
}
