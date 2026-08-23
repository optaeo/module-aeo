<?php
/**
 * The installed version of optaeo/module-aeo, read from Composer's runtime
 * installed-package registry (the same source `composer show` reads). composer.json
 * deliberately carries no `version` field — the git tag is the single source of
 * truth — so this is the only honest place to learn the version at run time.
 * Falls back to "unknown" outside a Composer install (source checkout in app/code).
 *
 * Used for the callback User-Agent and the connector-facing config read-back so
 * OptAEO can tell an outdated module apart from a broken one.
 */
declare(strict_types=1);

namespace Optaeo\Aeo\Model;

class ModuleVersion
{
    public const PACKAGE = 'optaeo/module-aeo';

    public function get(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)
                && \Composer\InstalledVersions::isInstalled(self::PACKAGE)
            ) {
                $version = \Composer\InstalledVersions::getPrettyVersion(self::PACKAGE);
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            }
        } catch (\Throwable) {
            // fall through — version is diagnostic only
        }
        return 'unknown';
    }
}
