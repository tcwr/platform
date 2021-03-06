<?php declare(strict_types=1);

namespace Shopware\Core\Framework;

use PHPUnit\Framework\TestCase;

class Feature
{
    public const ALL_MAJOR = 'major';

    /**
     * @var array[]
     */
    private static $registeredFeatures = [];

    public static function normalizeName(string $name): string
    {
        /*
         * Examples:
         * - NEXT-1234
         * - FEATURE_NEXT_1234
         * - SAAS_321
         */
        if (!preg_match('/(feature)?[-_ ]?([^-_ 0-9]*)[-_ ]?([0-9]+)/i', $name, $matches)) {
            throw new \InvalidArgumentException('Invalid feature name "' . $name . '"');
        }

        $project = $matches[2];
        if ($project !== '') {
            $project .= '_';
        }

        return strtoupper('FEATURE_' . $project . $matches[3]);
    }

    public static function isActive(string $feature): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        $feature = self::normalizeName($feature);

        if (self::$registeredFeatures !== []
            && !isset(self::$registeredFeatures[$feature])
            && $env !== 'prod'
        ) {
            trigger_error('Unknown feature "' . $feature . '"', E_USER_WARNING);
        }

        $featureAll = $_SERVER['FEATURE_ALL'] ?? '';
        if (self::isTrue((string) $featureAll) && (self::$registeredFeatures === [] || \array_key_exists($feature, self::$registeredFeatures ?? []))) {
            if ($featureAll === Feature::ALL_MAJOR) {
                return true;
            }

            // return true if it's registered and not a major feature
            if ((self::$registeredFeatures[$feature]['major'] ?? false) === false) {
                return true;
            }
        }

        if (!\array_key_exists($feature, $_SERVER)) {
            $fallback = self::$registeredFeatures[$feature]['default'] ?? false;

            return (bool) $fallback;
        }

        return self::isTrue(trim($_SERVER[$feature]));
    }

    public static function ifActive(string $flagName, \Closure $closure): void
    {
        self::isActive($flagName) && $closure();
    }

    public static function ifActiveCall(string $flagName, $object, string $methodName, ...$arguments): void
    {
        $closure = function () use ($object, $methodName, $arguments): void {
            $object->{$methodName}(...$arguments);
        };

        self::ifActive($flagName, \Closure::bind($closure, $object, $object));
    }

    public static function skipTestIfInActive(string $flagName, TestCase $test): void
    {
        if (self::isActive($flagName)) {
            return;
        }

        $test::markTestSkipped('Skipping feature test for flag  "' . $flagName . '"');
    }

    public static function skipTestIfActive(string $flagName, TestCase $test): void
    {
        if (!self::isActive($flagName)) {
            return;
        }

        $test::markTestSkipped('Skipping feature test for flag  "' . $flagName . '"');
    }

    public static function getAll(): array
    {
        $resolvedFlags = [];

        foreach (self::$registeredFeatures as $name => $_) {
            $resolvedFlags[$name] = self::isActive($name);
        }

        return $resolvedFlags;
    }

    /**
     * @internal
     */
    public static function registerFeature(string $name, array $metaData = []): void
    {
        // merge with existing data
        $metaData = array_merge(
            self::$registeredFeatures[$name] ?? [],
            $metaData
        );

        // set defaults
        $metaData['major'] = (bool) ($metaData['major'] ?? false);
        $metaData['default'] = (bool) ($metaData['default'] ?? false);
        $metaData['description'] = (string) ($metaData['description'] ?? '');

        self::$registeredFeatures[$name] = $metaData;
    }

    /**
     * @internal
     */
    public static function registerFeatures(iterable $registeredFeatures, ?string $dumpPath = null): void
    {
        foreach ($registeredFeatures as $flag => $data) {
            // old format
            if (\is_string($data)) {
                $flag = $data;
                $data = [];
            }

            self::registerFeature($flag, $data);
        }

        if ($dumpPath !== null) {
            self::dumpFeatures($dumpPath);
        }
    }

    /**
     * @internal
     *
     * @deprecated tag:v6.4.0.0 Use `Feature::resetRegisteredFeatures` and `Feature::registerFeatures`
     */
    public static function setRegisteredFeatures(iterable $registeredFeatures, ?string $dumpPath = null): void
    {
        self::resetRegisteredFeatures();
        self::registerFeatures($registeredFeatures, $dumpPath);
    }

    /**
     * @internal
     */
    public static function resetRegisteredFeatures(): void
    {
        self::$registeredFeatures = [];
    }

    /**
     * @internal
     */
    public static function getRegisteredFeatures(): array
    {
        return self::$registeredFeatures;
    }

    private static function isTrue(string $value): bool
    {
        /* @var mixed $value */
        return $value
            && $value !== 'false'
            && $value !== '0'
            && $value !== '';
    }

    private static function dumpFeatures(string $dumpPath): void
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
        // do not dump in prod
        if ($env === 'prod') {
            return;
        }

        $values = [];
        foreach (self::$registeredFeatures as $flag => $_) {
            $values[$flag] = self::isActive($flag);
        }

        $template = <<<'TEMPLATE'
<?php

// DO NOT EDIT! This file is auto generated by '%class%'}
namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function(ContainerConfigurator $configurator) {
    $configurator->parameters()->set(
        'shopware.features',
        %features%
    );
};
TEMPLATE;

        $rendered = str_replace(
            ['%class%', '%features%'],
            [self::class, var_export($values, true)],
            $template
        );

        $current = file_exists($dumpPath) ? file_get_contents($dumpPath) : '';

        if ($current !== $rendered) {
            file_put_contents($dumpPath, $rendered);
        }
    }
}
