(function () {
  if (!window.wp || !window.wp.blockEditor || !window.wp.components || !window.wp.hooks) return;

  const { BlockControls, InspectorControls } = window.wp.blockEditor;
  const { Button, Modal, PanelBody, TabPanel, ToolbarButton, ToolbarGroup } = window.wp.components;
  const { createHigherOrderComponent } = window.wp.compose;
  const { createElement: el, Fragment, useEffect, useMemo, useRef, useState } = window.wp.element;
  const { addFilter } = window.wp.hooks;
  const { __ } = window.wp.i18n;
  const { registerPlugin } = window.wp.plugins || {};
  const dataApi = window.wp.data || {};

  const presets = {
    basic: [
      { label: 'a/b', title: __('Fraction', 'pkb-math-tools'), value: '\\frac{}{}' },
      { label: '√', title: __('Square root', 'pkb-math-tools'), value: '\\sqrt{}' },
      { label: 'x²', title: __('Power', 'pkb-math-tools'), value: '^{}' },
      { label: 'x_i', title: __('Subscript', 'pkb-math-tools'), value: '_{}' },
      { label: 'Σ', title: __('Summation', 'pkb-math-tools'), value: '\\sum_{i=1}^{n} ' },
      { label: '∫', title: __('Integral', 'pkb-math-tools'), value: '\\int_{a}^{b} ' },
      { label: 'lim', title: __('Limit', 'pkb-math-tools'), value: '\\lim_{x \\to 0} ' },
      { label: '()', title: __('Parentheses', 'pkb-math-tools'), value: '\\left(  \\right)' },
      { label: '[]', title: __('Brackets', 'pkb-math-tools'), value: '\\left[  \\right]' },
      { label: 'matrix', title: __('2 by 2 matrix with brackets', 'pkb-math-tools'), value: '\\begin{bmatrix} a & b \\\\ c & d \\end{bmatrix}' },
    ],
    linear: [
      { label: 'x_vec', title: __('Vector', 'pkb-math-tools'), value: '\\mathbf{x} ' },
      { label: 'A^T', title: __('Matrix transpose', 'pkb-math-tools'), value: 'A^\\top ' },
      { label: 'A^-1', title: __('Matrix inverse', 'pkb-math-tools'), value: 'A^{-1} ' },
      { label: '||x||2', title: __('L2 norm', 'pkb-math-tools'), value: '\\lVert x \\rVert_2 ' },
      { label: '||x||1', title: __('L1 norm', 'pkb-math-tools'), value: '\\lVert x \\rVert_1 ' },
      { label: '<x,y>', title: __('Inner product', 'pkb-math-tools'), value: '\\langle x, y \\rangle ' },
      { label: 'rank', title: __('Matrix rank', 'pkb-math-tools'), value: '\\operatorname{rank}(A) ' },
      { label: 'tr', title: __('Trace', 'pkb-math-tools'), value: '\\operatorname{tr}(A) ' },
      { label: 'det', title: __('Determinant', 'pkb-math-tools'), value: '\\det(A) ' },
      { label: 'Ax=b', title: __('Linear system', 'pkb-math-tools'), value: 'Ax = b ' },
      { label: 'quad', title: __('Quadratic form', 'pkb-math-tools'), value: 'x^\\top A x ' },
      { label: 'diag', title: __('Diagonal matrix', 'pkb-math-tools'), value: '\\operatorname{diag}(x) ' },
    ],
    probability: [
      { label: 'E[X]', title: __('Expectation', 'pkb-math-tools'), value: '\\mathbb{E}[X] ' },
      { label: 'E[Y|X]', title: __('Conditional expectation', 'pkb-math-tools'), value: '\\mathbb{E}[Y \\mid X] ' },
      { label: 'Var', title: __('Variance', 'pkb-math-tools'), value: '\\mathrm{Var}(X) ' },
      { label: 'Cov', title: __('Covariance', 'pkb-math-tools'), value: '\\mathrm{Cov}(X,Y) ' },
      { label: 'P(A|B)', title: __('Conditional probability', 'pkb-math-tools'), value: 'P(A \\mid B) ' },
      { label: 'N(mu,s2)', title: __('Normal distribution', 'pkb-math-tools'), value: 'X \\sim \\mathcal{N}(\\mu, \\sigma^2) ' },
      { label: 'Bern', title: __('Bernoulli distribution', 'pkb-math-tools'), value: 'X \\sim \\mathrm{Bernoulli}(p) ' },
      { label: 'Pois', title: __('Poisson distribution', 'pkb-math-tools'), value: 'X \\sim \\mathrm{Poisson}(\\lambda) ' },
      { label: 'iid', title: __('Independent identically distributed', 'pkb-math-tools'), value: 'X_i \\overset{iid}{\\sim} F ' },
      { label: 'LLN', title: __('Sample mean convergence', 'pkb-math-tools'), value: '\\bar{X}_n \\xrightarrow{p} \\mu ' },
      { label: 'PDF', title: __('Probability density', 'pkb-math-tools'), value: 'f_X(x) ' },
      { label: 'CDF', title: __('Cumulative distribution', 'pkb-math-tools'), value: 'F_X(x) = P(X \\le x) ' },
    ],
    optimization: [
      { label: 'argmin', title: __('Argmin', 'pkb-math-tools'), value: '\\arg\\min_{x \\in X} f(x) ' },
      { label: 'argmax', title: __('Argmax', 'pkb-math-tools'), value: '\\arg\\max_{\\theta} L(\\theta) ' },
      { label: 'min s.t.', title: __('Constrained minimization', 'pkb-math-tools'), value: '\\begin{aligned}\\min_{x} \\quad & f(x) \\\\ \\text{s.t.} \\quad & g_i(x) \\le 0,\\ i=1,\\dots,m\\end{aligned}' },
      { label: 'LP', title: __('Linear program', 'pkb-math-tools'), value: '\\begin{aligned}\\min_x \\quad & c^\\top x \\\\ \\text{s.t.} \\quad & Ax \\le b\\end{aligned}' },
      { label: 'KKT', title: __('KKT stationarity', 'pkb-math-tools'), value: '\\nabla_x \\mathcal{L}(x,\\lambda)=0 ' },
      { label: 'Lag', title: __('Lagrangian', 'pkb-math-tools'), value: '\\mathcal{L}(x,\\lambda)=f(x)+\\sum_{i=1}^{m}\\lambda_i g_i(x) ' },
      { label: 'grad', title: __('Gradient', 'pkb-math-tools'), value: '\\nabla f(x) ' },
      { label: 'Hess', title: __('Hessian', 'pkb-math-tools'), value: '\\nabla^2 f(x) ' },
      { label: 'Ax<=b', title: __('Linear constraints', 'pkb-math-tools'), value: 'Ax \\le b ' },
      { label: 'x>=0', title: __('Nonnegativity constraint', 'pkb-math-tools'), value: 'x \\ge 0 ' },
      { label: 'dual', title: __('Dual problem', 'pkb-math-tools'), value: '\\max_{\\lambda \\ge 0} \\inf_x \\mathcal{L}(x,\\lambda) ' },
      { label: 'O(n)', title: __('Big O', 'pkb-math-tools'), value: 'O(n \\log n) ' },
    ],
    ml: [
      { label: 'L(theta)', title: __('Loss function', 'pkb-math-tools'), value: '\\mathcal{L}(\\theta) ' },
      { label: 'risk', title: __('Empirical risk', 'pkb-math-tools'), value: '\\frac{1}{n}\\sum_{i=1}^{n}\\ell(f_\\theta(x_i), y_i) ' },
      { label: 'MSE', title: __('Mean squared error', 'pkb-math-tools'), value: '\\frac{1}{n}\\sum_{i=1}^{n}(y_i - \\hat{y}_i)^2 ' },
      { label: 'CE', title: __('Cross entropy', 'pkb-math-tools'), value: '-\\sum_{k=1}^{K} y_k \\log \\hat{p}_k ' },
      { label: 'softmax', title: __('Softmax', 'pkb-math-tools'), value: '\\mathrm{softmax}(z)_k = \\frac{e^{z_k}}{\\sum_j e^{z_j}} ' },
      { label: 'sigmoid', title: __('Sigmoid', 'pkb-math-tools'), value: '\\sigma(z)=\\frac{1}{1+e^{-z}} ' },
      { label: 'MLE', title: __('Likelihood', 'pkb-math-tools'), value: 'L(\\theta)=\\prod_{i=1}^{n}p(x_i \\mid \\theta) ' },
      { label: 'logL', title: __('Log-likelihood', 'pkb-math-tools'), value: '\\ell(\\theta)=\\sum_{i=1}^{n}\\log p(x_i \\mid \\theta) ' },
      { label: 'GD', title: __('Gradient descent update', 'pkb-math-tools'), value: '\\theta_{t+1}=\\theta_t-\\eta\\nabla_\\theta \\mathcal{L}(\\theta_t) ' },
      { label: 'Reg', title: __('L2 regularization', 'pkb-math-tools'), value: '\\mathcal{L}(\\theta)+\\lambda\\lVert\\theta\\rVert_2^2 ' },
      { label: 'yhat', title: __('Prediction', 'pkb-math-tools'), value: '\\hat{y}=f_\\theta(x) ' },
      { label: 'MAP', title: __('MAP estimate', 'pkb-math-tools'), value: '\\hat{\\theta}_{MAP}=\\arg\\max_\\theta p(\\theta \\mid X) ' },
    ],
    greek: [
      ['alpha', '\\alpha'], ['beta', '\\beta'], ['gamma', '\\gamma'], ['delta', '\\delta'],
      ['epsilon', '\\epsilon'], ['theta', '\\theta'], ['lambda', '\\lambda'], ['mu', '\\mu'],
      ['pi', '\\pi'], ['rho', '\\rho'], ['sigma', '\\sigma'], ['phi', '\\phi'],
      ['omega', '\\omega'], ['Gamma', '\\Gamma'], ['Delta', '\\Delta'], ['Theta', '\\Theta'],
      ['Lambda', '\\Lambda'], ['Pi', '\\Pi'], ['Sigma', '\\Sigma'], ['Omega', '\\Omega'],
    ].map(([label, value]) => ({ label, title: value.replace('\\', ''), value: `${value} ` })),
    relations: [
      ['<=', '\\leq'], ['>=', '\\geq'], ['!=', '\\neq'], ['~=', '\\approx'],
      ['==', '\\equiv'], ['~', '\\sim'], ['simeq', '\\simeq'], ['prop', '\\propto'],
      ['+-', '\\pm'], ['-+', '\\mp'], ['x', '\\times'], ['dot', '\\cdot'],
      ['inf', '\\infty'], ['partial', '\\partial'], ['forall', '\\forall'], ['exists', '\\exists'],
      ['in', '\\in'], ['notin', '\\notin'], ['empty', '\\emptyset'], ['setminus', '\\setminus'],
      ['subset', '\\subset'], ['subseteq', '\\subseteq'], ['cup', '\\cup'], ['cap', '\\cap'],
      ['perp', '\\perp'], ['parallel', '\\parallel'], ['to', '\\to'], ['Right', '\\Rightarrow'],
      ['iff', '\\leftrightarrow'],
    ].map(([label, value]) => ({ label, title: value.replace('\\', ''), value: `${value} ` })),
  };

  const examples = [
    { label: __('Transportation LP', 'pkb-math-tools'), value: '\\begin{align}\\min_{x_{ij}} \\quad & \\sum_{i \\in I}\\sum_{j \\in J} c_{ij}x_{ij} && \\tag{Obj} \\\\ \\text{s.t.}\\quad & \\sum_{j \\in J} x_{ij} \\le s_i,\\quad i \\in I && \\tag{1} \\\\ & \\sum_{i \\in I} x_{ij} \\ge d_j,\\quad j \\in J && \\tag{2} \\\\ & x_{ij} \\ge 0 && \\tag{3}\\end{align}' },
    { label: __('Bayes theorem', 'pkb-math-tools'), value: 'P(A|B) = \\frac{P(B|A)P(A)}{P(B)}' },
    { label: __('Economic order quantity', 'pkb-math-tools'), value: 'Q^* = \\sqrt{\\frac{2DS}{H}}' },
    { label: __('Mean', 'pkb-math-tools'), value: '\\bar{x} = \\frac{1}{n} \\sum_{i=1}^{n} x_i' },
  ];

  const activeMathFields = new Map();
  const recentSnippetsStorageKey = 'pkbMathToolsRecentSnippets';
  const recentSnippetsLimit = 12;
  let latexToMathMLPromise = null;

  function normalizeLatex(value) {
    return String(value || '').replace(/\\u005c|u005c/g, '\\');
  }

  function mathAttributeName(attributes) {
    const candidates = ['latex', 'content', 'value', 'formula'];
    return candidates.find((name) => Object.prototype.hasOwnProperty.call(attributes || {}, name)) || 'content';
  }

  function currentLatex(attributes) {
    return normalizeLatex((attributes || {})[mathAttributeName(attributes)]);
  }

  function mathBlockAttributeUpdate(attributes, latex, mathML = null) {
    const normalized = normalizeLatex(latex);
    if (Object.prototype.hasOwnProperty.call(attributes || {}, 'latex') || Object.prototype.hasOwnProperty.call(attributes || {}, 'mathML')) {
      const next = { latex: normalized };
      if (mathML !== null) {
        next.mathML = mathML;
      }
      return next;
    }

    return { [mathAttributeName(attributes)]: normalized };
  }

  function getLatexToMathML() {
    if (!latexToMathMLPromise) {
      latexToMathMLPromise = import('@wordpress/latex-to-mathml').then((module) => module.default);
    }
    return latexToMathMLPromise;
  }

  async function convertLatexToMathML(latex, displayMode = false) {
    const latexToMathML = await getLatexToMathML();
    return latexToMathML(normalizeLatex(latex), { displayMode });
  }

  function findMathTextarea() {
    const selectors = [
      '.wp-block-math__textarea-control textarea',
      '.components-popover textarea:not(.pkb-live-math-code-editor)',
      '.components-popover .block-editor-format-toolbar__math-input input[type="text"]',
      '.components-popover input[placeholder*="x^2"]',
      '.components-popover input[placeholder*="frac"]',
      '.components-popover input[aria-label*="LaTeX"]',
      '.components-popover input[aria-label*="수학"]',
      '[role="dialog"] textarea:not(.pkb-live-math-code-editor)',
      'textarea[placeholder*="x^2"]',
      'textarea[placeholder*="frac"]',
      'textarea[placeholder*="수식"]',
    ];

    const docs = [document];
    document.querySelectorAll('iframe').forEach((iframe) => {
      try {
        if (iframe.contentDocument) docs.push(iframe.contentDocument);
      } catch (error) {
        // Ignore cross-origin frames.
      }
    });

    for (const doc of docs) {
      for (const selector of selectors) {
        const textarea = doc.querySelector(selector);
        if (
          textarea &&
          !textarea.closest('.pkb-live-math-modal') &&
          !textarea.classList.contains('editor-post-title__input') &&
          !textarea.classList.contains('editor-post-text-editor') &&
          textarea.type !== 'hidden' &&
          textarea.getAttribute('aria-label') !== __('LaTeX code', 'pkb-math-tools')
        ) {
          return textarea;
        }
      }
    }

    return null;
  }

  function mathControlsInDocument(doc) {
    const selectors = [
      '.wp-block-math__textarea-control textarea',
      '.components-popover textarea:not(.pkb-live-math-code-editor)',
      '.components-popover .block-editor-format-toolbar__math-input input[type="text"]',
      '.components-popover input[placeholder*="x^2"]',
      '.components-popover input[placeholder*="frac"]',
      '.components-popover input[aria-label*="LaTeX"]',
      '.components-popover input[aria-label*="수학"]',
      '[role="dialog"] textarea:not(.pkb-live-math-code-editor)',
      'textarea[placeholder*="x^2"]',
      'textarea[placeholder*="frac"]',
      'textarea[placeholder*="수식"]',
    ];

    return selectors.flatMap((selector) => Array.from(doc.querySelectorAll(selector))).filter((control) => (
      control &&
      !control.closest('.pkb-live-math-modal') &&
      !control.classList.contains('editor-post-title__input') &&
      !control.classList.contains('editor-post-text-editor') &&
      control.type !== 'hidden' &&
      control.getAttribute('aria-label') !== __('LaTeX code', 'pkb-math-tools')
    ));
  }

  function mathControlDocuments() {
    const docs = [document];
    document.querySelectorAll('iframe').forEach((iframe) => {
      try {
        if (iframe.contentDocument && !docs.includes(iframe.contentDocument)) {
          docs.push(iframe.contentDocument);
        }
      } catch (error) {
        // Ignore cross-origin frames.
      }
    });
    return docs;
  }

  function setNativeTextareaValue(textarea, nextValue) {
    if (!textarea) return;
    const proto = Object.getPrototypeOf(textarea);
    const descriptor = Object.getOwnPropertyDescriptor(proto, 'value');
    if (descriptor && descriptor.set) {
      descriptor.set.call(textarea, nextValue);
    } else {
      textarea.value = nextValue;
    }
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function closeDefaultMathEditor(preferredControl = null) {
    const control = preferredControl || findMathTextarea();
    const docs = [document];
    if (control?.ownerDocument && control.ownerDocument !== document) {
      docs.push(control.ownerDocument);
    }

    document.querySelectorAll('iframe').forEach((iframe) => {
      try {
        if (iframe.contentDocument && !docs.includes(iframe.contentDocument)) {
          docs.push(iframe.contentDocument);
        }
      } catch (error) {
        // Ignore cross-origin frames.
      }
    });

    if (control && typeof control.blur === 'function') {
      control.blur();
    }

    docs.forEach((doc) => {
      const popover = control?.ownerDocument === doc
        ? control.closest('.components-popover')
        : doc.querySelector('.components-popover .block-editor-format-toolbar__math-input')?.closest('.components-popover');
      const closeButton = popover?.querySelector(
        'button[aria-label*="Close"], button[aria-label*="close"], button[aria-label*="닫"]'
      );

      if (closeButton) {
        closeButton.click();
      }

      const KeyboardEventCtor = doc.defaultView?.KeyboardEvent || window.KeyboardEvent;
      const escapeTargets = [
        control?.ownerDocument === doc ? control : null,
        doc.activeElement,
        doc.body,
        doc,
        doc.defaultView,
      ].filter(Boolean);

      escapeTargets.forEach((target) => {
        target.dispatchEvent(new KeyboardEventCtor('keydown', {
          key: 'Escape',
          code: 'Escape',
          keyCode: 27,
          which: 27,
          bubbles: true,
          cancelable: true,
        }));
      });

    });

    if (document.querySelector('.block-editor-format-toolbar__math-popover, .components-popover')) {
      dispatchOutsideEditorClick();
    }
  }

  function dispatchOutsideEditorClick() {
    const clickX = 20;
    const clickY = Math.min(500, Math.max(20, window.innerHeight - 20));
    const eventNames = ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'];

    function dispatchAt(doc, x, y) {
      const target = doc.elementFromPoint(x, y) || doc.body;
      eventNames.forEach((eventName) => {
        const EventCtor = eventName.startsWith('pointer') && doc.defaultView?.PointerEvent
          ? doc.defaultView.PointerEvent
          : (doc.defaultView?.MouseEvent || window.MouseEvent);
        target.dispatchEvent(new EventCtor(eventName, {
          bubbles: true,
          cancelable: true,
          view: doc.defaultView || window,
          clientX: x,
          clientY: y,
          pointerId: 1,
          pointerType: 'mouse',
          isPrimary: true,
        }));
      });
    }

    dispatchAt(document, clickX, clickY);
    document.querySelectorAll('iframe').forEach((iframe) => {
      try {
        const rect = iframe.getBoundingClientRect();
        if (
          iframe.contentDocument &&
          clickX >= rect.left &&
          clickX <= rect.right &&
          clickY >= rect.top &&
          clickY <= rect.bottom
        ) {
          dispatchAt(iframe.contentDocument, clickX - rect.left, clickY - rect.top);
        }
      } catch (error) {
        // Ignore cross-origin frames.
      }
    });
  }

  function scheduleCloseDefaultMathEditor(control = null) {
    window.setTimeout(() => closeDefaultMathEditor(control), 80);
  }

  function mathMLToInlineMathHTML(latex, mathML) {
    const template = document.createElement('template');
    template.innerHTML = mathML || '';
    let math = template.content.querySelector('math');
    if (!math) {
      math = document.createElement('math');
      math.append(...Array.from(template.content.childNodes));
    }
    math.setAttribute('data-latex', normalizeLatex(latex));
    if (!math.querySelector('annotation[encoding="application/x-tex"]')) {
      const semantics = math.querySelector('semantics') || math;
      const annotation = document.createElement('annotation');
      annotation.setAttribute('encoding', 'application/x-tex');
      annotation.textContent = normalizeLatex(latex);
      semantics.appendChild(annotation);
    }
    return math.outerHTML;
  }

  function replaceInlineMathInContent(content, sourceHTML, replacementHTML) {
    const current = String(content || '');
    if (sourceHTML && current.includes(sourceHTML)) {
      return current.replace(sourceHTML, replacementHTML);
    }

    const template = document.createElement('template');
    template.innerHTML = current;
    const sourceTemplate = document.createElement('template');
    sourceTemplate.innerHTML = sourceHTML || '';
    const sourceLatex = sourceTemplate.content.querySelector('math')?.getAttribute('data-latex') || '';
    const target = Array.from(template.content.querySelectorAll('math[data-latex]')).find((node) => (
      node.outerHTML === sourceHTML || (sourceLatex && node.getAttribute('data-latex') === sourceLatex)
    ));
    if (!target) return current;

    const replacementTemplate = document.createElement('template');
    replacementTemplate.innerHTML = replacementHTML;
    target.replaceWith(...Array.from(replacementTemplate.content.childNodes));
    return template.innerHTML;
  }

  function mathfieldLatex(mathfield) {
    if (!mathfield) return '';
    if (typeof mathfield.getValue === 'function') {
      try {
        return normalizeLatex(mathfield.getValue('latex-without-placeholders'));
      } catch (error) {
        return normalizeLatex(mathfield.getValue());
      }
    }
    return normalizeLatex(mathfield.value || '');
  }

  function mathliveSnippetValue(snippet) {
    const snippetMap = {
      '\\frac{}{}': '\\frac{#?}{#?}',
      '\\sqrt{}': '\\sqrt{#?}',
      '^{}': '#@^{#?}',
      '_{}': '#@_{#?}',
      '\\left(  \\right)': '\\left(#?\\right)',
      '\\left[  \\right]': '\\left[#?\\right]',
    };

    return snippetMap[snippet.value] || snippet.value;
  }

  function insertIntoMathField(clientId, snippet) {
    const mathfield = activeMathFields.get(clientId);
    if (!mathfield) return false;

    try {
      mathfield.insert(mathliveSnippetValue(snippet), {
        insertionMode: 'replaceSelection',
        selectionMode: 'placeholder',
      });
      mathfield.focus();
      mathfield.dispatchEvent(new Event('input', { bubbles: true, composed: true }));
      return true;
    } catch (error) {
      window.console.warn('[pkb-math-tools] Snippet insert failed.', error);
      return false;
    }
  }

  function readRecentSnippets() {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(recentSnippetsStorageKey) || '[]');
      return Array.isArray(parsed) ? parsed.slice(0, recentSnippetsLimit) : [];
    } catch (error) {
      return [];
    }
  }

  function writeRecentSnippets(items) {
    try {
      window.localStorage.setItem(recentSnippetsStorageKey, JSON.stringify(items.slice(0, recentSnippetsLimit)));
    } catch (error) {
      // Local storage can be disabled in hardened browser profiles.
    }
  }

  function normalizeRecentSnippet(snippet) {
    return {
      label: snippet.label,
      title: snippet.title || snippet.label,
      value: snippet.value,
    };
  }

  function SnippetButton({ snippet, onInsert }) {
    return el(Button, {
      className: 'pkb-math-tools-button',
      label: snippet.title || snippet.label,
      onClick: () => onInsert(snippet),
      size: 'compact',
      showTooltip: true,
      variant: 'secondary',
    }, snippet.label);
  }

  function SnippetGrid({ items, onInsert }) {
    return el('div', { className: 'pkb-math-tools-grid' },
      items.map((snippet) => el(SnippetButton, {
        key: `${snippet.label}-${snippet.value}`,
        snippet,
        onInsert,
      }))
    );
  }

  function MathToolsTabs({ onInsert }) {
    const [recentSnippets, setRecentSnippets] = useState(readRecentSnippets);
    const tabs = useMemo(() => [
      { name: 'recent', title: __('Recent', 'pkb-math-tools') },
      { name: 'basic', title: __('Basic', 'pkb-math-tools') },
      { name: 'linear', title: __('Linear', 'pkb-math-tools') },
      { name: 'probability', title: __('Prob.', 'pkb-math-tools') },
      { name: 'optimization', title: __('Opt.', 'pkb-math-tools') },
      { name: 'ml', title: __('ML', 'pkb-math-tools') },
      { name: 'greek', title: __('Greek', 'pkb-math-tools') },
      { name: 'relations', title: __('Symbols', 'pkb-math-tools') },
    ], []);

    function insert(snippet) {
      const normalized = normalizeRecentSnippet(snippet);
      setRecentSnippets((current) => {
        const next = [
          normalized,
          ...current.filter((item) => item.value !== normalized.value),
        ].slice(0, recentSnippetsLimit);
        writeRecentSnippets(next);
        return next;
      });
      onInsert(snippet);
    }

    return el(TabPanel, {
      className: 'pkb-math-tools-tabs',
      tabs,
    }, (tab) => {
      const items = tab.name === 'recent' ? recentSnippets : presets[tab.name];
      if (tab.name === 'recent' && items.length === 0) {
        return el('div', { className: 'pkb-math-tools-empty' }, __('No recent shortcuts yet.', 'pkb-math-tools'));
      }

      return el(SnippetGrid, { items, onInsert: insert });
    });
  }

  function ExtendedEditorModal({ clientId, initialLatex, onApply, onClose }) {
    const fieldWrapRef = useRef(null);
    const mathfieldRef = useRef(null);
    const lastValueRef = useRef(normalizeLatex(initialLatex));
    const undoStackRef = useRef([]);
    const redoStackRef = useRef([]);
    const [latex, setLatex] = useState(normalizeLatex(initialLatex));
    const [isCodeMode, setIsCodeMode] = useState(false);
    const [codeLatex, setCodeLatex] = useState(normalizeLatex(initialLatex));

    useEffect(() => {
      if (isCodeMode || !window.MathfieldElement || !fieldWrapRef.current) return undefined;

      const mathfield = new window.MathfieldElement();
      mathfield.className = 'pkb-live-math-field';
      mathfield.value = latex || '';
      fieldWrapRef.current.replaceChildren(mathfield);
      mathfieldRef.current = mathfield;
      activeMathFields.set(clientId, mathfield);

      try {
        mathfield.smartFence = true;
        mathfield.smartSuperscript = true;
        mathfield.smartMode = false;
        mathfield.defaultMode = 'math';
        mathfield.mathVirtualKeyboardPolicy = 'manual';
        mathfield.inlineShortcuts = {
          align: '\\begin{aligned}#? &= #? \\\\ #? &= #?\\end{aligned}',
          matrix: '\\begin{bmatrix}#? & #? \\\\ #? & #?\\end{bmatrix}',
        };
      } catch (error) {
        window.console.warn('[pkb-math-tools] MathLive configuration failed.', error);
      }

      function sync() {
        const nextLatex = mathfieldLatex(mathfield);
        if (nextLatex !== lastValueRef.current) {
          undoStackRef.current.push(lastValueRef.current);
          redoStackRef.current = [];
        }
        lastValueRef.current = nextLatex;
        setLatex(nextLatex);
        setCodeLatex(nextLatex);
      }

      function keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.ctrlKey && !event.metaKey && !event.altKey) {
          event.preventDefault();
          event.stopPropagation();
          onApply(mathfieldLatex(mathfield));
        }
      }

      mathfield.addEventListener('input', sync);
      mathfield.addEventListener('change', sync);
      mathfield.addEventListener('keydown', keydown, true);
      window.requestAnimationFrame(() => mathfield.focus());

      return () => {
        mathfield.removeEventListener('input', sync);
        mathfield.removeEventListener('change', sync);
        mathfield.removeEventListener('keydown', keydown, true);
        mathfield.remove();
        mathfieldRef.current = null;
        activeMathFields.delete(clientId);
      };
    }, [clientId, isCodeMode]);

    function setMathfieldValue(nextLatex) {
      lastValueRef.current = nextLatex;
      setLatex(nextLatex);
      setCodeLatex(nextLatex);
      if (mathfieldRef.current) {
        mathfieldRef.current.value = nextLatex;
        window.requestAnimationFrame(() => mathfieldRef.current && mathfieldRef.current.focus());
      }
    }

    function insertSnippet(snippet) {
      if (insertIntoMathField(clientId, snippet)) return;
      setMathfieldValue(`${latex || ''}${snippet.value}`);
    }

    function clearMath() {
      undoStackRef.current.push(lastValueRef.current);
      redoStackRef.current = [];
      setMathfieldValue('');
    }

    function undoMath() {
      if (!undoStackRef.current.length) return;
      const previous = undoStackRef.current.pop();
      redoStackRef.current.push(lastValueRef.current);
      setMathfieldValue(previous || '');
    }

    function redoMath() {
      if (!redoStackRef.current.length) return;
      const next = redoStackRef.current.pop();
      undoStackRef.current.push(lastValueRef.current);
      setMathfieldValue(next || '');
    }

    function toggleCodeMode() {
      if (isCodeMode) {
        setMathfieldValue(normalizeLatex(codeLatex));
        setIsCodeMode(false);
        return;
      }

      setCodeLatex(mathfieldRef.current ? mathfieldLatex(mathfieldRef.current) : latex);
      setIsCodeMode(true);
    }

    function done() {
      onApply(normalizeLatex(isCodeMode ? codeLatex : (mathfieldRef.current ? mathfieldLatex(mathfieldRef.current) : latex)));
    }

    return el(Modal, {
      className: 'pkb-live-math-modal',
      onRequestClose: onClose,
      title: __('Extended Editor', 'pkb-math-tools'),
    },
      el('div', { className: 'pkb-live-math-modal-body' },
        el('div', { className: 'pkb-live-math-toolbar' },
          el('div', { className: 'pkb-live-math-actions' },
            el(Button, {
              onClick: toggleCodeMode,
              size: 'compact',
              variant: isCodeMode ? 'primary' : 'secondary',
            }, isCodeMode ? __('Preview', 'pkb-math-tools') : __('Code', 'pkb-math-tools')),
            el(Button, { onClick: clearMath, size: 'compact', variant: 'secondary' }, __('Clear', 'pkb-math-tools')),
            el(Button, { onClick: undoMath, size: 'compact', variant: 'secondary' }, __('Undo', 'pkb-math-tools')),
            el(Button, { onClick: redoMath, size: 'compact', variant: 'secondary' }, __('Redo', 'pkb-math-tools')),
            el(Button, { onClick: done, size: 'compact', variant: 'primary' }, __('Done', 'pkb-math-tools'))
          )
        ),
        isCodeMode
          ? el('textarea', {
            className: 'pkb-live-math-code-editor',
            value: codeLatex,
            onChange: (event) => setCodeLatex(event.target.value),
            onKeyDown: (event) => {
              if (event.key === 'Enter' && !event.shiftKey && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                event.stopPropagation();
                done();
              }
            },
            spellCheck: false,
            'aria-label': __('LaTeX code', 'pkb-math-tools'),
          })
          : el('div', {
            className: 'pkb-live-math-field-wrap',
            ref: fieldWrapRef,
          }),
        el('div', { className: 'pkb-live-math-tools' },
          el('div', { className: 'pkb-live-math-tools-heading' }, __('Math Tools', 'pkb-math-tools')),
          el(MathToolsTabs, { onInsert: insertSnippet })
        ),
        el('div', { className: 'pkb-live-math-tools' },
          el('div', { className: 'pkb-live-math-tools-heading' }, __('Examples', 'pkb-math-tools')),
          el('div', { className: 'pkb-math-tools-examples' },
            examples.map((example) => el(Button, {
              key: example.label,
              className: 'pkb-math-tools-example',
              onClick: () => setMathfieldValue(example.value),
              size: 'compact',
              variant: 'tertiary',
            }, example.label))
          )
        )
      )
    );
  }

  function MathBlockControls({ attributes, setAttributes, clientId }) {
    const [isEditing, setIsEditing] = useState(false);
    const attributeName = mathAttributeName(attributes);
    const latex = currentLatex(attributes);

    useEffect(() => {
      let cleanupButton = null;
      let frame = 0;
      let stopped = false;

      function removeButton() {
        if (cleanupButton) {
          cleanupButton();
          cleanupButton = null;
        }
      }

      function installButton() {
        if (stopped) return;

        const textarea = findMathTextarea();
        if (!textarea || textarea.closest('.pkb-live-math-modal')) {
          frame = window.requestAnimationFrame(installButton);
          return;
        }

        const ownerDocument = textarea.ownerDocument;
        const existing = ownerDocument.querySelector('[data-pkb-math-tools-extended-button="1"]');
        if (existing) {
          existing.onclick = () => setIsEditing(true);
          frame = window.requestAnimationFrame(installButton);
          return;
        }

        const buttonRow = ownerDocument.createElement('div');
        buttonRow.className = 'pkb-math-tools-popover-controls';
        buttonRow.setAttribute('data-pkb-math-tools-extended-button', '1');

        const button = ownerDocument.createElement('button');
        button.type = 'button';
        button.className = 'components-button is-secondary is-compact pkb-math-tools-extended-button';
        button.textContent = __('Extended Editor', 'pkb-math-tools');
        button.onclick = () => setIsEditing(true);
        buttonRow.appendChild(button);

        const container = textarea.closest('.components-base-control') || textarea.parentElement;
        container.insertAdjacentElement('afterend', buttonRow);
        cleanupButton = () => buttonRow.remove();
        frame = window.requestAnimationFrame(installButton);
      }

      installButton();

      return () => {
        stopped = true;
        window.cancelAnimationFrame(frame);
        removeButton();
      };
    }, [clientId]);

    async function applyLatex(nextLatex) {
      const normalized = normalizeLatex(nextLatex);
      let mathML = null;
      try {
        mathML = await convertLatexToMathML(normalized, true);
      } catch (error) {
        window.console.warn('[pkb-math-tools] Math block conversion failed.', error);
      }
      setAttributes(mathBlockAttributeUpdate(attributes, normalized, mathML));
      const textarea = findMathTextarea();
      setNativeTextareaValue(textarea, normalized);
      window.setTimeout(() => setNativeTextareaValue(findMathTextarea(), normalized), 50);
      window.setTimeout(() => setNativeTextareaValue(findMathTextarea(), normalized), 250);
      setIsEditing(false);
      scheduleCloseDefaultMathEditor(textarea);
    }

    return el(Fragment, null,
      el(BlockControls, null,
        el(ToolbarGroup, null,
          el(ToolbarButton, {
            icon: 'edit',
            label: __('Extended Editor', 'pkb-math-tools'),
            onClick: () => setIsEditing(true),
          })
        )
      ),
      isEditing && el(ExtendedEditorModal, {
        clientId,
        initialLatex: latex,
        onApply: applyLatex,
        onClose: () => setIsEditing(false),
      })
    );
  }

  function MathToolsPanel({ attributes, setAttributes, clientId }) {
    const attributeName = mathAttributeName(attributes);
    const latex = currentLatex(attributes);

    async function updateMathBlock(nextLatex) {
      let mathML = null;
      try {
        mathML = await convertLatexToMathML(nextLatex, true);
      } catch (error) {
        window.console.warn('[pkb-math-tools] Math tools conversion failed.', error);
      }
      setAttributes(mathBlockAttributeUpdate(attributes, nextLatex, mathML));
    }

    function insertSnippet(snippet) {
      updateMathBlock(`${latex}${snippet.value}`);
    }

    return el(InspectorControls, null,
      el(PanelBody, {
        title: __('Math Tools', 'pkb-math-tools'),
        initialOpen: true,
        className: 'pkb-math-tools-panel',
      },
        el(MathToolsTabs, { onInsert: insertSnippet }),
        el('div', { className: 'pkb-math-tools-section' },
          el('div', { className: 'pkb-math-tools-heading' }, __('Examples', 'pkb-math-tools')),
          examples.map((example) => el(Button, {
            key: example.label,
            className: 'pkb-math-tools-example',
            onClick: () => updateMathBlock(example.value),
            size: 'compact',
            variant: 'tertiary',
          }, example.label))
        )
      )
    );
  }

  function GlobalMathToolsPlugin() {
    const [selectedBlock, setSelectedBlock] = useState(null);
    const [inlineSource, setInlineSource] = useState(null);
    const [textareaEditor, setTextareaEditor] = useState(null);

    useEffect(() => {
      if (!dataApi.subscribe || !dataApi.select) return undefined;

      const update = () => {
        const block = dataApi.select('core/block-editor').getSelectedBlock();
        setSelectedBlock(block && block.name === 'core/math' ? block : null);
      };

      update();
      const unsubscribe = dataApi.subscribe(update);
      return unsubscribe;
    }, []);

    useEffect(() => {
      const cleanups = [];
      const attached = new WeakSet();
      let stopped = false;
      let timer = 0;

      function attach(doc) {
        if (!doc || attached.has(doc)) return;
        attached.add(doc);

        const click = (event) => {
          const target = event.target && event.target.closest ? event.target.closest('math[data-latex]') : null;
          if (!target) {
            const element = event.target && event.target.closest ? event.target : null;
            if (
              element &&
              !element.closest('.components-popover, .pkb-live-math-modal, [data-pkb-math-tools-textarea-button="1"]')
            ) {
              setInlineSource(null);
            }
            return;
          }

          const blockNode = target.closest('[data-block]');
          const blockId = blockNode ? blockNode.getAttribute('data-block') : '';
          const block = blockId && dataApi.select ? dataApi.select('core/block-editor').getBlock(blockId) : null;
          if (!block || block.name !== 'core/paragraph') return;

          const rect = target.getBoundingClientRect();
          const iframe = Array.from(document.querySelectorAll('iframe')).find((item) => {
            try {
              return item.contentDocument === target.ownerDocument;
            } catch (error) {
              return false;
            }
          });
          const iframeRect = iframe ? iframe.getBoundingClientRect() : { left: 0, top: 0 };

          setInlineSource({
            blockId,
            latex: target.getAttribute('data-latex') || target.querySelector('annotation[encoding="application/x-tex"]')?.textContent || '',
            html: target.outerHTML,
            isOpen: false,
            left: iframeRect.left + rect.left,
            top: iframeRect.top + rect.bottom + 6,
          });
        };

        doc.addEventListener('click', click, true);
        cleanups.push(() => doc.removeEventListener('click', click, true));
      }

      function scan() {
        if (stopped) return;
        attach(document);
        document.querySelectorAll('iframe').forEach((iframe) => {
          try {
            attach(iframe.contentDocument);
          } catch (error) {
            // Ignore cross-origin frames.
          }
        });
        timer = window.setTimeout(scan, 500);
      }

      scan();
      return () => {
        stopped = true;
        window.clearTimeout(timer);
        cleanups.forEach((cleanup) => cleanup());
      };
    }, []);

    useEffect(() => {
      const attached = new WeakSet();
      const cleanups = [];
      let stopped = false;
      let frame = 0;

      function attachEnterHandler(control) {
        if (!control || attached.has(control)) return;
        attached.add(control);

        const keydown = (event) => {
          if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) return;
          event.preventDefault();
          event.stopPropagation();

          setNativeTextareaValue(control, normalizeLatex(control.value));
          scheduleCloseDefaultMathEditor(control);
        };

        control.addEventListener('keydown', keydown, true);
        cleanups.push(() => control.removeEventListener('keydown', keydown, true));
      }

      function scan() {
        if (stopped) return;
        mathControlDocuments().forEach((doc) => {
          mathControlsInDocument(doc).forEach(attachEnterHandler);
        });
        frame = window.requestAnimationFrame(scan);
      }

      scan();
      return () => {
        stopped = true;
        window.cancelAnimationFrame(frame);
        cleanups.forEach((cleanup) => cleanup());
      };
    }, []);

    useEffect(() => {
      const existing = document.querySelector('[data-pkb-math-tools-textarea-button="1"]');
      if (existing) {
        existing.remove();
      }

      if (selectedBlock) return undefined;

      let stopped = false;
      let frame = 0;
      let cleanup = null;

      function install() {
        if (stopped) return;

        const textarea = findMathTextarea();
        if (!textarea || textarea.closest('.pkb-live-math-modal')) {
          frame = window.requestAnimationFrame(install);
          return;
        }

        const ownerDocument = textarea.ownerDocument;
        const current = ownerDocument.querySelector('[data-pkb-math-tools-textarea-button="1"]');
        if (current) {
          frame = window.requestAnimationFrame(install);
          return;
        }

        const buttonRow = ownerDocument.createElement('div');
        buttonRow.className = 'pkb-math-tools-popover-controls';
        buttonRow.setAttribute('data-pkb-math-tools-textarea-button', '1');

        const button = ownerDocument.createElement('button');
        button.type = 'button';
        button.className = 'components-button is-secondary is-compact pkb-math-tools-extended-button';
        button.textContent = __('Extended Editor', 'pkb-math-tools');
        button.onclick = () => setTextareaEditor({
          initialLatex: inlineSource?.latex || textarea.value || '',
        });
        buttonRow.appendChild(button);

        const container = textarea.closest('.components-base-control') || textarea.parentElement;
        container.insertAdjacentElement('afterend', buttonRow);
        cleanup = () => buttonRow.remove();
        frame = window.requestAnimationFrame(install);
      }

      install();
      return () => {
        stopped = true;
        window.cancelAnimationFrame(frame);
        if (cleanup) cleanup();
      };
    }, [inlineSource, selectedBlock]);

    async function applyInlineLatex(nextLatex) {
      if (!inlineSource || !dataApi.select || !dataApi.dispatch) return;

      const normalized = normalizeLatex(nextLatex);
      let mathML = '';
      try {
        mathML = await convertLatexToMathML(normalized, false);
      } catch (error) {
        window.console.warn('[pkb-math-tools] Global inline math conversion failed.', error);
        mathML = `<math data-latex="${normalized}"><semantics><mtext>${normalized}</mtext><annotation encoding="application/x-tex">${normalized}</annotation></semantics></math>`;
      }

      const block = dataApi.select('core/block-editor').getBlock(inlineSource.blockId);
      if (block && block.attributes) {
        const replacementHTML = mathMLToInlineMathHTML(normalized, mathML);
        const currentContent = String(block.attributes.content || '');
        const nextContent = replaceInlineMathInContent(currentContent, inlineSource.html, replacementHTML);
        dataApi.dispatch('core/block-editor').updateBlockAttributes(inlineSource.blockId, { content: nextContent });
      }
      setInlineSource(null);
      scheduleCloseDefaultMathEditor();
    }

    async function applyTextareaLatex(nextLatex) {
      const normalized = normalizeLatex(nextLatex);
      if (inlineSource) {
        await applyInlineLatex(normalized);
        setTextareaEditor(null);
        return;
      }

      const textarea = findMathTextarea();
      setNativeTextareaValue(textarea, normalized);
      window.setTimeout(() => setNativeTextareaValue(findMathTextarea(), normalized), 50);
      window.setTimeout(() => setNativeTextareaValue(findMathTextarea(), normalized), 250);
      setTextareaEditor(null);
      scheduleCloseDefaultMathEditor(textarea);
    }

    return el(Fragment, null,
      textareaEditor && el(ExtendedEditorModal, {
        clientId: 'textarea-math-editor',
        initialLatex: textareaEditor.initialLatex,
        onApply: applyTextareaLatex,
        onClose: () => setTextareaEditor(null),
      })
    );
  }

  const withMathTools = createHigherOrderComponent((BlockEdit) => {
    return (props) => {
      if (props.name !== 'core/math') {
        return el(BlockEdit, props);
      }

      return el(Fragment, null,
        el(BlockEdit, props),
        props.isSelected && el(MathBlockControls, {
          attributes: props.attributes,
          setAttributes: props.setAttributes,
          clientId: props.clientId,
        }),
        props.isSelected && el(MathToolsPanel, {
          attributes: props.attributes,
          setAttributes: props.setAttributes,
          clientId: props.clientId,
        })
      );
    };
  }, 'withMathTools');

  addFilter('editor.BlockEdit', 'pkb-math-tools/with-math-tools', withMathTools);

  if (registerPlugin) {
    registerPlugin('pkb-math-tools-global', {
      render: GlobalMathToolsPlugin,
    });
  }
}());
