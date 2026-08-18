/**
 * Gutenberg Next: Drupal-to-Gutenberg bridge.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const API = (window.GutenbergNext = window.GutenbergNext || {});

  API.settings = function () {
    return drupalSettings.gutenbergNext || {};
  };

  API.getWp = function () {
    return window.wp || null;
  };

  API.findDrupalField = function (fieldName) {
    const selectorName = String(fieldName).replaceAll('_', '-');
    return (
      document.querySelector(`[data-drupal-selector="edit-${selectorName}"]`) ||
      document.querySelector(`[data-drupal-selector^="edit-${selectorName}-"]`) ||
      document.querySelector(`[name="${CSS.escape(fieldName)}"]`) ||
      document.querySelector(`[name^="${CSS.escape(fieldName)}["]`)
    );
  };

  API.focusDrupalField = function (fieldName) {
    const element = API.findDrupalField(fieldName);
    if (!element) {
      return false;
    }

    const details = element.closest('details');
    if (details) {
      details.open = true;
    }

    const verticalTab = element.closest('.vertical-tabs__pane');
    if (verticalTab && verticalTab.id) {
      const tab = document.querySelector(`a[href="#${CSS.escape(verticalTab.id)}"]`);
      if (tab instanceof HTMLElement) {
        tab.click();
      }
    }

    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    window.setTimeout(() => {
      const focusable = element.matches('input, textarea, select, button, [tabindex]')
        ? element
        : element.querySelector('input, textarea, select, button, [tabindex]');
      if (focusable instanceof HTMLElement) {
        focusable.focus({ preventScroll: true });
      }
    }, 250);
    return true;
  };

  API.findWidgetRoot = function (fieldName) {
    const selectorName = String(fieldName).replaceAll('_', '-');
    return (
      document.querySelector('[data-drupal-selector="edit-' + selectorName + '-wrapper"]') ||
      document.querySelector('[data-drupal-selector="edit-' + selectorName + '"]') ||
      document.querySelector('[data-drupal-selector^="edit-' + selectorName + '-"]')
    );
  };

  API.setWidgetValue = function (element, value) {
    if (!element) {
      return;
    }
    if (element.type === 'checkbox') {
      element.checked = Boolean(value);
      element.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
    const proto = Object.getPrototypeOf(element);
    const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
    if (descriptor && descriptor.set) {
      descriptor.set.call(element, value);
    }
    else {
      element.value = value;
    }
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
  };

  API.capabilities = function () {
    const wp = API.getWp() || {};
    return {
      blocks: Boolean(wp.blocks),
      blockEditor: Boolean(wp.blockEditor),
      components: Boolean(wp.components),
      data: Boolean(wp.data),
      editor: Boolean(wp.editor || wp.editPost),
      plugins: Boolean(wp.plugins),
      commands: Boolean(wp.commands),
      preferences: Boolean(wp.preferences),
      notices: Boolean(wp.notices),
    };
  };

  Drupal.behaviors.gutenbergNextBridge = {
    attach(context) {
      once('gutenberg-next-bridge', 'body', context).forEach(() => {
        const settings = API.settings();
        if (settings.debug) {
          // eslint-disable-next-line no-console
          console.info('[Gutenberg Next] bridge loaded', {
            settings,
            capabilities: API.capabilities(),
          });
        }
      });
    },
  };
})(Drupal, drupalSettings, once);
