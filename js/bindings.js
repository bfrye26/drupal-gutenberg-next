/**
 * Gutenberg Next: Drupal field block binding source.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  if (!wp || !wp.blocks || !wp.data || !wp.blocks.registerBlockBindingsSource) {
    return;
  }

  const STORE_NAME = 'gutenberg-next/fields';
  const { select, dispatch } = wp.data;

  const source = {
    name: 'gutenberg-next/field',
    label: 'Drupal field',

    getValues: function (args) {
      const bindings = args.bindings || {};
      const values = {};
      Object.keys(bindings).forEach(function (attribute) {
        const binding = bindings[attribute];
        if (!binding || binding.source !== 'gutenberg-next/field') {
          return;
        }
        const fieldName = binding.args && binding.args.field;
        if (!fieldName) {
          return;
        }
        const value = select(STORE_NAME).getValue(fieldName);
        values[attribute] = value === undefined || value === null ? '' : String(value);
      });
      return values;
    },

    setValues: function (args) {
      const attributeName = args.attributeName;
      const fieldName = args.binding && args.binding.args && args.binding.args.field;
      if (!fieldName) {
        return;
      }
      dispatch(STORE_NAME).setFieldValue(fieldName, args.value);
    },
  };

  // Include setValues unconditionally: builds that don't know the callback
  // simply never invoke it; builds that do get full write-through to the store.
  wp.blocks.registerBlockBindingsSource(source);

  Drupal.behaviors.gutenbergNextBindings = {
    attach: function () {
      once('gutenberg-next-bindings', 'body').forEach(function () {
        const config = drupalSettings.gutenbergNext || {};
        if (config.debug) {
          // eslint-disable-next-line no-console
          console.info('[Gutenberg Next] block binding source registered', {
            enabled: Boolean(config.bindings && config.bindings.enabled),
            setValuesSupported: typeof wp.blocks.getBlockBindingsSource('gutenberg-next/field').setValues === 'function',
          });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
