<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        parent::boot();

        $sessionsDir = $this->getProjectDir() . '/var/sessions/' . $this->environment;
        if (!is_dir($sessionsDir)) {
            mkdir($sessionsDir, 0775, true);
        }
    }
}
