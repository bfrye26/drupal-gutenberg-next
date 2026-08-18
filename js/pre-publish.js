/**
 * Gutenberg Next: Drupal publishing controls in Gutenberg's pre-publish flow.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  const config = drupalSettings.gutenbergNext || {};
  if (!wp || !wp.plugins || !wp.data || !wp.element || !wp.components || !wp.hooks || !wp.i18n || !config.publish) {
    return;
  }

  const STORE_NAME = 'gutenberg-next/fields';
  const publish = config.publish;
  const { createElement, useState } = wp.element;
  const { ToggleControl, SelectControl, TextControl, Button, Notice } = wp.components;
  const editorApi = wp.editor || wp.editPost;
  const { select, dispatch, subscribe } = wp.data;
  const { __ } = wp.i18n;

  function bridge() {
    return window.GutenbergNext || {};
  }

  function epochToLocalInput(ts) {
    if (!ts) {
      return '';
    }
    const d = new Date(ts * 1000);
    const pad = function (n) {
      return String(n).padStart(2, '0');
    };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
      'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  function writeDatetime(fieldName, storageValue) {
    const root = bridge().findWidgetRoot ? bridge().findWidgetRoot(fieldName) : null;
    if (!root) {
      return false;
    }
    const dateInput = root.querySelector('input[type="date"]');
    const timeInput = root.querySelector('input[type="time"]');
    if (!dateInput && !timeInput) {
      const text = root.querySelector('input[type="text"]');
      if (text) {
        bridge().setWidgetValue(text, storageValue || '');
        return true;
      }
      return false;
    }
    const parts = String(storageValue || '').split('T');
    if (dateInput) {
      bridge().setWidgetValue(dateInput, parts[0] || '');
    }
    if (timeInput) {
      bridge().setWidgetValue(timeInput, (parts[1] || '').slice(0, 8));
    }
    return true;
  }

  function PrePublishPanelBody(props) {
    const [state, setState] = useState({
      published: Boolean(publish.status && publish.status.published),
      moderation: publish.moderation ? publish.moderation.state : '',
      publishOn: epochToLocalInput(publish.scheduler ? publish.scheduler.publishOn : null),
      unpublishOn: epochToLocalInput(publish.scheduler ? publish.scheduler.unpublishOn : null),
      alias: publish.alias || '',
      featured: (publish.featuredMedia && publish.featuredMedia.value) || [],
      featuredQuery: '',
      featuredSuggestions: [],
      notice: null,
    });

    function searchMedia(query) {
      if (!publish.featuredMedia || !publish.featuredMedia.autocompleteUrl) {
        return;
      }
      fetch(publish.featuredMedia.autocompleteUrl + '?q=' + encodeURIComponent(query), {
        method: 'GET',
        credentials: 'same-origin',
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('media autocomplete failed: ' + response.status);
          }
          return response.json();
        })
        .then(function (matches) {
          setState(function (prev) {
            return Object.assign({}, prev, {
              featuredSuggestions: matches.map(function (match) {
                const idMatch = String(match.value).match(/\((\d+)\)$/);
                return { id: Number(idMatch ? idMatch[1] : 0), label: match.label };
              }).filter(function (item) {
                return item.id > 0;
              }),
            });
          });
        })
        .catch(function () {});
    }

    function selectFeatured(item) {
      const next = item ? [item] : [];
      const root = bridge().findWidgetRoot ? bridge().findWidgetRoot(publish.featuredMedia.field) : null;
      if (root) {
        const input = root.querySelector('input[data-autocomplete-path]');
        const selectEl = root.querySelector('select');
        if (input) {
          bridge().setWidgetValue(input, next.map(function (i) {
            return i.label + ' (' + i.id + ')';
          }).join(', '));
        }
        else if (selectEl) {
          bridge().setWidgetValue(selectEl, next.length ? String(next[0].id) : '');
        }
      }
      setState(function (prev) {
        return Object.assign({}, prev, { featured: next, featuredQuery: '', featuredSuggestions: [] });
      });
    }

    const sections = [];
    const statusRoot = bridge().findWidgetRoot ? bridge().findWidgetRoot('status') : null;

    if (statusRoot && statusRoot.querySelector('input[type="checkbox"]')) {
      sections.push(createElement(ToggleControl, {
        key: 'status',
        label: __('Published'),
        checked: state.published,
        onChange: function (checked) {
          bridge().setWidgetValue(statusRoot.querySelector('input[type="checkbox"]'), checked);
          setState(function (prev) {
            return Object.assign({}, prev, { published: checked });
          });
        },
      }));
    }

    if (publish.moderation) {
      sections.push(createElement(SelectControl, {
        key: 'moderation',
        label: __('Workflow state'),
        value: state.moderation,
        options: Object.keys(publish.moderation.states).map(function (id) {
          return { value: id, label: publish.moderation.states[id] };
        }),
        onChange: function (next) {
          const root = bridge().findWidgetRoot ? bridge().findWidgetRoot('moderation_state') : null;
          const selectEl = root && root.querySelector('select');
          if (selectEl) {
            bridge().setWidgetValue(selectEl, next);
            setState(function (prev) {
              return Object.assign({}, prev, { moderation: next });
            });
          }
        },
      }));
    }

    if (publish.scheduler) {
      sections.push(createElement(TextControl, {
        key: 'publish-on',
        type: 'datetime-local',
        label: __('Publish on'),
        value: state.publishOn,
        onChange: function (next) {
          setState(function (prev) {
            return Object.assign({}, prev, { publishOn: next });
          });
          writeDatetime('publish_on', next ? next + ':00' : '');
        },
      }));
      sections.push(createElement(TextControl, {
        key: 'unpublish-on',
        type: 'datetime-local',
        label: __('Unpublish on'),
        value: state.unpublishOn,
        onChange: function (next) {
          setState(function (prev) {
            return Object.assign({}, prev, { unpublishOn: next });
          });
          writeDatetime('unpublish_on', next ? next + ':00' : '');
        },
      }));
    }

    sections.push(createElement(TextControl, {
      key: 'alias',
      label: __('URL alias'),
      value: state.alias,
      onChange: function (next) {
        setState(function (prev) {
          return Object.assign({}, prev, { alias: next });
        });
        const root = bridge().findWidgetRoot ? bridge().findWidgetRoot('path') : null;
        const input = root && root.querySelector('input[type="text"]');
        if (input) {
          bridge().setWidgetValue(input, next);
        }
      },
    }));

    if (publish.featuredMedia) {
      const isReference = publish.featuredMedia.kind === 'entity_reference';
      const current = state.featured;
      const summaryText = isReference
        ? (Array.isArray(current) && current.length
          ? current.map(function (item) { return item.label; }).join(', ')
          : __('None'))
        : ((current && current.summary) || __('None'));
      sections.push(createElement(
        'div',
        { key: 'featured', className: 'gutenberg-next-featured-media' },
        createElement('p', { className: 'components-base-control__label' }, __('Featured media')),
        createElement('p', null, summaryText),
        isReference ? createElement(TextControl, {
          placeholder: __('Search media…'),
          value: state.featuredQuery,
          onChange: function (next) {
            setState(function (prev) {
              return Object.assign({}, prev, { featuredQuery: next });
            });
            searchMedia(next);
          },
        }) : null,
        isReference && state.featuredSuggestions.length ? createElement(
          'ul',
          { className: 'gutenberg-next-featured-suggestions' },
          state.featuredSuggestions.map(function (item) {
            return createElement('li', { key: item.id },
              createElement(Button, {
                variant: 'link',
                onClick: function () {
                  selectFeatured(item);
                },
              }, item.label));
          }),
        ) : null,
        isReference && Array.isArray(current) && current.length ? createElement(Button, {
          variant: 'secondary',
          size: 'compact',
          onClick: function () {
            selectFeatured(null);
          },
        }, __('Clear')) : null,
        !isReference ? createElement(Button, {
          variant: 'secondary',
          size: 'compact',
          onClick: function () {
            const api = bridge();
            if (!api.focusDrupalField || !api.focusDrupalField(publish.featuredMedia.field)) {
              setState(function (prev) {
                return Object.assign({}, prev, { notice: __('The form widget for this field is not available in the editor.') });
              });
            }
          },
        }, __('Edit in form')) : null,
      ));
    }

    if (publish.author && publish.author.name) {
      sections.push(createElement(
        'p',
        { key: 'author', className: 'gutenberg-next-prepublish-author' },
        __('By') + ' ' + publish.author.name + ' ',
        createElement(Button, {
          variant: 'link',
          onClick: function () {
            const api = bridge();
            const focused = api.focusDrupalField && api.focusDrupalField('uid');
            if (!focused) {
              const author = document.querySelector('#edit-author, [data-gutenberg-panel="author"]');
              if (author) {
                author.scrollIntoView({ behavior: 'smooth', block: 'center' });
              }
            }
          },
        }, __('Edit')),
      ));
    }

    const fields = props.fields || {};
    const fieldNames = Object.keys(fields);
    if (fieldNames.length) {
      sections.push(createElement(
        'div',
        { key: 'fields' },
        createElement('p', { className: 'components-base-control__label' }, __('Drupal fields')),
        createElement(
          'ul',
          { className: 'gutenberg-next-prepublish-fields' },
          fieldNames.map(function (name) {
            const field = fields[name];
            const problems = [];
            if (field.invalid) {
              problems.push(field.invalid.message);
            }
            if (field.required && (field.value === '' || field.value === null || field.value === undefined ||
              (Array.isArray(field.value) && field.value.length === 0))) {
              problems.push(__('Required'));
            }
            return createElement('li', { key: name },
              createElement(Button, {
                variant: problems.length ? 'secondary' : 'link',
                onClick: function () {
                  const api = bridge();
                  if (!api.focusDrupalField || !api.focusDrupalField(name)) {
                    setState(function (prev) {
                      return Object.assign({}, prev, { notice: __('The form widget for this field is not available in the editor.') });
                    });
                  }
                },
              }, field.label + (problems.length ? ' — ' + problems.join('; ') : '')));
          }),
        ),
      ));
    }

    sections.push(createElement(
      'div',
      { key: 'save', className: 'gutenberg-next-prepublish-save' },
      state.notice ? createElement(Notice, { status: 'warning', isDismissible: false }, state.notice) : null,
      createElement(Button, {
        variant: 'primary',
        onClick: function () {
          const submit = document.querySelector('#edit-submit');
          if (submit) {
            submit.click();
          }
        },
      }, __('Save')),
    ));

    return createElement('div', { className: 'gutenberg-next-prepublish-content' }, sections);
  }

  const PrePublishContent = wp.data.withSelect(function (selectFn) {
    const store = selectFn(STORE_NAME);
    return {
      fields: store && store.isReady && store.isReady() ? store.getFields() : {},
    };
  })(PrePublishPanelBody);

  // Save guard: block the editor save path while the field store knows
  // about invalid values. Drupal's own validation remains authoritative on
  // submit; this only stops the round-trip before it starts.
  wp.hooks.addFilter('editor.__unstableSavePost', 'gutenberg-next/pre-publish-guard', function (pending) {
    const store = select(STORE_NAME);
    if (store && store.isReady && store.isReady()) {
      const fields = store.getFields();
      const invalid = Object.keys(fields)
        .map(function (name) { return fields[name]; })
        .filter(function (field) { return field.invalid; });
      if (invalid.length) {
        return Promise.reject(new Error(__('Fix the Drupal field errors before saving:') + ' ' + invalid[0].label));
      }
    }
    return pending;
  });

  function insertHeaderButton() {
    if (document.querySelector('.gutenberg-next-publish-toggle')) {
      return true;
    }
    const target = document.querySelector('.gutenberg-header-settings') || document.querySelector('.editor-header__settings');
    if (!target) {
      return false;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'gutenberg-next-publish-toggle button';
    button.textContent = __('Publish…');
    button.addEventListener('click', function () {
      dispatch('core/editor').togglePublishSidebar();
    });
    target.prepend(button);
    return true;
  }

  function registerPrePublish() {
    if (window.__gutenbergNextPrePublishRegistered) {
      return true;
    }
    const editorActions = wp.data.dispatch('core/editor');
    const editorSelectors = wp.data.select('core/editor');
    const sidebarMode = Boolean(
      editorActions && typeof editorActions.togglePublishSidebar === 'function' &&
      editorSelectors && typeof editorSelectors.isPublishSidebarOpened === 'function' &&
      editorApi && editorApi.PluginPrePublishPanel,
    );

    if (sidebarMode) {
      wp.plugins.registerPlugin('gutenberg-next-pre-publish', {
        render: function () {
          return createElement(editorApi.PluginPrePublishPanel, {
            name: 'gutenberg-next-pre-publish',
            title: 'Drupal publishing',
          }, createElement(PrePublishContent));
        },
      });

      let lastOpen = null;
      subscribe(function () {
        const store = wp.data.select('core/editor');
        if (!store || typeof store.isPublishSidebarOpened !== 'function') {
          return;
        }
        const open = store.isPublishSidebarOpened();
        if (open !== lastOpen) {
          lastOpen = open;
          document.body.classList.toggle('gutenberg-next-publish-open', open);
        }
      });

      if (!insertHeaderButton()) {
        const observer = new MutationObserver(function () {
          if (insertHeaderButton()) {
            observer.disconnect();
          }
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        window.setTimeout(function () {
          observer.disconnect();
        }, 15000);
      }
    }
    else if (editorApi && editorApi.PluginDocumentSettingPanel) {
      wp.plugins.registerPlugin('gutenberg-next-pre-publish', {
        render: function () {
          return createElement(editorApi.PluginDocumentSettingPanel, {
            name: 'gutenberg-next-pre-publish',
            title: 'Drupal publishing',
          }, createElement(PrePublishContent));
        },
      });
    }
    else {
      return false;
    }

    window.__gutenbergNextPrePublishRegistered = true;
    return true;
  }

  Drupal.behaviors.gutenbergNextPrePublish = {
    attach: function () {
      once('gutenberg-next-pre-publish', 'body').forEach(function () {
        if (registerPrePublish()) {
          return;
        }
        // The core/editor store registers after the editor boots; retry
        // briefly before giving up silently.
        let attempts = 0;
        const timer = window.setInterval(function () {
          attempts += 1;
          if (registerPrePublish() || attempts >= 20) {
            window.clearInterval(timer);
          }
        }, 500);
      });
    },
  };
})(Drupal, drupalSettings, once);
