<?php

declare(strict_types=1);

namespace Ruhrcoder\RcDynamicPrice;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Plugin-Bootstrapper für RcDynamicPrice.
 * Registrierung von Services erfolgt über services.xml.
 */
final class RcDynamicPrice extends Plugin
{
    /**
     * Monolog-Channel `rc_dynamic_price` registrieren. Plugins laden `packages/*.yaml`
     * nicht automatisch — die Kanal-Liste muss per prependExtensionConfig an die
     * MonologBundle-Konfiguration durchgereicht werden, damit der Service
     * `monolog.logger.rc_dynamic_price` vom Container erzeugt wird.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->prependExtensionConfig('monolog', [
            'channels' => ['rc_dynamic_price'],
        ]);
    }
}
