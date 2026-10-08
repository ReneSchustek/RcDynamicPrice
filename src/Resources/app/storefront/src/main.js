import DynamicPricePlugin from './dynamic-price/dynamic-price.plugin';
import LengthVariantSwitchPlugin from './length-variant-switch/length-variant-switch.plugin';
import GuidedSelectionPlugin from './guided-selection/guided-selection.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('DynamicPrice', DynamicPricePlugin, '[data-dynamic-price]');

// Die eingegebene Länge wählt die Größenstufe. Das Element rendert die Kaufbox nur, wenn der Artikel
// das Zusatzfeld `rc_length_variant_switch` trägt; ohne Haken läuft auch kein Skript.
PluginManager.register('RcLengthVariantSwitch', LengthVariantSwitchPlugin, '[data-rc-length-variant-switch]');

// Geführte Auswahl: erst die Länge, dann nur die dazu kaufbaren Optionen. Das Element rendert die
// Kaufbox nur, wenn der Server die Angaben dafür mitgibt.
PluginManager.register('RcGuidedSelection', GuidedSelectionPlugin, '[data-rc-guided-selection]');
