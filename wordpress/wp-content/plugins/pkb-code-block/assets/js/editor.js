(function (blocks, element, blockEditor, components, i18n) {
  const { registerBlockType } = blocks;
  const { createElement: el, useMemo, useRef, useState } = element;
  const { useBlockProps } = blockEditor;
  const { Button, Popover } = components;
  const { __ } = i18n;

  const languages = window.PKBCodeBlock && window.PKBCodeBlock.languages ? window.PKBCodeBlock.languages : {
    plain: 'Plain Text',
    javascript: 'JavaScript',
    python: 'Python',
  };

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function highlightedHtml(code, language) {
    if (!code) {
      return '';
    }

    try {
      if (!window.Prism || language === 'plain' || !window.Prism.languages[language]) {
        return escapeHtml(code);
      }

      return window.Prism.highlight(code, window.Prism.languages[language], language);
    } catch (error) {
      return escapeHtml(code);
    }
  }

  function lineNumbers(code) {
    const count = Math.max(1, String(code || '').split('\n').length);
    return Array.from({ length: count }, function (_, index) {
      return el('span', { key: index }, String(index + 1));
    });
  }

  function CodePlusIcon() {
    return el(
      'svg',
      { width: 24, height: 24, viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' },
      el('path', {
        d: 'M9 8 5 12l4 4M15 8l4 4-4 4',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
      }),
      el('circle', { cx: 17.5, cy: 6.5, r: 4.25, fill: '#fff', stroke: 'currentColor', strokeWidth: 1.4 }),
      el('path', {
        d: 'M17.5 4.4v4.2M15.4 6.5h4.2',
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.4,
        strokeLinecap: 'round',
      })
    );
  }

  function LanguagePicker({ language, onChange }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const inputRef = useRef(null);
    const options = useMemo(function () {
      const needle = query.trim().toLowerCase();
      return Object.entries(languages).filter(function ([slug, label]) {
        return !needle || slug.includes(needle) || String(label).toLowerCase().includes(needle);
      });
    }, [query]);

    return el(
      'div',
      { className: 'pkb-code-language-picker' },
      el(
        Button,
        {
          className: 'pkb-code-language-button',
          onClick: function () {
            setOpen(!open);
            window.setTimeout(function () {
              if (inputRef.current) {
                inputRef.current.focus();
              }
            }, 0);
          },
          variant: 'secondary',
        },
        languages[language] || language
      ),
      open &&
        el(
          Popover,
          {
            className: 'pkb-code-language-popover',
            position: 'bottom right',
            onClose: function () {
              setOpen(false);
            },
          },
          el('input', {
            ref: inputRef,
            className: 'pkb-code-language-search',
            type: 'search',
            value: query,
            placeholder: __('Search language', 'pkb-code-block'),
            onChange: function (event) {
              setQuery(event.target.value);
            },
          }),
          el(
            'div',
            { className: 'pkb-code-language-options', role: 'listbox' },
            options.map(function ([slug, label]) {
              return el(
                'button',
                {
                  key: slug,
                  type: 'button',
                  className: slug === language ? 'is-selected' : '',
                  onClick: function () {
                    onChange(slug);
                    setOpen(false);
                    setQuery('');
                  },
                },
                el('span', null, label),
                el('small', null, slug)
              );
            })
          )
        )
    );
  }

  registerBlockType('pkb/code-block', {
    title: __('Extended Code Block', 'pkb-code-block'),
    description: __('Code block with language search, syntax highlighting, and line numbers.', 'pkb-code-block'),
    icon: el(CodePlusIcon),
    category: 'text',
    keywords: ['code', 'syntax', 'highlight', '코드'],
    attributes: {
      code: {
        type: 'string',
        default: '',
      },
      language: {
        type: 'string',
        default: 'plain',
      },
    },
    edit: function (props) {
      const { attributes, setAttributes, isSelected } = props;
      const code = attributes.code || '';
      const language = attributes.language || 'plain';
      const blockProps = useBlockProps({
        className: 'pkb-code-block-editor',
      });

      return el(
        'div',
        blockProps,
        el(
          'div',
          { className: 'pkb-code-block-toolbar' },
          el(LanguagePicker, {
            language: language,
            onChange: function (nextLanguage) {
              setAttributes({ language: nextLanguage });
            },
          })
        ),
        isSelected
          ? el(
              'div',
              { className: 'pkb-code-edit-shell' },
              el('div', { className: 'pkb-code-line-gutter', 'aria-hidden': 'true' }, lineNumbers(code)),
              el(
                'div',
                { className: 'pkb-code-input-layer' },
                el('pre', { className: 'pkb-code-highlight-layer', 'aria-hidden': 'true' }, el('code', {
                  className: language === 'plain' ? 'language-none' : 'language-' + language,
                  dangerouslySetInnerHTML: { __html: highlightedHtml(code || __('Write code...', 'pkb-code-block'), language) },
                })),
                el('textarea', {
                  className: 'pkb-code-textarea',
                  value: code,
                  spellCheck: false,
                  placeholder: __('Write code...', 'pkb-code-block'),
                  onChange: function (event) {
                    setAttributes({ code: event.target.value });
                  },
                })
              )
            )
          : el(
              'div',
              { className: 'pkb-code-stage pkb-code-preview-stage' },
              el('div', { className: 'pkb-code-line-gutter', 'aria-hidden': 'true' }, lineNumbers(code)),
              el(
                'pre',
                { className: 'pkb-code-preview' },
                el('code', {
                  className: language === 'plain' ? 'language-none' : 'language-' + language,
                  dangerouslySetInnerHTML: { __html: highlightedHtml(code, language) || escapeHtml(__('Write code...', 'pkb-code-block')) },
                })
              )
            )
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
