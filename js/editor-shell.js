/**
 * Gutenberg Next: modern editor shell and Gutenberg-native Drupal field panel.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const EDITOR_SELECTORS = [
    '.interface-interface-skeleton',
    '.edit-post-layout',
    '.edit-post-visual-editor',
    '.editor-visual-editor',
    '[data-gutenberg-editor]',
  ];

  function settings() {
    return drupalSettings.gutenbergNext || {};
  }

  function editorExists() {
    return EDITOR_SELECTORS.some((selector) => document.querySelector(selector));
  }

  function applyDocumentClasses() {
    const config = settings();
    const root = document.documentElement;
    document.body.classList.add('gutenberg-next-enabled');
    document.body.classList.toggle('gutenberg-next-sticky-header', Boolean(config.stickyHeader));
    root.style.setProperty('--gutenberg-next-content-width', `${config.contentWidth || 760}px`);
    root.style.setProperty('--gutenberg-next-wide-width', `${config.wideWidth || 1200}px`);
  }

  function addDrupalBadge() {
    const config = settings();
    if (!config.showDrupalBadge || document.querySelector('.gutenberg-next-badge')) {
      return;
    }

    const target =
      document.querySelector('.editor-header__settings') ||
      document.querySelector('.edit-post-header__settings') ||
      document.querySelector('.interface-interface-skeleton__header');
    if (!target) {
      return;
    }

    const badge = document.createElement('span');
    badge.className = 'gutenberg-next-badge';
    badge.textContent = 'Drupal';
    badge.title = `Gutenberg Next ${config.version || ''}`.trim();
    target.prepend(badge);
  }

  function buildCanvasCss(config) {
    return `
      :root {
        --gutenberg-next-content-width: ${config.contentWidth || 760}px;
        --gutenberg-next-wide-width: ${config.wideWidth || 1200}px;
      }
      .editor-styles-wrapper .is-root-container > :where(:not(.alignwide):not(.alignfull)),
      .editor-styles-wrapper .wp-block-post-content > :where(:not(.alignwide):not(.alignfull)),
      .editor-styles-wrapper .edit-post-visual-editor__post-title-wrapper > * {
        width: min(100%, var(--gutenberg-next-content-width));
        max-width: var(--gutenberg-next-content-width);
        margin-left: auto;
        margin-right: auto;
        box-sizing: border-box;
      }
      .editor-styles-wrapper .is-root-container > .alignwide,
      .editor-styles-wrapper .wp-block-post-content > .alignwide {
        width: min(100%, var(--gutenberg-next-wide-width));
        max-width: var(--gutenberg-next-wide-width);
        margin-left: auto;
        margin-right: auto;
        box-sizing: border-box;
      }
      .editor-styles-wrapper .is-root-container > .alignfull,
      .editor-styles-wrapper .wp-block-post-content > .alignfull {
        width: 100%;
        max-width: none;
      }
    `;
  }

  function injectIntoDocument(doc) {
    const config = settings();
    if (!config.injectCanvasStyles || !doc || !doc.head || doc.getElementById('gutenberg-next-canvas-style')) {
      return;
    }
    const style = doc.createElement('style');
    style.id = 'gutenberg-next-canvas-style';
    style.textContent = buildCanvasCss(config);
    doc.head.appendChild(style);
  }

  function injectCanvasStyles() {
    injectIntoDocument(document);
    document
      .querySelectorAll('iframe[name="editor-canvas"], iframe.editor-canvas, .editor-canvas iframe, .edit-site-visual-editor__editor-canvas')
      .forEach((iframe) => {
        if (!(iframe instanceof HTMLIFrameElement)) {
          return;
        }
        const inject = () => {
          try {
            injectIntoDocument(iframe.contentDocument);
          }
          catch (error) {
            if (settings().debug) {
              // eslint-disable-next-line no-console
              console.warn('[Gutenberg Next] could not inject editor canvas styles', error);
            }
          }
        };
        inject();
        iframe.addEventListener('load', inject, { once: true });
      });
  }

  function activate() {
    if (!editorExists()) {
      return false;
    }
    applyDocumentClasses();
    addDrupalBadge();
    injectCanvasStyles();
    return true;
  }

  Drupal.behaviors.gutenbergNextEditorShell = {
    attach(context) {
      once('gutenberg-next-editor-shell', 'body', context).forEach(() => {
        if (activate()) {
          return;
        }

        const observer = new MutationObserver(() => {
          if (!activate()) {
            return;
          }
          // Disconnect once the editor canvas iframe exists: its load
          // listener handles the style injection. Otherwise keep a short
          // grace window for late header/canvas renders, hard-capped below.
          const canvasIframe = [...document.querySelectorAll('iframe[name="editor-canvas"], iframe.editor-canvas, .editor-canvas iframe')]
            .find((iframe) => iframe instanceof HTMLIFrameElement);
          if (canvasIframe) {
            observer.disconnect();
            return;
          }
          window.clearTimeout(window.__gutenbergNextObserverTimer);
          window.__gutenbergNextObserverTimer = window.setTimeout(() => observer.disconnect(), 4000);
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        window.setTimeout(() => observer.disconnect(), 15000);
      });
    },
  };
})(Drupal, drupalSettings, once);
