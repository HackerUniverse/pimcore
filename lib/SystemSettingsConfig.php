<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore;

use Exception;
use Pimcore\Cache\RuntimeCache;
use Pimcore\Config\LocationAwareConfigRepository;
use Pimcore\Event\SystemEvents;
use Pimcore\Helper\SystemConfig;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\Exception\ConfigWriteException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class SystemSettingsConfig
{
    private const CONFIG_ID = 'system_settings';

    private const SCOPE = 'pimcore_system_settings';

    private static ?LocationAwareConfigRepository $locationAwareConfigRepository = null;

    private LocaleServiceInterface $localeService;

    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        LocaleServiceInterface $localeService
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->localeService = $localeService;
    }

    private static function getRepository(): LocationAwareConfigRepository
    {
        if (!self::$locationAwareConfigRepository) {
            $containerConfig = \Pimcore::getContainer()->getParameter('pimcore.config');
            $config[self::CONFIG_ID] = [
                'general' => $containerConfig['general'],
                'documents' => $containerConfig['documents'],
                'objects' => $containerConfig['objects'],
                'assets' => $containerConfig['assets'],
                'email' => $containerConfig['email'],
            ];

            $storageConfig = $containerConfig['config_location'][self::CONFIG_ID];

            self::$locationAwareConfigRepository = new LocationAwareConfigRepository(
                $config,
                self::SCOPE,
                $storageConfig
            );
        }

        return self::$locationAwareConfigRepository;
    }

    public static function get(): array
    {
        $repository = self::getRepository();

        return SystemConfig::getConfigDataByKey($repository, self::CONFIG_ID);
    }

    public function save(array $values): void
    {
        $repository = self::getRepository();

        if (!$repository->isWriteable()) {
            throw new ConfigWriteException();
        }
        $data = $this->prepareSystemConfig($values);

        foreach ($data as $key => $value) {
            $repository->saveConfig($key, $value, function ($key, $data) {
                return ['pimcore' => $data];
            });
        }
    }

    /**
     *
     * @internal ONLY FOR TESTING PURPOSES IF NEEDED FOR SPECIFIC TEST CASES
     */
    public function testSave(array $values): void
    {
        $repository = self::getRepository();

        unset($values['writeable']);
        $repository->saveConfig(self::CONFIG_ID, $values, function ($key, $data) {
            return ['pimcore' => $data];
        });

    }

    /**
     *
     * @internal
     */
    public function getSystemSettingsConfig(): array
    {
        if (RuntimeCache::isRegistered('pimcore_system_settings_config')) {
            $config = RuntimeCache::get('pimcore_system_settings_config');
        } else {
            $config = self::get();
            $this->setSystemSettingsConfig($config);
        }

        return $config;
    }

    /**
     *
     * @internal
     */
    public function setSystemSettingsConfig(array $config): void
    {
        RuntimeCache::set('pimcore_system_settings_config', $config);
    }

    /**
     * @throws Exception
     */
    private function prepareSystemConfig(array $values): array
    {
        $fallbackLanguages = [];
        $localizedErrorPages = [];
        $languages = explode(',', $values['general.validLanguages']);
        $filteredLanguages = [];
        $existingValues = self::get();

        foreach ($languages as $language) {
            if (isset($values['general.fallbackLanguages.' . $language])) {
                $fallbackLanguages[$language] = str_replace(' ', '', $values['general.fallbackLanguages.' . $language]);
            }

            // localized error pages
            if (isset($values['documents.error_pages.localized.' . $language])) {
                $localizedErrorPages[$language] = $values['documents.error_pages.localized.' . $language];
            }

            if ($this->localeService->isLocale($language)) {
                $filteredLanguages[] = $language;
            }
        }

        // check if there's a fallback language endless loop
        foreach ($fallbackLanguages as $sourceLang => $targetLang) {
            $this->checkFallbackLanguageLoop($sourceLang, $fallbackLanguages);
        }

        $settings[self::CONFIG_ID] = [
            'general' => [
                'domain' => $values['general.domain'],
                'redirect_to_maindomain' => $values['general.redirect_to_maindomain'],
                'language' => $values['general.language'],
                'valid_languages' => $filteredLanguages,
                'fallback_languages' => $fallbackLanguages,
                'default_language' => $values['general.defaultLanguage'],
                'debug_admin_translations' => $values['general.debug_admin_translations'],
            ],
            'documents' => [
                'versions' => [
                    'days' => $values['documents.versions.days'] ?? null,
                    'steps' => $values['documents.versions.steps'] ?? null,
                ],
                'error_pages' => [
                    'default' => $values['documents.error_pages.default'],
                    'localized' => $localizedErrorPages,
                ],
            ],
            'objects' => [
                'versions' => [
                    'days' => $values['objects.versions.days'] ?? null,
                    'steps' => $values['objects.versions.steps'] ?? null,
                ],
            ],
            'assets' => [
                'versions' => [
                    'days' => $values['assets.versions.days'] ?? null,
                    'steps' => $values['assets.versions.steps'] ?? null,
                ],
            ],
            'email' => [],
        ];

        if (array_key_exists('email.debug.emailAddresses', $values) && $values['email.debug.emailAddresses']) {
            $settings[self::CONFIG_ID]['email']['debug']['email_addresses'] = $values['email.debug.emailAddresses'];
        }

        if ($existingValues) {
            $saveSettingsEvent = new GenericEvent(null, [
                'settings' => $settings,
                'existingValues' => $existingValues,
                'values' => $values,
            ]);
            $this->eventDispatcher->dispatch($saveSettingsEvent, SystemEvents::SAVE_ACTION_SYSTEM_SETTINGS);
        }

        return $settings;
    }

    /**
     * @throws \Exception
     */
    private function checkFallbackLanguageLoop(string $source, array $definitions, array $fallbacks = []): void
    {
        if (isset($definitions[$source])) {
            $targets = explode(',', $definitions[$source]);
            foreach ($targets as $l) {
                $target = trim($l);
                if ($target) {
                    if (in_array($target, $fallbacks)) {
                        throw new \Exception("Language `$source` | `$target` causes an infinte loop.");
                    }
                    $fallbacks[] = $target;

                    $this->checkFallbackLanguageLoop($target, $definitions, $fallbacks);
                }
            }
        } else {
            throw new \Exception("Language `$source` doesn't exist");
        }
    }
}
