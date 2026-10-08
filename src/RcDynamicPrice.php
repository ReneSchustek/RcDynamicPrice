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

    /**
     * Vorrang beim Laden der Vorlagen.
     *
     * Die Warenkorbzeile für Artikel mit Längenwahl erweitert eine Vorlage aus
     * `TmmsProductCustomerInputs`. Ohne eigenen Vorrang entschiede die Reihenfolge, in der Shopware
     * die Plugins lädt, und die ergibt sich zufällig aus den Installationsdaten.
     *
     * Der Kern liegt bei -1, Plugins ohne eigene Angabe bei 0; TMMS setzt keine. Mit 10 stehen die
     * Vorlagen dieser Erweiterung sicher dahinter, ohne einem anderen Plugin den Weg zu verstellen,
     * das seinerseits einen Vorrang beansprucht.
     */
    public function getTemplatePriority(): int
    {
        return 10;
    }
}
