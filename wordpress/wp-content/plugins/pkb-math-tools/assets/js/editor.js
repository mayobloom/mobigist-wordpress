(function () {
  if (!window.wp || !window.wp.hooks || !window.wp.blockEditor) return;

  const { InspectorControls } = window.wp.blockEditor;
  const { Button, PanelBody, TabPanel } = window.wp.components;
  const { createHigherOrderComponent } = window.wp.compose;
  const { createElement: el, Fragment, useMemo } = window.wp.element;
  const { addFilter } = window.wp.hooks;
  const { __ } = window.wp.i18n;

  const presets = {
    basic: [
      { label: 'a/b', title: __('Fraction', 'pkb-math-tools'), value: '\\frac{}{}', cursorOffset: 6 },
      { label: '√', title: __('Square root', 'pkb-math-tools'), value: '\\sqrt{}', cursorOffset: 6 },
      { label: 'x²', title: __('Power', 'pkb-math-tools'), value: '^{}', cursorOffset: 2 },
      { label: 'xᵢ', title: __('Subscript', 'pkb-math-tools'), value: '_{}', cursorOffset: 2 },
      { label: 'Σ', title: __('Summation', 'pkb-math-tools'), value: '\\sum_{i=1}^{n} ', cursorOffset: 0 },
      { label: '∫', title: __('Integral', 'pkb-math-tools'), value: '\\int_{a}^{b} ', cursorOffset: 0 },
      { label: 'lim', title: __('Limit', 'pkb-math-tools'), value: '\\lim_{x \\to 0} ', cursorOffset: 0 },
      { label: '()', title: __('Parentheses', 'pkb-math-tools'), value: '\\left(  \\right)', cursorOffset: 7 },
      { label: '[]', title: __('Brackets', 'pkb-math-tools'), value: '\\left[  \\right]', cursorOffset: 7 },
      { label: 'matrix', title: __('2 by 2 matrix with brackets', 'pkb-math-tools'), value: '\\begin{bmatrix} a & b \\\\ c & d \\end{bmatrix}', cursorOffset: 0 },
    ],
    linear: [
      { label: 'x⃗', title: __('Vector', 'pkb-math-tools'), value: '\\mathbf{x} ', cursorOffset: 0 },
      { label: 'Aᵀ', title: __('Matrix transpose', 'pkb-math-tools'), value: 'A^\\top ', cursorOffset: 0 },
      { label: 'A⁻¹', title: __('Matrix inverse', 'pkb-math-tools'), value: 'A^{-1} ', cursorOffset: 0 },
      { label: '||x||₂', title: __('L2 norm', 'pkb-math-tools'), value: '\\lVert x \\rVert_2 ', cursorOffset: 0 },
      { label: '||x||₁', title: __('L1 norm', 'pkb-math-tools'), value: '\\lVert x \\rVert_1 ', cursorOffset: 0 },
      { label: '⟨x,y⟩', title: __('Inner product', 'pkb-math-tools'), value: '\\langle x, y \\rangle ', cursorOffset: 0 },
      { label: 'rank', title: __('Matrix rank', 'pkb-math-tools'), value: '\\operatorname{rank}(A) ', cursorOffset: 0 },
      { label: 'tr', title: __('Trace', 'pkb-math-tools'), value: '\\operatorname{tr}(A) ', cursorOffset: 0 },
      { label: 'det', title: __('Determinant', 'pkb-math-tools'), value: '\\det(A) ', cursorOffset: 0 },
      { label: 'Ax=b', title: __('Linear system', 'pkb-math-tools'), value: 'Ax = b ', cursorOffset: 0 },
      { label: 'quad', title: __('Quadratic form', 'pkb-math-tools'), value: 'x^\\top A x ', cursorOffset: 0 },
      { label: 'diag', title: __('Diagonal matrix', 'pkb-math-tools'), value: '\\operatorname{diag}(x) ', cursorOffset: 0 },
    ],
    probability: [
      { label: 'E[X]', title: __('Expectation', 'pkb-math-tools'), value: '\\mathbb{E}[X] ', cursorOffset: 0 },
      { label: 'E[Y|X]', title: __('Conditional expectation', 'pkb-math-tools'), value: '\\mathbb{E}[Y \\mid X] ', cursorOffset: 0 },
      { label: 'Var', title: __('Variance', 'pkb-math-tools'), value: '\\mathrm{Var}(X) ', cursorOffset: 0 },
      { label: 'Cov', title: __('Covariance', 'pkb-math-tools'), value: '\\mathrm{Cov}(X,Y) ', cursorOffset: 0 },
      { label: 'P(A|B)', title: __('Conditional probability', 'pkb-math-tools'), value: 'P(A \\mid B) ', cursorOffset: 0 },
      { label: 'N(μ,σ²)', title: __('Normal distribution', 'pkb-math-tools'), value: 'X \\sim \\mathcal{N}(\\mu, \\sigma^2) ', cursorOffset: 0 },
      { label: 'Bern', title: __('Bernoulli distribution', 'pkb-math-tools'), value: 'X \\sim \\mathrm{Bernoulli}(p) ', cursorOffset: 0 },
      { label: 'Pois', title: __('Poisson distribution', 'pkb-math-tools'), value: 'X \\sim \\mathrm{Poisson}(\\lambda) ', cursorOffset: 0 },
      { label: 'iid', title: __('Independent identically distributed', 'pkb-math-tools'), value: 'X_i \\overset{iid}{\\sim} F ', cursorOffset: 0 },
      { label: 'LLN', title: __('Sample mean convergence', 'pkb-math-tools'), value: '\\bar{X}_n \\xrightarrow{p} \\mu ', cursorOffset: 0 },
      { label: 'PDF', title: __('Probability density', 'pkb-math-tools'), value: 'f_X(x) ', cursorOffset: 0 },
      { label: 'CDF', title: __('Cumulative distribution', 'pkb-math-tools'), value: 'F_X(x) = P(X \\le x) ', cursorOffset: 0 },
    ],
    optimization: [
      { label: 'argmin', title: __('Argmin', 'pkb-math-tools'), value: '\\arg\\min_{x \\in X} f(x) ', cursorOffset: 0 },
      { label: 'argmax', title: __('Argmax', 'pkb-math-tools'), value: '\\arg\\max_{\\theta} L(\\theta) ', cursorOffset: 0 },
      { label: 'min s.t.', title: __('Constrained minimization', 'pkb-math-tools'), value: '\\begin{aligned}\\min_{x} \\quad & f(x) \\\\ \\text{s.t.} \\quad & g_i(x) \\le 0,\\ i=1,\\dots,m\\end{aligned}', cursorOffset: 0 },
      { label: 'LP', title: __('Linear program', 'pkb-math-tools'), value: '\\begin{aligned}\\min_x \\quad & c^\\top x \\\\ \\text{s.t.} \\quad & Ax \\le b\\end{aligned}', cursorOffset: 0 },
      { label: 'KKT', title: __('KKT stationarity', 'pkb-math-tools'), value: '\\nabla_x \\mathcal{L}(x,\\lambda)=0 ', cursorOffset: 0 },
      { label: 'Lag', title: __('Lagrangian', 'pkb-math-tools'), value: '\\mathcal{L}(x,\\lambda)=f(x)+\\sum_{i=1}^{m}\\lambda_i g_i(x) ', cursorOffset: 0 },
      { label: '∇f', title: __('Gradient', 'pkb-math-tools'), value: '\\nabla f(x) ', cursorOffset: 0 },
      { label: '∇²f', title: __('Hessian', 'pkb-math-tools'), value: '\\nabla^2 f(x) ', cursorOffset: 0 },
      { label: 'Ax≤b', title: __('Linear constraints', 'pkb-math-tools'), value: 'Ax \\le b ', cursorOffset: 0 },
      { label: 'x≥0', title: __('Nonnegativity constraint', 'pkb-math-tools'), value: 'x \\ge 0 ', cursorOffset: 0 },
      { label: 'dual', title: __('Dual problem', 'pkb-math-tools'), value: '\\max_{\\lambda \\ge 0} \\inf_x \\mathcal{L}(x,\\lambda) ', cursorOffset: 0 },
      { label: 'O(n)', title: __('Big O', 'pkb-math-tools'), value: 'O(n \\log n) ', cursorOffset: 0 },
    ],
    ml: [
      { label: 'L(θ)', title: __('Loss function', 'pkb-math-tools'), value: '\\mathcal{L}(\\theta) ', cursorOffset: 0 },
      { label: 'R̂', title: __('Empirical risk', 'pkb-math-tools'), value: '\\frac{1}{n}\\sum_{i=1}^{n}\\ell(f_\\theta(x_i), y_i) ', cursorOffset: 0 },
      { label: 'MSE', title: __('Mean squared error', 'pkb-math-tools'), value: '\\frac{1}{n}\\sum_{i=1}^{n}(y_i - \\hat{y}_i)^2 ', cursorOffset: 0 },
      { label: 'CE', title: __('Cross entropy', 'pkb-math-tools'), value: '-\\sum_{k=1}^{K} y_k \\log \\hat{p}_k ', cursorOffset: 0 },
      { label: 'softmax', title: __('Softmax', 'pkb-math-tools'), value: '\\mathrm{softmax}(z)_k = \\frac{e^{z_k}}{\\sum_j e^{z_j}} ', cursorOffset: 0 },
      { label: 'sigmoid', title: __('Sigmoid', 'pkb-math-tools'), value: '\\sigma(z)=\\frac{1}{1+e^{-z}} ', cursorOffset: 0 },
      { label: 'MLE', title: __('Likelihood', 'pkb-math-tools'), value: 'L(\\theta)=\\prod_{i=1}^{n}p(x_i \\mid \\theta) ', cursorOffset: 0 },
      { label: 'logL', title: __('Log-likelihood', 'pkb-math-tools'), value: '\\ell(\\theta)=\\sum_{i=1}^{n}\\log p(x_i \\mid \\theta) ', cursorOffset: 0 },
      { label: 'GD', title: __('Gradient descent update', 'pkb-math-tools'), value: '\\theta_{t+1}=\\theta_t-\\eta\\nabla_\\theta \\mathcal{L}(\\theta_t) ', cursorOffset: 0 },
      { label: 'Reg', title: __('L2 regularization', 'pkb-math-tools'), value: '\\mathcal{L}(\\theta)+\\lambda\\lVert\\theta\\rVert_2^2 ', cursorOffset: 0 },
      { label: 'ŷ', title: __('Prediction', 'pkb-math-tools'), value: '\\hat{y}=f_\\theta(x) ', cursorOffset: 0 },
      { label: 'MAP', title: __('MAP estimate', 'pkb-math-tools'), value: '\\hat{\\theta}_{MAP}=\\arg\\max_\\theta p(\\theta \\mid X) ', cursorOffset: 0 },
    ],
    greek: [
      ['α', '\\alpha'], ['β', '\\beta'], ['γ', '\\gamma'], ['δ', '\\delta'],
      ['ε', '\\epsilon'], ['θ', '\\theta'], ['λ', '\\lambda'], ['μ', '\\mu'],
      ['π', '\\pi'], ['ρ', '\\rho'], ['σ', '\\sigma'], ['φ', '\\phi'],
      ['ω', '\\omega'], ['Γ', '\\Gamma'], ['Δ', '\\Delta'], ['Θ', '\\Theta'],
      ['Λ', '\\Lambda'], ['Π', '\\Pi'], ['Σ', '\\Sigma'], ['Ω', '\\Omega'],
    ].map(([label, value]) => ({ label, title: value.replace('\\', ''), value: `${value} ` })),
    relations: [
      ['≤', '\\leq'], ['≥', '\\geq'], ['≠', '\\neq'], ['≈', '\\approx'],
      ['≡', '\\equiv'], ['∼', '\\sim'], ['≃', '\\simeq'], ['∝', '\\propto'],
      ['±', '\\pm'], ['∓', '\\mp'], ['×', '\\times'], ['·', '\\cdot'],
      ['∞', '\\infty'], ['∂', '\\partial'], ['∀', '\\forall'], ['∃', '\\exists'],
      ['∈', '\\in'], ['∉', '\\notin'], ['∅', '\\emptyset'], ['∖', '\\setminus'],
      ['⊂', '\\subset'], ['⊆', '\\subseteq'], ['∪', '\\cup'], ['∩', '\\cap'],
      ['⊥', '\\perp'], ['∥', '\\parallel'], ['→', '\\to'], ['⇒', '\\Rightarrow'],
      ['↔', '\\leftrightarrow'],
    ].map(([label, value]) => ({ label, title: value.replace('\\', ''), value: `${value} ` })),
  };

  const examples = [
    { label: __('Transportation LP', 'pkb-math-tools'), value: '\\begin{align}\\min_{x_{ij}} \\quad & \\sum_{i \\in I}\\sum_{j \\in J} c_{ij}x_{ij} && \\tag{Obj} \\\\ \\text{s.t.}\\quad & \\sum_{j \\in J} x_{ij} \\le s_i,\\quad i \\in I && \\tag{1} \\\\ & \\sum_{i \\in I} x_{ij} \\ge d_j,\\quad j \\in J && \\tag{2} \\\\ & x_{ij} \\ge 0 && \\tag{3}\\end{align}' },
    { label: __('Bayes theorem', 'pkb-math-tools'), value: 'P(A|B) = \\frac{P(B|A)P(A)}{P(B)}' },
    { label: __('Economic order quantity', 'pkb-math-tools'), value: 'Q^* = \\sqrt{\\frac{2DS}{H}}' },
    { label: __('Mean', 'pkb-math-tools'), value: '\\bar{x} = \\frac{1}{n} \\sum_{i=1}^{n} x_i' },
  ];

  const textareaSelections = new Map();
  let latexToMathMLPromise = null;

  function getLatexToMathML() {
    if (!latexToMathMLPromise) {
      latexToMathMLPromise = import('@wordpress/latex-to-mathml').then((module) => module.default);
    }
    return latexToMathMLPromise;
  }

  function selectedMathClientId(textarea) {
    const block = textarea && textarea.closest && textarea.closest('[data-block]');
    return block ? block.getAttribute('data-block') : null;
  }

  function rememberTextareaSelection(event) {
    const textarea = event.target;
    if (!textarea || !textarea.matches || !textarea.matches('.wp-block-math__textarea-control textarea')) {
      return;
    }

    const clientId = selectedMathClientId(textarea);
    if (!clientId) return;

    textareaSelections.set(clientId, {
      start: textarea.selectionStart,
      end: textarea.selectionEnd,
    });
  }

  ['focusin', 'click', 'keyup', 'select', 'input'].forEach((eventName) => {
    document.addEventListener(eventName, rememberTextareaSelection, true);
  });

  function insertAtSelection(latex, snippet, clientId) {
    const selection = textareaSelections.get(clientId);
    const start = selection ? selection.start : latex.length;
    const end = selection ? selection.end : start;

    return {
      latex: latex.slice(0, start) + snippet.value + latex.slice(end),
      cursor: start + (snippet.cursorOffset || snippet.value.length),
    };
  }

  async function updateMath(attributes, setAttributes, nextLatex) {
    setAttributes({ latex: nextLatex });

    try {
      const latexToMathML = await getLatexToMathML();
      setAttributes({
        latex: nextLatex,
        mathML: latexToMathML(nextLatex, { displayMode: true }),
      });
    } catch (error) {
      setAttributes({
        latex: nextLatex,
        mathML: attributes.mathML || '',
      });
    }
  }

  function focusMathTextarea(clientId, cursor) {
    window.requestAnimationFrame(() => {
      const block = document.querySelector(`[data-block="${clientId}"]`);
      const textarea = block && block.querySelector('.wp-block-math__textarea-control textarea');
      if (!textarea) return;
      textarea.focus();
      textarea.setSelectionRange(cursor, cursor);
      textareaSelections.set(clientId, { start: cursor, end: cursor });
    });
  }

  function SnippetButton({ snippet, attributes, setAttributes, clientId }) {
    const insert = () => {
      const currentLatex = attributes.latex || '';
      const next = insertAtSelection(currentLatex, snippet, clientId);
      updateMath(attributes, setAttributes, next.latex);
      focusMathTextarea(clientId, next.cursor);
    };

    return el(Button, {
      className: 'pkb-math-tools-button',
      label: snippet.title || snippet.label,
      onClick: insert,
      size: 'compact',
      showTooltip: true,
      variant: 'secondary',
    }, snippet.label);
  }

  function SnippetGrid({ items, attributes, setAttributes, clientId }) {
    return el('div', { className: 'pkb-math-tools-grid' },
      items.map((snippet) => el(SnippetButton, {
        key: `${snippet.label}-${snippet.value}`,
        snippet,
        attributes,
        setAttributes,
        clientId,
      }))
    );
  }

  function MathToolsPanel({ attributes, setAttributes, clientId }) {
    const tabs = useMemo(() => [
      { name: 'basic', title: __('Basic', 'pkb-math-tools') },
      { name: 'linear', title: __('Linear', 'pkb-math-tools') },
      { name: 'probability', title: __('Prob.', 'pkb-math-tools') },
      { name: 'optimization', title: __('Opt.', 'pkb-math-tools') },
      { name: 'ml', title: __('ML', 'pkb-math-tools') },
      { name: 'greek', title: __('Greek', 'pkb-math-tools') },
      { name: 'relations', title: __('Symbols', 'pkb-math-tools') },
    ], []);

    return el(InspectorControls, null,
      el(PanelBody, {
        title: __('Math Tools', 'pkb-math-tools'),
        initialOpen: true,
        className: 'pkb-math-tools-panel',
      },
        el(TabPanel, {
          className: 'pkb-math-tools-tabs',
          tabs,
        }, (tab) => el(SnippetGrid, {
            items: presets[tab.name],
            attributes,
            setAttributes,
            clientId,
        })),
        el('div', { className: 'pkb-math-tools-section' },
          el('div', { className: 'pkb-math-tools-heading' }, __('Examples', 'pkb-math-tools')),
          examples.map((example) => el(Button, {
            key: example.label,
            className: 'pkb-math-tools-example',
            onClick: () => updateMath(attributes, setAttributes, example.value),
            size: 'compact',
            variant: 'tertiary',
          }, example.label))
        )
      )
    );
  }

  const withMathTools = createHigherOrderComponent((BlockEdit) => {
    return (props) => {
      if (props.name !== 'core/math') {
        return el(BlockEdit, props);
      }

      return el(Fragment, null,
        el(BlockEdit, props),
        props.isSelected && el(MathToolsPanel, {
          attributes: props.attributes,
          setAttributes: props.setAttributes,
          clientId: props.clientId,
        })
      );
    };
  }, 'withMathTools');

  addFilter('editor.BlockEdit', 'pkb-math-tools/with-math-tools', withMathTools);
}());
