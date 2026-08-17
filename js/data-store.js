/**
 * Gutenberg Next: wp.data store for Drupal field state.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  let storeRegistered = false;

  const STORE_NAME = 'gutenberg-next/fields';

  const settings = function () {
    return drupalSettings.gutenbergNext || {};
  };

  let dispatch = null;
  let select = null;
  let scanServerErrors = function () {};
  let restoreAutosave = function () {};

  function registerDataStore() {
    if (storeRegistered) {
      return;
    }
    const wp = window.wp;
    if (!wp || !wp.data || !wp.i18n) {
      return;
    }

    const { createReduxStore, register, subscribe } = wp.data;
    const { __ } = wp.i18n;
    dispatch = wp.data.dispatch;
    select = wp.data.select;

    const DEFAULT_STATE = {
      ready: false,
      entity: { type: 'node', bundle: '', id: null },
      fields: {},
      autosaveRestored: false,
    };

    function isBlank(value) {
      return value === '' || value === null || value === undefined ||
        (Array.isArray(value) && value.length === 0);
    }

    function validateField(field, value) {
      if (field.required && isBlank(value)) {
        return { ok: false, message: __('This field is required.') };
      }
      if (field.kind === 'text' && field.maxLength && String(value).length > field.maxLength) {
        return { ok: false, message: __('The value is too long.') };
      }
      if (field.kind === 'number' && !isBlank(value)) {
        const n = Number(value);
        if (Number.isNaN(n)) {
          return { ok: false, message: __('Enter a number.') };
        }
        if (field.numberMin !== undefined && n < field.numberMin) {
          return { ok: false, message: __('The value is below the minimum.') };
        }
        if (field.numberMax !== undefined && n > field.numberMax) {
          return { ok: false, message: __('The value is above the maximum.') };
        }
      }
      if (field.kind === 'list' && !field.multiple && !isBlank(value)) {
        const options = field.options || {};
        if (!Object.prototype.hasOwnProperty.call(options, value)) {
          return { ok: false, message: __('Invalid option selected.') };
        }
      }
      return { ok: true };
    }

    function widgetRoot(fieldName) {
      const selectorName = String(fieldName).replaceAll('_', '-');
      return document.querySelector('[data-drupal-selector="edit-' + selectorName + '"]');
    }

    function setNativeValue(input, value) {
      const proto = Object.getPrototypeOf(input);
      const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
      if (descriptor && descriptor.set) {
        descriptor.set.call(input, value);
      } else {
        input.value = value;
      }
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function writeWidget(field, value) {
      const root = widgetRoot(field.name);
      if (!root) {
        return false;
      }

      if (field.kind === 'text' || field.kind === 'number') {
        const target = root.querySelector('input[type="text"], input[type="number"], textarea') ||
          (root.matches('input, textarea') ? root : null);
        if (!target) {
          return false;
        }
        setNativeValue(target, value === null || value === undefined ? '' : String(value));
        return true;
      }

      if (field.kind === 'boolean') {
        const checkbox = root.querySelector('input[type="checkbox"]');
        if (!checkbox) {
          return false;
        }
        checkbox.checked = Boolean(value);
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }

      if (field.kind === 'list') {
        if (field.multiple) {
          const checkboxes = root.querySelectorAll('input[type="checkbox"]');
          if (!checkboxes.length) {
            return false;
          }
          const selected = new Set(value || []);
          checkboxes.forEach(function (checkbox) {
            checkbox.checked = selected.has(checkbox.value);
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
          });
          return true;
        }
        const select = root.querySelector('select');
        if (select) {
          setNativeValue(select, value === null || value === undefined ? '' : String(value));
          return true;
        }
        const radio = value ? root.querySelector('input[type="radio"][value="' + CSS.escape(String(value)) + '"]') : null;
        if (radio) {
          radio.checked = true;
          radio.dispatchEvent(new Event('change', { bubbles: true }));
          return true;
        }
        return false;
      }

      if (field.kind === 'datetime') {
        const dateInput = root.querySelector('input[type="date"]');
        const timeInput = root.querySelector('input[type="time"]');
        if (field.datetimeStorageFormat === 'Y-m-d') {
          if (!dateInput) {
            return false;
          }
          setNativeValue(dateInput, value === null || value === undefined ? '' : String(value));
          return true;
        }
        if (!dateInput && !timeInput) {
          return false;
        }
        const parts = String(value || '').split('T');
        if (dateInput) {
          setNativeValue(dateInput, parts[0] || '');
        }
        if (timeInput) {
          setNativeValue(timeInput, (parts[1] || '').slice(0, 5));
        }
        return true;
      }

      if (field.kind === 'entity_reference') {
        const input = root.querySelector('input[data-autocomplete-path]');
        if (input) {
          const items = Array.isArray(value) ? value : [value];
          const label = items
            .filter(function (item) { return item && item.id; })
            .map(function (item) { return item.label + ' (' + item.id + ')'; })
            .join(', ');
          setNativeValue(input, label);
          return true;
        }
        const select = root.querySelector('select');
        if (select && !field.multiple) {
          const item = Array.isArray(value) ? value[0] : value;
          setNativeValue(select, item && item.id ? String(item.id) : '');
          return true;
        }
        const checkboxes = root.querySelectorAll('input[type="checkbox"]');
        if (checkboxes.length) {
          const ids = new Set((Array.isArray(value) ? value : []).map(function (item) { return String(item.id); }));
          checkboxes.forEach(function (checkbox) {
            checkbox.checked = ids.has(checkbox.value);
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
          });
          return true;
        }
        return false;
      }

      return false;
    }

    const actions = {
      load: function (payload) {
        return { type: 'LOAD', payload: payload };
      },
      setFieldValue: function (name, value) {
        const field = select(STORE_NAME).getField(name);
        if (!field) {
          return { type: 'NOOP' };
        }
        const result = validateField(field, value);
        if (!result.ok) {
          return { type: 'SET_INVALID', name: name, message: result.message };
        }
        if (!writeWidget(field, value)) {
          return { type: 'SET_INVALID', name: name, message: __('The form widget for this field is not available in the editor.') };
        }
        return { type: 'SET_FIELD_VALUE', name: name, value: value };
      },
      setInvalid: function (name, message) {
        return { type: 'SET_INVALID', name: name, message: message };
      },
      markSaved: function () {
        return { type: 'MARK_SAVED' };
      },
      setAutosaveRestored: function (restored) {
        return { type: 'SET_AUTOSAVE_RESTORED', restored: restored };
      },
    };

    const reducer = function (state, action) {
      if (state === undefined) {
        state = DEFAULT_STATE;
      }
      switch (action.type) {
        case 'LOAD': {
          const fields = {};
          (action.payload.entity.fields || []).forEach(function (field) {
            fields[field.name] = Object.assign({}, field, { dirty: false, invalid: null });
          });
          return Object.assign({}, DEFAULT_STATE, {
            ready: true,
            entity: action.payload.entity,
            fields: fields,
          });
        }
        case 'SET_FIELD_VALUE': {
          const field = state.fields[action.name];
          if (!field) {
            return state;
          }
          return Object.assign({}, state, {
            fields: Object.assign({}, state.fields, {
              [action.name]: Object.assign({}, field, { value: action.value, dirty: true, invalid: null }),
            }),
          });
        }
        case 'SET_INVALID':
          if (!state.fields[action.name]) {
            return state;
          }
          return Object.assign({}, state, {
            fields: Object.assign({}, state.fields, {
              [action.name]: Object.assign({}, state.fields[action.name], {
                invalid: action.message ? { message: action.message } : null,
              }),
            }),
          });
        case 'MARK_SAVED': {
          const fields = {};
          Object.keys(state.fields).forEach(function (name) {
            fields[name] = Object.assign({}, state.fields[name], { dirty: false });
          });
          return Object.assign({}, state, { fields: fields });
        }
        case 'SET_AUTOSAVE_RESTORED':
          return Object.assign({}, state, { autosaveRestored: state.autosaveRestored || action.restored });
        default:
          return state;
      }
    };

    const selectors = {
      getField: function (state, name) {
        return state.fields[name] || null;
      },
      getFields: function (state) {
        return state.fields;
      },
      getValue: function (state, name) {
        const field = state.fields[name];
        return field ? field.value : undefined;
      },
      isDirty: function (state) {
        return Object.values(state.fields).some(function (field) { return field.dirty; });
      },
      isFieldDirty: function (state, name) {
        return Boolean(state.fields[name] && state.fields[name].dirty);
      },
      getEntity: function (state) {
        return state.entity;
      },
      isReady: function (state) {
        return state.ready;
      },
      wasAutosaveRestored: function (state) {
        return state.autosaveRestored;
      },
    };

    const store = createReduxStore(STORE_NAME, {
      reducer: reducer,
      actions: actions,
      selectors: selectors,
    });
    register(store);

    function createNotice(message, status) {
      if (!wp.data.dispatch('core/notices')) {
        return;
      }
      wp.data.dispatch('core/notices').createNotice(status || 'info', message, {
        type: 'snackbar',
        isDismissible: true,
      });
    }

    function autosaveToken() {
      const cfg = settings().autosave || {};
      return cfg.token || (drupalSettings.gutenberg && drupalSettings.gutenberg.csrfToken) || '';
    }

    function autosaveRequest(method, body) {
      const cfg = settings().autosave || {};
      if (!cfg.enabled || !cfg.url) {
        return Promise.resolve(null);
      }
      return fetch(cfg.url, {
        method: method,
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': autosaveToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('autosave ' + method + ' failed: ' + response.status);
        }
        return response.json();
      });
    }

    let autosaveTimer = null;
    let autosaveFailed = false;

    function scheduleAutosave() {
      window.clearTimeout(autosaveTimer);
      autosaveTimer = window.setTimeout(function () {
        const current = select(STORE_NAME).getFields();
        const payload = {};
        Object.keys(current).forEach(function (name) {
          if (current[name].dirty) {
            payload[name] = current[name].value;
          }
        });
        autosaveRequest('POST', { fields: payload })
          .then(function () { autosaveFailed = false; })
          .catch(function () {
            if (!autosaveFailed) {
              autosaveFailed = true;
              createNotice(__('Could not autosave Drupal field changes.'), 'warning');
            }
          });
      }, 2000);
    }

    let lastDirty = false;
    subscribe(function () {
      if (!select(STORE_NAME).isReady()) {
        return;
      }
      const dirty = select(STORE_NAME).isDirty();
      if (dirty && !lastDirty) {
        if (wp.data.dispatch('core/editor') && wp.data.dispatch('core/editor').__unstableMarkEditorAsDirty) {
          wp.data.dispatch('core/editor').__unstableMarkEditorAsDirty();
        }
      }
      if (dirty) {
        scheduleAutosave();
      }
      lastDirty = dirty;
    });

    document.addEventListener('submit', function (event) {
      const target = event.target;
      if (!(target instanceof HTMLFormElement) || !target.classList.contains('node-form')) {
        return;
      }
      window.clearTimeout(autosaveTimer);
      dispatch(STORE_NAME).markSaved();
      autosaveRequest('DELETE').catch(function () {});
    }, true);

    window.addEventListener('beforeunload', function (event) {
      if (select(STORE_NAME).isReady() && select(STORE_NAME).isDirty()) {
        event.preventDefault();
        event.returnValue = '';
      }
    });

    scanServerErrors = function () {
      const fields = select(STORE_NAME).getFields();
      Object.keys(fields).forEach(function (name) {
        const root = widgetRoot(name);
        if (!root) {
          return;
        }
        const error = root.closest('.form-item--error') ||
          root.querySelector('[aria-invalid="true"], .form-item--error');
        if (error) {
          const messageEl = error.querySelector('.form-item--error-message');
          dispatch(STORE_NAME).setInvalid(name, messageEl ? messageEl.textContent.trim() : __('Validation error.'));
        }
      });
    };

    restoreAutosave = function () {
      const cfg = settings().autosave || {};
      if (!cfg.enabled || !cfg.url) {
        return;
      }
      autosaveRequest('GET')
        .then(function (response) {
          const data = response && response.data;
          if (!data || typeof data !== 'object') {
            return;
          }
          const current = select(STORE_NAME).getFields();
          const differs = Object.keys(data).some(function (name) {
            const field = current[name];
            if (!field) {
              return false;
            }
            return JSON.stringify(data[name]) !== JSON.stringify(field.value);
          });
          if (!differs) {
            autosaveRequest('DELETE').catch(function () {});
            return;
          }
          Object.keys(data).forEach(function (name) {
            if (current[name]) {
              dispatch(STORE_NAME).setFieldValue(name, data[name]);
            }
          });
          dispatch(STORE_NAME).setAutosaveRestored(true);
          createNotice(__('Drupal field changes restored from autosave.'));
        })
        .catch(function () {});
    };

    storeRegistered = true;
  }

  if (window.wp && window.wp.data && window.wp.i18n && !storeRegistered) {
    registerDataStore();
  }

  Drupal.behaviors.gutenbergNextDataStore = {
    attach: function () {
      once('gutenberg-next-data-store', 'body').forEach(function () {
        const payload = settings();
        if (!payload.entity) {
          return;
        }
        let attempts = 0;
        const loadPayload = function () {
          registerDataStore();
          if (!storeRegistered) {
            attempts += 1;
            if (attempts < 10) {
              window.setTimeout(loadPayload, 500);
            }
            return;
          }
          dispatch(STORE_NAME).load(payload);
          scanServerErrors();
          restoreAutosave();
        };
        loadPayload();
      });
    },
  };
})(Drupal, drupalSettings, once);
