import TheriusPaymentPlugin from './therius-payment/therius-payment.plugin';

const PluginManager = window.PluginManager;
PluginManager.register('TheriusPaymentPlugin', TheriusPaymentPlugin, '[data-therius-payment]');
