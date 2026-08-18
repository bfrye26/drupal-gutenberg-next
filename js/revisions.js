/**
 * Gutenberg Next: Drupal revision browser and visual comparison.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const wp = window.wp;
  const config = drupalSettings.gutenbergNext || {};
  if (!wp || !wp.plugins || !wp.element || !wp.components || !wp.i18n ||
    !config.revisions || !config.revisions.enabled) {
    return;
  }

  const revisionsConfig = config.revisions;
  const { createElement, useState, useEffect } = wp.element;
  const { Button, CheckboxControl, Modal, Notice, Spinner } = wp.components;
  const editorApi = wp.editor || wp.editPost;
  const { __ } = wp.i18n;

  function formatDate(ts) {
    if (!ts) {
      return '';
    }
    return new Date(ts * 1000).toLocaleString();
  }

  function RevisionPane(props) {
    const [state, setState] = useState({ loading: true, error: null, data: null });

    useEffect(function () {
      let cancelled = false;
      fetch(revisionsConfig.listUrl + '/' + props.vid, { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('revision fetch failed: ' + response.status);
          }
          return response.json();
        })
        .then(function (data) {
          if (!cancelled) {
            setState({ loading: false, error: null, data: data });
          }
        })
        .catch(function (error) {
          if (!cancelled) {
            setState({ loading: false, error: String(error.message || error), data: null });
          }
        });
      return function () {
        cancelled = true;
      };
    }, [props.vid]);

    if (state.loading) {
      return createElement('div', { className: 'gutenberg-next-revision-pane' }, createElement(Spinner));
    }
    if (state.error) {
      return createElement('div', { className: 'gutenberg-next-revision-pane' },
        createElement(Notice, { status: 'error', isDismissible: false }, state.error));
    }
    const data = state.data;
    return createElement('div', { className: 'gutenberg-next-revision-pane' },
      createElement('h3', null, data.title),
      createElement('p', { className: 'gutenberg-next-revision-meta' },
        formatDate(data.timestamp) + ' — ' + (data.authorName || __('Anonymous')) +
        (data.log ? ' — ' + data.log : '')),
      createElement('div', {
        className: 'gutenberg-next-revision-view',
        dangerouslySetInnerHTML: { __html: data.html },
      }),
    );
  }

  function RevisionsPanel() {
    const [state, setState] = useState({
      loaded: false,
      loading: false,
      error: null,
      revisions: [],
      selected: [],
      compareOpen: false,
    });

    useEffect(function () {
      if (state.loaded || state.loading) {
        return;
      }
      setState(function (prev) {
        return Object.assign({}, prev, { loading: true });
      });
      fetch(revisionsConfig.listUrl, { credentials: 'same-origin' })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('revision list failed: ' + response.status);
          }
          return response.json();
        })
        .then(function (payload) {
          setState(function (prev) {
            return Object.assign({}, prev, {
              loaded: true,
              loading: false,
              revisions: payload.revisions || [],
            });
          });
        })
        .catch(function (error) {
          setState(function (prev) {
            return Object.assign({}, prev, {
              loaded: true,
              loading: false,
              error: String(error.message || error),
            });
          });
        });
    }, [state.loaded, state.loading]);

    function toggleSelect(vid) {
      setState(function (prev) {
        let selected = prev.selected.slice();
        if (selected.includes(vid)) {
          selected = selected.filter(function (v) { return v !== vid; });
        }
        else {
          selected.push(vid);
          if (selected.length > 2) {
            selected.shift();
          }
        }
        return Object.assign({}, prev, { selected: selected });
      });
    }

    if (state.loading) {
      return createElement('p', null, __('Loading revisions…'));
    }
    if (state.error) {
      return createElement(Notice, { status: 'error', isDismissible: false }, state.error);
    }
    if (!state.revisions.length) {
      return createElement('p', null, __('No revisions yet.'));
    }

    return createElement('div', { className: 'gutenberg-next-revisions-panel' },
      state.revisions.map(function (revision) {
        return createElement('div', { key: revision.vid, className: 'gutenberg-next-revision-row' },
          createElement(CheckboxControl, {
            label: formatDate(revision.timestamp) + ' — ' + (revision.authorName || __('Anonymous')),
            checked: state.selected.includes(revision.vid),
            onChange: function () {
              toggleSelect(revision.vid);
            },
          }),
          createElement('div', { className: 'gutenberg-next-revision-info' },
            revision.isDefault ? createElement('span', { className: 'gutenberg-next-revision-current' }, __('Current')) : null,
            revision.log ? createElement('div', null, revision.log) : null,
          ),
          !revision.isDefault ? createElement(Button, {
            variant: 'link',
            onClick: function () {
              window.location = revisionsConfig.revertUrlBase + revision.vid + '/revert';
            },
          }, __('Restore')) : null,
        );
      }),
      createElement(Button, {
        variant: 'secondary',
        disabled: state.selected.length !== 2,
        onClick: function () {
          setState(function (prev) {
            return Object.assign({}, prev, { compareOpen: true });
          });
        },
      }, __('Compare selected')),
      state.compareOpen ? createElement(Modal, {
        title: __('Compare revisions'),
        className: 'gutenberg-next-revision-compare',
        onRequestClose: function () {
          setState(function (prev) {
            return Object.assign({}, prev, { compareOpen: false });
          });
        },
      },
        createElement('div', { className: 'gutenberg-next-revision-compare-grid' },
          createElement(RevisionPane, { vid: state.selected[0] }),
          createElement(RevisionPane, { vid: state.selected[1] }),
        )) : null,
    );
  }

  Drupal.behaviors.gutenbergNextRevisions = {
    attach: function () {
      once('gutenberg-next-revisions', 'body').forEach(function () {
        if (window.__gutenbergNextRevisionsRegistered) {
          return;
        }
        if (!editorApi || !editorApi.PluginDocumentSettingPanel) {
          return;
        }
        wp.plugins.registerPlugin('gutenberg-next-revisions', {
          render: function () {
            return createElement(editorApi.PluginDocumentSettingPanel, {
              name: 'gutenberg-next-revisions',
              title: 'Revisions',
            }, createElement(RevisionsPanel));
          },
        });
        window.__gutenbergNextRevisionsRegistered = true;
      });
    },
  };
})(Drupal, drupalSettings, once);
