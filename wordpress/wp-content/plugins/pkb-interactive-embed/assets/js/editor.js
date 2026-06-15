(function () {
  if (!window.wp || !window.wp.blocks || !window.wp.blockEditor || !window.wp.components) return;

  const { registerBlockType } = window.wp.blocks;
  const { InspectorControls, useBlockProps } = window.wp.blockEditor;
  const {
    BaseControl,
    Button,
    PanelBody,
    Placeholder,
    TextControl,
    TextareaControl,
    ToggleControl,
  } = window.wp.components;
  const { createElement: el, useState } = window.wp.element;
  const { __ } = window.wp.i18n;
  const ServerSideRender = window.wp.serverSideRender;
  const defaults = window.pkbInteractiveEmbedDefaults || {};

  const defaultAttributes = {
    src: '',
    title: '',
    caption: '',
    height: Number(defaults.height || 680),
    mobileHeight: Number(defaults.mobileHeight || 540),
    aspectRatio: String(defaults.aspectRatio || ''),
    linkLabel: __('Open interactive model', 'pkb-interactive-embed'),
    fallback: __('This interactive model cannot be embedded from the provided source.', 'pkb-interactive-embed'),
    allowScroll: true,
  };

  function numericValue(value, fallback) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function Fieldset({ attributes, setAttributes, sourceValue = null, onSourceChange = null, onSourceEnter = null }) {
    return el('div', { className: 'pkb-interactive-embed-editor-fields' },
      el(TextControl, {
        label: __('Source URL', 'pkb-interactive-embed'),
        value: sourceValue === null ? (attributes.src || '') : sourceValue,
        placeholder: 'https://mayobloom.github.io/mobigist-model-renderer/models/traffic-flow/',
        onChange: (src) => {
          if (onSourceChange) {
            onSourceChange(src);
            return;
          }
          setAttributes({ src });
        },
        onKeyDown: (event) => {
          if (event.key === 'Enter' && onSourceEnter) {
            event.preventDefault();
            event.stopPropagation();
            onSourceEnter();
          }
        },
      }),
      el(TextControl, {
        label: __('Title', 'pkb-interactive-embed'),
        value: attributes.title || '',
        onChange: (title) => setAttributes({ title }),
      }),
      el(TextareaControl, {
        label: __('Caption', 'pkb-interactive-embed'),
        value: attributes.caption || '',
        rows: 3,
        onChange: (caption) => setAttributes({ caption }),
      }),
      el('div', { className: 'pkb-interactive-embed-editor-grid' },
        el(TextControl, {
          label: __('Height', 'pkb-interactive-embed'),
          type: 'number',
          min: 120,
          value: attributes.height ?? defaultAttributes.height,
          onChange: (height) => setAttributes({ height: numericValue(height, defaultAttributes.height) }),
        }),
        el(TextControl, {
          label: __('Mobile height', 'pkb-interactive-embed'),
          type: 'number',
          min: 120,
          value: attributes.mobileHeight ?? defaultAttributes.mobileHeight,
          onChange: (mobileHeight) => setAttributes({ mobileHeight: numericValue(mobileHeight, defaultAttributes.mobileHeight) }),
        })
      ),
      el(TextControl, {
        label: __('Aspect ratio', 'pkb-interactive-embed'),
        value: attributes.aspectRatio || '',
        placeholder: '16:9',
        help: __('Optional. Leave empty to use fixed heights.', 'pkb-interactive-embed'),
        onChange: (aspectRatio) => setAttributes({ aspectRatio }),
      }),
      el(ToggleControl, {
        label: __('Allow internal scrolling', 'pkb-interactive-embed'),
        checked: attributes.allowScroll !== false,
        onChange: (allowScroll) => setAttributes({ allowScroll }),
      })
    );
  }

  function InsertPlaceholder({ attributes, setAttributes }) {
    const [draftSrc, setDraftSrc] = useState(attributes.src || '');
    const [draftTitle, setDraftTitle] = useState(attributes.title || '');
    const [draftCaption, setDraftCaption] = useState(attributes.caption || '');
    const [draftHeight, setDraftHeight] = useState(attributes.height ?? defaultAttributes.height);
    const [draftMobileHeight, setDraftMobileHeight] = useState(attributes.mobileHeight ?? defaultAttributes.mobileHeight);
    const [draftAspectRatio, setDraftAspectRatio] = useState(attributes.aspectRatio || '');
    const [draftAllowScroll, setDraftAllowScroll] = useState(attributes.allowScroll !== false);

    const draftAttributes = {
      ...attributes,
      src: '',
      title: draftTitle,
      caption: draftCaption,
      height: draftHeight,
      mobileHeight: draftMobileHeight,
      aspectRatio: draftAspectRatio,
      allowScroll: draftAllowScroll,
    };

    function insert() {
      const src = draftSrc.trim();
      if (!src) return;
      setAttributes({
        src,
        title: draftTitle,
        caption: draftCaption,
        height: numericValue(draftHeight, defaultAttributes.height),
        mobileHeight: numericValue(draftMobileHeight, defaultAttributes.mobileHeight),
        aspectRatio: draftAspectRatio,
        allowScroll: draftAllowScroll,
      });
    }

    function cancel() {
      setDraftSrc('');
      setDraftTitle('');
      setDraftCaption('');
      setDraftHeight(defaultAttributes.height);
      setDraftMobileHeight(defaultAttributes.mobileHeight);
      setDraftAspectRatio(defaultAttributes.aspectRatio);
      setDraftAllowScroll(defaultAttributes.allowScroll);
    }

    return el(Placeholder, {
      icon: 'chart-line',
      label: __('Interactive Graph', 'pkb-interactive-embed'),
      instructions: __('Enter a trusted model URL, then insert the graph.', 'pkb-interactive-embed'),
    },
      el(Fieldset, {
        attributes: draftAttributes,
        setAttributes: (next) => {
          if (Object.prototype.hasOwnProperty.call(next, 'title')) setDraftTitle(next.title);
          if (Object.prototype.hasOwnProperty.call(next, 'caption')) setDraftCaption(next.caption);
          if (Object.prototype.hasOwnProperty.call(next, 'height')) setDraftHeight(next.height);
          if (Object.prototype.hasOwnProperty.call(next, 'mobileHeight')) setDraftMobileHeight(next.mobileHeight);
          if (Object.prototype.hasOwnProperty.call(next, 'aspectRatio')) setDraftAspectRatio(next.aspectRatio);
          if (Object.prototype.hasOwnProperty.call(next, 'allowScroll')) setDraftAllowScroll(next.allowScroll);
        },
        sourceValue: draftSrc,
        onSourceChange: setDraftSrc,
        onSourceEnter: insert,
      }),
      el('div', { className: 'pkb-interactive-embed-editor-actions' },
        el(Button, {
          variant: 'primary',
          onClick: insert,
          disabled: !draftSrc.trim(),
        }, __('Insert', 'pkb-interactive-embed')),
        el(Button, {
          variant: 'secondary',
          onClick: cancel,
        }, __('Cancel', 'pkb-interactive-embed'))
      )
    );
  }

  registerBlockType('pkb/interactive-graph', {
    apiVersion: 2,
    title: __('Interactive Graph', 'pkb-interactive-embed'),
    description: __('Embed a trusted interactive model page.', 'pkb-interactive-embed'),
    category: 'embed',
    icon: 'chart-line',
    keywords: [
      __('graph', 'pkb-interactive-embed'),
      __('model', 'pkb-interactive-embed'),
      __('interactive', 'pkb-interactive-embed'),
    ],
    attributes: {
      src: { type: 'string', default: defaultAttributes.src },
      title: { type: 'string', default: defaultAttributes.title },
      caption: { type: 'string', default: defaultAttributes.caption },
      height: { type: 'number', default: defaultAttributes.height },
      mobileHeight: { type: 'number', default: defaultAttributes.mobileHeight },
      aspectRatio: { type: 'string', default: defaultAttributes.aspectRatio },
      linkLabel: { type: 'string', default: defaultAttributes.linkLabel },
      fallback: { type: 'string', default: defaultAttributes.fallback },
      allowScroll: { type: 'boolean', default: defaultAttributes.allowScroll },
    },
    supports: {
      html: false,
    },
    edit({ attributes, setAttributes }) {
      const blockProps = useBlockProps({ className: 'pkb-interactive-embed-editor' });
      const allowedDomains = Array.isArray(defaults.allowedDomains) ? defaults.allowedDomains : [];

      return el('div', blockProps,
        el(InspectorControls, null,
          el(PanelBody, {
            title: __('Interactive graph settings', 'pkb-interactive-embed'),
            initialOpen: true,
          },
            el(Fieldset, { attributes, setAttributes }),
            allowedDomains.length > 0 && el(BaseControl, {
              label: __('Allowed domains', 'pkb-interactive-embed'),
            },
              el('p', { className: 'pkb-interactive-embed-editor-help' }, allowedDomains.join(', '))
            )
          ),
          el(PanelBody, {
            title: __('Link and fallback text', 'pkb-interactive-embed'),
            initialOpen: false,
          },
            el(TextControl, {
              label: __('Link label', 'pkb-interactive-embed'),
              value: attributes.linkLabel || '',
              onChange: (linkLabel) => setAttributes({ linkLabel }),
            }),
            el(TextareaControl, {
              label: __('Fallback message', 'pkb-interactive-embed'),
              value: attributes.fallback || '',
              rows: 3,
              onChange: (fallback) => setAttributes({ fallback }),
            })
          )
        ),
        attributes.src
          ? el(ServerSideRender, {
            block: 'pkb/interactive-graph',
            attributes,
          })
          : el(InsertPlaceholder, { attributes, setAttributes })
      );
    },
    save() {
      return null;
    },
  });
}());
