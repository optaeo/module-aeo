<?php
/**
 * OptAEO module registration. Distributed as the Composer package
 * optaeo/module-aeo; deployed locally to app/code/Optaeo/Aeo for store testing.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Optaeo_Aeo', __DIR__);
