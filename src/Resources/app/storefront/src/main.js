import DynamicPricePlugin from './dynamic-price/dynamic-price.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('DynamicPrice', DynamicPricePlugin, '[data-dynamic-price]');

// Re-Initialisierung nach Variantenwechsel — Shopware baut die Buybox neu auf
document.$emitter.subscribe('onVariantChange', () => {
    window.PluginManager.initializePlugins();
});
