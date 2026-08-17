/**
 * Gutenberg Next: store-driven Drupal field panel.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  if (!wp || !wp.plugins || !wp.data || !wp.element || !wp.components) {
    return;
  }

  const STORE_NAME = 'gutenberg-next/fields';
  const { createElement, useState, useEffect } = wp.element;
  const { Button, Notice, TextControl, TextareaControl, ToggleControl, SelectControl, CheckboxControl, FormTokenField, ComboboxControl } = wp.components;
  const editorApi = wp.editor || wp.editPost;
  const PluginDocumentSettingPanel = editorApi && editorApi.PluginDocumentSettingPanel;
  const { dispatch } = wp.data;

  function createNotice(message, status) {
    if (wp.data.dispatch('core/notices')) {
      wp.data.dispatch('core/notices').createNotice(status || 'info', message, {
        type: 'snackbar',
        isDismissible: true,
      });
    }
  }

  function FieldControl(props) {
    const field = props.field;
    const onChange = function (value) {
      dispatch(STORE_NAME).setFieldValue(field.name, value);
    };

    if (field.kind === 'text' && field.maxLength && field.maxLength <= 512) {
      return createElement(TextControl, {
        label: field.label,
        value: field.value || '',
        onChange: onChange,
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'text') {
      return createElement(TextareaControl, {
        label: field.label,
        value: field.value || '',
        onChange: onChange,
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'number') {
      return createElement(TextControl, {
        type: 'number',
        label: field.label,
        value: field.value === null || field.value === undefined ? '' : String(field.value),
        onChange: function (next) { onChange(next === '' ? null : Number(next)); },
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'boolean') {
      return createElement(ToggleControl, {
        label: field.label,
        checked: Boolean(field.value),
        onChange: function (checked) { onChange(checked); },
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'list' && field.multiple) {
      const options = field.options || {};
      return createElement(
        'div',
        { className: 'gutenberg-next-checkboxes' },
        Object.keys(options).map(function (key) {
          return createElement(CheckboxControl, {
            key: key,
            label: options[key],
            checked: (field.value || []).includes(key),
            onChange: function (checked) {
              const next = new Set(field.value || []);
              if (checked) {
                next.add(key);
              } else {
                next.delete(key);
              }
              onChange([...next]);
            },
          });
        }),
        field.invalid ? createElement(Notice, { status: 'error', isDismissible: false }, field.invalid.message) : null,
      );
    }
    if (field.kind === 'list') {
      const options = field.options || {};
      const choices = Object.keys(options).map(function (key) {
        return { value: key, label: options[key] };
      });
      choices.unshift({ value: '', label: '- None -' });
      return createElement(SelectControl, {
        label: field.label,
        value: field.value || '',
        options: choices,
        onChange: onChange,
        help: field.invalid ? field.invalid.message : null,
        className: field.invalid ? 'gutenberg-next-field-invalid' : undefined,
      });
    }
    if (field.kind === 'datetime') {
      return createElement(DateTimeControl, {
        field: field,
        onChange: onChange,
      });
    }
    if (field.kind === 'entity_reference') {
      return createElement(EntityReferenceControl, {
        field: field,
        onChange: onChange,
      });
    }
    return createElement(ComplexControl, {
      field: field,
    });
  }

  function formatStorageValue(field) {
    const value = field.value || '';
    if (field.datetimeStorageFormat === 'Y-m-d') {
      return value;
    }
    return value.length >= 16 ? value.slice(0, 16) : value;
  }

  function DateTimeControl(props) {
    const { field } = props;
    const [localValue, setLocalValue] = useState(formatStorageValue(field));

    useEffect(function () {
      setLocalValue(formatStorageValue(field));
    }, [field.value]);

    if (field.datetimeStorageFormat === 'Y-m-d') {
      return createElement(TextControl, {
        type: 'date',
        label: field.label,
        value: localValue,
        onChange: function (next) {
          setLocalValue(next);
          props.onChange(next ? next : null);
        },
        help: field.invalid ? field.invalid.message : null,
      });
    }

    return createElement(TextControl, {
      type: 'datetime-local',
      label: field.label,
      value: localValue,
      onChange: function (next) {
        setLocalValue(next);
        props.onChange(next ? next + ':00' : null);
      },
      help: field.invalid ? field.invalid.message : null,
    });
  }

  function EntityReferenceControl(props) {
    const { field } = props;
    const items = Array.isArray(field.value) ? field.value : [];
    const [suggestions, setSuggestions] = useState([]);
    const [loading, setLoading] = useState(false);

    function searchEntities(query) {
      if (!field.autocompleteUrl) {
        return;
      }
      setLoading(true);
      wp.apiFetch({ path: field.autocompleteUrl + '&q=' + encodeURIComponent(query) })
        .then(function (matches) {
          setSuggestions(matches.map(function (match) {
            const idMatch = String(match.value).match(/\((\d+)\)$/);
            return {
              id: Number(idMatch ? idMatch[1] : 0),
              label: match.label,
            };
          }));
        })
        .catch(function () {
          setSuggestions([]);
        })
        .finally(function () {
          setLoading(false);
        });
    }

    const tokenValues = items.map(function (item) { return item.label + ' (' + item.id + ')'; });

    if (field.multiple) {
      return createElement(FormTokenField, {
        label: field.label,
        value: tokenValues,
        suggestions: suggestions.map(function (s) { return s.label + ' (' + s.id + ')'; }),
        onChange: function (tokens) {
          const next = tokens.map(function (token) {
            const idMatch = String(token).match(/\((\d+)\)$/);
            const existing = items.find(function (item) { return String(item.id) === String(idMatch ? idMatch[1] : ''); });
            return existing || { id: Number(idMatch ? idMatch[1] : 0), label: String(token).replace(/\s*\(\d+\)$/, '') };
          }).filter(function (item) { return item.id > 0; });
          props.onChange(next);
        },
        onInputChange: searchEntities,
        tokenizeOnBlur: false,
        help: loading ? 'Searching…' : (field.invalid ? field.invalid.message : null),
      });
    }

    return createElement(ComboboxControl, {
      label: field.label,
      value: items.length ? { value: String(items[0].id), label: items[0].label } : null,
      options: suggestions.map(function (s) { return { value: String(s.id), label: s.label }; }),
      onInputChange: function (inputValue) {
        searchEntities(inputValue || '');
      },
      onChange: function (option) {
        props.onChange(option ? [{ id: Number(option.value), label: option.label }] : []);
      },
      help: field.invalid ? field.invalid.message : null,
    });
  }

  function ComplexControl(props) {
    const { field } = props;
    const detail = (field.value && field.value.detail) || [];
    return createElement(
      'div',
      { className: 'gutenberg-next-complex-field' },
      createElement('p', null, detail.length ? detail.join('; ') : '(empty)'),
      field.invalid ? createElement(Notice, { status: 'error', isDismissible: false }, field.invalid.message) : null,
      createElement(Button, {
        variant: 'secondary',
        size: 'compact',
        onClick: function () {
          if (!window.GutenbergNext || !window.GutenbergNext.focusDrupalField(field.name)) {
            createNotice('Drupal field "' + field.label + '" is not currently visible on the form.', 'warning');
          }
        },
      }, 'Edit in form'),
    );
  }

  function FieldPanelBody(props) {
    const fields = props.fields;
    const names = Object.keys(fields).sort(function (a, b) {
      return fields[a].label.localeCompare(fields[b].label);
    });

    return createElement(
      PluginDocumentSettingPanel,
      {
        name: 'gutenberg-next-drupal-fields',
        title: 'Drupal fields',
        className: 'gutenberg-next-document-panel',
      },
      names.map(function (name) {
        const field = fields[name];
        return createElement(
          'div',
          { key: name, className: 'gutenberg-next-field-row' },
          createElement(FieldControl, { field: field }),
        );
      }),
    );
  }

  const FieldPanel = wp.data.withSelect
    ? wp.data.withSelect(function (selectFn) {
        return { fields: selectFn(STORE_NAME).getFields() };
      })(FieldPanelBody)
    : FieldPanelBody;

  Drupal.behaviors.gutenbergNextFieldPanel = {
    attach: function () {
      once('gutenberg-next-field-panel', 'body').forEach(function () {
        const config = drupalSettings.gutenbergNext || {};
        if (!config.showFieldPanel || !PluginDocumentSettingPanel) {
          return;
        }
        if (window.__gutenbergNextFieldPanelRegistered) {
          return;
        }
        if (!wp.data.withSelect && config.debug) {
          // eslint-disable-next-line no-console
          console.warn('[Gutenberg Next] wp.data.withSelect is unavailable; the field panel will not re-render on store changes.');
        }
        wp.plugins.registerPlugin('gutenberg-next-drupal-fields', { render: FieldPanel });
        window.__gutenbergNextFieldPanelRegistered = true;
      });
    },
  };
})(Drupal, drupalSettings, once);
