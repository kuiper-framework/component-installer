<?php

namespace kuiper\component;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

class ComponentInstaller implements PluginInterface, EventSubscriberInterface
{
    public const CONFIG_FILE = 'config/kuiper.php';
    /**
     * @var IOInterface
     */
    private $io;
    /**
     * @var Composer
     */
    private $composer;

    public static function getSubscribedEvents()
    {
        return [
            'post-update-cmd' => 'onPostUpdateCmd',
            'post-package-install' => 'onPostPackageInstall',
            'post-package-uninstall' => 'onPostPackageUninstall'
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public static function generate(): void
    {
        $io = new ConsoleIO(new ArrayInput([]), new ConsoleOutput(), new HelperSet([new QuestionHelper()]));
        $composer = Factory::create($io);
        $installer = new self();
        $installer->activate($composer, $io);

        $rootExtra = $installer->extractMetadata($composer->getPackage());
        $file = $rootExtra['config-file'] ?? self::CONFIG_FILE;
        if (file_exists($file)) {
            unlink($file);
        }

        foreach ($composer->getLocker()->getLockedRepository()->getPackages() as $package) {
            $installer->handlePackage($package);
        }
        $installer->onPostUpdateCmd();
    }

    public function onPostUpdateCmd()
    {
        $rootExtra = $this->extractMetadata($this->composer->getPackage(), true);
        if (empty($rootExtra)) {
            return;
        }
        $this->mergeInto($rootExtra, $rootExtra);
    }

    public function onPostPackageInstall(PackageEvent $event)
    {
        if (!$event->isDevMode()) {
            // Do nothing in production mode.
            return;
        }

        /** @var PackageInterface $package */
        $package = $event->getOperation()->getPackage();
        $this->handlePackage($package);
    }

    public function onPostPackageUninstall(PackageEvent $event)
    {
        if (!$event->isDevMode()) {
            // Do nothing in production mode.
            return;
        }
        /** @var PackageInterface $package */
        $package = $event->getOperation()->getPackage();
        $extra = $this->extractMetadata($package);

        if (empty($extra)) {
            // Package does not define anything of interest; do nothing.
            return;
        }
        $rootExtra = $this->extractMetadata($this->composer->getPackage());
        $this->removeFrom($extra, $rootExtra);
    }

    private static function dump($var, $indent = ""): string
    {
        switch (gettype($var)) {
            case "string":
                return '"' . addcslashes($var, "\\\$\"\r\n\t\v\f") . '"';
            case "array":
                $indexed = array_keys($var) === range(0, count($var) - 1);
                $r = [];
                foreach ($var as $key => $value) {
                    $r[] = "$indent    "
                        . ($indexed ? "" : self::dump($key) . " => ")
                        . self::dump($value, "$indent    ");
                }
                return "[\n" . implode(",\n", $r) . "\n" . $indent . "]";
            case "boolean":
                return $var ? "TRUE" : "FALSE";
            default:
                return var_export($var, TRUE);
        }
    }

    private function extractMetadata(PackageInterface $package, $includePsr4Namespace = false): array
    {
        $extra = $package->getExtra();
        $metadata = $extra['kuiper'] ?? [];
        if (!isset($metadata['component-scan'])) {
            $metadata['component-scan'] = $includePsr4Namespace;
        }
        if ($metadata['component-scan'] === true) {
            $metadata['component-scan'] = $this->getPsr4Namespaces($package);
        }
        if (empty($metadata['component-scan'])) {
            unset($metadata['component-scan']);
        }
        return $metadata;
    }

    private function mergeInto(array $extra, array $rootExtra): void
    {
        $file = $rootExtra['config-file'] ?? self::CONFIG_FILE;
        $config = [];
        if (file_exists($file)) {
            $config = require $file;
        }
        $copy = $config;
        $copy = $this->merge($copy, $extra);
        if ($config != $copy) {
            $this->write($copy, $file);
        }
    }

    private function removeFrom(array $extra, array $rootExtra)
    {
        $file = $rootExtra['config-file'] ?? self::CONFIG_FILE;
        $config = [];
        if (file_exists($file)) {
            $config = require $file;
        }
        $copy = $config;
        $copy = $this->remove($copy, $extra);
        if ($config != $copy) {
            $this->write($copy, $file);
        }
    }

    private function merge(array $copy, array $extra): array
    {
        $copy['component_scan'] = array_values(array_unique(array_merge(
            $copy['component_scan'] ?? [],
            $extra['component-scan'] ?? []
        )));
        $copy['configuration'] = array_values(array_unique(array_merge(
            $copy['configuration'] ?? [],
            $extra['configuration'] ?? []
        )));
        return $copy;
    }

    private function remove(array $copy, array $extra): array
    {
        $copy['component_scan'] = array_values(array_diff(
            $copy['component_scan'] ?? [],
            $extra['component-scan'] ?? []
        ));
        $copy['configuration'] = array_values(array_diff(
            $copy['configuration'] ?? [],
            $extra['configuration'] ?? []
        ));
        return $copy;
    }

    private function write(array $copy, string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create directry $dir");
        }
        file_put_contents($file, '<?php
/**
 * This file is automatic generator by kuiper/component-installer, don\'t edit it mannuly
 */
 
return ' . self::dump($copy). ";\n");
    }

    private function getPsr4Namespaces(PackageInterface $package)
    {
        return array_keys($package->getAutoload()['psr-4'] ?? []);
    }

    /**
     * @param PackageInterface $package
     */
    protected function handlePackage(PackageInterface $package): void
    {
        $name = $package->getName();
        $extra = $this->extractMetadata($package);

        if (empty($extra)) {
            // Package does not define anything of interest; do nothing.
            return;
        }
        $rootExtra = $this->extractMetadata($this->composer->getPackage());
        $whitelist = $rootExtra['whitelist'] ?? [];
        if (!in_array($name, $whitelist, true)
            && !$this->io->askConfirmation("Install component $name [y]: ")) {
            return;
        }
        $this->mergeInto($extra, $rootExtra);
    }
}