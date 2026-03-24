import DynamicPricePlugin from './dynamic-price/dynamic-price.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('DynamicPrice', DynamicPricePlugin, '[data-dynamic-price]');
